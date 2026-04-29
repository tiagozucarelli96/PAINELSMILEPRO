<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

$can_realizar_evento = !empty($_SESSION['perm_eventos_realizar']);
$is_superadmin = !empty($_SESSION['perm_superadmin']);
if (!$can_realizar_evento && !$is_superadmin) {
    header('Location: index.php?page=dashboard');
    exit;
}

function eventos_realizar_resumo_checklist(PDO $pdo, int $meeting_id): array
{
    if ($meeting_id <= 0) {
        return ['total' => 0, 'concluidos' => 0];
    }

    $secao = eventos_reuniao_get_secao($pdo, $meeting_id, 'checklist_evento');
    if (!$secao) {
        return ['total' => 0, 'concluidos' => 0];
    }

    $payload = json_decode((string)($secao['content_html'] ?? ''), true);
    if (!is_array($payload)) {
        return ['total' => 0, 'concluidos' => 0];
    }

    $items = $payload['items'] ?? [];
    if (!is_array($items)) {
        return ['total' => 0, 'concluidos' => 0];
    }

    $total = 0;
    $concluidos = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = trim((string)($item['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $total++;
        if (!empty($item['done'])) {
            $concluidos++;
        }
    }

    return ['total' => $total, 'concluidos' => $concluidos];
}

function eventos_realizar_formatar_hora_curta(?string $value): string
{
    $hora = trim((string)$value);
    if ($hora === '') {
        return '';
    }
    if (preg_match('/^\d{2}:\d{2}/', $hora, $matches)) {
        return $matches[0];
    }
    $timestamp = strtotime($hora);
    return $timestamp ? date('H:i', $timestamp) : $hora;
}

function eventos_realizar_buscar_resumo_evento(PDO $pdo, int $meeting_id): ?array
{
    if ($meeting_id <= 0) {
        return null;
    }

    $campo = eventos_arquivos_buscar_campo_por_chave($pdo, $meeting_id, 'resumo_evento', true);
    $campoId = (int)($campo['id'] ?? 0);
    if ($campoId <= 0) {
        return null;
    }

    $arquivos = eventos_arquivos_listar($pdo, $meeting_id, false, $campoId, true);
    return !empty($arquivos) && is_array($arquivos[0]) ? $arquivos[0] : null;
}

function eventos_realizar_buscar_evento_cache_lista(PDO $pdo, int $me_event_id): ?array
{
    if ($me_event_id <= 0) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT data
            FROM eventos_me_cache
            WHERE cache_key LIKE 'events_list_%'
            ORDER BY cached_at DESC
            LIMIT 10
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $events = json_decode((string)($row['data'] ?? ''), true);
            if (!is_array($events)) {
                continue;
            }

            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }
                if ((int)($event['id'] ?? 0) === $me_event_id) {
                    return $event;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('eventos_realizar_buscar_evento_cache_lista: ' . $e->getMessage());
    }

    return null;
}

function eventos_realizar_atualizar_snapshot_me(PDO $pdo, array $reuniao): array
{
    $me_event_id = (int)($reuniao['me_event_id'] ?? 0);
    if ($me_event_id <= 0) {
        return $reuniao;
    }

    $snapshot_atual = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
    if (!is_array($snapshot_atual)) {
        $snapshot_atual = [];
    }

    try {
        $result = eventos_me_buscar_por_id($pdo, $me_event_id);
        $event = (!empty($result['ok']) && !empty($result['event']) && is_array($result['event']))
            ? $result['event']
            : eventos_realizar_buscar_evento_cache_lista($pdo, $me_event_id);

        if (!is_array($event)) {
            return $reuniao;
        }

        $snapshot_me = eventos_me_criar_snapshot($event);
        $snapshot_atualizado = eventos_me_merge_snapshot($snapshot_atual, $snapshot_me);
        $snapshot_atualizado['snapshot_at'] = date('Y-m-d H:i:s');

        $snapshot_json_atual = json_encode($snapshot_atual, JSON_UNESCAPED_UNICODE);
        $snapshot_json_novo = json_encode($snapshot_atualizado, JSON_UNESCAPED_UNICODE);
        if ($snapshot_json_novo !== false && $snapshot_json_novo !== $snapshot_json_atual) {
            $stmt = $pdo->prepare("
                UPDATE eventos_reunioes
                SET me_event_snapshot = :snapshot, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':snapshot' => $snapshot_json_novo,
                ':id' => (int)$reuniao['id'],
            ]);

            $reuniao['me_event_snapshot'] = $snapshot_json_novo;
            $reuniao['updated_at'] = date('Y-m-d H:i:s');
        }
    } catch (Throwable $e) {
        error_log('eventos_realizar_atualizar_snapshot_me: ' . $e->getMessage());
    }

    return $reuniao;
}

function eventos_realizar_linha_from_reuniao(array $reuniao): array
{
    $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
    if (!is_array($snapshot)) {
        $snapshot = [];
    }

    $data_evento = trim((string)($snapshot['data'] ?? ''));
    $hora_inicio = eventos_realizar_formatar_hora_curta((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
    $hora_fim = eventos_realizar_formatar_hora_curta((string)($snapshot['hora_fim'] ?? ''));
    $data_hora_evento = null;
    if ($data_evento !== '') {
        $data_hora_evento = trim($data_evento . ' ' . ($hora_inicio !== '' ? $hora_inicio : '00:00'));
    }

    return [
        'id' => (int)($reuniao['id'] ?? 0),
        'me_event_id' => (int)($reuniao['me_event_id'] ?? 0),
        'status' => (string)($reuniao['status'] ?? 'rascunho'),
        'updated_at' => (string)($reuniao['updated_at'] ?? ''),
        'data_evento' => $data_evento,
        'hora_inicio' => $hora_inicio,
        'hora_fim' => $hora_fim,
        'data_hora_evento' => $data_hora_evento,
        'nome_evento' => eventos_me_pick_text($snapshot, ['nome'], 'Evento sem nome'),
        'local_evento' => eventos_me_snapshot_local($snapshot, 'Local não informado'),
        'cliente_nome' => eventos_me_snapshot_cliente_nome($snapshot, 'Cliente não informado'),
    ];
}

$meeting_id = (int)($_GET['id'] ?? 0);
$busca = trim((string)($_GET['busca'] ?? ''));
$reuniao = null;
$snapshot = [];
$eventos_disponiveis = [];
$erro_reuniao = '';

if ($meeting_id > 0) {
    $reuniao = eventos_reuniao_get($pdo, $meeting_id);
    if (!$reuniao) {
        $meeting_id = 0;
        $erro_reuniao = 'Evento não encontrado para realização.';
    } else {
        $reuniao = eventos_realizar_atualizar_snapshot_me($pdo, $reuniao);
        $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
    }
}

if (!$reuniao) {
    $evento_data_sql = "NULLIF(TRIM(r.me_event_snapshot->>'data'), '')";
    $evento_hora_sql = "COALESCE(NULLIF(TRIM(r.me_event_snapshot->>'hora_inicio'), ''), NULLIF(TRIM(r.me_event_snapshot->>'hora'), ''), '00:00')";
    $evento_data_hora_sql = "(($evento_data_sql)::date + ($evento_hora_sql)::time)";
    $evento_local_sql = "COALESCE(
        NULLIF(TRIM(r.me_event_snapshot->>'local'), ''),
        NULLIF(TRIM(r.me_event_snapshot->>'nomelocal'), ''),
        NULLIF(TRIM(r.me_event_snapshot->>'localevento'), ''),
        NULLIF(TRIM(r.me_event_snapshot->>'localEvento'), ''),
        NULLIF(TRIM(r.me_event_snapshot->>'endereco'), '')
    )";
    $evento_cliente_sql = "COALESCE(
        NULLIF(TRIM(r.me_event_snapshot->'cliente'->>'nome'), ''),
        NULLIF(TRIM(r.me_event_snapshot->>'nomecliente'), ''),
        NULLIF(TRIM(r.me_event_snapshot->>'nomeCliente'), ''),
        NULLIF(TRIM(r.me_event_snapshot->>'client_name'), '')
    )";
    $janela_pos_meia_noite_horas = 6;

    $where = [
        'r.me_event_id IS NOT NULL',
        'r.me_event_id > 0',
        "{$evento_data_sql} IS NOT NULL",
        "(
            ({$evento_data_sql})::date >= CURRENT_DATE
            OR (
                ({$evento_data_sql})::date = (CURRENT_DATE - INTERVAL '1 day')::date
                AND NOW() < date_trunc('day', NOW()) + INTERVAL '{$janela_pos_meia_noite_horas} hours'
            )
        )",
    ];
    $params = [];
    if ($busca !== '') {
        $where[] = "(
            r.me_event_snapshot->>'nome' ILIKE :busca
            OR {$evento_cliente_sql} ILIKE :busca
            OR {$evento_local_sql} ILIKE :busca
            OR CAST(r.me_event_id AS TEXT) ILIKE :busca
        )";
        $params[':busca'] = '%' . $busca . '%';
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.me_event_id,
            r.me_event_snapshot,
            r.status,
            r.updated_at,
            (r.me_event_snapshot->>'data') AS data_evento,
            COALESCE(NULLIF(TRIM(r.me_event_snapshot->>'hora_inicio'), ''), NULLIF(TRIM(r.me_event_snapshot->>'hora'), '')) AS hora_inicio,
            (r.me_event_snapshot->>'hora_fim') AS hora_fim,
            {$evento_data_hora_sql} AS data_hora_evento,
            COALESCE(NULLIF(TRIM(r.me_event_snapshot->>'nome'), ''), 'Evento sem nome') AS nome_evento,
            COALESCE({$evento_local_sql}, 'Local não informado') AS local_evento,
            COALESCE({$evento_cliente_sql}, 'Cliente não informado') AS cliente_nome
        FROM eventos_reunioes r
        {$where_sql}
        ORDER BY
            data_hora_evento ASC NULLS LAST,
            r.updated_at DESC NULLS LAST,
            r.id DESC
        LIMIT 7
    ");
    $stmt->execute($params);
    $eventos_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($eventos_disponiveis as $idx => $evento_item) {
        $reuniao_item = [
            'id' => (int)($evento_item['id'] ?? 0),
            'me_event_id' => (int)($evento_item['me_event_id'] ?? 0),
            'status' => (string)($evento_item['status'] ?? 'rascunho'),
            'updated_at' => (string)($evento_item['updated_at'] ?? ''),
            'me_event_snapshot' => (string)($evento_item['me_event_snapshot'] ?? '{}'),
        ];
        $reuniao_item = eventos_realizar_atualizar_snapshot_me($pdo, $reuniao_item);
        $eventos_disponiveis[$idx] = eventos_realizar_linha_from_reuniao($reuniao_item);
    }
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_inicio = eventos_realizar_formatar_hora_curta((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = eventos_realizar_formatar_hora_curta((string)($snapshot['hora_fim'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = eventos_me_snapshot_local($snapshot, 'Local não informado');
$cliente_nome = eventos_me_snapshot_cliente_nome($snapshot, 'Cliente não informado');

$checklist_resumo = $reuniao ? eventos_realizar_resumo_checklist($pdo, (int)$reuniao['id']) : ['total' => 0, 'concluidos' => 0];
$checklist_total = (int)($checklist_resumo['total'] ?? 0);
$checklist_concluidos = (int)($checklist_resumo['concluidos'] ?? 0);
$checklist_percentual = $checklist_total > 0 ? (int)round(($checklist_concluidos / $checklist_total) * 100) : 0;

$convidados_resumo = $reuniao ? eventos_convidados_resumo($pdo, (int)$reuniao['id']) : ['total' => 0, 'checkin' => 0, 'pendentes' => 0];
$arquivos_resumo = $reuniao ? eventos_arquivos_resumo($pdo, (int)$reuniao['id']) : [
    'campos_total' => 0,
    'campos_obrigatorios' => 0,
    'campos_pendentes' => 0,
    'arquivos_total' => 0,
];
$resumo_evento_arquivo = $reuniao ? eventos_realizar_buscar_resumo_evento($pdo, (int)$reuniao['id']) : null;

includeSidebar('Realizar evento');
?>

<style>
    .realizar-container {
        padding: 2rem;
        max-width: 1320px;
        margin: 0 auto;
        background: #f8fafc;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.9rem;
        flex-wrap: wrap;
        margin-bottom: 1.2rem;
    }

    .page-title {
        margin: 0;
        color: #1e3a8a;
        font-size: 1.65rem;
    }

    .page-subtitle {
        margin-top: 0.4rem;
        color: #64748b;
        font-size: 0.9rem;
    }

    .btn {
        padding: 0.62rem 1rem;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-size: 0.86rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .btn-primary {
        background: #1e3a8a;
        color: #fff;
    }

    .btn-primary:hover {
        background: #254ac9;
    }

    .btn[disabled] {
        opacity: 0.55;
        cursor: not-allowed;
    }

    .btn-secondary {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #dbe3ef;
    }

    .btn-secondary:hover {
        background: #e2e8f0;
    }

    .alert {
        border-radius: 10px;
        border: 1px solid #fecaca;
        background: #fee2e2;
        color: #991b1b;
        font-size: 0.88rem;
        padding: 0.72rem 0.9rem;
        margin-bottom: 0.9rem;
    }

    .selector-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
    }

    .selector-form {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        margin-bottom: 0.9rem;
    }

    .selector-input {
        flex: 1;
        min-width: 260px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.62rem 0.75rem;
        font-size: 0.9rem;
    }

    .selector-input:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.12);
    }

    .event-list {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }

    .event-item {
        display: flex;
        justify-content: space-between;
        gap: 0.8rem;
        align-items: center;
        padding: 0.85rem 0.9rem;
        border-bottom: 1px solid #eef2f7;
    }

    .event-item:last-child {
        border-bottom: none;
    }

    .event-item-title {
        margin: 0;
        font-size: 0.98rem;
        color: #1f2937;
    }

    .event-item-meta {
        margin-top: 0.3rem;
        font-size: 0.82rem;
        color: #64748b;
        display: flex;
        gap: 0.55rem 0.9rem;
        flex-wrap: wrap;
    }

    .empty-state {
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 1rem;
        color: #64748b;
        background: #fff;
        text-align: center;
        font-style: italic;
    }

    .event-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: #fff;
        border-radius: 14px;
        padding: 1.2rem 1.3rem;
        margin-bottom: 1rem;
    }

    .event-header h2 {
        margin: 0;
        font-size: 1.4rem;
    }

    .event-meta {
        margin-top: 0.7rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem 1.2rem;
        font-size: 0.9rem;
    }

    .cards-grid {
        display: grid;
        gap: 0.95rem;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    }

    .module-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
    }

    .module-card h3 {
        margin: 0;
        color: #1f2937;
        font-size: 1.05rem;
    }

    .module-card p {
        margin: 0.48rem 0 0 0;
        color: #64748b;
        font-size: 0.86rem;
        line-height: 1.45;
    }

    .card-actions {
        margin-top: 0.88rem;
    }

    .helper-note {
        margin-top: 0.58rem;
        color: #475569;
        font-size: 0.82rem;
    }

    .helper-note-stack {
        display: grid;
        gap: 0.2rem;
    }

    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.68);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        z-index: 1000;
    }

    .modal-overlay.show {
        display: flex;
    }

    .modal-card {
        width: min(960px, 100%);
        max-height: 92vh;
        background: #fff;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
    }

    .modal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.95rem 1rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .modal-head h3 {
        margin: 0;
        color: #0f172a;
        font-size: 1rem;
    }

    .modal-close {
        border: none;
        background: transparent;
        font-size: 1.6rem;
        line-height: 1;
        cursor: pointer;
        color: #475569;
    }

    .modal-body {
        padding: 0;
        height: min(78vh, 900px);
    }

    .modal-body iframe {
        width: 100%;
        height: 100%;
        border: 0;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.22rem 0.58rem;
        font-size: 0.75rem;
        font-weight: 700;
        margin-left: 0.55rem;
    }

    .status-rascunho {
        background: #fef3c7;
        color: #92400e;
    }

    .status-concluida {
        background: #d1fae5;
        color: #065f46;
    }

    @media (max-width: 768px) {
        .realizar-container {
            padding: 1rem;
        }

        .event-item {
            flex-direction: column;
            align-items: flex-start;
        }
    }
</style>

<div class="realizar-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">✅ Realizar evento</h1>
            <div class="page-subtitle">Selecione um evento organizado. A lista exibe os eventos de hoje e os próximos 7; na madrugada (até 06h), também mantém eventos de ontem para check-in tardio.</div>
        </div>
        <?php if ($reuniao): ?>
        <a href="index.php?page=eventos_realizar" class="btn btn-secondary">Trocar evento</a>
        <?php endif; ?>
    </div>

    <?php if ($erro_reuniao !== ''): ?>
    <div class="alert"><?= htmlspecialchars($erro_reuniao) ?></div>
    <?php endif; ?>

    <?php if (!$reuniao): ?>
    <section class="selector-card">
        <form method="GET" class="selector-form">
            <input type="hidden" name="page" value="eventos_realizar">
            <input type="text" name="busca" class="selector-input" value="<?= htmlspecialchars($busca) ?>" placeholder="Buscar por nome, cliente, local ou ID do evento">
            <button type="submit" class="btn btn-primary">Buscar</button>
            <?php if ($busca !== ''): ?>
            <a href="index.php?page=eventos_realizar" class="btn btn-secondary">Limpar</a>
            <?php endif; ?>
        </form>

        <?php if (empty($eventos_disponiveis)): ?>
        <div class="empty-state">Nenhum evento de hoje/próximo (ou de ontem na madrugada) foi encontrado para a busca atual.</div>
        <?php else: ?>
        <div class="event-list">
            <?php foreach ($eventos_disponiveis as $evento_item): ?>
            <?php
                $item_id = (int)($evento_item['id'] ?? 0);
                $item_nome = trim((string)($evento_item['nome_evento'] ?? 'Evento'));
                $item_cliente = trim((string)($evento_item['cliente_nome'] ?? 'Cliente não informado'));
                $item_local = trim((string)($evento_item['local_evento'] ?? 'Local não informado'));
                $item_data_raw = trim((string)($evento_item['data_evento'] ?? ''));
                $item_data_fmt = $item_data_raw !== '' ? date('d/m/Y', strtotime($item_data_raw)) : '-';
                $item_hora_inicio = trim((string)($evento_item['hora_inicio'] ?? ''));
                $item_hora_fim = trim((string)($evento_item['hora_fim'] ?? ''));
                $item_hora = $item_hora_inicio !== '' ? $item_hora_inicio : '-';
                if ($item_hora_inicio !== '' && $item_hora_fim !== '') {
                    $item_hora .= ' - ' . $item_hora_fim;
                }
                $item_status = trim((string)($evento_item['status'] ?? 'rascunho'));
                $item_status_label = $item_status === 'concluida' ? 'Concluída' : 'Rascunho';
            ?>
            <div class="event-item">
                <div>
                    <p class="event-item-title">
                        <?= htmlspecialchars($item_nome) ?>
                        <span class="status-badge <?= $item_status === 'concluida' ? 'status-concluida' : 'status-rascunho' ?>">
                            <?= htmlspecialchars($item_status_label) ?>
                        </span>
                    </p>
                    <div class="event-item-meta">
                        <span>👤 <?= htmlspecialchars($item_cliente) ?></span>
                        <span>📅 <?= htmlspecialchars($item_data_fmt) ?> • <?= htmlspecialchars($item_hora) ?></span>
                        <span>📍 <?= htmlspecialchars($item_local) ?></span>
                        <span>#<?= (int)($evento_item['me_event_id'] ?? 0) ?></span>
                    </div>
                </div>
                <a href="index.php?page=eventos_realizar&id=<?= $item_id ?>" class="btn btn-primary">Abrir cards</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php else: ?>
    <section class="event-header">
        <h2><?= htmlspecialchars($evento_nome) ?></h2>
        <div class="event-meta">
            <div>📅 <?= htmlspecialchars($data_evento_fmt) ?> • <?= htmlspecialchars($horario_evento) ?></div>
            <div>📍 <?= htmlspecialchars($local_evento) ?></div>
            <div>👤 <?= htmlspecialchars($cliente_nome) ?></div>
            <div>🆔 Reunião #<?= (int)$meeting_id ?></div>
        </div>
    </section>

    <section class="cards-grid">
        <article class="module-card">
            <h3>✅ Checklist de evento</h3>
            <p>Controle rápido dos pontos operacionais da execução do evento.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_checklist&id=<?= (int)$meeting_id ?>&origin=realizar&readonly=1" class="btn btn-primary">Abrir Checklist</a>
            </div>
            <div class="helper-note">
                <?= $checklist_total > 0 ? $checklist_concluidos . '/' . $checklist_total . ' itens concluídos (' . $checklist_percentual . '%).' : 'Checklist ainda sem itens.' ?>
            </div>
        </article>

        <article class="module-card">
            <h3>🍽️ Cardápio</h3>
            <p>Consulta rápida do cardápio definido para a execução do evento.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_arquivos&id=<?= (int)$meeting_id ?>&origin=realizar&readonly=1#cardapio" class="btn btn-primary">Abrir Cardápio</a>
            </div>
            <div class="helper-note">Mostra apenas o que já foi organizado para conferência operacional.</div>
        </article>

        <article class="module-card">
            <h3>📁 Arquivos</h3>
            <p>Central de envio e consulta dos arquivos operacionais do evento.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_arquivos&id=<?= (int)$meeting_id ?>&origin=realizar&readonly=1" class="btn btn-primary">Abrir Arquivos</a>
            </div>
            <div class="helper-note">
                <?= (int)($arquivos_resumo['arquivos_total'] ?? 0) ?> arquivos enviados • <?= (int)($arquivos_resumo['campos_total'] ?? 0) ?> campos.
            </div>
        </article>

        <article class="module-card">
            <h3>📄 Resumo do evento</h3>
            <p>Contrato/PDF do evento para consulta rápida durante a execução.</p>
            <div class="card-actions">
                <button type="button" class="btn btn-primary" onclick="abrirModalResumoEvento()" <?= !$resumo_evento_arquivo ? 'disabled' : '' ?>>Visualizar</button>
            </div>
            <div class="helper-note">
                <?= $resumo_evento_arquivo ? htmlspecialchars((string)($resumo_evento_arquivo['original_name'] ?? 'Resumo disponível')) : 'Nenhum resumo do evento anexado.' ?>
            </div>
        </article>

        <article class="module-card">
            <h3>📝 Reunião Final</h3>
            <p>Informações importantes para o evento, com visualização direta de decoração, observações, DJ / protocolos e formulários.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_reuniao_final&id=<?= (int)$meeting_id ?>&origin=realizar&readonly=1" class="btn btn-primary">Abrir Reunião Final</a>
            </div>
            <div class="helper-note">Somente leitura, sem controles de edição e sem informações que não ajudam na execução.</div>
        </article>

        <article class="module-card">
            <h3>📋 Lista de convidados</h3>
            <p>Modo recepção: focado em busca, filtro e check-in em tempo real.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_lista_convidados&id=<?= (int)$meeting_id ?>&mode=recepcao&origin=realizar" class="btn btn-primary">Abrir Check-in</a>
            </div>
            <div class="helper-note">
                <?= (int)($convidados_resumo['checkin'] ?? 0) ?> check-ins de <?= (int)($convidados_resumo['total'] ?? 0) ?> convidados.
            </div>
        </article>
    </section>
    <?php endif; ?>
</div>

<?php if ($reuniao && $resumo_evento_arquivo): ?>
<div class="modal-overlay" id="modalResumoEvento">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Resumo do evento</h3>
            <button type="button" class="modal-close" onclick="fecharModalResumoEvento()">&times;</button>
        </div>
        <div class="modal-body">
            <iframe src="<?= htmlspecialchars((string)($resumo_evento_arquivo['public_url'] ?? '')) ?>" title="Resumo do evento"></iframe>
        </div>
    </div>
</div>
<script>
function abrirModalResumoEvento() {
    const modal = document.getElementById('modalResumoEvento');
    if (!modal) return;
    modal.classList.add('show');
}

function fecharModalResumoEvento() {
    const modal = document.getElementById('modalResumoEvento');
    if (!modal) return;
    modal.classList.remove('show');
}

document.addEventListener('click', function(ev) {
    const modal = document.getElementById('modalResumoEvento');
    if (modal && ev.target === modal) {
        fecharModalResumoEvento();
    }
});

document.addEventListener('keydown', function(ev) {
    if (ev.key === 'Escape') {
        fecharModalResumoEvento();
    }
});
</script>
<?php endif; ?>

<?php endSidebar(); ?>
