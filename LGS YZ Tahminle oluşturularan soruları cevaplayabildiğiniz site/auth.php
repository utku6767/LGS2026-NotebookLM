<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

const REMEMBER_COOKIE = 'lgs_remember';
const REMEMBER_DAYS = 30;

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cookie_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function session_boot(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $savePath = (string) session_save_path();

        // Bazı ücretsiz hostinglerde save_path yazılamaz olur; o zaman geçici dizine düş.
        $checkPath = preg_replace('/^\d+;/', '', $savePath);
        if ($checkPath === '' || !is_dir($checkPath) || !is_writable($checkPath)) {
            $fallback = sys_get_temp_dir();
            if ($fallback !== '' && is_dir($fallback) && is_writable($fallback)) {
                session_save_path($fallback);
            }
        }

        session_set_cookie_params([
            'path'     => '/',
            'secure'   => cookie_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        remember_restore();
    }
}

function auth_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id'           => (int) $_SESSION['user_id'],
        'username'     => (string) ($_SESSION['username'] ?? ''),
        'display_name' => (string) ($_SESSION['display_name'] ?? ''),
        'is_admin'     => !empty($_SESSION['is_admin']),
    ];
}

function auth_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']      = (int) $user['id'];
    $_SESSION['username']     = (string) $user['username'];
    $_SESSION['display_name'] = (string) ($user['display_name'] ?: $user['username']);
    $_SESSION['is_admin']     = !empty($user['is_admin']);
    $_SESSION['is_admin_at']  = time();
}

function auth_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

/* ---------- gizli debug modu (Konami kodu) ---------- */

/* .env içinde LGS_DEBUG=0 yazılırsa Konami kodu hiçbir şey yapmaz. */
function debug_allowed(): bool
{
    return env_or('LGS_DEBUG', '1') !== '0';
}

function debug_enabled(): bool
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if (empty($_SESSION['user_id'])) {
        return $cache = false;
    }

    /* Konami koduyla açılan oturumluk debug. */
    if (debug_allowed() && !empty($_SESSION['lgs_debug'])) {
        return $cache = true;
    }

    /* users.is_admin = 1 → kalıcı admin (her cihaz/oturum).
       Sonuç 5 dakika önbelleklenir; phpMyAdmin'den açınca en geç 5 dk sonra geçerli. */
    $checkedAt = (int) ($_SESSION['is_admin_at'] ?? 0);
    if ($checkedAt > 0 && (time() - $checkedAt) < 300) {
        return $cache = !empty($_SESSION['is_admin']);
    }

    try {
        $stmt = db()->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $_SESSION['is_admin'] = (int) $stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        /* Sütun henüz eklenmemiş olabilir (install.php çalıştırılmadı). */
        $_SESSION['is_admin'] = false;
    }

    $_SESSION['is_admin_at'] = time();

    return $cache = (bool) $_SESSION['is_admin'];
}

function debug_set(bool $enabled): void
{
    if ($enabled) {
        $_SESSION['lgs_debug'] = true;
    } else {
        unset($_SESSION['lgs_debug']);
    }
}

/*
 * Konami kodu form POST'unu işler.
 *
 * Ayrı bir uç dosya (debug.php) yerine sayfanın kendisine POST edilir:
 * hostinglerin yeni dosyalara/AJAX'a uyguladığı engeller devre dışı kalır.
 * Her sayfada session_boot() çağrısının hemen ardından çalıştırılır.
 */
function debug_handle_post(string $page): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['lgs_konami'])) {
        return;
    }

    if (!debug_allowed()) {
        header('Location: ' . $page . '?konami=blocked');
        exit;
    }

    $enable = !debug_enabled();
    debug_set($enable);

    $query = '?konami=' . ($enable ? 'on' : 'off');

    if (!empty($_SESSION['is_admin'])) {
        $query .= '&admin=1';
    }

    header('Location: ' . $page . $query);
    exit;
}

function remember_issue(int $userId): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ? AND expires_at < NOW()')->execute([$userId]);

    $selector  = bin2hex(random_bytes(8));
    $validator = bin2hex(random_bytes(32));

    $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . REMEMBER_DAYS . ' DAY))'
    )->execute([$userId, $selector, hash('sha256', $validator)]);

    remember_write_cookie($selector . ':' . $validator);
}

function remember_forget(): void
{
    $selector = remember_cookie_selector($_COOKIE[REMEMBER_COOKIE] ?? '');

    if ($selector !== null) {
        db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    }

    remember_write_cookie('', time() - 42000);
}

function remember_restore(): void
{
    $raw = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw === '') {
        return;
    }

    $parts = explode(':', $raw);
    if (count($parts) !== 2 || !preg_match('/^[0-9a-f]{16}$/', $parts[0]) || !preg_match('/^[0-9a-f]{64}$/', $parts[1])) {
        remember_write_cookie('', time() - 42000);
        return;
    }

    try {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT id, user_id, validator_hash FROM remember_tokens WHERE selector = ? AND expires_at > NOW() LIMIT 1');
        $stmt->execute([$parts[0]]);
        $token = $stmt->fetch();

        if (!$token || !hash_equals((string) $token['validator_hash'], hash('sha256', $parts[1]))) {
            remember_write_cookie('', time() - 42000);
            return;
        }

        $stmt = $pdo->prepare('SELECT id, username, display_name, is_admin FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int) $token['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            remember_forget();
            return;
        }

        auth_login($user);

        $validator = bin2hex(random_bytes(32));
        $pdo->prepare(
            'UPDATE remember_tokens SET validator_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL ' . REMEMBER_DAYS . ' DAY) WHERE id = ?'
        )->execute([hash('sha256', $validator), (int) $token['id']]);

        remember_write_cookie($parts[0] . ':' . $validator);
    } catch (Throwable $e) {
        return;
    }
}

function remember_write_cookie(string $value, ?int $expires = null): void
{
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires ?? time() + REMEMBER_DAYS * 86400,
        'path'     => '/',
        'secure'   => cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($value === '') {
        unset($_COOKIE[REMEMBER_COOKIE]);
    } else {
        $_COOKIE[REMEMBER_COOKIE] = $value;
    }
}

function remember_cookie_selector(string $raw): ?string
{
    $parts = explode(':', $raw);
    return (count($parts) === 2 && preg_match('/^[0-9a-f]{16}$/', $parts[0])) ? $parts[0] : null;
}
