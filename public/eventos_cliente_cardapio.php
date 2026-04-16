<?php
/**
 * eventos_cliente_cardapio.php
 * Área pública de seleção do cardápio pelo cliente.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_cliente_portal_ui.php';
require_once __DIR__ . '/logistica_cardapio_helper.php';

logistica_cardapio_ensure_schema($pdo);

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$portal = null;
$reuniao = null;
$snapshot = [];
$contexto_cardapio = ['ok' => false];

if ($token === '') {
    $error = 'Link inválido.';
} else {
    $portal = eventos_cliente_portal_get_by_token($pdo, $token);
    if (!$portal || empty($portal['is_active'])) {
        $error = 'Portal não encontrado ou desativado.';
    } else {
        $reuniao = eventos_reuniao_get($pdo, (int)($portal['meeting_id'] ?? 0));
        if (!$reuniao) {
            $error = 'Reunião não encontrada.';
        } else {
            $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
            if (!is_array($snapshot)) {
                $snapshot = [];
            }

            $contexto_cardapio = logistica_cardapio_evento_contexto($pdo, (int)$reuniao['id']);
            if (empty($contexto_cardapio['ok'])) {
                $error = (string)($contexto_cardapio['error'] ?? 'Não foi possível carregar o cardápio.');
            }

            if ($error === '' && empty($portal['visivel_cardapio'])) {
                $error = 'O cardápio não está disponível para este portal.';
            }

            if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_cardapio') {
                if (empty($portal['editavel_cardapio'])) {
                    $error = 'O cardápio não está habilitado para edição neste portal.';
                } elseif (!empty($contexto_cardapio['locked'])) {
                    $error = 'O cardápio já foi enviado e está bloqueado para edição.';
                } else {
                    $result = logistica_cardapio_resposta_salvar_cliente(
                        $pdo,
                        (int)$reuniao['id'],
                        (int)($portal['id'] ?? 0),
                        $_POST['selecao'] ?? []
                    );
                    if (!empty($result['ok'])) {
                        $success = 'Escolhas salvas com sucesso. O cardápio foi bloqueado para nova edição.';
                        $contexto_cardapio = logistica_cardapio_evento_contexto($pdo, (int)$reuniao['id']);
                    } else {
                        $error = (string)($result['error'] ?? 'Não foi possível salvar as escolhas do cardápio.');
                    }
                }
            }
        }
    }
}

function eventos_cliente_cardapio_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$horario_evento = eventos_cliente_ui_horario_evento($snapshot, '-');
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$pacote_nome = trim((string)($contexto_cardapio['pacote']['nome'] ?? ''));
$submitted_at = trim((string)($contexto_cardapio['summary']['submitted_at'] ?? ''));
$submitted_fmt = $submitted_at !== '' ? date('d/m/Y H:i', strtotime($submitted_at)) : '';
$pode_editar = $error === ''
    && !empty($portal['editavel_cardapio'])
    && empty($contexto_cardapio['locked']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cardápio do Evento</title>
    <link rel="stylesheet" href="assets/css/custom_modals.css">
    <script src="assets/js/custom_modals.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.18), transparent 32%),
                linear-gradient(180deg, #f8fafc 0%, #eff6ff 100%);
            color: #1e293b;
            min-height: 100vh;
        }

        .hero {
            background: linear-gradient(135deg, #123c9c 0%, #2563eb 60%, #3b82f6 100%);
            color: #fff;
            padding: 2.25rem 1rem 2rem;
            text-align: center;
        }

        .hero h1 {
            font-size: 1.9rem;
            margin-bottom: 0.4rem;
        }

        .hero p {
            opacity: 0.92;
            max-width: 700px;
            margin: 0 auto;
            line-height: 1.55;
        }

        .container {
            max-width: 1180px;
            margin: 0 auto;
            padding: 1.4rem;
        }

        .event-box,
        .section-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 20px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        .event-box {
            padding: 1.2rem;
            margin-bottom: 1.1rem;
        }

        .event-meta {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        }

        .event-pill {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 0.85rem 0.95rem;
        }

        .event-pill .label {
            display: block;
            color: #64748b;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.3rem;
        }

        .event-pill .value {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.98rem;
        }

        .alert {
            border-radius: 14px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
        }

        .alert-error {
            background: #fee2e2;
            border-color: #fecaca;
            color: #991b1b;
        }

        .alert-success {
            background: #dcfce7;
            border-color: #bbf7d0;
            color: #166534;
        }

        .alert-info {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }

        .status-row {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.8rem;
            border-radius: 999px;
            font-size: 0.8rem;
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
        }

        .section-card {
            padding: 1.1rem;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            margin-bottom: 0.9rem;
        }

        .section-title {
            margin: 0;
            color: #0f172a;
            font-size: 1.18rem;
        }

        .section-desc {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .section-limit {
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 999px;
            padding: 0.34rem 0.78rem;
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .section-counter {
            margin-bottom: 0.8rem;
            color: #475569;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .item-list {
            display: grid;
            gap: 0.65rem;
        }

        .item-option {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1rem;
            border-radius: 16px;
            border: 1px solid #dbe3f1;
            background: #f8fafc;
        }

        .item-option.selected {
            border-color: #86efac;
            background: #f0fdf4;
        }

        .item-option input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #2563eb;
            flex: 0 0 auto;
        }

        .item-name {
            flex: 1;
            font-weight: 700;
            color: #0f172a;
        }

        .item-type {
            color: #64748b;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .empty-box {
            border-radius: 14px;
            border: 1px dashed #cbd5e1;
            background: #f8fafc;
            color: #64748b;
            padding: 1rem;
            font-size: 0.92rem;
        }

        .footer-bar {
            position: sticky;
            bottom: 0;
            z-index: 20;
            margin-top: 1rem;
            padding: 1rem 0 0;
        }

        .footer-inner {
            background: linear-gradient(135deg, rgba(18, 60, 156, 0.96) 0%, rgba(37, 99, 235, 0.96) 100%);
            color: #fff;
            border-radius: 20px;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.22);
        }

        .footer-copy {
            font-size: 0.92rem;
            line-height: 1.5;
        }

        .btn-submit,
        .btn-back {
            border: none;
            border-radius: 12px;
            padding: 0.82rem 1.15rem;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-submit {
            background: #ffffff;
            color: #1e3a8a;
        }

        .btn-submit:disabled {
            background: #64748b;
            color: #e2e8f0;
            cursor: not-allowed;
        }

        .btn-back {
            background: #e2e8f0;
            color: #0f172a;
        }

        @media (max-width: 640px) {
            .hero h1 {
                font-size: 1.55rem;
            }

            .container {
                padding: 1rem;
            }

            .section-head {
                flex-direction: column;
            }

            .section-limit {
                white-space: normal;
            }
        }
    </style>
</head>
<body>
    <header class="hero">
        <h1>Cardápio do Evento</h1>
        <p>Escolha os itens do seu cardápio conforme o pacote do evento. Depois do envio, a seleção fica bloqueada.</p>
    </header>

    <div class="container">
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= eventos_cliente_cardapio_e($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?= eventos_cliente_cardapio_e($success) ?></div>
        <?php endif; ?>

        <?php if ($error === ''): ?>
            <div class="event-box">
                <div class="event-meta">
                    <div class="event-pill">
                        <span class="label">Evento</span>
                        <div class="value"><?= eventos_cliente_cardapio_e($evento_nome) ?></div>
                    </div>
                    <div class="event-pill">
                        <span class="label">Cliente</span>
                        <div class="value"><?= eventos_cliente_cardapio_e($cliente_nome) ?></div>
                    </div>
                    <div class="event-pill">
                        <span class="label">Data</span>
                        <div class="value"><?= eventos_cliente_cardapio_e($data_evento_fmt) ?> • <?= eventos_cliente_cardapio_e($horario_evento) ?></div>
                    </div>
                    <div class="event-pill">
                        <span class="label">Local</span>
                        <div class="value"><?= eventos_cliente_cardapio_e($local_evento) ?></div>
                    </div>
                    <div class="event-pill">
                        <span class="label">Pacote</span>
                        <div class="value"><?= $pacote_nome !== '' ? eventos_cliente_cardapio_e($pacote_nome) : 'Não configurado' ?></div>
                    </div>
                    <div class="event-pill">
                        <span class="label">Status</span>
                        <div class="value"><?= !empty($contexto_cardapio['locked']) ? 'Concluído' : 'Aguardando envio' ?></div>
                    </div>
                </div>
            </div>

            <div class="status-row">
                <span class="badge <?= !empty($portal['visivel_cardapio']) ? 'badge-blue' : 'badge-slate' ?>">
                    <?= !empty($portal['visivel_cardapio']) ? 'Cardápio disponível' : 'Cardápio indisponível' ?>
                </span>
                <span class="badge <?= $pode_editar ? 'badge-blue' : 'badge-slate' ?>">
                    <?= $pode_editar ? 'Edição liberada' : 'Somente visualização' ?>
                </span>
                <span class="badge <?= !empty($contexto_cardapio['locked']) ? 'badge-green' : 'badge-slate' ?>">
                    <?= !empty($contexto_cardapio['locked']) ? 'Bloqueado após envio' : 'Ainda não enviado' ?>
                </span>
                <?php if ($submitted_fmt !== ''): ?>
                    <span class="badge badge-green">Enviado em <?= eventos_cliente_cardapio_e($submitted_fmt) ?></span>
                <?php endif; ?>
            </div>

            <?php if (empty($contexto_cardapio['pacote'])): ?>
                <div class="alert alert-info">O pacote deste evento ainda não foi configurado. Entre em contato com a equipe.</div>
            <?php elseif (empty($contexto_cardapio['secoes'])): ?>
                <div class="alert alert-info">O cardápio deste evento ainda não está pronto para seleção. Entre em contato com a equipe.</div>
            <?php else: ?>
                <form method="POST" id="cardapioForm">
                    <input type="hidden" name="action" value="save_cardapio">
                    <input type="hidden" name="token" value="<?= eventos_cliente_cardapio_e($token) ?>">

                    <div class="sections-grid">
                        <?php foreach (($contexto_cardapio['secoes'] ?? []) as $secao): ?>
                            <?php
                            $secao_id = (int)$secao['id'];
                            $max_escolhas = (int)$secao['quantidade_maxima'];
                            $selected_count = (int)($secao['selected_count'] ?? 0);
                            ?>
                            <section class="section-card" data-section-id="<?= $secao_id ?>" data-max="<?= $max_escolhas ?>">
                                <div class="section-head">
                                    <div>
                                        <h2 class="section-title"><?= eventos_cliente_cardapio_e((string)$secao['nome']) ?></h2>
                                        <?php if (!empty($secao['descricao'])): ?>
                                            <p class="section-desc"><?= eventos_cliente_cardapio_e((string)$secao['descricao']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="section-limit">Escolha <?= $max_escolhas ?></div>
                                </div>

                                <?php if (!empty($secao['itens']) && count($secao['itens']) < $max_escolhas): ?>
                                    <div class="alert alert-error" style="margin-bottom:0.8rem;">
                                        Esta seção ainda não possui itens suficientes para completar a seleção.
                                    </div>
                                <?php endif; ?>

                                <div class="section-counter">
                                    Selecionados: <span class="counter-current"><?= $selected_count ?></span> de <span class="counter-max"><?= $max_escolhas ?></span>
                                </div>

                                <?php if (empty($secao['itens'])): ?>
                                    <div class="empty-box">Nenhuma opção disponível nesta seção.</div>
                                <?php else: ?>
                                    <div class="item-list">
                                        <?php foreach ($secao['itens'] as $item): ?>
                                            <label class="item-option <?= !empty($item['checked']) ? 'selected' : '' ?>">
                                                <input
                                                    type="checkbox"
                                                    name="selecao[<?= $secao_id ?>][]"
                                                    value="<?= eventos_cliente_cardapio_e((string)$item['key']) ?>"
                                                    <?= !empty($item['checked']) ? 'checked' : '' ?>
                                                    <?= !$pode_editar ? 'disabled' : '' ?>
                                                >
                                                <div class="item-name"><?= eventos_cliente_cardapio_e((string)$item['nome']) ?></div>
                                                <div class="item-type"><?= eventos_cliente_cardapio_e((string)$item['item_tipo']) ?></div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>

                    <div class="footer-bar">
                        <div class="footer-inner">
                            <div class="footer-copy">
                                <?php if ($pode_editar): ?>
                                    Revise as escolhas por seção antes de enviar. Depois do envio, o cardápio fica bloqueado.
                                <?php else: ?>
                                    Este cardápio está disponível apenas para consulta neste momento.
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
                                <a class="btn-back" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">Voltar ao portal</a>
                                <?php if ($pode_editar): ?>
                                    <button class="btn-submit" type="submit">Salvar e concluir cardápio</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <a class="btn-back" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">Voltar ao portal</a>
        <?php endif; ?>
    </div>

    <script>
        function updateSectionCounter(section) {
            const current = section.querySelectorAll('input[type="checkbox"]:checked').length;
            const currentEl = section.querySelector('.counter-current');
            if (currentEl) {
                currentEl.textContent = String(current);
            }
        }

        document.querySelectorAll('.section-card[data-max]').forEach((section) => {
            const max = parseInt(section.getAttribute('data-max') || '0', 10);
            const checkboxes = Array.from(section.querySelectorAll('input[type="checkbox"]'));

            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked) {
                        const checked = checkboxes.filter((item) => item.checked);
                        if (max > 0 && checked.length > max) {
                            checkbox.checked = false;
                            window.alert('Você pode escolher apenas ' + max + ' opção(ões) nesta seção.');
                            return;
                        }
                    }

                    checkboxes.forEach((item) => {
                        const row = item.closest('.item-option');
                        if (!row) return;
                        row.classList.toggle('selected', item.checked);
                    });
                    updateSectionCounter(section);
                });
            });

            updateSectionCounter(section);
        });

        document.getElementById('cardapioForm')?.addEventListener('submit', (event) => {
            const sections = Array.from(document.querySelectorAll('.section-card[data-max]'));
            for (const section of sections) {
                const max = parseInt(section.getAttribute('data-max') || '0', 10);
                const checked = section.querySelectorAll('input[type="checkbox"]:checked').length;
                if (checked !== max) {
                    const title = section.querySelector('.section-title')?.textContent || 'esta seção';
                    event.preventDefault();
                    window.alert('Selecione ' + max + ' opção(ões) em ' + title + ' antes de enviar.');
                    return;
                }
            }
        });
    </script>
</body>
</html>
