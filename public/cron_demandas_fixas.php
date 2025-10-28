<?php
// cron_demandas_fixas.php - Gerador diário de demandas fixas
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/conexao.php';

try {
    $pdo = $GLOBALS['pdo'];
    $hoje = date('Y-m-d');
    
    // Verificar se as tabelas existem
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name IN ('demandas_modelos', 'demandas_modelos_log')
    ");
    $tabelas_existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('demandas', $tabelas_existentes)) {
        // Criar tabela demandas primeiro
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS demandas (
                id SERIAL PRIMARY KEY,
                descricao TEXT NOT NULL,
                prazo DATE NOT NULL,
                responsavel_id INTEGER NOT NULL,
                criador_id INTEGER NOT NULL,
                whatsapp VARCHAR(32),
                status TEXT NOT NULL DEFAULT 'pendente',
                data_criacao TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                data_conclusao TIMESTAMPTZ
            )
        ");
    }
    
    if (!in_array('demandas_modelos', $tabelas_existentes)) {
        // Criar tabela demandas_modelos
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS demandas_modelos (
                id SERIAL PRIMARY KEY,
                titulo VARCHAR(140) NOT NULL,
                descricao_padrao TEXT NOT NULL,
                responsavel_id INTEGER NOT NULL,
                dia_semana INT NOT NULL,
                prazo_offset_dias INT NOT NULL,
                hora_geracao TIME NOT NULL DEFAULT '09:00',
                ativo BOOLEAN NOT NULL DEFAULT TRUE
            )
        ");
        
        // Inserir modelo de exemplo
        $stmt = $pdo->prepare("
            INSERT INTO demandas_modelos (titulo, descricao_padrao, responsavel_id, dia_semana, prazo_offset_dias) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'Relatório Semanal',
            'Preparar relatório semanal de atividades e resultados',
            1, // admin
            1, // Segunda-feira
            2  // Prazo: +2 dias
        ]);
    }
    
    if (!in_array('demandas_modelos_log', $tabelas_existentes)) {
        // Criar tabela demandas_modelos_log
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS demandas_modelos_log (
                id SERIAL PRIMARY KEY,
                modelo_id INTEGER NOT NULL REFERENCES demandas_modelos(id) ON DELETE CASCADE,
                gerado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                demanda_id INTEGER REFERENCES demandas(id)
            )
        ");
    }
    
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
            'erros' => $erros,
            'debug' => [
                'dia_semana' => $diaSemana,
                'hora_atual' => $horaAtual,
                'tabelas_criadas' => !in_array('demandas_modelos', $tabelas_existentes)
            ]
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}