<?php
/**
 * eventos_ver_imagem.php
 * Proxy para exibir imagens do Magalu (Reunião Final). Uso: ?page=eventos_ver_imagem&key=<base64(key)>
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';

if (empty($_SESSION['logado']) || (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin']))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acesso negado';
    exit;
}

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

if (strpos($key, 'eventos/reunioes/') !== 0) {
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
