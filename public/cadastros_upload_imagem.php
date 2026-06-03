<?php
/**
 * Upload de imagem para TinyMCE em telas de Cadastros.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) {
    while (ob_get_level()) {
        ob_end_clean();
    }
}

if (empty($_SESSION['logado']) || (empty($_SESSION['perm_cadastros']) && empty($_SESSION['perm_superadmin']))) {
    echo json_encode(['location' => '', 'error' => 'Acesso negado']);
    exit;
}

$file = null;
foreach (['file', 'blobid0', 'imagetools0'] as $key) {
    if (!empty($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$key];
        break;
    }
}

if (!$file) {
    echo json_encode(['location' => '', 'error' => 'Arquivo inválido']);
    exit;
}

require_once __DIR__ . '/upload_magalu.php';

try {
    $uploader = new MagaluUpload();
    $result = $uploader->upload($file, 'cadastros/pacotes');
    $chave = $result['chave_storage'] ?? '';
    if ($chave === '') {
        echo json_encode(['location' => '', 'error' => 'Falha no upload']);
        exit;
    }

    $script = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    if ($script === '' || $script === '\\') {
        $script = '';
    }
    $keyB64 = strtr(base64_encode($chave), '+/', '-_');
    $url = $script . '/index.php?page=eventos_ver_imagem&key=' . rawurlencode($keyB64);
    echo json_encode(['location' => $url]);
} catch (Throwable $e) {
    echo json_encode(['location' => '', 'error' => $e->getMessage()]);
}
