<?php
// fix_agenda_schema.php — Corrigir schema da tabela agenda_eventos
require_once __DIR__ . '/conexao.php';

try {
    // Verificar se as colunas existem
    $stmt = $GLOBALS['pdo']->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'agenda_eventos' 
        AND column_name IN ('inicio', 'fim', 'data_inicio', 'data_fim')
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Colunas encontradas: " . implode(', ', $columns) . "\n";
    
    // Se não existem inicio e fim, mas existem data_inicio e data_fim
    if (!in_array('inicio', $columns) && in_array('data_inicio', $columns)) {
        echo "Renomeando colunas...\n";
        
        // Renomear colunas
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            RENAME COLUMN data_inicio TO inicio
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            RENAME COLUMN data_fim TO fim
        ");
        
        echo "Colunas renomeadas com sucesso!\n";
    }
    
    // Verificar se existem outras colunas necessárias
    $stmt = $GLOBALS['pdo']->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'agenda_eventos'
    ");
    $all_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "\nColunas atuais na tabela: " . implode(', ', $all_columns) . "\n";
    
    // Adicionar colunas faltantes se necessário
    if (!in_array('responsavel_usuario_id', $all_columns)) {
        echo "Adicionando colunas faltantes...\n";
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS responsavel_usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS criado_por_usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS lembrete_minutos INT DEFAULT 60
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'agendado' CHECK (status IN ('agendado', 'realizado', 'no_show', 'cancelado'))
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS compareceu BOOLEAN DEFAULT FALSE
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS fechou_contrato BOOLEAN DEFAULT FALSE
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS fechou_ref VARCHAR(255)
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS participantes JSONB DEFAULT '[]'
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS cor_evento VARCHAR(7)
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP DEFAULT NOW()
        ");
        
        $GLOBALS['pdo']->exec("
            ALTER TABLE agenda_eventos 
            ADD COLUMN IF NOT EXISTS atualizado_em TIMESTAMP DEFAULT NOW()
        ");
        
        echo "Colunas adicionadas com sucesso!\n";
    }
    
    echo "\n✅ Schema corrigido!\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
?>

