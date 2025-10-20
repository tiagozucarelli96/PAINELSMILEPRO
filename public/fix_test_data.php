<?php
// fix_test_data.php - Corrigir dados de teste existentes
require_once 'conexao.php';

echo "<h1>🔧 Corrigindo Dados de Teste Existentes</h1>";

try {
    // 1. Verificar listas existentes
    echo "<h2>📋 Listas Existentes no Banco</h2>";
    $listas = $pdo->query("
        SELECT id, tipo, data_gerada, criado_por, criado_por_nome 
        FROM smilee12_painel_smile.lc_listas 
        ORDER BY data_gerada DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Total de listas: " . count($listas) . "</p>";
    
    if (!empty($listas)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Tipo</th><th>Data</th><th>Criado Por</th><th>Ação</th></tr>";
        foreach ($listas as $lista) {
            echo "<tr>";
            echo "<td>{$lista['id']}</td>";
            echo "<td>{$lista['tipo']}</td>";
            echo "<td>{$lista['data_gerada']}</td>";
            echo "<td>{$lista['criado_por_nome']}</td>";
            echo "<td><a href='test_lista.php?id={$lista['id']}' target='_blank'>Testar</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 2. Verificar eventos existentes
    echo "<h2>📅 Eventos Existentes</h2>";
    $eventos = $pdo->query("
        SELECT id, grupo_id, espaco, convidados, evento, data, lista_id
        FROM smilee12_painel_smile.lc_listas_eventos 
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Total de eventos: " . count($eventos) . "</p>";
    
    if (!empty($eventos)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Grupo ID</th><th>Espaço</th><th>Convidados</th><th>Evento</th><th>Data</th><th>Lista ID</th></tr>";
        foreach ($eventos as $evento) {
            echo "<tr>";
            echo "<td>{$evento['id']}</td>";
            echo "<td>{$evento['grupo_id']}</td>";
            echo "<td>{$evento['espaco']}</td>";
            echo "<td>{$evento['convidados']}</td>";
            echo "<td>{$evento['evento']}</td>";
            echo "<td>{$evento['data']}</td>";
            echo "<td>{$evento['lista_id']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Verificar se eventos estão vinculados às listas
    echo "<h2>🔗 Verificando Vinculação Eventos-Listas</h2>";
    
    $eventos_sem_lista = $pdo->query("
        SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas_eventos 
        WHERE lista_id IS NULL
    ")->fetchColumn();
    
    echo "<p>Eventos sem lista_id: $eventos_sem_lista</p>";
    
    if ($eventos_sem_lista > 0) {
        echo "<p style='color: orange;'>⚠️ Há eventos sem vinculação com listas!</p>";
        
        // Tentar vincular eventos às listas baseado em grupo_id
        echo "<h3>🔧 Tentando vincular eventos às listas...</h3>";
        
        $pdo->exec("
            UPDATE smilee12_painel_smile.lc_listas_eventos 
            SET lista_id = (
                SELECT l.id 
                FROM smilee12_painel_smile.lc_listas l 
                WHERE l.grupo_id = lc_listas_eventos.grupo_id 
                LIMIT 1
            )
            WHERE lista_id IS NULL
        ");
        
        $vinculados = $pdo->query("
            SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas_eventos 
            WHERE lista_id IS NOT NULL
        ")->fetchColumn();
        
        echo "<p style='color: green;'>✅ Eventos vinculados: $vinculados</p>";
    }
    
    // 4. Testar queries corrigidas
    echo "<h2>🧪 Testando Queries Corrigidas</h2>";
    
    if (!empty($listas)) {
        $test_id = $listas[0]['id'];
        echo "<p>Testando com lista ID: $test_id</p>";
        
        // Testar query de eventos
        try {
            $stmt = $pdo->prepare("SELECT * FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id ORDER BY id");
            $stmt->execute([':id' => $test_id]);
            $test_eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<p style='color: green;'>✅ Query de eventos funcionando! Eventos encontrados: " . count($test_eventos) . "</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro na query de eventos: " . $e->getMessage() . "</p>";
        }
        
        // Testar query de convidados
        try {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(convidados),0) FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id");
            $stmt->execute([':id' => $test_id]);
            $tot_convidados = $stmt->fetchColumn();
            echo "<p style='color: green;'>✅ Query de convidados funcionando! Total: $tot_convidados</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Erro na query de convidados: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<h2>🎉 Correção Concluída!</h2>";
    echo "<p style='color: green; font-weight: bold;'>Agora os botões devem funcionar com os dados existentes!</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
