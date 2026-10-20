<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
session_boot();

debug_handle_post(auth_user() ? 'index.php' : 'login.php');

if (auth_user()) {
    header('Location: index.php');
    exit;
}

$identifier = '';
$error      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password   = (string) ($_POST['password'] ?? '');
    $remember   = !empty($_POST['remember']);

    if ($identifier === '' || $password === '') {
        $error = 'Kullanıcı adı ve şifre gerekli.';
    } else {
        try {
            $pdo  = db();
            $stmt = $pdo->prepare(
                'SELECT id, username, display_name, password_hash, is_active, is_admin FROM users WHERE username = ? LIMIT 1'
            );
            $stmt->execute([$identifier]);
            $user = $stmt->fetch();
        } catch (Throwable $e) {
            error_log('LGS login DB: ' . $e->getMessage());
            $user  = null;
            $error = 'Sunucuya bağlanılamadı. Kurulumu setup.php sayfasından kontrol et.';
        }

        if ($error === null) {
            if (!$user || !password_verify($password, (string) $user['password_hash'])) {
                $error = 'Kullanıcı adı veya şifre hatalı.';
            } elseif (!(int) $user['is_active']) {
                $error = 'Bu hesap devre dışı bırakılmış.';
            } else {
                if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
                }

                auth_login($user);
                $_SESSION['lgs_show_sync'] = true;

                if ($remember) {
                    remember_issue((int) $user['id']);
                } else {
                    remember_forget();
                }

                $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);

                header('Location: index.php');
                exit;
            }
        }
    }
}

$notice = isset($_GET['loggedout']) ? 'Çıkış yaptın.' : null;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş Yap | LGS Çözüm</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="auth.css?v=<?= (int) @filemtime(__DIR__ . '/auth.css') ?>">
</head>
<body>
    <a class="auth-brand" href="index.php">
        <span class="auth-brand-icon">🎯</span>
        <span>LGS ÇÖZÜM</span>
    </a>

    <main class="auth-card">
        <h1>Giriş Yap</h1>
        <p class="auth-sub">Hesabına giriş yap, ilerlemen hem bu cihazda hem bulutta saklansın.</p>

        <?php if ($error !== null): ?>
            <div class="auth-message is-error"><?= e($error) ?></div>
        <?php elseif ($notice !== null): ?>
            <div class="auth-message is-success"><?= e($notice) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <label for="identifier">Kullanıcı Adı</label>
            <input id="identifier" name="identifier" type="text" autocomplete="username"
                   maxlength="32" value="<?= e($identifier) ?>" required autofocus>

            <label for="password">Şifre</label>
            <div class="password-field">
                <input id="password" name="password" type="password" autocomplete="current-password"
                       maxlength="72" required>
                <button type="button" class="password-toggle" data-target="password"
                        aria-label="Şifreyi göster">👁</button>
            </div>

            <label class="remember-row" for="remember">
                <input type="checkbox" id="remember" name="remember" value="1" checked>
                <span>Beni hatırla</span>
            </label>
            <p class="auth-hint">Bu cihazda 30 gün boyunca açık kalırsın.</p>

            <button type="submit" class="auth-submit">Giriş Yap</button>
        </form>

        <p class="auth-alt">Hesabın yok mu? <a href="register.php">Kayıt ol</a></p>
    </main>

    <script>
        document.querySelectorAll('.password-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.target);
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.textContent = show ? '🙈' : '👁';
                btn.setAttribute('aria-label', show ? 'Şifreyi gizle' : 'Şifreyi göster');
                input.focus();
            });
        });

        document.querySelector('form').addEventListener('submit', () => {
            document.querySelector('.auth-submit').disabled = true;
        });
    </script>
    <script src="konami.js?v=<?= (int) @filemtime(__DIR__ . '/konami.js') ?>"></script>
</body>
</html>
