<?php
// CORRIGIR_SEARCH_PATH.php
// Forçar configuração do search_path

require_once __DIR__ . '/public/conexao.php';

echo "<h1>🔧 CORREÇÃO DO SEARCH_PATH</h1>";

try {
    // Forçar search_path
    $pdo->exec('SET search_path TO smilee12_painel_smile, public');
    echo "<p style='color: green;'>✅ Search path configurado</p>";
    
    // Verificar search_path
    $stmt = $pdo->query("SHOW search_path");
    $search_path = $stmt->fetchColumn();
    echo "<p>Search Path atual: $search_path</p>";
    
    // Testar consulta
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p style='color: green;'>✅ Consulta funcionando: " . $resultado['total'] . " usuários</p>";
    
    // Testar tabelas principais
    $tabelas_teste = ['usuarios', 'eventos', 'fornecedores', 'lc_categorias', 'lc_unidades'];
    
    echo "<h2>📊 Teste de Tabelas</h2>";
    foreach ($tabelas_teste as $tabela) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM $tabela");
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p style='color: green;'>✅ $tabela - " . $resultado['total'] . " registros</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $tabela - ERRO: " . $e->getMessage() . "</p>";
        }
    }
    
    // Testar colunas de permissões
    echo "<h2>🔐 Teste de Colunas de Permissões</h2>";
    try {
        $stmt = $pdo->query("SELECT perm_agenda_ver, perm_agenda_meus, perm_agenda_relatorios FROM usuarios WHERE id = 1");
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ Colunas de permissões funcionando</p>";
        echo "<p>perm_agenda_ver: " . ($resultado['perm_agenda_ver'] ? 'true' : 'false') . "</p>";
        echo "<p>perm_agenda_meus: " . ($resultado['perm_agenda_meus'] ? 'true' : 'false') . "</p>";
        echo "<p>perm_agenda_relatorios: " . ($resultado['perm_agenda_relatorios'] ? 'true' : 'false') . "</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Colunas de permissões - ERRO: " . $e->getMessage() . "</p>";
    }
    
    // Testar comercial_campos_padrao
    echo "<h2>🏢 Teste de Comercial</h2>";
    try {
        $stmt = $pdo->query("SELECT campos_json FROM comercial_campos_padrao WHERE ativo = TRUE ORDER BY criado_em DESC LIMIT 1");
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ comercial_campos_padrao funcionando</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ comercial_campos_padrao - ERRO: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>❌ ERRO</h3>";
    echo "<p style='color: #991b1b;'>Erro: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
