<?php
// push_register_subscription.php — Registrar subscription de push
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (empty($_SESSION['logado']) || empty($_SESSION['id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/push_schema.php';

$data = json_decode(file_get_contents('php://input'), true);
$usuario_id = (int)$_SESSION['id'];
$endpoint = trim($data['endpoint'] ?? '');
$keys = $data['keys'] ?? [];

if (empty($endpoint) || empty($keys['p256dh']) || empty($keys['auth'])) {
    echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    exit;
}

try {
    push_ensure_schema($pdo);
    $pdo->beginTransaction();
    
    // Verificar se já existe subscription para este endpoint
    $stmt = $pdo->prepare("
        SELECT id FROM sistema_notificacoes_navegador 
        WHERE usuario_id = :usuario_id AND endpoint = :endpoint
    ");
    $stmt->execute([':usuario_id' => $usuario_id, ':endpoint' => $endpoint]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Atualizar existente
        $stmt = $pdo->prepare("
            UPDATE sistema_notificacoes_navegador SET
                chave_publica = :p256dh,
                chave_autenticacao = :auth,
                consentimento_permitido = TRUE,
                data_autorizacao = NOW(),
                ativo = TRUE,
                atualizado_em = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':p256dh' => $keys['p256dh'],
            ':auth' => $keys['auth'],
            ':id' => $existing['id']
        ]);
    } else {
        // Criar novo
        $stmt = $pdo->prepare("
            INSERT INTO sistema_notificacoes_navegador 
            (usuario_id, endpoint, chave_publica, chave_autenticacao, consentimento_permitido, data_autorizacao, ativo)
            VALUES (:usuario_id, :endpoint, :p256dh, :auth, TRUE, NOW(), TRUE)
        ");
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':endpoint' => $endpoint,
            ':p256dh' => $keys['p256dh'],
            ':auth' => $keys['auth']
        ]);
    }
    
    $pdo->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Erro ao registrar subscription: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
