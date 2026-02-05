<?php
/**
 * eventos_galeria_imagem.php
 * Servir imagem da galeria de Eventos via URL pré-assinada (Magalu Cloud),
 * alinhado ao comportamento do módulo Contabilidade (contabilidade_download.php).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';

// Verificar permissão (eventos ou superadmin)
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acesso negado.';
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ID inválido.';
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT storage_key, public_url
        FROM eventos_galeria
        WHERE id = :id AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Imagem não encontrada.';
        exit;
    }

    $storage_key = $row['storage_key'] ?? null;
    $public_url = $row['public_url'] ?? null;

    // Gerar URL pré-assinada (igual ao contabilidade_download.php)
    if (!empty($storage_key) && file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';

        if (class_exists('Aws\S3\S3Client')) {
            $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
            $region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
            $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
            $accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
            $secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
            $bucket = strtolower($bucket);

            if ($accessKey && $secretKey) {
                try {
                    $s3Client = new \Aws\S3\S3Client([
                        'region' => $region,
                        'version' => 'latest',
                        'credentials' => [
                            'key' => $accessKey,
                            'secret' => $secretKey,
                        ],
                        'endpoint' => $endpoint,
                        'use_path_style_endpoint' => true,
                        'signature_version' => 'v4',
                    ]);

                    $cmd = $s3Client->getCommand('GetObject', [
                        'Bucket' => $bucket,
                        'Key' => $storage_key,
                    ]);

                    $presignedUrl = $s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();

                    header('Location: ' . (string) $presignedUrl);
                    exit;
                } catch (\Aws\Exception\AwsException $e) {
                    error_log('Eventos galeria presigned URL: ' . $e->getMessage());
                }
            }
        }
    }

    // Fallback: redirecionar para public_url (pode falhar se bucket não for público)
    if (!empty($public_url)) {
        header('Location: ' . $public_url);
        exit;
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Imagem indisponível.';

} catch (Exception $e) {
    error_log('eventos_galeria_imagem.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Erro ao carregar imagem.';
}
