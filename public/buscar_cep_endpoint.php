<?php
/**
 * Endpoint para busca de CEP via API ViaCEP
 * Retorna dados do endereço em JSON
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Limpar qualquer output anterior
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: application/json; charset=utf-8');

$cep = preg_replace('/[^0-9]/', '', $_GET['cep'] ?? '');

if (empty($cep) || strlen($cep) !== 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CEP inválido']);
    exit;
}

function fetchCepUrl(string $url, int $timeoutSeconds = 5): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PainelSmilePro/1.0 (+CEP lookup)');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $error === '',
        'status' => $httpCode,
        'body' => $response !== false ? (string)$response : '',
        'error' => $error,
    ];
}

try {
    // Rate limit simples por sessão (evita abuso em endpoints públicos)
    $now = time();
    $last = (int)($_SESSION['cep_last_req'] ?? 0);
    if ($last > 0 && ($now - $last) < 1) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Aguarde um instante e tente novamente.']);
        exit;
    }
    $_SESSION['cep_last_req'] = $now;

    // Cache simples em sessão (24h)
    if (!isset($_SESSION['cep_cache']) || !is_array($_SESSION['cep_cache'])) {
        $_SESSION['cep_cache'] = [];
    }
    $cached = $_SESSION['cep_cache'][$cep] ?? null;
    if (is_array($cached) && !empty($cached['data']) && !empty($cached['ts']) && ($now - (int)$cached['ts'] < 86400)) {
        echo json_encode(['success' => true, 'data' => $cached['data']]);
        exit;
    }

    $providers = [
        [
            'name' => 'viacep',
            'url' => "https://viacep.com.br/ws/{$cep}/json/",
            'timeout' => 4,
            'isNotFound' => function (array $data): bool {
                return !empty($data['erro']);
            },
            'map' => function (array $data): array {
                return [
                    'cep' => $data['cep'] ?? '',
                    'logradouro' => $data['logradouro'] ?? '',
                    'complemento' => $data['complemento'] ?? '',
                    'bairro' => $data['bairro'] ?? '',
                    'cidade' => $data['localidade'] ?? '',
                    'estado' => $data['uf'] ?? '',
                ];
            },
        ],
        [
            'name' => 'brasilapi',
            'url' => "https://brasilapi.com.br/api/cep/v1/{$cep}",
            'timeout' => 5,
            'isNotFound' => function (array $data): bool {
                return !empty($data['errors']) || (!empty($data['message']) && stripos((string)$data['message'], 'not found') !== false);
            },
            'map' => function (array $data): array {
                return [
                    'cep' => $data['cep'] ?? '',
                    'logradouro' => $data['street'] ?? '',
                    'complemento' => '',
                    'bairro' => $data['neighborhood'] ?? '',
                    'cidade' => $data['city'] ?? '',
                    'estado' => $data['state'] ?? '',
                ];
            },
        ],
    ];

    $lastError = '';
    foreach ($providers as $provider) {
        $result = fetchCepUrl($provider['url'], (int)$provider['timeout']);
        if (!$result['ok']) {
            $lastError = $provider['name'] . ' curl: ' . $result['error'];
            continue;
        }
        if ($result['status'] !== 200) {
            if ($result['status'] === 404) {
                echo json_encode(['success' => false, 'message' => 'CEP não encontrado']);
                exit;
            }
            $lastError = $provider['name'] . ' http: ' . $result['status'];
            continue;
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            $lastError = $provider['name'] . ' resposta inválida';
            continue;
        }
        $isNotFound = $provider['isNotFound'];
        if ($isNotFound($data)) {
            echo json_encode(['success' => false, 'message' => 'CEP não encontrado']);
            exit;
        }

        $map = $provider['map'];
        $payload = [
            'success' => true,
            'data' => $map($data),
        ];

        // Guardar cache
        $_SESSION['cep_cache'][$cep] = [
            'ts' => $now,
            'data' => $payload['data'],
        ];

        echo json_encode($payload);
        exit;
    }

    if ($lastError !== '') {
        error_log('[CEP] Falha ao buscar CEP ' . $cep . ': ' . $lastError);
    }

    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Serviço de CEP indisponível. Tente novamente.'
    ]);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

