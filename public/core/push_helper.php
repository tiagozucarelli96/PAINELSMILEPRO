<?php
// push_helper.php — Helper para envio de Web Push Notifications
require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/push_schema.php';

class PushHelper {
    private $pdo;
    private $vapidPublicKey;
    private $vapidPrivateKey;
    private $vapidSubject;
    private $vapidValidationChecked;
    private $vapidValidationError;
    private $missingMathExtensionWarned;
    private $useLibrary;
    private $schemaReady;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'] ?? null;
        
        // Carregar config_env.php se disponível
        if (file_exists(__DIR__ . '/../config_env.php')) {
            require_once __DIR__ . '/../config_env.php';
        }
        
        // Chaves VAPID - tentar múltiplas fontes
        if (defined('VAPID_PUBLIC_KEY') && !empty(VAPID_PUBLIC_KEY)) {
            $this->vapidPublicKey = VAPID_PUBLIC_KEY;
        } else {
            $this->vapidPublicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? getenv('VAPID_PUBLIC_KEY') ?: '';
        }
        
        if (defined('VAPID_PRIVATE_KEY') && !empty(VAPID_PRIVATE_KEY)) {
            $this->vapidPrivateKey = VAPID_PRIVATE_KEY;
        } else {
            $this->vapidPrivateKey = $_ENV['VAPID_PRIVATE_KEY'] ?? getenv('VAPID_PRIVATE_KEY') ?: '';
        }

        $this->vapidSubject = $_ENV['VAPID_SUBJECT'] ?? getenv('VAPID_SUBJECT') ?: 'mailto:painelsmilenotifica@smileeventos.com.br';
        $this->vapidPublicKey = $this->normalizarChaveVapid($this->vapidPublicKey);
        $this->vapidPrivateKey = $this->normalizarChaveVapid($this->vapidPrivateKey);
        $this->vapidValidationChecked = false;
        $this->vapidValidationError = '';
        $this->missingMathExtensionWarned = false;
        $this->schemaReady = false;
        
        // Carregar autoload do composer se disponível
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }
        
        // Verificar se biblioteca web-push está disponível
        $this->useLibrary = class_exists('Minishlink\WebPush\WebPush');
    }
    
    /**
     * Enviar push para um usuário
     */
    public function enviarPush($usuario_id, $titulo = 'Portal Grupo Smile', $mensagem = 'Você tem novas atualizações no sistema.', $data = []) {
        try {
            $this->ensurePushSchema();

            // Buscar subscriptions ativas do usuário
            $stmt = $this->pdo->prepare("
                SELECT endpoint, chave_publica, chave_autenticacao
                FROM sistema_notificacoes_navegador
                WHERE usuario_id = :usuario_id
                AND consentimento_permitido = TRUE
                AND ativo = TRUE
            ");
            $stmt->execute([':usuario_id' => $usuario_id]);
            $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($subscriptions)) {
                return ['success' => false, 'error' => 'Nenhuma subscription ativa'];
            }
            
            $enviados = 0;
            $erros = [];
            
            foreach ($subscriptions as $sub) {
                $resultado = $this->enviarParaEndpoint($sub, $titulo, $mensagem, $data);
                if ($resultado['success']) {
                    $enviados++;
                } else {
                    $erros[] = $resultado['error'];
                    // Se endpoint inválido, desativar
                    if (strpos($resultado['error'], '410') !== false || strpos($resultado['error'], '404') !== false) {
                        $this->desativarSubscription($sub['endpoint']);
                    }
                }
            }
            
            return [
                'success' => $enviados > 0,
                'enviados' => $enviados,
                'total' => count($subscriptions),
                'erros' => $erros
            ];
            
        } catch (Throwable $e) {
            error_log("Erro ao enviar push: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function ensurePushSchema() {
        if ($this->schemaReady || !($this->pdo instanceof PDO)) {
            return;
        }

        try {
            if (function_exists('push_ensure_schema')) {
                push_ensure_schema($this->pdo);
            }
        } catch (Throwable $e) {
            error_log("[PUSH_HELPER] Falha ao garantir schema de push: " . $e->getMessage());
        }

        $this->schemaReady = true;
    }
    
    /**
     * Enviar push para endpoint específico
     */
    private function enviarParaEndpoint($subscription, $titulo, $mensagem, $data) {
        $vapidError = $this->validarConfiguracaoVapid();
        if ($vapidError !== '') {
            return ['success' => false, 'error' => $vapidError];
        }
        
        // Usar biblioteca se disponível
        if ($this->useLibrary) {
            return $this->enviarComBiblioteca($subscription, $titulo, $mensagem, $data);
        }
        
        // Fallback: implementação manual (será implementada se necessário)
        return ['success' => false, 'error' => 'Biblioteca web-push não disponível. Execute: composer require minishlink/web-push'];
    }
    
    /**
     * Enviar usando biblioteca minishlink/web-push
     * @suppress PhanUndeclaredClass
     */
    private function enviarComBiblioteca($subscription, $titulo, $mensagem, $data) {
        try {
            $result = $this->executarComFiltroAvisoMathExtension(function () use ($subscription, $titulo, $mensagem, $data) {
                // @phpstan-ignore-next-line
                $webPush = new \Minishlink\WebPush\WebPush([
                    'VAPID' => [
                        'subject' => $this->vapidSubject,
                        'publicKey' => $this->vapidPublicKey,
                        'privateKey' => $this->vapidPrivateKey,
                    ],
                ]);
                
                // @phpstan-ignore-next-line
                $pushSubscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $subscription['endpoint'],
                    'keys' => [
                        'p256dh' => $subscription['chave_publica'],
                        'auth' => $subscription['chave_autenticacao'],
                    ],
                ]);
                
                // Payload
                $payload = json_encode([
                    'title' => $titulo,
                    'body' => $mensagem,
                    'data' => $data,
                    'icon' => '/favicon.ico',
                    'badge' => '/favicon.ico'
                ]);
                
                // Enviar notificação
                return $webPush->sendOneNotification($pushSubscription, $payload);
            });
            
            // Processar resultado
            if ($result->isSuccess()) {
                return ['success' => true];
            } else {
                $error = $result->getReason();
                return ['success' => false, 'error' => $error];
            }
            
        } catch (Throwable $e) {
            error_log("Erro ao enviar push com biblioteca: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Desativar subscription inválida
     */
    private function desativarSubscription($endpoint) {
        try {
            $stmt = $this->pdo->prepare("UPDATE sistema_notificacoes_navegador SET ativo = FALSE WHERE endpoint = :endpoint");
            $stmt->execute([':endpoint' => $endpoint]);
        } catch (Throwable $e) {
            error_log("Erro ao desativar subscription: " . $e->getMessage());
        }
    }

    private function normalizarChaveVapid($value) {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        $value = trim($value, "\"'");
        $value = preg_replace('/\s+/', '', $value);

        return is_string($value) ? $value : '';
    }

    private function validarConfiguracaoVapid() {
        if ($this->vapidValidationChecked) {
            return $this->vapidValidationError;
        }

        $this->vapidValidationChecked = true;

        if (empty($this->vapidPublicKey) || empty($this->vapidPrivateKey)) {
            $this->vapidValidationError = 'Chaves VAPID não configuradas';
            return $this->vapidValidationError;
        }

        if (!$this->useLibrary) {
            return $this->vapidValidationError;
        }

        try {
            // Valida formato e consistencia do par de chaves com uma assinatura real.
            $validado = \Minishlink\WebPush\VAPID::validate([
                'subject' => $this->vapidSubject,
                'publicKey' => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ]);

            set_error_handler(function ($severity, $message, $file, $line) {
                throw new ErrorException($message, 0, $severity, $file, $line);
            });

            try {
                \Minishlink\WebPush\VAPID::getVapidHeaders(
                    'https://fcm.googleapis.com',
                    $this->vapidSubject,
                    $validado['publicKey'],
                    $validado['privateKey'],
                    \Minishlink\WebPush\ContentEncoding::aes128gcm
                );
            } finally {
                restore_error_handler();
            }
        } catch (Throwable $e) {
            error_log("Falha na validacao VAPID: " . $e->getMessage());
            $this->vapidValidationError = 'Chaves VAPID invalidas ou incompatíveis. Gere um novo par e configure VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY.';
        }

        return $this->vapidValidationError;
    }

    private function executarComFiltroAvisoMathExtension(callable $callback) {
        set_error_handler(function ($severity, $message) {
            if (($severity === E_USER_NOTICE || $severity === E_NOTICE)
                && is_string($message)
                && strpos($message, 'GMP or BCMath extension') !== false
            ) {
                if (!$this->missingMathExtensionWarned) {
                    error_log('[WebPush] ' . $message);
                    $this->missingMathExtensionWarned = true;
                }
                return true;
            }

            return false;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
