<?php
// push_get_public_key.php — Obter chave pública VAPID
header('Content-Type: application/json');

// Chave pública VAPID (deve ser gerada e configurada)
// Por enquanto, usar uma chave de exemplo - DEVE SER SUBSTITUÍDA
$publicKey = getenv('VAPID_PUBLIC_KEY') ?: 'BEl62iUYgUivxIkv69yViEuiBIa40HIgvD8vV3gK3lB5jBqRzD1xZ5gJ8Y9K0L1M2N3O4P5Q6R7S8T9U0V1W2X3Y4Z5';

echo json_encode(['publicKey' => $publicKey]);
