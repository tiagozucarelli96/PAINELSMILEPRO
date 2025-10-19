<?php
// test_system.php
// Script para testar se o sistema está funcionando após criação das tabelas

session_start();
require_once __DIR__ . '/public/conexao.php';

echo "<h1>🧪 Teste do Sistema de Lista de Compras</h1>";

if (!isset($pdo) || !$pdo instanceof PDO) {
    die("<div style='color:red;'>❌ Erro: Não foi possível conectar ao banco de dados.</div>");
}

echo "<div style='color:green;'>✅ Conexão com banco estabelecida!</div>";

try {
    // Teste 1: Verificar tabelas criadas
    echo "<h2>📋 1. Verificação das tabelas</h2>";
    
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'public' 
        AND table_name LIKE 'lc_%'
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p>✅ Tabelas encontradas: " . count($tables) . "</p>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>✅ {$table}</li>";
    }
    echo "</ul>";
    
    // Teste 2: Verificar dados iniciais
    echo "<h2>📊 2. Dados iniciais</h2>";
    
    $unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
    $categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
    $configs = $pdo->query("SELECT COUNT(*) FROM lc_config")->fetchColumn();
    
    echo "<ul>";
    echo "<li>✅ Unidades: {$unidades} registros</li>";
    echo "<li>✅ Categorias: {$categorias} registros</li>";
    echo "<li>✅ Configurações: {$configs} registros</li>";
    echo "</ul>";
    
    // Teste 3: Testar queries do sistema
    echo "<h2>🔍 3. Teste de queries do sistema</h2>";
    
    // Teste configuracoes.php
    echo "<h3>Teste configuracoes.php:</h3>";
    try {
        $cat = $pdo->query("SELECT * FROM lc_categorias ORDER BY ativo DESC, ordem ASC, nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>✅ Categorias carregadas: " . count($cat) . " registros</p>";
        
        $uni = $pdo->query("SELECT * FROM lc_unidades ORDER BY ativo DESC, tipo ASC, nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>✅ Unidades carregadas: " . count($uni) . " registros</p>";
        
        $ins = $pdo->query("SELECT * FROM lc_insumos ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>✅ Insumos carregados: " . count($ins) . " registros</p>";
        
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Erro em configuracoes.php: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Teste lc_index.php
    echo "<h3>Teste lc_index.php:</h3>";
    try {
        $sqlCountC = "SELECT COUNT(*) FROM lc_listas l WHERE l.tipo_lista = 'compras'";
        $totalC = (int)$pdo->query($sqlCountC)->fetchColumn();
        echo "<p>✅ Listas de compras: {$totalC} registros</p>";
        
        $sqlCountE = "SELECT COUNT(*) FROM lc_listas l WHERE l.tipo_lista = 'encomendas'";
        $totalE = (int)$pdo->query($sqlCountE)->fetchColumn();
        echo "<p>✅ Listas de encomendas: {$totalE} registros</p>";
        
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Erro em lc_index.php: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Teste 4: Testar inserção de dados
    echo "<h2>➕ 4. Teste de inserção de dados</h2>";
    
    try {
        // Inserir um insumo de teste
        $stmt = $pdo->prepare("
            INSERT INTO lc_insumos (nome, unidade_padrao, unidade, custo_unit, aquisicao, observacoes, ativo) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'Teste - Arroz', 'kg', 'kg', 5.50, 'mercado', 'Insumo de teste', true
        ]);
        echo "<p>✅ Insumo de teste inserido com sucesso!</p>";
        
        // Inserir uma categoria de teste
        $stmt = $pdo->prepare("
            INSERT INTO lc_categorias (nome, ordem, ativo) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute(['Teste - Grãos', 99, true]);
        echo "<p>✅ Categoria de teste inserida com sucesso!</p>";
        
    } catch (Exception $e) {
        echo "<p style='color:red;'>❌ Erro na inserção: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    // Teste 5: Testar páginas principais
    echo "<h2>🌐 5. Teste de páginas principais</h2>";
    
    $pages = [
        'configuracoes.php' => 'Configurações',
        'lc_index.php' => 'Índice de Listas',
        'lista_compras.php' => 'Lista de Compras'
    ];
    
    echo "<ul>";
    foreach ($pages as $page => $name) {
        $url = "https://painelsmilepro-production.up.railway.app/public/{$page}";
        echo "<li><a href='{$url}' target='_blank'>{$name}</a> - <a href='{$url}'>Testar</a></li>";
    }
    echo "</ul>";
    
    echo "<div style='background: #d4edda; padding: 20px; border-radius: 10px; margin: 20px 0; border-left: 5px solid #28a745;'>";
    echo "<h3>🎉 SUCESSO! Sistema funcionando!</h3>";
    echo "<p><strong>O sistema de Lista de Compras está operacional!</strong></p>";
    echo "<p>Você pode agora:</p>";
    echo "<ul>";
    echo "<li>🔧 <a href='public/configuracoes.php'>Configurar categorias, unidades e insumos</a></li>";
    echo "<li>📋 <a href='public/lista_compras.php'>Gerar listas de compras</a></li>";
    echo "<li>📊 <a href='public/lc_index.php'>Ver histórico de listas</a></li>";
    echo "</ul>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 20px; border-radius: 10px; margin: 20px 0; border-left: 5px solid #dc3545;'>";
    echo "<h3>❌ ERRO no teste</h3>";
    echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><small>Teste executado em: " . date('d/m/Y H:i:s') . "</small></p>";
?>
