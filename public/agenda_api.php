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
    require_once __DIR__ . '/core/google_calendar_helper.php';

    $agenda = new AgendaHelper();
    $usuario_id = $_SESSION['user_id'] ?? 1;

    // Verificar permissões
    if (!$agenda->canAccessAgenda($usuario_id)) {
        echo json_encode(['error' => 'Acesso negado']);
        exit;
    }

    // Obter parâmetros (FullCalendar envia em formato ISO: 2026-01-11T00:00:00-03:00)
    $start_raw = $_GET['start'] ?? date('Y-m-d');
    $end_raw = $_GET['end'] ?? date('Y-m-d', strtotime('+1 month'));
    
    // Converter para formato de data simples (remover hora se presente)
    $start = preg_replace('/T.*$/', '', $start_raw);
    $end = preg_replace('/T.*$/', '', $end_raw);
    
    error_log("[AGENDA_API] Parâmetros recebidos - start_raw: $start_raw, end_raw: $end_raw");
    error_log("[AGENDA_API] Parâmetros processados - start: $start, end: $end");
    $responsavel_id = $_GET['responsavel_id'] ?? null;
    $espaco_id = $_GET['espaco_id'] ?? null;

    // Filtros
    $filtros = [];
    if ($responsavel_id) $filtros['responsavel_id'] = $responsavel_id;
    if ($espaco_id) $filtros['espaco_id'] = $espaco_id;

    // Obter eventos da agenda interna
    $eventos = $agenda->obterEventosCalendario($usuario_id, $start, $end, $filtros);
    
    // Obter eventos do Google Calendar (se configurado)
    try {
        $google_helper = new GoogleCalendarHelper();
        if ($google_helper->isConnected()) {
            $config = $google_helper->getConfig();
            if ($config && $config['ativo']) {
                // Buscar eventos do Google Calendar no período
                $pdo = $GLOBALS['pdo'];
                $stmt = $pdo->prepare("
                    SELECT 
                        id,
                        'google_' || id::text as id_formatado,
                        titulo,
                        descricao,
                        inicio,
                        fim,
                        localizacao,
                        organizador_email,
                        html_link,
                        COALESCE(eh_visita_agendada, false) as eh_visita_agendada,
                        COALESCE(contrato_fechado, false) as contrato_fechado
                    FROM google_calendar_eventos
                    WHERE status = 'confirmed'
                      AND (
                          (inicio >= :start AND inicio <= :end)
                          OR (fim >= :start AND fim <= :end)
                          OR (inicio <= :start AND fim >= :end)
                      )
                    ORDER BY inicio ASC
                ");
                // Converter para timestamp completo para comparação
                $start_timestamp = strtotime($start);
                $end_timestamp = strtotime($end);
                
                $start_date = date('Y-m-d 00:00:00', $start_timestamp);
                $end_date = date('Y-m-d 23:59:59', $end_timestamp);
                
                error_log("[AGENDA_API] Buscando eventos Google de $start_date até $end_date");
                
                $stmt->execute([
                    ':start' => $start_date,
                    ':end' => $end_date
                ]);
                $google_eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                error_log("[AGENDA_API] Eventos Google encontrados: " . count($google_eventos) . " no período $start_date até $end_date");
                
                if (count($google_eventos) > 0) {
                    error_log("[AGENDA_API] Primeiro evento: " . json_encode($google_eventos[0]));
                }
                error_log("[AGENDA_API] Config ativo: " . ($config['ativo'] ? 'SIM' : 'NÃO'));
                error_log("[AGENDA_API] Calendar ID config: " . $config['google_calendar_id']);
                
                // Adicionar eventos do Google aos eventos da agenda
                foreach ($google_eventos as $google_evento) {
                    error_log("[AGENDA_API] Adicionando evento Google: " . $google_evento['titulo'] . " em " . $google_evento['inicio']);
                    $eventos[] = [
                        'id' => $google_evento['id_formatado'],
                        'google_id' => $google_evento['id'], // ID real para updates
                        'tipo' => 'google',
                        'titulo' => $google_evento['titulo'],
                        'descricao' => $google_evento['descricao'],
                        'inicio' => $google_evento['inicio'],
                        'fim' => $google_evento['fim'],
                        'status' => 'agendado',
                        'compareceu' => false,
                        'fechou_contrato' => (bool)$google_evento['contrato_fechado'],
                        'eh_visita_agendada' => (bool)$google_evento['eh_visita_agendada'],
                        'contrato_fechado' => (bool)$google_evento['contrato_fechado'],
                        'cor_evento' => '#10b981', // Verde para eventos do Google
                        'responsavel_nome' => $google_evento['organizador_email'] ?? 'Google Calendar',
                        'espaco_nome' => $google_evento['localizacao'] ?? null,
                        'criado_por_nome' => 'Google Calendar',
                        'responsavel_usuario_id' => null,
                        'espaco_id' => null,
                        'google_link' => $google_evento['html_link']
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Se houver erro ao buscar eventos do Google, continuar sem eles
        error_log("[AGENDA_API] Erro ao buscar eventos do Google Calendar: " . $e->getMessage());
    }

    // Formatar para FullCalendar
    $eventos_formatados = [];
    foreach ($eventos as $evento) {
        // Definir cor baseada no tipo
        if (isset($evento['tipo']) && $evento['tipo'] === 'google') {
            $cor = '#10b981'; // Verde para eventos do Google
        } elseif (isset($evento['tipo']) && $evento['tipo'] === 'bloqueio') {
            $cor = '#dc2626'; // Vermelho para bloqueios
        } elseif (isset($evento['tipo']) && $evento['tipo'] === 'visita') {
            // Visitas usam a cor configurada do responsável
            $cor = $evento['cor_agenda'] ?? $evento['cor_evento'] ?? '#3b96f7';
        } else {
            $cor = $evento['cor_agenda'] ?? $evento['cor_evento'] ?? '#3b96f7';
        }
        
        $extended_props = [
            'tipo' => $evento['tipo'] ?? 'evento',
            'descricao' => $evento['descricao'] ?? null,
            'status' => $evento['status'] ?? 'agendado',
            'compareceu' => $evento['compareceu'] ?? false,
            'fechou_contrato' => $evento['fechou_contrato'] ?? false,
            'responsavel_nome' => $evento['responsavel_nome'] ?? null,
            'espaco_nome' => $evento['espaco_nome'] ?? null,
            'criado_por_nome' => $evento['criado_por_nome'] ?? null,
            'responsavel_usuario_id' => $evento['responsavel_usuario_id'] ?? null,
            'espaco_id' => $evento['espaco_id'] ?? null
        ];
        
        // Adicionar link do Google se disponível
        if (isset($evento['google_link'])) {
            $extended_props['google_link'] = $evento['google_link'];
        }
        
        // Adicionar campos específicos do Google Calendar
        if (isset($evento['google_id'])) {
            $extended_props['google_id'] = $evento['google_id'];
        }
        if (isset($evento['eh_visita_agendada'])) {
            $extended_props['eh_visita_agendada'] = $evento['eh_visita_agendada'];
        }
        if (isset($evento['contrato_fechado'])) {
            $extended_props['contrato_fechado'] = $evento['contrato_fechado'];
        }
        
        $eventos_formatados[] = [
            'id' => $evento['id'],
            'title' => $evento['titulo'],
            'start' => $evento['inicio'],
            'end' => $evento['fim'],
            'color' => $cor,
            'extendedProps' => $extended_props,
            // Eventos do Google são read-only (não editáveis, mas podem ter checkboxes)
            'editable' => !isset($evento['tipo']) || $evento['tipo'] !== 'google'
        ];
    }

    error_log("[AGENDA_API] Total de eventos formatados: " . count($eventos_formatados));
    error_log("[AGENDA_API] Eventos Google formatados: " . count(array_filter($eventos_formatados, fn($e) => isset($e['extendedProps']['tipo']) && $e['extendedProps']['tipo'] === 'google')));
    
    echo json_encode($eventos_formatados);
} catch (Exception $e) {
    error_log("[AGENDA_API] ERRO: " . $e->getMessage());
    error_log("[AGENDA_API] Stack trace: " . $e->getTraceAsString());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
