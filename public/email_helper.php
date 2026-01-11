<?php
// email_helper.php — Helper para envio de e-mails do sistema (usa EmailGlobalHelper)
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/email_global_helper.php';

class EmailHelper {
    private $pdo;
    private $emailGlobal;
    private $config;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        // Usar EmailGlobalHelper que utiliza sistema_email_config
        $this->emailGlobal = new EmailGlobalHelper();
        $this->carregarConfiguracao();
    }
    
    /**
     * Carregar configurações de e-mail do banco
     */
    private function carregarConfiguracao() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM sistema_email_config ORDER BY id DESC LIMIT 1");
            $global_config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($global_config) {
                $this->config = [
                    'from_email' => $global_config['email_remetente'],
                    'email_administrador' => $global_config['email_administrador'],
                    'ativado' => true
                ];
                return;
            }
        } catch (Exception $e) {
            // Continuar para fallback
        }

        $this->config = [
            'ativado' => false
        ];
    }
    
    /**
     * Enviar e-mail de notificação
     */
    public function enviarNotificacao($para_email, $para_nome, $assunto, $mensagem, $tipo = 'notificacao') {
        if (!$this->config['ativado']) {
            return [
                'success' => false,
                'error' => 'E-mail desativado no sistema'
            ];
        }
        
        try {
            // Criar template de e-mail
            $template = $this->criarTemplateEmail($assunto, $mensagem, $tipo);
            
            // Enviar e-mail
            $resultado = $this->enviarEmail(
                $para_email,
                $para_nome,
                $assunto,
                $template
            );
            
            // Log da notificação
            $this->logNotificacao($para_email, $assunto, $resultado['success']);
            
            return $resultado;
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Enviar e-mail usando sistema global (EmailGlobalHelper)
     */
    private function enviarEmail($para_email, $para_nome, $assunto, $corpo) {
        try {
            // Usar EmailGlobalHelper que utiliza sistema_email_config
            $enviado = $this->emailGlobal->enviarEmail($para_email, $assunto, $corpo, true);
            
            if ($enviado) {
                return [
                    'success' => true,
                    'message' => 'E-mail enviado com sucesso'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Falha ao enviar e-mail'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Criar template de e-mail
     */
    private function criarTemplateEmail($assunto, $mensagem, $tipo) {
        $cor_primaria = '#1e3a8a';
        $cor_secundaria = '#3b82f6';
        
        $template = "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$assunto}</title>
        </head>
        <body style='font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f7f6;'>
            <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);'>
                
                <!-- Header -->
                <div style='background: linear-gradient(135deg, {$cor_primaria} 0%, {$cor_secundaria} 100%); color: white; padding: 30px; text-align: center;'>
                    <h1 style='margin: 0; font-size: 24px; font-weight: 700;'>GRUPO Smile EVENTOS</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Sistema de Demandas</p>
                </div>
                
                <!-- Content -->
                <div style='padding: 30px;'>
                    <h2 style='color: {$cor_primaria}; margin: 0 0 20px 0; font-size: 20px;'>{$assunto}</h2>
                    
                    <div style='background-color: #f8faff; border-left: 4px solid {$cor_secundaria}; padding: 20px; margin: 20px 0; border-radius: 4px;'>
                        {$mensagem}
                    </div>
                    
                    <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e7ff;'>
                        <p style='color: #6b7280; font-size: 14px; margin: 0;'>
                            Esta é uma notificação automática do sistema de demandas.<br>
                            Para mais informações, acesse o painel administrativo.
                        </p>
                    </div>
                </div>
                
                <!-- Footer -->
                <div style='background-color: #f8faff; padding: 20px; text-align: center; border-top: 1px solid #e0e7ff;'>
                    <p style='color: #6b7280; font-size: 12px; margin: 0;'>
                        © " . date('Y') . " GRUPO Smile EVENTOS. Todos os direitos reservados.
                    </p>
                </div>
                
            </div>
        </body>
        </html>";
        
        return $template;
    }
    
    /**
     * Log de notificação
     */
    private function logNotificacao($para_email, $assunto, $sucesso) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO demandas_logs (
                    acao, entidade, entidade_id, dados_novos, 
                    ip_origem, user_agent
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                'email_notificacao',
                'email',
                0,
                json_encode([
                    'para' => $para_email,
                    'assunto' => $assunto,
                    'sucesso' => $sucesso,
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Sistema'
            ]);
        } catch (Exception $e) {
            // Log silencioso em caso de erro
            error_log("Erro ao logar notificação: " . $e->getMessage());
        }
    }
    
    /**
     * Enviar e-mail de teste
     */
    public function enviarEmailTeste($para_email) {
        $assunto = "Teste de Configuração - Sistema de Demandas";
        $mensagem = "
        <p>Este é um e-mail de teste para verificar se a configuração de e-mail está funcionando corretamente.</p>
        <p><strong>Configurações utilizadas:</strong></p>
        <ul>
            <li>Servidor: {$this->config['host']}:{$this->config['port']}</li>
            <li>Usuário: {$this->config['username']}</li>
            <li>Encriptação: {$this->config['encryption']}</li>
            <li>Remetente: {$this->config['from_name']} &lt;{$this->config['from_email']}&gt;</li>
        </ul>
        <p>Se você recebeu este e-mail, a configuração está funcionando perfeitamente!</p>
        ";
        
        return $this->enviarNotificacao($para_email, 'Usuário de Teste', $assunto, $mensagem, 'teste');
    }
    
    /**
     * Verificar se e-mail está ativado
     */
    public function isEmailAtivado() {
        return $this->config['ativado'];
    }
    
    /**
     * Obter configurações
     */
    public function obterConfiguracao() {
        return $this->config;
    }
    
    /**
     * Ativar/desativar e-mail
     */
    public function ativarEmail($ativado = true) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO demandas_configuracoes (chave, valor, descricao, tipo) 
                VALUES ('email_ativado', ?, 'E-mail ativado/desativado', 'boolean')
                ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor
            ");
            $stmt->execute([$ativado ? 'true' : 'false']);
            
            $this->config['ativado'] = $ativado;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>
