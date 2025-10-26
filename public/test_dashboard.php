<?php
// test_dashboard.php — Teste simples do dashboard
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Simular sessão logada
$_SESSION['logado'] = 1;
$_SESSION['nome'] = 'Tiago';
$_SESSION['perfil'] = 'ADM';
$_SESSION['user_id'] = 1;

echo "<h1>Teste Dashboard Principal</h1>";
echo "<p>Testando acesso ao dashboard...</p>";

// Testar include do dashboard
try {
    include __DIR__ . '/dashboard_principal.php';
    echo "<p style='color: green;'>✅ Dashboard carregado com sucesso!</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro ao carregar dashboard: " . $e->getMessage() . "</p>";
}
?>
