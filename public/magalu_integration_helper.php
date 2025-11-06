<?php
// magalu_integration_helper.php — Integração completa do Magalu Object Storage
require_once __DIR__ . '/magalu_storage_helper.php';

class MagaluIntegrationHelper {
    private $magalu;
    private $pdo;
    
    public function __construct() {
        $this->magalu = new MagaluStorageHelper();
        $this->pdo = $GLOBALS['pdo'];
    }
    
    /**
     * Upload de anexo para pagamentos
     */
    public function uploadAnexoPagamento($arquivo, $solicitacao_id, $tipo_anexo = 'solicitacao') {
        try {
            // Upload para Magalu
            $resultado = $this->magalu->uploadFile($arquivo, 'payments/' . $solicitacao_id);
            
            if (!$resultado['success']) {
                return [
                    'sucesso' => false,
                    'erro' => 'Erro no upload: ' . $resultado['error']
                ];
            }
            
            // Salvar no banco
            $stmt = $this->pdo->prepare("
                INSERT INTO pagamentos_anexos 
                (solicitacao_id, tipo_anexo, nome_original, nome_arquivo, caminho_arquivo, 
                 tipo_mime, tamanho_bytes, criado_em) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $solicitacao_id,
                $tipo_anexo,
                $arquivo['name'],
                $resultado['filename'],
                $resultado['url'],
                $arquivo['type'],
                $arquivo['size']
            ]);
            
            return [
                'sucesso' => true,
                'anexo_id' => $this->pdo->lastInsertId(),
                'url' => $resultado['url'],
                'provider' => 'Magalu Object Storage'
            ];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Upload de anexo para RH (holerites)
     */
    public function uploadAnexoRH($arquivo, $holerite_id, $tipo_anexo = 'holerite') {
        try {
            // Upload para Magalu
            $resultado = $this->magalu->uploadFile($arquivo, 'rh/' . $holerite_id);
            
            if (!$resultado['success']) {
                return [
                    'sucesso' => false,
                    'erro' => 'Erro no upload: ' . $resultado['error']
                ];
            }
            
            // Salvar no banco
            $stmt = $this->pdo->prepare("
                INSERT INTO rh_anexos 
                (holerite_id, tipo_anexo, nome_original, nome_arquivo, caminho_arquivo, 
                 tipo_mime, tamanho_bytes, criado_em) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $holerite_id,
                $tipo_anexo,
                $arquivo['name'],
                $resultado['filename'],
                $resultado['url'],
                $arquivo['type'],
                $arquivo['size']
            ]);
            
            return [
                'sucesso' => true,
                'anexo_id' => $this->pdo->lastInsertId(),
                'url' => $resultado['url'],
                'provider' => 'Magalu Object Storage'
            ];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Upload de anexo para Contabilidade
     */
    public function uploadAnexoContabilidade($arquivo, $documento_id, $tipo_anexo = 'documento') {
        try {
            // Upload para Magalu
            $resultado = $this->magalu->uploadFile($arquivo, 'contabilidade/' . $documento_id);
            
            if (!$resultado['success']) {
                return [
                    'sucesso' => false,
                    'erro' => 'Erro no upload: ' . $resultado['error']
                ];
            }
            
            // Salvar no banco
            $stmt = $this->pdo->prepare("
                INSERT INTO contab_anexos 
                (documento_id, tipo_anexo, nome_original, nome_arquivo, caminho_arquivo, 
                 tipo_mime, tamanho_bytes, criado_em) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $documento_id,
                $tipo_anexo,
                $arquivo['name'],
                $resultado['filename'],
                $resultado['url'],
                $arquivo['type'],
                $arquivo['size']
            ]);
            
            return [
                'sucesso' => true,
                'anexo_id' => $this->pdo->lastInsertId(),
                'url' => $resultado['url'],
                'provider' => 'Magalu Object Storage'
            ];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Upload de anexo para Comercial (degustações)
     */
    public function uploadAnexoComercial($arquivo, $degustacao_id, $tipo_anexo = 'degustacao') {
        try {
            // Upload para Magalu
            $resultado = $this->magalu->uploadFile($arquivo, 'comercial/' . $degustacao_id);
            
            if (!$resultado['success']) {
                return [
                    'sucesso' => false,
                    'erro' => 'Erro no upload: ' . $resultado['error']
                ];
            }
            
            // Salvar no banco (criar tabela se necessário)
            $stmt = $this->pdo->prepare("
                INSERT INTO comercial_anexos 
                (degustacao_id, tipo_anexo, nome_original, nome_arquivo, caminho_arquivo, 
                 tipo_mime, tamanho_bytes, criado_em) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $degustacao_id,
                $tipo_anexo,
                $arquivo['name'],
                $resultado['filename'],
                $resultado['url'],
                $arquivo['type'],
                $arquivo['size']
            ]);
            
            return [
                'sucesso' => true,
                'anexo_id' => $this->pdo->lastInsertId(),
                'url' => $resultado['url'],
                'provider' => 'Magalu Object Storage'
            ];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Upload de anexo para Estoque
     */
    public function uploadAnexoEstoque($arquivo, $contagem_id, $tipo_anexo = 'contagem') {
        try {
            // Upload para Magalu
            $resultado = $this->magalu->uploadFile($arquivo, 'estoque/' . $contagem_id);
            
            if (!$resultado['success']) {
                return [
                    'sucesso' => false,
                    'erro' => 'Erro no upload: ' . $resultado['error']
                ];
            }
            
            // Salvar no banco (criar tabela se necessário)
            $stmt = $this->pdo->prepare("
                INSERT INTO estoque_anexos 
                (contagem_id, tipo_anexo, nome_original, nome_arquivo, caminho_arquivo, 
                 tipo_mime, tamanho_bytes, criado_em) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $contagem_id,
                $tipo_anexo,
                $arquivo['name'],
                $resultado['filename'],
                $resultado['url'],
                $arquivo['type'],
                $arquivo['size']
            ]);
            
            return [
                'sucesso' => true,
                'anexo_id' => $this->pdo->lastInsertId(),
                'url' => $resultado['url'],
                'provider' => 'Magalu Object Storage'
            ];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Remover anexo
     */
    public function removerAnexo($anexo_id, $tabela = 'pagamentos_anexos') {
        try {
            // Buscar informações do anexo
            $stmt = $this->pdo->prepare("SELECT caminho_arquivo FROM {$tabela} WHERE id = ?");
            $stmt->execute([$anexo_id]);
            $anexo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$anexo) {
                return ['sucesso' => false, 'erro' => 'Anexo não encontrado'];
            }
            
            // Remover do Magalu (extrair key da URL)
            $url = $anexo['caminho_arquivo'];
            $key = $this->extrairKeyDaUrl($url);
            
            if ($key) {
                $this->magalu->deleteFile($key);
            }
            
            // Remover do banco
            $stmt = $this->pdo->prepare("DELETE FROM {$tabela} WHERE id = ?");
            $stmt->execute([$anexo_id]);
            
            return ['sucesso' => true];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Extrair key da URL do Magalu
     */
    private function extrairKeyDaUrl($url) {
        $parsed = parse_url($url);
        return ltrim($parsed['path'], '/');
    }
    
    /**
     * Obter URL de download
     */
    public function obterUrlDownload($anexo_id, $tabela = 'pagamentos_anexos') {
        try {
            $stmt = $this->pdo->prepare("SELECT caminho_arquivo FROM {$tabela} WHERE id = ?");
            $stmt->execute([$anexo_id]);
            $anexo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$anexo) {
                return null;
            }
            
            return $anexo['caminho_arquivo']; // URL do Magalu
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Upload de foto de usuário
     */
    public function uploadFotoUsuario($arquivo, $usuario_id) {
        error_log("DEBUG MAGALU: uploadFotoUsuario chamado com usuario_id: $usuario_id");
        error_log("DEBUG MAGALU: arquivo recebido: " . print_r($arquivo, true));
        
        try {
            // Validar se arquivo existe
            if (!isset($arquivo['tmp_name']) || !file_exists($arquivo['tmp_name'])) {
                error_log("DEBUG MAGALU: ❌ Arquivo temporário não existe!");
                return [
                    'sucesso' => false,
                    'erro' => 'Arquivo temporário não encontrado.'
                ];
            }
            
            // Validar tipo
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $arquivo['tmp_name']);
            finfo_close($finfo);
            
            error_log("DEBUG MAGALU: MIME type detectado: $mimeType");
            
            if (!in_array($mimeType, $allowedTypes)) {
                error_log("DEBUG MAGALU: ❌ Tipo de arquivo não permitido: $mimeType");
                return [
                    'sucesso' => false,
                    'erro' => 'Tipo de arquivo não permitido. Use JPG, PNG ou GIF.'
                ];
            }
            
            // Validar tamanho (2MB)
            if ($arquivo['size'] > 2 * 1024 * 1024) {
                error_log("DEBUG MAGALU: ❌ Arquivo muito grande: " . $arquivo['size'] . " bytes");
                return [
                    'sucesso' => false,
                    'erro' => 'Arquivo muito grande. Tamanho máximo: 2MB.'
                ];
            }
            
            error_log("DEBUG MAGALU: ✅ Validações passadas, processando arquivo...");
            
            // Criar arquivo temporário para redimensionamento
            $tempDir = sys_get_temp_dir();
            $tempFile = $tempDir . '/' . uniqid('user_photo_', true) . '.' . pathinfo($arquivo['name'], PATHINFO_EXTENSION);
            
            // Mover arquivo para temp
            if (!move_uploaded_file($arquivo['tmp_name'], $tempFile)) {
                return [
                    'sucesso' => false,
                    'erro' => 'Erro ao processar arquivo temporário.'
                ];
            }
            
            // Redimensionar imagem se necessário (máximo 400x400)
            try {
                $imageInfo = getimagesize($tempFile);
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
                            $image = imagecreatefromjpeg($tempFile);
                        } elseif ($mimeType === 'image/png') {
                            $image = imagecreatefrompng($tempFile);
                        } elseif ($mimeType === 'image/gif') {
                            $image = imagecreatefromgif($tempFile);
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
                                imagejpeg($resized, $tempFile, 85);
                            } elseif ($mimeType === 'image/png') {
                                imagepng($resized, $tempFile);
                            } elseif ($mimeType === 'image/gif') {
                                imagegif($resized, $tempFile);
                            }
                            
                            imagedestroy($image);
                            imagedestroy($resized);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Erro ao redimensionar imagem: " . $e->getMessage());
                // Continua mesmo se redimensionamento falhar
            }
            
            // Verificar se arquivo temporário existe antes de upload
            if (!file_exists($tempFile)) {
                error_log("DEBUG MAGALU: ❌ Arquivo temporário não existe antes de upload: $tempFile");
                return [
                    'sucesso' => false,
                    'erro' => 'Arquivo temporário não encontrado após processamento.'
                ];
            }
            
            error_log("DEBUG MAGALU: Arquivo temporário criado: $tempFile");
            error_log("DEBUG MAGALU: Tamanho do arquivo: " . filesize($tempFile) . " bytes");
            error_log("DEBUG MAGALU: Fazendo upload para Magalu...");
            
            // Usar mesma estrutura de pastas que Trello: prefix/YYYY/MM/uuid.ext
            $year = date('Y');
            $month = date('m');
            $uuid = uniqid();
            $extension = pathinfo($tempFile, PATHINFO_EXTENSION);
            $key = "usuarios/fotos/{$year}/{$month}/{$uuid}.{$extension}";
            
            error_log("DEBUG MAGALU: Key gerado: $key");
            error_log("DEBUG MAGALU: Subpasta: usuarios/fotos/{$year}/{$month}");
            
            // Upload para Magalu usando a key gerada
            $resultado = $this->magalu->uploadFileFromPath(
                $tempFile,
                '', // Não passar subfolder, já está na key
                $mimeType,
                $key // Passar a key completa
            );
            
            error_log("DEBUG MAGALU: Resultado do uploadFileFromPath: " . print_r($resultado, true));
            
            // Limpar arquivo temporário
            @unlink($tempFile);
            
            if (!$resultado['success']) {
                error_log("DEBUG MAGALU: ❌ Erro no upload para Magalu: " . ($resultado['error'] ?? 'Erro desconhecido'));
                return [
                    'sucesso' => false,
                    'erro' => 'Erro no upload para Magalu: ' . ($resultado['error'] ?? 'Erro desconhecido')
                ];
            }
            
            error_log("DEBUG MAGALU: ✅ Upload bem-sucedido! URL: " . $resultado['url']);
            error_log("DEBUG MAGALU: Key: " . ($resultado['key'] ?? 'N/A'));
            
            return [
                'sucesso' => true,
                'url' => $resultado['url'],
                'key' => $resultado['key'],
                'provider' => 'Magalu Object Storage'
            ];
            
        } catch (Exception $e) {
            // Limpar arquivo temporário em caso de erro
            if (isset($tempFile) && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Remover foto de usuário do Magalu
     */
    public function removerFotoUsuario($url) {
        try {
            // Extrair key da URL
            $key = $this->extrairKeyDaUrl($url);
            if (!$key) {
                return ['sucesso' => false, 'erro' => 'URL inválida'];
            }
            
            // Remover do Magalu
            $result = $this->magalu->deleteFile($key);
            
            if ($result['success']) {
                return ['sucesso' => true];
            }
            
            return ['sucesso' => false, 'erro' => $result['error'] ?? 'Erro ao remover arquivo'];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Verificar status do Magalu
     */
    public function verificarStatus() {
        return $this->magalu->testConnection();
    }
    
    /**
     * Obter estatísticas de uso
     */
    public function obterEstatisticas() {
        return $this->magalu->getUsageStats();
    }
}
?>
