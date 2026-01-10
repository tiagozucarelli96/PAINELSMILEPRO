<?php
// push_helper.php — Helper para envio de Web Push Notifications
require_once __DIR__ . '/../conexao.php';

class PushHelper {
    private $pdo;
    private $vapidPublicKey;
    private $vapidPrivateKey;
    private $useLibrary;
    
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
        
        // Verificar se biblioteca web-push está disponível
        $this->useLibrary = class_exists('Minishlink\WebPush\WebPush');
        
        if ($this->useLibrary && file_exists(__DIR__ . '/../../vendor/autoload.php')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
        }
    }
    
    /**
     * Enviar push para um usuário
     */
    public function enviarPush($usuario_id, $titulo = 'Portal Grupo Smile', $mensagem = 'Você tem novas atualizações no sistema.', $data = []) {
        try {
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
            
        } catch (Exception $e) {
            error_log("Erro ao enviar push: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Enviar push para endpoint específico
     */
    private function enviarParaEndpoint($subscription, $titulo, $mensagem, $data) {
        if (empty($this->vapidPublicKey) || empty($this->vapidPrivateKey)) {
            return ['success' => false, 'error' => 'Chaves VAPID não configuradas'];
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
            // @phpstan-ignore-next-line
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => 'mailto:painelsmilenotifica@smileeventos.com.br',
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
            $result = $webPush->sendOneNotification($pushSubscription, $payload);
            
            // Processar resultado
            if ($result->isSuccess()) {
                return ['success' => true];
            } else {
                $error = $result->getReason();
                return ['success' => false, 'error' => $error];
            }
            
        } catch (Exception $e) {
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
        } catch (Exception $e) {
            error_log("Erro ao desativar subscription: " . $e->getMessage());
        }
    }
}
