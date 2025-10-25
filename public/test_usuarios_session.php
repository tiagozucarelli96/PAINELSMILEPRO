<?php
// test_usuarios_session.php - Teste com sessão completa
session_start();

// Simular sessão completa de admin
$_SESSION['logado'] = true;
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;
$_SESSION['perm_pagamentos'] = 1;
$_SESSION['perm_tarefas'] = 1;
$_SESSION['perm_demandas'] = 1;
$_SESSION['perm_portao'] = 1;
$_SESSION['perm_banco_smile'] = 1;
$_SESSION['perm_banco_smile_admin'] = 1;
$_SESSION['perm_notas_fiscais'] = 1;
$_SESSION['perm_estoque_logistico'] = 1;
$_SESSION['perm_dados_contrato'] = 1;
$_SESSION['perm_uso_fiorino'] = 1;

echo "<h1>Teste de Sessão Completa</h1>";
echo "<p>Logado: " . ($_SESSION['logado'] ? 'Sim' : 'Não') . "</p>";
echo "<p>Perfil: " . ($_SESSION['perfil'] ?? 'não definido') . "</p>";
echo "<p>Perm Usuários: " . ($_SESSION['perm_usuarios'] ?? 'não definido') . "</p>";

// Testar acesso direto
echo "<h2>Teste de Acesso Direto</h2>";
echo "<a href='usuarios.php' target='_blank'>Abrir usuários.php</a><br>";
echo "<a href='usuarios.php?action=get_user&id=1' target='_blank'>Testar AJAX (ID=1)</a><br>";

// Testar se consegue incluir o arquivo
echo "<h2>Teste de Inclusão</h2>";
try {
    ob_start();
    include 'usuarios.php';
    $content = ob_get_clean();
    echo "<p style='color: green;'>✅ Arquivo incluído com sucesso!</p>";
    echo "<p>Tamanho do conteúdo: " . strlen($content) . " bytes</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro ao incluir: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
