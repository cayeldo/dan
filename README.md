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
- The signed-in screen is a protected welcome page with sign-out; no other app functionality was requested yet. Future private routes must perform their own session checks.
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
