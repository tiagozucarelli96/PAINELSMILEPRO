<?php
// adicionar_comprovante.php
// Adicionar comprovante de pagamento

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';
require_once __DIR__ . '/lc_anexos_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$solicitacao_id = intval($_POST['solicitacao_id'] ?? 0);

if (!$solicitacao_id) {
    header('Location: pagamentos_painel.php?erro=parametros_invalidos');
    exit;
}

try {
    // Verificar se a solicitação existe
    $stmt = $pdo->prepare("SELECT id, status FROM lc_solicitacoes_pagamento WHERE id = ?");
    $stmt->execute([$solicitacao_id]);
    $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$solicitacao) {
        header('Location: pagamentos_painel.php?erro=solicitacao_nao_encontrada');
        exit;
    }
    
    // Verificar se há arquivo
    if (!isset($_FILES['comprovante']) || $_FILES['comprovante']['error'] !== UPLOAD_ERR_OK) {
        header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&erro=arquivo_nao_enviado');
        exit;
    }
    
    // Fazer upload do comprovante
    $anexos_manager = new LcAnexosManager($pdo);
    $resultado = $anexos_manager->fazerUpload(
        $_FILES['comprovante'],
        $solicitacao_id,
        $_SESSION['usuario_id'],
        'interno',
        $_SERVER['REMOTE_ADDR'],
        true // eh_comprovante = true
    );
    
    if ($resultado['sucesso']) {
        header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&sucesso=comprovante_adicionado');
    } else {
        $erro = implode(', ', $resultado['erros']);
        header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&erro=' . urlencode($erro));
    }
    exit;
    
} catch (Exception $e) {
    error_log("Erro ao adicionar comprovante: " . $e->getMessage());
    header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&erro=erro_interno');
    exit;
}
?>
