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
