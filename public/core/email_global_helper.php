<?php
// email_global_helper.php — Helper para envio de e-mails usando configuração global (ETAPA 12)
require_once __DIR__ . '/../conexao.php';

// Carregar autoload do Composer apenas uma vez (usar flag global para evitar duplicação)
if (!isset($GLOBALS['autoload_carregado'])) {
    $autoload_path = __DIR__ . '/../../vendor/autoload.php';
    if (file_exists($autoload_path)) {
        require_once $autoload_path;
        $GLOBALS['autoload_carregado'] = true;
    }
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
     * Enviar e-mail usando APENAS Resend (API)
     */
    public function enviarEmail($para, $assunto, $corpo, $eh_html = true) {
        error_log("[EMAIL] ====== INÍCIO DO ENVIO DE E-MAIL ======");
        error_log("[EMAIL] Destinatário: $para");
        error_log("[EMAIL] Assunto: $assunto");
        
        // Verificar se Resend está configurado
        $resend_api_key = getenv('RESEND_API_KEY') ?: ($_ENV['RESEND_API_KEY'] ?? null);
        
        if (!$resend_api_key) {
            error_log("[EMAIL] ❌ ERRO: RESEND_API_KEY não configurada. Configure no Railway: Variables → RESEND_API_KEY");
            return false;
        }
        
        if (!class_exists('\Resend\Resend', false)) {
            error_log("[EMAIL] ❌ ERRO: Resend SDK não disponível. Execute: composer install");
            return false;
        }
        
        // Usar APENAS Resend
        error_log("[EMAIL] Usando Resend (API) para envio");
        $resultado = $this->enviarComResend($para, $assunto, $corpo, $eh_html, $resend_api_key);
        error_log("[EMAIL] ====== FIM DO ENVIO DE E-MAIL (resultado: " . ($resultado ? 'SUCESSO' : 'FALHA') . ") ======");
        return $resultado;
    }
    
    /**
     * Enviar usando Resend API
     */
    private function enviarComResend($para, $assunto, $corpo, $eh_html, $api_key) {
        try {
            // Garantir que autoload foi carregado (sem duplicar)
            if (!isset($GLOBALS['autoload_carregado'])) {
                $autoload_path = __DIR__ . '/../../vendor/autoload.php';
                if (file_exists($autoload_path)) {
                    require_once $autoload_path;
                    $GLOBALS['autoload_carregado'] = true;
                }
            }
            
            // Verificar se classe Resend existe antes de usar
            if (!class_exists('\Resend\Resend', false)) {
                error_log("[EMAIL] ❌ ERRO: Classe Resend\Resend não encontrada. Execute: composer install");
                return false;
            }
            
            $resend = \Resend\Resend::client($api_key);
            
            $email_remetente = $this->config['email_remetente'] ?? 'painelsmilenotifica@smileeventos.com.br';
            
            // Resend retorna um objeto Email com propriedade id
            $result = $resend->emails->send([
                'from' => $email_remetente,
                'to' => $para,
                'subject' => $assunto,
                'html' => $eh_html ? $corpo : nl2br(htmlspecialchars($corpo)),
            ]);
            
            // Verificar se tem ID (indica sucesso)
            if (isset($result->id) && !empty($result->id)) {
                error_log("[EMAIL] ✅ Resend: E-mail enviado com sucesso! ID: " . $result->id);
                return true;
            } else {
                error_log("[EMAIL] ❌ Resend: Resposta inesperada (sem ID): " . json_encode($result));
                return false;
            }
            
        } catch (\Resend\Exceptions\ErrorException $e) {
            error_log("[EMAIL] ❌ Erro ao enviar e-mail com Resend: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            error_log("[EMAIL] ❌ Erro geral ao enviar e-mail com Resend: " . $e->getMessage());
            return false;
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
        
        foreach ($tentativas as $index => $tentativa) {
            try {
                // Log inicial da tentativa
                error_log("[EMAIL] Tentativa " . ($index + 1) . "/" . count($tentativas) . ": {$tentativa['desc']}");
                error_log("[EMAIL] Host: {$this->config['smtp_host']}, Porta: {$tentativa['port']}, Encriptação: {$tentativa['encryption']}");
                
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
                
                $inicio_envio = microtime(true);
                $mail->send();
                $tempo_envio = round((microtime(true) - $inicio_envio) * 1000, 2);
                
                // Se chegou aqui, funcionou! Logar qual configuração funcionou
                if ($tentativa_port !== $port || $tentativa_enc !== $encryption) {
                    error_log("[EMAIL] ✅ SUCESSO usando configuração alternativa: {$tentativa['desc']} (tempo: {$tempo_envio}ms)");
                } else {
                    error_log("[EMAIL] ✅ SUCESSO usando configuração salva: {$tentativa['desc']} (tempo: {$tempo_envio}ms)");
                }
                error_log("[EMAIL] E-mail enviado para: $para");
                
                return true;
                
            } catch (Exception $e) {
                $ultimo_erro = $e->getMessage();
                $erro_resumido = substr($ultimo_erro, 0, 200); // Limitar tamanho do log
                error_log("[EMAIL] ❌ FALHA na tentativa ({$tentativa['desc']}): $erro_resumido");
                // Continuar para próxima tentativa
            }
        }
        
        // Se chegou aqui, todas as tentativas falharam
        error_log("[EMAIL] ❌❌❌ TODAS AS TENTATIVAS FALHARAM!");
        error_log("[EMAIL] Último erro completo: " . substr($ultimo_erro, 0, 500));
        error_log("[EMAIL] Configurações tentadas: " . count($tentativas));
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
