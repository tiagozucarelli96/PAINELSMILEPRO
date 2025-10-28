<?php
// create_all_demandas_tables.php - Criar todas as tabelas de demandas
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
    
    // 1. Criar tabela demandas principal
    $sql = "
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
    )";
    $pdo->exec($sql);
    $resultados[] = "✅ Tabela demandas criada";
    
    // 2. Criar índices para demandas
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_responsavel_status ON demandas (responsavel_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_prazo ON demandas (prazo)");
    $resultados[] = "✅ Índices de demandas criados";
    
    // 3. Criar tabela demandas_comentarios
    $sql = "
    CREATE TABLE IF NOT EXISTS demandas_comentarios (
        id SERIAL PRIMARY KEY,
        demanda_id INTEGER NOT NULL REFERENCES demandas(id) ON DELETE CASCADE,
        autor_id INTEGER NOT NULL,
        mensagem TEXT NOT NULL,
        data_criacao TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )";
    $pdo->exec($sql);
    $resultados[] = "✅ Tabela demandas_comentarios criada";
    
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_comentarios_demanda ON demandas_comentarios (demanda_id, data_criacao)");
    
    // 4. Criar tabela demandas_arquivos
    $sql = "
    CREATE TABLE IF NOT EXISTS demandas_arquivos (
        id SERIAL PRIMARY KEY,
        demanda_id INTEGER NOT NULL REFERENCES demandas(id) ON DELETE CASCADE,
        nome_original TEXT NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        tamanho_bytes BIGINT NOT NULL,
        chave_storage TEXT NOT NULL,
        criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )";
    $pdo->exec($sql);
    $resultados[] = "✅ Tabela demandas_arquivos criada";
    
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_arquivos_demanda ON demandas_arquivos (demanda_id, criado_em)");
    
    // 5. Criar tabela demandas_modelos
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
    
    // 6. Criar tabela demandas_modelos_log
    $sql = "
    CREATE TABLE IF NOT EXISTS demandas_modelos_log (
        id SERIAL PRIMARY KEY,
        modelo_id INTEGER NOT NULL REFERENCES demandas_modelos(id) ON DELETE CASCADE,
        gerado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        demanda_id INTEGER REFERENCES demandas(id)
    )";
    $pdo->exec($sql);
    $resultados[] = "✅ Tabela demandas_modelos_log criada";
    
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_modelos_log_modelo_data ON demandas_modelos_log (modelo_id, gerado_em)");
    
    // 7. Inserir dados de exemplo
    // Verificar se já existe usuário admin
    $stmt = $pdo->query("SELECT id FROM usuarios WHERE id = 1 LIMIT 1");
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios (id, nome, email, perfil, ativo) 
            VALUES (1, 'Admin', 'admin@admin.com', 'ADMIN', true)
            ON CONFLICT (id) DO NOTHING
        ");
        $stmt->execute();
        $resultados[] = "✅ Usuário admin criado";
    }
    
    // Inserir modelo de exemplo
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
    
    // Inserir demandas de exemplo
    $demandas_exemplo = [
        [
            'descricao' => 'Revisar relatório mensal de vendas',
            'prazo' => date('Y-m-d', strtotime('+3 days')),
            'responsavel_id' => 1,
            'whatsapp' => '(11) 99999-9999'
        ],
        [
            'descricao' => 'Atualizar base de dados de clientes',
            'prazo' => date('Y-m-d', strtotime('+1 week')),
            'responsavel_id' => 1,
            'whatsapp' => '(11) 88888-8888'
        ]
    ];
    
    foreach ($demandas_exemplo as $i => $demanda) {
        $stmt = $pdo->prepare("
            INSERT INTO demandas (descricao, prazo, responsavel_id, criador_id, whatsapp) 
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT DO NOTHING
        ");
        $stmt->execute([
            $demanda['descricao'],
            $demanda['prazo'],
            $demanda['responsavel_id'],
            1,
            $demanda['whatsapp']
        ]);
    }
    $resultados[] = "✅ Demandas de exemplo inseridas";
    
    // Verificar tabelas criadas
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
        'message' => 'Todas as tabelas de demandas criadas com sucesso',
        'resultados' => $resultados,
        'tabelas' => $tabelas,
        'total_tabelas' => count($tabelas)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
