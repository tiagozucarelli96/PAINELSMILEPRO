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
require_once __DIR__ . '/eventos_me_helper.php';
require_once __DIR__ . '/agenda_eventos_sync_helper.php';
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

function agenda_eventos_salvar_foto_evento(PDO $pdo, int $eventoId, string $dataUrl): array
{
    if ($eventoId <= 0) {
        return ['ok' => false, 'error' => 'Evento inválido.'];
    }
    if (preg_match('/^data:image\/(png|jpe?g|webp);base64,(.+)$/i', $dataUrl, $matches) !== 1) {
        return ['ok' => false, 'error' => 'Envie uma imagem válida.'];
    }
    $binary = base64_decode($matches[2], true);
    if ($binary === false || strlen($binary) < 100) {
        return ['ok' => false, 'error' => 'Não foi possível ler a imagem enviada.'];
    }
    if (@getimagesizefromstring($binary) === false) {
        return ['ok' => false, 'error' => 'Arquivo de imagem inválido.'];
    }

    $dir = __DIR__ . '/uploads/eventos_fotos';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Não foi possível criar a pasta de fotos.'];
    }

    $filename = 'evento_' . $eventoId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $path = $dir . '/' . $filename;
    if (file_put_contents($path, $binary) === false) {
        return ['ok' => false, 'error' => 'Não foi possível salvar a foto.'];
    }

    $url = 'uploads/eventos_fotos/' . $filename;
    $stmt = $pdo->prepare("UPDATE logistica_eventos_espelho SET foto_evento_url = :foto WHERE id = :id");
    $stmt->execute([':foto' => $url, ':id' => $eventoId]);
    return ['ok' => true, 'url' => $url];
}

function agenda_eventos_normalizar_hora(string $hora): ?string
{
    $hora = trim($hora);
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hora) !== 1) {
        return null;
    }
    return $hora . ':00';
}

function agenda_eventos_atualizar_data_horario(PDO $pdo, int $eventoId, string $dataEvento, string $horaInicio, string $horaFim): array
{
    if ($eventoId <= 0) {
        return ['ok' => false, 'error' => 'Evento inválido.'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataEvento) !== 1) {
        return ['ok' => false, 'error' => 'Informe uma data válida.'];
    }
    $inicioNormalizado = agenda_eventos_normalizar_hora($horaInicio);
    $fimNormalizado = agenda_eventos_normalizar_hora($horaFim);
    if ($inicioNormalizado === null || $fimNormalizado === null) {
        return ['ok' => false, 'error' => 'Informe horários válidos.'];
    }

    $stmt = $pdo->prepare("
        UPDATE logistica_eventos_espelho
        SET data_evento = :data_evento,
            hora_inicio = :hora_inicio,
            hora_fim = :hora_fim
        WHERE id = :id
    ");
    $stmt->execute([
        ':data_evento' => $dataEvento,
        ':hora_inicio' => $inicioNormalizado,
        ':hora_fim' => $fimNormalizado,
        ':id' => $eventoId,
    ]);

    return ['ok' => true];
}

function agenda_eventos_sync_me_range(PDO $pdo, DateTimeImmutable $inicio, DateTimeImmutable $fim, bool $force = false): array
{
    if (!function_exists('eventos_me_request') || !function_exists('agenda_eventos_sync_me_payload')) {
        return ['ok' => false, 'synced' => 0, 'error' => 'Helpers da ME indisponíveis.'];
    }

    $inicio = $inicio->setTime(0, 0);
    $fim = $fim->setTime(0, 0);
    if ($fim < $inicio) {
        return ['ok' => false, 'synced' => 0, 'error' => 'Intervalo inválido.'];
    }

    $cacheKey = 'agenda_eventos_me_sync_' . $inicio->format('Ymd') . '_' . $fim->format('Ymd');
    $lastSync = (int)($_SESSION[$cacheKey] ?? 0);
    if (!$force && $lastSync > 0 && (time() - $lastSync) < 1800) {
        return ['ok' => true, 'synced' => 0, 'cached' => true];
    }

    $synced = 0;
    $current = $inicio;
    while ($current <= $fim) {
        $chunkEnd = $current->modify('+89 days');
        if ($chunkEnd > $fim) {
            $chunkEnd = $fim;
        }

        $resp = eventos_me_request('GET', '/api/v1/events', [
            'start' => $current->format('Y-m-d'),
            'end' => $chunkEnd->format('Y-m-d'),
            'limit' => 500,
        ]);

        if (empty($resp['ok'])) {
            error_log('[AGENDA_EVENTOS_ME_SYNC] ' . (string)($resp['error'] ?? 'Falha ao buscar eventos na ME.'));
            return ['ok' => false, 'synced' => $synced, 'error' => (string)($resp['error'] ?? 'Falha ao buscar eventos na ME.')];
        }

        $items = [];
        $raw = $resp['data'] ?? [];
        if (is_array($raw)) {
            $items = $raw['data'] ?? $raw['eventos'] ?? $raw;
        }
        if (!is_array($items)) {
            $items = [];
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $result = agenda_eventos_sync_me_payload($pdo, $item);
            if (!empty($result['ok'])) {
                $synced++;
            }
        }

        $current = $chunkEnd->modify('+1 day');
    }

    $_SESSION[$cacheKey] = time();
    return ['ok' => true, 'synced' => $synced];
}

$usuarioId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$isSuperadmin = !empty($_SESSION['perm_superadmin']);
$canViewEventDetails = $isSuperadmin || !empty($_SESSION['perm_agenda_eventos_detalhes']);
$spacesUsuario = $isSuperadmin ? [] : agenda_eventos_fetch_user_spaces($pdo, $usuarioId);
$eventoSelecionadoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
$eventoSelecionado = null;

$temTabelaEventos = agenda_eventos_has_table($pdo, 'logistica_eventos_espelho');
$temColunaNomeEvento = $temTabelaEventos && agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'nome_evento');
$temColunaHoraFim = $temTabelaEventos && agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'hora_fim');
$temTabelaReunioes = agenda_eventos_has_table($pdo, 'eventos_reunioes');
$temTabelaComercialEventosPainel = agenda_eventos_has_table($pdo, 'comercial_eventos_painel');
$temColunaClienteCadastro = $temTabelaEventos && agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id');
$temTabelaClientesCadastro = agenda_eventos_has_table($pdo, 'comercial_cadastro_clientes');
$temColunaFotoEvento = false;
if ($temTabelaEventos) {
    try {
        $pdo->exec("ALTER TABLE logistica_eventos_espelho ADD COLUMN IF NOT EXISTS foto_evento_url TEXT NULL");
        $temColunaFotoEvento = agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'foto_evento_url');
    } catch (Throwable $e) {
        error_log('[AGENDA_EVENTOS_FOTO_SCHEMA] ' . $e->getMessage());
    }
}

$mesAtual = new DateTimeImmutable('first day of this month');
$forcarSyncAgenda = isset($_GET['sync']) || isset($_GET['refresh']);
if ($temTabelaEventos) {
    try {
        agenda_eventos_sync_me_range(
            $pdo,
            (new DateTimeImmutable('first day of this month'))->modify('-2 months'),
            (new DateTimeImmutable('first day of this month'))->modify('+18 months')->modify('last day of this month'),
            $forcarSyncAgenda
        );
    } catch (Throwable $e) {
        error_log('[AGENDA_EVENTOS_ME_RANGE_SYNC] ' . $e->getMessage());
    }
}
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
$messages = [];
if (!empty($_SESSION['agenda_eventos_success'])) {
    $messages[] = (string)$_SESSION['agenda_eventos_success'];
    unset($_SESSION['agenda_eventos_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'salvar_foto_evento') {
    $postEventoId = (int)($_POST['evento_id'] ?? 0);
    if (!$canViewEventDetails) {
        $errors[] = 'Você não possui permissão para alterar a foto do evento.';
    } else {
        $resultadoFoto = agenda_eventos_salvar_foto_evento($pdo, $postEventoId, (string)($_POST['foto_evento_data'] ?? ''));
        if (!empty($resultadoFoto['ok'])) {
            $_SESSION['agenda_eventos_success'] = 'Foto do evento atualizada.';
            header('Location: index.php?page=agenda_eventos&evento_id=' . $postEventoId);
            exit;
        }
        $errors[] = (string)($resultadoFoto['error'] ?? 'Não foi possível salvar a foto do evento.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'atualizar_data_horario_evento') {
    $postEventoId = (int)($_POST['evento_id'] ?? 0);
    if (!$isSuperadmin) {
        $errors[] = 'Apenas superadmin pode alterar data e horários do evento.';
    } else {
        $resultadoHorario = agenda_eventos_atualizar_data_horario(
            $pdo,
            $postEventoId,
            (string)($_POST['data_evento'] ?? ''),
            (string)($_POST['hora_inicio'] ?? ''),
            (string)($_POST['hora_fim'] ?? '')
        );
        if (!empty($resultadoHorario['ok'])) {
            $_SESSION['agenda_eventos_success'] = 'Data e horários do evento atualizados.';
            header('Location: index.php?page=agenda_eventos&evento_id=' . $postEventoId);
            exit;
        }
        $errors[] = (string)($resultadoHorario['error'] ?? 'Não foi possível atualizar data e horários do evento.');
    }
}

if ($eventoSelecionadoId > 0 && !$canViewEventDetails) {
    $eventoSelecionadoId = 0;
    $errors[] = 'Você não possui permissão para visualizar os detalhes completos dos eventos.';
}

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
        $fotoEventoSql = $temColunaFotoEvento
            ? "COALESCE(NULLIF(TRIM(e.foto_evento_url), ''), '')"
            : "''";

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
                {$fotoEventoSql} AS foto_evento_url,
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
                    SELECT er.id AS meeting_id, er.me_event_snapshot, er.tipo_evento_real
                    FROM eventos_reunioes er
                    WHERE er.me_event_id = e.me_event_id
                    ORDER BY er.updated_at DESC NULLS LAST, er.id DESC
                    LIMIT 1
                ) r ON TRUE
            "
            : '';

        $joinComercialPainelDetalhe = $temTabelaComercialEventosPainel
            ? "
                LEFT JOIN LATERAL (
                    SELECT tipo_evento_real
                    FROM comercial_eventos_painel
                    WHERE espelho_evento_id = e.id
                    ORDER BY updated_at DESC NULLS LAST, id DESC
                    LIMIT 1
                ) cep ON TRUE
            "
            : '';

        $joinClientesDetalhe = ($temColunaClienteCadastro && $temTabelaClientesCadastro)
            ? "LEFT JOIN comercial_cadastro_clientes cc ON cc.id = e.cliente_cadastro_id"
            : '';

        $nomeEventoDetalheSql = $temColunaNomeEvento
            ? "COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento sem nome')"
            : "'Evento sem nome'";
        $fotoEventoDetalheSql = $temColunaFotoEvento
            ? "COALESCE(NULLIF(TRIM(e.foto_evento_url), ''), '')"
            : "''";

        $meetingIdDetalheSql = $temTabelaReunioes ? 'COALESCE(r.meeting_id, 0)' : '0';
        $tipoEventoRealDetalheSql = "''";
        if ($temTabelaComercialEventosPainel && $temTabelaReunioes) {
            $tipoEventoRealDetalheSql = "COALESCE(NULLIF(TRIM(cep.tipo_evento_real), ''), NULLIF(TRIM(r.tipo_evento_real), ''), NULLIF(TRIM(r.me_event_snapshot->>'tipo_evento_real'), ''), '')";
        } elseif ($temTabelaComercialEventosPainel) {
            $tipoEventoRealDetalheSql = "COALESCE(NULLIF(TRIM(cep.tipo_evento_real), ''), '')";
        } elseif ($temTabelaReunioes) {
            $tipoEventoRealDetalheSql = "COALESCE(NULLIF(TRIM(r.tipo_evento_real), ''), NULLIF(TRIM(r.me_event_snapshot->>'tipo_evento_real'), ''), '')";
        }

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
                {$tipoEventoRealDetalheSql} AS tipo_evento_real,
                e.data_evento::text AS data_evento,
                COALESCE(TO_CHAR(e.hora_inicio, 'HH24:MI'), '') AS hora_inicio,
                {$horaFimDetalheSql} AS hora_fim,
                {$nomeEventoDetalheSql} AS nome_evento,
                COALESCE(NULLIF(TRIM(e.localevento), ''), {$snapshotLocalDetalheSql}, 'Local não informado') AS local_evento,
                COALESCE(NULLIF(TRIM(e.space_visivel), ''), 'Não mapeado') AS space_visivel,
                {$fotoEventoDetalheSql} AS foto_evento_url,
                COALESCE(NULLIF(TRIM(e.status_mapeamento), ''), 'PENDENTE') AS status_mapeamento,
                COALESCE(e.convidados, 0) AS convidados,
                COALESCE(e.idlocalevento, 0) AS id_local_me
            FROM logistica_eventos_espelho e
            {$joinReunioesDetalhe}
            {$joinComercialPainelDetalhe}
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

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css">

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

.agenda-alert.success {
    border-left-color: #16a34a;
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
    overflow: visible;
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
    position: relative;
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

.event-chip--locked {
    cursor: default;
}

.event-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 18px rgba(37, 99, 235, 0.12);
    border-color: #93c5fd;
}

.event-chip--locked:hover {
    transform: none;
    box-shadow: none;
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
    position: relative;
}

.event-more summary {
    list-style: none;
    font-size: 0.73rem;
    color: #475569;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    padding: 0.45rem 0.55rem;
    cursor: pointer;
}

.event-more summary::-webkit-details-marker {
    display: none;
}

.event-more-popover {
    position: absolute;
    z-index: 30;
    left: 0;
    right: auto;
    bottom: calc(100% + 0.45rem);
    width: min(280px, calc(100vw - 2rem));
    max-height: 360px;
    overflow: auto;
    display: grid;
    gap: 0.45rem;
    padding: 0.55rem;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.18);
}

.day-cell:nth-child(7n) .event-more-popover,
.day-cell:nth-child(7n - 1) .event-more-popover {
    left: auto;
    right: 0;
}

.event-more-popover-title {
    font-size: 0.74rem;
    font-weight: 800;
    color: #334155;
    padding: 0.1rem 0.15rem;
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
    display: block;
    width: 138px;
    height: 138px;
    margin: 0.8rem auto 1rem;
    border-radius: 999px;
    background: linear-gradient(180deg, #d5dbe4 0%, #bcc5d1 100%);
    position: relative;
    box-shadow: inset 0 -8px 16px rgba(15, 23, 42, 0.05);
    border: 0;
    padding: 0;
    cursor: pointer;
    overflow: hidden;
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

.event-avatar.has-photo {
    background: #e2e8f0;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
}

.event-avatar.has-photo::before,
.event-avatar.has-photo::after {
    display: none;
}

.event-avatar img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
}

.event-avatar-overlay {
    position: absolute;
    inset: auto 0 0;
    padding: 0.45rem 0.35rem;
    background: rgba(15, 23, 42, 0.68);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 800;
    opacity: 0;
    transition: opacity .16s ease;
}

.event-avatar:hover .event-avatar-overlay {
    opacity: 1;
}

.event-avatar:not(.has-photo) .event-avatar-overlay {
    opacity: .95;
}

.event-photo-modal {
    position: fixed;
    inset: 0;
    z-index: 1500;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, 0.58);
}

.event-photo-modal.open {
    display: flex;
}

.event-photo-card {
    width: min(760px, 100%);
    max-height: calc(100vh - 2rem);
    overflow: auto;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
}

.event-photo-head,
.event-photo-actions {
    display: flex;
    justify-content: space-between;
    gap: .75rem;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.event-photo-actions {
    border-top: 1px solid #e2e8f0;
    border-bottom: 0;
    justify-content: flex-end;
}

.event-photo-title {
    margin: 0;
    color: #1e293b;
    font-size: 1.1rem;
    font-weight: 900;
}

.event-photo-body {
    padding: 1rem;
}

.event-photo-input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: .8rem;
    margin-bottom: 1rem;
}

.event-photo-stage {
    min-height: 360px;
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.event-photo-stage img {
    max-width: 100%;
    display: block;
}

.event-photo-placeholder {
    color: #64748b;
    font-weight: 700;
    text-align: center;
    padding: 1rem;
}

.event-photo-close {
    width: 36px;
    height: 36px;
    border: 0;
    border-radius: 999px;
    background: #f1f5f9;
    color: #334155;
    font-weight: 900;
    cursor: pointer;
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

.event-info-block--button {
    display: block;
    width: calc(100% - 2.2rem);
    text-align: left;
    border-top: 0;
    border-right: 0;
    border-bottom: 0;
    cursor: pointer;
    font: inherit;
}

.event-info-block--button:hover {
    background: #f1f5f9;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
}

.event-info-block.event-info-block--place {
    border-left-color: #70bd68;
    background: #f5fbf4;
}

.event-schedule-hint {
    margin-top: .35rem;
    color: #64748b;
    font-size: .78rem;
    font-weight: 800;
}

.event-datetime-modal {
    position: fixed;
    inset: 0;
    z-index: 1500;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(15, 23, 42, .58);
}

.event-datetime-modal.open {
    display: flex;
}

.event-datetime-card {
    width: min(560px, 100%);
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
    overflow: hidden;
}

.event-datetime-head,
.event-datetime-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    padding: 1rem;
    border-bottom: 1px solid #e2e8f0;
}

.event-datetime-actions {
    border-top: 1px solid #e2e8f0;
    border-bottom: 0;
    justify-content: flex-end;
}

.event-datetime-title {
    margin: 0;
    color: #1e293b;
    font-size: 1.1rem;
    font-weight: 900;
}

.event-datetime-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .9rem;
    padding: 1rem;
}

.event-datetime-field:first-child {
    grid-column: 1 / -1;
}

.event-datetime-field label {
    display: block;
    margin-bottom: .35rem;
    color: #334155;
    font-size: .86rem;
    font-weight: 900;
}

.event-datetime-field input {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: .78rem .85rem;
    color: #0f172a;
    font: inherit;
}

.event-datetime-close {
    width: 36px;
    height: 36px;
    border: 0;
    border-radius: 999px;
    background: #f1f5f9;
    color: #334155;
    font-weight: 900;
    cursor: pointer;
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
        $eventoFotoUrl = trim((string)($eventoSelecionado['foto_evento_url'] ?? ''));
        $clienteCadastroHref = $clienteCadastroId > 0
            ? 'comercial_cadastro_cliente.php?edit_id=' . $clienteCadastroId
            : 'index.php?page=comercial_clientes_cadastrados' . ($clienteNome !== '' ? '&search=' . urlencode($clienteNome) : '');

        $cards = [
            ['icon' => '☑️', 'title' => 'Checklist', 'pill' => '10% / 90%'],
            ['icon' => '🧮', 'title' => 'Financeiro do Evento', 'pill' => 'Pagamentos em Dia', 'href' => 'index.php?page=eventos_financeiro&evento_id=' . (int)$eventoSelecionado['id']],
            ['icon' => '🖥️', 'title' => 'Organização do evento', 'pill' => 'Portal do cliente', 'href' => $organizacaoHref],
            ['icon' => '🏷️', 'title' => 'Fornecedores', 'pill' => '0'],
            ['icon' => '📄', 'title' => 'Contratos e Documentos', 'pill' => '', 'href' => 'index.php?page=eventos_documentos&evento_id=' . (int)$eventoSelecionado['id']],
            ['icon' => '❕', 'title' => 'Histórico', 'pill' => '', 'href' => $historicoHref],
        ];
        if ((string)($eventoSelecionado['tipo_evento_real'] ?? '') === 'formatura') {
            array_splice($cards, 3, 0, [[
                'icon' => '🎓',
                'title' => 'Formatura',
                'pill' => 'Formandos',
                'href' => 'index.php?page=eventos_formatura&evento_id=' . (int)$eventoSelecionado['id'],
            ]]);
        }
        ?>
        <div class="agenda-detail-layout">
            <aside class="event-side-panel">
                <h2 class="event-side-title"><?= h((string)($eventoSelecionado['nome_evento'] ?? 'Evento')) ?></h2>
                <button type="button" class="event-avatar<?= $eventoFotoUrl !== '' ? ' has-photo' : '' ?>" id="eventPhotoOpen" aria-label="Adicionar ou editar foto do evento" title="Adicionar ou editar foto do evento">
                    <?php if ($eventoFotoUrl !== ''): ?>
                        <img src="<?= h($eventoFotoUrl) ?>" alt="Foto do evento <?= h((string)($eventoSelecionado['nome_evento'] ?? '')) ?>">
                    <?php endif; ?>
                    <span class="event-avatar-overlay"><?= $eventoFotoUrl !== '' ? 'Editar foto' : 'Adicionar foto' ?></span>
                </button>

                <div class="event-side-summary">
                    <div>★ <?= h((string)($eventoSelecionado['space_visivel'] ?? 'Unidade não mapeada')) ?></div>
                    <div>● <?= (int)($eventoSelecionado['convidados'] ?? 0) ?> participantes</div>
                    <div class="event-type-dot" style="background: <?= h($eventColor) ?>">TI</div>
                </div>

                <?php if ($isSuperadmin): ?>
                    <button type="button" class="event-info-block event-info-block--button" id="eventDatetimeOpen" style="border-left-color: <?= h($eventColor) ?>" aria-label="Alterar data e horários do evento">
                        <span class="event-info-label">📅 <?= h(agenda_eventos_format_date((string)($eventoSelecionado['data_evento'] ?? ''))) ?></span>
                        <div><?= h(agenda_eventos_format_weekday((string)($eventoSelecionado['data_evento'] ?? ''))) ?></div>
                        <div><strong>Horário:</strong> <?= h(agenda_eventos_format_time($eventoSelecionado)) ?></div>
                        <div><strong>Local:</strong> <?= h((string)($eventoSelecionado['local_evento'] ?? 'Local não informado')) ?></div>
                        <div class="event-schedule-hint">Clique para alterar data e horários</div>
                    </button>
                <?php else: ?>
                    <div class="event-info-block" style="border-left-color: <?= h($eventColor) ?>">
                        <span class="event-info-label">📅 <?= h(agenda_eventos_format_date((string)($eventoSelecionado['data_evento'] ?? ''))) ?></span>
                        <div><?= h(agenda_eventos_format_weekday((string)($eventoSelecionado['data_evento'] ?? ''))) ?></div>
                        <div><strong>Horário:</strong> <?= h(agenda_eventos_format_time($eventoSelecionado)) ?></div>
                        <div><strong>Local:</strong> <?= h((string)($eventoSelecionado['local_evento'] ?? 'Local não informado')) ?></div>
                    </div>
                <?php endif; ?>

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
    <?php foreach ($messages as $message): ?>
        <div class="agenda-alert success"><?= h($message) ?></div>
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
                                    $eventChipClass = 'event-chip ' . $eventClass . (!$canViewEventDetails ? ' event-chip--locked' : '');
                                    ?>
                                    <?php if ($canViewEventDetails): ?>
                                        <a class="<?= h($eventChipClass) ?>" href="index.php?page=agenda_eventos&evento_id=<?= (int)($evento['id'] ?? 0) ?>&mes=<?= h($mesSelecionado->format('Y-m')) ?>">
                                            <div class="event-chip-time"><?= h($horario) ?></div>
                                            <div class="event-chip-name"><?= h((string)($evento['nome_evento'] ?? 'Evento')) ?></div>
                                            <div class="event-chip-meta"><?= h((string)($evento['local_evento'] ?? 'Local não informado')) ?></div>
                                            <div class="event-chip-guests">Convidados: <?= (int)($evento['convidados'] ?? 0) ?></div>
                                        </a>
                                    <?php else: ?>
                                        <div class="<?= h($eventChipClass) ?>">
                                            <div class="event-chip-time"><?= h($horario) ?></div>
                                            <div class="event-chip-name"><?= h((string)($evento['nome_evento'] ?? 'Evento')) ?></div>
                                            <div class="event-chip-meta"><?= h((string)($evento['local_evento'] ?? 'Local não informado')) ?></div>
                                            <div class="event-chip-guests">Convidados: <?= (int)($evento['convidados'] ?? 0) ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <?php if (count($dayEvents) > 3): ?>
                                    <details class="event-more">
                                        <summary>+<?= count($dayEvents) - 3 ?> evento(s) neste dia</summary>
                                        <div class="event-more-popover">
                                            <div class="event-more-popover-title">Eventos ocultos</div>
                                            <?php foreach (array_slice($dayEvents, 3) as $evento): ?>
                                                <?php
                                                $eventClass = agenda_eventos_color_class($evento);
                                                $horario = agenda_eventos_format_time($evento);
                                                $eventChipClass = 'event-chip ' . $eventClass . (!$canViewEventDetails ? ' event-chip--locked' : '');
                                                ?>
                                                <?php if ($canViewEventDetails): ?>
                                                    <a class="<?= h($eventChipClass) ?>" href="index.php?page=agenda_eventos&evento_id=<?= (int)($evento['id'] ?? 0) ?>&mes=<?= h($mesSelecionado->format('Y-m')) ?>">
                                                        <div class="event-chip-time"><?= h($horario) ?></div>
                                                        <div class="event-chip-name"><?= h((string)($evento['nome_evento'] ?? 'Evento')) ?></div>
                                                        <div class="event-chip-meta"><?= h((string)($evento['local_evento'] ?? 'Local não informado')) ?></div>
                                                        <div class="event-chip-guests">Convidados: <?= (int)($evento['convidados'] ?? 0) ?></div>
                                                    </a>
                                                <?php else: ?>
                                                    <div class="<?= h($eventChipClass) ?>">
                                                        <div class="event-chip-time"><?= h($horario) ?></div>
                                                        <div class="event-chip-name"><?= h((string)($evento['nome_evento'] ?? 'Evento')) ?></div>
                                                        <div class="event-chip-meta"><?= h((string)($evento['local_evento'] ?? 'Local não informado')) ?></div>
                                                        <div class="event-chip-guests">Convidados: <?= (int)($evento['convidados'] ?? 0) ?></div>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </details>
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

<?php if (empty($errors) && $isSuperadmin && $eventoSelecionadoId > 0 && is_array($eventoSelecionado)): ?>
    <div class="event-datetime-modal" id="eventDatetimeModal" role="dialog" aria-modal="true" aria-labelledby="eventDatetimeTitle">
        <form class="event-datetime-card" method="post">
            <input type="hidden" name="action" value="atualizar_data_horario_evento">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoSelecionadoId ?>">

            <div class="event-datetime-head">
                <h2 class="event-datetime-title" id="eventDatetimeTitle">Alterar data e horários</h2>
                <button type="button" class="event-datetime-close" data-event-datetime-close aria-label="Fechar">×</button>
            </div>

            <div class="event-datetime-body">
                <div class="event-datetime-field">
                    <label for="eventDatetimeDate">Data do evento</label>
                    <input type="date" id="eventDatetimeDate" name="data_evento" value="<?= h((string)($eventoSelecionado['data_evento'] ?? '')) ?>" required>
                </div>
                <div class="event-datetime-field">
                    <label for="eventDatetimeStart">Horário de início</label>
                    <input type="time" id="eventDatetimeStart" name="hora_inicio" value="<?= h(agenda_eventos_format_clock((string)($eventoSelecionado['hora_inicio'] ?? ''))) ?>" required>
                </div>
                <div class="event-datetime-field">
                    <label for="eventDatetimeEnd">Horário de término</label>
                    <input type="time" id="eventDatetimeEnd" name="hora_fim" value="<?= h(agenda_eventos_format_clock((string)($eventoSelecionado['hora_fim'] ?? ''))) ?>" required>
                </div>
            </div>

            <div class="event-datetime-actions">
                <button type="button" class="event-action-btn" data-event-datetime-close>Cancelar</button>
                <button type="submit" class="event-action-btn event-action-btn--green">Salvar alterações</button>
            </div>
        </form>
    </div>

    <script>
    (() => {
        const openButton = document.getElementById('eventDatetimeOpen');
        const modal = document.getElementById('eventDatetimeModal');
        const openModal = () => modal?.classList.add('open');
        const closeModal = () => modal?.classList.remove('open');

        openButton?.addEventListener('click', openModal);
        document.querySelectorAll('[data-event-datetime-close]').forEach((button) => {
            button.addEventListener('click', closeModal);
        });
        modal?.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal?.classList.contains('open')) {
                closeModal();
            }
        });
    })();
    </script>
<?php endif; ?>

<?php if (empty($errors) && $eventoSelecionadoId > 0 && is_array($eventoSelecionado)): ?>
    <div class="event-photo-modal" id="eventPhotoModal" role="dialog" aria-modal="true" aria-labelledby="eventPhotoTitle">
        <form class="event-photo-card" method="post" id="eventPhotoForm">
            <input type="hidden" name="action" value="salvar_foto_evento">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoSelecionadoId ?>">
            <input type="hidden" name="foto_evento_data" id="eventPhotoData">

            <div class="event-photo-head">
                <h2 class="event-photo-title" id="eventPhotoTitle">Foto do evento</h2>
                <button type="button" class="event-photo-close" data-event-photo-close aria-label="Fechar">×</button>
            </div>

            <div class="event-photo-body">
                <input class="event-photo-input" type="file" id="eventPhotoInput" accept="image/*">
                <div class="event-photo-stage">
                    <img id="eventPhotoEditor" alt="Pré-visualização da foto" style="display:none;">
                    <span class="event-photo-placeholder" id="eventPhotoPlaceholder">Selecione uma imagem para ajustar o corte.</span>
                </div>
            </div>

            <div class="event-photo-actions">
                <button type="button" class="event-action-btn" id="eventPhotoZoomOut">- Zoom</button>
                <button type="button" class="event-action-btn" id="eventPhotoZoomIn">+ Zoom</button>
                <button type="button" class="event-action-btn" id="eventPhotoRotate">↻ Girar</button>
                <button type="button" class="event-action-btn" data-event-photo-close>Cancelar</button>
                <button type="submit" class="event-action-btn event-action-btn--green">Salvar foto</button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
    <script>
    (() => {
        const openButton = document.getElementById('eventPhotoOpen');
        const modal = document.getElementById('eventPhotoModal');
        const input = document.getElementById('eventPhotoInput');
        const image = document.getElementById('eventPhotoEditor');
        const hidden = document.getElementById('eventPhotoData');
        const placeholder = document.getElementById('eventPhotoPlaceholder');
        const form = document.getElementById('eventPhotoForm');
        let cropper = null;

        const openModal = () => modal?.classList.add('open');
        const closeModal = () => modal?.classList.remove('open');
        const destroyCropper = () => {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
        };

        openButton?.addEventListener('click', openModal);
        document.querySelectorAll('[data-event-photo-close]').forEach((button) => {
            button.addEventListener('click', closeModal);
        });
        modal?.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal?.classList.contains('open')) {
                closeModal();
            }
        });

        input?.addEventListener('change', () => {
            const file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                return;
            }
            if (!file.type.startsWith('image/')) {
                alert('Selecione uma imagem válida.');
                input.value = '';
                return;
            }

            destroyCropper();
            const reader = new FileReader();
            reader.onload = () => {
                image.src = String(reader.result || '');
                image.style.display = 'block';
                if (placeholder) {
                    placeholder.style.display = 'none';
                }
                cropper = new Cropper(image, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 0.9,
                    background: false,
                    responsive: true
                });
            };
            reader.readAsDataURL(file);
        });

        document.getElementById('eventPhotoZoomOut')?.addEventListener('click', () => cropper?.zoom(-0.1));
        document.getElementById('eventPhotoZoomIn')?.addEventListener('click', () => cropper?.zoom(0.1));
        document.getElementById('eventPhotoRotate')?.addEventListener('click', () => cropper?.rotate(90));

        form?.addEventListener('submit', (event) => {
            if (!cropper) {
                event.preventDefault();
                alert('Selecione uma foto antes de salvar.');
                return;
            }
            const canvas = cropper.getCroppedCanvas({
                width: 900,
                height: 900,
                imageSmoothingQuality: 'high'
            });
            if (!canvas) {
                event.preventDefault();
                alert('Não foi possível recortar a foto.');
                return;
            }
            hidden.value = canvas.toDataURL('image/jpeg', 0.9);
        });
    })();
    </script>
<?php endif; ?>

<?php endSidebar(); ?>
