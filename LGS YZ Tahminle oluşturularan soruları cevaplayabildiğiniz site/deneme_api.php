<?php
declare(strict_types=1);

/*
 * Deneme (süreli sınav) API'si.
 *
 * Süre sunucu saatinden hesaplanır: deadline_at = started_at + mod süresi.
 * Cihaz kapansa da site kapansa da sayaç sunucuda işlemeye devam eder;
 * sayfa açıldığında kalan saniye sunucudan gelir.
 */

require __DIR__ . '/auth.php';
session_boot();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DENEME_MODES = [
    'sozel'   => ['minutes' => 75,  'subjects' => ['turkce', 'sosyal', 'din', 'ingilizce']],
    'sayisal' => ['minutes' => 80,  'subjects' => ['matematik', 'fen']],
    'maraton' => ['minutes' => 155, 'subjects' => ['turkce', 'sosyal', 'din', 'ingilizce', 'matematik', 'fen']],
];

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function load_attempt(int $userId, string $mode): ?array
{
    $stmt = db()->prepare(
        'SELECT mode, show_answers, started_at, deadline_at, finished_at, data,
                UNIX_TIMESTAMP(deadline_at) - UNIX_TIMESTAMP(NOW()) AS remaining
           FROM deneme_attempts
          WHERE user_id = ? AND mode = ?
          LIMIT 1'
    );
    $stmt->execute([$userId, $mode]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $remaining = (int) $row['remaining'];
    $finished  = $row['finished_at'] !== null;

    /* Süre sunucu saatinde dolduysa denemeyi burada kapat. */
    if (!$finished && $remaining <= 0) {
        db()->prepare('UPDATE deneme_attempts SET finished_at = deadline_at WHERE user_id = ? AND mode = ?')
            ->execute([$userId, $mode]);
        $finished  = true;
        $remaining = 0;
    }

    $answers = json_decode((string) $row['data'], true);

    return [
        'mode'         => (string) $row['mode'],
        'showAnswers'  => (bool) (int) $row['show_answers'],
        'startedAt'    => (string) $row['started_at'],
        'deadlineAt'   => (string) $row['deadline_at'],
        'finished'     => $finished,
        'remaining'    => max(0, $remaining),
        'totalMinutes' => DENEME_MODES[$mode]['minutes'],
        'answers'      => is_array($answers) ? $answers : [],
    ];
}

function save_answers(int $userId, string $mode, array $answers): void
{
    db()->prepare('UPDATE deneme_attempts SET data = ? WHERE user_id = ? AND mode = ?')
        ->execute([json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT), $userId, $mode]);
}

/* Verilen cevabı modun kapsadığı derslerle sınırlı tutar. */
function clean_answers(array $raw, string $mode): array
{
    $allowed = DENEME_MODES[$mode]['subjects'];
    $out     = [];

    foreach ($allowed as $subject) {
        $bucket = is_array($raw[$subject] ?? null) ? $raw[$subject] : [];
        $clean  = [];

        foreach ($bucket as $key => $value) {
            if (!preg_match('/^(?:0|[1-9]\d{0,2})$/', (string) $key)) {
                continue;
            }
            $selected = strtoupper(trim((string) $value));
            if (!in_array($selected, ['A', 'B', 'C', 'D'], true)) {
                continue;
            }
            $clean[(int) $key] = $selected;
        }

        if ($clean !== []) {
            ksort($clean);
            $out[$subject] = $clean;
        }
    }

    return $out;
}

if (auth_user() === null) {
    respond(['ok' => false, 'error' => 'auth'], 401);
}

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $attempts = [];
    foreach (array_keys(DENEME_MODES) as $mode) {
        $attempt = load_attempt($userId, $mode);
        if ($attempt !== null) {
            $attempts[$mode] = $attempt;
        }
    }

    $modes = [];
    foreach (DENEME_MODES as $name => $config) {
        $modes[$name] = ['minutes' => $config['minutes'], 'subjects' => $config['subjects']];
    }

    respond([
        'ok'       => true,
        'modes'    => $modes,
        'attempts' => $attempts,
        'now'      => date('c'),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['ok' => false, 'error' => 'method'], 405);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
if ($origin !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) !== $host) {
    respond(['ok' => false, 'error' => 'origin'], 403);
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    respond(['ok' => false, 'error' => 'payload'], 400);
}

$mode = (string) ($body['mode'] ?? '');
if (!isset(DENEME_MODES[$mode])) {
    respond(['ok' => false, 'error' => 'mode'], 400);
}

try {
    switch ((string) ($body['action'] ?? '')) {
        case 'start':
            $existing = load_attempt($userId, $mode);
            if ($existing !== null) {
                respond(['ok' => true, 'attempt' => $existing, 'created' => false]);
            }

            $minutes = DENEME_MODES[$mode]['minutes'];
            db()->prepare(
                'INSERT INTO deneme_attempts (user_id, mode, show_answers, started_at, deadline_at, data)
                 VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ' . $minutes . ' MINUTE), ?)'
            )->execute([$userId, $mode, empty($body['showAnswers']) ? 0 : 1, '{}']);

            respond(['ok' => true, 'attempt' => load_attempt($userId, $mode), 'created' => true]);

        case 'answer':
            $attempt = load_attempt($userId, $mode);
            if ($attempt === null) {
                respond(['ok' => false, 'error' => 'no_attempt'], 404);
            }
            if ($attempt['finished']) {
                respond(['ok' => false, 'error' => 'finished', 'attempt' => $attempt], 409);
            }

            $subject = (string) ($body['subject'] ?? '');
            if (!in_array($subject, DENEME_MODES[$mode]['subjects'], true)) {
                respond(['ok' => false, 'error' => 'subject'], 400);
            }

            $index = (int) ($body['index'] ?? -1);
            $selected = strtoupper(trim((string) ($body['selected'] ?? '')));
            if ($index < 0 || $index > 999 || !in_array($selected, ['A', 'B', 'C', 'D'], true)) {
                respond(['ok' => false, 'error' => 'answer'], 400);
            }

            $answers = clean_answers($attempt['answers'], $mode);
            $answers[$subject][$index] = $selected;
            save_answers($userId, $mode, $answers);

            respond(['ok' => true, 'attempt' => load_attempt($userId, $mode), 'saved' => true]);

        case 'finish':
            $attempt = load_attempt($userId, $mode);
            if ($attempt === null) {
                respond(['ok' => false, 'error' => 'no_attempt'], 404);
            }

            if (!$attempt['finished']) {
                db()->prepare('UPDATE deneme_attempts SET finished_at = NOW() WHERE user_id = ? AND mode = ?')
                    ->execute([$userId, $mode]);
            }

            respond(['ok' => true, 'attempt' => load_attempt($userId, $mode)]);

        case 'reset':
            db()->prepare('DELETE FROM deneme_attempts WHERE user_id = ? AND mode = ?')
                ->execute([$userId, $mode]);
            respond(['ok' => true, 'removed' => true]);

        /* --- gizli debug araçları: yalnızca Konami kodu açıkken --- */

        case 'debug_finish':
            if (!debug_enabled()) {
                respond(['ok' => false, 'error' => 'debug'], 403);
            }

            if (load_attempt($userId, $mode) === null) {
                respond(['ok' => false, 'error' => 'no_attempt'], 404);
            }

            db()->prepare(
                'UPDATE deneme_attempts
                    SET finished_at = NOW(), deadline_at = NOW()
                  WHERE user_id = ? AND mode = ?'
            )->execute([$userId, $mode]);

            respond(['ok' => true, 'attempt' => load_attempt($userId, $mode), 'debug' => true]);

        case 'debug_deadline':
            if (!debug_enabled()) {
                respond(['ok' => false, 'error' => 'debug'], 403);
            }

            $attempt = load_attempt($userId, $mode);
            if ($attempt === null) {
                respond(['ok' => false, 'error' => 'no_attempt'], 404);
            }
            if ($attempt['finished']) {
                respond(['ok' => true, 'attempt' => $attempt, 'debug' => true]);
            }

            $seconds = max(1, min(600, (int) ($body['seconds'] ?? 10)));

            db()->prepare(
                'UPDATE deneme_attempts
                    SET deadline_at = DATE_ADD(NOW(), INTERVAL ' . $seconds . ' SECOND)
                  WHERE user_id = ? AND mode = ?'
            )->execute([$userId, $mode]);

            respond(['ok' => true, 'attempt' => load_attempt($userId, $mode), 'debug' => true]);
    }
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => 'server', 'detail' => $e->getMessage()], 500);
}

respond(['ok' => false, 'error' => 'action'], 400);
