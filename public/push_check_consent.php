<?php
// push_check_consent.php — Verificar se usuário tem consentimento de push
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (empty($_SESSION['logado']) || empty($_SESSION['id'])) {
    echo json_encode(['hasConsent' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/conexao.php';

$usuario_id = (int)$_SESSION['id'];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM sistema_notificacoes_navegador 
        WHERE usuario_id = :usuario_id 
        AND consentimento_permitido = TRUE 
        AND ativo = TRUE
    ");
    $stmt->execute([':usuario_id' => $usuario_id]);
    $hasConsent = $stmt->fetchColumn() > 0;
    
    echo json_encode(['hasConsent' => $hasConsent]);
} catch (Exception $e) {
    error_log("Erro ao verificar consentimento: " . $e->getMessage());
    echo json_encode(['hasConsent' => false, 'error' => $e->getMessage()]);
}
