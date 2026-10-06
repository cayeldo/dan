# KLE Coin

PHP login screen for the existing DirectAdmin site and MySQL/MariaDB database.
Requires PHP 8.1+ with PDO MySQL and HTTPS in production. No Composer or Node dependencies.

## Public URL and deployment

The primary public URL is **https://klecoin.com**. Use this origin for future absolute URLs, links in emails, canonical URLs, and other public links. `https://dan.cayelli.us` redirects to it.

The deployment architecture intentionally remains unchanged. DirectAdmin/Apache serves `klecoin.com` from `/home/cayeldo/domains/dan.cayelli.us/public_html`. Keep using the existing `deploy/deploy-dan.sh` script and cron job; push to `main` and allow roughly a minute for changes to appear on `klecoin.com`.

Do not move or duplicate application files or private configuration into the `klecoin.com` domain directory. Preserve the existing database, private configuration paths, SimpleFIN storage, and background workers. The old domain name in server paths is intentional and must not be replaced as part of a public URL or branding change.

## Install

1. In phpMyAdmin, select `cayeldo_dan`, open **SQL**, and run [`database/accounts.sql`](database/accounts.sql). It creates `users` and seeds `don` and `dan`. Running it again will not reset passwords. Both accounts initially have `password_hash = NULL`; neither can log in until its owner chooses a password.
2. Copy `deploy/dan-config.example.php` to `/home/cayeldo/domains/dan.cayelli.us/dan-config.php` (one directory **above** `public_html`). Enter the database password from DirectAdmin. This is the database connection password, not Don's or Dan's login password. Restrict the file to the PHP service user (for example owner `cayeldo`, mode `0600`). Alternatively set `DAN_CONFIG` to a private PHP config file or `DAN_DB_PASSWORD` in the PHP environment. Never put real credentials in this repository.
3. Deploy the root `index.php`, `auth.php`, `styles.css`, and `.htaccess` files to `public_html`, replacing the old `index.html`. The existing deployment script has been updated to omit SQL, tests, and administrative files. It deploys committed `origin/main`, so local edits do not affect the live site until committed and pushed. PHP must be enabled; if using Nginx without Apache, set its index to `index.php` and deny hidden files/directory listings.
4. From the server's repository checkout, generate separate private setup codes:

   ```sh
   DAN_CONFIG=/home/cayeldo/domains/dan.cayelli.us/dan-config.php php deploy/create-setup-code.php don
   DAN_CONFIG=/home/cayeldo/domains/dan.cayelli.us/dan-config.php php deploy/create-setup-code.php dan
   ```

5. Give each code privately to its account owner. Each person opens `https://klecoin.com`, chooses **Create your password**, and enters their username, setup code, and chosen password twice. They are signed in immediately. Later visits use username and password only.

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

Open **Admin / Setup → Imports & AI reviews**, choose or name a card, select the CSV's charge sign convention, and preview the upload. Nothing is written until **Save transactions**. Files spanning multiple months produce separate calendar-month reports using transaction dates. The original description, memo, date, signed amount in cents, bank reference, and import provenance are retained. Uploaded CSV files themselves are not retained; previews expire after 30 minutes.

Select a pie slice or category label to reveal its **Audit your categories** group, then expand a merchant to inspect every dated charge and credit or correct its category. Category names next to the chart link directly to these groups. Purchases, credits, and net spending are shown at each level; credit-only categories remain available even when they have no pie-chart slice. The audit follows the selected month and card. **Browse categories** exposes all groups, including credit-only categories. Merchant totals are collapsed by default; recent imports are under Admin / Setup.

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

The app uses the OpenAI Responses API with strict [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) and `store: false`. The default model for categorization and monthly reviews is [GPT-6.1 Sol](https://developers.openai.com/api/docs/models/gpt-6.1-sol), configurable independently of other projects. Categorization uses low reasoning effort; monthly reviews use medium. Both allow up to 8,192 output tokens including reasoning, while retaining their existing JSON schemas and short review length. Requests time out after 60 seconds; the existing AI cron workers allow 90 seconds to finish and record results. Explicit legacy model overrides retain their previous request settings.

Copy `deploy/dan-ai.example.json` to `/home/cayeldo/domains/dan.cayelli.us/dan-ai.json`, outside `public_html`, and set the API key. Keep the file owned by `cayeldo` with permissions `0600`. Alternatively use `DAN_AI_CONFIG` for a private JSON path, or server-side `OPENAI_API_KEY` and `DAN_AI_MODEL`. An existing `model` value in the private JSON file overrides the code default; set it to `gpt-6.1-sol` when upgrading (and check for a higher-priority `DAN_AI_MODEL` environment override). The change applies to future AI jobs; saved reviews and learned categories are not regenerated. Never commit a real key. The nautical app is not modified and its credentials are not required when a dedicated key is configured.

Only normalized unfamiliar merchant labels and category hints are submitted, together with category names for that user. Phone-like numbers and email addresses are removed from labels. Transaction amounts, dates, bank references, card/account names, raw memos, and entire statements are not sent. Requests use a fixed HTTPS OpenAI endpoint, verified TLS, no redirects, strict structured output, and application-side validation. `store: false` disables response storage for later retrieval; it does not assert zero provider retention for all purposes.

Each unknown merchant gets a durable `analyzer_ai_jobs` record within the import transaction. After saving, the app tries up to 20 merchants from that user's queue in one request, outside database locks. The existing minute-by-minute deployment job also runs `deploy/categorize.php` as `cayeldo`, backfilling previously unconfirmed merchants and processing remaining/retry jobs. There is no additional root cron entry. Pending reports can be refreshed to see results. Missing credentials leave jobs pending and imports still succeed.

A result chooses an existing category or creates a short general category when necessary. High/medium confidence results are applied automatically; low-confidence results are recorded as unresolved without guessing a confirmed category. Human edits always win and cancel pending AI work. Expiring leases prevent duplicate concurrent application; retries stop after three attempts, and API bodies/keys are never logged. AI source/status is visible in the audit, and original transactions remain unchanged. Learned categories are reused on future imports without extra API calls. AI usage is billed to the configured OpenAI project.

Run `php tests/ai-test.php` to test queuing, privacy boundaries, category creation/reuse, retries, malformed results, stale workers, and concurrent manual corrections using a fake transport (no billed requests). It also supports the disposable MariaDB test configuration described above. `python3 tests/web-test.py` explicitly disables real API calls in its temporary environment.

## Spending overview and month comparisons

The signed-in overview shows total purchases by month (up to 12 calendar months), an all-time category donut, and up to two notable increases and two decreases from the latest imported month to its preceding calendar month. Highlights require at least $25 of change plus either 20% spending change or 5 percentage points of bill share; new spending qualifies at $25. Chart points open monthly reports. A twelve-month tile chart highlights the highest spending month and shows the average of imported months before the current month; missing months are excluded and partial uploaded months may still affect the average. Exact values appear on chart hover or keyboard focus using the local `charts.js` progressive enhancement. Accessible tables and the detailed month comparison are collapsed by default; the comparison appears below the analyzer pie and merchant disclosure. Category colors stay consistent across charts.

Monthly reports compare each category’s dollars, percent change, and share of total purchases against the immediately preceding calendar month for the same card filter. Missing months are unavailable, never zero or replaced by the last imported month. New categories have no relative percentage; a month with zero purchases has no category-share denominator. Current-month figures are labeled “so far”; imported data may represent partial months. Purchases are before refunds and exclude card payments; net spending is reported separately.

All aggregate queries are scoped to the signed-in user and use stored transactions and current category assignments. No extra AI calls or schema migrations are required. Verify with `php tests/analytics-test.php` and `python3 tests/web-test.py [sample.csv]`. The analytics tests also accept the disposable MySQL DSN documented for the analyzer tests.

## SimpleFIN automatic imports

The **Connect card** navigation item (`/?page=connect`) now offers SimpleFIN Bridge. Each existing app user can connect one SimpleFIN feed and select one USD credit card. Sign up with SimpleFIN, verify the card works there, create a setup token at https://bridge.simplefin.org/simplefin/create, then paste it into this app. Institution listing does not guarantee a particular Fidelity/Elan login will work.

The setup token is exchanged once, server-side, for a Basic Auth access URL. Neither credential is returned in HTML. The access URL and cached account snapshot are stored in `/home/cayeldo/.config/dan/simplefin/user-ID.json`, outside the web root and repository, with directory permissions 700 and file permissions 600. Per-user file locks and atomic writes protect connection changes. The PHP client accepts only HTTPS URLs at the two official Bridge hosts, validates the claim/access paths, disables redirects, and limits response size and duration. Include this private directory in secure backups. `DAN_SIMPLEFIN_STORAGE` is available for isolated tests; production uses the default private path.

After inspecting the account preview, select the credit card, match it to an existing CSV card or create a new card, and choose a start date. Existing-card imports must begin after the latest recorded transaction date. Later CSV uploads may include already-saved automatic imports: a unique match on date, signed amount, transaction kind, and normalized merchant is skipped. Matching uses source descriptions and the built-in merchant rules, never editable categories or AI guesses. Each matched CSV identity is remembered in `analyzer_csv_feed_matches`, with at most one CSV identity per bank transaction, so subsequent exports cannot reuse that transaction for a distinct reference. Unknown merchants require matching descriptions after case/whitespace normalization. Missing or ambiguous matches in the automatic-import period still stop the whole upload for review; refresh the feed or upload earlier history. This assumes CSV dates match the provider's posted dates in UTC. Choose the existing card when it already has CSV history. Boundaries and saved matches remain after disconnect/reconfiguration to protect history. Matching is heuristic across providers; equal date/amount/merchant values cannot establish identity with certainty when the sources use unrelated IDs.

Initial requests cover the preceding 44 days. Bridge's published guide says 90 days, but the live demo returned a warning for ranges above 45 days, so this implementation uses the smaller window. Available bank history may be shorter. The existing deployment cron calls `deploy/sync-simplefin.php`; each enabled connection is due about every six hours, with a random offset. One due connection is handled per cron invocation. Manual refresh is limited to hourly. Updates depend on the bank/provider schedule and are not real-time authorizations. Subsequent requests overlap five days; resume requests the full available window. Pauses/outages longer than 44 days may leave a history gap that needs reconciliation.

Posted transactions flow into the existing merchant categorization, AI queue, monthly reports, and portal charts. Amounts are stored in integer cents; expenses are negative, refunds/payments positive. Positive descriptions matching payment keywords are excluded from spending as card payments. Pending activity is preview-only. Stable account/transaction IDs prevent duplicates; corrected amounts/dates update existing records and preserve merchant/category choices. Transactions missing from a later response are not deleted, since a response can be partial. Provider errors are shown with credentials redacted and stop imports for that refresh. Demo tokens are preview-only. Pause stops scheduled imports; disconnect removes the local access credential and leaves history intact. Revoke the app token in SimpleFIN as well to invalidate it at the provider.

Additive tables `analyzer_simplefin_links` and `analyzer_simplefin_history` contain account mappings and date boundaries, never access credentials. The normal migration/deployment cron applies them. Helpers are blocked by `.htaccess`; forms require existing login and CSRF tokens. No new login accounts or separate cron installation is needed.

Validation: `php tests/simplefin-test.php` covers the shipped schema, URL validation, private storage, isolation, duplicate IDs, pending transitions, amount corrections, category preservation, CSV boundaries, pauses/disconnects, and demo protection. It can also run against a disposable `dan_test_*` MariaDB database via `ANALYZER_TEST_DSN`. `python3 tests/web-test.py` exercises connection, selection, reporting, CSRF, escaping, token secrecy, and isolation using synthetic transports. Real public-demo retrieval is tested separately without saving demo transactions into live reports.

References: [SimpleFIN Bridge developer guide](https://beta-bridge.simplefin.org/info/developers), [SimpleFIN protocol](https://www.simplefin.org/protocol.html).

## Admin user management

`/?page=admin` lets an explicitly designated administrator create users and issue setup codes. Don is the initial administrator in the live database. Admin membership is stored in the additive `app_admins` table and checked against the database on each request and again inside mutation transactions; it is not taken from session roles or submitted fields. The normal migration creates the table without granting roles or changing any passwords. Membership can be granted/revoked only by an operator with database access.

New users are regular members. **Add user & generate code** creates the account and automatically issues a cryptographically random one-time code. **Generate setup code** replaces the code for an account awaiting its first password. Each code expires in 24 hours, is stored only as a SHA-256 hash in the database, and is cleared after successful password setup. The plaintext code is briefly held in the administrator's server-side session for the POST/redirect/GET flow, shown once on a `no-store` page, and never put in a URL or log. Copy it and share it privately with the intended user, along with their username and `https://klecoin.com/?mode=setup`. Users choose their own passwords. Active passwords cannot be reset using these controls.

`admin.php`, `admin-controller.php`, and `views/admin.php` implement the flow; the helpers are blocked by `.htaccess`. All mutations require authentication, current admin membership, and CSRF protection. Admin membership grants user onboarding only; existing spending queries remain scoped to the signed-in user. No automatic emails or role-management UI are included.

Validation: `php tests/admin-test.php` checks permissions, role revocation, normalization, duplicates, hash-only storage, expiration, code replacement, successful signup, and replay prevention. Set `ADMIN_TEST_DSN` to a disposable `dan_test_*` MariaDB database to verify the production SQL dialect. `python3 tests/web-test.py` also exercises the admin HTTP flow, CSRF, one-time display, user signup, and denial of member access.


## Saved monthly AI reviews

On the **5th of each month, Eastern time**, the existing worker automatically queues the previous calendar month for users with imported transactions in that month. No monthly confirmation or additional cron installation is needed. Runs later in the same month catch up if the worker was unavailable on the 5th. Scheduling is idempotent, respects months explicitly marked incomplete, and does not backfill all older history or infer zero activity from absent transactions. Automatic reviews describe an imported-data snapshot; they do not certify complete bank coverage.

**Admin / Setup → Imports & AI reviews → Monthly AI reviews** still allows explicit confirmation of complete months to request an earlier review, review older history, or include zero-activity months. The current/future month cannot be confirmed. Only confirmed complete months enter the historical comparison baseline; automatic snapshots are not silently treated as confirmed history.

The existing deployment job runs `deploy/review-months.php` after categorization. It generates at most one review per worker run, newest eligible month first. User-row locks plus the `(user_id, month)` primary key reserve a review before the network request. Page views never call OpenAI. Completed reviews are immutable and are read from `analyzer_month_reviews`; the input snapshot, model, prompt version, attempts and timestamp are retained. Failures/abandoned requests do not automatically retry (an uncertain request may already have incurred tokens); an explicit retry is available only for failed reviews. Category jobs for the reviewed month finish first.

Month/category aggregates and a bounded merchant summary are sent through the existing Responses API configuration, with `store: false` and a strict JSON response schema. The merchant summary combines the top 10 merchants by purchase count and top 10 by purchase total, deduplicated, so frequent small purchases are not lost behind larger expenses. It includes normalized merchant names (with phone-like numbers and email addresses removed), categories, purchase counts, distinct purchase-day counts, totals, average purchase sizes, and spending shares. Refunds and card payments are excluded. No raw transaction rows, individual dates, card labels, memos, credentials or account numbers are sent. The prompt uses casual, direct language for adults in their 20s: natural contractions and everyday words without forced slang, lectures, or financial jargon. It asks for a specific one- or two-sentence teaser (at most 240 characters), an evidence-based positive, an honest concern, and 2–3 realistic things to try. Setbacks become learning opportunities without sugarcoating or invented praise. Specific merchant patterns and category budget context take priority over generic advice. Counts are transactions, not assumed visits; the prompt prohibits invented causes such as attributing higher groceries to home cooking or lower travel costs. Reviews refer directly to spending without generic imported-card or entire-finances disclaimers. New attempts record prompt version 4; previously saved reviews keep their original wording. Comparisons use the median of up to six earlier **confirmed complete** months within the preceding year; fewer than three months are called limited history, and absent history never produces invented comparisons. Refunds and card payments are separated from purchases. `budget: null` reserves a future input extension without inventing budget targets.

The analyzer shows the review directly below the pie chart, with a brief preview and a keyboard-accessible **Read more / Read less** disclosure for the full analysis. Long older summaries are shortened locally for the preview without another AI request.

For explicitly confirmed months, a transaction fingerprint invalidates completeness when financial records change, hiding the review until the user confirms the corrected month again. Automatic snapshots stay visible and show a changed-data notice when later imports alter the reviewed facts. The original saved review is then shown with a changed-data notice; it is not silently regenerated. Category edits similarly flag the saved snapshot as outdated. Reviews cover all imported cards; multi-card filters do not show a misleading all-card review. The overview links to the newest available saved review.

Migration is additive in `database/analyzer.sql`. Verify with `php tests/monthly-reviews-test.php` and `python3 tests/web-test.py`. Tests use stubbed model responses and disposable data, with no paid API calls.


## Monthly budgets

**Budget** is a separate menu item. Saved plans in `analyzer_budgets` take effect from their month onward, until a later saved change. Targets are integer cents and must initially be explicitly entered (zero is valid). Historical averages are suggestions only, shown in hover, keyboard-focus, or tap/click information bubbles. No OpenAI calls are involved and no targets are filled from suggestions.

For a new plan, categories with an unrounded average monthly purchase total strictly greater than $50 get separate fields. Exactly $50 and lower categories share **Misc**, whose suggestion is the combined monthly average. The baseline uses all earlier imported months before the chosen budget month, excludes the ongoing current month, includes category-zero months and explicitly confirmed empty months, and excludes unobserved gaps. Hints show the number and span of months; imported periods may be partial. Refunds and card payments do not lower the purchase baseline.

A saved plan keeps its category IDs/names for that month. Misc means all categories outside the explicit items, including later-created categories, so subsequent imports cannot silently rearrange targets. Inherited plans keep their grouping. Before saving a change, elapsed inherited months are frozen in `analyzer_budget_snapshots` under the same user lock; these snapshots do not override future targets. Explicitly editing a historical month replaces its snapshot, without rewriting other historical months. Database user locks, CSRF, server-derived membership, and an optimistic revision fingerprint prevent cross-user writes and stale-tab overwrites. The analyzer shows target minus purchases across all imported cards, even with a single-card report selected. More than 25% available is green, 0–25% is amber, and negative is red. Category totals reconcile to the overall budget; Misc links to its constituent categories. Current-month pace uses purchases through today divided by elapsed days times days in the month. Projections require at least seven elapsed days, three purchases, and a purchase within seven days; estimates disclose missing-data and irregular-spending limitations. No AI is called for progress or pace. Current-month comparisons use the same day cutoff in the previous month (capped at its last day); ended but unconfirmed months are labeled incomplete. New final AI reviews receive overall/category targets, actuals, offsets, and a retrospective halfway pace checkpoint. Previously saved reviews remain unchanged and disclose when they did not include a budget.

Verify with `php tests/budgets-test.php` and `python3 tests/web-test.py` (disposable data only).

## Spending statements and setup navigation

Top-level navigation is Overview, Credit card analyzer, Statements, and Admin / Setup. Budget, Connect card, and Imports & AI reviews are setup subsections available for each user's own data. User management is shown only to administrators and remains protected by the existing server-side permission checks. Existing budget/connect/admin URLs still work.

Statements are calendar-month KLE Coin spending records, not card-issuer billing statements. The ledger is sorted ascending by transaction date and ID, with original descriptions, categories, purchases, refunds, and payments. It uses the same ledger and totals as the analyzer. Net spending excludes card payments. In-progress and unconfirmed periods are labeled; no balances, due dates, or payment terms are invented. All-card budgets and saved AI reviews are included only when the statement covers all imported cards. Viewing or exporting never generates AI output or marks a month complete.

PDF export uses the vendored official Dompdf 3.1.6 release and its bundled Unicode fonts. Remote assets, PDF JavaScript, and embedded PHP execution are disabled. Temporary files use a random, private directory outside the web root and are cleaned after rendering. PDFs are returned only within an authenticated session with `Cache-Control: no-store`; the app does not save generated PDFs. The original deployment directory and cron remain unchanged. Verify with `php tests/statements-test.php` and `python3 tests/web-test.py`.
