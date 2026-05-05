<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$dataFile = __DIR__ . '/../data/public-help.json';
$uploadDir = __DIR__ . '/../uploads/pomoc-verejnosti';
$uploadUrl = 'uploads/pomoc-verejnosti';
$localConfig = __DIR__ . '/public-help.config.php';
$config = is_file($localConfig) ? require $localConfig : [];
$pin = getenv('PUBLIC_HELP_PIN') ?: (is_array($config) ? (string)($config['pin'] ?? '') : '');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function read_payload(): array
{
    if (strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0) {
        $payload = $_POST;
        if (isset($payload['draft']) && is_string($payload['draft'])) {
            $draft = json_decode($payload['draft'], true);
            if (is_array($draft)) {
                $payload['draft'] = $draft;
            }
        }
        return $payload;
    }

    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

function require_pin(array $payload, string $pin): void
{
    if ($pin === '') {
        respond(500, [
            'ok' => false,
            'error' => 'Na serveri chýba PUBLIC_HELP_PIN. Nastavte ho v hostingu ako bezpečnostný PIN pre admin akcie.'
        ]);
    }

    if (($payload['pin'] ?? '') !== $pin) {
        respond(403, ['ok' => false, 'error' => 'Nesprávny PIN.']);
    }
}

function load_items(string $dataFile): array
{
    if (!is_file($dataFile)) {
        return [];
    }

    $json = file_get_contents($dataFile);
    $items = json_decode($json ?: '[]', true);
    return is_array($items) ? $items : [];
}

function save_items(string $dataFile, array $items): void
{
    $dir = dirname($dataFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($dataFile, $json, LOCK_EX) === false) {
        respond(500, ['ok' => false, 'error' => 'Nepodarilo sa uložiť dáta.']);
    }
}

function clean_text(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?: '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function upload_images(string $uploadDir, string $uploadUrl): array
{
    if (empty($_FILES['images']) || !is_array($_FILES['images']['name'])) {
        return [];
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $images = [];
    $count = min(count($_FILES['images']['name']), 5);

    for ($i = 0; $i < $count; $i++) {
        if (($_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmp = (string)$_FILES['images']['tmp_name'][$i];
        $size = (int)($_FILES['images']['size'][$i] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            continue;
        }

        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
        if (!isset($allowed[$mime])) {
            continue;
        }

        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
        $target = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmp, $target)) {
            continue;
        }

        $images[] = [
            'src' => rtrim($uploadUrl, '/') . '/' . $filename,
            'alt' => 'Ilustračná fotografia k odbornej odpovedi',
            'width' => (int)($info[0] ?? 0),
            'height' => (int)($info[1] ?? 0),
        ];
    }

    return $images;
}

function local_draft(array $input): array
{
    $topic = clean_text((string)($input['topic'] ?? 'Otázka z praxe'), 90);
    $question = clean_text((string)($input['question'] ?? ''), 650);
    $comment = clean_text((string)($input['expertComment'] ?? ''), 1400);
    $category = clean_text((string)($input['category'] ?? 'Odpoveď z praxe'), 60);

    return [
        'title' => $topic !== '' ? $topic : 'Odpoveď z praxe',
        'summary' => $question !== ''
            ? $question
            : 'Stručne upravená otázka z verejnej diskusie bez zbytočných osobných detailov.',
        'answer' => $comment !== ''
            ? $comment
            : 'Doplňte odbornú odpoveď pred publikovaním.',
        'category' => $category,
        'takeaway' => 'Pri staršom dome je dôležité oddeliť bežný stav od rizika, ktoré môže neskôr znamenať nákladnú opravu.',
        'tags' => array_values(array_filter(array_map('trim', explode(',', (string)($input['tags'] ?? ''))))),
        'status' => 'draft'
    ];
}

function openai_draft(array $input): ?array
{
    global $config;

    $apiKey = getenv('OPENAI_API_KEY') ?: (is_array($config) ? (string)($config['openai_api_key'] ?? '') : '');
    if ($apiKey === '' || !function_exists('curl_init')) {
        return null;
    }

    $model = getenv('OPENAI_MODEL') ?: (is_array($config) ? (string)($config['openai_model'] ?? '') : '');
    $model = $model !== '' ? $model : 'gpt-5.4-mini';
    $sourceUrl = clean_text((string)($input['sourceUrl'] ?? ''), 500);
    $topic = clean_text((string)($input['topic'] ?? ''), 160);
    $category = clean_text((string)($input['category'] ?? ''), 80);
    $question = clean_text((string)($input['question'] ?? ''), 1500);
    $expertComment = clean_text((string)($input['expertComment'] ?? ''), 2200);
    $extraComments = clean_text((string)($input['extraComments'] ?? ''), 1400);
    $tags = clean_text((string)($input['tags'] ?? ''), 200);

    $prompt = <<<PROMPT
Vytvor krátky webový príspevok do sekcie "Pomoc verejnosti" pre slovenský web DoktorHaus.

Úloha:
- Nepoužívaj meno autora FB príspevku ani osobné údaje.
- Necituj cudzie komentáre dlhými pasážami.
- Zachovaj vecný, odborný, pokojný tón.
- Výsledok musí byť vhodný na publikovanie na webe.
- Výstup vráť výhradne ako JSON s kľúčmi: title, summary, answer, category, takeaway, tags.

Vstup:
Téma: {$topic}
Kategória: {$category}
Link na zdroj: {$sourceUrl}
Otázka / situácia: {$question}
Moja odborná odpoveď: {$expertComment}
Ďalšie označené komentáre: {$extraComments}
Tagy: {$tags}
PROMPT;

    $body = [
        'model' => $model,
        'input' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $prompt]
                ]
            ]
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'public_help_post',
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['title', 'summary', 'answer', 'category', 'takeaway', 'tags'],
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                        'answer' => ['type' => 'string'],
                        'category' => ['type' => 'string'],
                        'takeaway' => ['type' => 'string'],
                        'tags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string']
                        ]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 45,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    $decoded = json_decode($response, true);
    $text = $decoded['output'][0]['content'][0]['text'] ?? '';
    $draft = json_decode((string)$text, true);

    return is_array($draft) ? $draft : null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'list') {
    $items = array_values(array_filter(load_items($dataFile), fn($item) => ($item['status'] ?? '') === 'published'));
    usort($items, fn($a, $b) => strcmp((string)($b['publishedAt'] ?? ''), (string)($a['publishedAt'] ?? '')));
    respond(200, ['ok' => true, 'items' => $items]);
}

if ($method !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Nepodporovaná metóda.']);
}

$payload = read_payload();
require_pin($payload, $pin);

if (($payload['action'] ?? '') === 'generate') {
    $draft = openai_draft($payload) ?: local_draft($payload);
    $draft['sourceUrl'] = clean_text((string)($payload['sourceUrl'] ?? ''), 500);
    respond(200, ['ok' => true, 'draft' => $draft]);
}

if (($payload['action'] ?? '') === 'admin-list') {
    $items = load_items($dataFile);
    usort($items, fn($a, $b) => strcmp((string)($b['publishedAt'] ?? ''), (string)($a['publishedAt'] ?? '')));
    respond(200, ['ok' => true, 'items' => $items]);
}

if (($payload['action'] ?? '') === 'set-status') {
    $id = clean_text((string)($payload['id'] ?? ''), 40);
    $status = clean_text((string)($payload['status'] ?? ''), 20);
    if ($id === '' || !in_array($status, ['published', 'hidden'], true)) {
        respond(422, ['ok' => false, 'error' => 'Neplatná zmena stavu.']);
    }

    $items = load_items($dataFile);
    $updatedItem = null;
    foreach ($items as &$item) {
        if (($item['id'] ?? '') === $id) {
            $item['status'] = $status;
            if ($status === 'hidden') {
                $item['hiddenAt'] = date('c');
            } else {
                unset($item['hiddenAt']);
            }
            $updatedItem = $item;
            break;
        }
    }
    unset($item);

    if ($updatedItem === null) {
        respond(404, ['ok' => false, 'error' => 'Príspevok sa nenašiel.']);
    }

    save_items($dataFile, $items);
    respond(200, ['ok' => true, 'item' => $updatedItem]);
}

if (($payload['action'] ?? '') === 'publish') {
    $items = load_items($dataFile);
    $draft = is_array($payload['draft'] ?? null) ? $payload['draft'] : [];
    $images = upload_images($uploadDir, $uploadUrl);

    $item = [
        'id' => bin2hex(random_bytes(8)),
        'title' => clean_text((string)($draft['title'] ?? ''), 120),
        'summary' => clean_text((string)($draft['summary'] ?? ''), 900),
        'answer' => clean_text((string)($draft['answer'] ?? ''), 2200),
        'category' => clean_text((string)($draft['category'] ?? 'Odpoveď z praxe'), 70),
        'takeaway' => clean_text((string)($draft['takeaway'] ?? ''), 450),
        'tags' => array_values(array_filter(array_map(fn($tag) => clean_text((string)$tag, 40), $draft['tags'] ?? []))),
        'images' => $images,
        'sourceUrl' => filter_var((string)($draft['sourceUrl'] ?? $payload['sourceUrl'] ?? ''), FILTER_VALIDATE_URL) ?: '',
        'status' => 'published',
        'publishedAt' => date('c'),
    ];

    if ($item['title'] === '' || $item['answer'] === '') {
        respond(422, ['ok' => false, 'error' => 'Názov a odpoveď sú povinné.']);
    }

    array_unshift($items, $item);
    save_items($dataFile, $items);
    respond(200, ['ok' => true, 'item' => $item]);
}

respond(400, ['ok' => false, 'error' => 'Neznáma akcia.']);
