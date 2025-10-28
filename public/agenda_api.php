<?php
// agenda_api.php — API para o sistema de agenda
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Desabilitar display_errors para não retornar HTML
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/conexao.php';
    require_once __DIR__ . '/core/helpers.php';
    require_once __DIR__ . '/agenda_helper.php';

    $agenda = new AgendaHelper();
    $usuario_id = $_SESSION['user_id'] ?? 1;

    // Verificar permissões
    if (!$agenda->canAccessAgenda($usuario_id)) {
        echo json_encode(['error' => 'Acesso negado']);
        exit;
    }

    // Obter parâmetros
    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+1 month'));
    $responsavel_id = $_GET['responsavel_id'] ?? null;
    $espaco_id = $_GET['espaco_id'] ?? null;

    // Filtros
    $filtros = [];
    if ($responsavel_id) $filtros['responsavel_id'] = $responsavel_id;
    if ($espaco_id) $filtros['espaco_id'] = $espaco_id;

    // Obter eventos
    $eventos = $agenda->obterEventosCalendario($usuario_id, $start, $end, $filtros);

    // Formatar para FullCalendar
    $eventos_formatados = [];
    foreach ($eventos as $evento) {
        // Definir cor baseada no tipo
        if ($evento['tipo'] === 'bloqueio') {
            $cor = '#dc2626'; // Vermelho para bloqueios
        } elseif ($evento['tipo'] === 'visita') {
            $cor = '#10b981'; // Verde para visitas
        } else {
            $cor = $evento['cor_agenda'] ?? $evento['cor_evento'] ?? '#3b96f7';
        }
        
        $eventos_formatados[] = [
            'id' => $evento['id'],
            'title' => $evento['titulo'],
            'start' => $evento['inicio'],
            'end' => $evento['fim'],
            'color' => $cor,
            'extendedProps' => [
                'tipo' => $evento['tipo'],
                'descricao' => $evento['descricao'],
                'status' => $evento['status'],
                'compareceu' => $evento['compareceu'],
                'fechou_contrato' => $evento['fechou_contrato'],
                'responsavel_nome' => $evento['responsavel_nome'],
                'espaco_nome' => $evento['espaco_nome'],
                'criado_por_nome' => $evento['criado_por_nome'],
                'responsavel_usuario_id' => $evento['responsavel_usuario_id'],
                'espaco_id' => $evento['espaco_id']
            ]
        ];
    }

    echo json_encode($eventos_formatados);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
