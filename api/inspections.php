<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$dataFile = __DIR__ . '/../data/inspections.json';
$localConfig = __DIR__ . '/inspections.config.php';
$config = is_file($localConfig) ? require $localConfig : [];
$adminPin = getenv('INSPECTIONS_ADMIN_PIN')
    ?: getenv('PUBLIC_HELP_PIN')
    ?: (is_array($config) ? (string)($config['admin_pin'] ?? '') : '');
$fromEmail = getenv('INSPECTIONS_FROM_EMAIL')
    ?: (is_array($config) ? (string)($config['from_email'] ?? 'info@doktorhaus.sk') : 'info@doktorhaus.sk');
$baseUrl = rtrim(getenv('INSPECTIONS_BASE_URL')
    ?: (is_array($config) ? (string)($config['base_url'] ?? '') : ''), '/');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function read_payload(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
}

function require_admin_pin(array $payload, string $adminPin): void
{
    if ($adminPin === '') {
        respond(500, ['ok' => false, 'error' => 'Na serveri chýba INSPECTIONS_ADMIN_PIN.']);
    }

    if (($payload['adminPin'] ?? '') !== $adminPin) {
        respond(403, ['ok' => false, 'error' => 'Nesprávny Admin PIN.']);
    }
}

function load_items(string $dataFile): array
{
    if (!is_file($dataFile)) {
        return [];
    }

    $items = json_decode(file_get_contents($dataFile) ?: '[]', true);
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
        respond(500, ['ok' => false, 'error' => 'Nepodarilo sa uložiť inšpekcie.']);
    }
}

function clean_text(string $value, int $limit): string
{
    $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?: '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function clean_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(https?:\/\/|uploads\/|\.\/|\.\.\/)/i', $value) !== 1) {
        return '';
    }

    return filter_var($value, FILTER_SANITIZE_URL) ?: '';
}

function normalize_photos($photos): array
{
    if (is_string($photos)) {
        $decoded = json_decode($photos, true);
        $photos = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($photos)) {
        return [];
    }

    $normalized = [];
    foreach ($photos as $photo) {
        if (!is_array($photo)) {
            continue;
        }

        $full = clean_url((string)($photo['full'] ?? $photo['src'] ?? ''));
        if ($full === '') {
            continue;
        }

        $normalized[] = [
            'number' => clean_text((string)($photo['number'] ?? ''), 24),
            'title' => clean_text((string)($photo['title'] ?? ''), 120),
            'thumb' => clean_url((string)($photo['thumb'] ?? $full)),
            'full' => $full,
        ];
    }

    return array_slice($normalized, 0, 120);
}

function normalize_item(array $input, ?array $existing = null): array
{
    $media = is_array($input['media'] ?? null) ? $input['media'] : [];
    $now = date('c');

    $item = [
        'id' => $existing['id'] ?? bin2hex(random_bytes(8)),
        'title' => clean_text((string)($input['title'] ?? ''), 140),
        'location' => clean_text((string)($input['location'] ?? ''), 120),
        'summary' => clean_text((string)($input['summary'] ?? ''), 900),
        'clientEmail' => clean_text((string)($input['clientEmail'] ?? ''), 180),
        'status' => $existing['status'] ?? 'draft',
        'pin' => $existing['pin'] ?? '',
        'media' => [
            'reportUrl' => clean_url((string)($media['reportUrl'] ?? '')),
            'docsUrl' => clean_url((string)($media['docsUrl'] ?? '')),
            'panoravenUrl' => clean_url((string)($media['panoravenUrl'] ?? '')),
            'videoHdUrl' => clean_url((string)($media['videoHdUrl'] ?? '')),
            'video360Url' => clean_url((string)($media['video360Url'] ?? '')),
        ],
        'photos' => normalize_photos($input['photos'] ?? []),
        'createdAt' => $existing['createdAt'] ?? $now,
        'updatedAt' => $now,
    ];

    if ($item['title'] === '') {
        respond(422, ['ok' => false, 'error' => 'Názov inšpekcie je povinný.']);
    }

    if ($item['clientEmail'] !== '' && !filter_var($item['clientEmail'], FILTER_VALIDATE_EMAIL)) {
        respond(422, ['ok' => false, 'error' => 'Email klienta nemá platný tvar.']);
    }

    foreach (['readyAt', 'sentAt'] as $field) {
        if (isset($existing[$field])) {
            $item[$field] = $existing[$field];
        }
    }

    return $item;
}

function public_item(array $item): array
{
    return [
        'id' => $item['id'] ?? '',
        'title' => $item['title'] ?? '',
        'location' => $item['location'] ?? '',
        'summary' => $item['summary'] ?? '',
        'media' => $item['media'] ?? [],
        'photos' => $item['photos'] ?? [],
    ];
}

function find_item_index(array $items, string $id): int
{
    foreach ($items as $index => $item) {
        if (($item['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function generate_pin(array $items): string
{
    $used = array_flip(array_map(fn($item) => (string)($item['pin'] ?? ''), $items));
    do {
        $pin = (string)random_int(100000, 999999);
    } while (isset($used[$pin]));

    return $pin;
}

function inspection_link(string $baseUrl): string
{
    if ($baseUrl !== '') {
        return $baseUrl . '/inspekcie.html';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'doktorhaus.sk';
    return $scheme . '://' . $host . '/inspekcie.html';
}

function email_body(array $item, string $link): string
{
    $title = (string)($item['title'] ?? 'vašej inšpekcii');
    $pin = (string)($item['pin'] ?? '');

    return "Dobrý deň,\n\n"
        . "ďakujem za spoluprácu. Výstupy k inšpekcii nájdete na tomto odkaze:\n"
        . $link . "\n\n"
        . "PIN pre prístup: " . $pin . "\n\n"
        . "Po zadaní PINu sa zobrazia dokumenty a médiá pripravené k inšpekcii: " . $title . ".\n\n"
        . "S pozdravom\nDoktorHaus";
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Nepodporovaná metóda.']);
}

$payload = read_payload();
$action = (string)($payload['action'] ?? '');

if ($action === 'unlock') {
    $pin = clean_text((string)($payload['pin'] ?? ''), 6);
    if (!preg_match('/^\d{6}$/', $pin)) {
        respond(422, ['ok' => false, 'error' => 'Zadajte 6-miestny PIN.']);
    }

    foreach (load_items($dataFile) as $item) {
        if (($item['pin'] ?? '') === $pin && in_array(($item['status'] ?? ''), ['ready', 'sent'], true)) {
            respond(200, ['ok' => true, 'inspection' => public_item($item)]);
        }
    }

    respond(404, ['ok' => false, 'error' => 'K tomuto PINu sa nenašla pripravená inšpekcia.']);
}

require_admin_pin($payload, $adminPin);
$items = load_items($dataFile);

if ($action === 'admin-list') {
    usort($items, fn($a, $b) => strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? '')));
    respond(200, ['ok' => true, 'items' => $items, 'inspectionLink' => inspection_link($baseUrl)]);
}

if ($action === 'save') {
    $id = clean_text((string)($payload['id'] ?? ''), 40);
    $index = $id !== '' ? find_item_index($items, $id) : -1;
    $existing = $index >= 0 ? $items[$index] : null;
    $item = normalize_item($payload['inspection'] ?? [], $existing);

    if ($index >= 0) {
        $items[$index] = $item;
    } else {
        array_unshift($items, $item);
    }

    save_items($dataFile, $items);
    respond(200, ['ok' => true, 'item' => $item]);
}

if ($action === 'mark-ready') {
    $id = clean_text((string)($payload['id'] ?? ''), 40);
    $index = find_item_index($items, $id);
    if ($index < 0) {
        respond(404, ['ok' => false, 'error' => 'Inšpekcia sa nenašla.']);
    }

    if (($items[$index]['pin'] ?? '') === '') {
        $items[$index]['pin'] = generate_pin($items);
    }
    $items[$index]['status'] = 'ready';
    $items[$index]['readyAt'] = date('c');
    $items[$index]['updatedAt'] = date('c');
    save_items($dataFile, $items);
    respond(200, [
        'ok' => true,
        'item' => $items[$index],
        'emailText' => email_body($items[$index], inspection_link($baseUrl)),
        'inspectionLink' => inspection_link($baseUrl),
    ]);
}

if ($action === 'send-email') {
    $id = clean_text((string)($payload['id'] ?? ''), 40);
    $index = find_item_index($items, $id);
    if ($index < 0) {
        respond(404, ['ok' => false, 'error' => 'Inšpekcia sa nenašla.']);
    }

    $item = $items[$index];
    $email = (string)($item['clientEmail'] ?? '');
    if (($item['pin'] ?? '') === '' || !in_array(($item['status'] ?? ''), ['ready', 'sent'], true)) {
        respond(422, ['ok' => false, 'error' => 'Najprv označte inšpekciu ako pripravenú.']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(422, ['ok' => false, 'error' => 'Inšpekcia nemá platný email klienta.']);
    }

    $subject = 'Výstupy z inšpekcie DoktorHaus';
    $body = email_body($item, inspection_link($baseUrl));
    $headers = [
        'From: DoktorHaus <' . $fromEmail . '>',
        'Content-Type: text/plain; charset=UTF-8',
    ];

    if (!mail($email, $subject, $body, implode("\r\n", $headers))) {
        respond(500, [
            'ok' => false,
            'error' => 'Email sa nepodarilo odoslať. Text emailu si môžete skopírovať z backoffice.',
            'emailText' => $body,
        ]);
    }

    $items[$index]['status'] = 'sent';
    $items[$index]['sentAt'] = date('c');
    $items[$index]['updatedAt'] = date('c');
    save_items($dataFile, $items);
    respond(200, ['ok' => true, 'item' => $items[$index], 'emailText' => $body]);
}

if ($action === 'set-draft') {
    $id = clean_text((string)($payload['id'] ?? ''), 40);
    $index = find_item_index($items, $id);
    if ($index < 0) {
        respond(404, ['ok' => false, 'error' => 'Inšpekcia sa nenašla.']);
    }

    $items[$index]['status'] = 'draft';
    $items[$index]['updatedAt'] = date('c');
    save_items($dataFile, $items);
    respond(200, ['ok' => true, 'item' => $items[$index]]);
}

respond(400, ['ok' => false, 'error' => 'Neznáma akcia.']);
