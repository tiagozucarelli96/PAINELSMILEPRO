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
    
    // Vincular endpoint a apenas um usuário por vez (evita mistura de notificações entre logins no mesmo navegador).
    $stmt = $pdo->prepare("
        SELECT id, usuario_id
        FROM sistema_notificacoes_navegador
        WHERE endpoint = :endpoint
        ORDER BY atualizado_em DESC NULLS LAST, id DESC
    ");
    $stmt->execute([':endpoint' => $endpoint]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($rows)) {
        $primary = $rows[0];
        // Reatribuir endpoint para o usuário atual.
        $stmt = $pdo->prepare("
            UPDATE sistema_notificacoes_navegador SET
                usuario_id = :usuario_id,
                chave_publica = :p256dh,
                chave_autenticacao = :auth,
                consentimento_permitido = TRUE,
                data_autorizacao = NOW(),
                ativo = TRUE,
                atualizado_em = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':p256dh' => $keys['p256dh'],
            ':auth' => $keys['auth'],
            ':id' => $primary['id']
        ]);

        // Desativar registros duplicados do mesmo endpoint (se existirem).
        if (count($rows) > 1) {
            $idsToDisable = array_map(fn($r) => (int)$r['id'], array_slice($rows, 1));
            $placeholders = implode(',', array_fill(0, count($idsToDisable), '?'));
            $sql = "UPDATE sistema_notificacoes_navegador
                    SET ativo = FALSE, consentimento_permitido = FALSE, atualizado_em = NOW()
                    WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($idsToDisable);
        }
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
