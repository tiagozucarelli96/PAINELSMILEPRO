<?php
/**
 * cron_demandas_trello_fixas.php
 * Gera cards automaticamente baseado em demandas fixas configuradas
 * Executar diariamente às 00:00 (horário de Brasília)
 */

// Garantir que não há output buffer interferindo
if (ob_get_level()) {
    ob_end_clean();
}

// Configurar timezone para Brasília
date_default_timezone_set('America/Sao_Paulo');

// Verificar token ANTES de incluir qualquer coisa que possa redirecionar
$cron_token = getenv('CRON_TOKEN') ?: '';
$request_token = $_GET['token'] ?? '';

// Se token estiver configurado e não corresponder, retornar erro imediatamente
if (!empty($cron_token) && $request_token !== $cron_token) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

require_once __DIR__ . '/conexao.php';

// Headers devem ser enviados antes de qualquer output (garantir que não há redirecionamento)
header('Content-Type: application/json; charset=utf-8');

// Prevenir qualquer redirecionamento que possa vir de conexao.php ou outros includes
if (!isset($_GET['token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Token não fornecido']);
    exit;
}

try {
    $pdo = $GLOBALS['pdo'];
    $hoje = new DateTime();
    $dia_semana = (int)$hoje->format('w'); // 0=domingo, 6=sábado
    $dia_mes = (int)$hoje->format('j');
    
    // Buscar demandas fixas ativas
    $stmt = $pdo->query("
        SELECT df.*, db.nome as board_nome, dl.nome as lista_nome
        FROM demandas_fixas df
        JOIN demandas_boards db ON db.id = df.board_id
        JOIN demandas_listas dl ON dl.id = df.lista_id
        WHERE df.ativo = TRUE
    ");
    $fixas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $gerados = 0;
    $erros = [];
    
    foreach ($fixas as $fixa) {
        // Verificar se deve gerar hoje
        $deve_gerar = false;
        
        switch ($fixa['periodicidade']) {
            case 'diaria':
                $deve_gerar = true;
                break;
                
            case 'semanal':
                if ($fixa['dia_semana'] === $dia_semana) {
                    $deve_gerar = true;
                }
                break;
                
            case 'mensal':
                if ($fixa['dia_mes'] === $dia_mes) {
                    $deve_gerar = true;
                }
                break;
        }
        
        if (!$deve_gerar) continue;
        
        // Verificar se já foi gerado hoje
        $stmt_check = $pdo->prepare("
            SELECT id FROM demandas_fixas_log 
            WHERE demanda_fixa_id = :fixa_id 
            AND dia_gerado = CURRENT_DATE
        ");
        $stmt_check->execute([':fixa_id' => $fixa['id']]);
        
        if ($stmt_check->fetch()) {
            continue; // Já foi gerado hoje
        }
        
        // Buscar posição máxima na lista
        $stmt_pos = $pdo->prepare("
            SELECT COALESCE(MAX(posicao), 0) + 1 as nova_pos 
            FROM demandas_cards 
            WHERE lista_id = :lista_id
        ");
        $stmt_pos->execute([':lista_id' => $fixa['lista_id']]);
        $posicao = (int)$stmt_pos->fetch(PDO::FETCH_ASSOC)['nova_pos'];
        
        // Criar card
        try {
            $pdo->beginTransaction();
            
            $stmt_card = $pdo->prepare("
                INSERT INTO demandas_cards 
                (lista_id, titulo, descricao, status, prioridade, posicao, criador_id)
                VALUES (:lista_id, :titulo, :descricao, 'pendente', 'media', :posicao, 1)
                RETURNING id
            ");
            $stmt_card->execute([
                ':lista_id' => $fixa['lista_id'],
                ':titulo' => $fixa['titulo'],
                ':descricao' => $fixa['descricao'],
                ':posicao' => $posicao
            ]);
            
            $card = $stmt_card->fetch(PDO::FETCH_ASSOC);
            $card_id = (int)$card['id'];
            
            // Registrar no log
            $stmt_log = $pdo->prepare("
                INSERT INTO demandas_fixas_log 
                (demanda_fixa_id, card_id, dia_gerado)
                VALUES (:fixa_id, :card_id, CURRENT_DATE)
            ");
            $stmt_log->execute([
                ':fixa_id' => $fixa['id'],
                ':card_id' => $card_id
            ]);
            
            $pdo->commit();
            $gerados++;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erros[] = [
                'fixa_id' => $fixa['id'],
                'titulo' => $fixa['titulo'],
                'erro' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'gerados' => $gerados,
        'total_fixas' => count($fixas),
        'erros' => $erros
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

