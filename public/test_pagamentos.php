<?php
// test_pagamentos.php - Teste da página de pagamentos
session_start();
require_once __DIR__ . '/conexao.php';

// Simular sessão de admin
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['perfil'] = 'ADM';
$_SESSION['nome'] = 'Admin Teste';

echo "<h1>Teste de Pagamentos</h1>";

// Verificar se a tabela lc_freelancers existe
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_freelancers");
    $count = $stmt->fetchColumn();
    echo "<p>✅ Tabela lc_freelancers existe com $count registros</p>";
} catch (Exception $e) {
    echo "<p>❌ Erro na tabela lc_freelancers: " . $e->getMessage() . "</p>";
}

// Verificar se a tabela lc_solicitacoes_pagamento existe
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_solicitacoes_pagamento");
    $count = $stmt->fetchColumn();
    echo "<p>✅ Tabela lc_solicitacoes_pagamento existe com $count registros</p>";
} catch (Exception $e) {
    echo "<p>❌ Erro na tabela lc_solicitacoes_pagamento: " . $e->getMessage() . "</p>";
}

// Testar inserção de solicitação
if ($_POST['test_insert']) {
    try {
        $sql = "INSERT INTO lc_solicitacoes_pagamento (criado_por, beneficiario_tipo, valor, status, observacoes) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([1, 'freelancer', 100.00, 'aguardando', 'Teste de inserção']);
        echo "<p>✅ Inserção de teste realizada com sucesso</p>";
    } catch (Exception $e) {
        echo "<p>❌ Erro na inserção: " . $e->getMessage() . "</p>";
    }
}
?>

<form method="POST">
    <button type="submit" name="test_insert" value="1">Testar Inserção</button>
</form>