<?php
declare(strict_types=1);

function user_is_admin(PDO $db, int $userId): bool
{
    $query = $db->prepare('SELECT user_id FROM app_admins WHERE user_id = ?');
    $query->execute([$userId]);
    return $query->fetchColumn() !== false;
}

/** Authorization is checked again within the write transaction, never from form/session roles. */
function admin_setup_code(PDO $db, int $actor, string $username, bool $create): array
{
    $username = strtolower(trim($username));
    if (!preg_match('/\A[a-z0-9_]{1,50}\z/', $username)) {
        throw new InvalidArgumentException('Use 1–50 letters, numbers, or underscores for the username.');
    }
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT user_id FROM app_admins WHERE user_id = ? FOR UPDATE');
        $query->execute([$actor]);
        if ($query->fetchColumn() === false) { throw new DomainException('Administrator access is required.'); }
        $query = $db->prepare('SELECT id, password_hash FROM users WHERE username = ? FOR UPDATE');
        $query->execute([$username]); $existing = $query->fetch();
        if ($create) {
            if ($existing) { throw new InvalidArgumentException('That username already exists. Use its Generate setup code button if it is awaiting setup.'); }
            $query = $db->prepare('INSERT INTO users (username) VALUES (?)'); $query->execute([$username]);
        } elseif (!$existing || $existing['password_hash'] !== null) {
            throw new InvalidArgumentException('Setup codes are available only for users who have not created a password yet.');
        }
        $code = issue_setup_code($db, $username);
        $query = $db->prepare('SELECT setup_expires_at FROM users WHERE username = ?'); $query->execute([$username]);
        $expires = $query->fetchColumn();
        $db->commit();
        return ['username' => $username, 'code' => $code, 'expires' => $expires, 'issued_at' => time()];
    } catch (Throwable $error) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $error;
    }
}

function admin_user_list(PDO $db, int $actor): array
{
    if (!user_is_admin($db, $actor)) { throw new DomainException('Administrator access is required.'); }
    return $db->query('SELECT u.id, u.username, CASE WHEN u.password_hash IS NULL THEN 0 ELSE 1 END AS activated, u.setup_expires_at, u.last_login_at, CASE WHEN a.user_id IS NULL THEN 0 ELSE 1 END AS is_admin FROM users u LEFT JOIN app_admins a ON a.user_id = u.id ORDER BY u.username')->fetchAll();
}
