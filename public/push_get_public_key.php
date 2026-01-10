<?php
// push_get_public_key.php — Obter chave pública VAPID
// Este arquivo NÃO precisa de conexão com banco de dados
header('Content-Type: application/json');

// Chave pública VAPID das variáveis de ambiente
// Formato esperado: base64url (sem padding)
$publicKey = getenv('VAPID_PUBLIC_KEY') ?: '';

if (empty($publicKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'VAPID_PUBLIC_KEY não configurada']);
    exit;
}

echo json_encode(['publicKey' => $publicKey]);
