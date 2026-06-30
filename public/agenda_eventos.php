<?php
/**
 * agenda_eventos.php
 * Calendário de eventos filtrado por unidade/space visível do usuário.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/comercial_cliente_sync_helper.php';

if (empty($_SESSION['perm_agenda_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function agenda_eventos_has_table(PDO $pdo, string $table): bool
{
    try {
        // Respeita o search_path ativo da aplicação em produção.
        $stmt = $pdo->prepare("SELECT to_regclass(:table)");
        $stmt->execute([':table' => $table]);
        $reg = $stmt->fetchColumn();
        return is_string($reg) && trim($reg) !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function agenda_eventos_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM pg_attribute
            WHERE attrelid = to_regclass(:table)
              AND attname = :column
              AND NOT attisdropped
            LIMIT 1
        ");
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function agenda_eventos_fetch_user_spaces(PDO $pdo, int $usuarioId): array
{
    if (!agenda_eventos_has_table($pdo, 'usuarios_spaces_visiveis')) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT TRIM(space_visivel) AS space_visivel
        FROM usuarios_spaces_visiveis
        WHERE usuario_id = :usuario_id
          AND TRIM(COALESCE(space_visivel, '')) <> ''
        ORDER BY TRIM(space_visivel) ASC
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);

    $spaces = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $space) {
        $space = trim((string)$space);
        if ($space !== '') {
            $spaces[$space] = $space;
        }
    }

    return array_values($spaces);
}

function agenda_eventos_month_label(DateTimeImmutable $date): string
{
    $meses = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    return ($meses[(int)$date->format('n')] ?? $date->format('F')) . ' ' . $date->format('Y');
}

function agenda_eventos_parse_month(string $mesParam, DateTimeImmutable $mesAtual): DateTimeImmutable
{
    if ($mesParam === '' || $mesParam === 'atual') {
        return $mesAtual;
    }

    if ($mesParam === 'seguinte') {
        return $mesAtual->modify('+1 month');
    }

    if (preg_match('/^\d{4}-\d{2}$/', $mesParam) === 1) {
        $mesSelecionado = DateTimeImmutable::createFromFormat('!Y-m', $mesParam);
        if ($mesSelecionado instanceof DateTimeImmutable) {
            return $mesSelecionado;
        }
    }

    return $mesAtual;
}

function agenda_eventos_clamp_month(
    DateTimeImmutable $mesSelecionado,
    DateTimeImmutable $mesMinimo,
    DateTimeImmutable $mesMaximo
): DateTimeImmutable {
    if ($mesSelecionado < $mesMinimo) {
        return $mesMinimo;
    }

    if ($mesSelecionado > $mesMaximo) {
        return $mesMaximo;
    }

    return $mesSelecionado;
}

function agenda_eventos_date_bounds(PDO $pdo, bool $isSuperadmin, array $spacesUsuario): array
{
    try {
        $params = [];
        $sql = "
            SELECT MIN(e.data_evento)::text AS min_data,
                   MAX(e.data_evento)::text AS max_data
            FROM logistica_eventos_espelho e
            WHERE COALESCE(e.arquivado, FALSE) = FALSE
        ";

        if (!$isSuperadmin) {
            if (empty($spacesUsuario)) {
                return ['min' => null, 'max' => null];
            }

            $placeholders = [];
            foreach ($spacesUsuario as $index => $space) {
                $key = ':space_bound_' . $index;
                $placeholders[] = $key;
                $params[$key] = $space;
            }
            $sql .= ' AND TRIM(COALESCE(e.space_visivel, \'\')) IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'min' => !empty($row['min_data']) ? (string)$row['min_data'] : null,
            'max' => !empty($row['max_data']) ? (string)$row['max_data'] : null,
        ];
    } catch (Throwable $e) {
        error_log('[AGENDA_EVENTOS_BOUNDS] ' . $e->getMessage());
        return ['min' => null, 'max' => null];
    }
}

function agenda_eventos_normalize_label(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', ' ', $value) ?: '';
}

function agenda_eventos_color_class(array $evento): string
{
    $text = agenda_eventos_normalize_label(
        (string)($evento['space_visivel'] ?? '') . ' ' . (string)($evento['local_evento'] ?? '')
    );

    if (strpos($text, 'diverkids') !== false) {
        return 'event-chip--diverkids';
    }

    if (strpos($text, 'cristal') !== false) {
        return 'event-chip--cristal';
    }

    if (strpos($text, 'garden') !== false) {
        return 'event-chip--garden';
    }

    if (
        strpos($text, 'lisbon 1') !== false
        || strpos($text, 'unidade 1') !== false
        || strpos($text, 'parque dos sinos') !== false
        || strpos($text, 'lisbon buffet') !== false
    ) {
        return 'event-chip--lisbon';
    }

    return 'event-chip--default';
}

function agenda_eventos_format_date(?string $iso): string
{
    $iso = trim((string)$iso);
    if ($iso === '') {
        return '-';
    }

    $ts = strtotime($iso);
    return $ts ? date('d/m/Y', $ts) : $iso;
}

function agenda_eventos_format_weekday(?string $iso): string
{
    $iso = trim((string)$iso);
    if ($iso === '') {
        return 'Evento';
    }

    $ts = strtotime($iso);
    return $ts ? dow_pt($ts) : 'Evento';
}

function agenda_eventos_format_clock(?string $time): string
{
    $time = trim((string)$time);
    if ($time === '') {
        return '';
    }

    if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
        return str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2];
    }

    $ts = strtotime($time);
    return $ts ? date('H:i', $ts) : '';
}

function agenda_eventos_format_time(array $evento): string
{
    $horaInicio = agenda_eventos_format_clock((string)($evento['hora_inicio'] ?? ''));
    $horaFim = agenda_eventos_format_clock((string)($evento['hora_fim'] ?? ''));
    if ($horaInicio === '') {
        return 'Sem horário informado';
    }

    return $horaFim !== '' ? $horaInicio . ' às ' . $horaFim : $horaInicio;
}

$usuarioId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$isSuperadmin = !empty($_SESSION['perm_superadmin']);
$spacesUsuario = $isSuperadmin ? [] : agenda_eventos_fetch_user_spaces($pdo, $usuarioId);
$eventoSelecionadoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
$eventoSelecionado = null;

$temTabelaEventos = agenda_eventos_has_table($pdo, 'logistica_eventos_espelho');
$temColunaNomeEvento = $temTabelaEventos && agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'nome_evento');
$temColunaHoraFim = $temTabelaEventos && agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'hora_fim');
$temTabelaReunioes = agenda_eventos_has_table($pdo, 'eventos_reunioes');
$temColunaClienteCadastro = $temTabelaEventos && agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id');
$temTabelaClientesCadastro = agenda_eventos_has_table($pdo, 'comercial_cadastro_clientes');

$mesAtual = new DateTimeImmutable('first day of this month');
$boundsEventos = $temTabelaEventos ? agenda_eventos_date_bounds($pdo, $isSuperadmin, $spacesUsuario) : ['min' => null, 'max' => null];
$primeiraDataEvento = !empty($boundsEventos['min'])
    ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$boundsEventos['min'])
    : null;
$ultimaDataEvento = !empty($boundsEventos['max'])
    ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$boundsEventos['max'])
    : null;
$mesLimiteInicial = $primeiraDataEvento instanceof DateTimeImmutable
    ? $primeiraDataEvento->modify('first day of this month')
    : $mesAtual;
$mesLimiteFinal = $ultimaDataEvento instanceof DateTimeImmutable
    ? $ultimaDataEvento->modify('first day of this month')
    : $mesAtual;
$hojeIso = (new DateTimeImmutable('today'))->format('Y-m-d');

$mesSelecionadoParam = trim((string)($_GET['mes'] ?? 'atual'));
$mesSelecionado = agenda_eventos_parse_month($mesSelecionadoParam, $mesAtual)->modify('first day of this month');
$mesSelecionado = agenda_eventos_clamp_month($mesSelecionado, $mesLimiteInicial, $mesLimiteFinal);
$inicioPeriodo = $primeiraDataEvento instanceof DateTimeImmutable ? $primeiraDataEvento : $mesLimiteInicial;
$fimPeriodo = $ultimaDataEvento instanceof DateTimeImmutable ? $ultimaDataEvento : $mesLimiteFinal->modify('last day of this month');
$inicioConsulta = $mesSelecionado;
$fimConsulta = $mesSelecionado->modify('last day of this month');
$mesAnteriorLink = $mesSelecionado > $mesLimiteInicial ? $mesSelecionado->modify('-1 month')->format('Y-m') : null;
$mesProximoLink = $mesSelecionado < $mesLimiteFinal ? $mesSelecionado->modify('+1 month')->format('Y-m') : null;
$weekDays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$eventos = [];
$eventosPorData = [];
$errors = [];

if ($temTabelaEventos) {
    $lastClienteSync = (int)($_SESSION['agenda_eventos_cliente_sync_at'] ?? 0);
    if ($lastClienteSync <= 0 || (time() - $lastClienteSync) > 600) {
        try {
            comercial_cliente_sync_future_from_local($pdo, 500);
            $_SESSION['agenda_eventos_cliente_sync_at'] = time();
            $temColunaClienteCadastro = agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id');
            $temTabelaClientesCadastro = agenda_eventos_has_table($pdo, 'comercial_cadastro_clientes');
        } catch (Throwable $e) {
            error_log('[AGENDA_EVENTOS_CLIENTE_SYNC] ' . $e->getMessage());
        }
    }
}

if (!$temTabelaEventos) {
    $errors[] = 'A tabela de eventos da logística ainda não existe. Execute a base da Logística antes de usar este módulo.';
} elseif (!$isSuperadmin && empty($spacesUsuario)) {
    $errors[] = 'Este usuário não possui nenhuma unidade/space marcada na aba "Unidade".';
} else {
    try {
        $joinReunioes = $temTabelaReunioes
            ? "
                LEFT JOIN LATERAL (
                    SELECT er.id AS meeting_id, er.me_event_snapshot
                    FROM eventos_reunioes er
                    WHERE er.me_event_id = e.me_event_id
                    ORDER BY er.updated_at DESC NULLS LAST, er.id DESC
                    LIMIT 1
                ) r ON TRUE
            "
            : '';

        $joinClientes = ($temColunaClienteCadastro && $temTabelaClientesCadastro)
            ? "LEFT JOIN comercial_cadastro_clientes cc ON cc.id = e.cliente_cadastro_id"
            : '';

        $nomeEventoSql = $temColunaNomeEvento
            ? "COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento sem nome')"
            : "'Evento sem nome'";

        $meetingIdSql = $temTabelaReunioes ? 'COALESCE(r.meeting_id, 0)' : '0';

        $horaFimSnapshotSql = $temTabelaReunioes
            ? "COALESCE(
                    NULLIF(TRIM(r.me_event_snapshot->>'hora_fim'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'horafim'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'horatermino'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'hora_termino'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'horaeventofim'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'fim'), '')
               )"
            : "NULL";
        $horaFimSql = $temColunaHoraFim
            ? "COALESCE(NULLIF(TO_CHAR(e.hora_fim, 'HH24:MI'), ''), {$horaFimSnapshotSql})"
            : $horaFimSnapshotSql;

        $snapshotLocalSql = $temTabelaReunioes
            ? "COALESCE(
                    NULLIF(TRIM(r.me_event_snapshot->>'local'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'nomelocal'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'localevento'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'localEvento'), '')
               )"
            : "NULL";

        $clienteSelectSql = ($temColunaClienteCadastro && $temTabelaClientesCadastro)
            ? "
                COALESCE(e.cliente_cadastro_id, 0) AS cliente_cadastro_id,
                COALESCE(NULLIF(TRIM(cc.nome_completo), ''), '') AS cliente_nome,
                COALESCE(NULLIF(TRIM(cc.email), ''), '') AS cliente_email,
                COALESCE(NULLIF(TRIM(cc.telefone_whatsapp), ''), '') AS cliente_telefone,
                COALESCE(NULLIF(TRIM(cc.documento_tipo), ''), '') AS cliente_documento_tipo,
                COALESCE(NULLIF(TRIM(cc.documento_numero), ''), '') AS cliente_documento_numero,
                COALESCE(NULLIF(TRIM(cc.rg), ''), '') AS cliente_rg,
            "
            : "
                0 AS cliente_cadastro_id,
                '' AS cliente_nome,
                '' AS cliente_email,
                '' AS cliente_telefone,
                '' AS cliente_documento_tipo,
                '' AS cliente_documento_numero,
                '' AS cliente_rg,
            ";

        $sql = "
            SELECT
                e.id,
                e.me_event_id,
                {$meetingIdSql} AS meeting_id,
                {$clienteSelectSql}
                e.data_evento::text AS data_evento,
                COALESCE(TO_CHAR(e.hora_inicio, 'HH24:MI'), '') AS hora_inicio,
                {$horaFimSql} AS hora_fim,
                {$nomeEventoSql} AS nome_evento,
                COALESCE(NULLIF(TRIM(e.localevento), ''), {$snapshotLocalSql}, 'Local não informado') AS local_evento,
                COALESCE(NULLIF(TRIM(e.space_visivel), ''), 'Não mapeado') AS space_visivel,
                COALESCE(NULLIF(TRIM(e.status_mapeamento), ''), 'PENDENTE') AS status_mapeamento,
                COALESCE(e.convidados, 0) AS convidados,
                COALESCE(e.idlocalevento, 0) AS id_local_me
            FROM logistica_eventos_espelho e
            {$joinReunioes}
            {$joinClientes}
            WHERE COALESCE(e.arquivado, FALSE) = FALSE
              AND e.data_evento BETWEEN :inicio AND :fim
        ";

        $params = [
            ':inicio' => $inicioConsulta->format('Y-m-d'),
            ':fim' => $fimConsulta->format('Y-m-d'),
        ];

        if (!$isSuperadmin) {
            $placeholders = [];
            foreach ($spacesUsuario as $index => $space) {
                $key = ':space_' . $index;
                $placeholders[] = $key;
                $params[$key] = $space;
            }
            $sql .= ' AND TRIM(COALESCE(e.space_visivel, \'\')) IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST, e.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $errors[] = 'Não foi possível carregar os eventos.';
        error_log('[AGENDA_EVENTOS] ' . $e->getMessage());
    }
}

if ($eventoSelecionadoId > 0 && $temTabelaEventos) {
    try {
        $joinReunioesDetalhe = $temTabelaReunioes
            ? "
                LEFT JOIN LATERAL (
                    SELECT er.id AS meeting_id, er.me_event_snapshot
                    FROM eventos_reunioes er
                    WHERE er.me_event_id = e.me_event_id
                    ORDER BY er.updated_at DESC NULLS LAST, er.id DESC
                    LIMIT 1
                ) r ON TRUE
            "
            : '';

        $joinClientesDetalhe = ($temColunaClienteCadastro && $temTabelaClientesCadastro)
            ? "LEFT JOIN comercial_cadastro_clientes cc ON cc.id = e.cliente_cadastro_id"
            : '';

        $nomeEventoDetalheSql = $temColunaNomeEvento
            ? "COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento sem nome')"
            : "'Evento sem nome'";

        $meetingIdDetalheSql = $temTabelaReunioes ? 'COALESCE(r.meeting_id, 0)' : '0';

        $horaFimDetalheSnapshotSql = $temTabelaReunioes
            ? "COALESCE(
                    NULLIF(TRIM(r.me_event_snapshot->>'hora_fim'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'horafim'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'horatermino'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'hora_termino'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'horaeventofim'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'fim'), '')
               )"
            : "NULL";
        $horaFimDetalheSql = $temColunaHoraFim
            ? "COALESCE(NULLIF(TO_CHAR(e.hora_fim, 'HH24:MI'), ''), {$horaFimDetalheSnapshotSql})"
            : $horaFimDetalheSnapshotSql;

        $snapshotLocalDetalheSql = $temTabelaReunioes
            ? "COALESCE(
                    NULLIF(TRIM(r.me_event_snapshot->>'local'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'nomelocal'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'localevento'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'localEvento'), '')
               )"
            : "NULL";

        $clienteDetalheSelectSql = ($temColunaClienteCadastro && $temTabelaClientesCadastro)
            ? "
                COALESCE(e.cliente_cadastro_id, 0) AS cliente_cadastro_id,
                COALESCE(NULLIF(TRIM(cc.nome_completo), ''), '') AS cliente_nome,
                COALESCE(NULLIF(TRIM(cc.email), ''), '') AS cliente_email,
                COALESCE(NULLIF(TRIM(cc.telefone_whatsapp), ''), '') AS cliente_telefone,
                COALESCE(NULLIF(TRIM(cc.documento_tipo), ''), '') AS cliente_documento_tipo,
                COALESCE(NULLIF(TRIM(cc.documento_numero), ''), '') AS cliente_documento_numero,
                COALESCE(NULLIF(TRIM(cc.rg), ''), '') AS cliente_rg,
            "
            : "
                0 AS cliente_cadastro_id,
                '' AS cliente_nome,
                '' AS cliente_email,
                '' AS cliente_telefone,
                '' AS cliente_documento_tipo,
                '' AS cliente_documento_numero,
                '' AS cliente_rg,
            ";

        $sqlDetalhe = "
            SELECT
                e.id,
                e.me_event_id,
                {$meetingIdDetalheSql} AS meeting_id,
                {$clienteDetalheSelectSql}
                e.data_evento::text AS data_evento,
                COALESCE(TO_CHAR(e.hora_inicio, 'HH24:MI'), '') AS hora_inicio,
                {$horaFimDetalheSql} AS hora_fim,
                {$nomeEventoDetalheSql} AS nome_evento,
                COALESCE(NULLIF(TRIM(e.localevento), ''), {$snapshotLocalDetalheSql}, 'Local não informado') AS local_evento,
                COALESCE(NULLIF(TRIM(e.space_visivel), ''), 'Não mapeado') AS space_visivel,
                COALESCE(NULLIF(TRIM(e.status_mapeamento), ''), 'PENDENTE') AS status_mapeamento,
                COALESCE(e.convidados, 0) AS convidados,
                COALESCE(e.idlocalevento, 0) AS id_local_me
            FROM logistica_eventos_espelho e
            {$joinReunioesDetalhe}
            {$joinClientesDetalhe}
            WHERE COALESCE(e.arquivado, FALSE) = FALSE
              AND e.id = :evento_id
        ";

        $paramsDetalhe = [':evento_id' => $eventoSelecionadoId];
        if (!$isSuperadmin) {
            if (empty($spacesUsuario)) {
                $sqlDetalhe .= " AND 1 = 0";
            } else {
                $placeholdersDetalhe = [];
                foreach ($spacesUsuario as $index => $space) {
                    $key = ':space_detalhe_' . $index;
                    $placeholdersDetalhe[] = $key;
                    $paramsDetalhe[$key] = $space;
                }
                $sqlDetalhe .= ' AND TRIM(COALESCE(e.space_visivel, \'\')) IN (' . implode(', ', $placeholdersDetalhe) . ')';
            }
        }

        $stmtDetalhe = $pdo->prepare($sqlDetalhe);
        $stmtDetalhe->execute($paramsDetalhe);
        $eventoSelecionado = $stmtDetalhe->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$eventoSelecionado) {
            $errors[] = 'Evento não encontrado ou sem acesso para este usuário.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Não foi possível carregar o detalhe do evento.';
        error_log('[AGENDA_EVENTOS_DETALHE] ' . $e->getMessage());
    }
}

foreach ($eventos as $evento) {
    $dataEvento = (string)($evento['data_evento'] ?? '');
    if ($dataEvento === '') {
        continue;
    }
    if (!isset($eventosPorData[$dataEvento])) {
        $eventosPorData[$dataEvento] = [];
    }
    $eventosPorData[$dataEvento][] = $evento;
}

includeSidebar('Agenda Geral');
?>

<style>
.agenda-eventos-page {
    max-width: 1480px;
    margin: 0 auto;
    padding: 1.5rem;
    background: #f8fafc;
}

.agenda-eventos-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}

.agenda-eventos-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 800;
    color: #1e3a8a;
}

.agenda-eventos-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.95rem;
}

.agenda-eventos-badges {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.agenda-badge {
    background: #ffffff;
    border: 1px solid #dbe3ef;
    border-radius: 999px;
    padding: 0.65rem 0.95rem;
    font-size: 0.85rem;
    color: #334155;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
}

.agenda-alert {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #1d4ed8;
    color: #334155;
    border-radius: 14px;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
}

.agenda-alert.error {
    border-left-color: #dc2626;
}

.calendar-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.event-detail-toolbar {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1rem 1.15rem;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
}

.calendar-nav {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
}

.calendar-nav-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    height: 42px;
    padding: 0 0.9rem;
    border-radius: 999px;
    text-decoration: none;
    background: #ffffff;
    border: 1px solid #dbe3ef;
    color: #1e293b;
    font-weight: 700;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
}

.calendar-nav-btn.is-disabled {
    opacity: 0.45;
    pointer-events: none;
}

.calendar-toolbar-title {
    margin: 0;
    font-size: 1.42rem;
    font-weight: 800;
    color: #0f172a;
}

.calendar-toolbar-subtitle {
    margin: 0.2rem 0 0;
    color: #64748b;
    font-size: 0.86rem;
}

.calendar-card {
    background: #ffffff;
    border: 1px solid #dbe3ef;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
}

.calendar-header {
    padding: 1rem 1.1rem;
    background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
    border-bottom: 1px solid #e2e8f0;
}

.calendar-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
}

.calendar-caption {
    font-size: 0.8rem;
    color: #64748b;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}

.weekday {
    padding: 0.8rem 0.45rem;
    text-align: center;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.75rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.day-cell {
    min-height: 150px;
    padding: 0.7rem;
    border-right: 1px solid #eef2f7;
    border-bottom: 1px solid #eef2f7;
    background: #ffffff;
}

.day-cell:nth-child(7n) {
    border-right: none;
}

.day-cell.is-empty {
    background: #f8fafc;
}

.day-cell.is-today {
    background: linear-gradient(180deg, #eff6ff 0%, #ffffff 100%);
}

.day-number {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.55rem;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
}

.today-pill {
    font-size: 0.68rem;
    font-weight: 700;
    color: #1d4ed8;
    background: #dbeafe;
    border-radius: 999px;
    padding: 0.16rem 0.45rem;
}

.day-events {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.event-chip {
    display: block;
    width: 100%;
    text-align: left;
    border: none;
    border-radius: 4px;
    padding: 0.55rem 0.6rem;
    cursor: pointer;
    text-decoration: none;
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.event-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 18px rgba(37, 99, 235, 0.12);
    border-color: #93c5fd;
}

.event-chip--lisbon {
    background: #f0643d;
    color: #ffffff;
}

.event-chip--diverkids {
    background: #62b4ee;
    color: #ffffff;
}

.event-chip--garden {
    background: #3b7f31;
    color: #ffffff;
}

.event-chip--cristal {
    background: #1500f5;
    color: #ffffff;
}

.event-chip--default {
    background: #64748b;
    color: #ffffff;
}

.event-chip-time {
    font-size: 0.72rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.95);
    margin-bottom: 0.18rem;
}

.event-chip-name {
    font-size: 0.8rem;
    font-weight: 700;
    color: #ffffff;
    line-height: 1.25;
}

.event-chip-meta {
    margin-top: 0.18rem;
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.9);
    line-height: 1.25;
}

.event-chip-guests {
    margin-top: 0.3rem;
    font-size: 0.72rem;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.95);
}

.event-more {
    font-size: 0.73rem;
    color: #475569;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    padding: 0.45rem 0.55rem;
}

.agenda-empty {
    background: #ffffff;
    border: 1px dashed #cbd5e1;
    border-radius: 16px;
    padding: 2rem;
    text-align: center;
    color: #64748b;
    margin-top: 1rem;
}

.agenda-modal {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    z-index: 1600;
}

.agenda-modal.is-open {
    display: flex;
}

.agenda-modal-card {
    width: min(100%, 560px);
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
    overflow: hidden;
}

.agenda-modal-header {
    padding: 1.1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.agenda-modal-title {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 800;
    color: #0f172a;
}

.agenda-modal-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

.agenda-modal-close {
    border: none;
    background: #f1f5f9;
    color: #334155;
    width: 36px;
    height: 36px;
    border-radius: 999px;
    cursor: pointer;
    font-size: 1.25rem;
}

.agenda-modal-body {
    padding: 1.1rem 1.25rem 1.25rem;
}

.agenda-modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.agenda-modal-field {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 0.85rem;
}

.agenda-modal-label {
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    margin-bottom: 0.3rem;
    font-weight: 700;
}

.agenda-modal-value {
    color: #0f172a;
    font-size: 0.94rem;
    font-weight: 600;
    line-height: 1.35;
    word-break: break-word;
}

.agenda-detail-layout {
    display: grid;
    grid-template-columns: minmax(300px, 380px) minmax(0, 1fr);
    gap: 1.25rem;
    align-items: start;
}

.event-side-panel,
.event-functions-panel {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 16px 38px rgba(15, 23, 42, 0.08);
}

.event-side-panel {
    overflow: hidden;
}

.event-side-title {
    margin: 0;
    padding: 1.3rem 1.35rem 0.4rem;
    color: #dc6047;
    font-size: 1.45rem;
    font-weight: 800;
    text-align: center;
    line-height: 1.2;
}

.event-avatar {
    width: 138px;
    height: 138px;
    margin: 0.8rem auto 1rem;
    border-radius: 999px;
    background: linear-gradient(180deg, #d5dbe4 0%, #bcc5d1 100%);
    position: relative;
    box-shadow: inset 0 -8px 16px rgba(15, 23, 42, 0.05);
}

.event-avatar::before,
.event-avatar::after {
    content: "";
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    background: #ffffff;
}

.event-avatar::before {
    top: 31px;
    width: 50px;
    height: 50px;
    border-radius: 999px;
}

.event-avatar::after {
    bottom: 27px;
    width: 90px;
    height: 45px;
    border-radius: 48px 48px 18px 18px;
}

.event-side-summary {
    padding: 0 1.35rem 0.35rem;
    text-align: center;
    color: #475569;
    font-size: 0.98rem;
    line-height: 1.55;
}

.event-type-dot {
    display: inline-flex;
    width: 44px;
    height: 44px;
    border-radius: 999px;
    align-items: center;
    justify-content: center;
    margin: 0.75rem auto 0.9rem;
    color: #ffffff;
    font-weight: 800;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.16);
}

.event-info-block {
    margin: 0 1.1rem 1rem;
    border-left: 4px solid #62b4ee;
    background: #f8fafc;
    border-radius: 10px;
    padding: 0.9rem 0.95rem;
    color: #475569;
    line-height: 1.55;
    font-size: 0.92rem;
}

.event-info-block.event-info-block--place {
    border-left-color: #70bd68;
    background: #f5fbf4;
}

.event-info-label {
    display: block;
    color: #1f2937;
    font-weight: 800;
    margin-bottom: 0.35rem;
}

.event-client-link {
    color: #2878b8;
    font-weight: 800;
    text-decoration: none;
}

.event-client-link:hover {
    text-decoration: underline;
}

.event-client-muted {
    color: #94a3b8;
    font-size: 0.84rem;
}

.event-functions-panel {
    min-height: 620px;
    padding: 1.25rem;
}

.event-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.6rem;
    margin-bottom: 1.15rem;
}

.event-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    border: none;
    border-radius: 10px;
    padding: 0.68rem 1rem;
    color: #ffffff;
    background: #5ebfd4;
    font-weight: 800;
    font-size: 0.9rem;
    text-decoration: none;
    box-shadow: 0 10px 20px rgba(14, 116, 144, 0.16);
    transition: transform 0.16s ease, box-shadow 0.16s ease, filter 0.16s ease;
}

.event-action-btn:hover {
    transform: translateY(-1px);
    filter: brightness(0.98);
    box-shadow: 0 14px 28px rgba(14, 116, 144, 0.2);
}

.event-action-btn.event-action-btn--green {
    background: #58c786;
    width: 44px;
    padding-inline: 0;
}

.event-functions-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(180px, 1fr));
    gap: 1rem;
}

.event-function-card {
    min-height: 172px;
    border: 1px solid #dbe3ef;
    border-radius: 12px;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.7rem;
    padding: 1.15rem;
    text-align: center;
    text-decoration: none;
    color: inherit;
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

a.event-function-card:hover {
    transform: translateY(-3px);
    border-color: #93c5fd;
    box-shadow: 0 18px 36px rgba(37, 99, 235, 0.13);
}

.event-function-icon {
    width: 64px;
    height: 64px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 16px;
    background: #eff6ff;
    font-size: 2.25rem;
    line-height: 1;
    color: #4d8df7;
}

.event-function-title {
    color: #2f638a;
    font-size: 1.18rem;
    font-weight: 800;
    line-height: 1.15;
}

.event-function-pill {
    display: inline-flex;
    border-radius: 999px;
    padding: 0.27rem 0.72rem;
    background: #73b866;
    color: #ffffff;
    font-size: 0.76rem;
    font-weight: 800;
}

@media (max-width: 720px) {
    .agenda-eventos-page {
        padding: 1rem;
    }

    .calendar-toolbar {
        align-items: flex-start;
    }

    .day-cell {
        min-height: 120px;
        padding: 0.55rem;
    }

    .agenda-modal-grid {
        grid-template-columns: 1fr;
    }

    .calendar-title {
        font-size: 1rem;
    }

    .agenda-detail-layout {
        grid-template-columns: 1fr;
    }

    .event-functions-panel {
        padding: 1rem;
        min-height: 0;
    }

    .event-functions-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="agenda-eventos-page">
    <?php if (!($eventoSelecionadoId > 0 && is_array($eventoSelecionado))): ?>
        <div class="agenda-eventos-header">
            <div>
                <h1 class="agenda-eventos-title">Agenda Geral</h1>
                <p class="agenda-eventos-subtitle">Calendário mensal com todos os eventos importados da ME, filtrado pelas unidades marcadas no usuário.</p>
            </div>
            <div class="agenda-eventos-badges">
                <div class="agenda-badge">Período: <?= h($inicioPeriodo->format('d/m/Y')) ?> até <?= h($fimPeriodo->format('d/m/Y')) ?></div>
                <div class="agenda-badge">Eventos: <?= count($eventos) ?></div>
                <?php if ($isSuperadmin): ?>
                    <div class="agenda-badge">Visualização: todas as unidades</div>
                <?php else: ?>
                    <div class="agenda-badge">Unidades: <?= h(implode(', ', $spacesUsuario)) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($errors) && $eventoSelecionadoId > 0 && is_array($eventoSelecionado)): ?>
        <?php
        $eventColorClass = agenda_eventos_color_class($eventoSelecionado);
        $eventColorMap = [
            'event-chip--lisbon' => '#f0643d',
            'event-chip--diverkids' => '#62b4ee',
            'event-chip--garden' => '#3b7f31',
            'event-chip--cristal' => '#1500f5',
            'event-chip--default' => '#64748b',
        ];
        $eventColor = $eventColorMap[$eventColorClass] ?? '#64748b';
        $eventMesLink = '';
        if (!empty($eventoSelecionado['data_evento']) && preg_match('/^\d{4}-\d{2}/', (string)$eventoSelecionado['data_evento']) === 1) {
            $eventMesLink = substr((string)$eventoSelecionado['data_evento'], 0, 7);
        }
        $meetingId = (int)($eventoSelecionado['meeting_id'] ?? 0);
        $meEventId = (int)($eventoSelecionado['me_event_id'] ?? 0);
        $organizacaoHref = $meetingId > 0
            ? 'index.php?page=eventos_organizacao&id=' . $meetingId
            : 'index.php?page=eventos_organizacao' . ($meEventId > 0 ? '&me_event_id=' . $meEventId : '');
        $historicoHref = $meetingId > 0
            ? 'index.php?page=eventos_historico&meeting_id=' . $meetingId
            : 'index.php?page=eventos_historico' . ($meEventId > 0 ? '&me_event_id=' . $meEventId : '');
        $clienteCadastroId = (int)($eventoSelecionado['cliente_cadastro_id'] ?? 0);
        $clienteNome = trim((string)($eventoSelecionado['cliente_nome'] ?? ''));
        if ($clienteNome === '') {
            $clienteNome = trim((string)($eventoSelecionado['nome_evento'] ?? 'Cliente não identificado'));
        }
        $clienteEmail = trim((string)($eventoSelecionado['cliente_email'] ?? ''));
        $clienteTelefone = trim((string)($eventoSelecionado['cliente_telefone'] ?? ''));
        $clienteDocumentoTipo = trim((string)($eventoSelecionado['cliente_documento_tipo'] ?? ''));
        $clienteDocumentoNumero = trim((string)($eventoSelecionado['cliente_documento_numero'] ?? ''));
        $clienteRg = trim((string)($eventoSelecionado['cliente_rg'] ?? ''));
        $clienteCadastroHref = $clienteCadastroId > 0
            ? 'index.php?page=comercial_cadastro_cliente&id=' . $clienteCadastroId
            : 'index.php?page=comercial_clientes_cadastrados' . ($clienteNome !== '' ? '&search=' . urlencode($clienteNome) : '');

        $cards = [
            ['icon' => '☑️', 'title' => 'Checklist', 'pill' => '10% / 90%'],
            ['icon' => '🧮', 'title' => 'Financeiro do Evento', 'pill' => 'Pagamentos em Dia', 'href' => 'index.php?page=eventos_financeiro&evento_id=' . (int)$eventoSelecionado['id']],
            ['icon' => '🖥️', 'title' => 'Organização do evento', 'pill' => 'Portal do cliente', 'href' => $organizacaoHref],
            ['icon' => '🏷️', 'title' => 'Fornecedores', 'pill' => '0'],
            ['icon' => '📄', 'title' => 'Contratos e Documentos', 'pill' => ''],
            ['icon' => '❕', 'title' => 'Histórico', 'pill' => '', 'href' => $historicoHref],
        ];
        ?>
        <div class="agenda-detail-layout">
            <aside class="event-side-panel">
                <h2 class="event-side-title"><?= h((string)($eventoSelecionado['nome_evento'] ?? 'Evento')) ?></h2>
                <div class="event-avatar" aria-hidden="true"></div>

                <div class="event-side-summary">
                    <div>★ <?= h((string)($eventoSelecionado['space_visivel'] ?? 'Unidade não mapeada')) ?></div>
                    <div>● <?= (int)($eventoSelecionado['convidados'] ?? 0) ?> participantes</div>
                    <div class="event-type-dot" style="background: <?= h($eventColor) ?>">TI</div>
                </div>

                <div class="event-info-block" style="border-left-color: <?= h($eventColor) ?>">
                    <span class="event-info-label">📅 <?= h(agenda_eventos_format_date((string)($eventoSelecionado['data_evento'] ?? ''))) ?></span>
                    <div><?= h(agenda_eventos_format_weekday((string)($eventoSelecionado['data_evento'] ?? ''))) ?></div>
                    <div><strong>Horário:</strong> <?= h(agenda_eventos_format_time($eventoSelecionado)) ?></div>
                    <div><strong>Local:</strong> <?= h((string)($eventoSelecionado['local_evento'] ?? 'Local não informado')) ?></div>
                </div>

                <div class="event-info-block event-info-block--place">
                    <span class="event-info-label">📍 Local do Evento</span>
                    <div><?= h((string)($eventoSelecionado['local_evento'] ?? 'Local não informado')) ?></div>
                    <div><?= h((string)($eventoSelecionado['space_visivel'] ?? 'Unidade não mapeada')) ?></div>
                </div>

                <div class="event-info-block">
                    <span class="event-info-label">ℹ️ Dados do Cliente</span>
                    <div>
                        👤 <a class="event-client-link" href="<?= h($clienteCadastroHref) ?>"><?= h($clienteNome) ?></a>
                    </div>
                    <?php if ($clienteEmail !== ''): ?>
                        <div>✉️ <?= h($clienteEmail) ?></div>
                    <?php endif; ?>
                    <?php if ($clienteTelefone !== ''): ?>
                        <div>☎ <?= h($clienteTelefone) ?></div>
                    <?php endif; ?>
                    <?php if ($clienteDocumentoNumero !== ''): ?>
                        <div><?= h($clienteDocumentoTipo !== '' ? $clienteDocumentoTipo : 'Documento') ?>: <?= h($clienteDocumentoNumero) ?></div>
                    <?php endif; ?>
                    <?php if ($clienteRg !== ''): ?>
                        <div>RG: <?= h($clienteRg) ?></div>
                    <?php endif; ?>
                    <?php if ($clienteCadastroId <= 0): ?>
                        <div class="event-client-muted">Cadastro local pendente de sincronização.</div>
                    <?php endif; ?>
                </div>
            </aside>

            <section class="event-functions-panel">
                <div class="event-actions">
                    <a class="event-action-btn" href="index.php?page=agenda_eventos<?= $eventMesLink !== '' ? '&mes=' . h($eventMesLink) : '' ?>">← Agenda</a>
                    <button type="button" class="event-action-btn" onclick="window.print()">🖨️ Imprimir</button>
                    <a class="event-action-btn event-action-btn--green" href="index.php?page=agenda_eventos&evento_id=<?= (int)$eventoSelecionado['id'] ?>">🔗</a>
                </div>

                <div class="event-functions-grid">
                    <?php foreach ($cards as $card): ?>
                        <?php if (!empty($card['href'])): ?>
                            <a class="event-function-card" href="<?= h((string)$card['href']) ?>">
                                <div class="event-function-icon" aria-hidden="true"><?= h($card['icon']) ?></div>
                                <div class="event-function-title"><?= h($card['title']) ?></div>
                                <?php if ($card['pill'] !== ''): ?>
                                    <div class="event-function-pill"><?= h($card['pill']) ?></div>
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <div class="event-function-card">
                                <div class="event-function-icon" aria-hidden="true"><?= h($card['icon']) ?></div>
                                <div class="event-function-title"><?= h($card['title']) ?></div>
                                <?php if ($card['pill'] !== ''): ?>
                                    <div class="event-function-pill"><?= h($card['pill']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    <?php elseif (empty($errors)): ?>
        <div class="calendar-toolbar">
            <div>
                <h2 class="calendar-toolbar-title"><?= h(agenda_eventos_month_label($mesSelecionado)) ?></h2>
                <p class="calendar-toolbar-subtitle">Exibindo um mês por vez dentro de todo o período importado.</p>
            </div>
            <div class="calendar-nav">
                <a href="index.php?page=agenda_eventos<?= $mesAnteriorLink !== null ? '&mes=' . urlencode($mesAnteriorLink) : '' ?>" class="calendar-nav-btn<?= $mesAnteriorLink === null ? ' is-disabled' : '' ?>" aria-label="Mês anterior"<?= $mesAnteriorLink === null ? ' aria-disabled="true" tabindex="-1"' : '' ?>>←</a>
                <a href="index.php?page=agenda_eventos<?= $mesProximoLink !== null ? '&mes=' . urlencode($mesProximoLink) : '' ?>" class="calendar-nav-btn<?= $mesProximoLink === null ? ' is-disabled' : '' ?>" aria-label="Próximo mês"<?= $mesProximoLink === null ? ' aria-disabled="true" tabindex="-1"' : '' ?>>→</a>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="agenda-alert error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if (empty($errors) && !($eventoSelecionadoId > 0 && is_array($eventoSelecionado))): ?>
        <?php
        $monthStart = $mesSelecionado->modify('first day of this month');
        $daysInMonth = (int)$monthStart->format('t');
        $firstWeekday = (int)$monthStart->format('w');
        ?>
        <section class="calendar-card">
            <div class="calendar-header">
                <h2 class="calendar-title"><?= h(agenda_eventos_month_label($monthStart)) ?></h2>
            </div>

            <div class="calendar-grid">
                <?php foreach ($weekDays as $weekDay): ?>
                    <div class="weekday"><?= h($weekDay) ?></div>
                <?php endforeach; ?>

                <?php for ($blank = 0; $blank < $firstWeekday; $blank++): ?>
                    <div class="day-cell is-empty"></div>
                <?php endfor; ?>

                <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                    <?php
                    $dateObj = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('m'), $day);
                    $dateKey = $dateObj->format('Y-m-d');
                    $dayEvents = $eventosPorData[$dateKey] ?? [];
                    $isToday = $dateKey === $hojeIso;
                    ?>
                    <div class="day-cell<?= $isToday ? ' is-today' : '' ?>">
                        <div class="day-number">
                            <span><?= $day ?></span>
                            <?php if ($isToday): ?>
                                <span class="today-pill">Hoje</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($dayEvents)): ?>
                            <div class="day-events">
                                <?php foreach (array_slice($dayEvents, 0, 3) as $evento): ?>
                                    <?php
                                    $eventClass = agenda_eventos_color_class($evento);
                                    $horario = agenda_eventos_format_time($evento);
                                    ?>
                                    <a class="event-chip <?= h($eventClass) ?>" href="index.php?page=agenda_eventos&evento_id=<?= (int)($evento['id'] ?? 0) ?>&mes=<?= h($mesSelecionado->format('Y-m')) ?>">
                                        <div class="event-chip-time"><?= h($horario) ?></div>
                                        <div class="event-chip-name"><?= h((string)($evento['nome_evento'] ?? 'Evento')) ?></div>
                                        <div class="event-chip-meta"><?= h((string)($evento['local_evento'] ?? 'Local não informado')) ?></div>
                                        <div class="event-chip-guests">Convidados: <?= (int)($evento['convidados'] ?? 0) ?></div>
                                    </a>
                                <?php endforeach; ?>

                                <?php if (count($dayEvents) > 3): ?>
                                    <div class="event-more">+<?= count($dayEvents) - 3 ?> evento(s) neste dia</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </section>

        <?php if (empty($eventos)): ?>
            <div class="agenda-empty">
                Nenhum evento encontrado para as unidades selecionadas neste mês.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php endSidebar(); ?>
