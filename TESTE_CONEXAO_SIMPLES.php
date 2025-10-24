<?php
// TESTE_CONEXAO_SIMPLES.php
// Teste simples de conexão e consultas

require_once __DIR__ . '/public/conexao.php';

echo "<h1>🔍 TESTE DE CONEXÃO SIMPLES</h1>";

try {
    // Teste 1: Verificar conexão
    echo "<h2>1. ✅ Teste de Conexão</h2>";
    if ($pdo) {
        echo "<p style='color: green;'>✅ Conexão estabelecida</p>";
    } else {
        echo "<p style='color: red;'>❌ Falha na conexão</p>";
    }
    
    // Teste 2: Verificar search_path
    echo "<h2>2. 🔍 Verificar Search Path</h2>";
    $stmt = $pdo->query("SHOW search_path");
    $search_path = $stmt->fetchColumn();
    echo "<p>Search Path: $search_path</p>";
    
    // Teste 3: Consulta simples
    echo "<h2>3. 📊 Consulta Simples</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total de usuários: " . $resultado['total'] . "</p>";
    
    // Teste 4: Verificar tabelas existentes
    echo "<h2>4. 🗄️ Tabelas Existentes</h2>";
    $stmt = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name IN ('usuarios', 'eventos', 'fornecedores', 'lc_categorias')
        ORDER BY table_name
    ");
    $tabelas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tabelas as $tabela) {
        echo "<p style='color: green;'>✅ $tabela</p>";
    }
    
    // Teste 5: Consulta com schema explícito
    echo "<h2>5. 🔧 Consulta com Schema Explícito</h2>";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM smilee12_painel_smile.usuarios");
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ Schema explícito funciona: " . $resultado['total'] . " usuários</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Schema explícito falhou: " . $e->getMessage() . "</p>";
    }
    
    // Teste 6: Verificar colunas de permissões
    echo "<h2>6. 🔐 Verificar Colunas de Permissões</h2>";
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'usuarios' 
        AND table_schema = 'smilee12_painel_smile'
        AND column_name LIKE 'perm_%'
        ORDER BY column_name
    ");
    $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($colunas as $coluna) {
        echo "<p style='color: green;'>✅ $coluna</p>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>❌ ERRO GERAL</h3>";
    echo "<p style='color: #991b1b;'>Erro: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
