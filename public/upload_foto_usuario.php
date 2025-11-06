<?php
/**
 * Upload de foto de usuário
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar permissão
if (empty($_SESSION['logado']) || empty($_SESSION['perm_configuracoes'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

// Verificar se é upload de arquivo
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['foto'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nenhum arquivo enviado']);
    exit;
}

$file = $_FILES['foto'];

// Validar arquivo
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Erro ao fazer upload: ' . $file['error']]);
    exit;
}

// Validar tipo
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tipo de arquivo não permitido. Use JPG, PNG ou GIF']);
    exit;
}

// Validar tamanho (2MB)
if ($file['size'] > 2 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Arquivo muito grande. Tamanho máximo: 2MB']);
    exit;
}

// Criar diretório de uploads se não existir
$uploadDir = __DIR__ . '/uploads/fotos_usuarios/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Gerar nome único
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$fileName = uniqid('user_', true) . '.' . $extension;
$filePath = $uploadDir . $fileName;

// Mover arquivo
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao salvar arquivo']);
    exit;
}

// Redimensionar imagem se necessário (máximo 400x400)
try {
    $imageInfo = getimagesize($filePath);
    if ($imageInfo !== false) {
        $maxSize = 400;
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        if ($width > $maxSize || $height > $maxSize) {
            $ratio = min($maxSize / $width, $maxSize / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);
            
            $image = null;
            if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
                $image = imagecreatefromjpeg($filePath);
            } elseif ($mimeType === 'image/png') {
                $image = imagecreatefrompng($filePath);
            } elseif ($mimeType === 'image/gif') {
                $image = imagecreatefromgif($filePath);
            }
            
            if ($image) {
                $resized = imagecreatetruecolor($newWidth, $newHeight);
                
                // Preservar transparência para PNG e GIF
                if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
                    imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
                }
                
                imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                
                if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
                    imagejpeg($resized, $filePath, 85);
                } elseif ($mimeType === 'image/png') {
                    imagepng($resized, $filePath);
                } elseif ($mimeType === 'image/gif') {
                    imagegif($resized, $filePath);
                }
                
                imagedestroy($image);
                imagedestroy($resized);
            }
        }
    }
} catch (Exception $e) {
    error_log("Erro ao redimensionar imagem: " . $e->getMessage());
}

// Retornar URL relativa
$relativePath = 'uploads/fotos_usuarios/' . $fileName;

echo json_encode([
    'success' => true,
    'path' => $relativePath,
    'url' => $relativePath
], JSON_UNESCAPED_SLASHES);

