<?php
/**
 * eventos_cardapio.php
 * Visão interna do cardápio configurado por pacote para um evento.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/logistica_cardapio_helper.php';

$can_eventos = !empty($_SESSION['perm_eventos']);
$can_realizar_evento = !empty($_SESSION['perm_eventos_realizar']);
$is_superadmin = !empty($_SESSION['perm_superadmin']);

if (!$can_eventos && !$can_realizar_evento && !$is_superadmin) {
    header('Location: index.php?page=dashboard');
    exit;
}

if (painel_runtime_schema_setup_enabled()) {
    logistica_cardapio_ensure_schema($pdo);
}

$meeting_id = (int)($_GET['id'] ?? 0);
$origin = strtolower(trim((string)($_GET['origin'] ?? 'organizacao')));
if ($origin !== 'realizar' && $origin !== 'organizacao') {
    $origin = 'organizacao';
}
$somente_realizar = (!$is_superadmin && !$can_eventos && $can_realizar_evento);
if ($somente_realizar) {
    $origin = 'realizar';
}
$back_href = $origin === 'realizar'
    ? 'index.php?page=eventos_realizar&id=' . $meeting_id
    : 'index.php?page=eventos_organizacao&id=' . $meeting_id;
$back_label = $origin === 'realizar' ? '← Voltar para Realizar Evento' : '← Voltar para Organização';
$error = '';
$reuniao = null;
$portal = null;
$snapshot = [];
$contexto_cardapio = ['ok' => false];

if ($meeting_id <= 0) {
    $error = 'Reunião inválida.';
} else {
    $reuniao = eventos_reuniao_get($pdo, $meeting_id);
    if (!$reuniao) {
        $error = 'Reunião não encontrada.';
    } else {
        $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        $portal_result = eventos_cliente_portal_get_or_create($pdo, $meeting_id, (int)($_SESSION['id'] ?? 0));
        if (!empty($portal_result['ok']) && !empty($portal_result['portal'])) {
            $portal = $portal_result['portal'];
        }
        $contexto_cardapio = logistica_cardapio_evento_contexto($pdo, $meeting_id);
        if (empty($contexto_cardapio['ok'])) {
            $error = (string)($contexto_cardapio['error'] ?? 'Não foi possível carregar o cardápio do evento.');
        }
    }
}

function cardapio_evento_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
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
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$pacote_nome = trim((string)($contexto_cardapio['pacote']['nome'] ?? ''));
$summary = $contexto_cardapio['summary'] ?? [];
$submitted_at = trim((string)($summary['submitted_at'] ?? ''));
$submitted_fmt = $submitted_at !== '' ? date('d/m/Y H:i', strtotime($submitted_at)) : '';
$portal_url = trim((string)($portal['url'] ?? ''));
$visivel_cardapio = !empty($portal['visivel_cardapio']);
$editavel_cardapio = !empty($portal['editavel_cardapio']);

includeSidebar('Cardápio do Evento');
?>

<style>
    .page-container {
        max-width: 1320px;
        margin: 0 auto;
        padding: 1.5rem;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .page-title {
        margin: 0;
        color: #0f172a;
        font-size: 1.8rem;
    }

    .page-subtitle {
        margin: 0.35rem 0 0;
        color: #64748b;
        font-size: 0.95rem;
    }

    .actions {
        display: flex;
        gap: 0.6rem;
        flex-wrap: wrap;
    }

    .btn-primary,
    .btn-secondary {
        border: none;
        border-radius: 10px;
        padding: 0.7rem 1rem;
        cursor: pointer;
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn-primary {
        background: #2563eb;
        color: #fff;
    }

    .btn-secondary {
        background: #e2e8f0;
        color: #1f2937;
    }

    .panel {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 1.2rem;
        margin-bottom: 1rem;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
    }

    .meta-grid {
        display: grid;
        gap: 0.8rem;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }

    .meta-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 0.9rem;
    }

    .meta-label {
        display: block;
        color: #64748b;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 0.28rem;
    }

    .meta-value {
        color: #0f172a;
        font-size: 1rem;
        font-weight: 700;
    }

    .alert {
        border-radius: 12px;
        padding: 0.95rem 1rem;
        margin-bottom: 1rem;
        border: 1px solid transparent;
    }

    .alert-error {
        background: #fee2e2;
        border-color: #fecaca;
        color: #991b1b;
    }

    .alert-info {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }

    .status-row {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-top: 0.85rem;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.28rem 0.7rem;
        font-size: 0.78rem;
        font-weight: 700;
    }

    .badge-blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .badge-green {
        background: #dcfce7;
        color: #166534;
    }

    .badge-slate {
        background: #e2e8f0;
        color: #334155;
    }

    .sections-grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    }

    .section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 1rem;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }

    .section-head {
        display: flex;
        justify-content: space-between;
        gap: 0.8rem;
        align-items: flex-start;
        margin-bottom: 0.9rem;
    }

    .section-title {
        margin: 0;
        font-size: 1.1rem;
        color: #0f172a;
    }

    .section-desc {
        margin: 0.35rem 0 0;
        color: #64748b;
        font-size: 0.88rem;
        line-height: 1.45;
    }

    .section-limit {
        background: #eff6ff;
        color: #1d4ed8;
        border-radius: 999px;
        padding: 0.28rem 0.68rem;
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .items-list {
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
    }

    .item-row {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        padding: 0.75rem 0.85rem;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
    }

    .item-row.selected {
        border-color: #86efac;
        background: #f0fdf4;
    }

    .item-check {
        width: 18px;
        height: 18px;
        accent-color: #2563eb;
    }

    .item-name {
        flex: 1;
        color: #0f172a;
        font-weight: 600;
    }

    .item-type {
        color: #64748b;
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .empty-box {
        padding: 1rem;
        border-radius: 12px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        color: #64748b;
        font-size: 0.9rem;
    }
</style>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">Cardápio do Evento</h1>
            <p class="page-subtitle">Acompanhe o que o pacote libera para o cliente e o status da seleção no portal.</p>
        </div>
        <div class="actions">
            <a href="<?= cardapio_evento_e($back_href) ?>" class="btn-secondary"><?= cardapio_evento_e($back_label) ?></a>
            <?php if ($portal_url !== ''): ?>
                <a href="<?= cardapio_evento_e($portal_url) ?>" class="btn-primary" target="_blank" rel="noopener noreferrer">Abrir Portal do Cliente</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= cardapio_evento_e($error) ?></div>
    <?php else: ?>
        <div class="panel">
            <div class="meta-grid">
                <div class="meta-box">
                    <span class="meta-label">Evento</span>
                    <div class="meta-value"><?= cardapio_evento_e($evento_nome) ?></div>
                </div>
                <div class="meta-box">
                    <span class="meta-label">Cliente</span>
                    <div class="meta-value"><?= cardapio_evento_e($cliente_nome) ?></div>
                </div>
                <div class="meta-box">
                    <span class="meta-label">Data</span>
                    <div class="meta-value"><?= cardapio_evento_e($data_evento_fmt) ?> • <?= cardapio_evento_e($horario_evento) ?></div>
                </div>
                <div class="meta-box">
                    <span class="meta-label">Local</span>
                    <div class="meta-value"><?= cardapio_evento_e($local_evento) ?></div>
                </div>
                <div class="meta-box">
                    <span class="meta-label">Pacote do Evento</span>
                    <div class="meta-value"><?= $pacote_nome !== '' ? cardapio_evento_e($pacote_nome) : 'Sem pacote selecionado' ?></div>
                </div>
                <div class="meta-box">
                    <span class="meta-label">Status da Escolha</span>
                    <div class="meta-value">
                        <?= !empty($contexto_cardapio['locked']) ? 'Concluída' : 'Aguardando cliente' ?>
                    </div>
                </div>
            </div>

            <div class="status-row">
                <span class="badge <?= $visivel_cardapio ? 'badge-blue' : 'badge-slate' ?>">
                    <?= $visivel_cardapio ? 'Visível no portal' : 'Oculto no portal' ?>
                </span>
                <span class="badge <?= $editavel_cardapio ? 'badge-blue' : 'badge-slate' ?>">
                    <?= $editavel_cardapio ? 'Editável no portal' : 'Somente leitura no portal' ?>
                </span>
                <span class="badge <?= !empty($contexto_cardapio['locked']) ? 'badge-green' : 'badge-slate' ?>">
                    <?= !empty($contexto_cardapio['locked']) ? 'Bloqueado após envio' : 'Ainda não enviado' ?>
                </span>
                <?php if ($submitted_fmt !== ''): ?>
                    <span class="badge badge-green">Enviado em <?= cardapio_evento_e($submitted_fmt) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($contexto_cardapio['pacote'])): ?>
            <div class="alert alert-info">Selecione um pacote na organização do evento para montar o cardápio do cliente.</div>
        <?php elseif (empty($contexto_cardapio['secoes'])): ?>
            <div class="alert alert-info">O pacote selecionado ainda não possui seções configuradas para o cardápio do cliente.</div>
        <?php else: ?>
            <div class="sections-grid">
                <?php foreach (($contexto_cardapio['secoes'] ?? []) as $secao): ?>
                    <section class="section-card">
                        <div class="section-head">
                            <div>
                                <h2 class="section-title"><?= cardapio_evento_e((string)$secao['nome']) ?></h2>
                                <?php if (!empty($secao['descricao'])): ?>
                                    <p class="section-desc"><?= cardapio_evento_e((string)$secao['descricao']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="section-limit">
                                <?= (int)$secao['quantidade_maxima'] ?> escolha(s)
                            </div>
                        </div>

                        <?php if (empty($secao['itens'])): ?>
                            <div class="empty-box">Nenhum item disponível nesta seção para o pacote selecionado.</div>
                        <?php else: ?>
                            <div class="items-list">
                                <?php foreach ($secao['itens'] as $item): ?>
                                    <div class="item-row <?= !empty($item['checked']) ? 'selected' : '' ?>">
                                        <input class="item-check" type="checkbox" disabled <?= !empty($item['checked']) ? 'checked' : '' ?>>
                                        <div class="item-name"><?= cardapio_evento_e((string)$item['nome']) ?></div>
                                        <div class="item-type"><?= cardapio_evento_e((string)$item['item_tipo']) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php endSidebar(); ?>
