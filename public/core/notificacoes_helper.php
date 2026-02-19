<?php
// notificacoes_helper.php — Sistema Global de Notificações (ETAPAS 13-17)
require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/email_global_helper.php';
require_once __DIR__ . '/notification_dispatcher.php';

class NotificacoesHelper {
    private $pdo;
    private $dispatcher;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->dispatcher = null;
    }
    
    /**
     * Registrar uma nova notificação pendente (ETAPA 13)
     */
    public function registrarNotificacao($modulo, $tipo, $entidade_tipo, $entidade_id, $titulo, $descricao = '', $destinatario_tipo = 'ambos') {
        $modulo = trim((string)$modulo);
        $tipo = trim((string)$tipo);
        $entidade_tipo = trim((string)$entidade_tipo);
        $entidade_id = (int)$entidade_id;
        $titulo = trim((string)$titulo);
        $descricao = trim((string)$descricao);
        $destinatario_tipo = trim((string)$destinatario_tipo);

        if ($titulo === '') {
            $titulo = 'Nova atualização';
        }
        if ($destinatario_tipo === '') {
            $destinatario_tipo = 'ambos';
        }

        try {
            // Fluxo legado: fila consolidada
            if (!$this->tableExists('sistema_notificacoes_pendentes')) {
                return $this->enviarNotificacaoImediata($modulo, $tipo, $entidade_tipo, $entidade_id, $titulo, $descricao, $destinatario_tipo);
            }

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
            return $this->enviarNotificacaoImediata($modulo, $tipo, $entidade_tipo, $entidade_id, $titulo, $descricao, $destinatario_tipo);
        }
    }

    private function getDispatcher() {
        if ($this->dispatcher instanceof NotificationDispatcher) {
            return $this->dispatcher;
        }

        try {
            $this->dispatcher = new NotificationDispatcher($this->pdo);
            $this->dispatcher->ensureInternalSchema();
        } catch (Throwable $e) {
            error_log("Erro ao iniciar NotificationDispatcher: " . $e->getMessage());
            $this->dispatcher = null;
        }

        return $this->dispatcher;
    }

    private function tableExists($tableName): bool {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = current_schema()
                  AND table_name = :table
                LIMIT 1
            ");
            $stmt->execute([':table' => $tableName]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function columnExists($tableName, $columnName): bool {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 1
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = :table
                  AND column_name = :column
                LIMIT 1
            ");
            $stmt->execute([
                ':table' => $tableName,
                ':column' => $columnName,
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function buscarDestinatariosInternos($destinatario_tipo): array {
        $destinatario_tipo = trim((string)$destinatario_tipo);
        if (!in_array($destinatario_tipo, ['admin', 'ambos', 'contabilidade'], true)) {
            $destinatario_tipo = 'ambos';
        }

        try {
            $hasAtivo = $this->columnExists('usuarios', 'ativo');
            $hasPermAdmin = $this->columnExists('usuarios', 'perm_administrativo');
            $hasPermSuperadmin = $this->columnExists('usuarios', 'perm_superadmin');
            $hasEmail = $this->columnExists('usuarios', 'email');

            $select = $hasEmail ? 'id, email' : 'id, NULL::text AS email';
            $where = $hasAtivo ? 'ativo IS DISTINCT FROM FALSE' : '1=1';

            $filtrosPermissao = [];
            if ($hasPermAdmin) {
                $filtrosPermissao[] = 'perm_administrativo = TRUE';
            }
            if ($hasPermSuperadmin) {
                $filtrosPermissao[] = 'perm_superadmin = TRUE';
            }
            if (!empty($filtrosPermissao)) {
                $where .= ' AND (' . implode(' OR ', $filtrosPermissao) . ')';
            }

            $stmt = $this->pdo->query("SELECT {$select} FROM usuarios WHERE {$where} ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log("Erro ao buscar destinatários internos: " . $e->getMessage());
            return [];
        }
    }

    private function enviarEmailContabilidade($titulo, $descricao): bool {
        if (!$this->tableExists('contabilidade_acesso')) {
            return false;
        }

        $resendKey = getenv('RESEND_API_KEY') ?: ($_ENV['RESEND_API_KEY'] ?? '');
        if (trim((string)$resendKey) === '') {
            return false;
        }

        try {
            $stmt = $this->pdo->query("
                SELECT email
                FROM contabilidade_acesso
                WHERE status = 'ativo'
                  AND email IS NOT NULL
                  AND email <> ''
                ORDER BY id DESC
                LIMIT 1
            ");
            $email = trim((string)($stmt->fetchColumn() ?: ''));
            if ($email === '') {
                return false;
            }

            $emailHelper = new EmailGlobalHelper();
            $body = $this->gerarCorpoEmail([[
                'titulo' => $titulo,
                'descricao' => $descricao,
            ]]);
            return (bool)$emailHelper->enviarEmail($email, 'Portal Grupo Smile - Atualização', $body, true);
        } catch (Throwable $e) {
            error_log("Erro ao enviar e-mail para contabilidade: " . $e->getMessage());
            return false;
        }
    }

    private function enviarNotificacaoImediata($modulo, $tipo, $entidade_tipo, $entidade_id, $titulo, $descricao, $destinatario_tipo): bool {
        $dispatcher = $this->getDispatcher();
        if (!$dispatcher) {
            return false;
        }

        $destinatarios = $this->buscarDestinatariosInternos($destinatario_tipo);
        $mensagem = $descricao !== '' ? $descricao : $titulo;
        $urlDestino = 'index.php?page=contabilidade';

        $dispatchResult = $dispatcher->dispatch(
            $destinatarios,
            [
                'tipo' => trim((string)$modulo) . '_' . trim((string)$tipo),
                'referencia_id' => (int)$entidade_id > 0 ? (int)$entidade_id : null,
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'url_destino' => $urlDestino,
            ],
            [
                'internal' => true,
                'push' => true,
                'email' => false,
            ]
        );

        $okInterno = ((int)$dispatchResult['enviados_interno']) > 0
            || ((int)$dispatchResult['enviados_push']) > 0;

        $okContabilidade = false;
        if ($destinatario_tipo === 'contabilidade' || $destinatario_tipo === 'ambos') {
            $okContabilidade = $this->enviarEmailContabilidade($titulo, $descricao);
        }

        return $okInterno || $okContabilidade;
    }
    
    /**
     * Atualizar última atividade global (ETAPA 13)
     */
    private function atualizarUltimaAtividade() {
        try {
            if (!$this->tableExists('sistema_ultima_atividade')) {
                return;
            }

            $stmt = $this->pdo->prepare("
                UPDATE sistema_ultima_atividade 
                SET ultima_atividade = NOW()
                WHERE id = 1
            ");
            $stmt->execute();
            if ($stmt->rowCount() === 0) {
                $this->criarUltimaAtividade();
            }
        } catch (Exception $e) {
            // Ignorar erro silenciosamente
        }
    }

    private function criarUltimaAtividade() {
        try {
            if (!$this->tableExists('sistema_ultima_atividade')) {
                return;
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO sistema_ultima_atividade (id, ultima_atividade, ultimo_envio, bloqueado)
                VALUES (1, NOW(), NULL, FALSE)
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
            if (
                !$this->tableExists('sistema_email_config') ||
                !$this->tableExists('sistema_ultima_atividade') ||
                !$this->tableExists('sistema_notificacoes_pendentes')
            ) {
                return false;
            }

            // Buscar configuração
            $stmt = $this->pdo->query("SELECT tempo_inatividade_minutos FROM sistema_email_config ORDER BY id DESC LIMIT 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            $tempo_minutos = $config['tempo_inatividade_minutos'] ?? 10;
            
            // Buscar última atividade
            $stmt = $this->pdo->query("SELECT ultima_atividade, ultimo_envio, bloqueado FROM sistema_ultima_atividade WHERE id = 1");
            $atividade = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$atividade) {
                $this->criarUltimaAtividade();
                return false;
            }

            if ($atividade['bloqueado']) {
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
            if (
                !$this->tableExists('sistema_notificacoes_pendentes') ||
                !$this->tableExists('sistema_email_config')
            ) {
                return false;
            }

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
            $podePush = $this->tableExists('sistema_notificacoes_navegador');
            $push_helper = null;
            if ($podePush) {
                require_once __DIR__ . '/push_helper.php';
                $push_helper = new PushHelper();
            }
            
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
            if ($podePush && $push_helper) {
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
