<?php
// reset_database.php - Reset completo do banco de dados
require_once 'conexao.php';

echo "<h1>🔥 RESET COMPLETO DO BANCO DE DADOS</h1>";
echo "<p style='color: red; font-weight: bold;'>⚠️ ATENÇÃO: Esta operação irá APAGAR TODOS os dados!</p>";

// Verificar se o usuário confirmou
if (!isset($_GET['confirm'])) {
    echo "<h2>📋 Dados que serão APAGADOS:</h2>";
    
    try {
        // Contar registros em cada tabela
        $tables = [
            'lc_listas' => 'Listas',
            'lc_listas_eventos' => 'Eventos de Listas',
            'lc_compras_consolidadas' => 'Compras Consolidadas',
            'lc_encomendas_itens' => 'Itens de Encomendas',
            'lc_categorias' => 'Categorias',
            'lc_unidades' => 'Unidades',
            'lc_insumos' => 'Insumos',
            'lc_itens_fixos' => 'Itens Fixos',
            'lc_receitas' => 'Receitas',
            'lc_receita_componentes' => 'Componentes de Receitas'
        ];
        
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 20px 0;'>";
        echo "<tr><th>Tabela</th><th>Registros</th></tr>";
        
        foreach ($tables as $table => $name) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.$table")->fetchColumn();
                echo "<tr><td>$name</td><td style='color: red; font-weight: bold;'>$count registros</td></tr>";
            } catch (Exception $e) {
                echo "<tr><td>$name</td><td style='color: orange;'>Tabela não existe</td></tr>";
            }
        }
        echo "</table>";
        
        echo "<h2>🚨 CONFIRMAÇÃO NECESSÁRIA</h2>";
        echo "<p>Para confirmar o reset, clique no botão abaixo:</p>";
        echo "<a href='?confirm=1' style='background: #dc3545; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-size: 18px; font-weight: bold;'>🔥 CONFIRMAR RESET COMPLETO</a>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>Erro ao verificar dados: " . $e->getMessage() . "</p>";
    }
    
} else {
    // Executar reset
    echo "<h2>🗑️ Executando Reset...</h2>";
    
    try {
        // Ordem de exclusão (respeitando foreign keys)
        $delete_order = [
            'lc_receita_componentes',
            'lc_receitas', 
            'lc_itens_fixos',
            'lc_insumos',
            'lc_compras_consolidadas',
            'lc_encomendas_itens',
            'lc_listas_eventos',
            'lc_listas',
            'lc_categorias',
            'lc_unidades'
        ];
        
        foreach ($delete_order as $table) {
            try {
                $count_before = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.$table")->fetchColumn();
                $pdo->exec("DELETE FROM smilee12_painel_smile.$table");
                $count_after = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.$table")->fetchColumn();
                echo "<p style='color: green;'>✅ $table: $count_before → $count_after registros</p>";
            } catch (Exception $e) {
                echo "<p style='color: orange;'>⚠️ $table: " . $e->getMessage() . "</p>";
            }
        }
        
        // Recriar dados básicos
        echo "<h2>🔄 Recriando Dados Básicos...</h2>";
        
        // Categorias básicas
        $categorias = [
            ['nome' => 'Carnes', 'ordem' => 1, 'ativo' => true],
            ['nome' => 'Verduras', 'ordem' => 2, 'ativo' => true],
            ['nome' => 'Temperos', 'ordem' => 3, 'ativo' => true],
            ['nome' => 'Bebidas', 'ordem' => 4, 'ativo' => true]
        ];
        
        foreach ($categorias as $cat) {
            $pdo->prepare("INSERT INTO smilee12_painel_smile.lc_categorias (nome, ordem, ativo) VALUES (?, ?, ?)")
                ->execute([$cat['nome'], $cat['ordem'], $cat['ativo']]);
        }
        echo "<p style='color: green;'>✅ Categorias básicas criadas</p>";
        
        // Unidades básicas
        $unidades = [
            ['nome' => 'Quilograma', 'simbolo' => 'kg', 'tipo' => 'massa', 'fator_base' => 1.0, 'ativo' => true],
            ['nome' => 'Gramas', 'simbolo' => 'g', 'tipo' => 'massa', 'fator_base' => 0.001, 'ativo' => true],
            ['nome' => 'Litro', 'simbolo' => 'L', 'tipo' => 'volume', 'fator_base' => 1.0, 'ativo' => true],
            ['nome' => 'Unidade', 'simbolo' => 'un', 'tipo' => 'unidade', 'fator_base' => 1.0, 'ativo' => true]
        ];
        
        foreach ($unidades as $uni) {
            $pdo->prepare("INSERT INTO smilee12_painel_smile.lc_unidades (nome, simbolo, tipo, fator_base, ativo) VALUES (?, ?, ?, ?, ?)")
                ->execute([$uni['nome'], $uni['simbolo'], $uni['tipo'], $uni['fator_base'], $uni['ativo']]);
        }
        echo "<p style='color: green;'>✅ Unidades básicas criadas</p>";
        
        echo "<h2>🎉 RESET CONCLUÍDO COM SUCESSO!</h2>";
        echo "<p style='color: green; font-weight: bold; font-size: 18px;'>Agora você pode começar do zero sem problemas!</p>";
        echo "<p><a href='lc_index.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🏠 Voltar para o Sistema</a></p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erro durante o reset: " . $e->getMessage() . "</p>";
    }
}
?>
