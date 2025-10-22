<?php
// fornecedor_gerar_token.php
// Gerar token público para fornecedor

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$fornecedor_id = intval($_GET['id'] ?? 0);
if (!$fornecedor_id) {
    header('Location: fornecedores.php?erro=fornecedor_nao_encontrado');
    exit;
}

try {
    // Verificar se fornecedor existe
    $stmt = $pdo->prepare("SELECT id, nome FROM fornecedores WHERE id = ?");
    $stmt->execute([$fornecedor_id]);
    $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fornecedor) {
        header('Location: fornecedores.php?erro=fornecedor_nao_encontrado');
        exit;
    }
    
    // Verificar se já tem token
    $stmt = $pdo->prepare("SELECT token_publico FROM fornecedores WHERE id = ? AND token_publico IS NOT NULL");
    $stmt->execute([$fornecedor_id]);
    if ($stmt->fetchColumn()) {
        header('Location: fornecedores.php?erro=fornecedor_ja_tem_token');
        exit;
    }
    
    // Gerar token único
    $stmt = $pdo->query("SELECT lc_gerar_token_publico()");
    $token = $stmt->fetchColumn();
    
    // Atualizar fornecedor com o token
    $stmt = $pdo->prepare("UPDATE fornecedores SET token_publico = ? WHERE id = ?");
    $stmt->execute([$token, $fornecedor_id]);
    
    header('Location: fornecedores.php?sucesso=token_gerado&id=' . $fornecedor_id);
    exit;
    
} catch (Exception $e) {
    header('Location: fornecedores.php?erro=' . urlencode($e->getMessage()));
    exit;
}
?>
