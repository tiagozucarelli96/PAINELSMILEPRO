<?php
// check_if_events_needed.php - Verificar se realmente precisamos da tabela de eventos
require_once 'conexao.php';

echo "<h1>🔍 Verificando se Precisamos da Tabela de Eventos</h1>";

try {
    // 1. Verificar se existem listas criadas
    echo "<h2>📋 Verificando Listas Existentes</h2>";
    $listas = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas")->fetchColumn();
    echo "<p>Total de listas: $listas</p>";
    
    if ($listas > 0) {
        $sample = $pdo->query("SELECT id, tipo, data_gerada FROM smilee12_painel_smile.lc_listas LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        echo "<h3>Exemplos de listas:</h3>";
        echo "<pre>" . print_r($sample, true) . "</pre>";
    } else {
        echo "<p style='color: orange;'>⚠️ Nenhuma lista foi criada ainda!</p>";
    }
    
    // 2. Verificar se a tabela de eventos tem dados
    echo "<h2>📅 Verificando Dados de Eventos</h2>";
    $eventos = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas_eventos")->fetchColumn();
    echo "<p>Total de eventos: $eventos</p>";
    
    // 3. Verificar se as queries realmente precisam de eventos
    echo "<h2>🔍 Analisando Queries nos Arquivos</h2>";
    
    // Verificar lc_ver.php
    $lc_ver_content = file_get_contents('lc_ver.php');
    $uses_events = strpos($lc_ver_content, 'lc_listas_eventos') !== false;
    echo "<p>lc_ver.php usa eventos: " . ($uses_events ? "SIM" : "NÃO") . "</p>";
    
    // Verificar lc_pdf.php
    $lc_pdf_content = file_get_contents('lc_pdf.php');
    $uses_events_pdf = strpos($lc_pdf_content, 'lc_listas_eventos') !== false;
    echo "<p>lc_pdf.php usa eventos: " . ($uses_events_pdf ? "SIM" : "NÃO") . "</p>";
    
    // Verificar lc_excluir.php
    $lc_excluir_content = file_get_contents('lc_excluir.php');
    $uses_events_excluir = strpos($lc_excluir_content, 'lc_listas_eventos') !== false;
    echo "<p>lc_excluir.php usa eventos: " . ($uses_events_excluir ? "SIM" : "NÃO") . "</p>";
    
    // 4. Propor solução
    echo "<h2>💡 Proposta de Solução</h2>";
    
    if ($listas == 0) {
        echo "<p style='color: blue;'>📝 Como não há listas criadas, podemos:</p>";
        echo "<ul>";
        echo "<li>Remover as referências à tabela de eventos</li>";
        echo "<li>Simplificar as queries para funcionar sem eventos</li>";
        echo "<li>Focar apenas nas funcionalidades básicas</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: blue;'>📝 Como há listas criadas, podemos:</p>";
        echo "<ul>";
        echo "<li>Manter a tabela de eventos mas torná-la opcional</li>";
        echo "<li>Modificar as queries para funcionar com ou sem eventos</li>";
        echo "<li>Adicionar eventos quando necessário</li>";
        echo "</ul>";
    }
    
    // 5. Mostrar queries que podem ser simplificadas
    echo "<h2>🔧 Queries que Podem ser Simplificadas</h2>";
    
    if ($uses_events) {
        echo "<h3>lc_ver.php - Query atual:</h3>";
        echo "<pre>SELECT * FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id</pre>";
        echo "<h3>Proposta simplificada:</h3>";
        echo "<pre>// Comentar ou remover esta query se não há eventos</pre>";
    }
    
    if ($uses_events_pdf) {
        echo "<h3>lc_pdf.php - Query atual:</h3>";
        echo "<pre>SELECT * FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id</pre>";
        echo "<h3>Proposta simplificada:</h3>";
        echo "<pre>// Comentar ou remover esta query se não há eventos</pre>";
    }
    
    if ($uses_events_excluir) {
        echo "<h3>lc_excluir.php - Query atual:</h3>";
        echo "<pre>DELETE FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = ?</pre>";
        echo "<h3>Proposta simplificada:</h3>";
        echo "<pre>// Comentar ou remover esta query se não há eventos</pre>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
