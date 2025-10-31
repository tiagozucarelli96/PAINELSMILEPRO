<?php
// upload_magalu.php - Helper de upload para Magalu Cloud
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se usuário está logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/conexao.php';

// Tentar carregar AWS SDK se disponível
$awsSdkAvailable = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Aws\S3\S3Client')) {
        $awsSdkAvailable = true;
    }
}

class MagaluUpload {
    private $s3Client = null;
    private $bucket;
    private $region;
    private $endpoint;
    private $accessKey;
    private $secretKey;
    private $maxSizeMB;
    
    public function __construct() {
        // Carregar variáveis de ambiente (Railway usa $_ENV)
        $this->bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
        $this->region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
        $this->endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
        $this->accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
        $this->secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
        $this->maxSizeMB = (int)($_ENV['UPLOAD_MAX_MB'] ?? getenv('UPLOAD_MAX_MB') ?: 10);
        
        // Normalizar bucket name (minúsculas)
        $this->bucket = strtolower($this->bucket);
        
        if (!$this->accessKey || !$this->secretKey) {
            error_log("Magalu Credentials Missing - Access Key: " . ($this->accessKey ? 'SET' : 'MISSING') . ", Secret Key: " . ($this->secretKey ? 'SET' : 'MISSING'));
            throw new Exception('Credenciais Magalu não configuradas. Verifique MAGALU_ACCESS_KEY e MAGALU_SECRET_KEY nas variáveis de ambiente.');
        }
        
        error_log("Magalu Upload initialized - Bucket: {$this->bucket}, Endpoint: {$this->endpoint}, Region: {$this->region}");
        
        // Inicializar AWS SDK se disponível
        global $awsSdkAvailable;
        if ($awsSdkAvailable) {
            try {
                $this->s3Client = new \Aws\S3\S3Client([
                    'region' => $this->region,
                    'version' => 'latest',
                    'credentials' => [
                        'key' => $this->accessKey,
                        'secret' => $this->secretKey,
                    ],
                    'endpoint' => $this->endpoint,
                    'use_path_style_endpoint' => true,
                ]);
                error_log("AWS SDK S3Client inicializado com sucesso");
            } catch (\Exception $e) {
                error_log("Erro ao inicializar AWS SDK: " . $e->getMessage());
                $this->s3Client = null;
            }
        } else {
            error_log("AWS SDK não disponível - usando cURL como fallback");
        }
    }
    
    public function upload($file, $prefix = 'demandas') {
        // Validar arquivo
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Arquivo inválido');
        }
        
        // Validar tamanho
        $maxBytes = $this->maxSizeMB * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            throw new Exception("Arquivo muito grande. Máximo: {$this->maxSizeMB}MB");
        }
        
        // Validar MIME type
        $allowedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Tipo de arquivo não permitido');
        }
        
        // Gerar chave de armazenamento
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $uuid = uniqid();
        $year = date('Y');
        $month = date('m');
        $key = "{$prefix}/{$year}/{$month}/{$uuid}.{$extension}";
        
        // Upload para Magalu Cloud (simulado - implementar SDK real)
        $url = $this->uploadToMagalu($file['tmp_name'], $key, $mimeType);
        
        return [
            'chave_storage' => $key,
            'url' => $url,
            'nome_original' => $file['name'],
            'mime_type' => $mimeType,
            'tamanho_bytes' => $file['size']
        ];
    }
    
    private function uploadToMagalu($tmpFile, $key, $mimeType) {
        // Tentar usar AWS SDK primeiro (método recomendado pela Magalu Cloud)
        if ($this->s3Client !== null) {
            try {
                error_log("Usando AWS SDK para upload");
                
                $result = $this->s3Client->putObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'SourceFile' => $tmpFile,
                    'ContentType' => $mimeType,
                ]);
                
                error_log("AWS SDK Upload Success - Key: {$key}");
                
                // Retornar URL pública do arquivo
                return "{$this->endpoint}/{$this->bucket}/{$key}";
                
            } catch (\Aws\Exception\AwsException $e) {
                error_log("AWS SDK Error: " . $e->getMessage());
                error_log("Error Code: " . $e->getAwsErrorCode());
                
                // Extrair mensagem de erro
                $errorMessage = $e->getMessage();
                if ($e->getAwsErrorCode() === 'AccessDenied') {
                    throw new Exception("Erro no upload para Magalu Cloud: Access Denied. Verifique as permissões das credenciais e se o bucket existe.");
                }
                throw new Exception("Erro no upload para Magalu Cloud: " . $errorMessage);
            } catch (\Exception $e) {
                error_log("Erro genérico no AWS SDK: " . $e->getMessage());
                // Fallback para cURL
                error_log("Tentando fallback para cURL...");
            }
        }
        
        // Fallback: Upload via cURL (método manual)
        error_log("Usando cURL como fallback para upload");
        return $this->uploadToMagaluCurl($tmpFile, $key, $mimeType);
    }
    
    private function uploadToMagaluCurl($tmpFile, $key, $mimeType) {
        // Upload manual para Magalu Cloud usando API S3-compatible (formato AWS S3 v2)
        $url = "{$this->endpoint}/{$this->bucket}/{$key}";
        
        // Ler conteúdo do arquivo
        $fileContent = file_get_contents($tmpFile);
        if ($fileContent === false) {
            throw new Exception('Erro ao ler arquivo temporário');
        }
        
        $fileSize = filesize($tmpFile);
        
        // Preparar requisição PUT - AWS S3 Signature Version 2
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentType = $mimeType;
        
        // String para assinatura AWS S3 v2
        $canonicalizedResource = "/{$this->bucket}/{$key}";
        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$canonicalizedResource}";
        
        // Gerar assinatura HMAC-SHA1 e codificar em base64
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        error_log("String to sign (raw): " . str_replace("\n", "\\n", $stringToSign));
        error_log("=== Magalu Upload Debug (cURL) ===");
        error_log("URL: {$url}");
        error_log("Bucket: {$this->bucket}, Key: {$key}");
        
        // Headers da requisição
        $headers = [
            'Date: ' . $date,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . $fileSize,
            'Authorization: AWS ' . $this->accessKey . ':' . $signature
        ];
        
        // Fazer upload via cURL
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("Magalu Upload cURL Error: {$curlError}");
            throw new Exception('Erro no upload: ' . $curlError);
        }
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            error_log("Magalu Upload Error - HTTP {$httpCode}");
            error_log("Response: " . ($response ?: 'Sem resposta'));
            
            // Extrair mensagem de erro da resposta XML
            if (strpos($response, '<Error>') !== false) {
                if (preg_match('/<Message>(.*?)<\/Message>/', $response, $matches)) {
                    throw new Exception("Erro no upload para Magalu Cloud: " . $matches[1]);
                }
            }
            
            throw new Exception("Erro no upload para Magalu Cloud. Código HTTP: {$httpCode}. Verifique as credenciais e permissões.");
        }
        
        error_log("Magalu Upload Success (cURL) - HTTP {$httpCode}, Key: {$key}");
        
        // Retornar URL pública do arquivo
        return "{$this->endpoint}/{$this->bucket}/{$key}";
    }
    
    public function delete($key) {
        if (empty($key)) {
            return false;
        }
        
        // Tentar usar AWS SDK primeiro
        if ($this->s3Client !== null) {
            try {
                $this->s3Client->deleteObject([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                ]);
                return true;
            } catch (\Exception $e) {
                error_log("Erro ao deletar via AWS SDK: " . $e->getMessage());
                // Fallback para cURL
            }
        }
        
        // Fallback: Delete via cURL
        try {
            $url = "{$this->endpoint}/{$this->bucket}/{$key}";
            $date = gmdate('D, d M Y H:i:s \G\M\T');
            $stringToSign = "DELETE\n\n\n{$date}\n/{$this->bucket}/{$key}";
            $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: AWS ' . $this->accessKey . ':' . $signature,
                    'Date: ' . $date
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 204 || $httpCode === 200;
            
        } catch (Exception $e) {
            error_log("Erro ao deletar do Magalu Cloud: " . $e->getMessage());
            return false;
        }
    }
    
    private function generateSignature($method, $key) {
        // Gerar assinatura AWS S3-compatible para autenticação (v2)
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $stringToSign = "{$method}\n\n\n{$date}\n/{$this->bucket}/{$key}";
        return base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
    }
}

// API endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $uploader = new MagaluUpload();
        
        if (!isset($_FILES['file'])) {
            throw new Exception('Nenhum arquivo enviado');
        }
        
        $result = $uploader->upload($_FILES['file']);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
}
