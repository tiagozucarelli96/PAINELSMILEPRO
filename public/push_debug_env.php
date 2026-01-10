<?php
// push_debug_env.php — Debug de variáveis de ambiente VAPID
header('Content-Type: application/json');

$debug = [
    '_ENV_VAPID_PUBLIC_KEY' => isset($_ENV['VAPID_PUBLIC_KEY']) ? 'definida (' . strlen($_ENV['VAPID_PUBLIC_KEY']) . ' chars)' : 'não definida',
    '_ENV_VAPID_PRIVATE_KEY' => isset($_ENV['VAPID_PRIVATE_KEY']) ? 'definida (' . strlen($_ENV['VAPID_PRIVATE_KEY']) . ' chars)' : 'não definida',
    'getenv_VAPID_PUBLIC_KEY' => getenv('VAPID_PUBLIC_KEY') ? 'definida (' . strlen(getenv('VAPID_PUBLIC_KEY')) . ' chars)' : 'não definida',
    'getenv_VAPID_PRIVATE_KEY' => getenv('VAPID_PRIVATE_KEY') ? 'definida (' . strlen(getenv('VAPID_PRIVATE_KEY')) . ' chars)' : 'não definida',
    'public_key_value' => $_ENV['VAPID_PUBLIC_KEY'] ?? getenv('VAPID_PUBLIC_KEY') ?: 'não encontrada',
    'all_env_keys' => array_keys($_ENV)
];

echo json_encode($debug, JSON_PRETTY_PRINT);
