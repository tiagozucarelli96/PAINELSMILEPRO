<?php
// test_dashboard_connection.php - Testar conexão e dados do dashboard
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';

echo "<h2>🔍 Teste de Conexão Dashboard</h2>";

try {
    // Testar conexão
    echo "<p>✅ Conexão com banco: OK</p>";
    
    // Testar tabela usuarios
    $usuarios = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = true")->fetchColumn();
    echo "<p>👥 Usuários ativos: $usuarios</p>";
    
    // Testar tabela fornecedores
    $fornecedores = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true")->fetchColumn();
    echo "<p>🏢 Fornecedores ativos: $fornecedores</p>";
    
    // Testar tabela lc_insumos
    $insumos = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE ativo = true")->fetchColumn();
    echo "<p>📦 Insumos cadastrados: $insumos</p>";
    
    // Testar tabela me_eventos_stats
    $mes_atual = date('Y-m');
    echo "<p>📅 Mês atual: $mes_atual</p>";
    
    $stmt = $pdo->prepare("SELECT eventos_ativos, contratos_fechados, leads_total, leads_negociacao, vendas_realizadas FROM me_eventos_stats WHERE mes_ano = ?");
    $stmt->execute([$mes_atual]);
    $me_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($me_stats) {
        echo "<p>📊 ME Eventos Stats encontrado:</p>";
        echo "<ul>";
        echo "<li>Eventos ativos: " . ($me_stats['eventos_ativos'] ?? 0) . "</li>";
        echo "<li>Contratos fechados: " . ($me_stats['contratos_fechados'] ?? 0) . "</li>";
        echo "<li>Leads total: " . ($me_stats['leads_total'] ?? 0) . "</li>";
        echo "<li>Leads negociação: " . ($me_stats['leads_negociacao'] ?? 0) . "</li>";
        echo "<li>Vendas realizadas: " . ($me_stats['vendas_realizadas'] ?? 0) . "</li>";
        echo "</ul>";
    } else {
        echo "<p>❌ ME Eventos Stats não encontrado para o mês $mes_atual</p>";
    }
    
    // Testar usuários com email
    $stmt = $pdo->query("SELECT nome, email FROM usuarios WHERE ativo = true AND email IS NOT NULL AND email != '' ORDER BY nome LIMIT 5");
    $usuarios_email = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>📧 Usuários com email: " . count($usuarios_email) . "</p>";
    if (!empty($usuarios_email)) {
        echo "<ul>";
        foreach ($usuarios_email as $user) {
            echo "<li>" . htmlspecialchars($user['nome']) . " - " . htmlspecialchars($user['email']) . "</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p>❌ Erro: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='index.php?page=dashboard'>← Voltar ao Dashboard</a></p>";
?>
