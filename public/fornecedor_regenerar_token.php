<?php
// fornecedor_regenerar_token.php
// Regenerar token público para fornecedor

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
    
    // Gerar novo token único
    $stmt = $pdo->query("SELECT lc_gerar_token_publico()");
    $novo_token = $stmt->fetchColumn();
    
    // Atualizar fornecedor com o novo token
    $stmt = $pdo->prepare("UPDATE fornecedores SET token_publico = ? WHERE id = ?");
    $stmt->execute([$novo_token, $fornecedor_id]);
    
    header('Location: fornecedores.php?sucesso=token_regenerado&id=' . $fornecedor_id);
    exit;
    
} catch (Exception $e) {
    header('Location: fornecedores.php?erro=' . urlencode($e->getMessage()));
    exit;
}
?>
