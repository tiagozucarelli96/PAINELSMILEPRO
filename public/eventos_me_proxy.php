<?php
/**
 * eventos_me_proxy.php
 * Endpoint AJAX para buscar eventos da ME com cache
 * Retorna JSON para uso em dropdowns/buscas no frontend
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticação
if (empty($_SESSION['logado'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado']);
    exit;
}

// Verificar permissão
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sem permissão']);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_me_helper.php';

// Parâmetros
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$search = trim($_GET['search'] ?? $_POST['search'] ?? '');
$event_id = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$force_refresh = isset($_GET['refresh']) || isset($_POST['refresh']);
$days = (int)($_GET['days'] ?? $_POST['days'] ?? 60);

try {
    switch ($action) {
        case 'list':
            // Listar eventos futuros com busca
            $result = eventos_me_buscar_futuros($pdo, $search, $days, $force_refresh);
            
            if (!$result['ok']) {
                echo json_encode([
                    'ok' => false,
                    'error' => $result['error'] ?? 'Erro ao buscar eventos'
                ]);
                exit;
            }
            
            // Formatar eventos para o dropdown
            $events = array_map(function($ev) {
                $data = $ev['dataevento'] ?? $ev['data'] ?? '';
                $data_fmt = '';
                if ($data) {
                    $ts = strtotime($data);
                    if ($ts) {
                        $data_fmt = date('d/m/Y', $ts);
                    }
                }
                
                return [
                    'id' => (int)($ev['id'] ?? 0),
                    'nome' => $ev['nomeevento'] ?? $ev['nome'] ?? 'Sem nome',
                    'data' => $data,
                    'data_formatada' => $data_fmt,
                    'hora' => $ev['horainicio'] ?? $ev['hora_inicio'] ?? '',
                    'local' => $ev['local'] ?? $ev['nomelocal'] ?? '',
                    'convidados' => (int)($ev['nconvidados'] ?? $ev['convidados'] ?? 0),
                    'cliente' => $ev['nomecliente'] ?? $ev['cliente']['nome'] ?? '',
                    'tipo' => $ev['tipoevento'] ?? $ev['tipo'] ?? '',
                    // Label para dropdown
                    'label' => sprintf(
                        '%s - %s (%s)',
                        $ev['nomeevento'] ?? $ev['nome'] ?? 'Evento',
                        $data_fmt,
                        $ev['local'] ?? $ev['nomelocal'] ?? 'Local'
                    )
                ];
            }, $result['events']);
            
            // Ordenar por data (mais próximos primeiro)
            usort($events, function($a, $b) {
                return strtotime($a['data'] ?: '2099-01-01') - strtotime($b['data'] ?: '2099-01-01');
            });
            
            echo json_encode([
                'ok' => true,
                'events' => $events,
                'from_cache' => $result['from_cache'] ?? false,
                'total' => $result['total'] ?? count($events),
                'filtered' => count($events)
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'detail':
            // Buscar evento específico por ID
            if ($event_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'ID do evento inválido']);
                exit;
            }
            
            $result = eventos_me_buscar_por_id($pdo, $event_id);
            
            if (!$result['ok']) {
                echo json_encode([
                    'ok' => false,
                    'error' => $result['error'] ?? 'Evento não encontrado'
                ]);
                exit;
            }
            
            // Criar snapshot
            $snapshot = eventos_me_criar_snapshot($result['event']);
            
            echo json_encode([
                'ok' => true,
                'event' => $result['event'],
                'snapshot' => $snapshot,
                'from_cache' => $result['from_cache'] ?? false
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'cleanup':
            // Limpar cache expirado (pode ser chamado periodicamente)
            eventos_me_cache_cleanup($pdo);
            echo json_encode(['ok' => true, 'message' => 'Cache limpo']);
            break;
            
        default:
            echo json_encode(['ok' => false, 'error' => 'Ação inválida']);
    }
    
} catch (Exception $e) {
    error_log("eventos_me_proxy error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => 'Erro interno: ' . $e->getMessage()
    ]);
}
