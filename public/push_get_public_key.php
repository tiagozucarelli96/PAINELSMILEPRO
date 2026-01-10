<?php
// push_get_public_key.php — Obter chave pública VAPID
// Este arquivo NÃO precisa de conexão com banco de dados
header('Content-Type: application/json');

// Carregar config_env.php para ter acesso às constantes
require_once __DIR__ . '/config_env.php';

// Chave pública VAPID - tentar múltiplas fontes
$publicKey = '';
if (defined('VAPID_PUBLIC_KEY') && !empty(VAPID_PUBLIC_KEY)) {
    $publicKey = VAPID_PUBLIC_KEY;
} elseif (isset($_ENV['VAPID_PUBLIC_KEY']) && !empty($_ENV['VAPID_PUBLIC_KEY'])) {
    $publicKey = $_ENV['VAPID_PUBLIC_KEY'];
} elseif (getenv('VAPID_PUBLIC_KEY')) {
    $publicKey = getenv('VAPID_PUBLIC_KEY');
}

if (empty($publicKey)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'VAPID_PUBLIC_KEY não configurada',
        'hint' => 'Configure a variável VAPID_PUBLIC_KEY no Railway ou em config_env.php'
    ]);
    exit;
}

echo json_encode(['publicKey' => $publicKey]);
