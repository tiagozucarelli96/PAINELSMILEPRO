<?php
// google_calendar_helper.php — Helper para integração Google Calendar

require_once __DIR__ . '/../conexao.php';

class GoogleCalendarHelper {
    private const APP_TIMEZONE = 'America/Sao_Paulo';
    private $pdo;
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $webhook_expiration_is_timestamp = null;
    private static $schema_checked = false;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->client_id = getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? null);
        $this->client_secret = getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? null);
        $this->redirect_uri = getenv('GOOGLE_REDIRECT_URL') ?: ($_ENV['GOOGLE_REDIRECT_URL'] ?? 'https://painelsmilepro-production.up.railway.app/google/callback');
        $this->ensureSchema();
    }

    /**
     * Garante colunas mínimas para sincronização automática.
     */
    private function ensureSchema(): void {
        if (self::$schema_checked) {
            return;
        }

        self::$schema_checked = true;

        if (!$this->pdo instanceof PDO) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE google_calendar_config ADD COLUMN IF NOT EXISTS webhook_channel_id VARCHAR(255)");
            $this->pdo->exec("ALTER TABLE google_calendar_config ADD COLUMN IF NOT EXISTS webhook_resource_id VARCHAR(255)");
            $this->pdo->exec("ALTER TABLE google_calendar_config ADD COLUMN IF NOT EXISTS webhook_expiration TIMESTAMP");
            $this->pdo->exec("ALTER TABLE google_calendar_config ADD COLUMN IF NOT EXISTS precisa_sincronizar BOOLEAN DEFAULT FALSE");
            $this->pdo->exec("UPDATE google_calendar_config SET precisa_sincronizar = FALSE WHERE precisa_sincronizar IS NULL");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_google_calendar_config_webhook ON google_calendar_config(webhook_resource_id)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_google_calendar_config_sync ON google_calendar_config(ativo, precisa_sincronizar)");
        } catch (Exception $e) {
            error_log('[GOOGLE_CALENDAR_SCHEMA] Falha ao validar schema: ' . $e->getMessage());
        }
    }

    /**
     * Normaliza URL de webhook para o endpoint real do projeto.
     */
    public static function normalizeWebhookUrl(string $url): string {
        if (strpos($url, '/google/webhook') !== false) {
            return str_replace('/google/webhook', '/google_calendar_webhook.php', $url);
        }
        return $url;
    }

    /**
     * Verifica se o token possui escopo suficiente para registrar webhooks.
     */
    public static function hasCalendarWriteScope(?string $scope): bool {
        $scope = trim((string)$scope);
        if ($scope === '') {
            return false;
        }

        $tokens = preg_split('/[\s,]+/', $scope) ?: [];
        $allowed = [
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/calendar.events'
        ];

        foreach ($tokens as $token) {
            if (in_array($token, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Converte valores de expiração (timestamp ou ms) para epoch em segundos.
     */
    public static function parseExpirationToUnix($value): int {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            $number = (int)$value;
            if ($number <= 0) {
                return 0;
            }
            if ($number > 9999999999) {
                return (int)floor($number / 1000);
            }
            return $number;
        }

        $timestamp = strtotime((string)$value);
        return $timestamp !== false ? $timestamp : 0;
    }

    /**
     * Detecta tipo da coluna webhook_expiration (timestamp vs numérico).
     */
    private function isWebhookExpirationTimestampColumn(): bool {
        if ($this->webhook_expiration_is_timestamp !== null) {
            return (bool)$this->webhook_expiration_is_timestamp;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT data_type
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'google_calendar_config'
                  AND column_name = 'webhook_expiration'
                LIMIT 1
            ");
            $stmt->execute();
            $data_type = strtolower((string)$stmt->fetchColumn());
            $this->webhook_expiration_is_timestamp = (
                strpos($data_type, 'timestamp') !== false ||
                strpos($data_type, 'date') !== false
            );
        } catch (Exception $e) {
            $this->webhook_expiration_is_timestamp = true;
        }

        return (bool)$this->webhook_expiration_is_timestamp;
    }

    /**
     * Converte datetime do Google para horário local da aplicação.
     */
    private function normalizeGoogleDateTimeToAppTimezone(?string $value): ?string {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($raw);
        } catch (Exception $e) {
            $ts = strtotime($raw);
            if ($ts === false) {
                return null;
            }
            $dt = new DateTimeImmutable('@' . $ts);
        }

        return $dt
            ->setTimezone(new DateTimeZone(self::APP_TIMEZONE))
            ->format('Y-m-d H:i:s');
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
            // Usar scope completo para permitir webhooks (watch requer calendar, não apenas readonly)
            'scope' => 'https://www.googleapis.com/auth/calendar',
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
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error']['message']) 
                ? $error_data['error']['message'] 
                : "Erro desconhecido";
            
            error_log("[GOOGLE_CALENDAR] Erro HTTP $http_code na URL: $url");
            error_log("[GOOGLE_CALENDAR] Resposta: $response");
            
            throw new Exception("Erro na API do Google (HTTP $http_code): $error_message");
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[GOOGLE_CALENDAR] Erro ao decodificar JSON: " . json_last_error_msg());
            error_log("[GOOGLE_CALENDAR] Resposta recebida: " . substr($response, 0, 500));
            throw new Exception("Resposta inválida da API do Google");
        }
        
        return $decoded;
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
        // Montar janela em horário local e converter para UTC (formato exigido pela API do Google).
        $app_tz = new DateTimeZone(self::APP_TIMEZONE);
        $utc_tz = new DateTimeZone('UTC');
        $time_min = (new DateTimeImmutable('today 00:00:00', $app_tz))
            ->setTimezone($utc_tz)
            ->format('Y-m-d\TH:i:s\Z');
        $time_max = (new DateTimeImmutable('+' . (int)$dias_futuro . ' days 23:59:59', $app_tz))
            ->setTimezone($utc_tz)
            ->format('Y-m-d\TH:i:s\Z');
        $sync_started_at = (new DateTimeImmutable('now', $app_tz))->format('Y-m-d H:i:s');
        
        error_log("[GOOGLE_CALENDAR_SYNC] Iniciando sincronização do calendário: $calendar_id");
        error_log("[GOOGLE_CALENDAR_SYNC] Período: $time_min até $time_max");
        
        $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events";
        $url .= "?timeMin=" . urlencode($time_min);
        $url .= "&timeMax=" . urlencode($time_max);
        $url .= "&singleEvents=true";
        $url .= "&orderBy=startTime";
        $url .= "&maxResults=2500";
        
        try {
            $response = $this->makeApiRequest($url);
            
            error_log("[GOOGLE_CALENDAR_SYNC] Resposta recebida. Total de itens: " . (isset($response['items']) ? count($response['items']) : 0));
            
            if (!isset($response['items']) || !is_array($response['items'])) {
                error_log("[GOOGLE_CALENDAR_SYNC] Nenhum evento encontrado na resposta");
                $response['items'] = [];
            }
            
            if (empty($response['items'])) {
                error_log("[GOOGLE_CALENDAR_SYNC] Array de eventos vazio - nenhum evento no período especificado");
            }
        } catch (Exception $e) {
            error_log("[GOOGLE_CALENDAR_SYNC] Erro ao buscar eventos: " . $e->getMessage());
            throw $e;
        }
        
        $importados = 0;
        $atualizados = 0;
        $pulados = 0;
        $removidos = 0;
        $total_processados = 0;
        
        error_log("[GOOGLE_CALENDAR_SYNC] Processando " . count($response['items']) . " eventos");
        
        foreach ($response['items'] as $event) {
            $total_processados++;
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
                // Evento com hora específica - converter considerando timezone
                $inicio = $this->normalizeGoogleDateTimeToAppTimezone($event['start']['dateTime']);
            } elseif (isset($event['start']['date'])) {
                // Evento de dia todo - usar a data exata (sem conversão de timezone)
                // O Google retorna apenas a data (YYYY-MM-DD) sem timezone para eventos de dia todo
                $date_str = $event['start']['date'];
                $inicio = $date_str . ' 00:00:00';
            }
            
            // Processar data de fim
            $fim = null;
            if (isset($event['end']['dateTime'])) {
                // Evento com hora específica - converter considerando timezone
                $fim = $this->normalizeGoogleDateTimeToAppTimezone($event['end']['dateTime']);
            } elseif (isset($event['end']['date'])) {
                // Evento de dia todo - o Google retorna a data do DIA SEGUINTE como fim
                // Ex: evento de 26/01 tem end.date = 27/01 (exclusivo)
                // Precisamos usar 26/01 23:59:59 como fim
                $date_str = $event['end']['date'];
                // Subtrair 1 dia porque o Google usa data exclusiva
                $end_date = date('Y-m-d', strtotime($date_str . ' -1 day'));
                $fim = $end_date . ' 23:59:59';
            }
            
            if (!$inicio || !$fim) {
                $pulados++;
                error_log("[GOOGLE_CALENDAR_SYNC] Evento pulado (sem data válida): " . ($titulo ?? 'Sem título'));
                continue; // Pular eventos sem data válida
            }
            
            // Verificar se o evento está no período correto (filtro adicional)
            $inicio_timestamp = strtotime($inicio);
            $time_min_timestamp = strtotime($time_min);
            $time_max_timestamp = strtotime($time_max);
            
            if ($inicio_timestamp < $time_min_timestamp || $inicio_timestamp > $time_max_timestamp) {
                $pulados++;
                continue; // Pular eventos fora do período
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

        // Limpar eventos obsoletos no período sincronizado:
        // se um evento deixou de existir no Google, removemos do cache local.
        $period_start = date('Y-m-d', strtotime($time_min));
        $period_end = date('Y-m-d', strtotime($time_max));
        $stmtCleanup = $this->pdo->prepare("
            DELETE FROM google_calendar_eventos
            WHERE google_calendar_id = :calendar_id
              AND (
                  DATE(inicio) BETWEEN DATE(:start_date) AND DATE(:end_date)
                  OR DATE(fim) BETWEEN DATE(:start_date) AND DATE(:end_date)
                  OR (DATE(inicio) <= DATE(:start_date) AND DATE(fim) >= DATE(:end_date))
              )
              AND atualizado_em < :sync_started_at
        ");
        $stmtCleanup->execute([
            ':calendar_id' => $calendar_id,
            ':start_date' => $period_start,
            ':end_date' => $period_end,
            ':sync_started_at' => $sync_started_at
        ]);
        $removidos = (int)$stmtCleanup->rowCount();
        if ($removidos > 0) {
            error_log("[GOOGLE_CALENDAR_SYNC] Eventos obsoletos removidos: $removidos");
        }
        
        // Registrar log de sincronização (sempre, mesmo se não houver eventos)
        $stmt = $this->pdo->prepare("
            INSERT INTO google_calendar_sync_logs (tipo, total_eventos, detalhes)
            VALUES ('importado', :total, :detalhes)
        ");
        $stmt->execute([
            ':total' => $importados + $atualizados,
            ':detalhes' => json_encode([
                'importados' => $importados,
                'atualizados' => $atualizados,
                'pulados' => $pulados,
                'removidos' => $removidos,
                'total_processado' => $total_processados,
                'calendar_id' => $calendar_id,
                'time_min' => $time_min,
                'time_max' => $time_max
            ])
        ]);
        
        // Atualizar última sincronização na config (sempre, mesmo se não houver eventos)
        $stmt = $this->pdo->prepare("
            UPDATE google_calendar_config 
            SET ultima_sincronizacao = NOW(), atualizado_em = NOW()
            WHERE google_calendar_id = :calendar_id
        ");
        $stmt->execute([':calendar_id' => $calendar_id]);
        
        $rows_updated = $stmt->rowCount();
        error_log("[GOOGLE_CALENDAR_SYNC] Sincronização concluída. Importados: $importados, Atualizados: $atualizados, Pulados: $pulados, Removidos: $removidos, Total processado: $total_processados");
        error_log("[GOOGLE_CALENDAR_SYNC] Config atualizada: $rows_updated linha(s)");
        
        return [
            'importados' => $importados,
            'atualizados' => $atualizados,
            'total' => $importados + $atualizados,
            'pulados' => $pulados,
            'removidos' => $removidos,
            'total_encontrado' => count($response['items']),
            'total_processado' => $total_processados
        ];
    }
    
    /**
     * Atualizar última sincronização
     */
    private function updateLastSync($calendar_id) {
        $stmt = $this->pdo->prepare("
            UPDATE google_calendar_config 
            SET ultima_sincronizacao = NOW(), atualizado_em = NOW()
            WHERE google_calendar_id = :calendar_id
        ");
        $stmt->execute([':calendar_id' => $calendar_id]);
        error_log("[GOOGLE_CALENDAR_SYNC] Última sincronização atualizada para calendário: $calendar_id");
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
    
    /**
     * Registrar webhook para notificações de mudanças
     */
    public function registerWebhook($calendar_id, $webhook_url) {
        $access_token = $this->getValidAccessToken();
        $webhook_url = self::normalizeWebhookUrl((string)$webhook_url);
        
        error_log("[GOOGLE_CALENDAR_WEBHOOK] Tentando registrar webhook para: $calendar_id");
        error_log("[GOOGLE_CALENDAR_WEBHOOK] URL do webhook: $webhook_url");
        
        // Primeiro, criar um canal (watch) no Google Calendar
        $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calendar_id) . "/events/watch";
        
        // Google exige id com caracteres [A-Za-z0-9\\-_\\+/=] (sem ponto).
        $channel_id = 'channel_' . bin2hex(random_bytes(16));
        // O token deve ser o calendar_id para identificar qual calendário está sendo notificado
        $token = $calendar_id;
        $data = [
            'id' => $channel_id,
            'type' => 'web_hook',
            'address' => $webhook_url,
            'token' => $token // Usar calendar_id como token para identificar o calendário
        ];
        
        error_log("[GOOGLE_CALENDAR_WEBHOOK] Dados do canal: " . json_encode($data));
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        error_log("[GOOGLE_CALENDAR_WEBHOOK] HTTP Code: $http_code");
        error_log("[GOOGLE_CALENDAR_WEBHOOK] Response: " . substr($response, 0, 500));
        
        if ($curl_error) {
            throw new Exception("Erro cURL ao registrar webhook: $curl_error");
        }
        
        if ($http_code !== 200) {
            $error_data = json_decode($response, true);
            $error_message = isset($error_data['error']['message']) 
                ? $error_data['error']['message'] 
                : "Erro desconhecido";
            $error_code = isset($error_data['error']['code']) ? $error_data['error']['code'] : 'N/A';
            error_log("[GOOGLE_CALENDAR_WEBHOOK] Erro completo: " . json_encode($error_data));
            throw new Exception("Erro ao registrar webhook (HTTP $http_code, Code: $error_code): $error_message");
        }
        
        $result = json_decode($response, true);
        
        if (!$result || !isset($result['resourceId'])) {
            error_log("[GOOGLE_CALENDAR_WEBHOOK] Resposta inválida: " . $response);
            throw new Exception("Resposta inválida do Google ao registrar webhook");
        }
        
        error_log("[GOOGLE_CALENDAR_WEBHOOK] Webhook registrado com sucesso. Resource ID: " . $result['resourceId']);
        
        // Converter expiração para o tipo suportado pela coluna (timestamp ou numérico)
        $expiration_ms = (isset($result['expiration']) && is_numeric($result['expiration']))
            ? (int)$result['expiration']
            : null;
        $expiration_value = null;
        if (!empty($expiration_ms)) {
            $expiration_value = $this->isWebhookExpirationTimestampColumn()
                ? date('Y-m-d H:i:s', (int)floor($expiration_ms / 1000))
                : $expiration_ms;
        }
        
        // Salvar informações do webhook no banco
        $stmt = $this->pdo->prepare("
            UPDATE google_calendar_config 
            SET webhook_channel_id = :channel_id,
                webhook_resource_id = :resource_id, 
                webhook_expiration = :expiration,
                atualizado_em = NOW()
            WHERE google_calendar_id = :calendar_id
        ");
        $stmt->execute([
            ':channel_id' => $channel_id,
            ':resource_id' => $result['resourceId'] ?? null,
            ':expiration' => $expiration_value,
            ':calendar_id' => $calendar_id
        ]);
        
        $rows_updated = $stmt->rowCount();
        error_log("[GOOGLE_CALENDAR_WEBHOOK] Config atualizada: $rows_updated linha(s)");
        
        return $result;
    }
    
    /**
     * Parar webhook (stop watch)
     */
    public function stopWebhook($resource_id, $channel_id = null) {
        $access_token = $this->getValidAccessToken();
        
        $url = "https://www.googleapis.com/calendar/v3/channels/stop";
        
        $channel_id = $channel_id ?: $resource_id;
        $data = [
            'id' => $channel_id,
            'resourceId' => $resource_id
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 204 && $http_code !== 200) {
            error_log("[GOOGLE_CALENDAR_WEBHOOK] Erro ao parar webhook (HTTP $http_code): " . substr($response, 0, 200));
            // Não lançar exceção - pode ser que o webhook já tenha expirado
        }
        
        return true;
    }
}
