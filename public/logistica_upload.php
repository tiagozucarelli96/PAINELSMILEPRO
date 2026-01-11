<?php
// logistica_upload.php — Upload Magalu para Catálogo Logístico
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logado']) || (empty($_SESSION['perm_logistico']) && empty($_SESSION['perm_superadmin']))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sem permissão para upload.']);
    exit;
}

require_once __DIR__ . '/upload_magalu.php';

try {
    if (empty($_FILES['file'])) {
        throw new Exception('Arquivo não enviado.');
    }

    $context = $_POST['context'] ?? 'catalogo';
    $prefix = 'logistica/catalogo';
    if ($context === 'insumo') {
        $prefix = 'logistica/insumos';
    } elseif ($context === 'receita') {
        $prefix = 'logistica/receitas';
    }

    $uploader = new MagaluUpload();
    $result = $uploader->upload($_FILES['file'], $prefix);

    if (empty($result['url'])) {
        throw new Exception('Upload não retornou URL.');
    }

    echo json_encode([
        'ok' => true,
        'url' => $result['url'],
        'chave_storage' => $result['chave_storage'] ?? null,
        'nome_original' => $result['nome_original'] ?? null
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
