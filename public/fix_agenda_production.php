<?php
// fix_agenda_production.php — Corrigir schema da tabela agenda_eventos em PRODUÇÃO
require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=utf-8');

try {
    echo "<h2>🔧 Corrigindo Schema da Tabela agenda_eventos</h2>";
    echo "<pre>";
    
    // Verificar se as colunas existem
    $stmt = $GLOBALS['pdo']->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'agenda_eventos' 
        AND column_name IN ('inicio', 'fim', 'data_inicio', 'data_fim')
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📋 Colunas encontradas: " . implode(', ', $columns) . "\n\n";
    
    // Se não existem inicio e fim, mas existem data_inicio e data_fim
    if (!in_array('inicio', $columns) && in_array('data_inicio', $columns)) {
        echo "⚙️  Renomeando colunas data_inicio/data_fim para inicio/fim...\n";
        
        // Renomear colunas
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            RENAME COLUMN data_inicio TO inicio
        ");
        
        echo "✅ Renomeado: data_inicio → inicio\n";
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            RENAME COLUMN data_fim TO fim
        ");
        
        echo "✅ Renomeado: data_fim → fim\n\n";
    }
    
    // Verificar se existem outras colunas necessárias
    $stmt = $GLOBALS['pdo']->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'agenda_eventos'
    ");
    $all_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📋 Colunas atuais na tabela: " . implode(', ', $all_columns) . "\n\n";
    
    // Adicionar colunas faltantes se necessário
    $required_columns = [
        'responsavel_usuario_id',
        'criado_por_usuario_id',
        'lembrete_minutos',
        'status',
        'compareceu',
        'fechou_contrato',
        'fechou_ref',
        'participantes',
        'cor_evento',
        'criado_em',
        'atualizado_em'
    ];
    
    $missing_columns = array_diff($required_columns, $all_columns);
    
    if (!empty($missing_columns)) {
        echo "📝 Adicionando colunas faltantes...\n\n";
        
        $additions = [
            'responsavel_usuario_id' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS responsavel_usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE",
            'criado_por_usuario_id' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS criado_por_usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE",
            'lembrete_minutos' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS lembrete_minutos INT DEFAULT 60",
            'status' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'agendado'",
            'compareceu' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS compareceu BOOLEAN DEFAULT FALSE",
            'fechou_contrato' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS fechou_contrato BOOLEAN DEFAULT FALSE",
            'fechou_ref' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS fechou_ref VARCHAR(255)",
            'participantes' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS participantes JSONB DEFAULT '[]'",
            'cor_evento' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS cor_evento VARCHAR(7)",
            'criado_em' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP DEFAULT NOW()",
            'atualizado_em' => "ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS atualizado_em TIMESTAMP DEFAULT NOW()"
        ];
        
        foreach ($missing_columns as $col) {
            if (isset($additions[$col])) {
                $GLOBALS['pdo']->exec($additions[$col]);
                echo "✅ Adicionado: $col\n";
            }
        }
    } else {
        echo "✅ Todas as colunas necessárias já existem!\n";
    }
    
    // Mostrar schema final
    echo "\n📊 Schema Final da Tabela agenda_eventos:\n";
    $stmt = $GLOBALS['pdo']->query("
        SELECT column_name, data_type, is_nullable
        FROM information_schema.columns 
        WHERE table_name = 'agenda_eventos'
        ORDER BY ordinal_position
    ");
    $schema = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n";
    foreach ($schema as $col) {
        echo "  - {$col['column_name']} ({$col['data_type']})\n";
    }
    
    echo "\n✅ Correção concluída com sucesso!\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>

