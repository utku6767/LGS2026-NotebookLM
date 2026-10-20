<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';

session_boot();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const SUBJECTS = ['matematik', 'turkce', 'fen', 'din', 'sosyal', 'ingilizce'];
const MAX_INDEX = 65535;
const MAX_BLOB_BYTES = 524288;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function empty_progress(): array
{
    return ['_meta' => (object) ['lastVisited' => (object) []]];
}

/*
 * İstemciden gelen ilerlemeyi bilinen şekle indirger: yalnızca tanımlı
 * dersler, 0..65535 arası soru sırası, A-D seçeneği, bool doğruluk.
 * Tek satırda saklanan JSON bloğunun şekli budur.
 */
function sanitize_progress(array $raw): array
{
    $visitedIn = is_array($raw['_meta']['lastVisited'] ?? null) ? $raw['_meta']['lastVisited'] : [];
    $visited   = [];
    $out       = [];

    foreach (SUBJECTS as $subject) {
        $bucket = is_array($raw[$subject] ?? null) ? $raw[$subject] : [];
        $clean  = [];

        foreach ($bucket as $key => $value) {
            if ($key === '_total' || !is_array($value) || !isset($value['selected'])) {
                continue;
            }

            if (!preg_match('/^(?:0|[1-9]\d{0,4})$/', (string) $key)) {
                continue;
            }

            $index = (int) $key;
            if ($index > MAX_INDEX) {
                continue;
            }

            $selected = strtoupper(trim((string) $value['selected']));
            if (!in_array($selected, ['A', 'B', 'C', 'D'], true)) {
                continue;
            }

            $clean[(string) $index] = [
                'selected'  => $selected,
                'isCorrect' => !empty($value['isCorrect']),
            ];
        }

        $total = max(0, min((int) ($bucket['_total'] ?? 0), MAX_INDEX));
        if ($total > 0) {
            $clean['_total'] = $total;
        }

        if ($clean !== []) {
            $out[$subject] = $clean;
        }
    }

    foreach (SUBJECTS as $subject) {
        $value = $visitedIn[$subject] ?? 0;
        if (!is_int($value) && !is_string($value)) {
            continue;
        }
        if (is_string($value) && !preg_match('/^\d{1,5}$/', $value)) {
            continue;
        }

        $last = max(0, min((int) $value, MAX_INDEX));
        if ($last > 0) {
            $visited[$subject] = $last;
        }
    }

    $out['_meta'] = ['lastVisited' => $visited];

    return $out;
}

/* Bloktaki çözülen / doğru sayısı — tablodaki özet sütunlar için. */
function tally(array $progress): array
{
    $answered = 0;
    $correct  = 0;

    foreach (SUBJECTS as $subject) {
        foreach ($progress[$subject] ?? [] as $key => $value) {
            if ($key === '_total') {
                continue;
            }
            $answered++;
            if ($value['isCorrect']) {
                $correct++;
            }
        }
    }

    return [$answered, $correct];
}

function load_progress(int $userId): array
{
    $stmt = db()->prepare('SELECT data FROM progress WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $data = $stmt->fetchColumn();

    $decoded = is_string($data) && $data !== '' ? json_decode($data, true) : null;
    if (!is_array($decoded)) {
        return empty_progress();
    }

    $clean = sanitize_progress($decoded);

    /* Ders blokları JSON'da nesne olarak çıksın; dizi olursa istemci
       tarafında _total gibi metin anahtarları kaybolur. */
    foreach (SUBJECTS as $subject) {
        if (isset($clean[$subject])) {
            $clean[$subject] = (object) $clean[$subject];
        }
    }
    $clean['_meta'] = (object) ['lastVisited' => (object) $clean['_meta']['lastVisited']];

    return $clean;
}

function save_progress(int $userId, array $raw): void
{
    $progress = sanitize_progress($raw);
    $json     = json_encode($progress, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);

    if (!is_string($json)) {
        respond(['ok' => false, 'error' => 'payload'], 400);
    }

    if (strlen($json) > MAX_BLOB_BYTES) {
        respond(['ok' => false, 'error' => 'too_large'], 413);
    }

    [$answered, $correct] = tally($progress);

    /* Boş push satır bırakmasın; eski sürümün budama davranışıyla aynı. */
    if ($answered === 0 && $progress['_meta']['lastVisited'] === []) {
        db()->prepare('DELETE FROM progress WHERE user_id = ?')->execute([$userId]);
        return;
    }

    db()->prepare(
        'INSERT INTO progress (user_id, data, answered, correct) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data), answered = VALUES(answered), correct = VALUES(correct)'
    )->execute([$userId, $json, $answered, $correct]);
}

if (auth_user() === null) {
    respond(['ok' => false, 'error' => 'auth'], 401);
}

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(['ok' => true, 'progress' => load_progress($userId)]);
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

try {
    switch ((string) ($body['action'] ?? '')) {
        case 'push':
            if (!is_array($body['progress'] ?? null)) {
                respond(['ok' => false, 'error' => 'payload'], 400);
            }
            save_progress($userId, $body['progress']);
            respond(['ok' => true]);

        case 'reset':
            db()->prepare('DELETE FROM progress WHERE user_id = ?')->execute([$userId]);
            respond(['ok' => true]);
    }
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => 'server'], 500);
}

respond(['ok' => false, 'error' => 'action'], 400);
