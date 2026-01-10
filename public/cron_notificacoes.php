<?php
// cron_notificacoes.php — Cron para enviar notificações consolidadas (ETAPA 13)
// Este arquivo deve ser executado periodicamente (ex: a cada 1-2 minutos)

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/notificacoes_helper.php';

// Executar apenas via CLI ou com token de segurança
$is_cli = php_sapi_name() === 'cli';
$token_valido = isset($_GET['token']) && $_GET['token'] === getenv('CRON_TOKEN');

if (!$is_cli && !$token_valido) {
    http_response_code(403);
    die('Acesso negado');
}

try {
    $notificacoes = new NotificacoesHelper();
    $enviado = $notificacoes->enviarNotificacoesConsolidadas();
    
    if ($is_cli) {
        echo $enviado ? "Notificações enviadas com sucesso\n" : "Nenhuma notificação para enviar\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => $enviado, 'message' => $enviado ? 'Notificações enviadas' : 'Nenhuma notificação pendente']);
    }
    
} catch (Exception $e) {
    error_log("Erro no cron de notificações: " . $e->getMessage());
    
    if ($is_cli) {
        echo "Erro: " . $e->getMessage() . "\n";
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
