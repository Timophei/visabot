<?php
declare(strict_types=1);

/**
 * Пользователи админки и вход.
 *
 *  - Вход по логину (не почте) и паролю. Пароли — password_hash().
 *  - «Запомнить этот компьютер»: долгоживущая кука (selector:validator).
 *    В базе хранится только хеш validator; срок продлевается при каждом входе.
 *    Выход удаляет токен этого компьютера; смена пароля / блокировка — все токены пользователя.
 *  - Роли. Сейчас: «Администратор» (всё, включая пользователей) и «Менеджер» (всё, кроме пользователей).
 *    Чтобы ограничить роль, добавьте право в ROLE_DENY и проверку can('право') в нужном месте.
 *  - Первый запуск: если пользователей нет, а в config.php есть admin_password_hash —
 *    создаётся пользователь «admin» с этим паролем. Если нет и его — админка предложит создать первого.
 */

const ROLES = ['admin' => 'Администратор', 'manager' => 'Менеджер'];
/** Что роли запрещено. Права: users — управление пользователями. Остальное пока доступно всем. */
const ROLE_DENY = ['manager' => ['users']];

const REMEMBER_COOKIE = 'visabot_remember';
const REMEMBER_DAYS   = 60;
const LOGIN_MAX_FAILS = 10;   // неудачных попыток с одного IP…
const LOGIN_WINDOW    = 900;  // …за 15 минут — дальше вход временно закрыт

function auth_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        login TEXT NOT NULL UNIQUE COLLATE NOCASE,
        name TEXT NOT NULL DEFAULT '',
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'admin',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        last_login_at TEXT
    );
    CREATE TABLE IF NOT EXISTS admin_tokens (
        selector TEXT PRIMARY KEY,
        validator_hash TEXT NOT NULL,
        user_id INTEGER NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
        expires_at INTEGER NOT NULL,
        user_agent TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS admin_tokens_user ON admin_tokens(user_id);
    SQL);
    // Переход со старого единого пароля
    if (!(int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() && cfg('admin_password_hash')) {
        $pdo->prepare("INSERT INTO admin_users(login, name, password_hash, role) VALUES('admin', 'Администратор', ?, 'admin')")
            ->execute([cfg('admin_password_hash')]);
    }
    $pdo->prepare('DELETE FROM admin_tokens WHERE expires_at < ?')->execute([time()]);
}

function users_exist(): bool
{
    auth_migrate();
    return (bool)db()->query('SELECT 1 FROM admin_users LIMIT 1')->fetchColumn();
}

function user_by_id(int $id): ?array
{
    auth_migrate();
    $st = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function list_users(): array
{
    auth_migrate();
    return db()->query('SELECT u.*, (SELECT COUNT(*) FROM admin_tokens t WHERE t.user_id = u.id) AS devices
                        FROM admin_users u ORDER BY u.active DESC, u.login')->fetchAll();
}

/** Логин: латиница, цифры, точка, дефис, подчёркивание; 2–32 символа. */
function valid_login(string $login): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/i', $login);
}

function check_password_strength(string $pass): void
{
    if (mb_strlen($pass) < 8) throw new RuntimeException('Пароль — не короче 8 символов.');
}

function create_user(string $login, string $name, string $pass, string $role): int
{
    auth_migrate();
    $login = trim($login);
    if (!valid_login($login)) throw new RuntimeException('Логин: 2–32 символа — латиница, цифры, точка, дефис, подчёркивание.');
    if (!isset(ROLES[$role])) throw new RuntimeException('Неизвестная роль.');
    check_password_strength($pass);
    $st = db()->prepare('SELECT 1 FROM admin_users WHERE login = ?');
    $st->execute([$login]);
    if ($st->fetchColumn()) throw new RuntimeException("Логин «{$login}» уже занят.");
    db()->prepare('INSERT INTO admin_users(login, name, password_hash, role) VALUES(?, ?, ?, ?)')
        ->execute([$login, trim($name), password_hash($pass, PASSWORD_DEFAULT), $role]);
    return (int)db()->lastInsertId();
}

/** Сколько активных администраторов, кроме указанного. */
function other_active_admins(int $exceptId): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND active = 1 AND id != ?");
    $st->execute([$exceptId]);
    return (int)$st->fetchColumn();
}

function update_user(int $id, array $d): void
{
    $u = user_by_id($id) ?? throw new RuntimeException('Пользователь не найден.');
    $role = $d['role'] ?? $u['role'];
    $active = isset($d['active']) ? (int)(bool)$d['active'] : (int)$u['active'];
    if (!isset(ROLES[$role])) throw new RuntimeException('Неизвестная роль.');
    if ($u['role'] === 'admin' && ($role !== 'admin' || !$active) && !other_active_admins($id)) {
        throw new RuntimeException('Это последний администратор — его нельзя понизить или отключить.');
    }
    db()->prepare('UPDATE admin_users SET name = ?, role = ?, active = ? WHERE id = ?')
        ->execute([trim((string)($d['name'] ?? $u['name'])), $role, $active, $id]);
    if (!$active) forget_all_devices($id);
}

function set_user_password(int $id, string $pass): void
{
    check_password_strength($pass);
    db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
    forget_all_devices($id);
}

function delete_user(int $id): void
{
    $u = user_by_id($id) ?? throw new RuntimeException('Пользователь не найден.');
    if ($u['role'] === 'admin' && $u['active'] && !other_active_admins($id)) {
        throw new RuntimeException('Это последний администратор — его нельзя удалить.');
    }
    db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
}

function forget_all_devices(int $userId): void
{
    db()->prepare('DELETE FROM admin_tokens WHERE user_id = ?')->execute([$userId]);
}

/* ---------------- вход / выход ---------------- */

function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }

function login_blocked(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM rate WHERE k = ? AND ts > ?');
    $st->execute(['login:' . client_ip(), time() - LOGIN_WINDOW]);
    return (int)$st->fetchColumn() >= LOGIN_MAX_FAILS;
}

function login_failed(): void
{
    db()->prepare('INSERT INTO rate(k, ts) VALUES(?, ?)')->execute(['login:' . client_ip(), time()]);
}

/** Проверяет логин и пароль. Возвращает пользователя или null. */
function attempt_login(string $login, string $pass): ?array
{
    auth_migrate();
    $st = db()->prepare('SELECT * FROM admin_users WHERE login = ?');
    $st->execute([trim($login)]);
    $u = $st->fetch();
    // password_verify и для несуществующего логина — чтобы время ответа не выдавало, есть ли такой
    $ok = password_verify($pass, $u['password_hash'] ?? '$2y$10$bDjLj/PvgqfpJqL4FLsnT.UR2lRi2eQ1N5JF83TinuhqNWqNVGSzG');
    if (!$u || !$ok || !$u['active']) { login_failed(); return null; }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    db()->prepare('DELETE FROM rate WHERE k = ?')->execute(['login:' . client_ip()]);
    return $u;
}

function start_user_session(array $u, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    unset($_SESSION['ok']);
    db()->prepare("UPDATE admin_users SET last_login_at = datetime('now') WHERE id = ?")->execute([$u['id']]);
    if ($remember) issue_remember_token((int)$u['id']);
}

function cookie_opts(int $expires): array
{
    return ['expires' => $expires, 'path' => '/admin/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])];
}

function issue_remember_token(int $userId): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    db()->prepare('INSERT INTO admin_tokens(selector, validator_hash, user_id, expires_at, user_agent) VALUES(?, ?, ?, ?, ?)')
        ->execute([$selector, hash('sha256', $validator), $userId, $expires, mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    setcookie(REMEMBER_COOKIE, "$selector:$validator", cookie_opts($expires));
}

function clear_remember_cookie(): void
{
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw !== '') {
        db()->prepare('DELETE FROM admin_tokens WHERE selector = ?')->execute([explode(':', $raw)[0]]);
        setcookie(REMEMBER_COOKIE, '', cookie_opts(time() - 3600));
    }
}

/** Вход по куке «запомнить». Срок продлевается при каждом входе (скользящие 60 дней). */
function login_from_cookie(): ?array
{
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if (!preg_match('/^([0-9a-f]{18}):([0-9a-f]{64})$/', $raw, $m)) return null;
    auth_migrate();
    $st = db()->prepare('SELECT * FROM admin_tokens WHERE selector = ? AND expires_at > ?');
    $st->execute([$m[1], time()]);
    $t = $st->fetch();
    if (!$t || !hash_equals($t['validator_hash'], hash('sha256', $m[2]))) { clear_remember_cookie(); return null; }
    $u = user_by_id((int)$t['user_id']);
    if (!$u || !$u['active']) { clear_remember_cookie(); return null; }
    $expires = time() + REMEMBER_DAYS * 86400;
    db()->prepare('UPDATE admin_tokens SET expires_at = ? WHERE selector = ?')->execute([$expires, $m[1]]);
    setcookie(REMEMBER_COOKIE, $raw, cookie_opts($expires));
    start_user_session($u, false);
    return $u;
}

/** Текущий пользователь (или null). */
function current_user(): ?array
{
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (!empty($_SESSION['uid'])) {
        $u = user_by_id((int)$_SESSION['uid']);
        if (!$u || !$u['active']) { $u = null; unset($_SESSION['uid']); }
    }
    return $u ??= login_from_cookie();
}

function logout(): void
{
    clear_remember_cookie();
    $_SESSION = [];
    session_destroy();
}

/** Есть ли у текущего пользователя право. */
function can(string $perm): bool
{
    $u = current_user();
    return $u && !in_array($perm, ROLE_DENY[$u['role']] ?? [], true);
}
