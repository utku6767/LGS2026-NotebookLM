<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
session_boot();

if (auth_user()) {
    header('Location: index.php');
    exit;
}

$values = ['display_name' => '', 'username' => ''];
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['display_name'] = trim((string) ($_POST['display_name'] ?? ''));
    $values['username']     = trim((string) ($_POST['username'] ?? ''));
    $password               = (string) ($_POST['password'] ?? '');
    $passwordConfirm        = (string) ($_POST['password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.]{3,32}$/', $values['username'])) {
        $error = 'Kullanıcı adı 3-32 karakter olmalı; sadece harf, rakam, nokta ve alt çizgi kullanılabilir.';
    } elseif (mb_strlen($values['display_name']) > 60) {
        $error = 'Adın en fazla 60 karakter olabilir.';
    } elseif (strlen($password) < 8 || strlen($password) > 72) {
        $error = 'Şifre 8 ile 72 karakter arasında olmalı.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Şifreler eşleşmiyor.';
    }

    if ($error === null) {
        try {
            $pdo = db();

            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$values['username']]);

            if ($stmt->fetch()) {
                $error = 'Bu kullanıcı adı zaten alınmış.';
            } else {
                $displayName = $values['display_name'] !== '' ? $values['display_name'] : $values['username'];

                $pdo->prepare('INSERT INTO users (username, display_name, password_hash) VALUES (?, ?, ?)')
                    ->execute([$values['username'], $displayName, password_hash($password, PASSWORD_DEFAULT)]);

                auth_login([
                    'id'           => (int) $pdo->lastInsertId(),
                    'username'     => $values['username'],
                    'display_name' => $displayName,
                ]);

                $_SESSION['lgs_show_sync'] = true;

                header('Location: index.php');
                exit;
            }
        } catch (PDOException $e) {
            $message = $e->getMessage();
            error_log('LGS register DB: ' . $message);
            $error = str_contains($message, 'uq_users_username')
                ? 'Bu kullanıcı adı zaten alınmış.'
                : 'Kayıt oluşturulamadı. Kurulumu setup.php sayfasından kontrol et.';
        } catch (Throwable $e) {
            error_log('LGS register: ' . $e->getMessage());
            $error = 'Kayıt oluşturulamadı. Kurulumu setup.php sayfasından kontrol et.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kayıt Ol | LGS Çözüm</title>
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
        <h1>Kayıt Ol</h1>
        <p class="auth-sub">Çözdüğün sorular hesabına kaydedilsin; başka bir cihazdan da kaldığın yerden devam et.</p>

        <?php if ($error !== null): ?>
            <div class="auth-message is-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php">
            <label for="display_name">Adın</label>
            <input id="display_name" name="display_name" type="text" autocomplete="name"
                   maxlength="60" placeholder="Ekranda görünecek isim" value="<?= e($values['display_name']) ?>">

            <label for="username">Kullanıcı Adı</label>
            <input id="username" name="username" type="text" autocomplete="username"
                   pattern="[A-Za-z0-9_.]{3,32}" maxlength="32" value="<?= e($values['username']) ?>"
                   title="3-32 karakter: harf, rakam, nokta ve alt çizgi" required>
            <p class="auth-hint">3-32 karakter. Harf, rakam, nokta ve alt çizgi kullanabilirsin.</p>

            <label for="password">Şifre</label>
            <div class="password-field">
                <input id="password" name="password" type="password" autocomplete="new-password"
                       minlength="8" maxlength="72" required>
                <button type="button" class="password-toggle" data-target="password"
                        aria-label="Şifreyi göster">👁</button>
            </div>
            <p class="auth-hint">En az 8 karakter.</p>

            <label for="password_confirm">Şifre (Tekrar)</label>
            <div class="password-field">
                <input id="password_confirm" name="password_confirm" type="password"
                       autocomplete="new-password" minlength="8" maxlength="72" required>
                <button type="button" class="password-toggle" data-target="password_confirm"
                        aria-label="Şifreyi göster">👁</button>
            </div>

            <button type="submit" class="auth-submit">Kayıt Ol</button>
        </form>

        <p class="auth-alt">Zaten hesabın var mı? <a href="login.php">Giriş yap</a></p>
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

        const confirm2 = document.getElementById('password_confirm');
        confirm2.addEventListener('input', () => confirm2.setCustomValidity(''));

        document.querySelector('form').addEventListener('submit', (e) => {
            if (document.getElementById('password').value !== confirm2.value) {
                e.preventDefault();
                confirm2.setCustomValidity('Şifreler eşleşmiyor.');
                confirm2.reportValidity();
                return;
            }
            document.querySelector('.auth-submit').disabled = true;
        });
    </script>
</body>
</html>
