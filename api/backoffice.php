<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$publicHelpConfig = __DIR__ . '/public-help.config.php';
$config = is_file($publicHelpConfig) ? require $publicHelpConfig : [];
$pin = getenv('PUBLIC_HELP_PIN') ?: (is_array($config) ? (string)($config['pin'] ?? '') : '');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Nepodporovaná metóda.']);
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

if ($pin === '') {
    respond(500, ['ok' => false, 'error' => 'Na serveri chýba PUBLIC_HELP_PIN.']);
}

if (($payload['pin'] ?? '') !== $pin) {
    respond(403, ['ok' => false, 'error' => 'Nesprávny PIN.']);
}

respond(200, [
    'ok' => true,
    'modules' => [
        [
            'title' => 'Pomoc verejnosti',
            'url' => 'pomoc-admin.html',
            'description' => 'Pridávanie a úprava odpovedí z verejných diskusií.'
        ],
        [
            'title' => 'Inšpekcie',
            'url' => 'inspekcie-admin.html',
            'description' => 'Príprava klientskych výstupov, PINov a odoslania emailu.'
        ]
    ]
]);
