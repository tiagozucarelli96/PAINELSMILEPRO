<?php
// upload_magalu.php - Helper de upload para Magalu Cloud
// NOTA: Este arquivo é apenas uma classe, não deve fazer verificações ou gerar output
// A verificação de sessão e arquivo é feita pelo endpoint que inclui este arquivo
// Se este arquivo for acessado diretamente, não deve fazer nada

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
    
    public function __construct(?int $maxSizeMB = null) {
        // Carregar variáveis de ambiente (Railway usa $_ENV)
        $this->bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
        $this->region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
        $this->endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
        $this->accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
        $this->secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
        // Default mais realista para propostas/PDFs (pode ser sobrescrito por UPLOAD_MAX_MB)
        // Suporta arquivos maiores quando o PHP também permitir (post_max_size/upload_max_filesize)
        $defaultMaxSizeMB = (int)($_ENV['UPLOAD_MAX_MB'] ?? getenv('UPLOAD_MAX_MB') ?: 60);
        if ($defaultMaxSizeMB <= 0) {
            $defaultMaxSizeMB = 60;
        }
        if ($maxSizeMB !== null && $maxSizeMB > 0) {
            $this->maxSizeMB = $maxSizeMB;
        } else {
            $this->maxSizeMB = $defaultMaxSizeMB;
        }
        
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
                // Configuração conforme documentação Magalu Cloud
                // https://developers.magalu.cloud/docs/magalu-object-storage/guia-de-integracao/php
                $this->s3Client = new \Aws\S3\S3Client([
                    'version' => 'latest',
                    'region' => $this->region,
                    'endpoint' => $this->endpoint,
                    'use_path_style_endpoint' => true,
                    'credentials' => [
                        'key' => $this->accessKey,
                        'secret' => $this->secretKey,
                    ],
                    // Configurações adicionais para compatibilidade com Magalu
                    'signature_version' => 'v4',
                    'http' => [
                        'verify' => true,
                        'timeout' => 60,
                    ],
                ]);
                error_log("AWS SDK S3Client inicializado com sucesso - Region: {$this->region}, Endpoint: {$this->endpoint}, Bucket: {$this->bucket}");
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
        if (!isset($file['tmp_name']) || (!is_uploaded_file($file['tmp_name']) && !file_exists($file['tmp_name']))) {
            throw new Exception('Arquivo inválido');
        }
        
        // Validar tamanho
        $maxBytes = $this->maxSizeMB * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            throw new Exception("Arquivo muito grande. Máximo: {$this->maxSizeMB}MB");
        }
        
        // Validar MIME type
        $allowedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
            'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-msvideo',
            'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/aac',
            'application/pdf', 'text/plain', 'text/csv',
            'application/msword', // .doc
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
            'application/vnd.ms-excel', // .xls
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
            'application/vnd.ms-excel.sheet.macroEnabled.12', // .xlsm
            'application/x-ofx', 'application/xml', 'text/xml', 'application/octet-stream'
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

    /**
     * Upload a partir de um caminho já existente (arquivo gerado)
     */
    public function uploadFromPath(string $path, string $prefix = 'demandas', ?string $filename = null, ?string $mimeType = null) {
        if (!file_exists($path)) {
            throw new Exception("Arquivo não encontrado: {$path}");
        }
        $fakeFile = [
            'tmp_name' => $path,
            'name' => $filename ?: basename($path),
            'size' => filesize($path),
            'type' => $mimeType ?: (mime_content_type($path) ?: 'application/octet-stream'),
            'error' => UPLOAD_ERR_OK,
        ];
        return $this->upload($fakeFile, $prefix);
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
                    'ACL' => 'public-read', // necessário para a imagem ser exibida no navegador (URL pública)
                ]);
                
                error_log("AWS SDK Upload Success - Key: {$key}");
                
                // Retornar URL pública do arquivo (objeto com ACL public-read)
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
        // Upload manual para Magalu Cloud usando API S3-compatible
        // Conforme documentação: https://developers.magalu.cloud/docs/magalu-object-storage/guia-de-integracao/php
        
        // URL completa: endpoint/bucket/key
        $url = "{$this->endpoint}/{$this->bucket}/{$key}";
        
        $fileSize = filesize($tmpFile);
        if ($fileSize === false || $fileSize <= 0) {
            throw new Exception('Erro ao obter tamanho do arquivo temporário');
        }
        
        // Preparar requisição PUT - AWS S3 Signature Version 2
        // Formato: METHOD\n\nContent-Type\nDate\nCanonicalizedResource
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentType = $mimeType;
        
        // IMPORTANTE: A canonicalizedResource deve incluir o bucket no path
        // Formato: /bucket/key
        $canonicalizedResource = "/{$this->bucket}/{$key}";
        
        // String para assinatura (AWS S3 Signature Version 2)
        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$canonicalizedResource}";
        
        // Log para debug (sem expor credenciais completas)
        error_log("=== Magalu Upload Debug (cURL) ===");
        error_log("URL: {$url}");
        error_log("Bucket: {$this->bucket}, Key: {$key}");
        error_log("Region: {$this->region}, Endpoint: {$this->endpoint}");
        error_log("Content-Type: {$contentType}, Size: {$fileSize} bytes");
        error_log("String to sign: " . str_replace("\n", "\\n", $stringToSign));
        error_log("Access Key (primeiros 10 chars): " . substr($this->accessKey, 0, 10) . "...");
        
        // Gerar assinatura HMAC-SHA1 e codificar em base64
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
        
        // Headers da requisição (conforme especificação AWS S3)
        $headers = [
            'Date: ' . $date,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . $fileSize,
            'Authorization: AWS ' . $this->accessKey . ':' . $signature
        ];
        
        $fh = fopen($tmpFile, 'rb');
        if ($fh === false) {
            throw new Exception('Erro ao abrir arquivo temporário para upload');
        }

        // Fazer upload via cURL (streaming: não carrega o arquivo inteiro em memória)
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        fclose($fh);
        
        // Log detalhado da resposta
        error_log("HTTP Code: {$httpCode}");
        error_log("cURL Error: " . ($curlError ?: 'Nenhum'));
        if ($response) {
            error_log("Response (primeiros 500 chars): " . substr($response, 0, 500));
        }
        
        if ($curlError) {
            error_log("Magalu Upload cURL Error: {$curlError}");
            throw new Exception("Erro no upload: {$curlError}");
        }
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            error_log("Magalu Upload Error - HTTP {$httpCode}");
            error_log("Response completa: " . ($response ?: 'Sem resposta'));
            
            // Extrair mensagem de erro da resposta XML (formato AWS S3)
            if (strpos($response, '<Error>') !== false) {
                $errorCode = '';
                $errorMessage = '';
                
                if (preg_match('/<Code>(.*?)<\/Code>/', $response, $codeMatches)) {
                    $errorCode = $codeMatches[1];
                }
                if (preg_match('/<Message>(.*?)<\/Message>/', $response, $msgMatches)) {
                    $errorMessage = $msgMatches[1];
                }
                
                if ($errorCode === 'AccessDenied') {
                    throw new Exception("Erro no upload para Magalu Cloud: Access Denied. Verifique: 1) Se as credenciais estão corretas, 2) Se o bucket '{$this->bucket}' existe, 3) Se as credenciais têm permissão de escrita no bucket.");
                }
                
                if ($errorMessage) {
                    throw new Exception("Erro no upload para Magalu Cloud: {$errorMessage} (Código: {$errorCode})");
                }
            }
            
            throw new Exception("Erro no upload para Magalu Cloud. Código HTTP: {$httpCode}. Verifique as credenciais, permissões e se o bucket existe.");
        }
        
        error_log("Magalu Upload Success (cURL) - HTTP {$httpCode}, Key: {$key}");
        
        // Retornar URL pública do arquivo
        return "{$this->endpoint}/{$this->bucket}/{$key}";
    }
    
    /**
     * Retorna o objeto (conteúdo e Content-Type) para exibição em proxy.
     * @return array{body: string, content_type: string}|null
     */
    public function getObject(string $key): ?array {
        if (trim($key) === '') return null;
        if ($this->s3Client === null) return null;
        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
            $body = (string) $result['Body'];
            $contentType = $result['ContentType'] ?? 'application/octet-stream';
            return ['body' => $body, 'content_type' => $contentType];
        } catch (\Exception $e) {
            error_log("Magalu getObject error: " . $e->getMessage());
            return null;
        }
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

// API endpoint - APENAS se arquivo for acessado DIRETAMENTE (não quando incluído)
// Verificar se está sendo incluído por outro arquivo
$isIncluded = (basename($_SERVER['PHP_SELF']) !== basename(__FILE__)) || 
              (strpos($_SERVER['REQUEST_URI'] ?? '', 'upload_magalu.php') === false);

// Só executar endpoint se for acesso direto
if (!$isIncluded && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
} elseif (!$isIncluded) {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Método não permitido']);
}
