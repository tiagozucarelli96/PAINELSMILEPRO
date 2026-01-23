<?php
/**
 * vendas_me_helper.php
 * Helper para integração com API ME Eventos no módulo de Vendas
 */

require_once __DIR__ . '/me_config.php';
require_once __DIR__ . '/conexao.php';

/**
 * Buscar cliente na ME por CPF, email ou telefone
 */
function vendas_me_buscar_cliente(string $cpf = '', string $email = '', string $telefone = '', string $nome = ''): array {
    $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
    $key = getenv('ME_API_KEY') ?: ME_API_KEY;
    
    if (!$base || !$key) {
        throw new Exception('ME_BASE_URL/ME_API_KEY não configurados');
    }
    
    $clientes_encontrados = [];
    
    // Prioridade 1: Buscar por CPF (match forte)
    if (!empty($cpf)) {
        $cpf_limpo = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf_limpo) === 11) {
            $url = rtrim($base, '/') . '/api/v1/events';
            $params = ['search' => $cpf_limpo];
            $url .= '?' . http_build_query($params);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $key,
                    'Content-Type: application/json',
                    'Accept: application/json'
                ]
            ]);
            
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($code === 200) {
                $data = json_decode($resp, true);
                $events = $data['data'] ?? [];
                
                foreach ($events as $event) {
                    // Extrair dados do cliente do evento
                    $cliente_data = $event['cliente'] ?? [];
                    if (!empty($cliente_data)) {
                        $clientes_encontrados[] = [
                            'match_type' => 'cpf',
                            'match_strength' => 'forte',
                            'cliente' => $cliente_data,
                            'evento' => $event
                        ];
                    }
                }
            }
        }
    }
    
    // Prioridade 2: Buscar por email/telefone (match forte se CPF não encontrou)
    if (empty($clientes_encontrados)) {
        $search_term = !empty($email) ? $email : (!empty($telefone) ? $telefone : '');
        if (!empty($search_term)) {
            $url = rtrim($base, '/') . '/api/v1/events';
            $params = ['search' => $search_term];
            $url .= '?' . http_build_query($params);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . $key,
                    'Content-Type: application/json',
                    'Accept: application/json'
                ]
            ]);
            
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($code === 200) {
                $data = json_decode($resp, true);
                $events = $data['data'] ?? [];
                
                foreach ($events as $event) {
                    $cliente_data = $event['cliente'] ?? [];
                    if (!empty($cliente_data)) {
                        $clientes_encontrados[] = [
                            'match_type' => !empty($email) ? 'email' : 'telefone',
                            'match_strength' => 'forte',
                            'cliente' => $cliente_data,
                            'evento' => $event
                        ];
                    }
                }
            }
        }
    }
    
    // Prioridade 3: Buscar por nome (match fraco - apenas sugestão)
    if (empty($clientes_encontrados) && !empty($nome) && strlen($nome) >= 3) {
        $url = rtrim($base, '/') . '/api/v1/events';
        $params = ['search' => $nome];
        $url .= '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $key,
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($code === 200) {
            $data = json_decode($resp, true);
            $events = $data['data'] ?? [];
            
            foreach ($events as $event) {
                $cliente_data = $event['cliente'] ?? [];
                if (!empty($cliente_data)) {
                    $clientes_encontrados[] = [
                        'match_type' => 'nome',
                        'match_strength' => 'fraco',
                        'cliente' => $cliente_data,
                        'evento' => $event
                    ];
                }
            }
        }
    }
    
    return $clientes_encontrados;
}

/**
 * Criar cliente na ME
 */
function vendas_me_criar_cliente(array $dados_cliente): array {
    $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
    $key = getenv('ME_API_KEY') ?: ME_API_KEY;
    
    if (!$base || !$key) {
        throw new Exception('ME_BASE_URL/ME_API_KEY não configurados');
    }
    
    $url = rtrim($base, '/') . '/api/v1/clients';
    
    $payload = [
        'nome' => $dados_cliente['nome'] ?? '',
        'cpf' => preg_replace('/\D/', '', $dados_cliente['cpf'] ?? ''),
        'email' => $dados_cliente['email'] ?? '',
        'telefone' => $dados_cliente['telefone'] ?? '',
    ];
    
    if (!empty($dados_cliente['endereco'])) {
        $payload['endereco'] = $dados_cliente['endereco'];
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('Erro cURL: ' . $error);
    }
    
    if ($code < 200 || $code >= 300) {
        $error_data = json_decode($resp, true);
        $error_msg = $error_data['message'] ?? 'Erro HTTP ' . $code;
        throw new Exception('Erro ao criar cliente na ME: ' . $error_msg);
    }
    
    $data = json_decode($resp, true);
    return $data['data'] ?? $data;
}

/**
 * Atualizar cliente na ME
 */
function vendas_me_atualizar_cliente(int $client_id, array $dados_cliente): array {
    $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
    $key = getenv('ME_API_KEY') ?: ME_API_KEY;
    
    if (!$base || !$key) {
        throw new Exception('ME_BASE_URL/ME_API_KEY não configurados');
    }
    
    $url = rtrim($base, '/') . '/api/v1/clients/' . $client_id;
    
    $payload = [];
    if (isset($dados_cliente['nome'])) $payload['nome'] = $dados_cliente['nome'];
    if (isset($dados_cliente['email'])) $payload['email'] = $dados_cliente['email'];
    if (isset($dados_cliente['telefone'])) $payload['telefone'] = $dados_cliente['telefone'];
    if (isset($dados_cliente['endereco'])) $payload['endereco'] = $dados_cliente['endereco'];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('Erro cURL: ' . $error);
    }
    
    if ($code < 200 || $code >= 300) {
        $error_data = json_decode($resp, true);
        $error_msg = $error_data['message'] ?? 'Erro HTTP ' . $code;
        throw new Exception('Erro ao atualizar cliente na ME: ' . $error_msg);
    }
    
    $data = json_decode($resp, true);
    return $data['data'] ?? $data;
}

/**
 * Buscar eventos na ME por data e unidade/local
 */
function vendas_me_buscar_eventos(string $data, string $unidade): array {
    $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
    $key = getenv('ME_API_KEY') ?: ME_API_KEY;
    
    if (!$base || !$key) {
        throw new Exception('ME_BASE_URL/ME_API_KEY não configurados');
    }
    
    // Buscar eventos do dia
    $url = rtrim($base, '/') . '/api/v1/events';
    $params = ['date' => $data];
    $url .= '?' . http_build_query($params);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200) {
        error_log("Erro ao buscar eventos na ME: HTTP $code");
        return [];
    }
    
    $data = json_decode($resp, true);
    $events = $data['data'] ?? [];
    
    // Filtrar por unidade/local
    $eventos_filtrados = [];
    foreach ($events as $event) {
        $event_local = $event['local'] ?? $event['unidade'] ?? '';
        if (stripos($event_local, $unidade) !== false || stripos($unidade, $event_local) !== false) {
            $eventos_filtrados[] = $event;
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
    
    // Regras de distância mínima por unidade
    $regras = [
        'Lisbon' => 2 * 3600, // 2 horas em segundos
        'Diverkids' => 1.5 * 3600, // 1h30 em segundos
        'Garden' => 3 * 3600, // 3 horas em segundos
        'Cristal' => 3 * 3600, // 3 horas em segundos
    ];
    
    $distancia_minima = $regras[$unidade] ?? 2 * 3600; // Padrão: 2 horas
    
    $conflitos = [];
    $hora_inicio_ts = strtotime($hora_inicio);
    $hora_termino_ts = strtotime($hora_termino);
    
    foreach ($eventos as $evento) {
        $evento_inicio = $evento['hora_inicio'] ?? $evento['inicio'] ?? '';
        $evento_termino = $evento['hora_termino'] ?? $evento['fim'] ?? '';
        
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
 */
function vendas_me_criar_evento(array $dados_evento): array {
    $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
    $key = getenv('ME_API_KEY') ?: ME_API_KEY;
    
    if (!$base || !$key) {
        throw new Exception('ME_BASE_URL/ME_API_KEY não configurados');
    }
    
    $url = rtrim($base, '/') . '/api/v1/events';
    
    $payload = [
        'client_id' => $dados_evento['client_id'],
        'tipo_evento_id' => $dados_evento['tipo_evento_id'] ?? null,
        'nome_evento' => $dados_evento['nome_evento'],
        'data_evento' => $dados_evento['data_evento'],
        'hora_inicio' => $dados_evento['hora_inicio'],
        'hora_termino' => $dados_evento['hora_termino'],
        'local' => $dados_evento['local'] ?? $dados_evento['unidade'],
    ];
    
    if (!empty($dados_evento['observacoes'])) {
        $payload['observacoes'] = $dados_evento['observacoes'];
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('Erro cURL: ' . $error);
    }
    
    if ($code < 200 || $code >= 300) {
        $error_data = json_decode($resp, true);
        $error_msg = $error_data['message'] ?? 'Erro HTTP ' . $code;
        throw new Exception('Erro ao criar evento na ME: ' . $error_msg);
    }
    
    $data = json_decode($resp, true);
    return $data['data'] ?? $data;
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
    
    $url = rtrim($base, '/') . '/api/v1/event-types';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200) {
        return [];
    }
    
    $data = json_decode($resp, true);
    $tipos = $data['data'] ?? [];
    
    // Salvar no cache da sessão
    $_SESSION[$cache_key] = $tipos;
    $_SESSION[$cache_time_key] = time();
    
    return $tipos;
}
