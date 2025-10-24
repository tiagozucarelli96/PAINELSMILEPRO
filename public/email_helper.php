<?php
// email_helper.php — Helper para envio de e-mails do sistema
require_once __DIR__ . '/conexao.php';

class EmailHelper {
    private $pdo;
    private $config;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->carregarConfiguracao();
    }
    
    /**
     * Carregar configurações de e-mail do banco
     */
    private function carregarConfiguracao() {
        try {
            $stmt = $this->pdo->query("
                SELECT chave, valor 
                FROM demandas_configuracoes 
                WHERE chave LIKE 'smtp_%' OR chave LIKE 'email_%'
            ");
            $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $this->config = [
                'host' => $configs['smtp_host'] ?? 'mail.smileeventos.com.br',
                'port' => (int)($configs['smtp_port'] ?? 465),
                'username' => $configs['smtp_username'] ?? 'contato@smileeventos.com.br',
                'password' => $configs['smtp_password'] ?? 'ti1996august',
                'from_name' => $configs['smtp_from_name'] ?? 'GRUPO Smile EVENTOS',
                'from_email' => $configs['smtp_from_email'] ?? 'contato@smileeventos.com.br',
                'reply_to' => $configs['smtp_reply_to'] ?? 'contato@smileeventos.com.br',
                'encryption' => $configs['smtp_encryption'] ?? 'ssl',
                'auth' => ($configs['smtp_auth'] ?? 'true') === 'true',
                'ativado' => ($configs['email_ativado'] ?? 'true') === 'true'
            ];
        } catch (Exception $e) {
            // Configurações padrão em caso de erro
            $this->config = [
                'host' => 'mail.smileeventos.com.br',
                'port' => 465,
                'username' => 'contato@smileeventos.com.br',
                'password' => 'ti1996august',
                'from_name' => 'GRUPO Smile EVENTOS',
                'from_email' => 'contato@smileeventos.com.br',
                'reply_to' => 'contato@smileeventos.com.br',
                'encryption' => 'ssl',
                'auth' => true,
                'ativado' => true
            ];
        }
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
     * Enviar e-mail usando configurações SMTP
     */
    private function enviarEmail($para_email, $para_nome, $assunto, $corpo) {
        try {
            // Usar função mail() do PHP como fallback
            $headers = [
                'From: ' . $this->config['from_name'] . ' <' . $this->config['from_email'] . '>',
                'Reply-To: ' . $this->config['reply_to'],
                'Content-Type: text/html; charset=UTF-8',
                'X-Mailer: GRUPO Smile EVENTOS - Sistema de Demandas'
            ];
            
            $headers_string = implode("\r\n", $headers);
            
            $enviado = mail($para_email, $assunto, $corpo, $headers_string);
            
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
