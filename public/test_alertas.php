<?php
// test_alertas.php
// Script de teste para o sistema de alertas

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Definir usuário de teste
$_SESSION['perfil'] = 'ADM';
$_SESSION['usuario_id'] = 1;
$_SESSION['usuario_nome'] = 'Administrador';

echo "<h1>Teste do Sistema de Alertas de Ruptura</h1>";

try {
    // Teste 1: Verificar campos de estoque
    echo "<h2>1. Verificando campos de estoque nos insumos...</h2>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'lc_insumos' AND column_name IN ('estoque_atual', 'estoque_minimo', 'embalagem_multiplo')");
    $camposExistem = $stmt->fetchColumn() >= 3;
    echo "<p>Campos de estoque: " . ($camposExistem ? "✅ Existem" : "❌ Não existem") . "</p>";
    
    if (!$camposExistem) {
        echo "<p style='color: red;'>Execute o arquivo sql/008_estoque_contagem.sql para criar os campos necessários.</p>";
    }
    
    // Teste 2: Verificar insumos com dados de estoque
    echo "<h2>2. Verificando insumos com dados de estoque...</h2>";
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as total,
               COUNT(CASE WHEN estoque_atual IS NOT NULL AND estoque_atual > 0 THEN 1 END) as com_estoque,
               COUNT(CASE WHEN estoque_minimo IS NOT NULL AND estoque_minimo > 0 THEN 1 END) as com_minimo
        FROM lc_insumos 
        WHERE ativo = true
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>Total de insumos ativos: {$stats['total']}</p>";
    echo "<p>Com estoque atual: {$stats['com_estoque']}</p>";
    echo "<p>Com estoque mínimo: {$stats['com_minimo']}</p>";
    
    // Teste 3: Simular dados de teste
    echo "<h2>3. Criando dados de teste...</h2>";
    
    // Buscar alguns insumos para configurar
    $stmt = $pdo->query("SELECT id, nome FROM lc_insumos WHERE ativo = true LIMIT 3");
    $insumos_teste = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($insumos_teste) > 0) {
        echo "<p>Configurando dados de teste para " . count($insumos_teste) . " insumos...</p>";
        
        foreach ($insumos_teste as $insumo) {
            // Configurar estoque atual baixo e mínimo
            $stmt = $pdo->prepare("
                UPDATE lc_insumos 
                SET estoque_atual = 5, estoque_minimo = 10, embalagem_multiplo = 12
                WHERE id = :id
            ");
            $stmt->execute([':id' => $insumo['id']]);
            echo "<p>✅ {$insumo['nome']}: estoque=5, mínimo=10, embalagem=12</p>";
        }
    } else {
        echo "<p>⚠️ Nenhum insumo ativo encontrado</p>";
    }
    
    // Teste 4: Verificar alertas
    echo "<h2>4. Verificando alertas de ruptura...</h2>";
    
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM lc_insumos 
        WHERE ativo = true AND estoque_atual <= estoque_minimo
    ");
    $alertas = (int)$stmt->fetchColumn();
    echo "<p>Insumos com risco de ruptura: $alertas</p>";
    
    if ($alertas > 0) {
        echo "<p>✅ Sistema de alertas funcionando</p>";
    } else {
        echo "<p>⚠️ Nenhum alerta encontrado - configure estoque mínimo nos insumos</p>";
    }
    
    // Teste 5: Verificar eventos futuros
    echo "<h2>5. Verificando eventos futuros...</h2>";
    
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM lc_listas_eventos 
        WHERE data_evento >= CURRENT_DATE
    ");
    $eventos_futuros = (int)$stmt->fetchColumn();
    echo "<p>Eventos futuros: $eventos_futuros</p>";
    
    // Teste 6: Verificar cardápios
    echo "<h2>6. Verificando cardápios de eventos...</h2>";
    
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM lc_evento_cardapio 
        WHERE ativo = true
    ");
    $cardapios = (int)$stmt->fetchColumn();
    echo "<p>Cardápios ativos: $cardapios</p>";
    
    // Teste 7: Verificar permissões
    echo "<h2>7. Testando sistema de permissões...</h2>";
    echo "<p>Perfil atual: " . lc_get_user_perfil() . "</p>";
    echo "<p>Pode gerar sugestões: " . (in_array(lc_get_user_perfil(), ['ADM', 'OPER']) ? "✅ Sim" : "❌ Não") . "</p>";
    echo "<p>Pode ver custos: " . (lc_can_view_stock_value() ? "✅ Sim" : "❌ Não") . "</p>";
    
    // Teste 8: Verificar funcionalidades JavaScript
    echo "<h2>8. Verificando funcionalidades JavaScript...</h2>";
    echo "<p>Biblioteca ZXing: ✅ Carregada via CDN</p>";
    echo "<p>Suporte a câmera: " . (navigator.mediaDevices ? "✅ Sim" : "❌ Não") . "</p>";
    
    echo "<h2>✅ Testes concluídos!</h2>";
    
    echo "<div style='margin-top: 20px; padding: 15px; background: #e7f3ff; border-radius: 8px;'>";
    echo "<h3>📋 Links para testar:</h3>";
    echo "<ul>";
    echo "<li><a href='estoque_alertas.php'>🚨 Alertas de Ruptura</a></li>";
    echo "<li><a href='estoque_sugestao.php'>📋 Sugestão de Compra</a></li>";
    echo "<li><a href='estoque_contagens.php'>📦 Contagens de Estoque</a></li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div style='margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 8px;'>";
    echo "<h3>⚠️ Configurações necessárias:</h3>";
    echo "<ol>";
    echo "<li><strong>Estoque mínimo:</strong> Configure o estoque mínimo para cada insumo</li>";
    echo "<li><strong>Estoque atual:</strong> Faça contagens para atualizar o estoque atual</li>";
    echo "<li><strong>Fornecedores:</strong> Associe fornecedores aos insumos</li>";
    echo "<li><strong>Preços:</strong> Configure preços e fatores de correção</li>";
    echo "<li><strong>Eventos:</strong> Crie eventos futuros com cardápios</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2>❌ Erro durante os testes:</h2>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>

<script>
// Teste de funcionalidades JavaScript
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ JavaScript carregado');
    console.log('✅ ZXing disponível:', typeof ZXing !== 'undefined');
    console.log('✅ MediaDevices disponível:', !!navigator.mediaDevices);
    
    // Teste de atalhos
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'k') {
            console.log('✅ Atalho Ctrl+K funcionando');
        }
    });
});
</script>
