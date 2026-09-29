# Dan account login

PHP login screen for the existing DirectAdmin site and MySQL/MariaDB database.
Requires PHP 8.1+ with PDO MySQL and HTTPS in production. No Composer or Node dependencies.

## Install

1. In phpMyAdmin, select `cayeldo_dan`, open **SQL**, and run [`database/accounts.sql`](database/accounts.sql). It creates `users` and seeds `don` and `dan`. Running it again will not reset passwords. Both accounts initially have `password_hash = NULL`; neither can log in until its owner chooses a password.
2. Copy `deploy/dan-config.example.php` to `/home/cayeldo/domains/dan.cayelli.us/dan-config.php` (one directory **above** `public_html`). Enter the database password from DirectAdmin. This is the database connection password, not Don's or Dan's login password. Restrict the file to the PHP service user (for example owner `cayeldo`, mode `0600`). Alternatively set `DAN_CONFIG` to a private PHP config file or `DAN_DB_PASSWORD` in the PHP environment. Never put real credentials in this repository.
3. Deploy the root `index.php`, `auth.php`, `styles.css`, and `.htaccess` files to `public_html`, replacing the old `index.html`. The existing deployment script has been updated to omit SQL, tests, and administrative files. It deploys committed `origin/main`, so local edits do not affect the live site until committed and pushed. PHP must be enabled; if using Nginx without Apache, set its index to `index.php` and deny hidden files/directory listings.
4. From the server's repository checkout, generate separate private setup codes:

   ```sh
   DAN_CONFIG=/home/cayeldo/domains/dan.cayelli.us/dan-config.php php deploy/create-setup-code.php don
   DAN_CONFIG=/home/cayeldo/domains/dan.cayelli.us/dan-config.php php deploy/create-setup-code.php dan
   ```

5. Give each code privately to its account owner. Each person opens `https://dan.cayelli.us`, chooses **Create your password**, and enters their username, setup code, and chosen password twice. They are signed in immediately. Later visits use username and password only.

Setup codes expire after 24 hours and are consumed on use. Re-run the command to replace an unused/expired code. It refuses to change an account that already has a password. There is no public registration or password-reset feature.

## Password and session behavior

- Passwords are stored as salted bcrypt hashes using PHP `password_hash()` and checked with `password_verify()`; plaintext passwords are never stored. See the [PHP password hashing documentation](https://www.php.net/manual/en/function.password-hash.php).
- Passwords must be 12–72 bytes; the byte limit avoids bcrypt truncation, and Unicode characters may occupy multiple bytes. Spaces are preserved.
- Setup codes use 32 random bytes; only their SHA-256 hashes are stored. Codes prove ownership before a password can be created, preventing a visitor from claiming `don` or `dan` by knowing the name.
- Prepared statements, CSRF tokens, session ID rotation, HTTPS-only production access, HttpOnly/SameSite cookies, a 30-minute inactivity timeout, and a 15-minute account lock after five failed attempts protect sign-in and setup. Account locks persist across browsers.
- Signing in opens a personalized portal. The credit card analyzer, reports, and uploaded records are private to the signed-in account.
- Dates written by the application are UTC. If TLS terminates at a proxy, configure the trusted server to report HTTPS to PHP; do not trust arbitrary forwarded headers.

## Local checks

```sh
php -l index.php
php -l auth.php
php -l deploy/create-setup-code.php
php tests/auth-test.php
php -S 127.0.0.1:8080
```

The login and setup pages render without a database. Successful account operations require the imported MySQL database and private connection settings. The local PHP development server allows HTTP; production requires HTTPS. No real database was configured or seeded by merely adding these files.

## Credit card analyzer

Run the additive migration once before serving this feature (the deployment script also runs it automatically before copying new files):

```sh
DAN_CONFIG=/home/cayeldo/domains/dan.cayelli.us/dan-config.php php deploy/migrate.php
```

The equivalent SQL is `database/analyzer.sql`. It creates categories, card accounts, merchants, merchant aliases, imports, and individual transactions without altering login accounts. Records are scoped to a user, and imported data is never shared between Don and Dan.

Open **Credit card analyzer**, choose or name a card, select the CSV's charge sign convention, and preview the upload. Nothing is written until **Save transactions**. Files spanning multiple months produce separate calendar-month reports using transaction dates. The original description, memo, date, signed amount in cents, bank reference, and import provenance are retained. Uploaded CSV files themselves are not retained; previews expire after 30 minutes.

Under **Audit your categories**, expand a category to see merchant totals, then expand a merchant to inspect every dated charge and credit or correct its category. Category names next to the chart link directly to these groups. Purchases, credits, and net spending are shown at each level; credit-only categories remain available even when they have no pie-chart slice. The audit follows the selected month and card.

Supported input is a UTF-8, comma-separated CSV (up to 2 MB / 10,000 rows) with `Date`, `Name` (or `Description` / `Merchant`), and `Amount`; `Transaction`, `Memo`, and `Transaction ID` / `Reference Number` are optional. Dates accept US M/D/YY, M/D/YYYY, or ISO YYYY-MM-DD. Currency is USD in this first version. Purchases can be signed negative or positive, selected before import. Recognized card payment descriptions are excluded from spending; other credits are shown as refunds/credits. The pie chart shows gross purchases; the separate net figure subtracts credits.

Merchant rules distinguish Uber Eats / Uber Trip, Costco / Costco Gas, and food-delivery services from restaurant names. Store variants such as Red Robin 23 and Red Robin No 360 roll up together. Known merchants use local rules. Unknown businesses receive an MCC-based starting category when possible, then enter an automatic OpenAI categorization queue. Confident AI results update the database without a category-confirmation prompt; unknown or failed results retain the starting category and remain editable. Every merchant can be renamed or assigned a dropdown/custom category. Corrections apply across that user's historical reports and future matches. Renaming to an existing merchant explicitly merges both groups, retaining source transactions and matching aliases.

Duplicate protection is scoped to the same user and card. Bank transaction IDs (including the numeric first field of this bank's Memo) are preferred. Without IDs, matching uses date, normalized description, signed amount, kind, and within-file occurrence count. This preserves repeated equal-value purchases within a file. Separate overlapping files containing indistinguishable same-day purchases without bank IDs cannot be perfectly disambiguated; the preview discloses this limitation. Reuse the same card entry for repeat/overlapping exports. A row with a reused bank ID but different financial data rejects the entire import. Account row locks and unique database keys protect concurrent imports; all statement writes are transactional.

### Analyzer verification

```sh
php tests/analyzer-test.php
python3 tests/web-test.py
```

An optional local sample path can be passed to either test command. The provided sample is never committed or seeded into a real user's account. Tests use disposable data, cover the full upload/preview/save/report flow, and check duplicate protection, custom categories, explicit merges, malformed rows, XSS escaping, CSRF, totals, and tenant isolation. The default SQLite test adapter omits MySQL row-lock syntax. To check the actual MySQL schema, use a disposable database whose name starts with `dan_test_` and set `ANALYZER_TEST_DSN`, `ANALYZER_TEST_USER`, and `ANALYZER_TEST_PASSWORD` for `tests/analyzer-test.php`.


## Automatic AI categories

The app uses the OpenAI Responses API with strict [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) and `store: false`. The default model is [GPT-4.1 Mini](https://developers.openai.com/api/docs/models/gpt-4.1-mini), configurable independently of other projects.

Copy `deploy/dan-ai.example.json` to `/home/cayeldo/domains/dan.cayelli.us/dan-ai.json`, outside `public_html`, and set the API key. Keep the file owned by `cayeldo` with permissions `0600`. Alternatively use `DAN_AI_CONFIG` for a private JSON path, or server-side `OPENAI_API_KEY` and `DAN_AI_MODEL`. Never commit a real key. The nautical app is not modified and its credentials are not required when a dedicated key is configured.

Only normalized unfamiliar merchant labels and category hints are submitted, together with category names for that user. Phone-like numbers and email addresses are removed from labels. Transaction amounts, dates, bank references, card/account names, raw memos, and entire statements are not sent. Requests use a fixed HTTPS OpenAI endpoint, verified TLS, no redirects, strict structured output, and application-side validation. `store: false` disables response storage for later retrieval; it does not assert zero provider retention for all purposes.

Each unknown merchant gets a durable `analyzer_ai_jobs` record within the import transaction. After saving, the app tries up to 20 merchants from that user's queue in one request, outside database locks. The existing minute-by-minute deployment job also runs `deploy/categorize.php` as `cayeldo`, backfilling previously unconfirmed merchants and processing remaining/retry jobs. There is no additional root cron entry. Pending reports can be refreshed to see results. Missing credentials leave jobs pending and imports still succeed.

A result chooses an existing category or creates a short general category when necessary. High/medium confidence results are applied automatically; low-confidence results are recorded as unresolved without guessing a confirmed category. Human edits always win and cancel pending AI work. Expiring leases prevent duplicate concurrent application; retries stop after three attempts, and API bodies/keys are never logged. AI source/status is visible in the audit, and original transactions remain unchanged. Learned categories are reused on future imports without extra API calls. AI usage is billed to the configured OpenAI project.

Run `php tests/ai-test.php` to test queuing, privacy boundaries, category creation/reuse, retries, malformed results, stale workers, and concurrent manual corrections using a fake transport (no billed requests). It also supports the disposable MariaDB test configuration described above. `python3 tests/web-test.py` explicitly disables real API calls in its temporary environment.

## Spending overview and month comparisons

The signed-in overview shows total purchases by month (up to 12 calendar months), an all-time category donut, and up to two notable increases and two decreases from the latest imported month to its preceding calendar month. Highlights require at least $25 of change plus either 20% spending change or 5 percentage points of bill share; new spending qualifies at $25. Chart points open monthly reports. Accessible tables expose the chart data.

Monthly reports compare each category’s dollars, percent change, and share of total purchases against the immediately preceding calendar month for the same card filter. Missing months are unavailable, never zero or replaced by the last imported month. New categories have no relative percentage; a month with zero purchases has no category-share denominator. Current-month figures are labeled “so far”; imported data may represent partial months. Purchases are before refunds and exclude card payments; net spending is reported separately.

All aggregate queries are scoped to the signed-in user and use stored transactions and current category assignments. No extra AI calls or schema migrations are required. Verify with `php tests/analytics-test.php` and `python3 tests/web-test.py [sample.csv]`. The analytics tests also accept the disposable MySQL DSN documented for the analyzer tests.

## Plaid Sandbox proof of concept

Open `/?page=plaid` after signing in, or choose **Connect card · Sandbox** in the navigation. Click **Connect Credit Card**, choose **First Platypus Bank**, use Plaid's sample `user_good` / `pass_good` credentials if prompted, and select the credit-card account. The page saves the connection and retrieves sample activity automatically. **Refresh transactions** retrieves a fresh snapshot; initial data preparation is retried briefly and can be retried manually later.

- The existing `/home/cayeldo/.config/dan/plaid.env` is read only. It must contain `PLAID_CLIENT_ID`, `PLAID_SECRET`, and `PLAID_ENV=sandbox`. No secrets belong in this repository. This code refuses any other environment and uses a fixed Sandbox API origin.
- One Sandbox connection per existing app user is stored in `/home/cayeldo/.config/dan/plaid-items/sandbox-user-ID.json`. The application creates the directory with mode `700`, files with `600`, uses per-user locks and atomic writes, and rejects storage inside its source/web directory. The file contains the access token, Item ID, public-token hash for retry handling, and an allowlisted transaction snapshot. Include this private directory in secure server backups. It is outside deployment and is never sent to the browser.
- `DAN_PLAID_CONFIG` and `DAN_PLAID_STORAGE` are optional server-side path overrides for isolated tests. They cannot be supplied through HTTP. No production environment switch or new login system is implemented.
- `plaid.php` contains the private configuration, API, storage, and snapshot logic. `plaid-controller.php` handles signed-in, CSRF-protected POST endpoints at `/?page=plaid&api=link-token`, `exchange`, and `transactions`. `plaid-link.js` loads Link with the short-lived Link token and returns the public token; it never receives an access token or secret. `views/plaid.php` renders the table with escaped provider data. Link's CSP allowances apply only to the signed-in Sandbox page.
- The proof uses `/transactions/get` for the last 90 days, paginated up to 1,000 transactions. Only credit-card accounts are requested and displayed. The snapshot is replaced on refresh rather than appended, and transaction IDs deduplicate pages. It shows date, merchant/description, account, signed amount/currency, pending status, and personal finance category/confidence (legacy category fallback). Charges are positive; credits/payments are negative. No Sandbox rows are imported into the analyzer database or sent to OpenAI.
- Credentials, tokens, raw API errors, and response bodies are never logged or included in API responses. Public-token exchange is repeat-safe, a second connection cannot replace the first, and a delayed/failed transaction fetch preserves the stored connection and previous snapshot.

Validation: `php tests/plaid-test.php` exercises storage, permissions, exchange retries, account filtering, pagination, categorization display fields, delayed data, and Production rejection with a fake transport. `python3 tests/web-test.py [sample.csv]` tests the authenticated HTTP endpoints and existing app flows in a disposable environment with synthetic Plaid responses; it makes no real Plaid calls. Deployment remains commit/push to `main`, followed by the existing minute-by-minute deployment job. No schema changes or new cron jobs are required.

Before real Elan/Fidelity cards: obtain Plaid Production access and approved Transactions access; recheck institution availability (`ins_102552` was identified during research), complete required Link/OAuth/redirect and consent setup, and deliberately implement a separate Production configuration and token store. Sandbox Items/tokens cannot be reused. Add authenticated webhook handling, durable `/transactions/sync` ingestion (added/modified/removed, pending-to-posted reconciliation and cursor recovery), connection management/update mode, and secure token lifecycle handling before automatic reporting imports. Transactions updates depend on institution/Plaid refresh schedules; this proof does not promise real-time authorization events.

Official references: [Link Web SDK](https://plaid.com/docs/link/web/), [Transactions API](https://plaid.com/docs/api/products/transactions/), [Sandbox credentials](https://plaid.com/docs/sandbox/test-credentials/).
