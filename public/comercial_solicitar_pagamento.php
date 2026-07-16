<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/comercial_pagamento_solicitacao_helper.php';

if (!lc_can_access_comercial()) {
    header('Location: index.php?page=dashboard&error=permission_denied');
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(503);
    exit('Banco de dados indisponível.');
}

comercial_pagamento_solicitacao_ensure_schema($pdo);

if (empty($_SESSION['comercial_pagamento_csrf'])) {
    $_SESSION['comercial_pagamento_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string)$_SESSION['comercial_pagamento_csrf'];
$errors = [];
$created = null;
$old = [
    'evento_id' => '',
    'evento_label' => '',
    'descricao' => 'Pagamento Smile Eventos',
    'valor' => '',
    'vencimento' => date('Y-m-d'),
    'pagador_nome' => '',
    'pagador_documento' => '',
];

function comercial_solicitar_pagamento_eventos(PDO $pdo): array
{
    try {
        $hasCliente = eventos_financeiro_table_exists($pdo, 'comercial_cadastro_clientes')
            && eventos_financeiro_column_exists($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id');
        $hasPreContrato = eventos_financeiro_table_exists($pdo, 'vendas_pre_contratos');
        $joins = [];
        $clienteNomeCandidates = [];
        $clienteDocumentoCandidates = [];

        if ($hasCliente) {
            $joins[] = "
            LEFT JOIN comercial_cadastro_clientes c ON c.id = e.cliente_cadastro_id
            LEFT JOIN LATERAL (
                SELECT nome_completo, documento_numero
                FROM comercial_cadastro_clientes
                WHERE ultimo_me_event_id = e.me_event_id
                ORDER BY updated_at DESC NULLS LAST, id DESC
                LIMIT 1
            ) c_me ON TRUE
            LEFT JOIN LATERAL (
                SELECT nome_completo, documento_numero
                FROM comercial_cadastro_clientes
                WHERE regexp_replace(COALESCE(telefone_whatsapp, ''), '\\D', '', 'g') <> ''
                  AND regexp_replace(COALESCE(telefone_whatsapp, ''), '\\D', '', 'g') = regexp_replace(COALESCE(e.telefone_cliente, e.whatsapp_cliente, ''), '\\D', '', 'g')
                ORDER BY updated_at DESC NULLS LAST, id DESC
                LIMIT 1
            ) c_tel ON TRUE
            ";
            $clienteNomeCandidates = [
                "NULLIF(TRIM(c.nome_completo), '')",
                "NULLIF(TRIM(c_me.nome_completo), '')",
                "NULLIF(TRIM(c_tel.nome_completo), '')",
            ];
            $clienteDocumentoCandidates = [
                "NULLIF(TRIM(c.documento_numero), '')",
                "NULLIF(TRIM(c_me.documento_numero), '')",
                "NULLIF(TRIM(c_tel.documento_numero), '')",
            ];
        }

        if ($hasPreContrato) {
            $joins[] = "
            LEFT JOIN LATERAL (
                SELECT nome_completo, cpf
                FROM vendas_pre_contratos
                WHERE (e.me_event_id IS NOT NULL AND me_event_id = e.me_event_id)
                   OR (
                        regexp_replace(COALESCE(telefone, ''), '\\D', '', 'g') <> ''
                        AND regexp_replace(COALESCE(telefone, ''), '\\D', '', 'g') = regexp_replace(COALESCE(e.telefone_cliente, e.whatsapp_cliente, ''), '\\D', '', 'g')
                   )
                ORDER BY
                    CASE WHEN e.me_event_id IS NOT NULL AND me_event_id = e.me_event_id THEN 0 ELSE 1 END,
                    atualizado_em DESC NULLS LAST,
                    id DESC
                LIMIT 1
            ) vp ON TRUE
            ";
            $clienteNomeCandidates[] = "NULLIF(TRIM(vp.nome_completo), '')";
            $clienteDocumentoCandidates[] = "NULLIF(TRIM(vp.cpf), '')";
        }

        $clienteNomeCandidates[] = "''";
        $clienteDocumentoCandidates[] = "''";
        $clienteSelect = sprintf(
            "COALESCE(%s) AS cliente_nome, COALESCE(%s) AS cliente_documento,",
            implode(', ', $clienteNomeCandidates),
            implode(', ', $clienteDocumentoCandidates)
        );
        $join = implode("\n", $joins);
        $stmt = $pdo->query("
            SELECT e.id,
                   COALESCE(e.nome_evento, 'Evento') AS nome_evento,
                   e.data_evento::text AS data_evento,
                   COALESCE(e.localevento, '') AS local_evento,
                   {$clienteSelect}
                   '' AS telefone_evento
            FROM logistica_eventos_espelho e
            {$join}
            ORDER BY COALESCE(e.data_evento, CURRENT_DATE) DESC, e.id DESC
            LIMIT 800
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Falha ao carregar eventos para solicitação de pagamento: ' . $e->getMessage());
        return [];
    }
}

$eventos = comercial_solicitar_pagamento_eventos($pdo);
$eventOptions = [];
foreach ($eventos as $evento) {
    $label = trim((string)$evento['nome_evento']);
    $labelBase = $label;
    if (!empty($evento['data_evento'])) {
        $labelBase .= ' - ' . brDateOnly((string)$evento['data_evento']);
        $label .= ' - ' . brDateOnly((string)$evento['data_evento']);
    }
    if (!empty($evento['cliente_nome'])) {
        $label .= ' - ' . trim((string)$evento['cliente_nome']);
    }
    $eventOptions[] = [
        'id' => (int)$evento['id'],
        'label' => $label,
        'label_base' => $labelBase,
        'search' => mb_strtolower($label . ' ' . ($evento['local_evento'] ?? ''), 'UTF-8'),
        'nome_evento' => (string)$evento['nome_evento'],
        'cliente_nome' => (string)$evento['cliente_nome'],
        'cliente_documento' => (string)$evento['cliente_documento'],
    ];
}
$eventOptionsJson = json_encode(
    $eventOptions,
    JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
);
if (!is_string($eventOptionsJson)) {
    $eventOptionsJson = '[]';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $old = array_merge($old, [
        'evento_id' => trim((string)($_POST['evento_id'] ?? '')),
        'evento_label' => trim((string)($_POST['evento_label'] ?? '')),
        'descricao' => trim((string)($_POST['descricao'] ?? '')),
        'valor' => trim((string)($_POST['valor'] ?? '')),
        'vencimento' => trim((string)($_POST['vencimento'] ?? '')),
        'pagador_nome' => trim((string)($_POST['pagador_nome'] ?? '')),
        'pagador_documento' => '',
    ]);

    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Sessão expirada. Atualize a página e tente novamente.';
    }

    $valor = comercial_pagamento_money($old['valor']);
    if ($valor < 10) {
        $errors[] = 'O valor mínimo para PixGo é R$ 10,00.';
    }

    $vencimento = DateTimeImmutable::createFromFormat('!Y-m-d', $old['vencimento']);
    if (!$vencimento) {
        $errors[] = 'Informe uma data de vencimento válida.';
    }

    $eventoId = (int)$old['evento_id'];
    $eventoNome = '';
    $eventoSelecionado = null;
    if ($eventoId <= 0 && $old['evento_label'] !== '') {
        foreach ($eventOptions as $option) {
            if ((string)$option['label'] === $old['evento_label']) {
                $eventoId = (int)$option['id'];
                $old['evento_id'] = (string)$eventoId;
                break;
            }
        }
    }
    if ($eventoId > 0) {
        foreach ($eventOptions as $option) {
            if ((int)$option['id'] === $eventoId) {
                $eventoNome = (string)$option['nome_evento'];
                $eventoSelecionado = $option;
                break;
            }
        }
    }
    if ($eventoSelecionado) {
        $clienteNomeEvento = trim((string)$eventoSelecionado['cliente_nome']);
        if ($old['pagador_nome'] === '' && $clienteNomeEvento !== '') {
            $old['pagador_nome'] = $clienteNomeEvento;
        }
    }
    $pagadorNome = trim($old['pagador_nome']) !== '' ? trim($old['pagador_nome']) : 'Cliente Smile Eventos';

    if (!$errors) {
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("
            INSERT INTO comercial_pagamento_solicitacoes
                (token, evento_id, evento_nome, descricao, valor_original, vencimento,
                 pagador_nome, pagador_documento, created_by)
            VALUES
                (:token, :evento_id, :evento_nome, :descricao, :valor_original, :vencimento,
                 :pagador_nome, :pagador_documento, :created_by)
            RETURNING *
        ");
        $stmt->execute([
            ':token' => $token,
            ':evento_id' => $eventoId > 0 ? $eventoId : null,
            ':evento_nome' => $eventoNome !== '' ? $eventoNome : null,
            ':descricao' => $old['descricao'] !== '' ? $old['descricao'] : 'Pagamento Smile Eventos',
            ':valor_original' => $valor,
            ':vencimento' => $old['vencimento'],
            ':pagador_nome' => $pagadorNome,
            ':pagador_documento' => '',
            ':created_by' => !empty($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null,
        ]);
        $created = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $old['valor'] = '';
        $old['descricao'] = 'Pagamento Smile Eventos';
    }
}

$recentes = [];
try {
    $recentes = $pdo->query("
        SELECT id, token, descricao, valor_original, vencimento::text AS vencimento, status, created_at::text AS created_at
        FROM comercial_pagamento_solicitacoes
        ORDER BY created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recentes = [];
}

includeSidebar('Comercial');
?>

<style>
.pay-page { max-width: 1180px; margin: 0 auto; padding: 2rem; }
.pay-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
.pay-header h1 { margin: 0 0 .4rem; color: #1e3a8a; font-size: 2rem; }
.pay-header p { margin: 0; color: #64748b; }
.pay-grid { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(320px, .75fr); gap: 1.5rem; align-items: start; }
.pay-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 2px 8px rgba(15, 23, 42, .08); padding: 1.5rem; }
.pay-card h2 { margin: 0 0 1rem; color: #0f172a; font-size: 1.2rem; }
.pay-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
.pay-field { display: flex; flex-direction: column; gap: .4rem; }
.pay-field.span-2 { grid-column: 1 / -1; }
.pay-field label { font-weight: 700; color: #334155; font-size: .9rem; }
.pay-field input, .pay-field textarea { border: 1px solid #cbd5e1; border-radius: 8px; padding: .78rem .85rem; font: inherit; }
.pay-field textarea { min-height: 82px; resize: vertical; }
.pay-help { color: #64748b; font-size: .82rem; line-height: 1.45; }
.pay-info { grid-column: 1 / -1; border: 1px solid #bfdbfe; border-radius: 10px; padding: .85rem 1rem; background: #eff6ff; color: #1e3a8a; font-size: .9rem; line-height: 1.45; }
.pay-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; }
.pay-btn { border: 0; border-radius: 8px; padding: .85rem 1.2rem; font-weight: 800; cursor: pointer; background: #2563eb; color: #fff; }
.pay-link-box { background: #f8fafc; border: 1px solid #dbeafe; border-radius: 10px; padding: 1rem; margin-bottom: 1rem; }
.pay-link-box input { width: 100%; border: 1px solid #bfdbfe; border-radius: 8px; padding: .75rem; margin-top: .5rem; }
.pay-error { background: #fef2f2; color: #991b1b; border-radius: 10px; padding: 1rem; margin-bottom: 1rem; }
.pay-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
.pay-table th, .pay-table td { padding: .75rem .5rem; border-bottom: 1px solid #e5e7eb; text-align: left; }
.pay-status { display: inline-block; border-radius: 999px; padding: .25rem .6rem; font-size: .78rem; font-weight: 700; background: #eff6ff; color: #1d4ed8; }
@media (max-width: 900px) { .pay-grid, .pay-form { grid-template-columns: 1fr; } .pay-field.span-2 { grid-column: auto; } }
</style>

<div class="pay-page">
    <div class="pay-header">
        <div>
            <h1>Solicitar pagamento</h1>
            <p>Gere um link para o cliente. A cobrança PixGo só nasce quando ele clicar em gerar Pix.</p>
        </div>
        <a class="pay-btn" href="index.php?page=comercial">Voltar</a>
    </div>

    <?php if ($errors): ?>
        <div class="pay-error"><?= h(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <?php if ($created): ?>
        <?php $publicUrl = comercial_pagamento_public_url((string)$created['token']); ?>
        <div class="pay-link-box">
            <strong>Link de pagamento gerado</strong>
            <input id="created-link" value="<?= h($publicUrl) ?>" readonly>
            <button class="pay-btn" type="button" onclick="navigator.clipboard.writeText(document.getElementById('created-link').value); this.textContent='Link copiado';">Copiar link</button>
        </div>
    <?php endif; ?>

    <div class="pay-grid">
        <section class="pay-card">
            <h2>Nova solicitação</h2>
            <form method="post" class="pay-form">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" id="evento_id" name="evento_id" value="<?= h($old['evento_id']) ?>">

                <div class="pay-field span-2">
                    <label for="evento_label">Este pagamento é relacionado a algum evento?</label>
                    <input id="evento_label" name="evento_label" type="search" list="eventos-list" value="<?= h($old['evento_label']) ?>" placeholder="Digite para pesquisar ou deixe em branco">
                    <datalist id="eventos-list">
                        <?php foreach ($eventOptions as $option): ?>
                            <option
                                value="<?= h($option['label']) ?>"
                                data-event-id="<?= h((string)$option['id']) ?>"
                                data-payer-name="<?= h($option['cliente_nome']) ?>"
                                data-payer-document="<?= h($option['cliente_documento']) ?>"
                            ></option>
                        <?php endforeach; ?>
                    </datalist>
                    <span class="pay-help">Ao selecionar um evento, o nome do cliente será usado apenas para identificação interna.</span>
                </div>

                <div class="pay-field span-2">
                    <label for="descricao">Descrição para o cliente</label>
                    <textarea id="descricao" name="descricao"><?= h($old['descricao']) ?></textarea>
                </div>

                <div class="pay-field">
                    <label for="valor">Valor original</label>
                    <input id="valor" name="valor" inputmode="decimal" placeholder="Ex.: 1500,00" value="<?= h($old['valor']) ?>" required>
                </div>

                <div class="pay-field">
                    <label for="vencimento">Data de vencimento</label>
                    <input id="vencimento" name="vencimento" type="date" value="<?= h($old['vencimento']) ?>" required>
                    <span class="pay-help">Após o vencimento: 2% de multa + 1% ao mês proporcional por dia.</span>
                </div>

                <input type="hidden" id="pagador_nome" name="pagador_nome" value="<?= h($old['pagador_nome']) ?>">
                <input type="hidden" id="pagador_documento" name="pagador_documento" value="">

                <div class="pay-info">
                    O CPF/CNPJ da conta pagadora será solicitado ao cliente no link de pagamento. Esse documento precisa ser da conta que fará o Pix.
                </div>

                <div class="pay-actions">
                    <button class="pay-btn" type="submit">Gerar link</button>
                </div>
            </form>
        </section>

        <aside class="pay-card">
            <h2>Últimos links</h2>
            <table class="pay-table">
                <thead><tr><th>Descrição</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($recentes as $row): ?>
                        <tr>
                            <td>
                                <?= h((string)$row['descricao']) ?><br>
                                <small><?= h(format_currency($row['valor_original'])) ?> · venc. <?= h(brDateOnly((string)$row['vencimento'])) ?></small>
                            </td>
                            <td><span class="pay-status"><?= h(comercial_pagamento_status_label((string)$row['status'])) ?></span></td>
                            <td><a href="<?= h(comercial_pagamento_public_url((string)$row['token'])) ?>" target="_blank" rel="noopener">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$recentes): ?><tr><td colspan="3">Nenhum link gerado ainda.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </aside>
    </div>
</div>

<script>
const eventOptions = <?= $eventOptionsJson ?>;
const eventInput = document.getElementById('evento_label');
const eventIdInput = document.getElementById('evento_id');
const payerName = document.getElementById('pagador_nome');
const payerDocument = document.getElementById('pagador_documento');
const payerNameNote = document.getElementById('payer-name-note');
const payerDocumentNote = document.getElementById('payer-document-note');
const descriptionInput = document.getElementById('descricao');
let lastSelectedEventId = eventIdInput.value || '';
let payerNameWasFilledFromEvent = false;
let payerDocumentWasFilledFromEvent = false;
let descriptionWasFilledFromEvent = isAutoPaymentDescription(descriptionInput?.value || '');

function normalizeEventLabel(value) {
    return String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}
function eventPaymentDescription(selected) {
    return 'Pagamento relacionado ao evento ' + (selected?.nome_evento || 'Evento');
}
function isAutoPaymentDescription(value) {
    const description = String(value || '').trim();
    return description === ''
        || description === 'Pagamento Smile Eventos'
        || /^Pagamento relacionado ao evento\s+.+/i.test(description);
}
function findSelectedEvent() {
    const selectedLabel = (eventInput.value || '').trim();
    const selectedKey = normalizeEventLabel(selectedLabel);
    if (!selectedKey) return null;
    const selectedOption = Array.from(document.querySelectorAll('#eventos-list option'))
        .find((option) => normalizeEventLabel(option.value) === selectedKey);
    const optionId = selectedOption ? String(selectedOption.dataset.eventId || '') : '';
    if (optionId) {
        const byId = eventOptions.find((item) => String(item.id) === optionId);
        if (byId) return byId;
        return {
            id: optionId,
            label: selectedOption.value,
            label_base: selectedOption.value,
            nome_evento: selectedOption.value,
            cliente_nome: selectedOption.dataset.payerName || '',
            cliente_documento: selectedOption.dataset.payerDocument || ''
        };
    }
    return eventOptions.find((item) => normalizeEventLabel(item.label) === selectedKey)
        || eventOptions.find((item) => normalizeEventLabel(item.label_base || '') === selectedKey)
        || eventOptions.find((item) => {
            const labelKey = normalizeEventLabel(item.label);
            const baseKey = normalizeEventLabel(item.label_base || item.label);
            return labelKey.startsWith(selectedKey) || selectedKey.startsWith(baseKey);
        })
        || null;
}
function applySelectedEvent() {
    const selected = findSelectedEvent();
    const selectedId = selected ? String(selected.id) : '';
    const changedEvent = selectedId !== lastSelectedEventId;
    eventIdInput.value = selected ? String(selected.id) : '';
    const hasPayerName = !!(selected?.cliente_nome || '').trim();
    const hasPayerDocument = !!(selected?.cliente_documento || '').trim();
    payerName.readOnly = !!selected && hasPayerName;
    payerDocument.readOnly = !!selected && hasPayerDocument;
    if (payerNameNote) payerNameNote.hidden = !selected;
    if (payerDocumentNote) payerDocumentNote.hidden = !selected;

    if (!selected) {
        if (changedEvent && payerNameWasFilledFromEvent) payerName.value = '';
        if (changedEvent && payerDocumentWasFilledFromEvent) payerDocument.value = '';
        if (changedEvent && descriptionWasFilledFromEvent && descriptionInput) descriptionInput.value = 'Pagamento Smile Eventos';
        payerNameWasFilledFromEvent = false;
        payerDocumentWasFilledFromEvent = false;
        descriptionWasFilledFromEvent = isAutoPaymentDescription(descriptionInput?.value || '');
        lastSelectedEventId = '';
        return;
    }

    if (hasPayerName && (changedEvent || !(payerName.value || '').trim())) {
        payerName.value = selected.cliente_nome || '';
        payerNameWasFilledFromEvent = true;
    } else if (changedEvent && payerNameWasFilledFromEvent) {
        payerName.value = '';
        payerNameWasFilledFromEvent = false;
    }
    if (hasPayerDocument && (changedEvent || !(payerDocument.value || '').trim())) {
        payerDocument.value = selected.cliente_documento || '';
        payerDocumentWasFilledFromEvent = true;
        payerDocument.placeholder = '';
        if (payerDocumentNote) payerDocumentNote.textContent = 'Preenchido pelo cliente vinculado ao evento.';
    } else {
        if (changedEvent && payerDocumentWasFilledFromEvent) payerDocument.value = '';
        payerDocumentWasFilledFromEvent = false;
        payerDocument.placeholder = 'CPF/CNPJ não cadastrado. Informe manualmente.';
        if (payerDocumentNote) payerDocumentNote.textContent = 'CPF/CNPJ não cadastrado neste cliente. Informe manualmente.';
    }
    if (payerNameNote) {
        payerNameNote.textContent = hasPayerName
            ? 'Preenchido pelo cliente vinculado ao evento.'
            : 'Nome não cadastrado neste cliente. Informe manualmente.';
    }
    if (descriptionInput) {
        const currentDescription = descriptionInput.value || '';
        const shouldUpdateDescription = changedEvent
            ? (descriptionWasFilledFromEvent || isAutoPaymentDescription(currentDescription))
            : isAutoPaymentDescription(currentDescription);
        if (shouldUpdateDescription) {
            descriptionInput.value = eventPaymentDescription(selected);
            descriptionWasFilledFromEvent = true;
        }
    }
    lastSelectedEventId = selectedId;
}
descriptionInput?.addEventListener('input', () => {
    descriptionWasFilledFromEvent = isAutoPaymentDescription(descriptionInput.value);
});
eventInput.addEventListener('input', applySelectedEvent);
eventInput.addEventListener('change', applySelectedEvent);
eventInput.addEventListener('blur', applySelectedEvent);
applySelectedEvent();
</script>

<?php endSidebar(); ?>
