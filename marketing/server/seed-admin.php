<?php
declare(strict_types=1);

/**
 * Create (or promote) an admin account.
 *
 * Usage: php seed-admin.php <email> <password> [name]
 */

require_once __DIR__ . '/db.php';

$email = $argv[1] ?? '';
$password = $argv[2] ?? '';
$name = $argv[3] ?? 'Administrator';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php seed-admin.php <email> <password> [name]\n");
    exit(2);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(2);
}

$email = strtolower($email);
$stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
$u = $stmt->fetch();

if ($u !== false) {
    db()->prepare("UPDATE users SET role = 'admin', status = 'active', email_verified = 1, password_hash = ? WHERE id = ?")
        ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$u['id']]);
    fwrite(STDOUT, "Promoted existing user {$email} to admin.\n");
} else {
    db()->prepare("INSERT INTO users (email, password_hash, name, account_type, role, email_verified, status) VALUES (?, ?, ?, 'personal', 'admin', 1, 'active')")
        ->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
    fwrite(STDOUT, "Created admin {$email}.\n");
}
