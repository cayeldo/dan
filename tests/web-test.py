"""End-to-end HTTP checks using a disposable copy and SQLite database.

Run: python3 tests/web-test.py [optional-sample.csv]
No production code, credentials, or user accounts are modified.
"""
import base64
import contextlib
import datetime
import http.cookiejar
import hashlib
from html.parser import HTMLParser
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]

class AuditDetails(HTMLParser):
    """Inspect semantic category/merchant nesting in the rendered response."""
    def __init__(self, page):
        super().__init__()
        self.stack = []
        self.nodes = {}
        self.feed(page)

    def handle_starttag(self, tag, attrs):
        if tag == 'details':
            attrs = dict(attrs)
            node = {'id': attrs.get('id', ''), 'parent': self.stack[-1]['id'] if self.stack else '',
                    'text': '', 'open': 'open' in attrs}
            self.stack.append(node)
            if node['id']:
                self.nodes[node['id']] = node

    def handle_data(self, data):
        for node in self.stack:
            node['text'] += data

    def handle_endtag(self, tag):
        if tag == 'details':
            self.stack.pop()


def check(condition, message):
    if not condition:
        raise AssertionError(message)
    print('PASS', message, flush=True)


with tempfile.TemporaryDirectory(prefix='dan-web-test-') as temporary:
    folder = Path(temporary) / 'public'
    folder.mkdir()
    for source in ROOT.glob('*.php'):
        shutil.copy(source, folder)
    for source in ROOT.glob('*.js'):
        shutil.copy(source, folder)
    for source in ROOT.glob('*.css'):
        shutil.copy(source, folder)
    shutil.copytree(ROOT / 'views', folder / 'views')
    shutil.copytree(ROOT / 'assets', folder / 'assets')
    shutil.copytree(ROOT / 'lib', folder / 'lib')
    (folder / 'sessions').mkdir()
    simplefin = (folder / 'simplefin.php').read_text().replace('function simplefin_http(', 'function unused_network_simplefin_http(', 1)
    simplefin = simplefin.replace('declare(strict_types=1);', "declare(strict_types=1);\nrequire __DIR__ . '/simplefin-web-fixture.php';", 1)
    (folder / 'simplefin.php').write_text(simplefin)
    shutil.copy(ROOT / 'tests/simplefin-web-fixture.php', folder)
    (folder / 'disabled-ai.json').write_text('{"enabled": false}')
    auth = (folder / 'auth.php').read_text().replace('function database(): PDO', 'function unused_production_database(): PDO', 1)
    auth = auth.replace('declare(strict_types=1);', "declare(strict_types=1);\nrequire_once __DIR__ . '/test-database.php';", 1)
    (folder / 'auth.php').write_text(auth)
    (folder / 'test-database.php').write_text('''<?php
class WebTestDatabase extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function database(): PDO {
    $db = new WebTestDatabase('sqlite:' . __DIR__ . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}
''')
    schema = (ROOT / 'database/analyzer.sql').read_text() + (ROOT / 'database/admin.sql').read_text()
    schema = schema.replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT')
    schema = re.sub(r'UNIQUE KEY \w+ \(', 'UNIQUE (', schema)
    schema = re.sub(r'^\s*KEY \w+ \([^\n]+\),\n', '', schema, flags=re.M)
    schema = re.sub(r'\) ENGINE=InnoDB[^;]+;', ');', schema)
    schema = re.sub(r'VARCHAR\((\d+)\) NOT NULL', r'VARCHAR(\1) COLLATE NOCASE NOT NULL', schema)
    (folder / 'schema.sql').write_text(schema)
    (folder / 'fixture.php').write_text('''<?php
require __DIR__ . '/test-database.php';
$db = database();
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password_hash TEXT, setup_token_hash TEXT, setup_expires_at TEXT, password_created_at TEXT, failed_attempts INTEGER DEFAULT 0, locked_until TEXT, last_login_at TEXT)');
$db->exec(file_get_contents(__DIR__ . '/schema.sql'));
$q = $db->prepare('INSERT INTO users (id, username, password_hash) VALUES (?, ?, ?)');
$q->execute([1, 'first_test_user', password_hash('test-only-passphrase', PASSWORD_BCRYPT)]);
$q->execute([2, 'second_test_user', password_hash('test-only-passphrase', PASSWORD_BCRYPT)]);
$db->exec('INSERT INTO app_admins (user_id) VALUES (1)');
''')
    subprocess.run(['php', str(folder / 'fixture.php')], check=True, stdout=subprocess.DEVNULL)
    with contextlib.closing(socket.socket()) as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    log = open(folder / 'server.log', 'w+')
    server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(folder / 'sessions'), '-S', f'127.0.0.1:{port}', '-t', str(folder)], stdout=log, stderr=log,
                              env=dict(os.environ, OPENAI_API_KEY='', DAN_AI_CONFIG=str(folder / 'disabled-ai.json'), DAN_SIMPLEFIN_STORAGE=str(Path(temporary) / 'simplefin-private')))
    jar = http.cookiejar.CookieJar()
    client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar))

    def request(path='/', fields=None, multipart=None, browser=client):
        headers = {}
        data = None
        if fields is not None:
            data = urllib.parse.urlencode(fields).encode()
        if multipart:
            boundary = '----DanTestBoundary7e23f1'
            parts = []
            for key, value in fields.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="statement"; filename="statement.csv"\r\nContent-Type: text/csv\r\n\r\n'.encode() + multipart + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            data = b''.join(parts)
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
        try:
            response = browser.open(urllib.request.Request(base + path, data=data, headers=headers), timeout=10)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.read().decode(), response.headers

    def token(page, name='csrf'):
        return re.search(r'name="' + name + r'" value="([^"]+)"', page).group(1)

    try:
        for attempt in range(50):
            try:
                status, login, headers = request()
                break
            except urllib.error.URLError:
                if server.poll() is not None:
                    raise RuntimeError('PHP server failed to start')
                time.sleep(.1)
        else:
            raise RuntimeError('PHP server did not start')
        check(status == 200 and 'Welcome back' in login, 'login page renders')
        check('class="auth-logo"' in login and '<header' not in login and 'Your money. Your next move.' in login, 'login centers the original stacked logo without a page header')
        if os.environ.get('DAN_PREVIEW_DIR'):
            destination = Path(os.environ['DAN_PREVIEW_DIR'])
            destination.mkdir(parents=True, exist_ok=True)
            (destination / 'login.html').write_text(login)
            shutil.copytree(ROOT / 'assets', destination / 'assets', dirs_exist_ok=True)
        status, portal, _ = request('/', {'csrf': token(login), 'username': 'first_test_user', 'password': 'test-only-passphrase'})
        check(status == 200 and 'Welcome back, First_test_user.' in portal and 'Open analyzer' in portal, 'login opens personalized portal')
        for asset in ['styles.css', 'portal.css', 'charts.js']:
            version = hashlib.sha256((folder / asset).read_bytes()).hexdigest()[:16]
            check(f'/{asset}?v={version}' in portal, f'{asset} URL is versioned by its deployed contents')
        old_version = hashlib.sha256((folder / 'portal.css').read_bytes()).hexdigest()[:16]
        with (folder / 'portal.css').open('a') as changed_css:
            changed_css.write('\n/* simulate the next deployment */\n')
        next_portal = request('/')[1]
        next_version = hashlib.sha256((folder / 'portal.css').read_bytes()).hexdigest()[:16]
        check(next_version != old_version and f'/portal.css?v={next_version}' in next_portal,
              'a stylesheet deployment changes its URL so cached older CSS cannot be reused')

        status, empty, _ = request('/?page=analyzer')
        check(status == 200 and 'first monthly report' in empty, 'analyzer empty state renders')
        check('A few charts. A clearer picture.' in portal, 'new user sees an empty overview without invented totals')
        csrf = token(empty)
        status, connect_page, connect_headers = request('/?page=connect')
        check(status == 200 and 'Three steps to automatic imports' in connect_page and 'Cardmember Service' in connect_page, 'SimpleFIN setup page renders')
        check(connect_headers['Content-Security-Policy'] == "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'", 'SimpleFIN page retains strict app policy without third-party scripts')
        check(request('/?page=connect', {'action': 'sfin_connect', 'csrf': 'wrong'})[0] == 403, 'SimpleFIN connection requires CSRF')
        setup = base64.b64encode(b'https://beta-bridge.simplefin.org/simplefin/claim/test-web-token').decode()
        status, connected, _ = request('/?page=connect', {'action': 'sfin_connect', 'csrf': csrf, 'setup_token': setup})
        check(status == 200 and 'Test Elan card' in connected and 'Select your credit card' in connected, 'setup exchange retrieves accounts without importing')
        check('test-web-private-password' not in connected and setup not in connected and '&lt;script&gt;provider text&lt;/script&gt;' in connected, 'connection page escapes provider data and never returns tokens')
        if os.environ.get('DAN_PREVIEW_DIR'):
            destination = Path(os.environ['DAN_PREVIEW_DIR'])
            destination.mkdir(parents=True, exist_ok=True)
            (destination / 'connect.html').write_text(connect_page)
            (destination / 'connected.html').write_text(connected)
            for stylesheet in ROOT.glob('*.css'):
                shutil.copy(stylesheet, destination)
        status, retired_page, retired_headers = request('/?page=plaid')
        check(status == 200 and 'Welcome back, First_test_user.' in retired_page and 'plaid' not in retired_page.lower(), 'retired connection bookmark falls back to the portal')
        check(retired_headers['Content-Security-Policy'] == connect_headers['Content-Security-Policy'], 'retired connection URL cannot enable external scripts or frames')

        csv = b'Date,Transaction,Name,Memo,Amount\n8/1/26,DEBIT,RED ROBIN 23,111111111111111; 05812,-10\n8/2/26,DEBIT,RED ROBIN NO 360,222222222222222; 05812,-20\n8/3/26,DEBIT,UBER *EATS,333333333333333; 05812,-15\n8/4/26,DEBIT,CURIOUS SHOP,444444444444444;,-5\n8/5/26,CREDIT,PAYMENT MADE BY ACCOUNT ENDING IN:1234,INTERNET,50\n'
        upload = {'csrf': csrf, 'action': 'upload', 'account_id': 0, 'account_label': 'Test card', 'charge_sign': 'negative'}
        status, preview, _ = request('/?page=analyzer', upload, csv)
        check(status == 200 and 'Save 5 transactions' in preview and 'Not saved yet' in preview, 'CSV upload creates a five-row preview')
        status, report, _ = request('/?page=analyzer', {'csrf': csrf, 'action': 'confirm_import', 'pending_token': token(preview, 'pending_token')})
        check(status == 200 and '5 transactions saved. 0 duplicates skipped.' in report, 'confirm saves import')
        check('Spending by category for August 2026' in report and '$50.00' in report and 'Red Robin' in report and '$30.00' in report, 'monthly chart and merchant totals render')
        check('No transactions have been imported for July 2026' in report, 'monthly comparison distinguishes missing prior data from zero')
        heading = report[report.index('class="page-heading analyzer-heading"'):report.index('id="spending-chart"')]
        check('Report month' in heading and 'View report' in heading and 'Select a slice' not in heading and 'By transaction date' not in heading, 'analyzer header contains compact report controls without helper captions')
        check(report.index('id="merchant-totals"') < report.index('id="month-comparison"') < report.index('id="budget-progress"') < report.index('class="panel payments"'), 'merchant totals stay above detailed comparison, budget and card payments')
        check('id="month-comparison" open' not in report, 'exact monthly comparison stays collapsed by default')
        _, expanded_compare, _ = request('/?page=analyzer&month=2026-08&compare=1')
        check('id="month-comparison" open' in expanded_compare, 'comparison detail link opens its native disclosure')
        status, overview, _ = request('/')
        check(status == 200 and 'Monthly spending' in overview and 'Cumulative expenses' in overview and 'Category shifts' not in overview and 'No standout patterns yet' not in overview, 'overview removes the old graphic without showing filler insights')
        check('Automatic categorization is in progress.' in report and 'Curious Shop' in report, 'unknown merchant is queued without prompting for a category')
        check('id="audit-heading"' not in report and 'name="statement"' not in report, 'analyzer hides category audit and keeps upload on its own page')
        check('<details class="panel compact-disclosure" id="merchant-totals">' in report and 'Recent imports' not in report and '<details class="panel import-history compact-disclosure">' in request('/?page=imports')[1], 'merchant totals stay collapsed and recent imports move to setup')
        check('data-tooltip=' in report and '/charts.js' in report, 'category charts expose values through the local tooltip script')
        status, statement_page, _ = request('/?page=statements&month=2026-08')
        check(status == 200 and 'Monthly spending statement' in statement_page and 'Download PDF' in statement_page and 'name="statement"' not in statement_page, 'Statements is a readable monthly record, separate from CSV uploads')
        pdf_response = client.open(base + '/?page=statements&month=2026-08&download=pdf', timeout=30)
        pdf_bytes = pdf_response.read()
        check(pdf_response.headers.get_content_type() == 'application/pdf' and pdf_bytes.startswith(b'%PDF-') and 'attachment' in pdf_response.headers['Content-Disposition'], 'authenticated PDF export returns a downloadable document')
        check(pdf_response.headers['Cache-Control'] == 'no-store', 'private PDF downloads cannot be cached')
        check('id="upload"' not in request('/?page=setup')[1] and 'Manage imports' in request('/?page=setup')[1], 'setup groups controls without putting upload forms on the landing page')
        status, statements, _ = request('/?page=imports')
        check(status == 200 and 'name="statement"' in statements, 'Imports subsection provides the upload form')
        check('id="monthly-review"' not in report, 'incomplete months show no AI review or review placeholder')
        check(request('/?page=imports', {'action': 'confirm_months', 'csrf': 'wrong'})[0] == 403, 'month completion requires CSRF')
        complete_fields = {'csrf': csrf, 'action': 'confirm_months', 'complete_from': '2026-08', 'complete_through': '2026-08', 'coverage_confirmed': '1'}
        current_month = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m')
        future_fields = dict(complete_fields, complete_from=current_month, complete_through=current_month)
        check('current month cannot be marked complete' in request('/?page=imports', future_fields)[1], 'HTTP confirmation rejects the current month')
        check('months confirmed complete' in request('/?page=imports', complete_fields)[1], 'complete-data confirmation queues a closed month')
        check('review will appear once prepared' in request('/?page=analyzer&month=2026-08')[1], 'complete month shows a deterministic pending status')
        (folder / 'review-fixture.php').write_text("""<?php
require __DIR__ . '/test-database.php';
require __DIR__ . '/analyzer.php';
require __DIR__ . '/monthly-reviews.php';
$db = database();
$db->exec("UPDATE analyzer_ai_jobs SET status = 'unresolved' WHERE user_id = 1");
run_month_review($db, 1, fn() => ['headline' => 'A good start <script>bad()</script>', 'summary' => 'Your recorded purchases total $50.', 'bright_spot' => 'You have a clearer view of spending.', 'opportunity' => 'Review dining costs.', 'next_steps' => ['Plan one meal at home.', 'Review the uncategorized purchase.']], ['enabled' => true, 'model' => 'local-test']);
""")
        subprocess.run(['php', str(folder / 'review-fixture.php')], check=True)
        saved_review_page = request('/?page=analyzer&month=2026-08')[1]
        check('A good start &lt;script&gt;bad()&lt;/script&gt;' in saved_review_page and '<script>bad()</script>' not in saved_review_page, 'saved model output is escaped')
        review_details = AuditDetails(saved_review_page).nodes['monthly-review-details']
        check(not review_details['open'] and 'Read now' in review_details['text'] and 'Read less' in review_details['text'], 'AI review starts collapsed with native accessible disclosure controls')
        check('Review dining costs.' in review_details['text'] and 'Plan one meal at home.' in review_details['text'], 'expanded review contains honest opportunities and next steps')
        check(saved_review_page.index('id="spending-chart"') < saved_review_page.index('id="monthly-review"') < saved_review_page.index('id="monthly-review-details"'), 'review preview follows the spending chart')
        check('A good start' in request('/?page=analyzer&month=2026-08')[1], 'repeat report views reuse the saved review')
        request('/?page=analyzer', {'csrf': csrf, 'action': 'retry_month_review', 'review_month': '2026-08'})
        check('A good start' in request('/?page=analyzer&month=2026-08')[1], 'retry action cannot regenerate a successful review')
        request('/?page=imports', {'csrf': csrf, 'action': 'reopen_month', 'review_month': '2026-08'})
        check('id="monthly-review"' not in request('/?page=analyzer&month=2026-08')[1], 'reopening a month hides all AI content')
        request('/?page=imports', complete_fields)
        check('A good start' in request('/?page=analyzer&month=2026-08')[1], 'reconfirming restores the saved review without a new request')
        status, budget_page, _ = request('/?page=budget&month=2026-09')
        check(status == 200 and 'Monthly budget' in budget_page and 'Historical average for Misc' in budget_page, 'Budget menu renders historical suggestion controls')
        check('name="targets[misc]"' in budget_page and 'value="" placeholder="Enter amount"' in budget_page, 'new targets stay blank despite available historical averages')
        check('About Misc' in budget_page and 'Examples from your past purchases:' in budget_page, 'category help includes examples from imported purchases')
        check('Total monthly budget' in budget_page and 'data-budget-total aria-live="polite">$0.00' in budget_page, 'blank budget shows a dollar total before targets are entered')
        budget_fields = {'csrf': csrf, 'action': 'save_budget', 'budget_month': '2026-09', 'budget_version': token(budget_page, 'budget_version'), 'targets[misc]': '125.50'}
        check(request('/?page=budget', dict(budget_fields, csrf='wrong'))[0] == 403, 'budget saves require CSRF')
        check('Enter a monthly amount' in request('/?page=budget', dict(budget_fields, **{'targets[misc]': ''}))[1], 'empty budget targets are rejected by the server')
        status, saved_budget, _ = request('/?page=budget', budget_fields)
        check(status == 200 and 'Budget saved.' in saved_budget and 'value="125.50"' in saved_budget and '$125.50' in saved_budget, 'budget persists the entered amount and total')
        check('changed in another tab' in request('/?page=budget', budget_fields)[1], 'stale form cannot overwrite a saved budget')
        check('value="125.50"' in request('/?page=budget&month=2026-09')[1], 'saved targets load without reentering them')
        check('value="125.50"' in request('/?page=budget&month=2026-10')[1], 'the next month inherits the saved budget')
        progress_page = request('/?page=analyzer&month=2026-09')[1]
        check('Your budget at a glance' in progress_page and 'What’s in Misc?' in progress_page and 'Spending pace' in progress_page, 'analyzer shows saved targets, grouped details, and calculated pace')

        current_budget = request('/?page=budget&month=' + current_month)[1]
        recommendation_fields = {'csrf': csrf, 'action': 'recommend_budget', 'budget_month': current_month,
            'budget_version': token(current_budget, 'budget_version'), 'resources_version': token(current_budget, 'resources_version'),
            'cash_available': '200', 'cash_reserve': '20', 'priorities[misc]': 'protect', 'minimums[misc]': ''}
        check(request('/?page=budget', dict(recommendation_fields, csrf='wrong'))[0] == 403, 'recommendation inputs require CSRF')
        status, recommended_page, _ = request('/?page=budget', recommendation_fields)
        check(status == 200 and 'Recommendation ready to compare' in recommended_page and 'Apply recommended budget' in recommended_page, 'HTTP budget recommendation shows a usable comparison')
        check('value="125.50"' in recommended_page, 'building a recommendation leaves the current targets intact')
        (folder / 'budget-advice-fixture.php').write_text("""<?php
require __DIR__ . '/test-database.php';
require __DIR__ . '/analyzer.php';
require __DIR__ . '/analytics.php';
require __DIR__ . '/budgets.php';
require __DIR__ . '/monthly-reviews.php';
require __DIR__ . '/budget-recommendations.php';
run_budget_advice(database(), fn() => ['summary' => 'Your plan has room for savings. <script>bad()</script>', 'categories' => ['misc' => 'Keep room for your mixed costs.'], 'next_steps' => ['Check the category details.', 'Set aside the remaining cash.']], ['enabled' => true, 'model' => 'local-test']);
""")
        subprocess.run(['php', str(folder / 'budget-advice-fixture.php')], check=True)
        recommended_page = request('/?page=budget&month=' + current_month)[1]
        check('Your plan has room for savings. &lt;script&gt;bad()&lt;/script&gt;' in recommended_page and '<script>bad()</script>' not in recommended_page, 'budget AI explanation is escaped and shows a short teaser')
        check('Read now' in recommended_page and 'Keep room for your mixed costs.' in recommended_page, 'budget explanation expands to the full category reasoning')
        apply_fields = {'csrf': csrf, 'action': 'apply_recommended_budget', 'budget_month': current_month,
            'recommendation_version': token(recommended_page, 'recommendation_version')}
        check(request('/?page=budget', dict(apply_fields, csrf='wrong'))[0] == 403, 'switching budgets requires CSRF')
        status, applied_page, _ = request('/?page=budget', apply_fields)
        check(status == 200 and 'Recommended budget applied' in applied_page and 'This recommendation was applied' in applied_page, 'one button applies the verified recommended budget')
        check('no longer available' in request('/?page=budget', apply_fields)[1], 'HTTP apply action cannot be replayed')
        travel_fields = {'csrf': csrf, 'action': 'start_travel_fund', 'budget_month': current_month,
            'budget_version': token(applied_page, 'budget_version'), 'travel_opening': '300', 'travel_confirmed': '1'}
        check(request('/?page=budget', dict(travel_fields, csrf='wrong'))[0] == 403, 'travel starting balances require CSRF')
        check('Confirm the starting balance' in request('/?page=budget', dict(travel_fields, travel_confirmed=''))[1], 'opening savings require an explicit confirmation')
        status, travel_page, _ = request('/?page=budget', travel_fields)
        check(status == 200 and 'Travel rollover started' in travel_page and '$300.00' in travel_page and 'Travel target above' in travel_page, 'travel fund starts with an explicit opening balance and displays its rules')

        if os.environ.get('DAN_PREVIEW_DIR'):
            destination = Path(os.environ['DAN_PREVIEW_DIR'])
            (destination / 'review.html').write_text(saved_review_page)
            (destination / 'budget.html').write_text(budget_page)
            (destination / 'budget-saved.html').write_text(saved_budget)
            (destination / 'recommended.html').write_text(recommended_page)
            (destination / 'travel.html').write_text(travel_page)
            for stylesheet in ROOT.glob('*.css'):
                shutil.copy(stylesheet, destination)
            (destination / 'review-statements.html').write_text(request('/?page=imports&reviews=1')[1])
            for script in ROOT.glob('*.js'):
                shutil.copy(script, destination)


        status, audit_report, _ = request('/?page=analyzer&month=2026-08&audit=all')
        audit = AuditDetails(audit_report).nodes
        robin = next(node for key, node in audit.items() if key.startswith('merchant-') and 'RED ROBIN NO 360' in node['text'])
        restaurant = audit[robin['parent']]
        check(restaurant['id'].startswith('category-') and 'Restaurants' in restaurant['text']
              and '$30.00' in restaurant['text'] and 'UBER *EATS' not in restaurant['text'], 'category expands into only its own rolled-up merchants')
        check('2026-08-01' in robin['text'] and '2026-08-02' in robin['text']
              and 'RED ROBIN 23' in robin['text'] and '$10.00' in robin['text'] and '$20.00' in robin['text'], 'merchant expands into individual dated charges')
        status, category_open, _ = request('/?page=analyzer&month=2026-08&category=' + restaurant['id'].split('-')[1])
        check(status == 200 and AuditDetails(category_open).nodes[restaurant['id']]['open'], 'chart category link opens the matching audit group')
        check(len([key for key in AuditDetails(category_open).nodes if key.startswith('category-')]) == 1, 'selecting a slice reveals only that category')
        status, merchant_open, _ = request('/?page=analyzer&month=2026-08&merchant=' + robin['id'].split('-')[1])
        opened = AuditDetails(merchant_open).nodes
        check(status == 200 and opened[restaurant['id']]['open'] and opened[robin['id']]['open'], 'merchant details link opens both disclosure levels')
        edit_id = re.search(r'id="name-(\d+)" name="merchant_name" value="Curious Shop"', audit_report).group(1)
        status, updated, _ = request('/?page=analyzer', {'csrf': csrf, 'action': 'save_merchant', 'merchant_id': edit_id, 'merchant_name': '<script>alert(1)</script>', 'category_id': 0, 'custom_category': 'My custom category', 'month': '2026-08', 'account_filter': 0})
        check(status == 200 and 'My custom category' in updated and '&lt;script&gt;alert(1)&lt;/script&gt;' in updated and '<script>alert(1)</script>' not in updated, 'custom category saves and user-entered names are escaped')
        status, duplicate_preview, _ = request('/?page=analyzer', upload, csv)
        check(status == 200 and 'Finish — no new transactions' in duplicate_preview, 'repeat upload previews zero new rows')
        status, _, _ = request('/?page=analyzer', {'csrf': 'wrong', 'action': 'confirm_import', 'pending_token': token(duplicate_preview, 'pending_token')})
        check(status == 403, 'CSRF protects statement confirmation')
        status, _, _ = request('/?page=analyzer', {'csrf': csrf, 'action': 'discard_import', 'pending_token': token(duplicate_preview, 'pending_token')})
        check(status == 200, 'preview can be discarded')
        if len(sys.argv) > 1:
            sample_upload = dict(upload, account_label='Sample validation')
            status, sample_preview, _ = request('/?page=analyzer', sample_upload, Path(sys.argv[1]).read_bytes())
            check(status == 200 and 'Save 136 transactions' in sample_preview, 'provided sample completes multipart upload and preview')
            status, sample_report, _ = request('/?page=analyzer', {'csrf': csrf, 'action': 'confirm_import', 'pending_token': token(sample_preview, 'pending_token')})
            check(status == 200 and '136 transactions saved' in sample_report and '$2,277.45' in sample_report, 'provided sample saves and opens September report')
            check('September 2026 vs August 2026' in sample_report and '+$9.99' in sample_report and 'Percentage points' in sample_report, 'sample month comparison reconciles dollars and shows bill share changes')
            status, overview, _ = request('/')
            check(status == 200 and 'Monthly purchases over time' in overview and 'All-time purchases by category' in overview, 'populated overview renders line and cumulative charts')
            if os.environ.get('DAN_PREVIEW_DIR'):
                destination = Path(os.environ['DAN_PREVIEW_DIR'])
                destination.mkdir(parents=True, exist_ok=True)
                (destination / 'index.html').write_text(overview)
                (destination / 'month.html').write_text(sample_report)
                for stylesheet in ROOT.glob('*.css'):
                    shutil.copy(stylesheet, destination)
            status, august, _ = request('/?page=analyzer&month=2026-08&account=2')
            check(status == 200 and '$2,217.46' in august and 'Food Delivery' in august, 'month and card filters reconcile sample August report')
            status, september_card, _ = request('/?page=analyzer&month=2026-09&account=2')
            check(status == 200 and '+$59.99' in september_card, 'previous-month comparison honors the selected card')
            sample_audit = AuditDetails(request('/?page=analyzer&month=2026-09&audit=all')[1]).nodes
            costco = next(node for key, node in sample_audit.items() if key.startswith('merchant-') and 'COSTCO WHSE' in node['text'])
            harris = next(node for key, node in sample_audit.items() if key.startswith('merchant-') and 'HARRIS TEETER' in node['text'])
            check(costco['parent'] == harris['parent'] and 'Groceries' in sample_audit[costco['parent']]['text']
                  and 'COSTCO GAS' not in sample_audit[costco['parent']]['text'], 'sample Groceries audit groups Costco and Harris Teeter separately from fuel')
        # Add a separate unknown merchant across two months to verify the cleanup flow.
        unknown_csv = b'Date,Name,Amount\n2025-01-02,ODD LITTLE SHOP,-12\n2026-08-02,ODD LITTLE SHOP,-18\n'
        _, unknown_preview, _ = request('/?page=analyzer', dict(upload, account_id=1), unknown_csv)
        request('/?page=analyzer', {'csrf': csrf, 'action': 'confirm_import', 'pending_token': token(unknown_preview, 'pending_token')})
        status, unsorted, _ = request('/?page=uncategorized')
        check(status == 200 and 'Uncategorized expenses' in unsorted and '2025-01-02' in unsorted and '2026-08-02' in unsorted, 'uncategorized screen shows purchases from all months')
        cleanup_id = next(item[0] for item in re.findall(r'<article class="uncategorized-merchant" id="uncategorized-(\d+)">.*?<h3>([^<]+)</h3>', unsorted, re.S) if item[1].upper() == 'ODD LITTLE SHOP')
        cleanup_fields = {'csrf': csrf, 'action': 'categorize_uncategorized', 'merchant_id': cleanup_id, 'category_id': '0', 'custom_category': 'Independent stores'}
        check(request('/?page=uncategorized', dict(cleanup_fields, csrf='wrong'))[0] == 403, 'uncategorized updates require CSRF')
        if os.environ.get('DAN_PREVIEW_DIR'):
            (Path(os.environ['DAN_PREVIEW_DIR']) / 'uncategorized.html').write_text(unsorted)
        status, sorted_page, _ = request('/?page=uncategorized', cleanup_fields)
        check(status == 200 and 'Category saved for this merchant across all months' in sorted_page and ('id="uncategorized-' + cleanup_id + '"') not in sorted_page, 'category save removes the resolved merchant from cleanup')
        check('Independent stores' in request('/?page=analyzer&month=2025-01')[1], 'cleanup categorization updates an earlier year’s report')
        status, admin_page, _ = request('/?page=admin')
        check(status == 200 and 'Add user &amp; generate code' in admin_page and '>Admin / Setup</a>' in portal and 'User management' in request('/?page=setup')[1], 'administrator sees user management and navigation')
        check(request('/?page=admin', {'csrf': 'wrong', 'action': 'create_user', 'username': 'blocked_user'})[0] == 403, 'CSRF blocks admin creation')
        status, issued, issued_headers = request('/?page=admin', {'csrf': csrf, 'action': 'create_user', 'username': 'Emilia'})
        code = re.search(r'id="issued-code" value="([a-f0-9]{64})"', issued).group(1)
        check(status == 200 and 'Setup code for emilia' in issued and issued_headers['Cache-Control'] == 'no-store', 'new account gets a private one-time setup code automatically')
        check(code not in request('/?page=admin')[1], 'setup code disappears after its first display')
        status, duplicate, _ = request('/?page=admin', {'csrf': csrf, 'action': 'create_user', 'username': 'EMILIA'})
        check('already exists' in duplicate and 'id="issued-code"' not in duplicate, 'duplicate username cannot replace an existing account')
        status, replacement, _ = request('/?page=admin', {'csrf': csrf, 'action': 'generate_setup_code', 'username': 'emilia'})
        replacement_code = re.search(r'id="issued-code" value="([a-f0-9]{64})"', replacement).group(1)
        check(replacement_code != code, 'admin can replace an unused setup code')
        status, active_user, _ = request('/?page=admin', {'csrf': csrf, 'action': 'generate_setup_code', 'username': 'first_test_user'})
        check('only for users who have not created a password' in active_user and 'id="issued-code"' not in active_user, 'active passwords cannot be replaced by setup generation')
        fresh = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        status, setup_page, _ = request('/?mode=setup', browser=fresh)
        check('Add user &amp; generate code' not in request('/?page=admin', browser=fresh)[1], 'signed-out visitors cannot access user management')
        setup_fields = {'csrf': token(setup_page), 'username': 'emilia', 'setup_code': code, 'password': 'new-emilia-test-passphrase', 'confirmation': 'new-emilia-test-passphrase'}
        status, obsolete, _ = request('/?mode=setup', setup_fields, browser=fresh)
        check('Welcome back, Emilia.' not in obsolete, 'replaced code cannot claim the new account')
        setup_fields['setup_code'] = replacement_code
        status, emilia_home, _ = request('/?mode=setup', setup_fields, browser=fresh)
        check(status == 200 and 'Welcome back, Emilia.' in emilia_home and '>Admin / Setup</a>' in emilia_home and 'User management' not in request('/?page=setup', browser=fresh)[1], 'new user creates a password and enters her own portal without admin access')
        check(request('/?page=admin', browser=fresh)[0] == 403, 'member cannot read admin user list')
        status, denied, _ = request('/?page=admin', {'csrf': token(emilia_home), 'action': 'generate_setup_code', 'username': 'second_test_user', 'is_admin': '1'}, browser=fresh)
        check(status == 403 and 'id="issued-code"' not in denied, 'member cannot generate codes or self-assign admin via form fields')
        if os.environ.get('DAN_PREVIEW_DIR'):
            destination = Path(os.environ['DAN_PREVIEW_DIR'])
            destination.mkdir(parents=True, exist_ok=True)
            (destination / 'admin.html').write_text(issued)
        status, login, _ = request('/', {'csrf': csrf, 'action': 'logout'})
        check('autocomplete="current-password"' in login, 'sign-out returns to login')
        status, protected, _ = request('/?page=analyzer')
        check('autocomplete="current-password"' in protected and 'Merchant totals' not in protected, 'signed-out requests cannot see reports')
        check('autocomplete="current-password"' in request('/?page=statements&month=2026-08&download=pdf')[1], 'signed-out visitors cannot download a statement PDF')
        status, other_overview, _ = request('/', {'csrf': token(protected), 'username': 'second_test_user', 'password': 'test-only-passphrase'})
        check('A few charts. A clearer picture.' in other_overview and 'All-time purchases by category' not in other_overview, 'second user cannot see another user’s dashboard totals')
        status, other_user, _ = request('/?page=analyzer&month=2026-08&account=1')
        check(status == 200 and 'first monthly report' in other_user and 'Red Robin' not in other_user, 'second authenticated user cannot see first user’s report')
        check('value="125.50"' not in request('/?page=budget&month=2026-09')[1], 'second user cannot see the first user’s budget targets')
        check('ODD LITTLE SHOP' not in request('/?page=uncategorized')[1], 'cleanup cannot expose another user’s purchase details')
        other_budget = request('/?page=budget&month=' + current_month)[1]
        check('value="200.00"' not in other_budget and 'Still available' not in other_budget and 'Planning baseline</th>' not in other_budget, 'cash inputs, recommendations and travel balances remain private')
        check('$50.00' not in request('/?page=statements&month=2026-08')[1], 'statement rows and totals remain private to their owner')
        check(request('/?page=statements&month=2026-08&account=999999&download=pdf')[0] == 403, 'foreign card PDF requests are rejected')
        status, other_connect, _ = request('/?page=connect')
        check('Test Elan card' not in other_connect and 'setup_token' in other_connect, 'second user cannot see first user’s SimpleFIN connection')
        other_csrf = token(other_connect)
        status, other_connected, _ = request('/?page=connect', {'csrf': other_csrf, 'action': 'sfin_connect', 'setup_token': setup})
        remote_key = re.search(r'<option value="([a-f0-9]{64})"', other_connected).group(1)
        status, enabled, _ = request('/?page=connect', {'csrf': other_csrf, 'action': 'sfin_enable', 'remote_key': remote_key, 'account_id': '0', 'account_label': 'My test Visa', 'start_date': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d')})
        check(status == 200 and 'Automatic imports are on.' in enabled, 'selecting a card enables automatic imports')
        status, feed_report, _ = request('/?page=analyzer')
        check(status == 200 and '$18.25' in feed_report and 'Costco' in feed_report and '$5.50' not in feed_report, 'posted feed charge appears in reports while pending charge is excluded')
        feed_card_id = re.search(r'href="/\?page=analyzer&amp;account=(\d+)"', enabled).group(1)
        feed_day = datetime.datetime.now(datetime.timezone.utc).date()
        history_day = feed_day - datetime.timedelta(days=2)
        mixed_csv = f'Date,Description,Amount,Transaction ID\n{feed_day},Costco,-18.25,csv-feed-test\n{history_day},History Shop,-7.50,csv-history-test\n'.encode()
        mixed_upload = {'csrf': other_csrf, 'action': 'upload', 'account_id': feed_card_id, 'charge_sign': 'negative'}
        status, mixed_preview, _ = request('/?page=analyzer', mixed_upload, mixed_csv)
        check(status == 200 and 'Save 1 transactions' in mixed_preview and 'duplicates to skip' in mixed_preview, 'CSV upload accepts a bank-feed duplicate alongside new history')
        status, mixed_report, _ = request('/?page=analyzer', {'csrf': other_csrf, 'action': 'confirm_import', 'pending_token': token(mixed_preview, 'pending_token')})
        check(status == 200 and '1 transactions saved. 1 duplicates skipped.' in mixed_report, 'CSV confirmation saves history without duplicating the bank charge')
        status, repeat_preview, _ = request('/?page=analyzer', mixed_upload, mixed_csv)
        check(status == 200 and 'Finish — no new transactions' in repeat_preview, 'repeated cross-source upload previews no new transactions')
        request('/?page=analyzer', {'csrf': other_csrf, 'action': 'discard_import', 'pending_token': token(repeat_preview, 'pending_token')})
        status, paused, _ = request('/?page=connect', {'csrf': other_csrf, 'action': 'sfin_pause'})
        check('Resume imports' in paused, 'automatic imports can be paused')
        status, disconnected, _ = request('/?page=connect', {'csrf': other_csrf, 'action': 'sfin_disconnect'})
        check('setup_token' in disconnected and 'Revoke the app token' in disconnected, 'disconnect removes access and explains provider revocation')
        check('$18.25' in request('/?page=analyzer')[1], 'disconnect preserves spending reports')
        check(client.open(base + '/assets/kle-coin-logo-v2.png').headers.get_content_type() == 'image/png', 'brand artwork is served as an image')
        for stylesheet in ['/styles.css', '/portal.css', '/assets/kle-coin-icon.svg']:
            check(request(stylesheet)[0] == 200, stylesheet + ' served')
        log.flush()
        log.seek(0)
        errors = log.read()
        check('PHP Warning:' not in errors and 'PHP Fatal error:' not in errors and 'PHP Deprecated:' not in errors, 'no PHP warnings or runtime errors')
        print('All end-to-end HTTP checks passed.', flush=True)
    finally:
        server.terminate()
        server.wait(timeout=5)
        log.close()
