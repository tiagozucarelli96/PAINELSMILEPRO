<?php
// demandas_helper.php — Helper principal do sistema de demandas
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/magalu_integration_helper.php';

class DemandasHelper {
    private $pdo;
    private $magalu;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->magalu = new MagaluIntegrationHelper();
    }
    
    /**
     * Obter agenda do dia do usuário
     */
    public function obterAgendaDia($usuario_id, $incluir_48h = false) {
        $horas = $incluir_48h ? 48 : 24;
        
        $stmt = $this->pdo->prepare("
            SELECT 
                dc.*,
                dq.nome as quadro_nome,
                dq.cor as quadro_cor,
                dc2.nome as coluna_nome,
                u.nome as responsavel_nome
            FROM demandas_cartoes dc
            JOIN demandas_quadros dq ON dc.quadro_id = dq.id
            JOIN demandas_colunas dc2 ON dc.coluna_id = dc2.id
            LEFT JOIN usuarios u ON dc.responsavel_id = u.id
            WHERE dc.responsavel_id = ?
            AND dc.concluido = FALSE
            AND dc.arquivado = FALSE
            AND dc.vencimento BETWEEN NOW() AND NOW() + INTERVAL '{$horas} hours'
            ORDER BY dc.vencimento ASC
        ");
        
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Contar notificações não lidas
     */
    public function contarNotificacoesNaoLidas($usuario_id) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM demandas_notificacoes 
            WHERE usuario_id = ? AND lida = FALSE
        ");
        
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Criar quadro
     */
    public function criarQuadro($nome, $descricao, $cor, $criado_por) {
        $stmt = $this->pdo->prepare("
            INSERT INTO demandas_quadros (nome, descricao, cor, criado_por) 
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$nome, $descricao, $cor, $criado_por]);
        $quadro_id = $this->pdo->lastInsertId();
        
        // Adicionar criador como participante com permissão total
        $this->adicionarParticipante($quadro_id, $criado_por, 'editar', $criado_por);
        
        return $quadro_id;
    }
    
    /**
     * Adicionar coluna ao quadro
     */
    public function adicionarColuna($quadro_id, $nome, $cor = '#6b7280') {
        // Obter próxima posição
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(MAX(posicao), 0) + 1 
            FROM demandas_colunas 
            WHERE quadro_id = ?
        ");
        $stmt->execute([$quadro_id]);
        $posicao = $stmt->fetchColumn();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO demandas_colunas (quadro_id, nome, posicao, cor) 
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$quadro_id, $nome, $posicao, $cor]);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Criar cartão
     */
    public function criarCartao($dados) {
        $stmt = $this->pdo->prepare("
            INSERT INTO demandas_cartoes (
                quadro_id, coluna_id, titulo, descricao, responsavel_id, 
                vencimento, prioridade, cor, criado_por
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $dados['quadro_id'],
            $dados['coluna_id'],
            $dados['titulo'],
            $dados['descricao'],
            $dados['responsavel_id'],
            $dados['vencimento'],
            $dados['prioridade'],
            $dados['cor'],
            $dados['criado_por']
        ]);
        
        $cartao_id = $this->pdo->lastInsertId();
        
        // Se for recorrente, criar regra de recorrência
        if (isset($dados['recorrente']) && $dados['recorrente']) {
            $this->criarRecorrencia($cartao_id, $dados['tipo_recorrencia'], $dados['intervalo']);
        }
        
        // Notificar responsável
        if ($dados['responsavel_id']) {
            $this->criarNotificacao(
                $dados['responsavel_id'],
                'novo_cartao',
                'Novo cartão atribuído',
                "Você foi atribuído ao cartão: {$dados['titulo']}",
                $cartao_id
            );
        }
        
        return $cartao_id;
    }
    
    /**
     * Adicionar participante ao quadro
     */
    public function adicionarParticipante($quadro_id, $usuario_id, $permissao, $convidado_por) {
        $stmt = $this->pdo->prepare("
            INSERT INTO demandas_participantes (quadro_id, usuario_id, permissao, convidado_por) 
            VALUES (?, ?, ?, ?)
            ON CONFLICT (quadro_id, usuario_id) 
            DO UPDATE SET permissao = EXCLUDED.permissao
        ");
        
        $stmt->execute([$quadro_id, $usuario_id, $permissao, $convidado_por]);
        
        // Notificar usuário
        $this->criarNotificacao(
            $usuario_id,
            'novo_cartao',
            'Convite para quadro',
            "Você foi convidado para participar de um quadro",
            null
        );
    }
    
    /**
     * Criar recorrência
     */
    public function criarRecorrencia($cartao_id, $tipo, $intervalo, $dias_semana = null, $dia_mes = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO demandas_recorrencia (cartao_id, tipo, intervalo, dias_semana, dia_mes) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$cartao_id, $tipo, $intervalo, $dias_semana, $dia_mes]);
    }
    
    /**
     * Concluir cartão
     */
    public function concluirCartao($cartao_id, $usuario_id) {
        $stmt = $this->pdo->prepare("
            UPDATE demandas_cartoes 
            SET concluido = TRUE, concluido_em = NOW(), concluido_por = ?
            WHERE id = ?
        ");
        
        $stmt->execute([$usuario_id, $cartao_id]);
        
        // Verificar se é recorrente e gerar próximo
        $stmt = $this->pdo->prepare("
            SELECT dr.* FROM demandas_recorrencia dr
            WHERE dr.cartao_id = ? AND dr.ativo = TRUE
        ");
        $stmt->execute([$cartao_id]);
        $recorrencia = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($recorrencia) {
            $proximo_cartao_id = $this->gerarProximoCartaoRecorrente($cartao_id);
            if ($proximo_cartao_id) {
                $this->criarNotificacao(
                    $usuario_id,
                    'novo_cartao',
                    'Próximo cartão gerado',
                    "Foi gerado o próximo cartão da sequência recorrente",
                    $proximo_cartao_id
                );
            }
        }
    }
    
    /**
     * Gerar próximo cartão recorrente
     */
    public function gerarProximoCartaoRecorrente($cartao_id) {
        $stmt = $this->pdo->prepare("SELECT gerar_proximo_cartao_recorrente(?)");
        $stmt->execute([$cartao_id]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Adicionar comentário
     */
    public function adicionarComentario($cartao_id, $usuario_id, $comentario, $mencionados = []) {
        $stmt = $this->pdo->prepare("
            INSERT INTO demandas_comentarios (cartao_id, usuario_id, comentario, mencionados) 
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([$cartao_id, $usuario_id, $comentario, json_encode($mencionados)]);
        $comentario_id = $this->pdo->lastInsertId();
        
        // Notificar mencionados
        foreach ($mencionados as $mencionado_id) {
            $this->criarNotificacao(
                $mencionado_id,
                'comentario',
                'Você foi mencionado',
                "Você foi mencionado em um comentário",
                $cartao_id
            );
        }
        
        return $comentario_id;
    }
    
    /**
     * Upload de anexo
     */
    public function uploadAnexo($arquivo, $cartao_id, $usuario_id) {
        try {
            // Upload para Magalu
            $resultado = $this->magalu->uploadFile($arquivo, 'demandas/' . $cartao_id);
            
            if (!$resultado['success']) {
                return [
                    'sucesso' => false,
                    'erro' => 'Erro no upload: ' . $resultado['error']
                ];
            }
            
            // Salvar no banco
            $stmt = $this->pdo->prepare("
                INSERT INTO demandas_anexos (
                    cartao_id, nome_original, nome_arquivo, caminho_arquivo, 
                    tipo_mime, tamanho_bytes, upload_por
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $cartao_id,
                $arquivo['name'],
                $resultado['filename'],
                $resultado['url'],
                $arquivo['type'],
                $arquivo['size'],
                $usuario_id
            ]);
            
            return [
                'sucesso' => true,
                'anexo_id' => $this->pdo->lastInsertId(),
                'url' => $resultado['url']
            ];
            
        } catch (Exception $e) {
            return [
                'sucesso' => false,
                'erro' => 'Erro: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Criar notificação
     */
    public function criarNotificacao($usuario_id, $tipo, $titulo, $mensagem, $cartao_id = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO demandas_notificacoes (usuario_id, tipo, titulo, mensagem, cartao_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$usuario_id, $tipo, $titulo, $mensagem, $cartao_id]);
        
        // Enviar e-mail se configurado
        $this->enviarEmailNotificacao($usuario_id, $titulo, $mensagem);
    }
    
    /**
     * Enviar e-mail de notificação
     */
    private function enviarEmailNotificacao($usuario_id, $titulo, $mensagem) {
        // Buscar preferências do usuário
        $stmt = $this->pdo->prepare("
            SELECT u.email, u.nome, dpn.notificacao_email 
            FROM usuarios u
            LEFT JOIN demandas_preferencias_notificacao dpn ON u.id = dpn.usuario_id
            WHERE u.id = ?
        ");
        $stmt->execute([$usuario_id]);
        $preferencias = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($preferencias && $preferencias['notificacao_email'] && $preferencias['email']) {
            // Usar EmailHelper para enviar e-mail
            require_once __DIR__ . '/email_helper.php';
            $emailHelper = new EmailHelper();
            
            $resultado = $emailHelper->enviarNotificacao(
                $preferencias['email'],
                $preferencias['nome'],
                $titulo,
                $mensagem
            );
            
            if (!$resultado['success']) {
                error_log("Erro ao enviar e-mail para {$preferencias['email']}: " . $resultado['error']);
            }
        }
    }
    
    /**
     * Obter KPIs de produtividade
     */
    public function obterKPIsProdutividade($usuario_id, $periodo_inicio, $periodo_fim, $quadro_id = null) {
        $where_quadro = $quadro_id ? "AND dc.quadro_id = ?" : "";
        $params = [$usuario_id, $periodo_inicio, $periodo_fim];
        if ($quadro_id) $params[] = $quadro_id;
        
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_criados,
                SUM(CASE WHEN dc.concluido = TRUE THEN 1 ELSE 0 END) as total_concluidos,
                SUM(CASE WHEN dc.concluido = TRUE AND dc.concluido_em <= dc.vencimento THEN 1 ELSE 0 END) as no_prazo,
                AVG(CASE WHEN dc.concluido = TRUE THEN EXTRACT(EPOCH FROM (dc.concluido_em - dc.criado_em))/3600 ELSE NULL END) as tempo_medio_horas
            FROM demandas_cartoes dc
            WHERE dc.responsavel_id = ?
            AND dc.criado_em BETWEEN ? AND ?
            {$where_quadro}
        ");
        
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Executar reset semanal
     */
    public function executarResetSemanal() {
        $stmt = $this->pdo->prepare("SELECT executar_reset_semanal()");
        $stmt->execute();
    }
    
    /**
     * Arquivar cartões antigos
     */
    public function arquivarCartoesAntigos() {
        $stmt = $this->pdo->prepare("SELECT arquivar_cartoes_antigos()");
        $stmt->execute();
    }
    
    /**
     * Obter configuração
     */
    public function obterConfiguracao($chave) {
        $stmt = $this->pdo->prepare("SELECT valor FROM demandas_configuracoes WHERE chave = ?");
        $stmt->execute([$chave]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Definir configuração
     */
    public function definirConfiguracao($chave, $valor) {
        $stmt = $this->pdo->prepare("
            INSERT INTO demandas_configuracoes (chave, valor) 
            VALUES (?, ?)
            ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor
        ");
        $stmt->execute([$chave, $valor]);
    }
}
?>
