<?php
/**
 * Proxy: exibir imagem do Magalu (Reunião Final). Uso: ?page=eventos_ver_imagem&key=<base64(key)>
 */
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';

$keyB64 = $_GET['key'] ?? '';
if ($keyB64 === '') {
    http_response_code(400);
    echo 'Parâmetro key obrigatório';
    exit;
}

$key = base64_decode(strtr($keyB64, '-_', '+/'), true);
if ($key === false || $key === '' || strpos($key, '..') !== false) {
    http_response_code(400);
    echo 'Key inválida';
    exit;
}

if (
    strpos($key, 'eventos/reunioes/') !== 0
    && strpos($key, 'administrativo/avisos/') !== 0
) {
    http_response_code(403);
    echo 'Key não permitida';
    exit;
}

try {
    $uploader = new MagaluUpload();
    $obj = $uploader->getObject($key);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erro ao obter imagem';
    exit;
}

if ($obj === null) {
    http_response_code(404);
    echo 'Imagem não encontrada';
    exit;
}

header('Content-Type: ' . $obj['content_type']);
header('Cache-Control: public, max-age=86400');
echo $obj['body'];
