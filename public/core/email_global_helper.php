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

    private function getEnvVar($name) {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }
        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }
        if (function_exists('apache_getenv')) {
            $apache_value = apache_getenv($name);
            if ($apache_value !== false && $apache_value !== '') {
                return $apache_value;
            }
        }
        return null;
    }

    private function resendDisponivel() {
        return class_exists('Resend', false) || class_exists('\Resend\Resend', false);
    }

    private function criarClienteResend($api_key) {
        if (class_exists('Resend', false)) {
            return Resend::client($api_key);
        }
        if (class_exists('\Resend\Resend', false)) {
            return \Resend\Resend::client($api_key);
        }
        return null;
    }
    
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
        
        // Verificar se Resend está configurado (Railway pode usar diferentes métodos)
        // Tentar múltiplas formas de ler a variável de ambiente
        $resend_api_key = null;
        $fonte_detectada = null;
        
        // Método 1: getenv (mais comum)
        $env_getenv = getenv('RESEND_API_KEY');
        if ($env_getenv && !empty($env_getenv)) {
            $resend_api_key = $env_getenv;
            $fonte_detectada = 'getenv';
        }
        
        // Método 2: $_ENV (alguns servidores)
        if (!$resend_api_key && isset($_ENV['RESEND_API_KEY']) && !empty($_ENV['RESEND_API_KEY'])) {
            $resend_api_key = $_ENV['RESEND_API_KEY'];
            $fonte_detectada = '_ENV';
        }
        
        // Método 3: $_SERVER (alguns servidores web)
        if (!$resend_api_key && isset($_SERVER['RESEND_API_KEY']) && !empty($_SERVER['RESEND_API_KEY'])) {
            $resend_api_key = $_SERVER['RESEND_API_KEY'];
            $fonte_detectada = '_SERVER';
        }
        
        // Método 4: apache_getenv (se Apache)
        if (!$resend_api_key && function_exists('apache_getenv')) {
            $env_apache = apache_getenv('RESEND_API_KEY');
            if ($env_apache && !empty($env_apache)) {
                $resend_api_key = $env_apache;
                $fonte_detectada = 'apache_getenv';
            }
        }
        
        if (!$resend_api_key) {
            // Debug: verificar todas as fontes possíveis
            $debug_env = [
                'getenv' => getenv('RESEND_API_KEY') ? 'SIM (' . strlen(getenv('RESEND_API_KEY')) . ' chars)' : 'NÃO',
                '_ENV' => isset($_ENV['RESEND_API_KEY']) ? 'SIM (' . strlen($_ENV['RESEND_API_KEY']) . ' chars)' : 'NÃO',
                '_SERVER' => isset($_SERVER['RESEND_API_KEY']) ? 'SIM (' . strlen($_SERVER['RESEND_API_KEY']) . ' chars)' : 'NÃO',
                'apache_getenv' => function_exists('apache_getenv') ? (apache_getenv('RESEND_API_KEY') ? 'SIM' : 'NÃO') : 'N/A'
            ];
            error_log("[EMAIL] ❌ ERRO: RESEND_API_KEY não configurada.");
            error_log("[EMAIL] Debug - Verificações: " . json_encode($debug_env, JSON_PRETTY_PRINT));
            error_log("[EMAIL] Configure no Railway: Variables → RESEND_API_KEY");
            error_log("[EMAIL] Após configurar, faça RESTART ou REDEPLOY no Railway");
            return false;
        }
        
        error_log("[EMAIL] ✅ RESEND_API_KEY encontrada via $fonte_detectada! (tamanho: " . strlen($resend_api_key) . " caracteres)");
        error_log("[EMAIL] Preview: " . substr($resend_api_key, 0, 10) . "..." . substr($resend_api_key, -5));
        
        // Verificar se classe Resend está disponível
        if (!$this->resendDisponivel()) {
            // Tentar carregar manualmente se autoload não funcionou
            $resend_autoload_paths = [
                __DIR__ . '/../../vendor/resend/resend-php/src/Resend.php',
                __DIR__ . '/../vendor/resend/resend-php/src/Resend.php',
                __DIR__ . '/vendor/resend/resend-php/src/Resend.php'
            ];
            
            $resend_carregado = false;
            foreach ($resend_autoload_paths as $resend_path) {
                if (file_exists($resend_path)) {
                    require_once $resend_path;
                    error_log("[EMAIL] ⚠️ Carregando Resend manualmente de: $resend_path");
                    $resend_carregado = true;
                    break;
                }
            }
            
            // Verificar novamente após tentativa manual
            if (!$this->resendDisponivel()) {
                error_log("[EMAIL] ❌ ERRO: Resend SDK não disponível após tentativa manual.");
                error_log("[EMAIL] Caminhos tentados: " . implode(', ', $resend_autoload_paths));
                error_log("[EMAIL] Execute no Railway: composer dump-autoload --optimize");
                return false;
            }
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
            if (!$this->resendDisponivel()) {
                // Tentar carregar manualmente se autoload não funcionou
                $resend_autoload_paths = [
                    __DIR__ . '/../../vendor/resend/resend-php/src/Resend.php',
                    __DIR__ . '/../vendor/resend/resend-php/src/Resend.php',
                    __DIR__ . '/vendor/resend/resend-php/src/Resend.php'
                ];
                
                $resend_carregado = false;
                foreach ($resend_autoload_paths as $resend_path) {
                    if (file_exists($resend_path)) {
                        require_once $resend_path;
                        error_log("[EMAIL] ⚠️ Carregando Resend manualmente de: $resend_path");
                        $resend_carregado = true;
                        break;
                    }
                }
                
                // Verificar novamente após tentativa manual
                if (!$this->resendDisponivel()) {
                    error_log("[EMAIL] ❌ ERRO: Classe Resend\Resend não encontrada após tentativa manual.");
                    error_log("[EMAIL] Caminhos tentados: " . implode(', ', $resend_autoload_paths));
                    error_log("[EMAIL] Execute no Railway: composer dump-autoload --optimize");
                    return false;
                }
            }

            $resend = $this->criarClienteResend($api_key);
            if (!$resend) {
                error_log("[EMAIL] ❌ ERRO: Resend SDK disponível mas cliente não pôde ser criado.");
                return false;
            }
            
            $email_remetente = $this->getEnvVar('RESEND_FROM')
                ?: $this->getEnvVar('RESEND_FROM_EMAIL')
                ?: ($this->config['email_remetente'] ?? 'painelsmilenotifica@smileeventos.com.br');

            if (!filter_var($email_remetente, FILTER_VALIDATE_EMAIL)) {
                error_log("[EMAIL] ❌ Remetente inválido para Resend: $email_remetente");
                return false;
            }
            
            // Resend retorna um objeto Email com propriedade id
            $result = $resend->emails->send([
                'from' => $email_remetente,
                'to' => $para,
                'subject' => $assunto,
                'html' => $eh_html ? $corpo : nl2br(htmlspecialchars($corpo)),
            ]);
            
            // Verificar se tem ID (indica sucesso) - SDK pode retornar objeto ou array
            $result_id = null;
            if (is_object($result)) {
                if (method_exists($result, 'getAttribute')) {
                    $result_id = $result->getAttribute('id');
                } elseif (method_exists($result, 'toArray')) {
                    $result_array = $result->toArray();
                    $result_id = $result_array['id'] ?? null;
                } elseif (property_exists($result, 'id')) {
                    $result_id = $result->id;
                }
            } elseif (is_array($result)) {
                $result_id = $result['id'] ?? null;
            }

            if (!empty($result_id)) {
                error_log("[EMAIL] ✅ Resend: E-mail enviado com sucesso! ID: " . $result_id);
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
    
    // Envio por Resend apenas.
}
