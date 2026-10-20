<?php
declare(strict_types=1);

/*
 * Tek tıkla kurulum.
 *
 * .env'de yazılı veritabanına bağlanır, veritabanı yoksa oluşturmayı dener,
 * sonra lgs.sql şemasını oraya uygular. Şema CREATE TABLE IF NOT EXISTS
 * kullandığı için mevcut kayıtları silmez; tekrar çalıştırmak zararsızdır.
 * Eski answers / subject_state tabloları varsa kayıtları progress bloğuna
 * taşınır ve bu iki tablo düşürülür.
 *
 * Kurulumdan sonra bu dosyayı sunucudan sil.
 */

require __DIR__ . '/config.php';

function add_step(array &$steps, string $label, bool $ok, string $detail = ''): void
{
    $steps[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

$steps = [];
$db    = null;
$sql   = is_readable(__DIR__ . '/lgs.sql') ? (string) file_get_contents(__DIR__ . '/lgs.sql') : '';

/* 1. Veritabanı adı: tanımlayıcı olarak güvenli mi? */
$nameOk = DB_NAME !== '' && (bool) preg_match('/^[A-Za-z0-9_]+$/', DB_NAME);
add_step($steps, 'Veritabanı adı (.env)', $nameOk, DB_NAME !== '' ? DB_NAME : 'LGS_DB_NAME boş');

if ($nameOk) {
    /* 2. Sunucuya veritabanı seçmeden bağlan */
    try {
        $db = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        add_step($steps, 'Sunucuya bağlandı', true, DB_HOST);
    } catch (Throwable $e) {
        add_step($steps, 'Sunucuya bağlandı', false, $e->getMessage());
        $db = null;
    }
}

if ($db instanceof PDO) {
    /* 3. Veritabanı var mı? */
    $exists = false;
    try {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
        $stmt->execute([DB_NAME]);
        $exists = (bool) $stmt->fetchColumn();
        add_step($steps, 'Veritabanı kontrolü', true, $exists ? 'zaten var' : 'yok');
    } catch (Throwable $e) {
        add_step($steps, 'Veritabanı kontrolü', false, $e->getMessage());
    }

    /* 4. Yoksa oluşturmayı dene (InfinityFree'de yetki yok, orada panelden açılır) */
    if (!$exists) {
        try {
            $db->exec('CREATE DATABASE `' . DB_NAME . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            add_step($steps, 'Veritabanı oluşturuldu', true, DB_NAME);
        } catch (Throwable $e) {
            add_step(
                $steps,
                'Veritabanı oluşturuldu',
                false,
                $e->getMessage() . ' — kullanıcının CREATE yetkisi yoksa veritabanını panelden oluştur.'
            );
        }
    }

    /* 5. Seç ve şemayı uygula */
    $selected = false;
    try {
        $db->exec('USE `' . DB_NAME . '`');
        $selected = true;
        add_step($steps, 'Veritabanı seçildi', true, DB_NAME);
    } catch (Throwable $e) {
        add_step($steps, 'Veritabanı seçildi', false, $e->getMessage());
    }

    if ($selected) {
        $statements = [];
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*(?:\r?\n|$)/', (string) $withoutComments) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '') {
                $statements[] = $chunk;
            }
        }

        $applied  = 0;
        $failures = [];

        foreach ($statements as $statement) {
            try {
                $db->exec($statement);
                $applied++;
            } catch (Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }

        add_step(
            $steps,
            'Şema uygulandı',
            $failures === [] && $statements !== [],
            $statements === []
                ? 'lgs.sql okunamadı'
                : $applied . '/' . count($statements) . ' ifade'
                    . ($failures !== [] ? ' — ' . implode(' | ', array_slice($failures, 0, 3)) : '')
        );

        /* Eski şemadan kalan e-posta sütununu kaldır — artık kullanılmıyor. */
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([DB_NAME, 'users', 'email']);

            if ($stmt->fetchColumn()) {
                $db->exec('ALTER TABLE `users` DROP COLUMN `email`');
                add_step($steps, 'Eski e-posta sütunu kaldırıldı', true, 'users.email');
            }
        } catch (Throwable $e) {
            add_step($steps, 'Eski e-posta sütunu kaldırılamadı', false, $e->getMessage());
        }

        /* Admin / debug bayrağı — eski kurulumlarda sütun eksikse ekle. */
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([DB_NAME, 'users', 'is_admin']);

            if (!$stmt->fetchColumn()) {
                $db->exec('ALTER TABLE `users` ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`');
                add_step($steps, 'Admin bayrağı eklendi', true, 'users.is_admin');
            } else {
                add_step($steps, 'Admin bayrağı hazır', true, 'users.is_admin');
            }
        } catch (Throwable $e) {
            add_step($steps, 'Admin bayrağı eklenemedi', false, $e->getMessage());
        }

        /* Eski answers + subject_state kayıtlarını tek satırlık JSON bloğuna taşı. */
        try {
            $tables      = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $hasAnswers  = in_array('answers', $tables, true);
            $hasState    = in_array('subject_state', $tables, true);
            $hasProgress = in_array('progress', $tables, true);

            if (($hasAnswers || $hasState) && !$hasProgress) {
                add_step(
                    $steps,
                    'Eski tablolar taşınamadı',
                    false,
                    'progress tablosu oluşmadığı için answers / subject_state silinmedi'
                );
            } elseif ($hasProgress && ($hasAnswers || $hasState)) {
                $ids = [];
                if ($hasAnswers) {
                    $ids = array_merge($ids, $db->query('SELECT DISTINCT user_id FROM answers')->fetchAll(PDO::FETCH_COLUMN));
                }
                if ($hasState) {
                    $ids = array_merge($ids, $db->query('SELECT DISTINCT user_id FROM subject_state')->fetchAll(PDO::FETCH_COLUMN));
                }

                $insertAnswers = $hasAnswers
                    ? $db->prepare('SELECT subject, question_index, selected, is_correct FROM answers WHERE user_id = ?')
                    : null;
                $insertState = $hasState
                    ? $db->prepare('SELECT subject, total_questions, last_visited FROM subject_state WHERE user_id = ?')
                    : null;
                $save = $db->prepare(
                    'INSERT IGNORE INTO progress (user_id, data, answered, correct) VALUES (?, ?, ?, ?)'
                );

                $migrated = 0;

                foreach (array_unique(array_map('intval', $ids)) as $uid) {
                    $progress = [];
                    $visited  = [];
                    $answered = 0;
                    $correct  = 0;

                    if ($insertAnswers !== null) {
                        $insertAnswers->execute([$uid]);
                        foreach ($insertAnswers->fetchAll() as $row) {
                            $progress[$row['subject']][(string) (int) $row['question_index']] = [
                                'selected'  => strtoupper((string) $row['selected']),
                                'isCorrect' => (bool) (int) $row['is_correct'],
                            ];
                            $answered++;
                            if ((int) $row['is_correct'] === 1) {
                                $correct++;
                            }
                        }
                    }

                    if ($insertState !== null) {
                        $insertState->execute([$uid]);
                        foreach ($insertState->fetchAll() as $row) {
                            $subject = $row['subject'];
                            if (!isset($progress[$subject])) {
                                $progress[$subject] = [];
                            }
                            $progress[$subject]['_total'] = (int) $row['total_questions'];
                            if ((int) $row['last_visited'] > 0) {
                                $visited[$subject] = (int) $row['last_visited'];
                            }
                        }
                    }

                    $progress['_meta'] = ['lastVisited' => $visited];
                    $json = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
                    if (!is_string($json)) {
                        continue;
                    }

                    $save->execute([$uid, $json, $answered, $correct]);
                    $migrated++;
                }

                if ($hasAnswers) {
                    $db->exec('DROP TABLE `answers`');
                }
                if ($hasState) {
                    $db->exec('DROP TABLE `subject_state`');
                }

                add_step(
                    $steps,
                    'Eski tablolar tek satırlık bloğa taşındı',
                    true,
                    $migrated . ' kullanıcı — answers / subject_state silindi'
                );
            }
        } catch (Throwable $e) {
            add_step($steps, 'Eski tablolar taşınamadı', false, $e->getMessage());
        }

        try {
            $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach (['users', 'progress', 'deneme_attempts', 'remember_tokens'] as $table) {
                add_step($steps, 'Tablo: ' . $table, in_array($table, $tables, true), '');
            }
        } catch (Throwable $e) {
            add_step($steps, 'Tablo listesi okunamadı', false, $e->getMessage());
        }
    }
}

$allOk = $steps !== [] && !in_array(false, array_column($steps, 'ok'), true);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Kurulum | LGS Çözüm</title>
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
        <h1>Veritabanı Kurulumu</h1>
        <p class="setup-sub">
            <b>.env</b> dosyasındaki veritabanına bağlanır, yoksa oluşturmayı dener ve
            <b>lgs.sql</b> şemasını uygular.
        </p>

        <div class="setup-list">
            <?php foreach ($steps as $item): ?>
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

        <?php if ($allOk): ?>
            <div class="setup-verdict">
                <b>Kurulum tamam.</b> <a href="setup.php">Kurulum kontrolü</a> veya
                <a href="register.php">kayıt ol</a> sayfasına geç.
            </div>
        <?php else: ?>
            <div class="setup-verdict is-bad">
                <b>Kurulum tamamlanamadı.</b> <a href="setup.php">setup.php</a> sayfası hangi ayarın
                eksik olduğunu satır satır gösterir.
            </div>
        <?php endif; ?>

        <p class="setup-note">
            Bu sayfa hiçbir kaydı silmez (<b>CREATE TABLE IF NOT EXISTS</b>), ama kurulumdan sonra
            <b>install.php</b> dosyasını sunucudan sil.
        </p>
    </main>
</body>
</html>
