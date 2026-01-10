<?php
// magalu_storage_helper.php — Helper para Magalu Object Storage
class MagaluStorageHelper {
    private $access_key;
    private $secret_key;
    private $bucket;
    private $region;
    private $endpoint;
    
    public function __construct() {
        // Carregar variáveis de ambiente (tentar múltiplas fontes para compatibilidade com Railway)
        $this->access_key = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY') ?: '';
        $this->secret_key = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY') ?: '';
        $this->bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: '';
        $this->region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
        $this->endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
        
        // Log de inicialização (sem expor credenciais completas)
        error_log("MAGALU Storage Helper inicializado:");
        error_log("  - Bucket: " . ($this->bucket ?: 'NÃO CONFIGURADO'));
        error_log("  - Region: {$this->region}");
        error_log("  - Endpoint: {$this->endpoint}");
        error_log("  - Access Key: " . ($this->access_key ? 'CONFIGURADO (' . substr($this->access_key, 0, 10) . '...)' : 'NÃO CONFIGURADO'));
        error_log("  - Secret Key: " . ($this->secret_key ? 'CONFIGURADO (' . substr($this->secret_key, 0, 10) . '...)' : 'NÃO CONFIGURADO'));
    }
    
    /**
     * Upload de arquivo para Magalu Object Storage
     */
    public function uploadFile($file, $subfolder = '') {
        // Verificar configuração com logs detalhados
        if (!$this->isConfigured()) {
            error_log("❌ MAGALU: Upload bloqueado - não configurado");
            error_log("MAGALU DEBUG - access_key: " . ($this->access_key ? 'SET (' . substr($this->access_key, 0, 10) . '...)' : 'MISSING'));
            error_log("MAGALU DEBUG - secret_key: " . ($this->secret_key ? 'SET (' . substr($this->secret_key, 0, 10) . '...)' : 'MISSING'));
            error_log("MAGALU DEBUG - bucket: " . ($this->bucket ?: 'MISSING'));
            error_log("MAGALU DEBUG - region: " . ($this->region ?: 'MISSING'));
            error_log("MAGALU DEBUG - endpoint: " . ($this->endpoint ?: 'MISSING'));
            throw new Exception('Magalu Object Storage não configurado. Verifique as variáveis de ambiente: MAGALU_ACCESS_KEY, MAGALU_SECRET_KEY, MAGALU_BUCKET');
        }
        
        error_log("✅ MAGALU: Configuração OK, iniciando upload");
        error_log("MAGALU DEBUG - Arquivo: " . ($file['name'] ?? 'N/A'));
        error_log("MAGALU DEBUG - Tamanho: " . ($file['size'] ?? 0) . " bytes");
        error_log("MAGALU DEBUG - Subfolder: " . ($subfolder ?: 'raiz'));
        
        // Validar arquivo
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $error_code = $file['error'] ?? 'N/A';
            error_log("❌ MAGALU: Erro no upload do arquivo - código: $error_code");
            throw new Exception('Erro no upload do arquivo. Código de erro: ' . $error_code);
        }
        
        // Validar tamanho (10MB máximo)
        $maxSize = 10485760; // 10MB
        if ($file['size'] > $maxSize) {
            $sizeMB = round($file['size'] / 1048576, 2);
            error_log("❌ MAGALU: Arquivo muito grande - {$sizeMB}MB (máximo: 10MB)");
            throw new Exception("Arquivo muito grande. Tamanho: {$sizeMB}MB. Máximo permitido: 10MB");
        }
        
        // Validar tipo
        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'csv'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_types)) {
            error_log("❌ MAGALU: Tipo de arquivo não permitido - extensão: $extension");
            throw new Exception('Tipo de arquivo não permitido. Extensão: ' . $extension . '. Permitidos: ' . implode(', ', $allowed_types));
        }
        
        error_log("✅ MAGALU: Validações passadas - extensão: $extension, tamanho: " . round($file['size'] / 1024, 2) . "KB");
        
        // Gerar nome único
        $filename = $this->generateFilename($file['name']);
        $key = $subfolder ? $subfolder . '/' . $filename : $filename;
        
        // Fazer upload via API S3-compatible
        $result = $this->s3Upload($file, $key);
        
        if ($result['success']) {
            return [
                'success' => true,
                'url' => $this->getPublicUrl($key),
                'filename' => $filename,
                'size' => $file['size'],
                'provider' => 'Magalu Object Storage',
                'key' => $key
            ];
        }
        
        return ['success' => false, 'error' => $result['error']];
    }
    
    /**
     * Upload via API S3-compatible
     * Aceita tanto arquivo temporário quanto caminho de arquivo
     * USA AUTENTICAÇÃO AWS S3 Signature Version 2 (mesmo que Trello)
     */
    private function s3Upload($file, $key) {
        error_log("DEBUG MAGALU S3: s3Upload chamado");
        error_log("DEBUG MAGALU S3: key: $key");
        error_log("DEBUG MAGALU S3: file type: " . (is_array($file) ? 'array' : gettype($file)));
        
        $url = $this->endpoint . '/' . $this->bucket . '/' . $key;
        error_log("DEBUG MAGALU S3: URL completa: $url");
        error_log("DEBUG MAGALU S3: endpoint: " . $this->endpoint);
        error_log("DEBUG MAGALU S3: bucket: " . $this->bucket);
        
        // Determinar se é array $_FILES ou caminho de arquivo
        $filePath = is_array($file) ? $file['tmp_name'] : $file;
        $fileSize = is_array($file) ? $file['size'] : filesize($file);
        $contentType = is_array($file) ? $file['type'] : mime_content_type($file);
        
        error_log("DEBUG MAGALU S3: filePath: $filePath");
        error_log("DEBUG MAGALU S3: fileSize: $fileSize");
        error_log("DEBUG MAGALU S3: contentType: $contentType");
        
        // Ler conteúdo do arquivo
        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            error_log("DEBUG MAGALU S3: ❌ Erro ao ler arquivo");
            return ['success' => false, 'error' => 'Erro ao ler arquivo'];
        }
        
        error_log("DEBUG MAGALU S3: ✅ Arquivo lido, tamanho do conteúdo: " . strlen($fileContent) . " bytes");
        
        // AUTENTICAÇÃO AWS S3 Signature Version 2 (mesmo que Trello usa)
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $canonicalizedResource = "/{$this->bucket}/{$key}";
        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$canonicalizedResource}";
        
        // Gerar assinatura HMAC-SHA1 e codificar em base64
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secret_key, true));
        
        error_log("DEBUG MAGALU S3: String to sign: " . str_replace("\n", "\\n", $stringToSign));
        error_log("DEBUG MAGALU S3: Signature gerada: " . substr($signature, 0, 20) . "...");
        error_log("DEBUG MAGALU S3: Access Key: " . substr($this->access_key, 0, 10) . "...");
        
        // Headers da requisição COM AUTENTICAÇÃO
        $headers = [
            'Date: ' . $date,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . $fileSize,
            'Authorization: AWS ' . $this->access_key . ':' . $signature
        ];
        
        error_log("DEBUG MAGALU S3: Headers: " . print_r($headers, true));
        error_log("DEBUG MAGALU S3: Iniciando requisição cURL com autenticação...");
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_VERBOSE => true
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);
        
        error_log("DEBUG MAGALU S3: HTTP Code: $http_code");
        error_log("DEBUG MAGALU S3: cURL Error: " . ($error ?: 'Nenhum'));
        error_log("DEBUG MAGALU S3: Response: " . substr($response, 0, 500));
        error_log("DEBUG MAGALU S3: cURL Info: " . print_r($curl_info, true));
        
        if ($error) {
            error_log("DEBUG MAGALU S3: ❌ Erro cURL: $error");
            return ['success' => false, 'error' => 'cURL Error: ' . $error];
        }
        
        // Extrair mensagem de erro da resposta XML se houver
        if ($http_code !== 200 && $http_code !== 201) {
            error_log("DEBUG MAGALU S3: ❌ Upload falhou! HTTP $http_code");
            if ($response && strpos($response, '<Error>') !== false) {
                if (preg_match('/<Message>(.*?)<\/Message>/', $response, $matches)) {
                    error_log("DEBUG MAGALU S3: Mensagem de erro XML: " . $matches[1]);
                    return ['success' => false, 'error' => 'Erro no upload para Magalu Cloud: ' . $matches[1]];
                }
            }
            error_log("DEBUG MAGALU S3: Response completo: $response");
            return ['success' => false, 'error' => 'HTTP ' . $http_code . ': ' . substr($response, 0, 200)];
        }
        
        error_log("DEBUG MAGALU S3: ✅ Upload bem-sucedido! HTTP $http_code");
        return ['success' => true];
    }
    
    /**
     * Upload de arquivo já processado (redimensionado, etc)
     * Aceita key completo ou gera automaticamente
     */
    public function uploadFileFromPath($filePath, $subfolder = '', $contentType = null, $keyCompleto = null) {
        error_log("DEBUG MAGALU STORAGE: uploadFileFromPath chamado");
        error_log("DEBUG MAGALU STORAGE: filePath: $filePath");
        error_log("DEBUG MAGALU STORAGE: subfolder: $subfolder");
        error_log("DEBUG MAGALU STORAGE: contentType: " . ($contentType ?? 'N/A'));
        error_log("DEBUG MAGALU STORAGE: keyCompleto: " . ($keyCompleto ?? 'N/A - será gerado'));
        
        if (!$this->isConfigured()) {
            error_log("DEBUG MAGALU STORAGE: ❌ Magalu não configurado!");
            throw new Exception('Magalu Object Storage não configurado');
        }
        
        if (!file_exists($filePath)) {
            error_log("DEBUG MAGALU STORAGE: ❌ Arquivo não encontrado: $filePath");
            throw new Exception('Arquivo não encontrado: ' . $filePath);
        }
        
        error_log("DEBUG MAGALU STORAGE: ✅ Arquivo existe, tamanho: " . filesize($filePath) . " bytes");
        
        // Usar key completo se fornecido, senão gerar
        if ($keyCompleto) {
            $key = $keyCompleto;
            $filename = basename($key);
            error_log("DEBUG MAGALU STORAGE: Usando key completo fornecido: $key");
        } else {
            // Gerar nome único (fallback para compatibilidade)
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $filename = $this->generateFilename('file.' . $extension);
            $key = $subfolder ? $subfolder . '/' . $filename : $filename;
            error_log("DEBUG MAGALU STORAGE: Filename gerado: $filename");
            error_log("DEBUG MAGALU STORAGE: Key gerado: $key");
        }
        
        // Determinar content type
        if (!$contentType) {
            $contentType = mime_content_type($filePath);
            error_log("DEBUG MAGALU STORAGE: Content type detectado: $contentType");
        }
        
        // Fazer upload via API S3-compatible
        error_log("DEBUG MAGALU STORAGE: Chamando s3Upload...");
        $result = $this->s3Upload($filePath, $key);
        
        error_log("DEBUG MAGALU STORAGE: Resultado do s3Upload: " . print_r($result, true));
        
        if ($result['success']) {
            $url = $this->getPublicUrl($key);
            error_log("DEBUG MAGALU STORAGE: ✅ Upload bem-sucedido! URL: $url");
            return [
                'success' => true,
                'url' => $url,
                'filename' => $filename,
                'size' => filesize($filePath),
                'provider' => 'Magalu Object Storage',
                'key' => $key
            ];
        }
        
        error_log("DEBUG MAGALU STORAGE: ❌ Upload falhou: " . ($result['error'] ?? 'Erro desconhecido'));
        return ['success' => false, 'error' => $result['error']];
    }
    
    /**
     * Obter URL pública do arquivo
     */
    public function getPublicUrl($key) {
        return $this->endpoint . '/' . $this->bucket . '/' . $key;
    }
    
    /**
     * Verificar se está configurado
     */
    public function isConfigured() {
        $configured = !empty($this->access_key) && 
                      !empty($this->secret_key) && 
                      !empty($this->bucket);
        
        if (!$configured) {
            error_log("MAGALU isConfigured: FALSE");
            error_log("  - access_key: " . ($this->access_key ? 'SET' : 'EMPTY'));
            error_log("  - secret_key: " . ($this->secret_key ? 'SET' : 'EMPTY'));
            error_log("  - bucket: " . ($this->bucket ?: 'EMPTY'));
            error_log("  - region: " . ($this->region ?: 'DEFAULT'));
            error_log("  - endpoint: " . ($this->endpoint ?: 'DEFAULT'));
        } else {
            error_log("MAGALU isConfigured: TRUE");
        }
        
        return $configured;
    }
    
    /**
     * Obter configuração (mascarada)
     */
    public function getConfiguration() {
        return [
            'access_key' => $this->access_key ? '***' . substr($this->access_key, -4) : 'Não configurado',
            'secret_key' => $this->secret_key ? '***' . substr($this->secret_key, -4) : 'Não configurado',
            'bucket' => $this->bucket ?: 'Não configurado',
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'configured' => $this->isConfigured()
        ];
    }
    
    /**
     * Testar conexão
     */
    public function testConnection() {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Não configurado'];
        }
        
        $test_url = $this->endpoint . '/' . $this->bucket;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $test_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'cURL Error: ' . $error];
        }
        
        if ($http_code === 200 || $http_code === 403) {
            return ['success' => true, 'message' => 'Conexão OK com Magalu Object Storage'];
        }
        
        return ['success' => false, 'error' => 'HTTP ' . $http_code];
    }
    
    /**
     * Gerar nome único para arquivo
     */
    private function generateFilename($original_name) {
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $uuid = $this->generateUUID();
        return $uuid . '.' . $extension;
    }
    
    /**
     * Gerar UUID v4
     */
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Obter estatísticas de uso
     */
    public function getUsageStats() {
        return [
            'message' => 'Estatísticas disponíveis no painel Magalu Object Storage',
            'suggestion' => 'Acesse o painel para ver uso detalhado',
            'provider' => 'Magalu Object Storage'
        ];
    }
    
    /**
     * Remover arquivo
     */
    public function deleteFile($key) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Não configurado'];
        }
        
        $url = $this->endpoint . '/' . $this->bucket . '/' . $key;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 204) {
            return ['success' => true, 'message' => 'Arquivo removido com sucesso'];
        }
        
        return ['success' => false, 'error' => 'HTTP ' . $http_code];
    }
}
?>
