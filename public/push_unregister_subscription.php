<?php
// push_unregister_subscription.php — Remover subscription
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (empty($_SESSION['logado']) || empty($_SESSION['id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/conexao.php';
$data = json_decode(file_get_contents('php://input'), true);
$usuario_id = (int)$_SESSION['id'];

try {
    $stmt = $pdo->prepare("UPDATE sistema_notificacoes_navegador SET ativo = FALSE WHERE usuario_id = :usuario_id");
    $stmt->execute([':usuario_id' => $usuario_id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
