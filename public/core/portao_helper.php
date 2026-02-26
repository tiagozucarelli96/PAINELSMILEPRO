<?php
declare(strict_types=1);

if (!function_exists('portao_env')) {
    function portao_env(string $key, ?string $default = null): ?string {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string)$value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string)$_ENV[$key];
        }
        return $default;
    }
}

if (!function_exists('portao_provider_mode')) {
    function portao_provider_mode(): string {
        $mode = strtolower(trim((string)(portao_env('PORTAO_PROVIDER', 'simulado') ?? 'simulado')));
        if ($mode === '') {
            $mode = 'simulado';
        }
        return $mode;
    }
}

if (!function_exists('portao_auto_close_minutes')) {
    function portao_auto_close_minutes(): int {
        $minutes = (int)(portao_env('PORTAO_AUTO_CLOSE_MINUTES', '30') ?? '30');
        if ($minutes < 1) {
            $minutes = 30;
        }
        if ($minutes > 720) {
            $minutes = 720;
        }
        return $minutes;
    }
}

if (!function_exists('portao_user_context')) {
    function portao_user_context(): array {
        $userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
        $userName = trim((string)($_SESSION['nome'] ?? ''));
        if ($userName === '' && $userId > 0) {
            $userName = 'Usuario #' . $userId;
        }

        return [
            'usuario_id' => $userId > 0 ? $userId : null,
            'usuario_nome' => $userName !== '' ? $userName : 'Sistema',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ];
    }
}

if (!function_exists('portao_ensure_schema')) {
    function portao_ensure_schema(PDO $pdo): void {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS perm_portao BOOLEAN DEFAULT FALSE");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS portao_estado (
                id SMALLINT PRIMARY KEY,
                estado VARCHAR(20) NOT NULL DEFAULT 'desconhecido',
                ultima_acao VARCHAR(20),
                ultima_origem VARCHAR(20),
                ultima_resultado VARCHAR(20),
                ultima_usuario_id INTEGER,
                ultima_usuario_nome VARCHAR(255),
                ultima_detalhe TEXT,
                ultima_acao_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                auto_close_at TIMESTAMP NULL,
                atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            INSERT INTO portao_estado (id, estado, ultima_acao_em, atualizado_em)
            VALUES (1, 'desconhecido', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (id) DO NOTHING
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS portao_logs (
                id BIGSERIAL PRIMARY KEY,
                usuario_id INTEGER NULL,
                usuario_nome VARCHAR(255) NULL,
                acao VARCHAR(40) NOT NULL,
                origem VARCHAR(20) NOT NULL DEFAULT 'manual',
                resultado VARCHAR(20) NOT NULL,
                detalhe TEXT NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                payload_json JSONB NULL,
                criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_portao_logs_criado_em ON portao_logs (criado_em DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_portao_logs_usuario ON portao_logs (usuario_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_portao_logs_acao ON portao_logs (acao)");

        $initialized = true;
    }
}

if (!function_exists('portao_get_estado')) {
    function portao_get_estado(PDO $pdo): array {
        portao_ensure_schema($pdo);

        $st = $pdo->query("SELECT * FROM portao_estado WHERE id = 1 LIMIT 1");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            return [
                'id' => 1,
                'estado' => 'desconhecido',
                'ultima_acao' => null,
                'ultima_origem' => null,
                'ultima_resultado' => null,
                'ultima_usuario_id' => null,
                'ultima_usuario_nome' => null,
                'ultima_detalhe' => null,
                'ultima_acao_em' => null,
                'auto_close_at' => null,
                'atualizado_em' => null,
            ];
        }
        return $row;
    }
}

if (!function_exists('portao_set_estado')) {
    function portao_set_estado(PDO $pdo, array $data): void {
        portao_ensure_schema($pdo);

        $sql = "
            UPDATE portao_estado
            SET
                estado = :estado,
                ultima_acao = :ultima_acao,
                ultima_origem = :ultima_origem,
                ultima_resultado = :ultima_resultado,
                ultima_usuario_id = :ultima_usuario_id,
                ultima_usuario_nome = :ultima_usuario_nome,
                ultima_detalhe = :ultima_detalhe,
                ultima_acao_em = CURRENT_TIMESTAMP,
                auto_close_at = :auto_close_at,
                atualizado_em = CURRENT_TIMESTAMP
            WHERE id = 1
        ";

        $st = $pdo->prepare($sql);
        $st->execute([
            ':estado' => (string)($data['estado'] ?? 'desconhecido'),
            ':ultima_acao' => $data['ultima_acao'] ?? null,
            ':ultima_origem' => $data['ultima_origem'] ?? null,
            ':ultima_resultado' => $data['ultima_resultado'] ?? null,
            ':ultima_usuario_id' => $data['ultima_usuario_id'] ?? null,
            ':ultima_usuario_nome' => $data['ultima_usuario_nome'] ?? null,
            ':ultima_detalhe' => $data['ultima_detalhe'] ?? null,
            ':auto_close_at' => $data['auto_close_at'] ?? null,
        ]);
    }
}

if (!function_exists('portao_log')) {
    function portao_log(PDO $pdo, array $data): int {
        portao_ensure_schema($pdo);

        $payload = null;
        if (array_key_exists('payload', $data) && $data['payload'] !== null) {
            $encoded = json_encode($data['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $payload = ($encoded === false) ? null : $encoded;
        }

        $sql = "
            INSERT INTO portao_logs
                (usuario_id, usuario_nome, acao, origem, resultado, detalhe, ip, user_agent, payload_json, criado_em)
            VALUES
                (:usuario_id, :usuario_nome, :acao, :origem, :resultado, :detalhe, :ip, :user_agent, CAST(:payload AS JSONB), CURRENT_TIMESTAMP)
            RETURNING id
        ";

        try {
            $st = $pdo->prepare($sql);
            $st->execute([
                ':usuario_id' => $data['usuario_id'] ?? null,
                ':usuario_nome' => $data['usuario_nome'] ?? null,
                ':acao' => (string)($data['acao'] ?? 'evento'),
                ':origem' => (string)($data['origem'] ?? 'manual'),
                ':resultado' => (string)($data['resultado'] ?? 'info'),
                ':detalhe' => $data['detalhe'] ?? null,
                ':ip' => $data['ip'] ?? null,
                ':user_agent' => $data['user_agent'] ?? null,
                ':payload' => $payload,
            ]);
            $id = $st->fetchColumn();
            return (int)($id ?: 0);
        } catch (Throwable $e) {
            error_log('[PORTAO] Falha ao gravar log: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('portao_tuya_region_endpoint')) {
    function portao_tuya_region_endpoint(string $region): ?string {
        $normalized = strtolower(trim($region));
        if ($normalized === '') {
            return null;
        }

        $map = [
            'western america' => 'https://openapi.tuyaus.com',
            'america' => 'https://openapi.tuyaus.com',
            'us' => 'https://openapi.tuyaus.com',
            'tuyaus' => 'https://openapi.tuyaus.com',
            'eastern america' => 'https://openapi-ueaz.tuyaus.com',
            'ueaz' => 'https://openapi-ueaz.tuyaus.com',
            'central europe' => 'https://openapi.tuyaeu.com',
            'europe' => 'https://openapi.tuyaeu.com',
            'eu' => 'https://openapi.tuyaeu.com',
            'tuyaeu' => 'https://openapi.tuyaeu.com',
            'western europe' => 'https://openapi-weaz.tuyaeu.com',
            'weaz' => 'https://openapi-weaz.tuyaeu.com',
            'india' => 'https://openapi.tuyain.com',
            'in' => 'https://openapi.tuyain.com',
            'tuyain' => 'https://openapi.tuyain.com',
            'china' => 'https://openapi.tuyacn.com',
            'cn' => 'https://openapi.tuyacn.com',
            'tuyacn' => 'https://openapi.tuyacn.com',
        ];

        return $map[$normalized] ?? null;
    }
}

if (!function_exists('portao_tuya_config')) {
    function portao_tuya_config(): array {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $clientId = trim((string)(portao_env('PORTAO_TUYA_CLIENT_ID', portao_env('TUYA_CLIENT_ID', portao_env('TUYA_ACCESS_ID', ''))) ?? ''));
        $clientSecret = trim((string)(portao_env('PORTAO_TUYA_CLIENT_SECRET', portao_env('TUYA_CLIENT_SECRET', portao_env('TUYA_ACCESS_SECRET', ''))) ?? ''));
        $deviceId = trim((string)(portao_env('PORTAO_TUYA_DEVICE_ID', portao_env('TUYA_DEVICE_ID', '')) ?? ''));

        $endpoint = trim((string)(portao_env('PORTAO_TUYA_ENDPOINT', portao_env('TUYA_ENDPOINT', '')) ?? ''));
        if ($endpoint === '') {
            $region = (string)(portao_env('PORTAO_TUYA_REGION', portao_env('TUYA_REGION', '')) ?? '');
            $mapped = portao_tuya_region_endpoint($region);
            if ($mapped !== null) {
                $endpoint = $mapped;
            }
        }
        if ($endpoint !== '' && !preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://' . $endpoint;
        }
        $endpoint = rtrim($endpoint, '/');

        $missing = [];
        if ($clientId === '') {
            $missing[] = 'PORTAO_TUYA_CLIENT_ID';
        }
        if ($clientSecret === '') {
            $missing[] = 'PORTAO_TUYA_CLIENT_SECRET';
        }
        if ($deviceId === '') {
            $missing[] = 'PORTAO_TUYA_DEVICE_ID';
        }
        if ($endpoint === '') {
            $missing[] = 'PORTAO_TUYA_ENDPOINT (ou PORTAO_TUYA_REGION)';
        }

        if (!empty($missing)) {
            $cache = [
                'ok' => false,
                'message' => 'Configuracao Tuya incompleta. Faltando: ' . implode(', ', $missing) . '.',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'device_id' => $deviceId,
                'endpoint' => $endpoint,
            ];
            return $cache;
        }

        $cache = [
            'ok' => true,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'device_id' => $deviceId,
            'endpoint' => $endpoint,
        ];
        return $cache;
    }
}

if (!function_exists('portao_tuya_now_ms')) {
    function portao_tuya_now_ms(): string {
        return (string)((int)floor(microtime(true) * 1000));
    }
}

if (!function_exists('portao_tuya_make_nonce')) {
    function portao_tuya_make_nonce(): string {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return str_replace('.', '', uniqid('', true));
        }
    }
}

if (!function_exists('portao_tuya_build_relative_url')) {
    function portao_tuya_build_relative_url(string $path, array $query = []): string {
        $parsed = parse_url($path);
        $cleanPath = (string)($parsed['path'] ?? $path);
        if ($cleanPath === '') {
            $cleanPath = '/';
        }
        if ($cleanPath[0] !== '/') {
            $cleanPath = '/' . $cleanPath;
        }

        $queryFromPath = [];
        if (!empty($parsed['query'])) {
            parse_str((string)$parsed['query'], $queryFromPath);
        }

        $finalQuery = array_merge($queryFromPath, $query);
        if (!empty($finalQuery)) {
            ksort($finalQuery);
            $queryString = http_build_query($finalQuery, '', '&', PHP_QUERY_RFC3986);
            if ($queryString !== '') {
                return $cleanPath . '?' . $queryString;
            }
        }

        return $cleanPath;
    }
}

if (!function_exists('portao_tuya_request')) {
    function portao_tuya_request(
        string $method,
        string $path,
        ?array $query = null,
        $body = null,
        ?string $accessToken = null
    ): array {
        $config = portao_tuya_config();
        if (empty($config['ok'])) {
            return [
                'ok' => false,
                'http_code' => 0,
                'api_code' => null,
                'message' => (string)($config['message'] ?? 'Configuracao Tuya invalida.'),
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'http_code' => 0,
                'api_code' => null,
                'message' => 'Extensao cURL nao disponivel no PHP.',
            ];
        }

        $method = strtoupper(trim($method));
        if ($method === '') {
            $method = 'GET';
        }

        $relativeUrl = portao_tuya_build_relative_url($path, $query ?? []);
        $bodyRaw = '';
        if ($body !== null) {
            if (is_string($body)) {
                $bodyRaw = $body;
            } else {
                $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $bodyRaw = ($encoded === false) ? '' : $encoded;
            }
        }

        $contentSha256 = hash('sha256', $bodyRaw);
        $stringToSign = $method . "\n" . $contentSha256 . "\n\n" . $relativeUrl;

        $timestamp = portao_tuya_now_ms();
        $nonce = portao_tuya_make_nonce();

        $signString = (string)$config['client_id']
            . ($accessToken !== null ? $accessToken : '')
            . $timestamp
            . $nonce
            . $stringToSign;

        $sign = strtoupper(hash_hmac('sha256', $signString, (string)$config['client_secret']));

        $headers = [
            'client_id: ' . $config['client_id'],
            'sign: ' . $sign,
            't: ' . $timestamp,
            'sign_method: HMAC-SHA256',
            'nonce: ' . $nonce,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($accessToken !== null && $accessToken !== '') {
            $headers[] = 'access_token: ' . $accessToken;
        }

        $url = (string)$config['endpoint'] . $relativeUrl;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        if ($bodyRaw !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyRaw);
        }

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'api_code' => null,
                'message' => 'Falha cURL Tuya: ' . ($curlError ?: 'erro desconhecido'),
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'http_code' => $httpCode,
                'api_code' => null,
                'message' => 'Resposta Tuya invalida (nao-JSON).',
                'raw' => $raw,
            ];
        }

        $success = !empty($decoded['success']);
        $apiCode = $decoded['code'] ?? null;
        $msg = (string)($decoded['msg'] ?? $decoded['message'] ?? '');

        $ok = $success && $httpCode >= 200 && $httpCode < 300;
        if ($msg === '') {
            $msg = $ok ? 'OK' : 'Falha Tuya.';
        }

        return [
            'ok' => $ok,
            'http_code' => $httpCode,
            'api_code' => $apiCode,
            'message' => $msg,
            'result' => $decoded['result'] ?? null,
            'raw' => $raw,
            'response' => $decoded,
        ];
    }
}

if (!function_exists('portao_tuya_get_token')) {
    function portao_tuya_get_token(bool $forceRefresh = false): array {
        static $tokenCache = null;

        if (!$forceRefresh && is_array($tokenCache) && !empty($tokenCache['token']) && !empty($tokenCache['expires_at'])) {
            if ((int)$tokenCache['expires_at'] > (time() + 45)) {
                return ['ok' => true, 'access_token' => (string)$tokenCache['token'], 'cached' => true];
            }
        }

        $tokenResponse = portao_tuya_request('GET', '/v1.0/token', ['grant_type' => 1], null, null);
        if (empty($tokenResponse['ok'])) {
            return [
                'ok' => false,
                'message' => (string)($tokenResponse['message'] ?? 'Falha ao obter token Tuya.'),
                'http_code' => $tokenResponse['http_code'] ?? 0,
                'api_code' => $tokenResponse['api_code'] ?? null,
            ];
        }

        $result = is_array($tokenResponse['result'] ?? null) ? $tokenResponse['result'] : [];
        $accessToken = trim((string)($result['access_token'] ?? ''));
        $expireSeconds = (int)($result['expire_time'] ?? 0);

        if ($accessToken === '') {
            return [
                'ok' => false,
                'message' => 'Tuya nao retornou access_token.',
            ];
        }

        if ($expireSeconds <= 0) {
            $expireSeconds = 3600;
        }
        $tokenCache = [
            'token' => $accessToken,
            'expires_at' => time() + max(60, $expireSeconds - 30),
        ];

        return ['ok' => true, 'access_token' => $accessToken, 'cached' => false];
    }
}

if (!function_exists('portao_tuya_call')) {
    function portao_tuya_call(string $method, string $path, ?array $query = null, $body = null): array {
        $tokenData = portao_tuya_get_token(false);
        if (empty($tokenData['ok'])) {
            return [
                'ok' => false,
                'message' => (string)($tokenData['message'] ?? 'Falha ao autenticar na Tuya.'),
                'http_code' => $tokenData['http_code'] ?? 0,
                'api_code' => $tokenData['api_code'] ?? null,
            ];
        }

        $response = portao_tuya_request($method, $path, $query, $body, (string)$tokenData['access_token']);
        if (!empty($response['ok'])) {
            return $response;
        }

        $apiCode = (string)($response['api_code'] ?? '');
        $message = strtolower((string)($response['message'] ?? ''));
        $looksLikeExpiredToken = in_array($apiCode, ['1010', '1011', '1106'], true)
            || strpos($message, 'token') !== false;

        if (!$looksLikeExpiredToken) {
            return $response;
        }

        $tokenData = portao_tuya_get_token(true);
        if (empty($tokenData['ok'])) {
            return $response;
        }

        return portao_tuya_request($method, $path, $query, $body, (string)$tokenData['access_token']);
    }
}

if (!function_exists('portao_tuya_fetch_functions')) {
    function portao_tuya_fetch_functions(string $deviceId): array {
        $deviceId = rawurlencode(trim($deviceId));
        if ($deviceId === '') {
            return ['ok' => false, 'message' => 'Device ID Tuya vazio.', 'functions' => []];
        }

        $paths = [
            '/v1.0/iot-03/devices/' . $deviceId . '/functions',
            '/v1.0/devices/' . $deviceId . '/functions',
        ];

        $lastError = null;
        foreach ($paths as $path) {
            $resp = portao_tuya_call('GET', $path, null, null);
            if (!empty($resp['ok'])) {
                $result = is_array($resp['result'] ?? null) ? $resp['result'] : [];
                $functions = [];
                if (isset($result['functions']) && is_array($result['functions'])) {
                    $functions = $result['functions'];
                } elseif (is_array($resp['result'] ?? null)) {
                    $functions = $resp['result'];
                }

                return [
                    'ok' => true,
                    'message' => 'Funcoes Tuya carregadas.',
                    'functions' => $functions,
                    'path' => $path,
                ];
            }
            $lastError = $resp;
        }

        return [
            'ok' => false,
            'message' => (string)($lastError['message'] ?? 'Falha ao buscar funcoes Tuya.'),
            'functions' => [],
            'http_code' => $lastError['http_code'] ?? 0,
            'api_code' => $lastError['api_code'] ?? null,
        ];
    }
}

if (!function_exists('portao_tuya_parse_typed_value')) {
    function portao_tuya_parse_typed_value(string $raw, string $type = 'auto') {
        $value = trim($raw);
        $type = strtolower(trim($type));
        if ($type === '') {
            $type = 'auto';
        }

        if ($type === 'bool' || $type === 'boolean') {
            $truthy = ['1', 'true', 'yes', 'on', 'open', 'abrir', 'aberto'];
            return in_array(strtolower($value), $truthy, true);
        }
        if ($type === 'int' || $type === 'integer') {
            return (int)$value;
        }
        if ($type === 'float' || $type === 'double') {
            return (float)$value;
        }
        if ($type === 'json') {
            $decoded = json_decode($value, true);
            return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
        }
        if ($type === 'string') {
            return $value;
        }

        $lower = strtolower($value);
        if (in_array($lower, ['true', 'false'], true)) {
            return $lower === 'true';
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return (int)$value;
        }
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float)$value;
        }
        if ((substr($value, 0, 1) === '{' && substr($value, -1) === '}')
            || (substr($value, 0, 1) === '[' && substr($value, -1) === ']')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $value;
    }
}

if (!function_exists('portao_tuya_build_command_from_functions')) {
    function portao_tuya_build_command_from_functions(array $functions, string $acao): ?array {
        $acao = strtolower(trim($acao));
        if (!in_array($acao, ['abrir', 'fechar'], true)) {
            return null;
        }

        $normalizedFunctions = [];
        foreach ($functions as $function) {
            if (!is_array($function)) {
                continue;
            }
            $code = strtolower(trim((string)($function['code'] ?? '')));
            $type = strtolower(trim((string)($function['type'] ?? '')));
            if ($code === '' || $type === '') {
                continue;
            }
            $normalizedFunctions[] = [
                'raw' => $function,
                'code' => $code,
                'type' => $type,
                'values' => $function['values'] ?? null,
            ];
        }

        $preferredBooleanCodes = ['switch_1', 'switch', 'switch_led', 'relay_switch', 'start'];
        foreach ($preferredBooleanCodes as $preferredCode) {
            foreach ($normalizedFunctions as $item) {
                if ($item['code'] === $preferredCode && strpos($item['type'], 'bool') !== false) {
                    return [
                        'commands' => [
                            ['code' => $item['raw']['code'], 'value' => $acao === 'abrir'],
                        ],
                        'strategy' => 'auto_boolean_preferido',
                        'code' => $item['raw']['code'],
                    ];
                }
            }
        }

        foreach ($normalizedFunctions as $item) {
            if (strpos($item['type'], 'bool') === false) {
                continue;
            }
            if (preg_match('/switch|relay|open|close|door|gate|lock|unlock/i', (string)$item['code'])) {
                return [
                    'commands' => [
                        ['code' => $item['raw']['code'], 'value' => $acao === 'abrir'],
                    ],
                    'strategy' => 'auto_boolean_keyword',
                    'code' => $item['raw']['code'],
                ];
            }
        }

        foreach ($normalizedFunctions as $item) {
            if (strpos($item['type'], 'enum') === false) {
                continue;
            }
            $valuesRaw = $item['values'];
            $valuesData = is_string($valuesRaw) ? json_decode($valuesRaw, true) : (is_array($valuesRaw) ? $valuesRaw : []);
            $range = is_array($valuesData['range'] ?? null) ? $valuesData['range'] : [];
            if (empty($range)) {
                continue;
            }
            $rangeLower = array_map(static function ($v) {
                return strtolower(trim((string)$v));
            }, $range);

            $openCandidates = ['open', 'on', 'up', 'start', 'unlock'];
            $closeCandidates = ['close', 'off', 'down', 'stop', 'lock'];
            $openValue = null;
            $closeValue = null;

            foreach ($openCandidates as $candidate) {
                $idx = array_search($candidate, $rangeLower, true);
                if ($idx !== false) {
                    $openValue = $range[$idx];
                    break;
                }
            }
            foreach ($closeCandidates as $candidate) {
                $idx = array_search($candidate, $rangeLower, true);
                if ($idx !== false) {
                    $closeValue = $range[$idx];
                    break;
                }
            }

            if ($openValue === null || $closeValue === null) {
                continue;
            }

            return [
                'commands' => [
                    ['code' => $item['raw']['code'], 'value' => $acao === 'abrir' ? $openValue : $closeValue],
                ],
                'strategy' => 'auto_enum_range',
                'code' => $item['raw']['code'],
            ];
        }

        return null;
    }
}

if (!function_exists('portao_tuya_build_commands')) {
    function portao_tuya_build_commands(string $acao, array $functions): array {
        $acao = strtolower(trim($acao));
        if (!in_array($acao, ['abrir', 'fechar'], true)) {
            return ['ok' => false, 'message' => 'Acao Tuya invalida: ' . $acao];
        }

        $jsonEnvKey = ($acao === 'abrir') ? 'PORTAO_TUYA_COMMAND_ABRIR_JSON' : 'PORTAO_TUYA_COMMAND_FECHAR_JSON';
        $jsonCommandRaw = trim((string)(portao_env($jsonEnvKey, '') ?? ''));
        if ($jsonCommandRaw !== '') {
            $decoded = json_decode($jsonCommandRaw, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'message' => $jsonEnvKey . ' invalido (JSON).'];
            }
            if (isset($decoded['commands']) && is_array($decoded['commands'])) {
                return [
                    'ok' => true,
                    'commands' => $decoded['commands'],
                    'strategy' => 'env_json_commands',
                ];
            }
            if (isset($decoded['code'])) {
                return [
                    'ok' => true,
                    'commands' => [['code' => $decoded['code'], 'value' => $decoded['value'] ?? null]],
                    'strategy' => 'env_json_single',
                ];
            }
            return ['ok' => false, 'message' => $jsonEnvKey . ' precisa conter "commands" ou "code".'];
        }

        $dpCode = trim((string)(portao_env('PORTAO_TUYA_DP_CODE', '') ?? ''));
        if ($dpCode !== '') {
            $valueType = trim((string)(portao_env('PORTAO_TUYA_VALUE_TYPE', 'auto') ?? 'auto'));
            $openRaw = (string)(portao_env('PORTAO_TUYA_OPEN_VALUE', 'true') ?? 'true');
            $closeRaw = (string)(portao_env('PORTAO_TUYA_CLOSE_VALUE', 'false') ?? 'false');
            $valueRaw = ($acao === 'abrir') ? $openRaw : $closeRaw;
            $value = portao_tuya_parse_typed_value($valueRaw, $valueType);

            return [
                'ok' => true,
                'commands' => [['code' => $dpCode, 'value' => $value]],
                'strategy' => 'env_dp_code',
            ];
        }

        $auto = portao_tuya_build_command_from_functions($functions, $acao);
        if ($auto !== null) {
            return [
                'ok' => true,
                'commands' => $auto['commands'],
                'strategy' => (string)($auto['strategy'] ?? 'auto'),
                'code' => $auto['code'] ?? null,
            ];
        }

        return [
            'ok' => false,
            'message' => 'Nao foi possivel inferir comando Tuya automaticamente. Configure PORTAO_TUYA_DP_CODE ou PORTAO_TUYA_COMMAND_*_JSON.',
        ];
    }
}

if (!function_exists('portao_tuya_dispatch_command')) {
    function portao_tuya_dispatch_command(string $acao, string $origem = 'manual'): array {
        $config = portao_tuya_config();
        if (empty($config['ok'])) {
            return [
                'ok' => false,
                'provider' => 'tuya',
                'message' => (string)($config['message'] ?? 'Configuracao Tuya invalida.'),
            ];
        }

        $deviceId = (string)$config['device_id'];
        $functionsResp = portao_tuya_fetch_functions($deviceId);
        $functions = is_array($functionsResp['functions'] ?? null) ? $functionsResp['functions'] : [];

        $commandPlan = portao_tuya_build_commands($acao, $functions);
        if (empty($commandPlan['ok'])) {
            return [
                'ok' => false,
                'provider' => 'tuya',
                'message' => (string)($commandPlan['message'] ?? 'Falha ao montar comando Tuya.'),
                'payload' => [
                    'functions_loaded' => !empty($functionsResp['ok']),
                    'functions_count' => count($functions),
                    'functions_error' => $functionsResp['message'] ?? null,
                ],
            ];
        }

        $commandBody = ['commands' => $commandPlan['commands']];
        $encodedDeviceId = rawurlencode($deviceId);
        $paths = [
            '/v1.0/iot-03/devices/' . $encodedDeviceId . '/commands',
            '/v1.0/devices/' . $encodedDeviceId . '/commands',
        ];

        $lastError = null;
        $response = null;
        $usedPath = null;

        foreach ($paths as $index => $path) {
            $response = portao_tuya_call('POST', $path, null, $commandBody);
            if (!empty($response['ok'])) {
                $usedPath = $path;
                break;
            }

            $lastError = $response;
            $httpCode = (int)($response['http_code'] ?? 0);
            if ($index === 0 && $httpCode === 404) {
                continue;
            }
            break;
        }

        if ($usedPath === null || empty($response['ok'])) {
            $err = $lastError ?: $response ?: [];
            return [
                'ok' => false,
                'provider' => 'tuya',
                'message' => (string)($err['message'] ?? 'Falha ao enviar comando Tuya.'),
                'payload' => [
                    'http_code' => $err['http_code'] ?? null,
                    'api_code' => $err['api_code'] ?? null,
                    'strategy' => $commandPlan['strategy'] ?? null,
                    'commands' => $commandBody['commands'],
                ],
            ];
        }

        return [
            'ok' => true,
            'provider' => 'tuya',
            'message' => 'Comando enviado para Tuya com sucesso.',
            'payload' => [
                'origin' => $origem,
                'path' => $usedPath,
                'strategy' => $commandPlan['strategy'] ?? null,
                'commands' => $commandBody['commands'],
                'tuya_result' => $response['result'] ?? null,
            ],
        ];
    }
}

if (!function_exists('portao_dispatch_command')) {
    function portao_dispatch_command(string $acao, string $origem = 'manual'): array {
        $provider = portao_provider_mode();

        if (in_array($provider, ['simulado', 'mock', 'teste'], true)) {
            return [
                'ok' => true,
                'provider' => 'simulado',
                'message' => 'Comando executado em modo simulado.',
                'payload' => ['acao' => $acao, 'origem' => $origem],
            ];
        }

        if (in_array($provider, ['http', 'webhook'], true)) {
            $url = trim((string)(portao_env('PORTAO_HTTP_URL', '') ?? ''));
            if ($url === '') {
                return [
                    'ok' => false,
                    'provider' => $provider,
                    'message' => 'PORTAO_HTTP_URL nao configurada.',
                ];
            }

            if (!function_exists('curl_init')) {
                return [
                    'ok' => false,
                    'provider' => $provider,
                    'message' => 'Extensao cURL nao disponivel no PHP.',
                ];
            }

            $timeout = (int)(portao_env('PORTAO_HTTP_TIMEOUT', '10') ?? '10');
            if ($timeout < 2 || $timeout > 60) {
                $timeout = 10;
            }

            $body = [
                'action' => $acao,
                'origin' => $origem,
                'requested_at' => date('c'),
            ];

            $headers = ['Content-Type: application/json'];
            $token = trim((string)(portao_env('PORTAO_HTTP_TOKEN', '') ?? ''));
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $responseRaw = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($responseRaw === false) {
                return [
                    'ok' => false,
                    'provider' => $provider,
                    'message' => 'Falha HTTP: ' . ($curlError ?: 'erro desconhecido'),
                    'payload' => ['http_code' => $httpCode],
                ];
            }

            $decoded = json_decode($responseRaw, true);
            $ok = ($httpCode >= 200 && $httpCode < 300);

            if ($ok && is_array($decoded) && array_key_exists('ok', $decoded) && empty($decoded['ok'])) {
                $ok = false;
            }

            $message = $ok
                ? 'Comando enviado para integracao HTTP.'
                : 'Integracao HTTP retornou erro.';

            if (is_array($decoded)) {
                $message = (string)($decoded['message'] ?? $decoded['error'] ?? $message);
            }

            return [
                'ok' => $ok,
                'provider' => $provider,
                'message' => $message,
                'payload' => [
                    'http_code' => $httpCode,
                    'response' => $decoded ?? $responseRaw,
                ],
            ];
        }

        if (in_array($provider, ['smartlife', 'tuya'], true)) {
            $tuyaResponse = portao_tuya_dispatch_command($acao, $origem);
            if (!isset($tuyaResponse['provider'])) {
                $tuyaResponse['provider'] = 'tuya';
            }
            return $tuyaResponse;
        }

        return [
            'ok' => false,
            'provider' => $provider,
            'message' => 'Provider de portao invalido: ' . $provider,
        ];
    }
}

if (!function_exists('portao_execute_action')) {
    function portao_execute_action(PDO $pdo, string $acao, ?array $context = null, string $origem = 'manual'): array {
        $acao = strtolower(trim($acao));
        if (!in_array($acao, ['abrir', 'fechar'], true)) {
            return ['ok' => false, 'message' => 'Acao invalida.', 'acao' => $acao];
        }

        $ctx = $context ?? portao_user_context();
        portao_ensure_schema($pdo);
        $estadoAntes = portao_get_estado($pdo);
        $estadoAtual = strtolower((string)($estadoAntes['estado'] ?? 'desconhecido'));

        $sameState = ($acao === 'abrir' && $estadoAtual === 'aberto')
            || ($acao === 'fechar' && $estadoAtual === 'fechado');

        if ($sameState && $acao === 'fechar') {
            portao_set_estado($pdo, [
                'estado' => 'fechado',
                'ultima_acao' => 'fechar',
                'ultima_origem' => $origem,
                'ultima_resultado' => 'ignored',
                'ultima_usuario_id' => $ctx['usuario_id'] ?? null,
                'ultima_usuario_nome' => $ctx['usuario_nome'] ?? null,
                'ultima_detalhe' => 'Comando ignorado: portao ja estava fechado.',
                'auto_close_at' => null,
            ]);

            portao_log($pdo, [
                'usuario_id' => $ctx['usuario_id'] ?? null,
                'usuario_nome' => $ctx['usuario_nome'] ?? null,
                'acao' => 'fechar_ignorado',
                'origem' => $origem,
                'resultado' => 'ignored',
                'detalhe' => 'Comando ignorado: portao ja estava fechado.',
                'ip' => $ctx['ip'] ?? null,
                'user_agent' => $ctx['user_agent'] ?? null,
            ]);

            return ['ok' => true, 'message' => 'Portao ja estava fechado.', 'acao' => $acao];
        }

        if ($sameState && $acao === 'abrir') {
            $minutes = portao_auto_close_minutes();
            $autoCloseAt = (new DateTimeImmutable('now'))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');

            portao_set_estado($pdo, [
                'estado' => 'aberto',
                'ultima_acao' => 'abrir',
                'ultima_origem' => $origem,
                'ultima_resultado' => 'success',
                'ultima_usuario_id' => $ctx['usuario_id'] ?? null,
                'ultima_usuario_nome' => $ctx['usuario_nome'] ?? null,
                'ultima_detalhe' => 'Portao ja aberto; temporizador renovado.',
                'auto_close_at' => $autoCloseAt,
            ]);

            portao_log($pdo, [
                'usuario_id' => $ctx['usuario_id'] ?? null,
                'usuario_nome' => $ctx['usuario_nome'] ?? null,
                'acao' => 'abrir_renovado',
                'origem' => $origem,
                'resultado' => 'success',
                'detalhe' => 'Portao ja aberto; temporizador renovado para auto-fechar em ' . $minutes . ' min.',
                'ip' => $ctx['ip'] ?? null,
                'user_agent' => $ctx['user_agent'] ?? null,
                'payload' => ['auto_close_at' => $autoCloseAt],
            ]);

            return ['ok' => true, 'message' => 'Portao ja estava aberto. Temporizador renovado.', 'acao' => $acao];
        }

        $dispatch = portao_dispatch_command($acao, $origem);
        $ok = !empty($dispatch['ok']);
        $detail = (string)($dispatch['message'] ?? ($ok ? 'Comando enviado.' : 'Falha ao enviar comando.'));

        if (!$ok) {
            portao_set_estado($pdo, [
                'estado' => $estadoAtual ?: 'desconhecido',
                'ultima_acao' => $acao,
                'ultima_origem' => $origem,
                'ultima_resultado' => 'error',
                'ultima_usuario_id' => $ctx['usuario_id'] ?? null,
                'ultima_usuario_nome' => $ctx['usuario_nome'] ?? null,
                'ultima_detalhe' => $detail,
                'auto_close_at' => $estadoAntes['auto_close_at'] ?? null,
            ]);

            portao_log($pdo, [
                'usuario_id' => $ctx['usuario_id'] ?? null,
                'usuario_nome' => $ctx['usuario_nome'] ?? null,
                'acao' => $acao,
                'origem' => $origem,
                'resultado' => 'error',
                'detalhe' => $detail,
                'ip' => $ctx['ip'] ?? null,
                'user_agent' => $ctx['user_agent'] ?? null,
                'payload' => $dispatch,
            ]);

            return ['ok' => false, 'message' => $detail, 'acao' => $acao, 'provider' => $dispatch['provider'] ?? null];
        }

        $newState = ($acao === 'abrir') ? 'aberto' : 'fechado';
        $autoCloseAt = null;
        if ($acao === 'abrir') {
            $minutes = portao_auto_close_minutes();
            $autoCloseAt = (new DateTimeImmutable('now'))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
        }

        portao_set_estado($pdo, [
            'estado' => $newState,
            'ultima_acao' => $acao,
            'ultima_origem' => $origem,
            'ultima_resultado' => 'success',
            'ultima_usuario_id' => $ctx['usuario_id'] ?? null,
            'ultima_usuario_nome' => $ctx['usuario_nome'] ?? null,
            'ultima_detalhe' => $detail,
            'auto_close_at' => $autoCloseAt,
        ]);

        portao_log($pdo, [
            'usuario_id' => $ctx['usuario_id'] ?? null,
            'usuario_nome' => $ctx['usuario_nome'] ?? null,
            'acao' => $acao,
            'origem' => $origem,
            'resultado' => 'success',
            'detalhe' => $detail,
            'ip' => $ctx['ip'] ?? null,
            'user_agent' => $ctx['user_agent'] ?? null,
            'payload' => $dispatch,
        ]);

        if ($acao === 'abrir') {
            portao_log($pdo, [
                'usuario_id' => $ctx['usuario_id'] ?? null,
                'usuario_nome' => $ctx['usuario_nome'] ?? null,
                'acao' => 'auto_close_agendado',
                'origem' => $origem,
                'resultado' => 'success',
                'detalhe' => 'Fechamento automatico agendado para ' . $autoCloseAt . '.',
                'ip' => $ctx['ip'] ?? null,
                'user_agent' => $ctx['user_agent'] ?? null,
                'payload' => ['auto_close_at' => $autoCloseAt, 'minutes' => portao_auto_close_minutes()],
            ]);
        } elseif ($acao === 'fechar' && $origem !== 'auto' && !empty($estadoAntes['auto_close_at'])) {
            portao_log($pdo, [
                'usuario_id' => $ctx['usuario_id'] ?? null,
                'usuario_nome' => $ctx['usuario_nome'] ?? null,
                'acao' => 'auto_close_cancelado',
                'origem' => $origem,
                'resultado' => 'success',
                'detalhe' => 'Auto-fechamento cancelado por fechamento manual.',
                'ip' => $ctx['ip'] ?? null,
                'user_agent' => $ctx['user_agent'] ?? null,
            ]);
        }

        return [
            'ok' => true,
            'message' => $detail,
            'acao' => $acao,
            'provider' => $dispatch['provider'] ?? null,
            'auto_close_at' => $autoCloseAt,
        ];
    }
}

if (!function_exists('portao_process_auto_close')) {
    function portao_process_auto_close(PDO $pdo, bool $force = false): array {
        portao_ensure_schema($pdo);
        $state = portao_get_estado($pdo);
        $autoCloseAt = $state['auto_close_at'] ?? null;

        if (empty($autoCloseAt)) {
            return ['ok' => true, 'executed' => false, 'reason' => 'no_schedule'];
        }

        $dueTs = strtotime((string)$autoCloseAt);
        if ($dueTs === false) {
            portao_set_estado($pdo, [
                'estado' => (string)($state['estado'] ?? 'desconhecido'),
                'ultima_acao' => 'auto_close',
                'ultima_origem' => 'auto',
                'ultima_resultado' => 'error',
                'ultima_usuario_id' => null,
                'ultima_usuario_nome' => 'AUTO_CLOSE',
                'ultima_detalhe' => 'Data invalida em auto_close_at.',
                'auto_close_at' => null,
            ]);

            portao_log($pdo, [
                'usuario_id' => null,
                'usuario_nome' => 'AUTO_CLOSE',
                'acao' => 'auto_close_invalido',
                'origem' => 'auto',
                'resultado' => 'error',
                'detalhe' => 'Data invalida em auto_close_at; agendamento limpo.',
                'payload' => ['auto_close_at' => $autoCloseAt],
            ]);

            return ['ok' => false, 'executed' => false, 'reason' => 'invalid_schedule'];
        }

        if (!$force && $dueTs > time()) {
            return ['ok' => true, 'executed' => false, 'reason' => 'not_due', 'auto_close_at' => $autoCloseAt];
        }

        if (($state['estado'] ?? '') !== 'aberto') {
            portao_set_estado($pdo, [
                'estado' => (string)($state['estado'] ?? 'desconhecido'),
                'ultima_acao' => 'auto_close',
                'ultima_origem' => 'auto',
                'ultima_resultado' => 'ignored',
                'ultima_usuario_id' => null,
                'ultima_usuario_nome' => 'AUTO_CLOSE',
                'ultima_detalhe' => 'Agendamento descartado: estado atual nao e aberto.',
                'auto_close_at' => null,
            ]);

            portao_log($pdo, [
                'usuario_id' => null,
                'usuario_nome' => 'AUTO_CLOSE',
                'acao' => 'auto_close_descartado',
                'origem' => 'auto',
                'resultado' => 'ignored',
                'detalhe' => 'Agendamento descartado: estado atual nao e aberto.',
            ]);

            return ['ok' => true, 'executed' => false, 'reason' => 'state_not_open'];
        }

        $result = portao_execute_action($pdo, 'fechar', [
            'usuario_id' => null,
            'usuario_nome' => 'AUTO_CLOSE',
            'ip' => null,
            'user_agent' => 'system/cron',
        ], 'auto');

        return [
            'ok' => !empty($result['ok']),
            'executed' => true,
            'reason' => !empty($result['ok']) ? 'closed' : 'error',
            'result' => $result,
        ];
    }
}

if (!function_exists('portao_list_logs')) {
    function portao_list_logs(PDO $pdo, int $limit = 100): array {
        portao_ensure_schema($pdo);
        $limit = max(1, min(500, $limit));

        $st = $pdo->prepare("
            SELECT id, usuario_id, usuario_nome, acao, origem, resultado, detalhe, ip, user_agent, criado_em
            FROM portao_logs
            ORDER BY criado_em DESC, id DESC
            LIMIT :limite
        ");
        $st->bindValue(':limite', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
