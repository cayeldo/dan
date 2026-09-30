<?php
declare(strict_types=1);
require dirname(__DIR__) . '/auth.php';
require dirname(__DIR__) . '/admin.php';
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "PASS $label\n"; }
function rejects(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException | DomainException $e) { check(true, $label); return; }
    throw new RuntimeException('Expected rejection: ' . $label);
}
class AdminTestDatabase extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options); }
}
$dsn = getenv('ADMIN_TEST_DSN');
if ($dsn && !preg_match('/dbname=dan_test_[a-z0-9_]+(?:;|$)/', $dsn)) { throw new RuntimeException('Only disposable dan_test_ databases may be used.'); }
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$db = $dsn ? new PDO($dsn, 'root', '', $options) : new AdminTestDatabase('sqlite::memory:', null, null, $options);
$accounts = file_get_contents(dirname(__DIR__) . '/database/accounts.sql');
$start = strpos($accounts, 'CREATE TABLE');
$schema = substr($accounts, $start, strpos($accounts, ';', $start) - $start + 1) . "\n" . file_get_contents(dirname(__DIR__) . '/database/admin.sql');
if (!$dsn) {
    $db->exec('PRAGMA foreign_keys = ON');
    $schema = str_replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', 'INTEGER NOT NULL', $schema);
    $schema = preg_replace('/UNIQUE KEY `\w+` \(/', 'UNIQUE (', $schema);
    $schema = preg_replace('/\) ENGINE=InnoDB[^;]+;/', ');', $schema);
}
foreach (explode(';', $schema) as $statement) { if (trim($statement)) { $db->exec($statement); } }
$db->exec("INSERT INTO users (id, username) VALUES (1, 'don'), (2, 'dan')");
$db->exec('INSERT INTO app_admins (user_id) VALUES (1)');
check(user_is_admin($db, 1) && !user_is_admin($db, 2), 'admin access is explicitly granted per user');
rejects(fn() => admin_user_list($db, 2), 'member cannot read administrative account information');
rejects(fn() => admin_setup_code($db, 2, 'emilia', true), 'member cannot create users');
rejects(fn() => admin_setup_code($db, 1, '<script>', true), 'invalid usernames rejected');
$result = admin_setup_code($db, 1, ' Emilia ', true);
check($result['username'] === 'emilia' && strlen($result['code']) === 64, 'admin creates normalized username with random setup code');
$q = $db->query("SELECT * FROM users WHERE username = 'emilia'"); $emilia = $q->fetch();
check($emilia['password_hash'] === null && $emilia['setup_token_hash'] === hash('sha256', $result['code']), 'only code hash stored and no initial password assigned');
check(!user_is_admin($db, (int)$emilia['id']), 'new accounts are members by default');
check(abs(strtotime($result['expires'] . ' UTC') - time() - 86400) < 5, 'code expires in 24 hours');
rejects(fn() => admin_setup_code($db, 1, 'EMILIA', true), 'duplicate creation preserves original account');
$list = admin_user_list($db, 1);
check(!str_contains(json_encode($list), $emilia['setup_token_hash']) && !str_contains(json_encode($list), $result['code']), 'user list never discloses password or setup hashes/codes');
$new = admin_setup_code($db, 1, 'emilia', false);
$password = 'Emilia test-only passphrase';
check(authenticate($db, 'emilia', $password, $result['code']) === null, 'reissued code invalidates previous code');
check(authenticate($db, 'emilia', $password, $new['code'])['username'] === 'emilia', 'new code completes existing password setup flow');
rejects(fn() => admin_setup_code($db, 1, 'emilia', false), 'active passwords cannot be reset by setup code generation');
check(authenticate($db, 'emilia', $password, null)['username'] === 'emilia', 'active password remains valid');
check(authenticate($db, 'emilia', 'a different passphrase', $new['code']) === null, 'setup code cannot be replayed');
$db->exec('DELETE FROM app_admins WHERE user_id = 1');
rejects(fn() => admin_setup_code($db, 1, 'dan', false), 'revoked admin cannot generate codes with an existing session ID');
check((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3, 'failed actions leave account inventory intact');
echo "All admin checks passed.\n";
