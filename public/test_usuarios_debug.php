<?php
// test_usuarios_debug.php - Debug da página de usuários
session_start();

// Simular sessão de admin
$_SESSION['logado'] = true;
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = true;

echo "<h1>Debug Sessão</h1>";
echo "<p>Logado: " . ($_SESSION['logado'] ?? 'não definido') . "</p>";
echo "<p>Perfil: " . ($_SESSION['perfil'] ?? 'não definido') . "</p>";
echo "<p>Perm Usuários: " . ($_SESSION['perm_usuarios'] ?? 'não definido') . "</p>";

// Incluir a página de usuários
try {
    ob_start();
    include 'usuarios.php';
    $content = ob_get_clean();
    echo "<h2>Conteúdo da página:</h2>";
    echo "<div style='border: 1px solid #ccc; padding: 10px; max-height: 300px; overflow-y: auto;'>";
    echo htmlspecialchars($content);
    echo "</div>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
