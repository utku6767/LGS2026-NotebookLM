<?php
declare(strict_types=1);

/*
 * Kurulum doğrulama sayfası.
 * Ayarlar tamamlandıktan sonra bu dosyayı sunucudan sil.
 */

require __DIR__ . '/config.php';

function check(string $label, bool $ok, string $detail = ''): array
{
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

$checks = [];

/* 1. PHP */
$checks[] = check('PHP sürümü 8.0 veya üzeri', PHP_VERSION_ID >= 80000, PHP_VERSION);

/* 2. PDO MySQL */
$hasPdo = extension_loaded('pdo_mysql');
$checks[] = check('PDO MySQL sürücüsü', $hasPdo, $hasPdo ? 'yüklü' : 'eksik — hosting desteğine yaz');

/* 3. Ayar değerleri */
$hostOk   = DB_HOST !== '' && !str_contains(DB_HOST, 'XXX');
$passOk   = DB_PASS !== '';
$checks[] = check(
    'Kimlik bilgileri kaynağı',
    LGS_ENV_LOADED,
    LGS_ENV_LOADED
        ? '.env okunuyor'
        : '.env bulunamadı — .env.example dosyasını .env olarak kopyala, şifreyi doldur ve sunucuya yükle'
);
$checks[] = check('Veritabanı sunucusu', $hostOk, DB_HOST . ($hostOk ? '' : ' — InfinityFree kullanıyorsan paneldeki sqlXXX.infinityfree.com adresini yaz; yerel kurulumda localhost olur'));
$checks[] = check('Veritabanı adı', DB_NAME !== '', DB_NAME);
$checks[] = check('Kullanıcı adı', DB_USER !== '', DB_USER);
$checks[] = check('Şifre', $passOk, $passOk ? 'girilmiş' : 'boş — .env içindeki LGS_DB_PASS satırına kontrol panelindeki MySQL şifresini yaz');

/* .env teşhisi — değerler gösterilmez, sadece anahtar adı ve uzunluk. */
$envReport = [];
foreach (($GLOBALS['LGS_ENV'] ?? []) as $envKey => $envValue) {
    $envReport[] = $envKey . ' = ' . ((string) $envValue === '' ? 'boş' : mb_strlen((string) $envValue) . ' karakter');
}

$envMeta = is_readable(LGS_ENV_FILE)
    ? sprintf('%.1f KB · %s', filesize(LGS_ENV_FILE) / 1024, date('d.m.Y H:i', (int) filemtime(LGS_ENV_FILE)))
    : 'dosya yok';

/* 4. Oturum — gerçek yaz/oku turu (session kalıcı mı?) */
$sessionOk     = false;
$sessionDetail = '';
try {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_start();

    $probe = bin2hex(random_bytes(6));
    $_SESSION['lgs_setup_probe'] = $probe;
    session_write_close();

    session_start();
    $sessionOk = (($_SESSION['lgs_setup_probe'] ?? '') === $probe);
    unset($_SESSION['lgs_setup_probe']);
    session_write_close();

    $sessionDetail = 'save_path: ' . (session_save_path() !== '' ? session_save_path() : 'varsayılan');
    if (!$sessionOk) {
        $sessionDetail .= ' — oturum verisi saklanamıyor; giriş yaptıktan sonra sayfa seni tekrar login.php\'ye atar.';
    }
} catch (Throwable $e) {
    $sessionDetail = $e->getMessage();
}
$checks[] = check('Oturum (session) çalışıyor', $sessionOk, $sessionDetail);

/* 5. Bağlantı ve tablolar */
$required = ['users', 'progress', 'deneme_attempts', 'remember_tokens'];
$pdoError = null;
$tables   = [];

if ($hasPdo) {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $pdoError = $e->getMessage();
    }
}

$checks[] = check(
    'MySQL bağlantısı',
    $pdoError === null,
    $pdoError ?? (DB_HOST . ' / ' . DB_NAME . ' bağlantısı kuruldu')
);

foreach ($required as $table) {
    $exists   = in_array($table, $tables, true);
    $checks[] = check('Tablo: ' . $table, $exists, $exists ? '' : 'phpMyAdmin > Import (lgs.sql) veya tek tıkla install.php');
}

$missingTables = array_values(array_diff($required, $tables));

/* Admin/debug bayrağı sütunu (users.is_admin) */
if ($pdoError === null && in_array('users', $tables, true)) {
    try {
        $column = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'is_admin'")->fetch();
        $hasAdminColumn = $column !== false;
        $checks[] = check(
            'Sütun: users.is_admin',
            $hasAdminColumn,
            $hasAdminColumn ? '' : 'install.php bir kez açılınca eklenir (ALTER TABLE users ADD is_admin)'
        );
    } catch (Throwable $e) {
        $checks[] = check('Sütun: users.is_admin', false, $e->getMessage());
    }
}

$counts = [];
if ($pdoError === null) {
    foreach ($required as $table) {
        if (in_array($table, $tables, true)) {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
    }
}

$allOk = !in_array(false, array_column($checks, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Kurulum Kontrolü | LGS Çözüm</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="auth.css?v=<?= (int) @filemtime(__DIR__ . '/auth.css') ?>">
    <link rel="stylesheet" href="admin.css?v=<?= (int) @filemtime(__DIR__ . '/admin.css') ?>">
</head>
<body>
    <a class="auth-brand" href="index.php">
        <span class="auth-brand-icon">🎯</span>
        <span>LGS ÇÖZÜM</span>
    </a>

    <main class="setup-card">
        <h1>Kurulum Kontrolü</h1>
        <p class="setup-sub">Giriş ve kayıt sayfalarının çalışması için gereken her şey burada listelenir.</p>

        <div class="setup-list">
            <?php foreach ($checks as $item): ?>
                <div class="setup-row <?= $item['ok'] ? 'is-good' : 'is-bad' ?>">
                    <span class="setup-mark"><?= $item['ok'] ? '✓' : '✕' ?></span>
                    <div>
                        <div class="setup-label"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($item['detail'] !== ''): ?>
                            <div class="setup-detail"><?= htmlspecialchars($item['detail'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="setup-note">
            <b>.env</b> <?= htmlspecialchars($envMeta, ENT_QUOTES, 'UTF-8') ?> —
            <?= $envReport ? htmlspecialchars(implode(' · ', $envReport), ENT_QUOTES, 'UTF-8') : 'anahtar bulunamadı' ?>
            <?php if (!$passOk): ?>
                <br>LGS_DB_PASS görünmüyorsa dosya gerçekten <b>.env</b> adıyla mı yüklendi kontrol et:
                Windows uzantıları gizlediği için <b>.env.txt</b> olarak kaydedilmiş olabilir.
                Dosyayı Not Defteri yerine kod editöründe düzenle, satır sonunda boşluk bırakma.
            <?php endif; ?>
        </p>

        <?php if ($pdoError === null && $missingTables): ?>
            <p class="setup-note">
                Eksik tablolar: <b><?= htmlspecialchars(implode(', ', $missingTables), ENT_QUOTES, 'UTF-8') ?></b> —
                <b>install.php</b> sayfasını aç: <b>.env</b>'deki veritabanına bağlanır, veritabanı yoksa oluşturmayı dener,
                sonra <b>lgs.sql</b> şemasını oraya uygular.
            </p>
        <?php endif; ?>

        <?php if ($counts): ?>
            <p class="setup-note">
                Kayıt sayıları:
                <?php foreach ($counts as $table => $count): ?>
                    <br><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?>: <?= $count ?>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <?php if ($allOk): ?>
            <div class="setup-verdict">
                <b>Her şey hazır.</b> <a href="register.php">Kayıt ol</a> veya <a href="login.php">giriş yap</a> sayfasını aç.
            </div>
        <?php else: ?>
            <div class="setup-verdict is-bad">
                <b>Eksik var.</b> Yukarıdaki ✕ işaretli satırları düzeltip sayfayı yenile.
            </div>
        <?php endif; ?>

        <p class="setup-note">
            Notlar: Bu sayfayı mutlaka <b>http(s)://</b> adresinden aç — dosyayı çift tıklayıp
            <b>file://</b> ile açarsan PHP çalışmaz ve giriş sayfası hiçbir şey yapmaz.
            <b>.env</b> dosyası sunucuya yüklenmeli; <b>.htaccess</b> onu tarayıcıdan gizler.
            Ayarlar tamamlanınca <b>setup.php</b> dosyasını sunucudan sil.
        </p>
    </main>
</body>
</html>
