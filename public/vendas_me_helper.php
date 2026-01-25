<?php
/**
 * vendas_me_helper.php
 * Helper para integração com API ME Eventos no módulo de Vendas
 */

require_once __DIR__ . '/me_config.php';
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/vendas_helper.php';

/**
 * Request helper (alinhado com padrões do projeto e docs da ME)
 */
function vendas_me_get_config(): array {
    $base = getenv('ME_BASE_URL') ?: (defined('ME_BASE_URL') ? ME_BASE_URL : '');
    $token = getenv('ME_API_TOKEN') ?: getenv('ME_API_KEY') ?: (defined('ME_API_KEY') ? ME_API_KEY : '');
    return ['base' => rtrim((string)$base, '/'), 'token' => (string)$token];
}

function vendas_me_request(string $method, string $path, array $query = [], $body = null): array {
    $cfg = vendas_me_get_config();
    if ($cfg['base'] === '' || $cfg['token'] === '') {
        return ['ok' => false, 'code' => 0, 'error' => 'ME_BASE_URL/ME_API_TOKEN (ou ME_API_KEY) não configurados.'];
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
        CURLOPT_TIMEOUT => 20,
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

    // Guardar último retorno para diagnóstico/UI (sem tokens)
    if (session_status() === PHP_SESSION_ACTIVE) {
        $raw_str = is_string($resp) ? $resp : '';
        if (strlen($raw_str) > 4000) {
            $raw_str = substr($raw_str, 0, 4000) . '...';
        }
        $body_safe = null;
        if ($body !== null) {
            $body_json = json_encode($body, JSON_UNESCAPED_UNICODE);
            if (!is_string($body_json)) {
                $body_json = '';
            }
            if (strlen($body_json) > 4000) {
                $body_json = substr($body_json, 0, 4000) . '...';
            }
            // Guardar como string para não quebrar JSON ao truncar
            $body_safe = $body_json;
        }
        $_SESSION['vendas_me_last'] = [
            'method' => strtoupper($method),
            'path' => $path,
            'code' => $code,
            'ok' => ($err === '' && $code >= 200 && $code < 300),
            'body' => $body_safe,
            'raw' => $raw_str,
        ];
    }

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
        return ['ok' => false, 'code' => $code, 'error' => $msg, 'raw' => $resp];
    }

    return ['ok' => true, 'code' => $code, 'data' => is_array($data) ? $data : [], 'raw' => $resp];
}

/**
 * Normaliza strings para comparação (lowercase, sem acentos, só [a-z0-9])
 */
function vendas_me_norm_str(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    $s = mb_strtolower($s);
    // Remover acentos quando possível
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if (is_string($t) && $t !== '') {
        $s = $t;
    }
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return (string)$s;
}

/**
 * Buscar cliente na ME por CPF, email ou telefone
 * Alinhado com a documentação: GET /api/v1/clients?search=...&type=...
 * type:
 *  - 1: E-mail
 *  - 2: Telefone/Celular
 *  - 3: CPF/CNPJ
 */
function vendas_me_buscar_cliente(string $cpf = '', string $email = '', string $telefone = '', string $nome = ''): array {
    $clientes_encontrados = [];

    $cpf_limpo = $cpf !== '' ? preg_replace('/\D/', '', $cpf) : '';
    $tel_limpo = $telefone !== '' ? preg_replace('/\D/', '', $telefone) : '';
    // Se veio com DDI 55 (ex.: 55DDDNÚMERO), remover para padronizar busca (ME retorna "31 999999999")
    if (strlen($tel_limpo) >= 12 && substr($tel_limpo, 0, 2) === '55') {
        $tel_limpo = substr($tel_limpo, 2);
    }
    $email_norm = strtolower(trim($email));
    $nome_norm = trim($nome);

    // Helper para normalizar retorno
    $pushCliente = function(array $cli, string $matchType, string $strength) use (&$clientes_encontrados) {
        $id = isset($cli['id']) ? (int)$cli['id'] : null;
        $clientes_encontrados[] = [
            'match_type' => $matchType,
            'match_strength' => $strength,
            'cliente' => [
                'id' => $id,
                'nome' => (string)($cli['nome'] ?? ''),
                'cpf' => preg_replace('/\D/', '', (string)($cli['cpf'] ?? '')),
                'email' => (string)($cli['email'] ?? ''),
                'telefone' => preg_replace('/\D/', '', (string)(($cli['celular'] ?? $cli['telefone'] ?? '') ?: '')),
                'raw' => $cli
            ]
        ];
    };

    // Prioridade 1: CPF/CNPJ (type=3)
    if ($cpf_limpo !== '' && strlen($cpf_limpo) >= 11) {
        $resp = vendas_me_request('GET', '/api/v1/clients', [
            'search' => $cpf_limpo,
            'type' => 3,
            'limit' => 200
        ]);
        if ($resp['ok']) {
            $items = $resp['data']['data'] ?? $resp['data'] ?? [];
            if (is_array($items)) {
                foreach ($items as $cli) {
                    if (!is_array($cli)) continue;
                    $cpf_api = preg_replace('/\D/', '', (string)($cli['cpf'] ?? $cli['cnpjpj'] ?? $cli['cpfcnpj'] ?? ''));
                    if ($cpf_api !== '' && $cpf_api === $cpf_limpo) {
                        $pushCliente($cli, 'cpf', 'forte');
                    }
                }
            }
        }
    }

    // Prioridade 2: Email (type=1) ou Telefone/Celular (type=2)
    if (empty($clientes_encontrados)) {
        if ($email_norm !== '') {
            $resp = vendas_me_request('GET', '/api/v1/clients', [
                'search' => $email_norm,
                'type' => 1,
                'limit' => 200
            ]);
            if ($resp['ok']) {
                $items = $resp['data']['data'] ?? $resp['data'] ?? [];
                if (is_array($items)) {
                    foreach ($items as $cli) {
                        if (!is_array($cli)) continue;
                        $email_api = strtolower(trim((string)($cli['email'] ?? '')));
                        if ($email_api !== '' && $email_api === $email_norm) {
                            $pushCliente($cli, 'email', 'forte');
                        }
                    }
                }
            }
        } elseif ($tel_limpo !== '' && strlen($tel_limpo) >= 8) {
            $resp = vendas_me_request('GET', '/api/v1/clients', [
                'search' => $tel_limpo,
                'type' => 2,
                'limit' => 200
            ]);
            if ($resp['ok']) {
                $items = $resp['data']['data'] ?? $resp['data'] ?? [];
                if (is_array($items)) {
                    foreach ($items as $cli) {
                        if (!is_array($cli)) continue;
                        $tel_api = preg_replace('/\D/', '', (string)($cli['celular'] ?? $cli['telefone'] ?? ''));
                        if ($tel_api !== '' && $tel_api === $tel_limpo) {
                            $pushCliente($cli, 'telefone', 'forte');
                        }
                    }
                }
            }
        }
    }

    // Prioridade 3: Nome (type=0 default) - sugestão (fraco)
    if (empty($clientes_encontrados) && $nome_norm !== '' && mb_strlen($nome_norm) >= 3) {
        $resp = vendas_me_request('GET', '/api/v1/clients', [
            'search' => $nome_norm,
            'type' => 0,
            'limit' => 50
        ]);
        if ($resp['ok']) {
            $items = $resp['data']['data'] ?? $resp['data'] ?? [];
            if (is_array($items)) {
                foreach ($items as $cli) {
                    if (!is_array($cli)) continue;
                    $pushCliente($cli, 'nome', 'fraco');
                }
            }
        }
    }

    return $clientes_encontrados;
}

/**
 * Criar cliente na ME
 * Nota: Se o endpoint /api/v1/clients não existir, retorna dados simulados
 * O cliente será criado automaticamente quando o evento for criado
 */
function vendas_me_parse_telefone_br(?string $raw): ?array {
    $digits = preg_replace('/\D/', '', (string)$raw);
    if ($digits === '') return null;

    // Se vier com DDI 55, remover para obter DDD + número
    if (strlen($digits) >= 12 && substr($digits, 0, 2) === '55') {
        $digits = substr($digits, 2);
    }

    // Esperado: DDD(2) + número(8 ou 9)
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        $ddd = substr($digits, 0, 2);
        $num = substr($digits, 2);
        return [
            'pais' => '+55',
            'ddd' => $ddd,
            'numero' => $num,
            // Formato exibido nas respostas da doc: "31 999999999"
            'formatado' => trim($ddd . ' ' . $num),
        ];
    }

    return null;
}

function vendas_me_formatar_telefone(?string $raw): ?string {
    $p = vendas_me_parse_telefone_br($raw);
    return $p ? $p['formatado'] : null;
}

function vendas_me_criar_cliente(array $dados_cliente): array {
    // Conforme docs: POST /api/v1/clients recebe um ARRAY de clientes
    // No Painel pedimos celular; na ME podemos enviar em 'celular' e também em 'telefone' (mesmo valor)
    // Aceitar input com/sem DDI 55 e normalizar para o formato esperado pela ME.
    $tel = vendas_me_parse_telefone_br($dados_cliente['celular'] ?? ($dados_cliente['telefone'] ?? null));
    $telefone_fmt = $tel['formatado'] ?? null;
    $cliente = [
        'nome' => (string)($dados_cliente['nome'] ?? ''),
        'email' => (string)($dados_cliente['email'] ?? ''),
        'cpf' => preg_replace('/\D/', '', (string)($dados_cliente['cpf'] ?? '')),
        'rg' => (string)($dados_cliente['rg'] ?? ''),
        'cep' => preg_replace('/\D/', '', (string)($dados_cliente['cep'] ?? '')),
        'endereco' => (string)($dados_cliente['endereco'] ?? ''),
        'numero' => (string)($dados_cliente['numero'] ?? ''),
        'complemento' => (string)($dados_cliente['complemento'] ?? ''),
        'bairro' => (string)($dados_cliente['bairro'] ?? ''),
        'cidade' => (string)($dados_cliente['cidade'] ?? ''),
        'estado' => (string)($dados_cliente['estado'] ?? ''),
        // DDI do Brasil fixo (conforme regra do fluxo)
        'pais' => '+55',
        // IMPORTANTE: ME valida formato de telefone/celular. Enviar no padrão "DD NUMERO".
        // Preferimos 'celular' e também preenchermos 'telefone' com o mesmo valor para compatibilidade.
        'celular' => $telefone_fmt ?: '',
        'telefone' => $telefone_fmt ?: '',
        'redesocial' => (string)($dados_cliente['redesocial'] ?? '')
    ];
    // remover campos vazios (evita erro de validação)
    if ($cliente['celular'] === '') unset($cliente['celular']);
    if ($cliente['telefone'] === '') unset($cliente['telefone']);

    $payload = [$cliente];
    $resp = vendas_me_request('POST', '/api/v1/clients', [], $payload);
    if (!$resp['ok']) {
        throw new Exception('Erro ao criar cliente na ME: ' . ($resp['error'] ?? 'erro desconhecido'));
    }

    $data = $resp['data'];
    $items = $data['data'] ?? $data ?? [];
    $id = null;
    if (is_array($items) && isset($items[0]['id'])) {
        $id = (int)$items[0]['id'];
    }
    if (!$id) {
        throw new Exception('ME não retornou ID do cliente na criação.');
    }

    return ['id' => $id, 'data' => $data, 'payload' => $payload];
}

/**
 * Atualizar cliente na ME
 */
function vendas_me_atualizar_cliente(int $client_id, array $dados_cliente): array {
    $allow = [
        'nome' => true,
        'email' => true,
        'email2' => true,
        'cep' => true,
        'endereco' => true,
        'numero' => true,
        'complemento' => true,
        'bairro' => true,
        'cidade' => true,
        'estado' => true,
        'pais' => true,
        'telefone' => true,
        'telefone2' => true,
        'celular' => true,
        'redesocial' => true,
        'rg' => true
    ];

    $payload = [];
    foreach ($dados_cliente as $k => $v) {
        if (!isset($allow[$k])) continue;
        if ($v === null) continue;
        $sv = is_string($v) ? trim($v) : $v;
        if ($sv === '') continue;
        $payload[$k] = $sv;
    }

    // Normalizar telefones para o formato esperado pela ME (evita "parâmetro telefone deve ser enviado corretamente")
    foreach (['telefone', 'telefone2', 'celular'] as $k) {
        if (!isset($payload[$k])) continue;
        $fmt = vendas_me_formatar_telefone((string)$payload[$k]);
        if ($fmt === null) {
            unset($payload[$k]);
        } else {
            $payload[$k] = $fmt;
        }
    }

    // DDI fixo Brasil (se estiver atualizando algum telefone/celular)
    if (!empty($payload['telefone']) || !empty($payload['telefone2']) || !empty($payload['celular'])) {
        $payload['pais'] = '+55';
    }

    if (empty($payload)) {
        return ['data' => [], 'payload' => []];
    }

    $resp = vendas_me_request('PUT', '/api/v1/clients/' . $client_id, [], $payload);
    if (!$resp['ok']) {
        throw new Exception('Erro ao atualizar cliente na ME: ' . ($resp['error'] ?? 'erro desconhecido'));
    }

    return ['data' => $resp['data'], 'payload' => $payload];
}

/**
 * Listar "Como conheceu" na ME (howmet) com cache em sessão.
 * Endpoint: GET /api/v1/howmet
 */
function vendas_me_listar_como_conheceu(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $cache_key = 'vendas_me_howmet';
    $cache_time_key = 'vendas_me_howmet_time';

    if (isset($_SESSION[$cache_key], $_SESSION[$cache_time_key])) {
        if (time() - (int)$_SESSION[$cache_time_key] < 300) { // 5 min
            return is_array($_SESSION[$cache_key]) ? $_SESSION[$cache_key] : [];
        }
    }

    $resp = vendas_me_request('GET', '/api/v1/howmet');
    if (!$resp['ok']) {
        return [];
    }

    $raw = $resp['data'];
    $items = null;
    if (is_array($raw)) {
        if (array_keys($raw) === range(0, count($raw) - 1)) {
            $items = $raw;
        } else {
            $items = $raw['data'] ?? null;
        }
    }
    if (!is_array($items)) $items = [];

    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = $item['id'] ?? null;
        $nome = $item['nome'] ?? null;
        if ($id === null || $nome === null) continue;
        $out[] = ['id' => (int)$id, 'nome' => (string)$nome];
    }

    $_SESSION[$cache_key] = $out;
    $_SESSION[$cache_time_key] = time();

    return $out;
}

/**
 * Resolve o ID numérico do "Como conheceu" (ME howmet) a partir de um valor textual do Painel.
 * A ME valida que o parâmetro `comoconheceu` seja numérico.
 */
function vendas_me_resolver_como_conheceu_id($valor): ?int {
    if ($valor === null) return null;
    $v = trim((string)$valor);
    if ($v === '') return null;

    // Se já vier numérico, ok
    $digits = preg_replace('/\D/', '', $v);
    if ($digits !== '' && $digits === $v) {
        $id = (int)$digits;
        return $id > 0 ? $id : null;
    }

    $vn = vendas_me_norm_str($v);
    if ($vn === '') return null;

    // Heurística para mapear rótulos do Painel para itens do howmet da ME
    $want = null;
    if (str_contains($vn, 'instagram')) $want = 'instagram';
    elseif (str_contains($vn, 'facebook') || str_contains($vn, 'face')) $want = 'facebook';
    elseif (str_contains($vn, 'google')) $want = 'google';
    elseif (str_contains($vn, 'indicacao') || str_contains($vn, 'indic')) $want = 'indicacao';
    elseif (str_contains($vn, 'outro') || str_contains($vn, 'outros')) $want = 'outro';

    $items = vendas_me_listar_como_conheceu();
    if (empty($items)) return null;

    // 1) Tentar match direto por substring (preferência)
    if ($want !== null) {
        foreach ($items as $it) {
            $nn = vendas_me_norm_str((string)($it['nome'] ?? ''));
            if ($nn === '') continue;
            if (str_contains($nn, $want)) {
                $id = (int)($it['id'] ?? 0);
                return $id > 0 ? $id : null;
            }
        }
    }

    // 2) Match aproximado: se o texto do Painel estiver contido no nome do howmet
    foreach ($items as $it) {
        $nn = vendas_me_norm_str((string)($it['nome'] ?? ''));
        if ($nn === '') continue;
        if (str_contains($nn, $vn) || str_contains($vn, $nn)) {
            $id = (int)($it['id'] ?? 0);
            return $id > 0 ? $id : null;
        }
    }

    return null;
}

/**
 * Buscar eventos na ME por data e unidade/local
 */
function vendas_me_buscar_eventos(string $data, string $unidade): array {
    // Conforme docs: GET /api/v1/events?start=Y-m-d&end=Y-m-d
    $me_local_id = vendas_obter_me_local_id($unidade);
    if (!$me_local_id) {
        throw new Exception('Local não mapeado. Ajuste em Logística > Conexão.');
    }

    $resp = vendas_me_request('GET', '/api/v1/events', [
        'start' => $data,
        'end' => $data,
        'limit' => 200
    ]);
    if (!$resp['ok']) {
        error_log('[VENDAS_ME] Erro ao buscar eventos: ' . ($resp['error'] ?? 'erro desconhecido'));
        return [];
    }

    $raw = $resp['data'];
    $items = $raw['data'] ?? $raw['eventos'] ?? $raw ?? [];
    if (!is_array($items)) return [];

    $eventos_filtrados = [];
    foreach ($items as $e) {
        if (!is_array($e)) continue;
        $idLocal = (int)($e['idlocalevento'] ?? 0);
        if ($idLocal > 0 && $idLocal === (int)$me_local_id) {
            $eventos_filtrados[] = $e;
            continue;
        }
        // fallback por nome
        $localNome = (string)($e['localevento'] ?? '');
        if ($localNome !== '' && mb_strtolower(trim($localNome)) === mb_strtolower(trim($unidade))) {
            $eventos_filtrados[] = $e;
        }
    }

    return $eventos_filtrados;
}

/**
 * Verificar conflito de agenda
 * Retorna array com eventos conflitantes e se há conflito
 */
function vendas_me_verificar_conflito_agenda(string $data, string $unidade, string $hora_inicio, string $hora_termino): array {
    $eventos = vendas_me_buscar_eventos($data, $unidade);
    $distancia_minima = vendas_distancia_minima_conflito_segundos($unidade);
    
    $conflitos = [];
    $hora_inicio_ts = strtotime($hora_inicio);
    $hora_termino_ts = strtotime($hora_termino);
    
    foreach ($eventos as $evento) {
        $evento_inicio = $evento['horaevento'] ?? $evento['hora_inicio'] ?? $evento['inicio'] ?? '';
        $evento_termino = $evento['horaeventofim'] ?? $evento['hora_termino'] ?? $evento['fim'] ?? '';
        
        if (empty($evento_inicio) || empty($evento_termino)) {
            continue;
        }
        
        $evento_inicio_ts = strtotime($evento_inicio);
        $evento_termino_ts = strtotime($evento_termino);
        
        // Verificar se há conflito
        // Conflito: novo evento começa antes do término do anterior + distância mínima
        // OU novo evento termina depois do início do anterior - distância mínima
        $tempo_entre_termino_e_inicio = $hora_inicio_ts - $evento_termino_ts;
        $tempo_entre_inicio_e_termino = $evento_inicio_ts - $hora_termino_ts;
        
        if ($tempo_entre_termino_e_inicio < $distancia_minima && $tempo_entre_termino_e_inicio > 0) {
            // Novo evento começa muito cedo após término do anterior
            $conflitos[] = [
                'evento' => $evento,
                'tipo' => 'inicio_proximo_demais',
                'tempo_entre' => $tempo_entre_termino_e_inicio,
                'distancia_minima' => $distancia_minima
            ];
        } elseif ($tempo_entre_inicio_e_termino < $distancia_minima && $tempo_entre_inicio_e_termino > 0) {
            // Novo evento termina muito tarde antes do início do próximo
            $conflitos[] = [
                'evento' => $evento,
                'tipo' => 'termino_proximo_demais',
                'tempo_entre' => $tempo_entre_inicio_e_termino,
                'distancia_minima' => $distancia_minima
            ];
        } elseif (($hora_inicio_ts >= $evento_inicio_ts && $hora_inicio_ts < $evento_termino_ts) ||
                  ($hora_termino_ts > $evento_inicio_ts && $hora_termino_ts <= $evento_termino_ts) ||
                  ($hora_inicio_ts <= $evento_inicio_ts && $hora_termino_ts >= $evento_termino_ts)) {
            // Sobreposição direta
            $conflitos[] = [
                'evento' => $evento,
                'tipo' => 'sobreposicao',
                'tempo_entre' => 0,
                'distancia_minima' => $distancia_minima
            ];
        }
    }
    
    return [
        'tem_conflito' => !empty($conflitos),
        'conflitos' => $conflitos,
        'distancia_minima_horas' => $distancia_minima / 3600
    ];
}

/**
 * Criar evento na ME
 * Se client_id for null, o evento será criado com dados do cliente inline
 */
function vendas_me_criar_evento(array $dados_evento): array {
    // Conforme docs: POST /api/v1/events (campos obrigatórios: idcliente, tipoevento, nomeevento, dataevento, idvendedor)
    $unidade_nome = (string)($dados_evento['local'] ?? $dados_evento['unidade'] ?? '');
    $me_local_id = vendas_obter_me_local_id($unidade_nome);
    if (!$me_local_id) {
        throw new Exception('Local não mapeado. Ajuste em Logística > Conexão.');
    }

    $idcliente = (int)($dados_evento['client_id'] ?? 0);
    if ($idcliente <= 0) {
        throw new Exception('ID do cliente (ME) é obrigatório para criar evento.');
    }

    $tipoevento = (int)($dados_evento['tipo_evento_id'] ?? 0);
    if ($tipoevento <= 0) {
        throw new Exception('tipoevento (ID do tipo de evento na ME) é obrigatório.');
    }

    $idvendedor = (int)($dados_evento['idvendedor'] ?? (getenv('ME_DEFAULT_SELLER_ID') ?: 0));
    if ($idvendedor <= 0) {
        throw new Exception('idvendedor é obrigatório. Mapeie vendedores em Logística > Conexão ou configure ME_DEFAULT_SELLER_ID no ambiente.');
    }

    $payload = [
        'idcliente' => $idcliente,
        'tipoevento' => $tipoevento,
        'nomeevento' => (string)($dados_evento['nome_evento'] ?? ''),
        'dataevento' => (string)($dados_evento['data_evento'] ?? ''),
        'idvendedor' => $idvendedor,
        'horaevento' => (string)($dados_evento['hora_inicio'] ?? ''),
        'horaeventofim' => (string)($dados_evento['hora_termino'] ?? ''),
        'idlocalevento' => (int)$me_local_id,
        'localevento' => $unidade_nome,
    ];

    if (!empty($dados_evento['nconvidados'])) {
        $payload['nconvidados'] = (int)$dados_evento['nconvidados'];
    }
    if (!empty($dados_evento['comoconheceu'])) {
        $cc_id = vendas_me_resolver_como_conheceu_id($dados_evento['comoconheceu']);
        if ($cc_id !== null) {
            // ME exige ID numérico
            $payload['comoconheceu'] = (int)$cc_id;
        } else {
            // Não bloquear criação do evento (campo é opcional na doc), mas registrar no texto.
            $cc_txt = trim((string)$dados_evento['comoconheceu']);
            if ($cc_txt !== '') {
                $payload['observacao'] = trim(((string)($payload['observacao'] ?? '')) . "\nComo conheceu (Painel): " . $cc_txt);
            }
        }
    }
    if (!empty($dados_evento['observacao'])) {
        $obs_in = trim((string)$dados_evento['observacao']);
        if ($obs_in !== '') {
            $obs_cur = trim((string)($payload['observacao'] ?? ''));
            $payload['observacao'] = $obs_cur !== '' ? ($obs_cur . "\n" . $obs_in) : $obs_in;
        }
    }

    $resp = vendas_me_request('POST', '/api/v1/events', [], $payload);
    if (!$resp['ok']) {
        throw new Exception('Erro ao criar evento na ME: ' . ($resp['error'] ?? 'erro desconhecido'));
    }

    $data = $resp['data'];
    $evento = $data['data'] ?? $data;
    $evento_id = isset($evento['id']) ? (int)$evento['id'] : null;
    if (!$evento_id) {
        throw new Exception('ME não retornou ID do evento na criação.');
    }

    return ['id' => $evento_id, 'data' => $evento, 'payload' => $payload];
}

/**
 * Listar tipos de evento na ME (com cache em sessão)
 */
function vendas_me_listar_tipos_evento(): array {
    // Usar cache em sessão (5 minutos)
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $cache_key = 'vendas_me_tipos_evento';
    $cache_time_key = 'vendas_me_tipos_evento_time';
    
    if (isset($_SESSION[$cache_key]) && isset($_SESSION[$cache_time_key])) {
        $cache_time = $_SESSION[$cache_time_key];
        if (time() - $cache_time < 300) { // 5 minutos
            return $_SESSION[$cache_key];
        }
    }
    
    $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
    $key = getenv('ME_API_KEY') ?: ME_API_KEY;
    
    if (!$base || !$key) {
        return [];
    }
    
    $resp = vendas_me_request('GET', '/api/v1/eventtype');
    if (!$resp['ok']) {
        return [];
    }
    $data = $resp['data'];
    $tipos = $data['data'] ?? $data ?? [];
    if (!is_array($tipos)) $tipos = [];
    
    // Salvar no cache da sessão
    $_SESSION[$cache_key] = $tipos;
    $_SESSION[$cache_time_key] = time();
    
    return $tipos;
}

/**
 * Listar vendedores na ME (sellers) com cache em sessão.
 * Endpoint: GET /api/v1/seller
 */
function vendas_me_listar_vendedores(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $cache_key = 'vendas_me_vendedores';
    $cache_time_key = 'vendas_me_vendedores_time';

    if (isset($_SESSION[$cache_key], $_SESSION[$cache_time_key])) {
        if (time() - (int)$_SESSION[$cache_time_key] < 300) { // 5 min
            return is_array($_SESSION[$cache_key]) ? $_SESSION[$cache_key] : [];
        }
    }

    $resp = vendas_me_request('GET', '/api/v1/seller');
    if (!$resp['ok']) {
        return [];
    }

    $raw = $resp['data'];
    $items = null;
    if (is_array($raw)) {
        if (array_keys($raw) === range(0, count($raw) - 1)) {
            $items = $raw;
        } else {
            $items = $raw['data'] ?? null;
        }
    }
    if (!is_array($items)) $items = [];

    $vendedores = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = $item['id'] ?? $item['idvendedor'] ?? null;
        $nome = $item['nome'] ?? $item['vendedor'] ?? null;
        if ($id === null || $nome === null) continue;
        $vendedores[] = [
            'id' => (int)$id,
            'nome' => (string)$nome,
        ];
    }

    usort($vendedores, fn($a, $b) => strcmp(mb_strtolower($a['nome']), mb_strtolower($b['nome'])));

    $_SESSION[$cache_key] = $vendedores;
    $_SESSION[$cache_time_key] = time();

    return $vendedores;
}
