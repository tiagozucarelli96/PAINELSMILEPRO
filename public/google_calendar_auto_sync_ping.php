<?php
/**
 * google_calendar_auto_sync_ping.php
 * Endpoint interno (autenticado) para sincronizar Google Calendar.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logado'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

try {
    $now = time();
    $last = (int)($_SESSION['google_sync_last_ping'] ?? 0);
    if ($last > 0 && ($now - $last) < 300) {
        echo json_encode(['success' => true, 'message' => 'Ignorado (throttle)']);
        exit;
    }
    $_SESSION['google_sync_last_ping'] = $now;

    $pdo = $GLOBALS['pdo'];
    $config = $pdo->query("SELECT * FROM google_calendar_config WHERE ativo = TRUE LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC);
    if (!$config) {
        echo json_encode(['success' => false, 'message' => 'Configuração não encontrada']);
        exit;
    }

    $ultima = $config['ultima_sincronizacao'] ? strtotime((string)$config['ultima_sincronizacao']) : 0;
    if ($ultima > 0 && ($now - $ultima) < 300) {
        echo json_encode(['success' => true, 'message' => 'Sem necessidade (recente)']);
        exit;
    }

    $helper = new GoogleCalendarHelper();
    if (!$helper->isConnected()) {
        throw new Exception('Google Calendar não conectado');
    }

    $resultado = $helper->syncCalendarEvents(
        (string)$config['google_calendar_id'],
        (int)($config['sync_dias_futuro'] ?? 180)
    );

    echo json_encode([
        'success' => true,
        'importados' => $resultado['importados'] ?? 0,
        'atualizados' => $resultado['atualizados'] ?? 0,
        'pulados' => $resultado['pulados'] ?? 0
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
