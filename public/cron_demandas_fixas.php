<?php
// cron_demandas_fixas.php - Gerador diário de demandas fixas
date_default_timezone_set('America/Sao_Paulo');

// Verificar token de segurança
$cronToken = getenv('CRON_TOKEN');
$providedToken = $_SERVER['HTTP_X_CRON_TOKEN'] ?? $_GET['token'] ?? '';

if (!$cronToken || $providedToken !== $cronToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

require_once __DIR__ . '/conexao.php';

try {
    $pdo = $GLOBALS['pdo'];
    $hoje = date('Y-m-d');
    $diaSemana = (int)date('w'); // 0=domingo, 6=sábado
    $horaAtual = date('H:i');
    
    // Buscar modelos ativos para hoje
    $stmt = $pdo->prepare("
        SELECT * FROM demandas_modelos 
        WHERE ativo = TRUE 
        AND dia_semana = ? 
        AND hora_geracao <= ?
    ");
    $stmt->execute([$diaSemana, $horaAtual]);
    $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $geradas = 0;
    $erros = [];
    
    foreach ($modelos as $modelo) {
        // Verificar se já foi gerada hoje
        $stmt = $pdo->prepare("
            SELECT id FROM demandas_modelos_log 
            WHERE modelo_id = ? 
            AND DATE(gerado_em) = ?
        ");
        $stmt->execute([$modelo['id'], $hoje]);
        
        if ($stmt->fetch()) {
            continue; // Já gerada hoje
        }
        
        // Calcular prazo
        $prazo = date('Y-m-d', strtotime("+{$modelo['prazo_offset_dias']} days"));
        
        // Criar demanda
        $stmt = $pdo->prepare("
            INSERT INTO demandas (descricao, prazo, responsavel_id, criador_id, status) 
            VALUES (?, ?, ?, ?, 'pendente')
            RETURNING id
        ");
        $stmt->execute([
            $modelo['descricao_padrao'],
            $prazo,
            $modelo['responsavel_id'],
            1 // Sistema como criador
        ]);
        
        $demandaId = $stmt->fetchColumn();
        
        // Registrar no log
        $stmt = $pdo->prepare("
            INSERT INTO demandas_modelos_log (modelo_id, demanda_id) 
            VALUES (?, ?)
        ");
        $stmt->execute([$modelo['id'], $demandaId]);
        
        $geradas++;
    }
    
    // Resposta de sucesso
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'data' => $hoje,
            'modelos_processados' => count($modelos),
            'demandas_geradas' => $geradas,
            'erros' => $erros
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
