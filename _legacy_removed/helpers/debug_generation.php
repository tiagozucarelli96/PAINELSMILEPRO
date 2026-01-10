<?php
// debug_generation.php
// Debug da geração de listas para identificar problemas

require_once 'conexao.php';
require_once 'lc_config_helper.php';

echo "<h1>🔍 Debug da Geração de Listas</h1>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("❌ Erro: Não foi possível conectar ao banco de dados.");
}

echo "✅ Conexão estabelecida!<br><br>";

try {
    // 1. Verificar configurações do sistema
    echo "<h3>📋 Configurações do Sistema:</h3>";
    
    $configs = [
        'precisao_quantidade' => lc_get_config($pdo, 'precisao_quantidade', '3'),
        'precisao_valor' => lc_get_config($pdo, 'precisao_valor', '2'),
        'mostrar_custo_previa' => lc_get_config($pdo, 'mostrar_custo_previa', '1'),
        'incluir_fixos_auto' => lc_get_config($pdo, 'incluir_fixos_auto', '1'),
        'multiplicar_por_eventos' => lc_get_config($pdo, 'multiplicar_por_eventos', '1')
    ];
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Configuração</th><th>Valor</th><th>Descrição</th></tr>";
    foreach ($configs as $key => $value) {
        $desc = [
            'precisao_quantidade' => 'Casas decimais para quantidades',
            'precisao_valor' => 'Casas decimais para valores monetários',
            'mostrar_custo_previa' => 'Mostrar custos na prévia',
            'incluir_fixos_auto' => 'Incluir itens fixos automaticamente',
            'multiplicar_por_eventos' => 'Multiplicar quantidades por número de eventos'
        ][$key] ?? 'N/A';
        
        echo "<tr><td>{$key}</td><td><strong>{$value}</strong></td><td>{$desc}</td></tr>";
    }
    echo "</table>";
    
    // 2. Verificar estrutura das tabelas
    echo "<h3>📊 Estrutura das Tabelas:</h3>";
    
    $tables = ['lc_listas', 'lc_listas_eventos', 'lc_compras_consolidadas', 'lc_encomendas_itens'];
    
    foreach ($tables as $table) {
        $exists = $pdo->query("
            SELECT EXISTS (
                SELECT FROM information_schema.tables
                WHERE table_schema = 'smilee12_painel_smile' 
                AND table_name = '{$table}'
            )
        ")->fetchColumn();
        
        $status = $exists ? "✅" : "❌";
        echo "{$status} {$table}<br>";
        
        if ($exists) {
            $count = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.{$table}")->fetchColumn();
            echo "&nbsp;&nbsp;&nbsp;📊 Registros: {$count}<br>";
        }
    }
    
    // 3. Verificar dados de exemplo
    echo "<h3>🧪 Dados de Exemplo:</h3>";
    
    // Categorias
    $cats = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_categorias WHERE ativo = true")->fetchColumn();
    echo "📂 Categorias ativas: {$cats}<br>";
    
    // Insumos
    $insumos = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos WHERE ativo = true")->fetchColumn();
    echo "🧾 Insumos ativos: {$insumos}<br>";
    
    // Fichas técnicas
    $fichas = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_fichas WHERE ativo = true")->fetchColumn();
    echo "📋 Fichas técnicas ativas: {$fichas}<br>";
    
    // Itens
    $itens = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_itens WHERE ativo = true")->fetchColumn();
    echo "📦 Itens ativos: {$itens}<br>";
    
    // 4. Verificar se há dados de teste
    echo "<h3>🔍 Verificação de Dados de Teste:</h3>";
    
    $testData = $pdo->query("
        SELECT 
            'Categorias' as tipo, 
            COUNT(*) as total,
            COUNT(CASE WHEN ativo = true THEN 1 END) as ativos
        FROM smilee12_painel_smile.lc_categorias
        UNION ALL
        SELECT 
            'Insumos' as tipo, 
            COUNT(*) as total,
            COUNT(CASE WHEN ativo = true THEN 1 END) as ativos
        FROM smilee12_painel_smile.lc_insumos
        UNION ALL
        SELECT 
            'Fichas' as tipo, 
            COUNT(*) as total,
            COUNT(CASE WHEN ativo = true THEN 1 END) as ativos
        FROM smilee12_painel_smile.lc_fichas
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Tipo</th><th>Total</th><th>Ativos</th></tr>";
    foreach ($testData as $row) {
        echo "<tr><td>{$row['tipo']}</td><td>{$row['total']}</td><td>{$row['ativos']}</td></tr>";
    }
    echo "</table>";
    
    // 5. Verificar se há problemas com valores "on"
    echo "<h3>⚠️ Verificação de Valores Problemáticos:</h3>";
    
    // Verificar se há campos que possam conter "on"
    $problematicFields = $pdo->query("
        SELECT 'lc_insumos' as tabela, 'nome' as campo, COUNT(*) as count
        FROM smilee12_painel_smile.lc_insumos 
        WHERE nome = 'on'
        UNION ALL
        SELECT 'lc_categorias' as tabela, 'nome' as campo, COUNT(*) as count
        FROM smilee12_painel_smile.lc_categorias 
        WHERE nome = 'on'
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $foundProblems = false;
    foreach ($problematicFields as $row) {
        if ($row['count'] > 0) {
            echo "❌ Encontrado valor 'on' em {$row['tabela']}.{$row['campo']}: {$row['count']} registros<br>";
            $foundProblems = true;
        }
    }
    
    if (!$foundProblems) {
        echo "✅ Nenhum valor 'on' problemático encontrado nos dados<br>";
    }
    
    // 6. Teste de geração de lista
    echo "<h3>🧪 Teste de Geração de Lista:</h3>";
    
    if (isset($_POST['test_generation'])) {
        echo "<h4>📝 Dados POST recebidos:</h4>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 5px; max-height: 200px; overflow-y: auto;'>";
        print_r($_POST);
        echo "</pre>";
        
        // Verificar se há valores "on" nos dados POST
        $foundOn = false;
        foreach ($_POST as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (is_array($subValue)) {
                        foreach ($subValue as $subSubKey => $subSubValue) {
                            if ($subSubValue === 'on') {
                                echo "❌ Encontrado 'on' em: \$_POST['{$key}']['{$subKey}']['{$subSubKey}'] = '{$subSubValue}'<br>";
                                $foundOn = true;
                            }
                        }
                    } else {
                        if ($subValue === 'on') {
                            echo "❌ Encontrado 'on' em: \$_POST['{$key}']['{$subKey}'] = '{$subValue}'<br>";
                            $foundOn = true;
                        }
                    }
                }
            } else {
                if ($value === 'on') {
                    echo "❌ Encontrado 'on' em: \$_POST['{$key}'] = '{$value}'<br>";
                    $foundOn = true;
                }
            }
        }
        
        if (!$foundOn) {
            echo "✅ Nenhum valor 'on' encontrado nos dados POST<br>";
        }
    } else {
        echo "<form method='POST'>";
        echo "<input type='hidden' name='test_generation' value='1'>";
        echo "<p>Clique no botão abaixo para testar a geração de lista:</p>";
        echo "<button type='submit' style='background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;'>Testar Geração</button>";
        echo "</form>";
    }
    
    echo "<br><div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>🎉 Sistema de Geração de Listas</h3>";
    echo "<p><strong>Configuração atual:</strong></p>";
    echo "<ul>";
    echo "<li>✅ Precisão de quantidades: {$configs['precisao_quantidade']} casas decimais</li>";
    echo "<li>✅ Precisão de valores: {$configs['precisao_valor']} casas decimais</li>";
    echo "<li>✅ Mostrar custos na prévia: " . ($configs['mostrar_custo_previa'] === '1' ? 'Sim' : 'Não') . "</li>";
    echo "<li>✅ Incluir itens fixos automaticamente: " . ($configs['incluir_fixos_auto'] === '1' ? 'Sim' : 'Não') . "</li>";
    echo "<li>✅ Multiplicar por eventos: " . ($configs['multiplicar_por_eventos'] === '1' ? 'Sim' : 'Não') . "</li>";
    echo "</ul>";
    echo "<p><strong>Próximos passos:</strong></p>";
    echo "<ul>";
    echo "<li>🔧 <a href='lc_config_avancadas.php'>Configurar preferências avançadas</a></li>";
    echo "<li>📋 <a href='lista_compras.php'>Gerar lista de compras</a></li>";
    echo "<li>📊 <a href='lc_index.php'>Ver listas existentes</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3>❌ ERRO</h3>";
    echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<br><br>Debug concluído em: " . date('H:i:s');
?>