<?php
declare(strict_types=1);

function database(): PDO
{
    $path = getenv('DAN_CONFIG') ?: dirname(__DIR__) . '/dan-config.php';
    $config = is_file($path) ? require $path : [];
    $password = $config['password'] ?? getenv('DAN_DB_PASSWORD');
    if (!is_string($password) || $password === '') {
        throw new RuntimeException('Database password has not been configured.');
    }
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['host'] ?? 'localhost', $config['database'] ?? 'cayeldo_dan'),
        $config['username'] ?? 'cayeldo_dan',
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function password_error(string $password, string $confirmation): ?string
{
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        return 'Use a password between 12 and 72 bytes (usually characters).';
    }
    if ($password !== $confirmation) {
        return 'The passwords do not match. Please try again.';
    }
    return null;
}

/** Returns the authenticated user, or null for any invalid or locked account. */
function authenticate(PDO $db, string $username, string $password, ?string $setupCode): ?array
{
    $db->beginTransaction();
    try {
        // Serialize attempts and password creation for each account.
        $query = $db->prepare('SELECT * FROM users WHERE username = ? FOR UPDATE');
        $query->execute([$username]);
        $user = $query->fetch();
        $now = time();
        if (!$user || ($user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > $now)) {
            $db->rollBack();
            return null;
        }

        $valid = false;
        if ($setupCode !== null) {
            $valid = $user['password_hash'] === null
                && is_string($user['setup_token_hash'])
                && $user['setup_expires_at'] !== null
                && strtotime($user['setup_expires_at'] . ' UTC') > $now
                && hash_equals($user['setup_token_hash'], hash('sha256', $setupCode))
                && password_error($password, $password) === null;
        } elseif (is_string($user['password_hash']) && strlen($password) <= 72 && !str_contains($password, "\0")) {
            $valid = password_verify($password, $user['password_hash']);
        }

        if (!$valid) {
            $attempts = $user['locked_until'] !== null ? 1 : (int) $user['failed_attempts'] + 1;
            $query = $db->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
            $query->execute([$attempts, $attempts >= 5 ? gmdate('Y-m-d H:i:s', $now + 900) : null, $user['id']]);
            $db->commit();
            return null;
        }

        $hash = $user['password_hash'];
        if ($setupCode !== null || password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        }
        $query = $db->prepare('UPDATE users SET password_hash = ?, password_created_at = COALESCE(password_created_at, ?), setup_token_hash = NULL, setup_expires_at = NULL, failed_attempts = 0, locked_until = NULL, last_login_at = ? WHERE id = ?');
        $query->execute([$hash, gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now), $user['id']]);
        $db->commit();
        return ['id' => (int) $user['id'], 'username' => $user['username']];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function issue_setup_code(PDO $db, string $username): string
{
    $code = bin2hex(random_bytes(32));
    $query = $db->prepare('UPDATE users SET setup_token_hash = ?, setup_expires_at = ?, failed_attempts = 0, locked_until = NULL WHERE username = ? AND password_hash IS NULL');
    $query->execute([hash('sha256', $code), gmdate('Y-m-d H:i:s', time() + 86400), $username]);
    if ($query->rowCount() !== 1) {
        throw new RuntimeException('Account does not exist or already has a password.');
    }
    return $code;
}
