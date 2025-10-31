<?php
// test_magalu_buckets.php - Listar buckets do Magalu Cloud usando AWS SDK
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se usuário está logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

// Tentar carregar AWS SDK
$awsSdkAvailable = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Aws\S3\S3Client')) {
        $awsSdkAvailable = true;
    }
}

if (!$awsSdkAvailable) {
    die(json_encode([
        'error' => 'AWS SDK não instalado',
        'message' => 'Execute: composer require aws/aws-sdk-php=3.335.0'
    ]));
}

// Carregar credenciais
$accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
$secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
$region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
$endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';

if (!$accessKey || !$secretKey) {
    die(json_encode(['error' => 'Credenciais não configuradas']));
}

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
    ]);
    
    // Listar buckets
    $result = $s3Client->listBuckets();
    
    $buckets = [];
    foreach ($result['Buckets'] as $bucket) {
        $buckets[] = [
            'name' => $bucket['Name'],
            'creation_date' => $bucket['CreationDate']->format('Y-m-d H:i:s'),
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'buckets' => $buckets,
        'count' => count($buckets)
    ]);
    
} catch (\Aws\Exception\AwsException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getAwsErrorCode(),
        'message' => $e->getMessage()
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Erro desconhecido',
        'message' => $e->getMessage()
    ]);
}
