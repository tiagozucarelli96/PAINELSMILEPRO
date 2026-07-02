<?php
// notification_dispatcher.php — Dispatcher único de notificações (interna + push + e-mail)
require_once __DIR__ . '/../conexao.php';

class NotificationDispatcher {
    private $pdo;
    private $schemaEnsured = false;
    private $internalColumns = null;
    private $pushHelper = null;
    private $pushHelperReady = false;
    private $emailHelper = null;
    private $emailHelperReady = false;

    public function __construct($pdo = null) {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
        } else {
            $this->pdo = $GLOBALS['pdo'] ?? null;
        }
    }

    public function ensureInternalSchema() {
        if ($this->schemaEnsured || !($this->pdo instanceof PDO)) {
            return;
        }

        if ($this->isSchemaMarkerFresh()) {
            $this->schemaEnsured = true;
            return;
        }

        $this->safeExec("
            CREATE TABLE IF NOT EXISTS demandas_notificacoes (
                id BIGSERIAL PRIMARY KEY,
                usuario_id BIGINT REFERENCES usuarios(id) ON DELETE CASCADE,
                tipo VARCHAR(50) NOT NULL,
                referencia_id INT,
                titulo VARCHAR(180),
                mensagem TEXT NOT NULL,
                url_destino TEXT,
                lida BOOLEAN DEFAULT FALSE,
                criada_em TIMESTAMPTZ DEFAULT NOW()
            )
        ");
        $this->safeExec("ALTER TABLE demandas_notificacoes ADD COLUMN IF NOT EXISTS referencia_id INT");
        $this->safeExec("ALTER TABLE demandas_notificacoes ADD COLUMN IF NOT EXISTS titulo VARCHAR(180)");
        $this->safeExec("ALTER TABLE demandas_notificacoes ADD COLUMN IF NOT EXISTS url_destino TEXT");
        $this->safeExec("CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_usuario ON demandas_notificacoes(usuario_id, lida)");

        $this->schemaEnsured = true;
        $this->internalColumns = null;
        $dateColumn = $this->getInternalDateColumn();
        if ($dateColumn !== null) {
            $this->safeExec("CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_{$dateColumn}_desc ON demandas_notificacoes({$dateColumn} DESC)");
        }
        $this->touchSchemaMarker();
    }

    private function isSchemaMarkerFresh(): bool
    {
        $ttl = max(300, (int)(painel_env('NOTIFICATION_SCHEMA_TTL_SECONDS', '3600') ?? '3600'));
        $mtime = @filemtime('/tmp/demandas_notificacoes_schema_ready');
        if ($mtime === false) {
            return false;
        }

        return (time() - $mtime) < $ttl;
    }

    private function touchSchemaMarker(): void
    {
        @touch('/tmp/demandas_notificacoes_schema_ready');
    }

    public function dispatch(array $recipients, array $payload = [], array $channels = []): array {
        $normalizedRecipients = $this->normalizeRecipients($recipients);

        $result = [
            'total_destinatarios' => count($normalizedRecipients),
            'enviados_interno' => 0,
            'enviados_push' => 0,
            'enviados_email' => 0,
            'enviados_whatsapp' => 0,
            'falhas_interno' => 0,
            'falhas_push' => 0,
            'falhas_email' => 0,
            'falhas_whatsapp' => 0,
            'emails_sem_endereco' => 0,
            'whatsapps_sem_numero' => 0,
        ];

        if (empty($normalizedRecipients)) {
            return $result;
        }

        $sendInternal = !empty($channels['internal']);
        $sendPush = !empty($channels['push']);
        $sendEmail = !empty($channels['email']);
        $sendWhatsapp = !empty($channels['whatsapp']);
        if (!$sendInternal && !$sendPush && !$sendEmail && !$sendWhatsapp) {
            return $result;
        }

        $tipo = trim((string)($payload['tipo'] ?? 'sistema'));
        if ($tipo === '') {
            $tipo = 'sistema';
        }

        $titulo = trim((string)($payload['titulo'] ?? 'Notificação'));
        if ($titulo === '') {
            $titulo = 'Notificação';
        }

        $mensagem = trim((string)($payload['mensagem'] ?? ''));
        if ($mensagem === '') {
            $mensagem = $titulo;
        }

        $urlDestino = trim((string)($payload['url_destino'] ?? ''));
        $referenciaId = isset($payload['referencia_id']) ? (int)$payload['referencia_id'] : 0;
        if ($referenciaId <= 0) {
            $referenciaId = null;
        }

        $pushTitulo = trim((string)($payload['push_titulo'] ?? $titulo));
        $pushMensagem = trim((string)($payload['push_mensagem'] ?? $mensagem));
        $pushData = is_array($payload['push_data'] ?? null) ? $payload['push_data'] : [];
        if (empty($pushData['url'])) {
            $pushData['url'] = $urlDestino !== '' ? $urlDestino : 'index.php?page=dashboard';
        }
        if (empty($pushData['tipo'])) {
            $pushData['tipo'] = $tipo;
        }
        if (!isset($pushData['referencia_id']) && $referenciaId !== null) {
            $pushData['referencia_id'] = $referenciaId;
        }

        $emailAssunto = trim((string)($payload['email_assunto'] ?? $titulo));
        if ($emailAssunto === '') {
            $emailAssunto = $titulo;
        }
        $emailHtml = (string)($payload['email_html'] ?? $this->buildDefaultEmailBody($titulo, $mensagem, $urlDestino));
        $whatsappMensagem = trim((string)($payload['whatsapp_mensagem'] ?? ''));
        if ($whatsappMensagem === '') {
            $whatsappMensagem = $this->buildDefaultWhatsappBody($titulo, $mensagem, $urlDestino);
        }
        $whatsappProvider = $this->getWhatsappProvider($payload);
        $whatsappSessionKey = trim((string)($payload['whatsapp_session_key'] ?? ''));

        if ($sendEmail) {
            $this->hydrateRecipientEmails($normalizedRecipients);
        }
        if ($sendWhatsapp) {
            $this->hydrateRecipientPhones($normalizedRecipients);
            if ($whatsappProvider === 'smilechat' && $whatsappSessionKey === '') {
                $whatsappSessionKey = $this->getWhatsappSessionKey();
            }
        }

        $pushHelper = $sendPush ? $this->getPushHelper() : null;
        $emailHelper = $sendEmail ? $this->getEmailHelper() : null;

        foreach ($normalizedRecipients as $recipient) {
            $userId = (int)$recipient['id'];

            if ($sendInternal) {
                $okInternal = $this->insertInternalNotification($userId, $tipo, $titulo, $mensagem, $urlDestino, $referenciaId);
                if ($okInternal) {
                    $result['enviados_interno']++;
                } else {
                    $result['falhas_interno']++;
                }
            }

            if ($sendPush) {
                if ($pushHelper) {
                    $retPush = $pushHelper->enviarPush($userId, $pushTitulo, $pushMensagem, $pushData);
                    if (!empty($retPush['success'])) {
                        $result['enviados_push']++;
                    } else {
                        $result['falhas_push']++;
                    }
                } else {
                    $result['falhas_push']++;
                }
            }

            if ($sendEmail) {
                $email = trim((string)($recipient['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result['emails_sem_endereco']++;
                    $result['falhas_email']++;
                } elseif ($emailHelper) {
                    $okEmail = $emailHelper->enviarEmail($email, $emailAssunto, $emailHtml, true);
                    if ($okEmail) {
                        $result['enviados_email']++;
                    } else {
                        $result['falhas_email']++;
                    }
                } else {
                    $result['falhas_email']++;
                }
            }

            if ($sendWhatsapp) {
                $phoneE164 = $this->normalizePhoneE164((string)($recipient['phone'] ?? ''));
                if ($phoneE164 === '') {
                    $result['whatsapps_sem_numero']++;
                    $result['falhas_whatsapp']++;
                } elseif ($whatsappProvider === 'smilechat' && $whatsappSessionKey === '') {
                    $result['falhas_whatsapp']++;
                } else {
                    $okWhatsapp = $this->sendWhatsappProviderMessage(
                        $whatsappProvider,
                        $whatsappSessionKey,
                        $phoneE164,
                        $whatsappMensagem,
                        (string)($recipient['name'] ?? $phoneE164)
                    );
                    if ($okWhatsapp) {
                        $result['enviados_whatsapp']++;
                    } else {
                        $result['falhas_whatsapp']++;
                    }
                }
            }
        }

        return $result;
    }

    public function sendWhatsappDirect(string $phone, string $message, string $contactName = '', array $payload = []): bool {
        $phoneE164 = $this->normalizePhoneE164($phone);
        $message = trim($message);
        if ($phoneE164 === '' || $message === '') {
            return false;
        }

        $provider = $this->getWhatsappProvider($payload);
        $sessionKey = trim((string)($payload['whatsapp_session_key'] ?? ''));
        if ($provider === 'smilechat' && $sessionKey === '') {
            $sessionKey = $this->getWhatsappSessionKey();
        }

        if ($provider === 'smilechat' && $sessionKey === '') {
            return false;
        }

        return $this->sendWhatsappProviderMessage(
            $provider,
            $sessionKey,
            $phoneE164,
            $message,
            $contactName !== '' ? $contactName : $phoneE164
        );
    }

    public function sendWhatsappMediaDirect(
        string $phone,
        string $mediaUrl,
        string $filename = '',
        string $caption = '',
        string $mimeType = '',
        array $payload = []
    ): bool {
        $phoneE164 = $this->normalizePhoneE164($phone);
        $mediaUrl = trim($mediaUrl);
        if ($phoneE164 === '' || $mediaUrl === '') {
            return false;
        }

        $provider = $this->getWhatsappProvider($payload);
        if ($provider !== 'smclick') {
            error_log('[NOTIFICATION_DISPATCHER] Envio de mídia WhatsApp disponível apenas via SMClick.');
            return false;
        }

        return $this->sendSmclickWhatsappMedia($phoneE164, $mediaUrl, $filename, $caption, $mimeType);
    }

    public function dispatchWhatsappMedia(array $recipients, array $media, array $payload = []): array {
        $normalizedRecipients = $this->normalizeRecipients($recipients);
        $result = [
            'total_destinatarios' => count($normalizedRecipients),
            'enviados_whatsapp_media' => 0,
            'falhas_whatsapp_media' => 0,
            'whatsapps_sem_numero' => 0,
        ];

        if (empty($normalizedRecipients)) {
            return $result;
        }

        $this->hydrateRecipientPhones($normalizedRecipients);
        $mediaUrl = trim((string)($media['url'] ?? $media['media_url'] ?? ''));
        $filename = trim((string)($media['filename'] ?? $media['nome_original'] ?? ''));
        $caption = trim((string)($media['caption'] ?? $media['message'] ?? ''));
        $mimeType = trim((string)($media['mime_type'] ?? ''));

        foreach ($normalizedRecipients as $recipient) {
            $phone = (string)($recipient['phone'] ?? '');
            if ($this->normalizePhoneE164($phone) === '') {
                $result['whatsapps_sem_numero']++;
                $result['falhas_whatsapp_media']++;
                continue;
            }

            $ok = $this->sendWhatsappMediaDirect($phone, $mediaUrl, $filename, $caption, $mimeType, $payload);
            if ($ok) {
                $result['enviados_whatsapp_media']++;
            } else {
                $result['falhas_whatsapp_media']++;
            }
        }

        return $result;
    }

    private function normalizeRecipients(array $recipients): array {
        $buffer = [];
        foreach ($recipients as $recipient) {
            if (is_array($recipient)) {
                $id = (int)($recipient['id'] ?? $recipient['usuario_id'] ?? 0);
                $email = trim((string)($recipient['email'] ?? ''));
                $phone = trim((string)($recipient['phone'] ?? $recipient['celular'] ?? $recipient['telefone'] ?? ''));
                $name = trim((string)($recipient['name'] ?? $recipient['nome'] ?? ''));
            } else {
                $id = (int)$recipient;
                $email = '';
                $phone = '';
                $name = '';
            }

            if ($id <= 0) {
                continue;
            }

            $buffer[$id] = [
                'id' => $id,
                'email' => $email,
                'phone' => $phone,
                'name' => $name,
            ];
        }

        return array_values($buffer);
    }

    private function hydrateRecipientEmails(array &$recipients): void {
        if (!($this->pdo instanceof PDO) || empty($recipients)) {
            return;
        }

        $missingIds = [];
        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            if ($email === '') {
                $missingIds[] = (int)$recipient['id'];
            }
        }

        $missingIds = array_values(array_unique(array_filter($missingIds)));
        if (empty($missingIds)) {
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
            $stmt = $this->pdo->prepare("SELECT id, email FROM usuarios WHERE id IN ({$placeholders})");
            $stmt->execute($missingIds);
            $emailMap = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $emailMap[(int)$row['id']] = trim((string)($row['email'] ?? ''));
            }

            foreach ($recipients as &$recipient) {
                if (trim((string)($recipient['email'] ?? '')) !== '') {
                    continue;
                }
                $id = (int)$recipient['id'];
                if (!empty($emailMap[$id])) {
                    $recipient['email'] = $emailMap[$id];
                }
            }
            unset($recipient);
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Falha ao buscar e-mails de destinatários: " . $e->getMessage());
        }
    }

    private function hydrateRecipientPhones(array &$recipients): void {
        if (!($this->pdo instanceof PDO) || empty($recipients)) {
            return;
        }

        $missingIds = [];
        foreach ($recipients as $recipient) {
            $phone = trim((string)($recipient['phone'] ?? ''));
            if ($phone === '') {
                $missingIds[] = (int)$recipient['id'];
            }
        }

        $missingIds = array_values(array_unique(array_filter($missingIds)));
        if (empty($missingIds)) {
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
            $stmt = $this->pdo->prepare("
                SELECT id, nome, COALESCE(NULLIF(TRIM(celular), ''), NULLIF(TRIM(telefone), '')) AS phone
                FROM usuarios
                WHERE id IN ({$placeholders})
            ");
            $stmt->execute($missingIds);
            $phoneMap = [];
            $nameMap = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['id'];
                $phoneMap[$id] = trim((string)($row['phone'] ?? ''));
                $nameMap[$id] = trim((string)($row['nome'] ?? ''));
            }

            foreach ($recipients as &$recipient) {
                $id = (int)$recipient['id'];
                if (trim((string)($recipient['phone'] ?? '')) === '' && !empty($phoneMap[$id])) {
                    $recipient['phone'] = $phoneMap[$id];
                }
                if (trim((string)($recipient['name'] ?? '')) === '' && !empty($nameMap[$id])) {
                    $recipient['name'] = $nameMap[$id];
                }
            }
            unset($recipient);
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Falha ao buscar celulares de destinatários: " . $e->getMessage());
        }
    }

    private function normalizePhoneE164(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        if (strlen($digits) < 12) {
            return '';
        }

        return '+' . $digits;
    }

    private function normalizePhoneDigits(string $phone): string {
        return preg_replace('/\D+/', '', $this->normalizePhoneE164($phone)) ?? '';
    }

    private function getWhatsappProvider(array $payload): string {
        $provider = strtolower(trim((string)($payload['whatsapp_provider'] ?? '')));
        if ($provider === '') {
            $provider = strtolower(trim((string)(
                painel_env('NOTIFICATION_WHATSAPP_PROVIDER', '') ?: painel_env('WHATSAPP_PROVIDER', 'smclick')
            )));
        }

        if (in_array($provider, ['smilechat', 'smile_chat', 'gateway'], true)) {
            return 'smilechat';
        }

        return 'smclick';
    }

    private function sendWhatsappProviderMessage(string $provider, string $sessionKey, string $phoneE164, string $body, string $contactName): bool {
        if ($provider === 'smilechat') {
            return $this->sendWhatsappMessage($sessionKey, $phoneE164, $body, $contactName);
        }

        return $this->sendSmclickWhatsappMessage($phoneE164, $body);
    }

    private function getWhatsappSessionKey(): string {
        $configured = trim((string)(painel_env('DEMANDAS_WHATSAPP_SESSION_KEY', '') ?: painel_env('NOTIFICATION_WHATSAPP_SESSION_KEY', '')));
        if ($configured !== '') {
            return $configured;
        }

        $default = 'smile-teste';
        if ($this->whatsappInboxIsConnected($default)) {
            return $default;
        }

        if (!($this->pdo instanceof PDO)) {
            return $default;
        }

        try {
            $stmt = $this->pdo->query("SELECT session_key FROM wa_inboxes WHERE status = 'connected' ORDER BY id LIMIT 1");
            $sessionKey = trim((string)($stmt->fetchColumn() ?: ''));
            return $sessionKey !== '' ? $sessionKey : $default;
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Falha ao buscar inbox WhatsApp conectado: " . $e->getMessage());
            return $default;
        }
    }

    private function whatsappInboxIsConnected(string $sessionKey): bool {
        if (!($this->pdo instanceof PDO) || $sessionKey === '') {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM wa_inboxes WHERE session_key = :session_key AND status = 'connected' LIMIT 1");
            $stmt->execute([':session_key' => $sessionKey]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function sendWhatsappMessage(string $sessionKey, string $phoneE164, string $body, string $contactName): bool {
        $baseUrl = rtrim((string)(painel_env('WHATSAPP_GATEWAY_BASE_URL', 'http://127.0.0.1:8787') ?? 'http://127.0.0.1:8787'), '/');
        if ($baseUrl === '' || $sessionKey === '' || $phoneE164 === '' || trim($body) === '') {
            return false;
        }

        $url = $baseUrl . '/api/sessions/' . rawurlencode($sessionKey) . '/messages/send';
        $payload = json_encode([
            'phoneE164' => $phoneE164,
            'body' => $body,
            'contactName' => $contactName !== '' ? $contactName : $phoneE164,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return false;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
            ]);
            $response = curl_exec($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $ok = $statusCode >= 200 && $statusCode < 300;
            if (!$ok) {
                error_log("[NOTIFICATION_DISPATCHER] Falha WhatsApp {$phoneE164} via {$sessionKey}: HTTP {$statusCode} " . ($curlError !== '' ? $curlError : (is_string($response) ? $response : '')));
            }

            return $ok;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\nContent-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $statusCode = isset($matches[1]) ? (int)$matches[1] : 0;
        $ok = $statusCode >= 200 && $statusCode < 300;

        if (!$ok) {
            error_log("[NOTIFICATION_DISPATCHER] Falha WhatsApp {$phoneE164} via {$sessionKey}: HTTP {$statusCode} " . (is_string($response) ? $response : ''));
        }

        return $ok;
    }

    private function sendSmclickWhatsappMessage(string $phoneE164, string $body): bool {
        $apiKey = trim((string)(painel_env('SMCLICK_API_KEY', '') ?? ''));
        $instanceId = trim((string)(painel_env('SMCLICK_INSTANCE_ID', '') ?? ''));
        $baseUrl = rtrim((string)(painel_env('SMCLICK_API_BASE_URL', 'https://api.smclick.com.br') ?? 'https://api.smclick.com.br'), '/');
        $telephone = $this->normalizePhoneDigits($phoneE164);

        if ($apiKey === '' || $instanceId === '' || $baseUrl === '' || $telephone === '' || trim($body) === '') {
            error_log('[NOTIFICATION_DISPATCHER] SMClick WhatsApp nao configurado: verifique SMCLICK_API_KEY, SMCLICK_INSTANCE_ID e telefone do usuario.');
            return false;
        }

        $payload = json_encode([
            'instance' => $instanceId,
            'type' => 'text',
            'content' => [
                'telephone' => $telephone,
                'message' => $body,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return false;
        }

        $url = $baseUrl . '/instances/messages';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-API-KEY: ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
            ]);
            $response = curl_exec($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $ok = $statusCode >= 200 && $statusCode < 300;
            if (!$ok) {
                error_log("[NOTIFICATION_DISPATCHER] Falha SMClick WhatsApp {$telephone}: HTTP {$statusCode} " . ($curlError !== '' ? $curlError : (is_string($response) ? $response : '')));
            }

            return $ok;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\nContent-Type: application/json\r\nX-API-KEY: {$apiKey}\r\n",
                'content' => $payload,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $statusCode = isset($matches[1]) ? (int)$matches[1] : 0;
        $ok = $statusCode >= 200 && $statusCode < 300;

        if (!$ok) {
            error_log("[NOTIFICATION_DISPATCHER] Falha SMClick WhatsApp {$telephone}: HTTP {$statusCode} " . (is_string($response) ? $response : ''));
        }

        return $ok;
    }

    private function sendSmclickWhatsappMedia(string $phoneE164, string $mediaUrl, string $filename, string $caption, string $mimeType): bool {
        $apiKey = trim((string)(painel_env('SMCLICK_API_KEY', '') ?? ''));
        $instanceId = trim((string)(painel_env('SMCLICK_INSTANCE_ID', '') ?? ''));
        $baseUrl = rtrim((string)(painel_env('SMCLICK_API_BASE_URL', 'https://api.smclick.com.br') ?? 'https://api.smclick.com.br'), '/');
        $telephone = $this->normalizePhoneDigits($phoneE164);

        if ($apiKey === '' || $instanceId === '' || $baseUrl === '' || $telephone === '' || trim($mediaUrl) === '') {
            error_log('[NOTIFICATION_DISPATCHER] SMClick mídia WhatsApp nao configurada: verifique SMCLICK_API_KEY, SMCLICK_INSTANCE_ID, telefone e URL do anexo.');
            return false;
        }

        $media = $this->fetchRemoteMediaDataUri($mediaUrl, $mimeType);
        if (!$media) {
            return false;
        }

        $resolvedMime = (string)$media['mime_type'];
        $isImage = stripos($resolvedMime, 'image/') === 0;
        $filename = trim($filename);
        if ($filename === '') {
            $filename = $this->filenameFromUrl($mediaUrl, $isImage ? 'imagem' : 'arquivo');
        }

        $content = [
            'telephone' => $telephone,
            'base64' => (string)$media['data_uri'],
        ];
        $type = $isImage ? 'image' : 'file';
        if ($isImage) {
            $content['message'] = trim($caption);
        } else {
            $content['filename'] = $filename;
        }

        $payload = json_encode([
            'instance' => $instanceId,
            'type' => $type,
            'content' => $content,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return false;
        }

        $url = $baseUrl . '/instances/messages';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'X-API-KEY: ' . $apiKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
            ]);
            $response = curl_exec($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $ok = $statusCode >= 200 && $statusCode < 300;
            if (!$ok) {
                error_log("[NOTIFICATION_DISPATCHER] Falha SMClick mídia {$telephone}: HTTP {$statusCode} " . ($curlError !== '' ? $curlError : (is_string($response) ? $response : '')));
            }

            return $ok;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Accept: application/json\r\nContent-Type: application/json\r\nX-API-KEY: {$apiKey}\r\n",
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $matches);
        $statusCode = isset($matches[1]) ? (int)$matches[1] : 0;
        $ok = $statusCode >= 200 && $statusCode < 300;

        if (!$ok) {
            error_log("[NOTIFICATION_DISPATCHER] Falha SMClick mídia {$telephone}: HTTP {$statusCode} " . (is_string($response) ? $response : ''));
        }

        return $ok;
    }

    private function fetchRemoteMediaDataUri(string $mediaUrl, string $mimeHint = ''): ?array {
        $mediaUrl = trim($mediaUrl);
        if (!preg_match('#^https?://#i', $mediaUrl)) {
            error_log('[NOTIFICATION_DISPATCHER] URL de mídia inválida para SMClick: ' . $mediaUrl);
            return null;
        }

        $maxBytes = max(1024 * 1024, (int)(painel_env('SMCLICK_MAX_MEDIA_BYTES', '8388608') ?? '8388608'));
        $body = '';
        $contentType = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($mediaUrl);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'PainelSmilePro/1.0',
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$contentType): int {
                    if (stripos($header, 'Content-Type:') === 0) {
                        $contentType = trim(substr($header, strlen('Content-Type:')));
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);

            $success = curl_exec($ch);
            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($success === false || $statusCode < 200 || $statusCode >= 300 || $body === '') {
                error_log("[NOTIFICATION_DISPATCHER] Falha ao baixar mídia SMClick: HTTP {$statusCode} " . ($curlError !== '' ? $curlError : $mediaUrl));
                return null;
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'ignore_errors' => true,
                    'header' => "User-Agent: PainelSmilePro/1.0\r\n",
                ],
            ]);
            $body = (string)@file_get_contents($mediaUrl, false, $context, 0, $maxBytes + 1);
            if ($body === '' || strlen($body) > $maxBytes) {
                error_log('[NOTIFICATION_DISPATCHER] Falha ao baixar mídia SMClick ou arquivo acima do limite: ' . $mediaUrl);
                return null;
            }
            foreach (($http_response_header ?? []) as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = trim(substr($header, strlen('Content-Type:')));
                    break;
                }
            }
        }

        $mime = trim(explode(';', $contentType)[0]);
        if ($mime === '') {
            $mime = trim($mimeHint);
        }
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = $this->mimeFromUrl($mediaUrl) ?: ($mime !== '' ? $mime : 'application/octet-stream');
        }

        return [
            'mime_type' => $mime,
            'data_uri' => 'data:' . $mime . ';base64,' . base64_encode($body),
        ];
    }

    private function mimeFromUrl(string $url): string {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
        ];

        return $map[$ext] ?? '';
    }

    private function filenameFromUrl(string $url, string $fallback): string {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $name = trim(urldecode(basename($path)));
        return $name !== '' && $name !== '/' ? $name : $fallback;
    }

    private function insertInternalNotification(int $usuarioId, string $tipo, string $titulo, string $mensagem, string $urlDestino, ?int $referenciaId): bool {
        if (!($this->pdo instanceof PDO) || $usuarioId <= 0) {
            return false;
        }

        $this->ensureInternalSchema();
        $columns = $this->getInternalColumns();
        if (empty($columns)) {
            return false;
        }

        $fields = ['usuario_id', 'tipo', 'mensagem'];
        $values = [':usuario_id', ':tipo', ':mensagem'];
        $params = [
            ':usuario_id' => $usuarioId,
            ':tipo' => $tipo,
            ':mensagem' => $mensagem,
        ];

        if (!empty($columns['referencia_id'])) {
            $fields[] = 'referencia_id';
            $values[] = ':referencia_id';
            $params[':referencia_id'] = $referenciaId;
        }

        if (!empty($columns['titulo'])) {
            $fields[] = 'titulo';
            $values[] = ':titulo';
            $params[':titulo'] = $titulo;
        }

        if (!empty($columns['url_destino'])) {
            $fields[] = 'url_destino';
            $values[] = ':url_destino';
            $params[':url_destino'] = $urlDestino !== '' ? $urlDestino : null;
        }

        $sql = "INSERT INTO demandas_notificacoes (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return true;
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Falha ao inserir notificação interna: " . $e->getMessage());
            return false;
        }
    }

    private function getInternalColumns(): array {
        if ($this->internalColumns !== null) {
            return $this->internalColumns;
        }

        $this->internalColumns = [];
        if (!($this->pdo instanceof PDO)) {
            return $this->internalColumns;
        }

        try {
            $stmt = $this->pdo->query("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_name = 'demandas_notificacoes'
            ");
            foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $column) {
                $column = (string)$column;
                if ($column !== '') {
                    $this->internalColumns[$column] = true;
                }
            }
        } catch (Throwable $e) {
            $this->internalColumns = [];
        }

        return $this->internalColumns;
    }

    private function getInternalDateColumn(): ?string {
        $columns = $this->getInternalColumns();
        foreach (['criada_em', 'criado_em', 'created_at', 'data_notificacao'] as $column) {
            if (!empty($columns[$column])) {
                return $column;
            }
        }

        return null;
    }

    private function getPushHelper() {
        if ($this->pushHelperReady) {
            return $this->pushHelper;
        }

        $this->pushHelperReady = true;
        try {
            require_once __DIR__ . '/push_helper.php';
            $this->pushHelper = new PushHelper();
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Push indisponível: " . $e->getMessage());
            $this->pushHelper = null;
        }

        return $this->pushHelper;
    }

    private function getEmailHelper() {
        if ($this->emailHelperReady) {
            return $this->emailHelper;
        }

        $this->emailHelperReady = true;
        try {
            require_once __DIR__ . '/email_global_helper.php';
            $this->emailHelper = new EmailGlobalHelper();
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] E-mail indisponível: " . $e->getMessage());
            $this->emailHelper = null;
        }

        return $this->emailHelper;
    }

    private function safeExec($sql): void {
        if (!($this->pdo instanceof PDO)) {
            return;
        }

        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Falha ao executar SQL de schema: " . $e->getMessage());
        }
    }

    private function buildDefaultEmailBody(string $titulo, string $mensagem, string $urlDestino): string {
        $tituloHtml = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $mensagemHtml = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));
        $urlHtml = htmlspecialchars($urlDestino, ENT_QUOTES, 'UTF-8');

        $cta = '';
        if ($urlDestino !== '') {
            $cta = "
                <p style='margin-top:16px;'>
                    <a href='{$urlHtml}' style='display:inline-block;background:#1e3a8a;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;'>
                        Abrir no Painel
                    </a>
                </p>
            ";
        }

        return "
            <div style='font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:700px;margin:0 auto;'>
                <div style='background:#1e3a8a;color:#fff;padding:16px 18px;border-radius:10px 10px 0 0;'>
                    <h2 style='margin:0;font-size:20px;'>{$tituloHtml}</h2>
                </div>
                <div style='border:1px solid #dbe3ef;border-top:none;padding:16px 18px;border-radius:0 0 10px 10px;background:#fff;'>
                    <div style='font-size:15px;color:#334155;'>{$mensagemHtml}</div>
                    {$cta}
                </div>
            </div>
        ";
    }

    private function buildDefaultWhatsappBody(string $titulo, string $mensagem, string $urlDestino): string {
        $body = "*{$titulo}*\n\n{$mensagem}";
        if ($urlDestino !== '') {
            $body .= "\n\nAcesse: {$urlDestino}";
        }

        return $body;
    }
}
