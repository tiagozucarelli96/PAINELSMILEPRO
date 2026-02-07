<?php
/**
 * upload_foto_usuario_endpoint.php
 * Upload de foto de usuário para Magalu Cloud (JSON puro).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logado']) || empty($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['perm_configuracoes']) && empty($_SESSION['perm_superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sem permissão para upload de foto'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_FILES['foto'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$file = $_FILES['foto'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Falha no upload. Código: ' . (int)$file['error']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Arquivo temporário inválido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$maxBytes = 10 * 1024 * 1024;
if ((int)($file['size'] ?? 0) > $maxBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Arquivo muito grande. Máximo 10MB.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
if ($finfo) {
    finfo_close($finfo);
}
if (!in_array($mime, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Tipo de arquivo não permitido. Use JPG, PNG, GIF ou WEBP.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';

try {
    $uploader = new MagaluUpload();
    $upload = $uploader->upload($file, 'usuarios');

    $url = trim((string)($upload['url'] ?? ''));
    $key = trim((string)($upload['chave_storage'] ?? ''));

    if ($url === '' && $key !== '') {
        $bucket = strtolower((string)($_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel'));
        $endpoint = rtrim((string)($_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com'), '/');
        $url = $endpoint . '/' . $bucket . '/' . $key;
    }

    if ($url === '') {
        throw new RuntimeException('Upload sem URL de retorno');
    }

    // Opcional: persistir imediatamente quando for edição de usuário existente.
    $persistedDb = false;
    $persistedDbError = '';
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    if ($targetUserId > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE usuarios SET foto = :foto WHERE id = :id");
            $stmt->execute([
                ':foto' => $url,
                ':id' => $targetUserId,
            ]);
            $persistedDb = true;
        } catch (Throwable $e) {
            $persistedDbError = $e->getMessage();
            error_log('upload_foto_usuario_endpoint: falha ao persistir foto no banco: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Foto enviada com sucesso',
        'data' => [
            'url' => $url,
            'chave_storage' => $key,
            'nome_original' => (string)($upload['nome_original'] ?? ($file['name'] ?? 'foto')),
            'mime_type' => (string)($upload['mime_type'] ?? $mime),
            'tamanho_bytes' => (int)($upload['tamanho_bytes'] ?? ($file['size'] ?? 0)),
            'persisted_db' => $persistedDb,
            'persisted_db_error' => $persistedDbError,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('upload_foto_usuario_endpoint: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao fazer upload da foto: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
