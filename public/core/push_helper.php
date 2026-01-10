<?php
// push_helper.php — Helper para envio de Web Push Notifications
require_once __DIR__ . '/../conexao.php';

class PushHelper {
    private $pdo;
    private $vapidPublicKey;
    private $vapidPrivateKey;
    
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
        
        $endpoint = $subscription['endpoint'];
        $p256dh = base64_decode($subscription['chave_publica']);
        $auth = base64_decode($subscription['chave_autenticacao']);
        
        // Payload
        $payload = json_encode([
            'title' => $titulo,
            'body' => $mensagem,
            'data' => $data,
            'icon' => '/favicon.ico',
            'badge' => '/favicon.ico'
        ]);
        
        // Gerar VAPID JWT
        $jwt = $this->gerarVAPIDJWT($endpoint);
        
        // Headers
        $headers = [
            'Authorization: vapid t=' . $jwt . ', k=' . $this->vapidPublicKey,
            'Content-Type: application/octet-stream',
            'Content-Encoding: aesgcm',
            'TTL: 86400'
        ];
        
        // Criptografar payload (simplificado - usar biblioteca em produção)
        $encrypted = $this->criptografarPayload($payload, $p256dh, $auth);
        
        // Enviar via cURL
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $encrypted,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 201 || $httpCode === 204) {
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => "HTTP $httpCode: $response"];
        }
    }
    
    /**
     * Gerar JWT VAPID (simplificado - usar biblioteca em produção)
     */
    private function gerarVAPIDJWT($audience) {
        // Implementação simplificada - em produção usar biblioteca JWT
        $header = base64_encode(json_encode(['alg' => 'ES256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'aud' => parse_url($audience, PHP_URL_SCHEME) . '://' . parse_url($audience, PHP_URL_HOST),
            'exp' => time() + 43200,
            'sub' => 'mailto:painelsmilenotifica@smileeventos.com.br'
        ]));
        // Em produção, assinar com chave privada VAPID
        return $header . '.' . $payload . '.signature';
    }
    
    /**
     * Criptografar payload (simplificado - usar biblioteca em produção)
     */
    private function criptografarPayload($payload, $p256dh, $auth) {
        // Implementação simplificada - em produção usar biblioteca de criptografia
        return $payload; // Placeholder
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
