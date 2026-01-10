<?php
// notificacoes_helper.php — Sistema Global de Notificações (ETAPAS 13-17)
require_once __DIR__ . '/../conexao.php';

class NotificacoesHelper {
    private $pdo;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
    }
    
    /**
     * Registrar uma nova notificação pendente (ETAPA 13)
     */
    public function registrarNotificacao($modulo, $tipo, $entidade_tipo, $entidade_id, $titulo, $descricao = '', $destinatario_tipo = 'ambos') {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO sistema_notificacoes_pendentes 
                (modulo, tipo, entidade_tipo, entidade_id, titulo, descricao, destinatario_tipo)
                VALUES (:modulo, :tipo, :entidade_tipo, :entidade_id, :titulo, :descricao, :destinatario_tipo)
            ");
            $stmt->execute([
                ':modulo' => $modulo,
                ':tipo' => $tipo,
                ':entidade_tipo' => $entidade_tipo,
                ':entidade_id' => $entidade_id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':destinatario_tipo' => $destinatario_tipo
            ]);
            
            // Atualizar última atividade global
            $this->atualizarUltimaAtividade();
            
            return true;
        } catch (Exception $e) {
            error_log("Erro ao registrar notificação: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Atualizar última atividade global (ETAPA 13)
     */
    private function atualizarUltimaAtividade() {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE sistema_ultima_atividade 
                SET ultima_atividade = NOW()
                WHERE id = 1
            ");
            $stmt->execute();
        } catch (Exception $e) {
            // Ignorar erro silenciosamente
        }
    }
    
    /**
     * Verificar se deve enviar notificações (ETAPA 13)
     */
    public function deveEnviarNotificacoes() {
        try {
            // Buscar configuração
            $stmt = $this->pdo->query("SELECT tempo_inatividade_minutos FROM sistema_email_config ORDER BY id DESC LIMIT 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            $tempo_minutos = $config['tempo_inatividade_minutos'] ?? 10;
            
            // Buscar última atividade
            $stmt = $this->pdo->query("SELECT ultima_atividade, ultimo_envio, bloqueado FROM sistema_ultima_atividade WHERE id = 1");
            $atividade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$atividade || $atividade['bloqueado']) {
                return false;
            }
            
            // Verificar se há notificações pendentes
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM sistema_notificacoes_pendentes WHERE processado = FALSE");
            $pendentes = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pendentes['total'] == 0) {
                return false;
            }
            
            // Verificar tempo de inatividade
            $ultima_atividade = new DateTime($atividade['ultima_atividade']);
            $agora = new DateTime();
            $diferenca = $agora->diff($ultima_atividade);
            $minutos_inativos = ($diferenca->days * 24 * 60) + ($diferenca->h * 60) + $diferenca->i;
            
            return $minutos_inativos >= $tempo_minutos;
            
        } catch (Exception $e) {
            error_log("Erro ao verificar envio de notificações: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enviar notificações consolidadas (ETAPAS 13-15)
     */
    public function enviarNotificacoesConsolidadas() {
        try {
            // Verificar se deve enviar
            if (!$this->deveEnviarNotificacoes()) {
                return false;
            }
            
            // Buscar notificações pendentes
            $stmt = $this->pdo->query("
                SELECT * FROM sistema_notificacoes_pendentes 
                WHERE processado = FALSE 
                ORDER BY criado_em ASC
            ");
            $notificacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($notificacoes)) {
                return false;
            }
            
            // Buscar configuração de e-mail
            $stmt = $this->pdo->query("SELECT * FROM sistema_email_config ORDER BY id DESC LIMIT 1");
            $email_config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$email_config) {
                error_log("Configuração de e-mail não encontrada");
                return false;
            }
            
            // Buscar e-mail da contabilidade (ETAPA 14)
            $email_contabilidade = null;
            try {
                $stmt = $this->pdo->query("SELECT email FROM contabilidade_acesso WHERE status = 'ativo' ORDER BY id DESC LIMIT 1");
                $acesso = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($acesso) {
                    $email_contabilidade = $acesso['email'];
                }
            } catch (Exception $e) {
                // Ignorar
            }
            
            // Preparar destinatários (ETAPA 14)
            $destinatarios = [];
            
            // Administrador
            if ($email_config['email_administrador']) {
                $destinatarios[] = [
                    'email' => $email_config['email_administrador'],
                    'tipo' => 'admin',
                    'preferencias' => [
                        'contabilidade' => $email_config['preferencia_notif_contabilidade'],
                        'sistema' => $email_config['preferencia_notif_sistema'],
                        'financeiro' => $email_config['preferencia_notif_financeiro']
                    ]
                ];
            }
            
            // Contabilidade
            if ($email_contabilidade) {
                $destinatarios[] = [
                    'email' => $email_contabilidade,
                    'tipo' => 'contabilidade',
                    'preferencias' => ['contabilidade' => true, 'sistema' => true, 'financeiro' => true]
                ];
            }
            
            // Filtrar notificações por destinatário e preferências
            $notificacoes_admin = [];
            $notificacoes_contabilidade = [];
            
            foreach ($notificacoes as $notif) {
                $incluir_admin = false;
                $incluir_contabilidade = false;
                
                if ($notif['destinatario_tipo'] === 'admin' || $notif['destinatario_tipo'] === 'ambos') {
                    // Verificar preferências do admin
                    $modulo = $notif['modulo'];
                    if ($modulo === 'contabilidade' && ($email_config['preferencia_notif_contabilidade'] ?? true)) {
                        $incluir_admin = true;
                    } elseif ($modulo === 'sistema' && ($email_config['preferencia_notif_sistema'] ?? true)) {
                        $incluir_admin = true;
                    } elseif ($modulo === 'financeiro' && ($email_config['preferencia_notif_financeiro'] ?? true)) {
                        $incluir_admin = true;
                    }
                }
                
                if ($notif['destinatario_tipo'] === 'contabilidade' || $notif['destinatario_tipo'] === 'ambos') {
                    $incluir_contabilidade = true;
                }
                
                if ($incluir_admin) {
                    $notificacoes_admin[] = $notif;
                }
                if ($incluir_contabilidade) {
                    $notificacoes_contabilidade[] = $notif;
                }
            }
            
            // Enviar e-mails (ETAPA 15)
            $email_helper = new EmailGlobalHelper();
            require_once __DIR__ . '/push_helper.php';
            $push_helper = new PushHelper();
            
            $enviados = 0;
            $push_enviados = 0;
            
            // E-mail para administrador
            if (!empty($notificacoes_admin) && !empty($destinatarios[0]['email'])) {
                $assunto = "Você tem novas atualizações no Portal Grupo Smile";
                $corpo = $this->gerarCorpoEmail($notificacoes_admin);
                
                if ($email_helper->enviarEmail(
                    $destinatarios[0]['email'],
                    $assunto,
                    $corpo
                )) {
                    $enviados++;
                }
            }
            
            // E-mail para contabilidade
            if (!empty($notificacoes_contabilidade) && $email_contabilidade) {
                $assunto = "Você tem novas atualizações no Portal Grupo Smile";
                $corpo = $this->gerarCorpoEmail($notificacoes_contabilidade);
                
                if ($email_helper->enviarEmail(
                    $email_contabilidade,
                    $assunto,
                    $corpo
                )) {
                    $enviados++;
                }
            }
            
            // Enviar push notifications para usuários internos
            foreach ($notificacoes as $notif) {
                // Buscar usuários internos que devem receber esta notificação
                $stmt = $this->pdo->prepare("
                    SELECT DISTINCT u.id
                    FROM usuarios u
                    JOIN sistema_notificacoes_navegador snn ON snn.usuario_id = u.id
                    WHERE u.ativo = TRUE
                    AND snn.consentimento_permitido = TRUE
                    AND snn.ativo = TRUE
                    AND (
                        (:modulo = 'contabilidade' AND :pref_contabilidade = TRUE) OR
                        (:modulo = 'sistema' AND :pref_sistema = TRUE) OR
                        (:modulo = 'financeiro' AND :pref_financeiro = TRUE)
                    )
                ");
                $stmt->execute([
                    ':modulo' => $notif['modulo'],
                    ':pref_contabilidade' => $email_config['preferencia_notif_contabilidade'] ?? true,
                    ':pref_sistema' => $email_config['preferencia_notif_sistema'] ?? true,
                    ':pref_financeiro' => $email_config['preferencia_notif_financeiro'] ?? true
                ]);
                $usuarios_push = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($usuarios_push as $user_id) {
                    $result = $push_helper->enviarPush(
                        $user_id,
                        'Portal Grupo Smile',
                        'Você tem novas atualizações no sistema.',
                        ['notificacao_id' => $notif['id']]
                    );
                    if ($result['success']) {
                        $push_enviados++;
                    }
                }
            }
            
            // Marcar notificações como processadas
            if ($enviados > 0 || $push_enviados > 0) {
                $ids = array_column($notificacoes, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->pdo->prepare("
                    UPDATE sistema_notificacoes_pendentes 
                    SET processado = TRUE, enviado_em = NOW()
                    WHERE id IN ($placeholders)
                ");
                $stmt->execute($ids);
                
                // Atualizar último envio
                $stmt = $this->pdo->prepare("
                    UPDATE sistema_ultima_atividade 
                    SET ultimo_envio = NOW()
                    WHERE id = 1
                ");
                $stmt->execute();
            }
            
            return $enviados > 0;
            
        } catch (Exception $e) {
            error_log("Erro ao enviar notificações: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Gerar corpo do e-mail (ETAPA 15)
     */
    private function gerarCorpoEmail($notificacoes) {
        $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1e40af; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 20px; border-radius: 0 0 8px 8px; }
        .notificacao { background: white; padding: 15px; margin-bottom: 10px; border-radius: 6px; border-left: 4px solid #1e40af; }
        .notificacao-titulo { font-weight: bold; color: #1e40af; margin-bottom: 5px; }
        .notificacao-descricao { color: #666; font-size: 0.9em; }
        .footer { text-align: center; margin-top: 20px; color: #999; font-size: 0.85em; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Portal Grupo Smile</h1>
        </div>
        <div class='content'>
            <p>Existem novas atualizações no Portal Grupo Smile.</p>
            <p>Acesse o sistema para visualizar.</p>
            
            <h3 style='margin-top: 20px;'>Atualizações:</h3>";
        
        foreach ($notificacoes as $notif) {
            $html .= "
            <div class='notificacao'>
                <div class='notificacao-titulo'>" . htmlspecialchars($notif['titulo']) . "</div>
                " . ($notif['descricao'] ? "<div class='notificacao-descricao'>" . htmlspecialchars($notif['descricao']) . "</div>" : "") . "
            </div>";
        }
        
        $html .= "
        </div>
        <div class='footer'>
            <p>Este é um e-mail automático do Portal Grupo Smile.</p>
        </div>
    </div>
</body>
</html>";
        
        return $html;
    }
}
