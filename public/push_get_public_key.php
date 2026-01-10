<?php
// push_get_public_key.php — Obter chave pública VAPID
// Este arquivo NÃO precisa de conexão com banco de dados
header('Content-Type: application/json');

// Chave pública VAPID das variáveis de ambiente
// Tentar ambos os métodos (Railway pode usar $_ENV ou getenv)
$publicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? getenv('VAPID_PUBLIC_KEY') ?: '';

if (empty($publicKey)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'VAPID_PUBLIC_KEY não configurada',
        'debug' => [
            '_ENV' => isset($_ENV['VAPID_PUBLIC_KEY']) ? 'definida' : 'não definida',
            'getenv' => getenv('VAPID_PUBLIC_KEY') ? 'definida' : 'não definida'
        ]
    ]);
    exit;
}

echo json_encode(['publicKey' => $publicKey]);
