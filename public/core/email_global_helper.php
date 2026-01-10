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
     * Enviar usando PHPMailer com tentativas automáticas de configuração
     */
    private function enviarComPHPMailer($para, $assunto, $corpo, $eh_html) {
        $port = (int)$this->config['smtp_port'];
        $encryption = strtolower($this->config['smtp_encryption'] ?? 'ssl');
        
        // Lista de configurações para tentar (em ordem de preferência)
        $tentativas = [];
        
        // Configuração principal (a que está salva)
        $tentativas[] = [
            'port' => $port,
            'encryption' => $encryption,
            'desc' => "Configuração salva: Porta $port com " . strtoupper($encryption)
        ];
        
        // Se estiver usando 587 com TLS, tentar também 465 com SSL (mais comum)
        if ($port === 587 && $encryption === 'tls') {
            $tentativas[] = [
                'port' => 465,
                'encryption' => 'ssl',
                'desc' => "Alternativa: Porta 465 com SSL (recomendado pelo servidor)"
            ];
        }
        
        // Se estiver usando 465 com SSL e falhar, tentar 587 com TLS (para Railway)
        if ($port === 465 && $encryption === 'ssl') {
            $tentativas[] = [
                'port' => 587,
                'encryption' => 'tls',
                'desc' => "Alternativa: Porta 587 com TLS (para ambientes cloud)"
            ];
        }
        
        $ultimo_erro = null;
        
        foreach ($tentativas as $tentativa) {
            try {
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                
                // Configurações do servidor
                $mail->isSMTP();
                $mail->Host = $this->config['smtp_host'];
                $mail->SMTPAuth = true;
                $mail->Username = $this->config['smtp_username'];
                $mail->Password = $this->config['smtp_password'];
                
                // Configurar porta e encriptação
                $tentativa_port = $tentativa['port'];
                $tentativa_enc = $tentativa['encryption'];
                
                if ($tentativa_port === 465) {
                    // Porta 465: SSL implícito (SMTPS)
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    $mail->SMTPAutoTLS = false; // Não tentar STARTTLS na porta 465
                } elseif ($tentativa_enc === 'tls') {
                    // Porta 587 ou outras com TLS: usar STARTTLS
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    // SSL em outras portas
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                }
                
                $mail->Port = $tentativa_port;
                $mail->CharSet = 'UTF-8';
                $mail->SMTPDebug = 0;
                $mail->Timeout = 15; // Timeout de 15 segundos
                
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
                
                // Se chegou aqui, funcionou! Logar qual configuração funcionou
                if ($tentativa_port !== $port || $tentativa_enc !== $encryption) {
                    error_log("Email enviado com sucesso usando configuração alternativa: {$tentativa['desc']}");
                }
                
                return true;
                
            } catch (Exception $e) {
                $ultimo_erro = $e->getMessage();
                error_log("Tentativa falhou ({$tentativa['desc']}): " . $ultimo_erro);
                // Continuar para próxima tentativa
            }
        }
        
        // Se chegou aqui, todas as tentativas falharam
        error_log("Todas as tentativas de envio de e-mail falharam. Último erro: " . $ultimo_erro);
        return false;
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
