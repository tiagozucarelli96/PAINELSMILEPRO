<?php
// email_global_helper.php — Helper para envio de e-mails usando configuração global (ETAPA 12)
require_once __DIR__ . '/../conexao.php';

// Carregar autoload do Composer (para PHPMailer)
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

class EmailGlobalHelper {
    private $pdo;
    private $config;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->carregarConfiguracao();
    }
    
    /**
     * Carregar configuração de e-mail do banco
     */
    private function carregarConfiguracao() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM sistema_email_config ORDER BY id DESC LIMIT 1");
            $this->config = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao carregar configuração de e-mail: " . $e->getMessage());
            $this->config = null;
        }
    }
    
    /**
     * Enviar e-mail usando PHPMailer ou mail() nativo
     */
    public function enviarEmail($para, $assunto, $corpo, $eh_html = true) {
        if (!$this->config) {
            error_log("Configuração de e-mail não encontrada");
            return false;
        }
        
        // Tentar usar PHPMailer se disponível
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return $this->enviarComPHPMailer($para, $assunto, $corpo, $eh_html);
        } else {
            return $this->enviarComMailNativo($para, $assunto, $corpo, $eh_html);
        }
    }
    
    /**
     * Enviar usando PHPMailer
     */
    private function enviarComPHPMailer($para, $assunto, $corpo, $eh_html) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Configurações do servidor
            $mail->isSMTP();
            $mail->Host = $this->config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['smtp_username'];
            $mail->Password = $this->config['smtp_password'];
            
            // Para porta 465, usar SSL implícito (SMTPS). Não usar STARTTLS.
            // Para outras portas (587, 25), usar STARTTLS se TLS
            $port = (int)$this->config['smtp_port'];
            $encryption = strtolower($this->config['smtp_encryption'] ?? 'ssl');
            
            if ($port === 465) {
                // Porta 465: SSL implícito (SMTPS)
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->SMTPAutoTLS = false; // Não tentar STARTTLS na porta 465
            } elseif ($encryption === 'tls') {
                // Outras portas com TLS: usar STARTTLS
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                // SSL em outras portas (não recomendado, mas suportado)
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            }
            
            $mail->Port = $port;
            $mail->CharSet = 'UTF-8';
            $mail->SMTPDebug = 0; // Desabilitar debug por padrão (pode ser ativado no diagnóstico)
            
            // Remetente e destinatário
            $mail->setFrom($this->config['email_remetente'], 'Portal Grupo Smile');
            $mail->addAddress($para);
            
            // Conteúdo
            $mail->isHTML($eh_html);
            $mail->Subject = $assunto;
            $mail->Body = $corpo;
            
            if (!$eh_html) {
                $mail->AltBody = strip_tags($corpo);
            }
            
            $mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Erro ao enviar e-mail com PHPMailer: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enviar usando mail() nativo (fallback)
     */
    private function enviarComMailNativo($para, $assunto, $corpo, $eh_html) {
        try {
            $headers = [];
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: " . ($eh_html ? "text/html" : "text/plain") . "; charset=UTF-8";
            $headers[] = "From: " . $this->config['email_remetente'];
            $headers[] = "Reply-To: " . $this->config['email_remetente'];
            $headers[] = "X-Mailer: PHP/" . phpversion();
            
            $headers_string = implode("\r\n", $headers);
            
            return mail($para, $assunto, $corpo, $headers_string);
            
        } catch (Exception $e) {
            error_log("Erro ao enviar e-mail com mail() nativo: " . $e->getMessage());
            return false;
        }
    }
}
