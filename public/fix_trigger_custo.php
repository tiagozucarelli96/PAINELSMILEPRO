<?php
// fix_trigger_custo.php - Corrigir trigger com coluna ambígua
require_once 'conexao.php';

echo "<h1>🔧 Corrigindo Trigger com Coluna Ambígua</h1>";

try {
    $schema = 'smilee12_painel_smile';
    
    // 1. Verificar triggers existentes
    echo "<h2>1. Verificando triggers existentes...</h2>";
    $result = $pdo->query("
        SELECT trigger_name, event_object_table, action_timing, event_manipulation
        FROM information_schema.triggers
        WHERE trigger_schema = '$schema'
        ORDER BY event_object_table, trigger_name
    ");
    $triggers = $result->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p>Nenhum trigger encontrado</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Trigger</th><th>Tabela</th><th>Timing</th><th>Evento</th></tr>";
        foreach ($triggers as $trigger) {
            echo "<tr><td>" . $trigger['trigger_name'] . "</td><td>" . $trigger['event_object_table'] . "</td><td>" . $trigger['action_timing'] . "</td><td>" . $trigger['event_manipulation'] . "</td></tr>";
        }
        echo "</table>";
    }
    
    // 2. Remover triggers problemáticos
    echo "<h2>2. Removendo triggers problemáticos...</h2>";
    $problematic_triggers = [
        'trigger_atualizar_custo_receita',
        'atualizar_custo_receita_trigger'
    ];
    
    foreach ($problematic_triggers as $trigger_name) {
        try {
            $pdo->exec("DROP TRIGGER IF EXISTS $trigger_name ON $schema.lc_receita_componentes");
            echo "<p style='color: green;'>✅ Trigger $trigger_name removido</p>";
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠️ Aviso ao remover $trigger_name: " . $e->getMessage() . "</p>";
        }
    }
    
    // 3. Remover função problemática
    echo "<h2>3. Removendo função problemática...</h2>";
    try {
        $pdo->exec("DROP FUNCTION IF EXISTS $schema.atualizar_custo_receita(integer)");
        echo "<p style='color: green;'>✅ Função atualizar_custo_receita removida</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Aviso ao remover função: " . $e->getMessage() . "</p>";
    }
    
    // 4. Criar função corrigida
    echo "<h2>4. Criando função corrigida...</h2>";
    $pdo->exec("
        CREATE OR REPLACE FUNCTION $schema.atualizar_custo_receita(p_receita_id INTEGER)
        RETURNS VOID AS \$\$
        DECLARE
            v_custo_total NUMERIC(12,4);
        BEGIN
            -- Calcular custo total dos componentes
            SELECT COALESCE(SUM(rc.custo_total), 0) 
            INTO v_custo_total
            FROM $schema.lc_receita_componentes rc
            WHERE rc.receita_id = p_receita_id;
            
            -- Atualizar custo total na receita
            UPDATE $schema.lc_receitas 
            SET custo_total = v_custo_total
            WHERE id = p_receita_id;
        END;
        \$\$ LANGUAGE plpgsql;
    ");
    echo "<p style='color: green;'>✅ Função atualizar_custo_receita criada</p>";
    
    // 5. Criar trigger corrigido
    echo "<h2>5. Criando trigger corrigido...</h2>";
    $pdo->exec("
        CREATE TRIGGER trigger_atualizar_custo_receita
        AFTER INSERT OR UPDATE OR DELETE ON $schema.lc_receita_componentes
        FOR EACH ROW
        EXECUTE FUNCTION $schema.atualizar_custo_receita(
            COALESCE(NEW.receita_id, OLD.receita_id)
        );
    ");
    echo "<p style='color: green;'>✅ Trigger trigger_atualizar_custo_receita criado</p>";
    
    // 6. Testar a função
    echo "<h2>6. Testando função...</h2>";
    try {
        $pdo->exec("SELECT $schema.atualizar_custo_receita(1)");
        echo "<p style='color: green;'>✅ Função testada com sucesso</p>";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao testar função: " . $e->getMessage() . "</p>";
    }
    
    echo "<h2>🎉 Correção Concluída!</h2>";
    echo "<p>O trigger agora deve funcionar sem erro de coluna ambígua</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
