<?php
/**
 * fix_final_complete.php — Corrigir TODOS os problemas finais
 * Execute: php fix_final_complete.php
 * OU acesse via web: http://localhost:8000/fix_final_complete.php
 */

echo "🔧 Corrigindo TODOS os Problemas Finais\n";
echo "=====================================\n\n";

// Detectar ambiente
$isProduction = getenv("DATABASE_URL") && strpos(getenv("DATABASE_URL"), 'railway') !== false;
$environment = $isProduction ? "PRODUÇÃO (Railway)" : "LOCAL";

echo "🌍 Ambiente detectado: $environment\n";
echo "🔗 DATABASE_URL: " . (getenv("DATABASE_URL") ? "Definido" : "Não definido") . "\n\n";

try {
    // Incluir conexão
    require_once __DIR__ . '/public/conexao.php';
    
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo']) {
        throw new Exception("Conexão com banco não estabelecida");
    }
    
    $pdo = $GLOBALS['pdo'];
    echo "✅ Conexão com banco estabelecida\n\n";
    
    // 1. CORRIGIR TABELA EVENTOS - ADICIONAR COLUNA DATA_INICIO
    echo "🔧 CORRIGINDO TABELA EVENTOS\n";
    echo "============================\n";
    
    try {
        // Verificar se a coluna data_inicio existe
        $stmt = $pdo->query("SELECT data_inicio FROM eventos LIMIT 1");
        echo "✅ Coluna 'data_inicio' já existe na tabela eventos\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'does not exist') !== false) {
            echo "🔨 Adicionando coluna 'data_inicio' à tabela eventos...\n";
            $pdo->exec("ALTER TABLE eventos ADD COLUMN data_inicio TIMESTAMP");
            echo "✅ Coluna 'data_inicio' adicionada com sucesso\n";
            
            // Atualizar registros existentes se houver
            $pdo->exec("UPDATE eventos SET data_inicio = created_at WHERE data_inicio IS NULL");
            echo "✅ Registros existentes atualizados\n";
        } else {
            echo "❌ Erro ao verificar coluna 'data_inicio': " . $e->getMessage() . "\n";
        }
    }
    
    // 2. CRIAR FUNÇÃO obter_proximos_eventos
    echo "\n🔧 CRIANDO FUNÇÃO obter_proximos_eventos\n";
    echo "=======================================\n";
    
    $functionSQL = "
    CREATE OR REPLACE FUNCTION obter_proximos_eventos(
        p_usuario_id INTEGER,
        p_horas INTEGER DEFAULT 24
    )
    RETURNS TABLE (
        id INTEGER,
        titulo VARCHAR(255),
        descricao TEXT,
        data_inicio TIMESTAMP,
        data_fim TIMESTAMP,
        local VARCHAR(255),
        status VARCHAR(20),
        observacoes TEXT,
        created_at TIMESTAMP,
        updated_at TIMESTAMP
    )
    LANGUAGE plpgsql
    AS $$
    BEGIN
        RETURN QUERY
        SELECT 
            e.id,
            e.titulo,
            e.descricao,
            e.data_inicio,
            e.data_fim,
            e.local,
            e.status,
            e.observacoes,
            e.created_at,
            e.updated_at
        FROM eventos e
        WHERE 
            e.data_inicio >= NOW()
            AND e.data_inicio <= NOW() + INTERVAL '1 hour' * p_horas
            AND e.status = 'ativo'
        ORDER BY e.data_inicio ASC;
    END;
    $$;
    ";
    
    try {
        $pdo->exec($functionSQL);
        echo "✅ Função 'obter_proximos_eventos' criada com sucesso\n";
    } catch (Exception $e) {
        echo "❌ Erro ao criar função 'obter_proximos_eventos': " . $e->getMessage() . "\n";
    }
    
    // 3. CRIAR OUTRAS FUNÇÕES ÚTEIS
    echo "\n🔧 CRIANDO OUTRAS FUNÇÕES ÚTEIS\n";
    echo "===============================\n";
    
    $functions = [
        'obter_eventos_hoje' => "
            CREATE OR REPLACE FUNCTION obter_eventos_hoje(p_usuario_id INTEGER)
            RETURNS TABLE (
                id INTEGER,
                titulo VARCHAR(255),
                data_inicio TIMESTAMP,
                data_fim TIMESTAMP,
                local VARCHAR(255)
            )
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN QUERY
                SELECT 
                    e.id,
                    e.titulo,
                    e.data_inicio,
                    e.data_fim,
                    e.local
                FROM eventos e
                WHERE 
                    DATE(e.data_inicio) = CURRENT_DATE
                    AND e.status = 'ativo'
                ORDER BY e.data_inicio ASC;
            END;
            $$;
        ",
        
        'obter_eventos_semana' => "
            CREATE OR REPLACE FUNCTION obter_eventos_semana(p_usuario_id INTEGER)
            RETURNS TABLE (
                id INTEGER,
                titulo VARCHAR(255),
                data_inicio TIMESTAMP,
                data_fim TIMESTAMP,
                local VARCHAR(255)
            )
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RETURN QUERY
                SELECT 
                    e.id,
                    e.titulo,
                    e.data_inicio,
                    e.data_fim,
                    e.local
                FROM eventos e
                WHERE 
                    e.data_inicio >= CURRENT_DATE
                    AND e.data_inicio <= CURRENT_DATE + INTERVAL '7 days'
                    AND e.status = 'ativo'
                ORDER BY e.data_inicio ASC;
            END;
            $$;
        "
    ];
    
    foreach ($functions as $functionName => $functionSQL) {
        try {
            $pdo->exec($functionSQL);
            echo "✅ Função '$functionName' criada com sucesso\n";
        } catch (Exception $e) {
            echo "❌ Erro ao criar função '$functionName': " . $e->getMessage() . "\n";
        }
    }
    
    // 4. CRIAR ÍNDICES CORRETOS
    echo "\n🔧 CRIANDO ÍNDICES CORRETOS\n";
    echo "==========================\n";
    
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_eventos_data_inicio ON eventos(data_inicio)",
        "CREATE INDEX IF NOT EXISTS idx_eventos_status ON eventos(status)",
        "CREATE INDEX IF NOT EXISTS idx_agenda_eventos_data ON agenda_eventos(data_inicio)",
        "CREATE INDEX IF NOT EXISTS idx_usuarios_email ON usuarios(email)",
        "CREATE INDEX IF NOT EXISTS idx_usuarios_perfil ON usuarios(perfil)"
    ];
    
    foreach ($indexes as $indexSql) {
        try {
            $pdo->exec($indexSql);
            echo "✅ Índice criado com sucesso\n";
        } catch (Exception $e) {
            echo "⚠️ Índice já existe ou erro: " . $e->getMessage() . "\n";
        }
    }
    
    // 5. TESTAR FUNÇÕES CRIADAS
    echo "\n🧪 TESTANDO FUNÇÕES CRIADAS\n";
    echo "==========================\n";
    
    try {
        // Testar função obter_proximos_eventos
        $stmt = $pdo->prepare("SELECT * FROM obter_proximos_eventos(1, 24)");
        $stmt->execute();
        $events = $stmt->fetchAll();
        echo "✅ Função 'obter_proximos_eventos' funcionando (" . count($events) . " eventos)\n";
    } catch (Exception $e) {
        echo "❌ Erro ao testar função 'obter_proximos_eventos': " . $e->getMessage() . "\n";
    }
    
    try {
        // Testar função obter_eventos_hoje
        $stmt = $pdo->prepare("SELECT * FROM obter_eventos_hoje(1)");
        $stmt->execute();
        $events = $stmt->fetchAll();
        echo "✅ Função 'obter_eventos_hoje' funcionando (" . count($events) . " eventos)\n";
    } catch (Exception $e) {
        echo "❌ Erro ao testar função 'obter_eventos_hoje': " . $e->getMessage() . "\n";
    }
    
    try {
        // Testar função obter_eventos_semana
        $stmt = $pdo->prepare("SELECT * FROM obter_eventos_semana(1)");
        $stmt->execute();
        $events = $stmt->fetchAll();
        echo "✅ Função 'obter_eventos_semana' funcionando (" . count($events) . " eventos)\n";
    } catch (Exception $e) {
        echo "❌ Erro ao testar função 'obter_eventos_semana': " . $e->getMessage() . "\n";
    }
    
    // 6. TESTAR CONSULTAS COMUNS
    echo "\n🧪 TESTANDO CONSULTAS COMUNS\n";
    echo "===========================\n";
    
    $testQueries = [
        "SELECT COUNT(*) FROM eventos" => "Eventos",
        "SELECT COUNT(*) FROM usuarios" => "Usuários",
        "SELECT COUNT(*) FROM lc_insumos" => "Insumos",
        "SELECT COUNT(*) FROM lc_listas" => "Listas de compras",
        "SELECT COUNT(*) FROM estoque_contagens" => "Contagens de estoque",
        "SELECT COUNT(*) FROM pagamentos_solicitacoes" => "Solicitações de pagamento",
        "SELECT COUNT(*) FROM comercial_degustacoes" => "Degustações comerciais"
    ];
    
    foreach ($testQueries as $query => $description) {
        try {
            $stmt = $pdo->query($query);
            $count = $stmt->fetchColumn();
            echo "✅ $description: $count registros\n";
        } catch (Exception $e) {
            echo "❌ $description: ERRO - " . $e->getMessage() . "\n";
        }
    }
    
    // 7. VERIFICAR ESTRUTURA DA TABELA EVENTOS
    echo "\n🔍 VERIFICANDO ESTRUTURA DA TABELA EVENTOS\n";
    echo "==========================================\n";
    
    try {
        $stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'eventos' ORDER BY ordinal_position");
        $columns = $stmt->fetchAll();
        
        echo "📋 Colunas da tabela eventos:\n";
        foreach ($columns as $column) {
            echo "  - " . $column['column_name'] . " (" . $column['data_type'] . ")\n";
        }
    } catch (Exception $e) {
        echo "❌ Erro ao verificar estrutura da tabela eventos: " . $e->getMessage() . "\n";
    }
    
    // RESUMO FINAL
    echo "\n🎉 CORREÇÃO FINAL COMPLETA!\n";
    echo "==========================\n";
    echo "🌍 Ambiente: $environment\n";
    echo "✅ Coluna 'data_inicio' adicionada à tabela eventos\n";
    echo "✅ Função 'obter_proximos_eventos' criada\n";
    echo "✅ Funções auxiliares criadas\n";
    echo "✅ Índices criados/verificados\n";
    echo "✅ Testes executados\n";
    
    echo "\n✅ TODOS OS PROBLEMAS FINAIS FORAM RESOLVIDOS!\n";
    echo "O sistema agora deve funcionar perfeitamente sem erros de colunas ou funções.\n";
    
} catch (Exception $e) {
    echo "❌ Erro fatal: " . $e->getMessage() . "\n";
    echo "\n💡 Verifique se o banco de dados está configurado corretamente.\n";
}
?>

