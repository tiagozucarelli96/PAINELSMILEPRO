<?php
// lc_anexos_helper.php
// Helper para gerenciar anexos de solicitações de pagamento

/**
 * Configurações do sistema de anexos
 */
class LcAnexosConfig {
    const TIPOS_PERMITIDOS = ['pdf', 'jpg', 'jpeg', 'png'];
    const MIMES_PERMITIDOS = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png'
    ];
    const TAMANHO_MAX_ARQUIVO = 10 * 1024 * 1024; // 10 MB
    const MAX_ARQUIVOS = 5;
    const TAMANHO_MAX_TOTAL = 25 * 1024 * 1024; // 25 MB
    const RATE_LIMIT_UPLOADS = 5; // por hora
    const THUMBNAIL_MAX_SIZE = 800;
    
    const PASTA_UPLOADS = 'uploads/payments';
    const PASTA_THUMBNAILS = 'thumbs';
}

/**
 * Classe para gerenciar anexos
 */
class LcAnexosManager {
    private $pdo;
    private $config;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->config = new LcAnexosConfig();
    }
    
    /**
     * Validar arquivo antes do upload
     */
    public function validarArquivo(array $arquivo): array {
        $erros = [];
        
        // Verificar se é upload válido
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            $erros[] = 'Erro no upload do arquivo';
            return $erros;
        }
        
        // Verificar tamanho
        if ($arquivo['size'] > LcAnexosConfig::TAMANHO_MAX_ARQUIVO) {
            $erros[] = 'Arquivo excede 10 MB';
        }
        
        // Verificar tipo por extensão
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        if (!in_array($extensao, LcAnexosConfig::TIPOS_PERMITIDOS)) {
            $erros[] = 'Tipo não permitido. Use PDF/JPG/PNG';
        }
        
        // Verificar MIME type real
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_real = finfo_file($finfo, $arquivo['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_real, LcAnexosConfig::MIMES_PERMITIDOS)) {
            $erros[] = 'Tipo de arquivo não permitido';
        }
        
        return $erros;
    }
    
    /**
     * Verificar rate limit
     */
    public function verificarRateLimit(string $ip_origem, ?string $token_publico = null, ?int $solicitacao_id = null): bool {
        try {
            $stmt = $this->pdo->prepare("SELECT lc_verificar_rate_limit_anexos(?, ?, ?)");
            $stmt->execute([$ip_origem, $token_publico, $solicitacao_id]);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Erro ao verificar rate limit: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verificar limites da solicitação
     */
    public function verificarLimitesSolicitacao(int $solicitacao_id, int $tamanho_novo): array {
        $erros = [];
        
        try {
            // Contar arquivos existentes
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*), COALESCE(SUM(tamanho_bytes), 0) 
                FROM lc_anexos_pagamentos 
                WHERE solicitacao_id = ? AND eh_comprovante = false
            ");
            $stmt->execute([$solicitacao_id]);
            $resultado = $stmt->fetch(PDO::FETCH_NUM);
            
            $arquivos_existentes = (int)$resultado[0];
            $tamanho_existente = (int)$resultado[1];
            
            // Verificar limite de arquivos
            if ($arquivos_existentes >= LcAnexosConfig::MAX_ARQUIVOS) {
                $erros[] = 'Limite de 5 anexos atingido';
            }
            
            // Verificar limite de tamanho total
            if (($tamanho_existente + $tamanho_novo) > LcAnexosConfig::TAMANHO_MAX_TOTAL) {
                $erros[] = 'Limite de 25 MB total atingido';
            }
            
        } catch (Exception $e) {
            $erros[] = 'Erro ao verificar limites';
        }
        
        return $erros;
    }
    
    /**
     * Fazer upload do arquivo
     */
    public function fazerUpload(array $arquivo, int $solicitacao_id, ?int $autor_id = null, 
                               string $autor_tipo = 'interno', ?string $ip_origem = null, 
                               bool $eh_comprovante = false): array {
        try {
            // Validar arquivo
            $erros = $this->validarArquivo($arquivo);
            if (!empty($erros)) {
                return ['sucesso' => false, 'erros' => $erros];
            }
            
            // Verificar rate limit
            if (!$this->verificarRateLimit($ip_origem ?? $_SERVER['REMOTE_ADDR'])) {
                return ['sucesso' => false, 'erros' => ['Limite de uploads atingido. Tente novamente mais tarde.']];
            }
            
            // Verificar limites da solicitação
            $erros_limites = $this->verificarLimitesSolicitacao($solicitacao_id, $arquivo['size']);
            if (!empty($erros_limites)) {
                return ['sucesso' => false, 'erros' => $erros_limites];
            }
            
            // Gerar nome único
            $uuid = uniqid() . '_' . bin2hex(random_bytes(8));
            $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
            $nome_arquivo = $uuid . '.' . $extensao;
            
            // Criar diretório se não existir
            $pasta_solicitacao = LcAnexosConfig::PASTA_UPLOADS . '/' . $solicitacao_id;
            if (!is_dir($pasta_solicitacao)) {
                mkdir($pasta_solicitacao, 0755, true);
            }
            
            $caminho_completo = $pasta_solicitacao . '/' . $nome_arquivo;
            
            // Mover arquivo
            if (!move_uploaded_file($arquivo['tmp_name'], $caminho_completo)) {
                return ['sucesso' => false, 'erros' => ['Falha no upload. Tente novamente.']];
            }
            
            // Obter MIME type real
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_real = finfo_file($finfo, $caminho_completo);
            finfo_close($finfo);
            
            // Salvar no banco
            $stmt = $this->pdo->prepare("
                INSERT INTO lc_anexos_pagamentos 
                (solicitacao_id, nome_original, nome_arquivo, caminho_arquivo, tipo_mime, 
                 tamanho_bytes, eh_comprovante, autor_id, autor_tipo, ip_origem)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $solicitacao_id,
                $arquivo['name'],
                $nome_arquivo,
                $caminho_completo,
                $mime_real,
                $arquivo['size'],
                $eh_comprovante,
                $autor_id,
                $autor_tipo,
                $ip_origem ?? $_SERVER['REMOTE_ADDR']
            ]);
            
            $anexo_id = $this->pdo->lastInsertId();
            
            // Criar miniatura se for imagem
            if (in_array($extensao, ['jpg', 'jpeg', 'png'])) {
                $this->criarMiniatura($anexo_id, $caminho_completo, $extensao);
            }
            
            // Registrar na timeline
            $this->registrarTimeline($solicitacao_id, $anexo_id, $arquivo['name'], $eh_comprovante);
            
            return [
                'sucesso' => true, 
                'anexo_id' => $anexo_id,
                'nome_arquivo' => $nome_arquivo,
                'tamanho' => $arquivo['size']
            ];
            
        } catch (Exception $e) {
            error_log("Erro no upload: " . $e->getMessage());
            return ['sucesso' => false, 'erros' => ['Erro interno. Tente novamente.']];
        }
    }
    
    /**
     * Criar miniatura de imagem
     */
    private function criarMiniatura(int $anexo_id, string $caminho_original, string $extensao): void {
        try {
            // Carregar imagem
            switch ($extensao) {
                case 'jpg':
                case 'jpeg':
                    $imagem = imagecreatefromjpeg($caminho_original);
                    break;
                case 'png':
                    $imagem = imagecreatefrompng($caminho_original);
                    break;
                default:
                    return;
            }
            
            if (!$imagem) return;
            
            // Obter dimensões originais
            $largura_original = imagesx($imagem);
            $altura_original = imagesy($imagem);
            
            // Calcular novas dimensões
            $ratio = min(
                LcAnexosConfig::THUMBNAIL_MAX_SIZE / $largura_original,
                LcAnexosConfig::THUMBNAIL_MAX_SIZE / $altura_original
            );
            
            if ($ratio >= 1) {
                // Imagem já é pequena, não precisa de miniatura
                imagedestroy($imagem);
                return;
            }
            
            $nova_largura = (int)($largura_original * $ratio);
            $nova_altura = (int)($altura_original * $ratio);
            
            // Criar miniatura
            $miniatura = imagecreatetruecolor($nova_largura, $nova_altura);
            imagecopyresampled($miniatura, $imagem, 0, 0, 0, 0, 
                              $nova_largura, $nova_altura, $largura_original, $altura_original);
            
            // Criar diretório de miniaturas
            $pasta_thumbnails = dirname($caminho_original) . '/' . LcAnexosConfig::PASTA_THUMBNAILS;
            if (!is_dir($pasta_thumbnails)) {
                mkdir($pasta_thumbnails, 0755, true);
            }
            
            // Salvar miniatura
            $caminho_miniatura = $pasta_thumbnails . '/' . pathinfo($caminho_original, PATHINFO_FILENAME) . '.jpg';
            imagejpeg($miniatura, $caminho_miniatura, 85);
            
            // Salvar no banco
            $stmt = $this->pdo->prepare("
                INSERT INTO lc_anexos_miniaturas (anexo_id, caminho_miniatura, largura, altura)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$anexo_id, $caminho_miniatura, $nova_largura, $nova_altura]);
            
            // Limpar memória
            imagedestroy($imagem);
            imagedestroy($miniatura);
            
        } catch (Exception $e) {
            error_log("Erro ao criar miniatura: " . $e->getMessage());
        }
    }
    
    /**
     * Registrar evento na timeline
     */
    private function registrarTimeline(int $solicitacao_id, int $anexo_id, string $nome_arquivo, bool $eh_comprovante): void {
        try {
            $mensagem = $eh_comprovante 
                ? "Comprovante anexado: " . $nome_arquivo
                : "Anexo adicionado: " . $nome_arquivo;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO lc_timeline_pagamentos (solicitacao_id, tipo_evento, mensagem)
                VALUES (?, 'comentario', ?)
            ");
            $stmt->execute([$solicitacao_id, $mensagem]);
        } catch (Exception $e) {
            error_log("Erro ao registrar timeline: " . $e->getMessage());
        }
    }
    
    /**
     * Buscar anexos de uma solicitação
     */
    public function buscarAnexos(int $solicitacao_id, bool $incluir_comprovantes = true): array {
        try {
            $sql = "
                SELECT 
                    a.id, a.nome_original, a.nome_arquivo, a.tipo_mime, a.tamanho_bytes,
                    a.eh_comprovante, a.autor_tipo, a.criado_em,
                    u.nome as autor_nome,
                    m.caminho_miniatura, m.largura, m.altura,
                    CASE 
                        WHEN a.tamanho_bytes < 1024 THEN a.tamanho_bytes::TEXT || ' B'
                        WHEN a.tamanho_bytes < 1048576 THEN ROUND(a.tamanho_bytes/1024.0, 1)::TEXT || ' KB'
                        ELSE ROUND(a.tamanho_bytes/1048576.0, 1)::TEXT || ' MB'
                    END as tamanho_formatado
                FROM lc_anexos_pagamentos a
                LEFT JOIN usuarios u ON u.id = a.autor_id
                LEFT JOIN lc_anexos_miniaturas m ON m.anexo_id = a.id
                WHERE a.solicitacao_id = ?
            ";
            
            if (!$incluir_comprovantes) {
                $sql .= " AND a.eh_comprovante = false";
            }
            
            $sql .= " ORDER BY a.eh_comprovante DESC, a.criado_em ASC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$solicitacao_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Erro ao buscar anexos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Remover anexo
     */
    public function removerAnexo(int $anexo_id, int $solicitacao_id, string $motivo = 'Anexo removido'): bool {
        try {
            // Buscar dados do anexo
            $stmt = $this->pdo->prepare("
                SELECT caminho_arquivo, nome_arquivo, eh_comprovante 
                FROM lc_anexos_pagamentos 
                WHERE id = ? AND solicitacao_id = ?
            ");
            $stmt->execute([$anexo_id, $solicitacao_id]);
            $anexo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$anexo) {
                return false;
            }
            
            // Remover arquivo físico
            if (file_exists($anexo['caminho_arquivo'])) {
                unlink($anexo['caminho_arquivo']);
            }
            
            // Remover miniatura se existir
            $pasta_thumbnails = dirname($anexo['caminho_arquivo']) . '/' . LcAnexosConfig::PASTA_THUMBNAILS;
            $caminho_miniatura = $pasta_thumbnails . '/' . pathinfo($anexo['nome_arquivo'], PATHINFO_FILENAME) . '.jpg';
            if (file_exists($caminho_miniatura)) {
                unlink($caminho_miniatura);
            }
            
            // Remover do banco
            $stmt = $this->pdo->prepare("DELETE FROM lc_anexos_pagamentos WHERE id = ?");
            $stmt->execute([$anexo_id]);
            
            // Registrar na timeline
            $stmt = $this->pdo->prepare("
                INSERT INTO lc_timeline_pagamentos (solicitacao_id, tipo_evento, mensagem)
                VALUES (?, 'comentario', ?)
            ");
            $stmt->execute([$solicitacao_id, $motivo]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Erro ao remover anexo: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verificar permissão de download
     */
    public function verificarPermissaoDownload(int $anexo_id, ?int $usuario_id = null, ?string $token_publico = null): bool {
        try {
            $sql = "
                SELECT s.id, s.criador_id, s.fornecedor_id, s.status, f.token_publico
                FROM lc_anexos_pagamentos a
                JOIN lc_solicitacoes_pagamento s ON s.id = a.solicitacao_id
                LEFT JOIN fornecedores f ON f.id = s.fornecedor_id
                WHERE a.id = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$anexo_id]);
            $dados = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dados) {
                return false;
            }
            
            // ADM/FIN podem baixar qualquer anexo
            if ($usuario_id) {
                $stmt = $this->pdo->prepare("SELECT perfil FROM usuarios WHERE id = ?");
                $stmt->execute([$usuario_id]);
                $perfil = $stmt->fetchColumn();
                
                if (in_array($perfil, ['ADM', 'FIN'])) {
                    return true;
                }
            }
            
            // Portal público: verificar token
            if ($token_publico && $dados['token_publico'] === $token_publico) {
                return true;
            }
            
            // Autor da solicitação
            if ($usuario_id && $dados['criador_id'] == $usuario_id) {
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Erro ao verificar permissão: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Registrar download
     */
    public function registrarDownload(int $anexo_id, ?int $usuario_id = null): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO lc_anexos_logs_download (anexo_id, usuario_id, ip_origem, user_agent)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $anexo_id,
                $usuario_id,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Erro ao registrar download: " . $e->getMessage());
        }
    }
    
    /**
     * Obter estatísticas de anexos
     */
    public function obterEstatisticas(int $solicitacao_id): array {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM lc_estatisticas_anexos_solicitacao(?)");
            $stmt->execute([$solicitacao_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao obter estatísticas: " . $e->getMessage());
            return [];
        }
    }
}
?>
