<?php
// setup_demandas_schema.php - Endpoint para aplicar schema no Railway
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se usuário está logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/conexao.php';

header('Content-Type: application/json');

try {
    $pdo = $GLOBALS['pdo'];
    
    $resultados = [];
    
    // 1. Criar tabela demandas_modelos
    $sql = "
    CREATE TABLE IF NOT EXISTS demandas_modelos (
        id SERIAL PRIMARY KEY,
        titulo VARCHAR(140) NOT NULL,
        descricao_padrao TEXT NOT NULL,
        responsavel_id INTEGER NOT NULL,
        dia_semana INT NOT NULL,
        prazo_offset_dias INT NOT NULL,
        hora_geracao TIME NOT NULL DEFAULT '09:00',
        ativo BOOLEAN NOT NULL DEFAULT TRUE
    )";
    $pdo->exec($sql);
    $resultados[] = "✅ Tabela demandas_modelos criada";
    
    // 2. Criar tabela demandas_modelos_log
    $sql = "
    CREATE TABLE IF NOT EXISTS demandas_modelos_log (
        id SERIAL PRIMARY KEY,
        modelo_id INTEGER NOT NULL REFERENCES demandas_modelos(id) ON DELETE CASCADE,
        gerado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        demanda_id INTEGER REFERENCES demandas(id)
    )";
    $pdo->exec($sql);
    $resultados[] = "✅ Tabela demandas_modelos_log criada";
    
    // 3. Criar índices
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_modelos_log_modelo_data ON demandas_modelos_log (modelo_id, gerado_em)");
    $resultados[] = "✅ Índices criados";
    
    // 4. Inserir modelo de exemplo
    $stmt = $pdo->prepare("
        INSERT INTO demandas_modelos (titulo, descricao_padrao, responsavel_id, dia_semana, prazo_offset_dias) 
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT DO NOTHING
    ");
    $stmt->execute([
        'Relatório Semanal',
        'Preparar relatório semanal de atividades e resultados',
        1, // admin
        1, // Segunda-feira
        2  // Prazo: +2 dias
    ]);
    $resultados[] = "✅ Modelo de exemplo inserido";
    
    // 5. Verificar tabelas
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'demandas%'
        ORDER BY table_name
    ");
    $tabelas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'message' => 'Schema aplicado com sucesso',
        'resultados' => $resultados,
        'tabelas' => $tabelas
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
