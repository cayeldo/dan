<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth.php';

// Exercise authentication with a real in-memory database. MySQL row-lock syntax
// is omitted here; run a MySQL integration check before production deployment.
final class TestDatabase extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
}
function account(PDO $db, string $name): array
{
    $query = $db->prepare('SELECT * FROM users WHERE username = ?');
    $query->execute([$name]);
    return $query->fetch();
}
$db = new TestDatabase('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, password_hash TEXT, setup_token_hash TEXT, setup_expires_at TEXT, password_created_at TEXT, failed_attempts INTEGER NOT NULL DEFAULT 0, locked_until TEXT, last_login_at TEXT)');
$db->exec("INSERT INTO users (username) VALUES ('don'), ('dan')");
$password = 'a private test passphrase';
check(password_error('short', 'short') !== null, 'short passwords rejected');
check(password_error(str_repeat('x', 73), str_repeat('x', 73)) !== null, 'bcrypt truncation prevented');
check(password_error("a long password\0", "a long password\0") !== null, 'null bytes rejected');
check(password_error($password, 'different password') !== null, 'confirmation mismatch rejected');
check(password_error($password, $password) === null, 'passphrase accepted');
check(authenticate($db, 'don', $password, null) === null, 'uninitialized account cannot sign in');
check(authenticate($db, 'don', $password, '') === null, 'account cannot be claimed without a setup code');
check(authenticate($db, "don' OR 1=1 --", $password, null) === null, 'SQL injection does not authenticate');

$oldCode = issue_setup_code($db, 'don');
$code = issue_setup_code($db, 'don');
check($code !== $oldCode && account($db, 'don')['setup_token_hash'] === hash('sha256', $code), 'only the new setup code hash is stored');
check(authenticate($db, 'don', $password, $oldCode) === null, 'replaced setup code rejected');
check(authenticate($db, 'dan', $password, $code) === null, 'setup code cannot claim another account');
check(authenticate($db, 'don', $password, $code)['username'] === 'don', 'first login creates password and authenticates');
$don = account($db, 'don');
check($don['password_hash'] !== $password && password_verify($password, $don['password_hash']), 'password stored as a verifiable hash');
check($don['setup_token_hash'] === null && $don['setup_expires_at'] === null, 'setup code removed on use');
check(authenticate($db, 'don', 'replacement passphrase', $code) === null, 'setup code replay cannot overwrite password');
check(authenticate($db, 'don', $password, null)['username'] === 'don', 'subsequent login succeeds');
try {
    issue_setup_code($db, 'don');
    throw new LogicException('Existing account unexpectedly received a new code');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Account does not exist or already has a password.', 'initialized account cannot be re-enrolled');
}
for ($i = 0; $i < 5; $i++) {
    check(authenticate($db, 'don', 'wrong password', null) === null, 'wrong password rejected, attempt ' . ($i + 1));
}
check(account($db, 'don')['locked_until'] !== null, 'fifth failure locks the account');
check(authenticate($db, 'don', $password, null) === null, 'correct password cannot bypass active lock');
$db->exec("UPDATE users SET locked_until = '2000-01-01 00:00:00' WHERE username = 'don'");
check(authenticate($db, 'don', $password, null)['username'] === 'don', 'login works after lock expires');
check((int) account($db, 'don')['failed_attempts'] === 0, 'successful login resets failures');

$danCode = issue_setup_code($db, 'dan');
$db->exec("UPDATE users SET setup_expires_at = '2000-01-01 00:00:00' WHERE username = 'dan'");
check(authenticate($db, 'dan', $password, $danCode) === null, 'expired setup code rejected');
$danCode = issue_setup_code($db, 'dan');
check(authenticate($db, 'dan', $password, $danCode)['username'] === 'dan', 'second seeded account can create its own password');
echo "Authentication checks passed (SQLite adapter; MySQL locking and schema require a MySQL server).\n";
