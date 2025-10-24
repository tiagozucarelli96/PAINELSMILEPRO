<?php
// TESTE_DIRETO_BANCO.php
// Teste direto no banco sem usar conexão.php

echo "<h1>🔍 TESTE DIRETO NO BANCO</h1>";

try {
    // Conexão direta
    $dsn = 'pgsql:host=switchback.proxy.rlwy.net;port=10898;dbname=railway;sslmode=require';
    $user = 'postgres';
    $pass = 'qgEAbEeoqBipYcBGKMezSWwcnOomAVJa';
    
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    // Forçar search_path
    $pdo->exec('SET search_path TO smilee12_painel_smile, public');
    
    // Verificar search_path
    $stmt = $pdo->query("SHOW search_path");
    $search_path = $stmt->fetchColumn();
    echo "<h2>1. 🔍 Search Path</h2>";
    echo "<p>Search Path: $search_path</p>";
    
    // Teste de tabelas
    echo "<h2>2. 📊 Teste de Tabelas</h2>";
    
    $tabelas_teste = [
        'usuarios' => 'SELECT COUNT(*) as total FROM usuarios',
        'eventos' => 'SELECT COUNT(*) as total FROM eventos',
        'fornecedores' => 'SELECT COUNT(*) as total FROM fornecedores',
        'lc_categorias' => 'SELECT COUNT(*) as total FROM lc_categorias',
        'lc_unidades' => 'SELECT COUNT(*) as total FROM lc_unidades',
        'lc_fichas' => 'SELECT COUNT(*) as total FROM lc_fichas',
        'comercial_campos_padrao' => 'SELECT COUNT(*) as total FROM comercial_campos_padrao',
        'demandas_quadros' => 'SELECT COUNT(*) as total FROM demandas_quadros',
        'demandas_cartoes' => 'SELECT COUNT(*) as total FROM demandas_cartoes'
    ];
    
    $tabelas_ok = 0;
    $tabelas_erro = 0;
    
    foreach ($tabelas_teste as $nome => $sql) {
        try {
            $stmt = $pdo->query($sql);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = $resultado['total'];
            echo "<p style='color: green;'>✅ $nome - $count registros</p>";
            $tabelas_ok++;
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ $nome - ERRO: " . $e->getMessage() . "</p>";
            $tabelas_erro++;
        }
    }
    
    // Teste de colunas de permissões
    echo "<h2>3. 🔐 Colunas de Permissões</h2>";
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
    
    // Teste comercial
    echo "<h2>4. 🏢 Comercial</h2>";
    try {
        $stmt = $pdo->query("SELECT campos_json FROM comercial_campos_padrao WHERE ativo = TRUE ORDER BY criado_em DESC LIMIT 1");
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p style='color: green;'>✅ comercial_campos_padrao funcionando</p>";
        echo "<p>campos_json: " . ($resultado['campos_json'] ?: 'vazio') . "</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ comercial_campos_padrao - ERRO: " . $e->getMessage() . "</p>";
    }
    
    // Resumo
    echo "<h2>5. 📊 Resumo</h2>";
    echo "<div style='background: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3>📈 Estatísticas:</h3>";
    echo "<p>• <strong>Tabelas testadas:</strong> " . count($tabelas_teste) . "</p>";
    echo "<p>• <strong>Tabelas funcionando:</strong> $tabelas_ok</p>";
    echo "<p>• <strong>Tabelas com problema:</strong> $tabelas_erro</p>";
    echo "</div>";
    
    if ($tabelas_erro == 0) {
        echo "<div style='background: #d1fae5; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #065f46;'>🎉 SUCESSO TOTAL!</h3>";
        echo "<p style='color: #065f46;'>✅ Todas as tabelas funcionam com conexão direta!</p>";
        echo "<p><strong>Conclusão:</strong> O problema está na conexão do arquivo conexao.php</p>";
        echo "</div>";
    } else {
        echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3 style='color: #991b1b;'>⚠️ AINDA HÁ PROBLEMAS</h3>";
        echo "<p style='color: #991b1b;'>❌ Existem $tabelas_erro tabela(s) com problema(s).</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
    echo "<h3 style='color: #991b1b;'>❌ ERRO DE CONEXÃO</h3>";
    echo "<p style='color: #991b1b;'>Erro: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
