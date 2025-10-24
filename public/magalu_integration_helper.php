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
