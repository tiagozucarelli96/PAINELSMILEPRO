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
        $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
    }
}

if (!$reuniao) {
    $where = [
        'r.me_event_id IS NOT NULL',
        'r.me_event_id > 0',
    ];
    $params = [];
    if ($busca !== '') {
        $where[] = "(
            r.me_event_snapshot->>'nome' ILIKE :busca
            OR r.me_event_snapshot->'cliente'->>'nome' ILIKE :busca
            OR r.me_event_snapshot->>'local' ILIKE :busca
            OR CAST(r.me_event_id AS TEXT) ILIKE :busca
        )";
        $params[':busca'] = '%' . $busca . '%';
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.me_event_id,
            r.status,
            r.updated_at,
            (r.me_event_snapshot->>'data') AS data_evento,
            (r.me_event_snapshot->>'hora_inicio') AS hora_inicio,
            (r.me_event_snapshot->>'hora_fim') AS hora_fim,
            COALESCE(NULLIF(TRIM(r.me_event_snapshot->>'nome'), ''), 'Evento sem nome') AS nome_evento,
            COALESCE(NULLIF(TRIM(r.me_event_snapshot->>'local'), ''), 'Local não informado') AS local_evento,
            COALESCE(NULLIF(TRIM(r.me_event_snapshot->'cliente'->>'nome'), ''), 'Cliente não informado') AS cliente_nome
        FROM eventos_reunioes r
        {$where_sql}
        ORDER BY
            (r.me_event_snapshot->>'data')::date ASC NULLS LAST,
            r.updated_at DESC NULLS LAST,
            r.id DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    $eventos_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente não informado'));

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
            <div class="page-subtitle">Selecione um evento organizado. Este modo é somente leitura (visualização modal + download), com check-in de convidados para recepção.</div>
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
        <div class="empty-state">Nenhum evento organizado encontrado para a busca atual.</div>
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
            <p>Acesso ao card de cardápio dentro dos anexos do evento.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_arquivos&id=<?= (int)$meeting_id ?>&origin=realizar&readonly=1#cardapio" class="btn btn-primary">Abrir Cardápio</a>
            </div>
            <div class="helper-note">Usa a mesma estrutura já existente em Organização de eventos.</div>
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
            <p>Contrato/PDF do cliente com o histórico de versão do anexo interno.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_arquivos&id=<?= (int)$meeting_id ?>&origin=realizar&readonly=1#resumo-evento" class="btn btn-primary">Abrir Resumo</a>
            </div>
            <div class="helper-note">Acesso direto ao bloco interno de Resumo do evento.</div>
        </article>

        <article class="module-card">
            <h3>🎧 DJ / Protocolos</h3>
            <p>Mantém o fluxo atual de DJ/Protocolos com os mesmos quadros.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_reuniao_final&id=<?= (int)$meeting_id ?>&scope=dj&origin=realizar&readonly=1" class="btn btn-primary">Abrir DJ / Protocolos</a>
            </div>
            <div class="helper-note">Mesmo conteúdo e lógica da tela de Organização.</div>
        </article>

        <article class="module-card">
            <h3>📝 Reunião Final</h3>
            <p>Acesso às áreas de Decoração e Observações Gerais da reunião final.</p>
            <div class="card-actions">
                <a href="index.php?page=eventos_reuniao_final&id=<?= (int)$meeting_id ?>&scope=reuniao&origin=realizar&readonly=1" class="btn btn-primary">Abrir Reunião Final</a>
            </div>
            <div class="helper-note">Mesmo fluxo da Organização, sem mudanças de comportamento.</div>
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

<?php endSidebar(); ?>
