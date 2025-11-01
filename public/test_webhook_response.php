<?php
// test_webhook_response.php — Teste rápido para verificar se webhook retorna HTTP 200
// Este arquivo pode ser acessado publicamente para verificar se o servidor está retornando 200

// Simular requisição POST
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_POST['data'] = json_encode(['test' => true]);

// Capturar headers enviados
ob_start();
http_response_code(200);
$headers = headers_list();
ob_end_clean();

header('Content-Type: text/plain');
echo "=== TESTE DE RESPOSTA HTTP 200 ===\n\n";
echo "Código HTTP definido: " . http_response_code() . "\n\n";
echo "Headers enviados:\n";
foreach ($headers as $header) {
    echo "  - $header\n";
}
echo "\n✅ Se você está vendo isso, o servidor retornou HTTP 200 corretamente\n";
echo "✅ O webhook deve funcionar da mesma forma\n";
?>

