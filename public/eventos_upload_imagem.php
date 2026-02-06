<?php
/**
 * Endpoint dedicado: upload de imagem para Reunião Final (TinyMCE).
 * Resposta APENAS JSON — chamado por index.php antes do layout.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) {
    while (ob_get_level()) ob_end_clean();
}

if (empty($_SESSION['logado']) || (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin']))) {
    echo json_encode(['location' => '', 'error' => 'Acesso negado']);
    exit;
}

$mid = (int)($_POST['meeting_id'] ?? 0);
$file = null;
foreach (['file', 'blobid0', 'imagetools0'] as $k) {
    if (!empty($_FILES[$k]) && $_FILES[$k]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$k];
        break;
    }
}

if ($mid <= 0 || !$file) {
    echo json_encode(['location' => '', 'error' => 'Dados ou arquivo inválido']);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';

try {
    $uploader = new MagaluUpload();
    $result = $uploader->upload($file, 'eventos/reunioes/' . $mid);
    $chave = $result['chave_storage'] ?? '';
    if ($chave === '') {
        echo json_encode(['location' => '', 'error' => 'Falha no upload']);
        exit;
    }
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
    $script = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    if ($script === '' || $script === '\\') $script = '';
    $keyB64 = strtr(base64_encode($chave), '+/', '-_');
    $url = $base . $script . '/index.php?page=eventos_ver_imagem&key=' . rawurlencode($keyB64);
    echo json_encode(['location' => $url]);
} catch (Exception $e) {
    echo json_encode(['location' => '', 'error' => $e->getMessage()]);
}
