<?php
declare(strict_types=1);

/*
 * Veritabanı bilgileri .env dosyasından okunur.
 *
 *   LGS_DB_HOST=sql107.infinityfree.com
 *   LGS_DB_NAME=if0_37081401_lgs
 *   LGS_DB_USER=if0_37081401
 *   LGS_DB_PASS=********
 *
 * .env web'den erişilemez (.htaccess) ve sürüm kontrolüne girmez (.gitignore).
 * Şifre InfinityFree panelinde: MySQL Databases > (veritabanı) > Password.
 * Doğrulama: setup.php
 *
 * Not: putenv()/getenv() paylaşımlı hostinglerde kapalı olabildiği için
 * dosya doğrudan okunur; ortam değişkenleri yalnızca yedek olarak denenir.
 */

define('LGS_ENV_FILE', __DIR__ . '/.env');

$GLOBALS['LGS_ENV'] = [];

if (is_readable(LGS_ENV_FILE)) {
    define('LGS_ENV_LOADED', true);

    $raw = (string) file_get_contents(LGS_ENV_FILE);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // UTF-8 BOM

    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last  = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if ($key === '') {
            continue;
        }
        // Boş bir satır, aynı anahtarın dolu değerini ezmesin (son dolu değer kazanır).
        if ($value === '' && ($GLOBALS['LGS_ENV'][$key] ?? '') !== '') {
            continue;
        }

        $GLOBALS['LGS_ENV'][$key] = $value;
    }
} else {
    define('LGS_ENV_LOADED', false);
}

function env_or(string $key, string $default): string
{
    $fromFile = (string) ($GLOBALS['LGS_ENV'][$key] ?? '');
    if ($fromFile !== '') {
        return $fromFile;
    }

    $fromSystem = getenv($key);
    if ($fromSystem !== false && $fromSystem !== '') {
        return $fromSystem;
    }

    return $default;
}

/* Sunucu, kullanıcı ve veritabanı adı gizli değil; şifre yalnızca .env'den gelir. */
define('DB_HOST', env_or('LGS_DB_HOST', 'sql107.infinityfree.com'));
define('DB_NAME', env_or('LGS_DB_NAME', 'if0_37081401_lgs'));
define('DB_USER', env_or('LGS_DB_USER', 'if0_37081401'));
define('DB_PASS', env_or('LGS_DB_PASS', ''));
