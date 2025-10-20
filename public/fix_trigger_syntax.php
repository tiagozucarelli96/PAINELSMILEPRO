<?php
// fix_trigger_syntax.php - Corrigir sintaxe do trigger
require_once 'conexao.php';

echo "<h1>🔧 Corrigindo Sintaxe do Trigger</h1>";

try {
    $schema = 'smilee12_painel_smile';
    
    // 1. Remover trigger problemático
    echo "<h2>1. Removendo trigger com erro de sintaxe...</h2>";
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS trigger_atualizar_custo_receita ON $schema.lc_receita_componentes");
        echo "<p style='color: green;'>✅ Trigger removido</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ Aviso: " . $e->getMessage() . "</p>";
    }
    
    // 2. Criar trigger com sintaxe correta
    echo "<h2>2. Criando trigger com sintaxe correta...</h2>";
    
    // Primeiro, criar a função
    $pdo->exec("
        CREATE OR REPLACE FUNCTION $schema.atualizar_custo_receita()
        RETURNS TRIGGER AS \$\$
        DECLARE
            v_receita_id INTEGER;
            v_custo_total NUMERIC(12,4);
        BEGIN
            -- Determinar qual receita_id usar
            IF TG_OP = 'DELETE' THEN
                v_receita_id := OLD.receita_id;
            ELSE
                v_receita_id := NEW.receita_id;
            END IF;
            
            -- Calcular custo total dos componentes
            SELECT COALESCE(SUM(rc.custo_total), 0) 
            INTO v_custo_total
            FROM $schema.lc_receita_componentes rc
            WHERE rc.receita_id = v_receita_id;
            
            -- Atualizar custo total na receita
            UPDATE $schema.lc_receitas 
            SET custo_total = v_custo_total
            WHERE id = v_receita_id;
            
            -- Retornar o registro apropriado
            IF TG_OP = 'DELETE' THEN
                RETURN OLD;
            ELSE
                RETURN NEW;
            END IF;
        END;
        \$\$ LANGUAGE plpgsql;
    ");
    echo "<p style='color: green;'>✅ Função criada</p>";
    
    // Agora criar o trigger
    $pdo->exec("
        CREATE TRIGGER trigger_atualizar_custo_receita
        AFTER INSERT OR UPDATE OR DELETE ON $schema.lc_receita_componentes
        FOR EACH ROW
        EXECUTE FUNCTION $schema.atualizar_custo_receita();
    ");
    echo "<p style='color: green;'>✅ Trigger criado</p>";
    
    // 3. Testar o trigger
    echo "<h2>3. Testando trigger...</h2>";
    try {
        // Verificar se há receitas para testar
        $receitas = $pdo->query("SELECT id FROM $schema.lc_receitas LIMIT 1")->fetchColumn();
        if ($receitas) {
            $pdo->exec("SELECT $schema.atualizar_custo_receita()");
            echo "<p style='color: green;'>✅ Trigger testado com sucesso</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ Nenhuma receita para testar</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro ao testar: " . $e->getMessage() . "</p>";
    }
    
    echo "<h2>🎉 Correção Concluída!</h2>";
    echo "<p>O trigger agora deve funcionar corretamente sem erros de sintaxe</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
