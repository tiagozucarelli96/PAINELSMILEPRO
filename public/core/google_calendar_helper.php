<?php
// google_calendar_helper.php — Helper para integração Google Calendar

require_once __DIR__ . '/../conexao.php';

class GoogleCalendarHelper {
    private $pdo;
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->client_id = getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? null);
        $this->client_secret = getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? null);
        $this->redirect_uri = getenv('GOOGLE_REDIRECT_URL') ?: ($_ENV['GOOGLE_REDIRECT_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google/callback');
    }
    
    /**
     * Gerar URL de autorização OAuth
     */
    public function getAuthorizationUrl($state = null) {
        if (!$this->client_id) {
            throw new Exception('GOOGLE_CLIENT_ID não configurado');
        }
        
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
        
        if ($state) {
            $params['state'] = $state;
        }
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * Trocar code por access_token e refresh_token
     */
    public function exchangeCodeForTokens($code) {
        if (!$this->client_id || !$this->client_secret) {
            throw new Exception('Credenciais Google não configuradas');
        }
        
        $token_url = 'https://oauth2.googleapis.com/token';
        
        $data = [
            'code' => $code,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri' => $this->redirect_uri,
            'grant_type' => 'authorization_code'
        ];
        
        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Erro ao trocar code por tokens: $error");
        }
        
        if ($http_code !== 200) {
            error_log("[GOOGLE_CALENDAR] Erro HTTP $http_code: $response");
            throw new Exception("Erro ao trocar code por tokens. HTTP $http_code");
        }
        
        $tokens = json_decode($response, true);
        
        if (!$tokens || !isset($tokens['access_token'])) {
            throw new Exception('Resposta inválida do Google OAuth');
        }
        
        return $tokens;
    }
    
    /**
     * Salvar tokens no banco
     */
    public function saveTokens($tokens) {
        $access_token = $tokens['access_token'];
        $refresh_token = $tokens['refresh_token'] ?? null;
        $expires_in = $tokens['expires_in'] ?? 3600;
        $scope = $tokens['scope'] ?? null;
        $token_type = $tokens['token_type'] ?? 'Bearer';
        
        $expires_at = null;
        if ($expires_in) {
            $expires_at = date('Y-m-d H:i:s', time() + $expires_in);
        }
        
        // Deletar tokens antigos (só mantém um registro)
        $this->pdo->exec("DELETE FROM google_calendar_tokens");
        
        $stmt = $this->pdo->prepare("
            INSERT INTO google_calendar_tokens (access_token, refresh_token, token_type, expires_at, scope)
            VALUES (:access_token, :refresh_token, :token_type, :expires_at, :scope)
        ");
        
        $stmt->execute([
            ':access_token' => $access_token,
            ':refresh_token' => $refresh_token,
            ':token_type' => $token_type,
            ':expires_at' => $expires_at,
            ':scope' => $scope
        ]);
        
        return true;
    }
    
    /**
     * Obter access_token válido (renova se necessário)
     */
    public function getValidAccessToken() {
        $stmt = $this->pdo->query("SELECT * FROM google_calendar_tokens ORDER BY id DESC LIMIT 1");
        $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$token_data) {
            throw new Exception('Tokens não encontrados. Conecte o Google Calendar primeiro.');
        }
        
        // Verificar se o token expirou
        $expires_at = $token_data['expires_at'] ? strtotime($token_data['expires_at']) : null;
        $now = time();
        
        // Se expirou ou vai expirar em menos de 5 minutos, renovar
        if ($expires_at && $expires_at <= ($now + 300)) {
            if (!$token_data['refresh_token']) {
                throw new Exception('Token expirado e refresh_token não disponível. Reconecte o Google Calendar.');
            }
            
            return $this->refreshAccessToken($token_data['refresh_token']);
        }
        
        return $token_data['access_token'];
    }
    
    /**
     * Renovar access_token usando refresh_token
     */
    private function refreshAccessToken($refresh_token) {
        if (!$this->client_id || !$this->client_secret) {
            throw new Exception('Credenciais Google não configuradas');
        }
        
        $token_url = 'https://oauth2.googleapis.com/token';
        
        $data = [
            'refresh_token' => $refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'refresh_token'
        ];
        
        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            error_log("[GOOGLE_CALENDAR] Erro ao renovar token: HTTP $http_code - $response");
            throw new Exception('Erro ao renovar access_token');
        }
        
        $tokens = json_decode($response, true);
        
        if (!$tokens || !isset($tokens['access_token'])) {
            throw new Exception('Resposta inválida ao renovar token');
        }
        
        // Atualizar no banco (preservar refresh_token)
        $expires_in = $tokens['expires_in'] ?? 3600;
        $expires_at = date('Y-m-d H:i:s', time() + $expires_in);
        
        $stmt = $this->pdo->prepare("
            UPDATE google_calendar_tokens 
            SET access_token = :access_token, expires_at = :expires_at, atualizado_em = NOW()
            WHERE refresh_token = :refresh_token
        ");
        
        $stmt->execute([
            ':access_token' => $tokens['access_token'],
            ':expires_at' => $expires_at,
            ':refresh_token' => $refresh_token
        ]);
        
        return $tokens['access_token'];
    }
    
    /**
     * Fazer requisição autenticada à API do Google
     */
    private function makeApiRequest($url, $method = 'GET', $data = null) {
        $access_token = $this->getValidAccessToken();
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Erro na requisição: $error");
        }
        
        if ($http_code !== 200) {
            error_log("[GOOGLE_CALENDAR] Erro HTTP $http_code: $response");
            throw new Exception("Erro na API do Google. HTTP $http_code");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Listar calendários do usuário
     */
    public function listCalendars() {
        $url = 'https://www.googleapis.com/calendar/v3/users/me/calendarList';
        return $this->makeApiRequest($url);
    }
    
    /**
     * Sincronizar eventos de um calendário
     */
    public function syncCalendarEvents($calendar_id, $dias_futuro = 180) {
        $time_min = date('Y-m-d\T00:00:00\Z');
        $time_max = date('Y-m-d\T23:59:59\Z', strtotime("+$dias_futuro days"));
        
        $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events";
        $url .= "?timeMin=" . urlencode($time_min);
        $url .= "&timeMax=" . urlencode($time_max);
        $url .= "&singleEvents=true";
        $url .= "&orderBy=startTime";
        $url .= "&maxResults=2500";
        
        $response = $this->makeApiRequest($url);
        
        if (!isset($response['items'])) {
            return ['importados' => 0, 'atualizados' => 0];
        }
        
        $importados = 0;
        $atualizados = 0;
        
        foreach ($response['items'] as $event) {
            $google_event_id = $event['id'];
            $titulo = $event['summary'] ?? 'Sem título';
            $descricao = $event['description'] ?? null;
            $localizacao = $event['location'] ?? null;
            $organizador_email = isset($event['organizer']['email']) ? $event['organizer']['email'] : null;
            $status = $event['status'] ?? 'confirmed';
            $html_link = $event['htmlLink'] ?? null;
            
            // Processar data de início
            $inicio = null;
            if (isset($event['start']['dateTime'])) {
                $inicio = date('Y-m-d H:i:s', strtotime($event['start']['dateTime']));
            } elseif (isset($event['start']['date'])) {
                $inicio = date('Y-m-d 00:00:00', strtotime($event['start']['date']));
            }
            
            // Processar data de fim
            $fim = null;
            if (isset($event['end']['dateTime'])) {
                $fim = date('Y-m-d H:i:s', strtotime($event['end']['dateTime']));
            } elseif (isset($event['end']['date'])) {
                $fim = date('Y-m-d 23:59:59', strtotime($event['end']['date']));
            }
            
            if (!$inicio || !$fim) {
                continue; // Pular eventos sem data válida
            }
            
            // Verificar se já existe
            $stmt = $this->pdo->prepare("
                SELECT id FROM google_calendar_eventos 
                WHERE google_calendar_id = :calendar_id AND google_event_id = :event_id
            ");
            $stmt->execute([
                ':calendar_id' => $calendar_id,
                ':event_id' => $google_event_id
            ]);
            $existe = $stmt->fetch();
            
            if ($existe) {
                // Atualizar
                $stmt = $this->pdo->prepare("
                    UPDATE google_calendar_eventos 
                    SET titulo = :titulo, descricao = :descricao, inicio = :inicio, fim = :fim,
                        localizacao = :localizacao, organizador_email = :organizador_email,
                        status = :status, html_link = :html_link, atualizado_em = NOW()
                    WHERE google_calendar_id = :calendar_id AND google_event_id = :event_id
                ");
                $stmt->execute([
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':inicio' => $inicio,
                    ':fim' => $fim,
                    ':localizacao' => $localizacao,
                    ':organizador_email' => $organizador_email,
                    ':status' => $status,
                    ':html_link' => $html_link,
                    ':calendar_id' => $calendar_id,
                    ':event_id' => $google_event_id
                ]);
                $atualizados++;
            } else {
                // Inserir
                $stmt = $this->pdo->prepare("
                    INSERT INTO google_calendar_eventos 
                    (google_calendar_id, google_event_id, titulo, descricao, inicio, fim, 
                     localizacao, organizador_email, status, html_link)
                    VALUES 
                    (:calendar_id, :event_id, :titulo, :descricao, :inicio, :fim,
                     :localizacao, :organizador_email, :status, :html_link)
                ");
                $stmt->execute([
                    ':calendar_id' => $calendar_id,
                    ':event_id' => $google_event_id,
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':inicio' => $inicio,
                    ':fim' => $fim,
                    ':localizacao' => $localizacao,
                    ':organizador_email' => $organizador_email,
                    ':status' => $status,
                    ':html_link' => $html_link
                ]);
                $importados++;
            }
        }
        
        // Registrar log de sincronização
        $stmt = $this->pdo->prepare("
            INSERT INTO google_calendar_sync_logs (tipo, total_eventos, detalhes)
            VALUES ('importado', :total, :detalhes)
        ");
        $stmt->execute([
            ':total' => $importados + $atualizados,
            ':detalhes' => json_encode([
                'importados' => $importados,
                'atualizados' => $atualizados,
                'calendar_id' => $calendar_id,
                'time_min' => $time_min,
                'time_max' => $time_max
            ])
        ]);
        
        // Atualizar última sincronização na config
        $stmt = $this->pdo->prepare("
            UPDATE google_calendar_config 
            SET ultima_sincronizacao = NOW(), atualizado_em = NOW()
            WHERE google_calendar_id = :calendar_id
        ");
        $stmt->execute([':calendar_id' => $calendar_id]);
        
        return [
            'importados' => $importados,
            'atualizados' => $atualizados,
            'total' => $importados + $atualizados
        ];
    }
    
    /**
     * Verificar se está conectado
     */
    public function isConnected() {
        try {
            $stmt = $this->pdo->query("SELECT id FROM google_calendar_tokens LIMIT 1");
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Obter configuração atual
     */
    public function getConfig() {
        $stmt = $this->pdo->query("SELECT * FROM google_calendar_config WHERE ativo = TRUE LIMIT 1");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
