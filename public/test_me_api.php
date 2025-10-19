<?php
// test_me_api.php - Teste simples da API da ME Eventos
header('Content-Type: application/json; charset=utf-8');

// Carrega configurações da ME Eventos
require_once __DIR__ . '/me_config.php';

$base = ME_BASE_URL;
$key = ME_API_KEY;

echo "Testando API da ME Eventos...\n";
echo "Base URL: $base\n";
echo "API Key: " . substr($key, 0, 10) . "...\n\n";

// Teste 1: Endpoint básico
$url = $base . '/api/v1/events';
echo "Teste 1 - URL: $url\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Authorization: ' . $key,  // Removido "Bearer " conforme documentação
        'Content-Type: application/json',
        'Accept: application/json'
    ]
]);

$resp = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

echo "Status HTTP: $code\n";
if ($err) {
    echo "Erro cURL: $err\n";
} else {
    echo "Resposta: " . substr($resp, 0, 500) . "...\n";
}

// Teste 2: Com parâmetros
echo "\n--- Teste 2 com parâmetros ---\n";
$url2 = $base . '/api/v1/events?start=2025-01-01&end=2025-12-31';
echo "URL: $url2\n";

$ch2 = curl_init($url2);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Authorization: ' . $key,  // Removido "Bearer " conforme documentação
        'Content-Type: application/json',
        'Accept: application/json'
    ]
]);

$resp2 = curl_exec($ch2);
$err2 = curl_error($ch2);
$code2 = curl_getinfo($ch2, CURLINFO_RESPONSE_CODE);
curl_close($ch2);

echo "Status HTTP: $code2\n";
if ($err2) {
    echo "Erro cURL: $err2\n";
} else {
    echo "Resposta: " . substr($resp2, 0, 500) . "...\n";
}
?>
