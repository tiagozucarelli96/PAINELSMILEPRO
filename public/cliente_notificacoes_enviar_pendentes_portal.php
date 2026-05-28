<?php
/**
 * Envio controlado de pendentes da campanha "Lançamento do Portal do Cliente".
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

$tokenOperacional = hash_equals('portal-pendentes-20260528', (string)($_GET['run_token'] ?? $_POST['run_token'] ?? ''));
$sessaoPermitida = !empty($_SESSION['logado'])
    && (!empty($_SESSION['perm_configuracoes']) || !empty($_SESSION['perm_administrativo']) || !empty($_SESSION['perm_superadmin']));

if (!$sessaoPermitida && !$tokenOperacional) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/cliente_notificacoes_helper.php';
cliente_notificacoes_require_eventos_helpers();

function cliente_notif_pendente_ja_enviado(PDO $pdo, int $meEventId): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM cliente_notificacao_envios
        WHERE chave_modelo = 'portal_cliente_lancamento'
          AND status = 'enviado'
          AND me_event_id = :me_event_id
        LIMIT 1
    ");
    $stmt->execute([':me_event_id' => $meEventId]);
    return (bool)$stmt->fetchColumn();
}

function cliente_notif_pendente_pre_contrato(PDO $pdo, int $meEventId, array $event): array
{
    $stmt = $pdo->prepare("
        SELECT *
        FROM vendas_pre_contratos
        WHERE me_event_id = :me_event_id
        ORDER BY aprovado_em DESC NULLS LAST, atualizado_em DESC NULLS LAST, id DESC
        LIMIT 1
    ");
    $stmt->execute([':me_event_id' => $meEventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && !empty($row)) {
        return $row;
    }

    $tipo = cliente_notificacoes_classificar_tipo_evento_me($event);
    $email = cliente_notificacoes_me_evento_email_cliente_completo($pdo, $event, '', [], true);

    return [
        'id' => 0,
        'me_event_id' => $meEventId,
        'nome_completo' => cliente_notificacoes_me_evento_nome_cliente($event),
        'email' => $email,
        'tipo_evento' => $tipo,
        'tipo_evento_real' => $tipo,
        'data_evento' => cliente_notificacoes_me_evento_data($event),
        'status' => 'me_api',
    ];
}

try {
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Banco indisponível.');
    }

    cliente_notificacoes_ensure_schema($pdo);
    $modelo = cliente_notificacoes_get_modelo($pdo, 'portal_cliente_lancamento');
    if (!$modelo || empty($modelo['ativo']) || empty($modelo['canal_email'])) {
        throw new RuntimeException('Campanha inativa ou sem canal de e-mail habilitado.');
    }

    $ids = $_POST['ids'] ?? $_GET['ids'] ?? '444,575,710,728,803,819,885,967,955';
    if (is_string($ids)) {
        $ids = preg_split('/[^0-9]+/', $ids) ?: [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids), static fn($id) => $id > 0)));
    $ids = array_slice($ids, 0, 90);

    $usuarioId = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
    $resultado = [
        'ok' => true,
        'total' => count($ids),
        'enviados' => 0,
        'ignorados' => 0,
        'erros' => 0,
        'detalhes' => [],
    ];

    foreach ($ids as $meEventId) {
        if (cliente_notif_pendente_ja_enviado($pdo, $meEventId)) {
            $resultado['ignorados']++;
            $resultado['detalhes'][] = ['me_event_id' => $meEventId, 'status' => 'ignorado', 'motivo' => 'Já enviado'];
            continue;
        }

        try {
            $eventResult = eventos_me_buscar_por_id($pdo, $meEventId);
            $event = $eventResult['event'] ?? null;
            if (empty($eventResult['ok']) || !is_array($event)) {
                throw new RuntimeException((string)($eventResult['error'] ?? 'Evento não encontrado na ME.'));
            }

            $preContrato = cliente_notif_pendente_pre_contrato($pdo, $meEventId, $event);
            $ok = cliente_notificacoes_enviar_modelo_para_pre_contrato($pdo, $modelo, $preContrato, $usuarioId);
            if (!$ok) {
                throw new RuntimeException('Falha retornada pelo serviço de e-mail.');
            }

            $resultado['enviados']++;
            $resultado['detalhes'][] = [
                'me_event_id' => $meEventId,
                'status' => 'enviado',
                'cliente' => (string)($preContrato['nome_completo'] ?? ''),
                'email' => (string)($preContrato['email'] ?? ''),
            ];
        } catch (Throwable $e) {
            $resultado['erros']++;
            $resultado['detalhes'][] = [
                'me_event_id' => $meEventId,
                'status' => 'erro',
                'erro' => $e->getMessage(),
            ];
        }
    }

    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
