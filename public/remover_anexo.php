<?php
// remover_anexo.php
// Remover anexo de uma solicitação

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/lc_permissions_stub.php';
require_once __DIR__ . '/lc_anexos_helper.php';

$anexo_id = intval($_GET['id'] ?? 0);
$solicitacao_id = intval($_GET['solicitacao'] ?? 0);

if (!$anexo_id || !$solicitacao_id) {
    header('Location: pagamentos_minhas.php?erro=parametros_invalidos');
    exit;
}

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE', 'CONSULTA'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

try {
    // Buscar dados do anexo e solicitação
    $stmt = $pdo->prepare("
        SELECT a.id, a.solicitacao_id, a.eh_comprovante, a.autor_id,
               s.criador_id, s.status
        FROM lc_anexos_pagamentos a
        JOIN lc_solicitacoes_pagamento s ON s.id = a.solicitacao_id
        WHERE a.id = ? AND a.solicitacao_id = ?
    ");
    $stmt->execute([$anexo_id, $solicitacao_id]);
    $dados = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dados) {
        header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&erro=anexo_nao_encontrado');
        exit;
    }
    
    // Verificar permissões específicas
    $pode_remover = false;
    
    if (in_array($perfil, ['ADM', 'FIN'])) {
        // ADM/FIN podem remover qualquer anexo
        $pode_remover = true;
    } elseif ($perfil === 'GERENTE') {
        // Gerente pode remover apenas seus próprios anexos normais (não comprovantes)
        // e apenas se a solicitação estiver em status que permite edição
        if (!$dados['eh_comprovante'] && 
            $dados['autor_id'] == ($_SESSION['usuario_id'] ?? 0) &&
            in_array($dados['status'], ['aguardando', 'suspenso'])) {
            $pode_remover = true;
        }
    }
    
    if (!$pode_remover) {
        header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&erro=permissao_negada');
        exit;
    }
    
    // Remover anexo
    $anexos_manager = new LcAnexosManager($pdo);
    $motivo = $dados['eh_comprovante'] ? 'Comprovante removido' : 'Anexo removido';
    
    if ($anexos_manager->removerAnexo($anexo_id, $solicitacao_id, $motivo)) {
        header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&sucesso=anexo_removido');
    } else {
        header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&erro=erro_ao_remover_anexo');
    }
    exit;
    
} catch (Exception $e) {
    error_log("Erro ao remover anexo: " . $e->getMessage());
    header('Location: pagamentos_ver.php?id=' . $solicitacao_id . '&erro=erro_interno');
    exit;
}
?>
