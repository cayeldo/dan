"""End-to-end HTTP checks using a disposable copy and SQLite database.

Run: python3 tests/web-test.py [optional-sample.csv]
No production code, credentials, or user accounts are modified.
"""
import base64
import contextlib
import datetime
import http.cookiejar
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
    private = Path(temporary) / 'plaid-items'
    private.mkdir(mode=0o700)
    plaid_config = Path(temporary) / 'plaid.env'
    plaid_config.write_text('PLAID_CLIENT_ID=test-only-client\nPLAID_SECRET=test-only-secret\nPLAID_ENV=sandbox\n')
    for source in ROOT.glob('*.php'):
        shutil.copy(source, folder)
    for source in ROOT.glob('*.js'):
        shutil.copy(source, folder)
    for source in ROOT.glob('*.css'):
        shutil.copy(source, folder)
    shutil.copytree(ROOT / 'views', folder / 'views')
    (folder / 'sessions').mkdir()
    plaid = (folder / 'plaid.php').read_text().replace('function plaid_api(', 'function unused_network_plaid_api(', 1)
    plaid = plaid.replace("declare(strict_types=1);", "declare(strict_types=1);\nrequire __DIR__ . '/plaid-web-fixture.php';", 1)
    (folder / 'plaid.php').write_text(plaid)
    shutil.copy(ROOT / 'tests/plaid-web-fixture.php', folder)
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
    schema = (ROOT / 'database/analyzer.sql').read_text()
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
''')
    subprocess.run(['php', str(folder / 'fixture.php')], check=True, stdout=subprocess.DEVNULL)
    with contextlib.closing(socket.socket()) as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    log = open(folder / 'server.log', 'w+')
    server = subprocess.Popen(['php', '-d', 'session.save_path=' + str(folder / 'sessions'), '-S', f'127.0.0.1:{port}', '-t', str(folder)], stdout=log, stderr=log,
                              env=dict(os.environ, OPENAI_API_KEY='', DAN_AI_CONFIG=str(folder / 'disabled-ai.json'), DAN_PLAID_CONFIG=str(plaid_config), DAN_PLAID_STORAGE=str(private), DAN_SIMPLEFIN_STORAGE=str(Path(temporary) / 'simplefin-private')))
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
        status, portal, _ = request('/', {'csrf': token(login), 'username': 'first_test_user', 'password': 'test-only-passphrase'})
        check(status == 200 and 'Welcome back, First_test_user.' in portal and 'Open analyzer' in portal, 'login opens personalized portal')
        status, empty, _ = request('/?page=analyzer')
        check(status == 200 and 'first monthly report' in empty, 'analyzer empty state renders')
        check('A few charts. A clearer picture.' in portal, 'new user sees an empty overview without invented totals')
        csrf = token(empty)
        status, connect_page, connect_headers = request('/?page=connect')
        check(status == 200 and 'Three steps to automatic imports' in connect_page and 'Cardmember Service' in connect_page, 'SimpleFIN setup page renders')
        check('cdn.plaid.com' not in connect_headers['Content-Security-Policy'], 'SimpleFIN page retains strict app policy without third-party scripts')
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
        status, plaid_page, plaid_headers = request('/?page=plaid')
        check(status == 200 and 'Connect Credit Card' in plaid_page and 'Test data only' in plaid_page, 'Sandbox connect page renders inside existing login')
        check('https://cdn.plaid.com' in plaid_headers['Content-Security-Policy'] and 'https://sandbox.plaid.com' in plaid_headers['Content-Security-Policy'], 'Link resources allowed only by Sandbox page policy')
        check(request('/?page=plaid&api=link-token')[0] == 405, 'Plaid mutations reject GET')
        check(request('/?page=plaid&api=link-token', {'csrf': 'invalid'})[0] == 403, 'Plaid endpoints require CSRF')
        status, link_result, _ = request('/?page=plaid&api=link-token', {'csrf': csrf})
        check(status == 200 and 'link-sandbox-' in link_result and 'test-only-secret' not in link_result, 'browser receives only the short-lived Link token')
        status, exchanged, _ = request('/?page=plaid&api=exchange', {'csrf': csrf, 'public_token': 'public-sandbox-00000000-0000-0000-0000-000000000001'})
        check(status == 200 and exchanged == '{"connected":true}', 'public token exchanged with no access token in response')
        status, retrieved, _ = request('/?page=plaid&api=transactions', {'csrf': csrf})
        check(status == 200 and '"ready":true' in retrieved and 'access-sandbox' not in retrieved, 'transactions retrieved with server-held token')
        status, plaid_table, _ = request('/?page=plaid')
        check(status == 200 and 'Sandbox merchant' in plaid_table and '18.25 USD' in plaid_table and 'Pending' in plaid_table and 'FOOD_AND_DRINK_RESTAURANTS' in plaid_table, 'sample table displays date, merchant, amount, pending, and Plaid category')
        check('&lt;script&gt;unsafe&lt;/script&gt;' in plaid_table and '<script>unsafe</script>' not in plaid_table and 'access-sandbox' not in plaid_table and 'test-only-secret' not in plaid_table, 'provider data is escaped and secrets stay out of HTML')
        check('No transactions have' not in plaid_table, 'connected Sandbox page renders independently of spending reports')
        csv = b'Date,Transaction,Name,Memo,Amount\n8/1/26,DEBIT,RED ROBIN 23,111111111111111; 05812,-10\n8/2/26,DEBIT,RED ROBIN NO 360,222222222222222; 05812,-20\n8/3/26,DEBIT,UBER *EATS,333333333333333; 05812,-15\n8/4/26,DEBIT,CURIOUS SHOP,444444444444444;,-5\n8/5/26,CREDIT,PAYMENT MADE BY ACCOUNT ENDING IN:1234,INTERNET,50\n'
        upload = {'csrf': csrf, 'action': 'upload', 'account_id': 0, 'account_label': 'Test card', 'charge_sign': 'negative'}
        status, preview, _ = request('/?page=analyzer', upload, csv)
        check(status == 200 and 'Save 5 transactions' in preview and 'Not saved yet' in preview, 'CSV upload creates a five-row preview')
        status, report, _ = request('/?page=analyzer', {'csrf': csrf, 'action': 'confirm_import', 'pending_token': token(preview, 'pending_token')})
        check(status == 200 and '5 transactions saved. 0 duplicates skipped.' in report, 'confirm saves import')
        check('Spending by category for August 2026' in report and '$50.00' in report and 'Red Robin' in report and '$30.00' in report, 'monthly chart and merchant totals render')
        check('No transactions have been imported for July 2026' in report, 'monthly comparison distinguishes missing prior data from zero')
        status, overview, _ = request('/')
        check(status == 200 and 'Monthly spending' in overview and 'Cumulative expenses' in overview and 'Category shifts' in overview, 'portal renders all dashboard charts after import')
        check('Automatic categorization is in progress.' in report and 'CURIOUS SHOP' in report, 'unknown merchant is queued without prompting for a category')
        audit = AuditDetails(report).nodes
        robin = next(node for key, node in audit.items() if key.startswith('merchant-') and 'RED ROBIN NO 360' in node['text'])
        restaurant = audit[robin['parent']]
        check(restaurant['id'].startswith('category-') and 'Restaurants' in restaurant['text']
              and '$30.00' in restaurant['text'] and 'UBER *EATS' not in restaurant['text'], 'category expands into only its own rolled-up merchants')
        check('2026-08-01' in robin['text'] and '2026-08-02' in robin['text']
              and 'RED ROBIN 23' in robin['text'] and '$10.00' in robin['text'] and '$20.00' in robin['text'], 'merchant expands into individual dated charges')
        status, category_open, _ = request('/?page=analyzer&month=2026-08&category=' + restaurant['id'].split('-')[1])
        check(status == 200 and AuditDetails(category_open).nodes[restaurant['id']]['open'], 'chart category link opens the matching audit group')
        status, merchant_open, _ = request('/?page=analyzer&month=2026-08&merchant=' + robin['id'].split('-')[1])
        opened = AuditDetails(merchant_open).nodes
        check(status == 200 and opened[restaurant['id']]['open'] and opened[robin['id']]['open'], 'merchant details link opens both disclosure levels')
        edit_id = re.search(r'id="name-(\d+)" name="merchant_name" value="Curious Shop"', report).group(1)
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
            sample_audit = AuditDetails(sample_report).nodes
            costco = next(node for key, node in sample_audit.items() if key.startswith('merchant-') and 'COSTCO WHSE' in node['text'])
            harris = next(node for key, node in sample_audit.items() if key.startswith('merchant-') and 'HARRIS TEETER' in node['text'])
            check(costco['parent'] == harris['parent'] and 'Groceries' in sample_audit[costco['parent']]['text']
                  and 'COSTCO GAS' not in sample_audit[costco['parent']]['text'], 'sample Groceries audit groups Costco and Harris Teeter separately from fuel')
        status, login, _ = request('/', {'csrf': csrf, 'action': 'logout'})
        check('autocomplete="current-password"' in login, 'sign-out returns to login')
        status, protected, _ = request('/?page=analyzer')
        check(request('/?page=plaid&api=link-token', {'csrf': csrf})[0] == 401, 'signed-out Plaid API requests are rejected')
        check('autocomplete="current-password"' in protected and 'Merchant totals' not in protected, 'signed-out requests cannot see reports')
        status, other_overview, _ = request('/', {'csrf': token(protected), 'username': 'second_test_user', 'password': 'test-only-passphrase'})
        check('A few charts. A clearer picture.' in other_overview and 'All-time purchases by category' not in other_overview, 'second user cannot see another user’s dashboard totals')
        status, other_user, _ = request('/?page=analyzer&month=2026-08&account=1')
        check(status == 200 and 'first monthly report' in other_user and 'Red Robin' not in other_user, 'second authenticated user cannot see first user’s report')
        status, other_plaid, _ = request('/?page=plaid')
        check(status == 200 and 'Sandbox merchant' not in other_plaid and 'id="connect-card"' in other_plaid, 'second user cannot view first user’s Plaid connection')
        status, other_connect, _ = request('/?page=connect')
        check('Test Elan card' not in other_connect and 'setup_token' in other_connect, 'second user cannot see first user’s SimpleFIN connection')
        other_csrf = token(other_connect)
        status, other_connected, _ = request('/?page=connect', {'csrf': other_csrf, 'action': 'sfin_connect', 'setup_token': setup})
        remote_key = re.search(r'<option value="([a-f0-9]{64})"', other_connected).group(1)
        status, enabled, _ = request('/?page=connect', {'csrf': other_csrf, 'action': 'sfin_enable', 'remote_key': remote_key, 'account_id': '0', 'account_label': 'My test Visa', 'start_date': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d')})
        check(status == 200 and 'Automatic imports are on.' in enabled, 'selecting a card enables automatic imports')
        status, feed_report, _ = request('/?page=analyzer')
        check(status == 200 and '$18.25' in feed_report and 'Costco' in feed_report and '$5.50' not in feed_report, 'posted feed charge appears in reports while pending charge is excluded')
        status, paused, _ = request('/?page=connect', {'csrf': other_csrf, 'action': 'sfin_pause'})
        check('Resume imports' in paused, 'automatic imports can be paused')
        status, disconnected, _ = request('/?page=connect', {'csrf': other_csrf, 'action': 'sfin_disconnect'})
        check('setup_token' in disconnected and 'Revoke the app token' in disconnected, 'disconnect removes access and explains provider revocation')
        check('$18.25' in request('/?page=analyzer')[1], 'disconnect preserves spending reports')
        for stylesheet in ['/styles.css', '/portal.css', '/plaid-link.js']:
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
