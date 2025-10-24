<?php
/**
 * check_missing_tables.php — Verificar tabelas faltantes
 * Execute: php check_missing_tables.php
 */

echo "🔍 Verificando Tabelas Faltantes\n";
echo "===============================\n\n";

try {
    $host = 'localhost';
    $port = '5432';
    $dbname = 'painel_smile';
    $user = 'tiagozucarelli';
    $password = '';
    
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=disable";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Conexão com banco estabelecida\n\n";
    
    // Lista de tabelas que deveriam existir baseado nos módulos
    $expectedTables = [
        'usuarios' => 'Usuários do sistema',
        'demandas_logs' => 'Logs de demandas',
        'demandas_configuracoes' => 'Configurações de demandas',
        'eventos' => 'Eventos do sistema',
        'agenda_eventos' => 'Eventos da agenda',
        'agenda_espacos' => 'Espaços da agenda',
        'lc_insumos' => 'Insumos para lista de compras',
        'lc_listas' => 'Listas de compras',
        'lc_fornecedores' => 'Fornecedores',
        'lc_insumos_substitutos' => 'Substitutos de insumos',
        'lc_evento_cardapio' => 'Cardápio de eventos',
        'estoque_contagens' => 'Contagens de estoque',
        'estoque_contagem_itens' => 'Itens de contagem',
        'ean_code' => 'Códigos EAN',
        'pagamentos_solicitacoes' => 'Solicitações de pagamento',
        'pagamentos_freelancers' => 'Freelancers',
        'pagamentos_timeline' => 'Timeline de pagamentos',
        'comercial_degustacoes' => 'Degustações comerciais',
        'comercial_degust_inscricoes' => 'Inscrições em degustações',
        'comercial_clientes' => 'Clientes comerciais'
    ];
    
    echo "🔍 Verificando tabelas existentes...\n";
    
    $stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "✅ Tabelas existentes (" . count($existingTables) . "):\n";
    foreach ($existingTables as $table) {
        echo "  - $table\n";
    }
    
    echo "\n❌ Tabelas faltantes:\n";
    $missingTables = [];
    
    foreach ($expectedTables as $table => $description) {
        if (!in_array($table, $existingTables)) {
            echo "  - $table ($description)\n";
            $missingTables[] = $table;
        }
    }
    
    if (empty($missingTables)) {
        echo "  Nenhuma tabela faltante! 🎉\n";
    } else {
        echo "\n⚠️ Total de tabelas faltantes: " . count($missingTables) . "\n";
        echo "\n💡 Estas tabelas podem estar causando erros em algumas páginas.\n";
        echo "   Execute: php fix_db_correct.php para criar as tabelas faltantes.\n";
    }
    
    // Verificar se há erros específicos em consultas comuns
    echo "\n🔍 Testando consultas comuns...\n";
    
    $commonQueries = [
        "SELECT COUNT(*) FROM usuarios" => "Contar usuários",
        "SELECT COUNT(*) FROM eventos" => "Contar eventos",
        "SELECT COUNT(*) FROM lc_insumos" => "Contar insumos",
        "SELECT COUNT(*) FROM lc_listas" => "Contar listas de compras"
    ];
    
    foreach ($commonQueries as $query => $description) {
        try {
            $stmt = $pdo->query($query);
            $count = $stmt->fetchColumn();
            echo "✅ $description: $count registros\n";
        } catch (Exception $e) {
            echo "❌ $description: ERRO - " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 Verificação de tabelas concluída!\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
}
?>
