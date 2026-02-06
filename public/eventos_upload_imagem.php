<?php
/**
 * eventos_upload_imagem.php
 * Endpoint dedicado para upload de imagem do TinyMCE (Reunião Final).
 * Incluído por index.php quando page=eventos_upload_imagem e POST.
 * Resposta APENAS JSON (sem HTML/layout) para o TinyMCE parar de carregar.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
if (ob_get_level()) { while (ob_get_level()) ob_end_clean(); }

if (empty($_SESSION['logado']) || (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin']))) {
    echo json_encode(['location' => '', 'error' => 'Acesso negado']);
    exit;
}

$mid = (int)($_POST['meeting_id'] ?? 0);
$file = null;
foreach (['file', 'blobid0', 'imagetools0'] as $key) {
    if (!empty($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$key];
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
    $prefix = 'eventos/reunioes/' . $mid;
    $result = $uploader->upload($file, $prefix);
    $chave = $result['chave_storage'] ?? '';
    if ($chave === '') {
        echo json_encode(['location' => '', 'error' => 'Falha no upload']);
        exit;
    }
    // URL via proxy (imagem carrega mesmo com bucket privado)
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
    $script = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    if ($script === '' || $script === '\\') $script = '';
    $keyB64 = strtr(base64_encode($chave), '+/', '-_');
    $url = $base . $script . '/index.php?page=eventos_ver_imagem&key=' . rawurlencode($keyB64);
    echo json_encode(['location' => $url]);
} catch (Exception $e) {
    echo json_encode(['location' => '', 'error' => $e->getMessage()]);
}
