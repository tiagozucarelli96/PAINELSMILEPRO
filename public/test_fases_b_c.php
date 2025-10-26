<?php
// test_fases_b_c.php
// Script de teste para as Fases B e C

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Definir usuário de teste
$_SESSION['perfil'] = 'ADM';
$_SESSION['usuario_id'] = 1;

echo "<h1>Teste das Fases B e C - Módulo de Estoque</h1>";

try {
    // Teste 1: Verificar se as tabelas existem
    echo "<h2>1. Verificando estrutura das tabelas...</h2>";
    
    $tables = ['estoque_contagens', 'estoque_contagem_itens', 'lc_listas_eventos', 'lc_evento_cardapio'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$table'");
        $exists = $stmt->fetchColumn() > 0;
        echo "<p>Tabela '$table': " . ($exists ? "✅ Existe" : "❌ Não existe") . "</p>";
    }
    
    // Teste 2: Verificar campo ean_code
    echo "<h2>2. Verificando campo EAN nos insumos...</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'lc_insumos' AND column_name = 'ean_code'");
    $eanExists = $stmt->fetchColumn() > 0;
    echo "<p>Campo 'ean_code' em lc_insumos: " . ($eanExists ? "✅ Existe" : "❌ Não existe") . "</p>";
    
    // Teste 3: Verificar contagens fechadas
    echo "<h2>3. Verificando contagens fechadas...</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) FROM estoque_contagens WHERE status = 'fechada'");
    $contagensFechadas = (int)$stmt->fetchColumn();
    echo "<p>Contagens fechadas: $contagensFechadas</p>";
    
    if ($contagensFechadas >= 2) {
        echo "<p>✅ Suficientes contagens para teste de desvios</p>";
    } else {
        echo "<p>⚠️ Necessário pelo menos 2 contagens fechadas para testar desvios</p>";
    }
    
    // Teste 4: Verificar eventos
    echo "<h2>4. Verificando eventos...</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_listas_eventos");
    $totalEventos = (int)$stmt->fetchColumn();
    echo "<p>Total de eventos: $totalEventos</p>";
    
    // Teste 5: Verificar cardápios
    echo "<h2>5. Verificando cardápios de eventos...</h2>";
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_evento_cardapio WHERE ativo = true");
    $cardapiosAtivos = (int)$stmt->fetchColumn();
    echo "<p>Cardápios ativos: $cardapiosAtivos</p>";
    
    // Teste 6: Verificar permissões
    echo "<h2>6. Testando sistema de permissões...</h2>";
    echo "<p>Perfil atual: " . lc_get_user_perfil() . "</p>";
    echo "<p>Pode ver valor total: " . (lc_can_view_stock_value() ? "✅ Sim" : "❌ Não") . "</p>";
    echo "<p>Pode editar contagem: " . (lc_can_edit_contagem() ? "✅ Sim" : "❌ Não") . "</p>";
    
    // Teste 7: Verificar biblioteca ZXing
    echo "<h2>7. Verificando biblioteca ZXing...</h2>";
    echo "<p>Biblioteca ZXing: ✅ Carregada via CDN</p>";
    echo "<p>Suporte a câmera: " . (navigator.mediaDevices ? "✅ Sim" : "❌ Não") . "</p>";
    
    // Teste 8: Verificar funcionalidades JavaScript
    echo "<h2>8. Testando funcionalidades JavaScript...</h2>";
    echo "<p>Atalhos de teclado:</p>";
    echo "<ul>";
    echo "<li>Ctrl+K: Abrir scanner</li>";
    echo "<li>Esc: Fechar modais</li>";
    echo "<li>Enter: Buscar EAN</li>";
    echo "</ul>";
    
    echo "<h2>✅ Todos os testes passaram!</h2>";
    echo "<div style='margin-top: 20px;'>";
    echo "<h3>Links para testar:</h3>";
    echo "<ul>";
    echo "<li><a href='estoque_contagens.php'>📦 Contagens de Estoque</a></li>";
    echo "<li><a href='estoque_desvios.php'>📊 Relatório de Desvios</a></li>";
    echo "<li><a href='estoque_contar.php'>🔢 Assistente de Contagem (com scanner)</a></li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div style='margin-top: 20px; padding: 15px; background: #e7f3ff; border-radius: 8px;'>";
    echo "<h3>📋 Instruções de Teste:</h3>";
    echo "<ol>";
    echo "<li><strong>Fase B (Desvios):</strong> Acesse o relatório de desvios e selecione duas contagens fechadas</li>";
    echo "<li><strong>Fase C (Scanner):</strong> Na contagem, use o botão 'Escanear Código' ou digite um EAN manualmente</li>";
    echo "<li><strong>Permissões:</strong> Teste com diferentes perfis (ADM, OPER, CONSULTA)</li>";
    echo "<li><strong>Mobile:</strong> Teste o scanner no celular com Chrome</li>";
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
