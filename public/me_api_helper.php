<?php
// me_api_helper.php
// Helper para integração com API ME Eventos
// DEPRECATED: manter apenas para compatibilidade; não usar no novo módulo Logística.

/**
 * Buscar eventos dos próximos dois finais de semana
 */
function me_buscar_eventos_proximos_finais_semana(): array {
    try {
        // Calcular próximos dois finais de semana
        $hoje = new DateTime();
        $proximos_finais = [];
        
        // Encontrar próximo sábado
        $proximo_sabado = clone $hoje;
        $proximo_sabado->modify('next saturday');
        
        // Adicionar próximos 2 sábados e domingos
        for ($i = 0; $i < 2; $i++) {
            $sabado = clone $proximo_sabado;
            $sabado->modify("+$i weeks");
            $proximos_finais[] = $sabado->format('Y-m-d');
            
            $domingo = clone $sabado;
            $domingo->modify('+1 day');
            $proximos_finais[] = $domingo->format('Y-m-d');
        }
        
        // Buscar eventos na base local primeiro
        $eventos_locais = me_buscar_eventos_locais($proximos_finais);
        
        // Buscar eventos na API ME se necessário
        $eventos_api = me_buscar_eventos_api($proximos_finais);
        
        // Combinar e deduplicar
        $todos_eventos = array_merge($eventos_locais, $eventos_api);
        $eventos_unicos = [];
        
        foreach ($todos_eventos as $evento) {
            $chave = $evento['data_evento'] . '_' . $evento['nome'];
            if (!isset($eventos_unicos[$chave])) {
                $eventos_unicos[$chave] = $evento;
            }
        }
        
        return array_values($eventos_unicos);
        
    } catch (Exception $e) {
        error_log("Erro ao buscar eventos ME: " . $e->getMessage());
        return [];
    }
}

/**
 * Buscar eventos na base local
 */
function me_buscar_eventos_locais(array $datas): array {
    global $pdo;
    
    if (!$pdo) {
        require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
    }
    
    $placeholders = str_repeat('?,', count($datas) - 1) . '?';
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            data_evento,
            nome as nome_evento,
            convidados,
            observacoes,
            'local' as origem
        FROM lc_listas_eventos 
        WHERE data_evento IN ($placeholders)
        ORDER BY data_evento, nome
    ");
    
    $stmt->execute($datas);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Buscar eventos na API ME Eventos
 */
function me_buscar_eventos_api(array $datas): array {
    try {
        // URL da API ME Eventos
        $api_url = 'https://meeventos.com.br/api/eventos';
        
        // Parâmetros da requisição
        $params = [
            'datas' => implode(',', $datas),
            'formato' => 'json',
            'incluir_cardapio' => 'true'
        ];
        
        // Fazer requisição HTTP
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => [
                    'User-Agent: PainelSmilePRO/1.0',
                    'Accept: application/json'
                ]
            ]
        ]);
        
        $url_completa = $api_url . '?' . http_build_query($params);
        $response = file_get_contents($url_completa, false, $context);
        
        if ($response === false) {
            throw new Exception('Falha na requisição à API ME Eventos');
        }
        
        $dados = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Resposta inválida da API: ' . json_last_error_msg());
        }
        
        if (!isset($dados['eventos'])) {
            return [];
        }
        
        // Processar eventos da API
        $eventos = [];
        foreach ($dados['eventos'] as $evento_api) {
            $eventos[] = [
                'id' => 'me_' . ($evento_api['id'] ?? uniqid()),
                'data_evento' => $evento_api['data'] ?? '',
                'nome_evento' => $evento_api['nome'] ?? 'Evento ME',
                'convidados' => (int)($evento_api['convidados'] ?? 0),
                'observacoes' => $evento_api['observacoes'] ?? '',
                'origem' => 'api',
                'cardapio' => $evento_api['cardapio'] ?? []
            ];
        }
        
        return $eventos;
        
    } catch (Exception $e) {
        error_log("Erro na API ME Eventos: " . $e->getMessage());
        return [];
    }
}

/**
 * Sincronizar evento da API para base local
 */
function me_sincronizar_evento(array $evento_api): int {
    global $pdo;
    
    if (!$pdo) {
        require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
    }
    
    try {
        // Verificar se já existe
        $stmt = $pdo->prepare("
            SELECT id FROM lc_listas_eventos 
            WHERE data_evento = :data AND nome = :nome
        ");
        $stmt->execute([
            ':data' => $evento_api['data_evento'],
            ':nome' => $evento_api['nome_evento']
        ]);
        
        $existente = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existente) {
            return (int)$existente['id'];
        }
        
        // Inserir novo evento
        $stmt = $pdo->prepare("
            INSERT INTO lc_listas_eventos 
            (data_evento, nome, convidados, observacoes, origem_me)
            VALUES (:data_evento, :nome, :convidados, :observacoes, 'api')
        ");
        
        $stmt->execute([
            ':data_evento' => $evento_api['data_evento'],
            ':nome' => $evento_api['nome_evento'],
            ':convidados' => $evento_api['convidados'],
            ':observacoes' => $evento_api['observacoes']
        ]);
        
        $evento_id = $pdo->lastInsertId();
        
        // Sincronizar cardápio se disponível
        if (!empty($evento_api['cardapio'])) {
            me_sincronizar_cardapio($evento_id, $evento_api['cardapio']);
        }
        
        return $evento_id;
        
    } catch (Exception $e) {
        error_log("Erro ao sincronizar evento ME: " . $e->getMessage());
        return 0;
    }
}

/**
 * Sincronizar cardápio do evento
 */
function me_sincronizar_cardapio(int $evento_id, array $cardapio): void {
    global $pdo;
    
    if (!$pdo) {
        require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
    }
    
    try {
        foreach ($cardapio as $item) {
            // Buscar ficha por nome
            $stmt = $pdo->prepare("
                SELECT id FROM lc_fichas 
                WHERE nome = :nome AND ativo = true
                LIMIT 1
            ");
            $stmt->execute([':nome' => $item['ficha_nome']]);
            $ficha = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$ficha) {
                // Criar ficha básica se não existir
                $ficha_id = me_criar_ficha_basica($item['ficha_nome']);
            } else {
                $ficha_id = (int)$ficha['id'];
            }
            
            if ($ficha_id > 0) {
                // Inserir no cardápio do evento
                $stmt = $pdo->prepare("
                    INSERT INTO lc_evento_cardapio 
                    (evento_id, ficha_id, consumo_pessoa_override, ativo)
                    VALUES (:evento_id, :ficha_id, :consumo_override, true)
                    ON CONFLICT (evento_id, ficha_id) DO UPDATE SET
                    consumo_pessoa_override = :consumo_override,
                    ativo = true
                ");
                
                $stmt->execute([
                    ':evento_id' => $evento_id,
                    ':ficha_id' => $ficha_id,
                    ':consumo_override' => $item['consumo_pessoa'] ?? null
                ]);
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao sincronizar cardápio: " . $e->getMessage());
    }
}

/**
 * Criar ficha básica se não existir
 */
function me_criar_ficha_basica(string $nome_ficha): int {
    global $pdo;
    
    if (!$pdo) {
        require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO lc_fichas 
            (nome, descricao, consumo_pessoa, ativo, criado_por)
            VALUES (:nome, :descricao, 1.0, true, :criado_por)
        ");
        
        $stmt->execute([
            ':nome' => $nome_ficha,
            ':descricao' => "Ficha criada automaticamente via ME Eventos",
            ':criado_por' => $_SESSION['usuario_id'] ?? 1
        ]);
        
        return (int)$pdo->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Erro ao criar ficha básica: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calcular demanda baseada em eventos ME
 */
function me_calcular_demanda_eventos(PDO $pdo, int $insumo_id, int $dias): float {
    // Buscar eventos dos próximos dois finais de semana
    $eventos = me_buscar_eventos_proximos_finais_semana();
    
    if (empty($eventos)) {
        return 0;
    }
    
    $demanda_total = 0;
    
    foreach ($eventos as $evento) {
        // Sincronizar evento se veio da API
        if ($evento['origem'] === 'api') {
            $evento_id = me_sincronizar_evento($evento);
            if ($evento_id <= 0) continue;
        } else {
            $evento_id = (int)$evento['id'];
        }
        
        // Buscar cardápio do evento
        $stmt = $pdo->prepare("
            SELECT ec.ficha_id, ec.consumo_pessoa_override
            FROM lc_evento_cardapio ec
            WHERE ec.evento_id = :evento_id AND ec.ativo = true
        ");
        $stmt->execute([':evento_id' => $evento_id]);
        $cardapio = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($cardapio as $item_cardapio) {
            // Carregar ficha
            $pack = lc_fetch_ficha($pdo, $item_cardapio['ficha_id']);
            if (!$pack) continue;
            
            // Aplicar override se existir
            if ($item_cardapio['consumo_pessoa_override']) {
                $pack['ficha']['consumo_pessoa'] = (float)$item_cardapio['consumo_pessoa_override'];
            }
            
            // Explodir ficha para o evento
            $res = lc_explode_ficha_para_evento($pack, (int)$evento['convidados']);
            
            // Somar compras (não encomendas)
            if (isset($res['compras'])) {
                foreach ($res['compras'] as $compra) {
                    if ($compra['insumo_id'] == $insumo_id) {
                        $demanda_total += (float)$compra['qtd'];
                    }
                }
            }
        }
    }
    
    return $demanda_total;
}
?>
