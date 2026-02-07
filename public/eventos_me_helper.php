<?php
/**
 * eventos_me_helper.php
 * Helper para integração com API ME Eventos no módulo Eventos
 * Implementa cache local para evitar rate limit (429)
 */

require_once __DIR__ . '/me_config.php';
require_once __DIR__ . '/conexao.php';

/**
 * Lê um caminho dentro de array com fallback case-insensitive por nível.
 */
function eventos_me_get_path_value(array $source, string $path) {
    $parts = explode('.', $path);
    $value = $source;

    foreach ($parts as $part) {
        if (!is_array($value)) {
            return null;
        }

        if (array_key_exists($part, $value)) {
            $value = $value[$part];
            continue;
        }

        $matched = null;
        $found = false;
        foreach ($value as $key => $candidate) {
            if (is_string($key) && strcasecmp($key, $part) === 0) {
                $matched = $candidate;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return null;
        }

        $value = $matched;
    }

    return $value;
}

/**
 * Retorna o primeiro texto não vazio encontrado nos caminhos informados.
 */
function eventos_me_pick_text(array $source, array $paths, string $default = ''): string {
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }

        $value = eventos_me_get_path_value($source, $path);
        if (is_string($value)) {
            $text = trim($value);
            if ($text !== '') {
                return $text;
            }
            continue;
        }

        if (is_numeric($value)) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
    }

    return $default;
}

/**
 * Retorna o primeiro inteiro válido encontrado nos caminhos informados.
 */
function eventos_me_pick_int(array $source, array $paths, int $default = 0): int {
    foreach ($paths as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }

        $value = eventos_me_get_path_value($source, $path);
        if (is_numeric($value)) {
            return (int)$value;
        }
    }

    return $default;
}

/**
 * Configuração da ME
 */
function eventos_me_get_config(): array {
    $base = getenv('ME_BASE_URL') ?: (defined('ME_BASE_URL') ? ME_BASE_URL : '');
    $token = getenv('ME_API_TOKEN') ?: getenv('ME_API_KEY') ?: (defined('ME_API_KEY') ? ME_API_KEY : '');
    return ['base' => rtrim((string)$base, '/'), 'token' => (string)$token];
}

/**
 * Request genérico para ME API
 */
function eventos_me_request(string $method, string $path, array $query = [], $body = null): array {
    $cfg = eventos_me_get_config();
    if ($cfg['base'] === '' || $cfg['token'] === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'ME_BASE_URL/ME_API_TOKEN não configurados.'];
    }

    $url = $cfg['base'] . $path;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    $headers = [
        'Authorization: ' . $cfg['token'],
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'code' => $code, 'error' => 'Erro cURL: ' . $err];
    }

    $data = null;
    if (is_string($resp) && trim($resp) !== '') {
        $data = json_decode($resp, true);
    }

    if ($code < 200 || $code >= 300) {
        $msg = 'HTTP ' . $code . ' da ME';
        if (is_array($data)) {
            $msg = (string)($data['message'] ?? $data['error'] ?? $msg);
        }
        return ['ok' => false, 'code' => $code, 'error' => $msg];
    }

    return ['ok' => true, 'code' => $code, 'data' => is_array($data) ? $data : []];
}

/**
 * ==========================================
 * CACHE LOCAL (eventos_me_cache)
 * ==========================================
 */

/**
 * Obter do cache se não expirou
 */
function eventos_me_cache_get(PDO $pdo, string $cache_key): ?array {
    try {
        $stmt = $pdo->prepare("
            SELECT data FROM eventos_me_cache 
            WHERE cache_key = :key AND expires_at > NOW()
        ");
        $stmt->execute([':key' => $cache_key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && !empty($row['data'])) {
            return json_decode($row['data'], true);
        }
    } catch (Exception $e) {
        error_log("Cache get error: " . $e->getMessage());
    }
    return null;
}

/**
 * Salvar no cache
 */
function eventos_me_cache_set(PDO $pdo, string $cache_key, array $data, int $ttl_minutes = 10): void {
    try {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("
            INSERT INTO eventos_me_cache (cache_key, data, cached_at, expires_at)
            VALUES (:key, :data, NOW(), NOW() + INTERVAL ':ttl minutes')
            ON CONFLICT (cache_key) DO UPDATE SET
                data = EXCLUDED.data,
                cached_at = NOW(),
                expires_at = NOW() + INTERVAL ':ttl minutes'
        ");
        // O INTERVAL não aceita placeholder, então vamos fazer diferente
        $expires = date('Y-m-d H:i:s', strtotime("+{$ttl_minutes} minutes"));
        
        $stmt = $pdo->prepare("
            INSERT INTO eventos_me_cache (cache_key, data, cached_at, expires_at)
            VALUES (:key, :data, NOW(), :expires)
            ON CONFLICT (cache_key) DO UPDATE SET
                data = EXCLUDED.data,
                cached_at = NOW(),
                expires_at = EXCLUDED.expires_at
        ");
        $stmt->execute([
            ':key' => $cache_key,
            ':data' => $json,
            ':expires' => $expires
        ]);
    } catch (Exception $e) {
        error_log("Cache set error: " . $e->getMessage());
    }
}

/**
 * Limpar cache expirado
 */
function eventos_me_cache_cleanup(PDO $pdo): void {
    try {
        $pdo->exec("DELETE FROM eventos_me_cache WHERE expires_at < NOW()");
    } catch (Exception $e) {
        error_log("Cache cleanup error: " . $e->getMessage());
    }
}

/**
 * ==========================================
 * BUSCA DE EVENTOS COM CACHE
 * ==========================================
 */

/**
 * Buscar eventos futuros da ME (com cache)
 * 
 * @param PDO $pdo
 * @param string $search Termo de busca (nome/cliente)
 * @param int $days_ahead Quantos dias à frente buscar (padrão 60)
 * @param bool $force_refresh Ignorar cache e buscar da API
 * @return array ['ok' => bool, 'events' => array, 'from_cache' => bool]
 */
function eventos_me_buscar_futuros(PDO $pdo, string $search = '', int $days_ahead = 60, bool $force_refresh = false): array {
    $start = date('Y-m-d');
    $end = date('Y-m-d', strtotime("+{$days_ahead} days"));
    
    // Gerar chave de cache baseada nos parâmetros
    $cache_key = "events_list_{$start}_{$end}";
    
    // Verificar cache (se não forçar refresh)
    if (!$force_refresh) {
        $cached = eventos_me_cache_get($pdo, $cache_key);
        if ($cached !== null) {
            // Aplicar filtro de busca local
            $filtered = eventos_me_filtrar_local($cached, $search);
            return [
                'ok' => true,
                'events' => $filtered,
                'from_cache' => true,
                'total' => count($cached),
                'filtered' => count($filtered)
            ];
        }
    }
    
    // Buscar da API ME
    $resp = eventos_me_request('GET', '/api/v1/events', [
        'start' => $start,
        'end' => $end,
        'limit' => 500 // Buscar bastante para ter cache robusto
    ]);
    
    if (!$resp['ok']) {
        return [
            'ok' => false,
            'error' => $resp['error'] ?? 'Erro ao buscar eventos',
            'events' => []
        ];
    }
    
    $events = $resp['data']['data'] ?? $resp['data'] ?? [];
    if (!is_array($events)) {
        $events = [];
    }
    
    // Salvar no cache (10 minutos)
    if (!empty($events)) {
        eventos_me_cache_set($pdo, $cache_key, $events, 10);
    }
    
    // Aplicar filtro de busca
    $filtered = eventos_me_filtrar_local($events, $search);
    
    return [
        'ok' => true,
        'events' => $filtered,
        'from_cache' => false,
        'total' => count($events),
        'filtered' => count($filtered)
    ];
}

/**
 * Filtrar eventos localmente por termo de busca
 */
function eventos_me_filtrar_local(array $events, string $search): array {
    if (trim($search) === '') {
        return $events;
    }
    
    $search_lower = mb_strtolower(trim($search));
    
    return array_values(array_filter($events, function($ev) use ($search_lower) {
        // Buscar no nome do evento
        $nome = mb_strtolower(eventos_me_pick_text($ev, [
            'nomeevento',
            'nome'
        ]));
        if (strpos($nome, $search_lower) !== false) {
            return true;
        }
        
        // Buscar no nome do cliente
        $cliente = mb_strtolower(eventos_me_pick_text($ev, [
            'nomecliente',
            'nomeCliente',
            'cliente.nome'
        ]));
        if (strpos($cliente, $search_lower) !== false) {
            return true;
        }

        // Buscar no local
        $local = mb_strtolower(eventos_me_pick_text($ev, [
            'local',
            'nomelocal',
            'localevento',
            'localEvento',
            'unidade',
            'endereco'
        ]));
        if (strpos($local, $search_lower) !== false) {
            return true;
        }
        
        // Buscar na data
        $data = mb_strtolower(eventos_me_pick_text($ev, [
            'dataevento',
            'data'
        ]));
        if (strpos($data, $search_lower) !== false) {
            return true;
        }
        
        return false;
    }));
}

/**
 * Buscar um evento específico por ID
 */
function eventos_me_buscar_por_id(PDO $pdo, int $event_id): array {
    $cache_key = "event_detail_{$event_id}";
    
    // Verificar cache (cache mais curto para detalhes: 5 min)
    $cached = eventos_me_cache_get($pdo, $cache_key);
    if ($cached !== null) {
        return ['ok' => true, 'event' => $cached, 'from_cache' => true];
    }
    
    // Buscar da API
    $resp = eventos_me_request('GET', "/api/v1/events/{$event_id}");
    
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => $resp['error'] ?? 'Evento não encontrado'];
    }
    
    $event = $resp['data']['data'] ?? $resp['data'] ?? null;
    
    if (!$event) {
        return ['ok' => false, 'error' => 'Evento não encontrado'];
    }
    
    // Salvar no cache (5 minutos)
    eventos_me_cache_set($pdo, $cache_key, $event, 5);
    
    return ['ok' => true, 'event' => $event, 'from_cache' => false];
}

/**
 * Criar snapshot do evento para salvar na reunião
 */
function eventos_me_criar_snapshot(array $event): array {
    return [
        'id' => eventos_me_pick_int($event, ['id']),
        'nome' => eventos_me_pick_text($event, ['nomeevento', 'nome']),
        'data' => eventos_me_pick_text($event, ['dataevento', 'data']),
        'hora_inicio' => eventos_me_pick_text($event, ['horainicio', 'hora_inicio', 'horaevento']),
        'hora_fim' => eventos_me_pick_text($event, ['horatermino', 'hora_fim', 'horafim']),
        'local' => eventos_me_pick_text($event, ['local', 'nomelocal', 'localevento', 'localEvento', 'endereco']),
        'unidade' => eventos_me_pick_text($event, ['unidade', 'tipoEvento', 'tipoevento']),
        'convidados' => eventos_me_pick_int($event, ['nconvidados', 'convidados']),
        'cliente' => [
            'id' => eventos_me_pick_int($event, ['idcliente', 'cliente.id']),
            'nome' => eventos_me_pick_text($event, ['nomecliente', 'nomeCliente', 'cliente.nome']),
            'email' => eventos_me_pick_text($event, ['emailcliente', 'cliente.email']),
            'telefone' => eventos_me_pick_text($event, ['telefonecliente', 'celular', 'cliente.telefone']),
        ],
        'tipo_evento' => eventos_me_pick_text($event, ['tipoevento', 'tipoEvento', 'tipo']),
        'snapshot_at' => date('Y-m-d H:i:s')
    ];
}
