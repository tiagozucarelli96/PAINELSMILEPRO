<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_gerar_lista.php — Gerador de lista de compras (sem estoque)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);
if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$errors = [];
$messages = [];
$result_groups = [];
$result_totals = [];
$result_meta = [];
$payload_saved = null;

function parse_decimal_local($value): float {
    $raw = trim((string)$value);
    if ($raw === '') return 0.0;
    $normalized = preg_replace('/[^0-9,\\.]/', '', $raw);
    if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } else {
        $normalized = str_replace(',', '.', $normalized);
    }
    return $normalized === '' ? 0.0 : (float)$normalized;
}

function explode_receita(
    int $receita_id,
    float $multiplicador,
    array $componentes_by_receita,
    array $insumos_map,
    array $receitas_yield,
    array &$totais,
    array &$stack,
    array &$errors
): void {
    if (isset($stack[$receita_id])) {
        $errors[] = 'Loop detectado em sub-receitas (ID ' . $receita_id . ').';
        return;
    }
    $stack[$receita_id] = true;
    $componentes = $componentes_by_receita[$receita_id] ?? [];
    foreach ($componentes as $comp) {
        $peso_bruto = (float)($comp['peso_bruto'] ?? 0);
        if ($peso_bruto <= 0) {
            $peso_liquido = (float)($comp['peso_liquido'] ?? 0);
            $fator = (float)($comp['fator_correcao'] ?? 1);
            if ($fator <= 0) { $fator = 1; }
            $peso_bruto = $peso_liquido * $fator;
        }
        if ($peso_bruto <= 0) {
            continue;
        }
        $quantidade_final = $peso_bruto * $multiplicador;
        $tipo = $comp['item_tipo'] ?? 'insumo';
        $item_id = (int)($comp['item_id'] ?? 0);
        if ($tipo === 'insumo') {
            if (!isset($insumos_map[$item_id])) { continue; }
            $totais[$item_id] = ($totais[$item_id] ?? 0) + $quantidade_final;
        } else {
            $yield_sub = (float)($receitas_yield[$item_id] ?? 0);
            if ($yield_sub <= 0) {
                $errors[] = 'Sub-receita sem rendimento (ID ' . $item_id . ').';
                continue;
            }
            $sub_multiplier = $quantidade_final / $yield_sub;
            explode_receita($item_id, $sub_multiplier, $componentes_by_receita, $insumos_map, $receitas_yield, $totais, $stack, $errors);
        }
    }
    unset($stack[$receita_id]);
}

function build_lista_consolidada(
    array $payload,
    array $eventos_map,
    array $insumos_map,
    array $receitas_yield,
    array $componentes_by_receita,
    array &$errors
): array {
    $totais = [];
    $unit_lock = null;

    foreach ($payload['eventos'] as $ev) {
        $event_id = (int)($ev['evento_id'] ?? 0);
        if (!$event_id || !isset($eventos_map[$event_id])) {
            $errors[] = 'Evento inválido na lista.';
            continue;
        }
        $evento = $eventos_map[$event_id];
        if (empty($evento['unidade_interna_id'])) {
            $errors[] = 'Evento sem unidade mapeada: ' . ($evento['nome_evento'] ?? '');
            continue;
        }
        if ($unit_lock === null) {
            $unit_lock = (int)$evento['unidade_interna_id'];
        } elseif ($unit_lock !== (int)$evento['unidade_interna_id']) {
            $errors[] = 'Você tentou misturar unidades. Separe por unidade.';
            continue;
        }

        foreach ($ev['itens'] as $item) {
            $tipo = $item['tipo'] ?? '';
            $item_id = (int)($item['item_id'] ?? 0);
            $quantidade = (float)($item['quantidade'] ?? 0);
            if ($item_id <= 0 || $quantidade <= 0) { continue; }

            if ($tipo === 'insumo') {
                if (!isset($insumos_map[$item_id])) { continue; }
                $totais[$item_id] = ($totais[$item_id] ?? 0) + $quantidade;
            } else {
                $yield = (float)($receitas_yield[$item_id] ?? 0);
                if ($yield <= 0) {
                    $errors[] = 'Receita sem rendimento (ID ' . $item_id . ').';
                    continue;
                }
                $multiplicador = $quantidade / $yield;
                $stack = [];
                explode_receita($item_id, $multiplicador, $componentes_by_receita, $insumos_map, $receitas_yield, $totais, $stack, $errors);
            }
        }
    }

    return [$totais, $unit_lock];
}

function build_totais_evento(
    array $itens,
    array $insumos_map,
    array $receitas_yield,
    array $componentes_by_receita,
    array &$errors
): array {
    $totais = [];
    foreach ($itens as $item) {
        $tipo = $item['tipo'] ?? '';
        $item_id = (int)($item['item_id'] ?? 0);
        $quantidade = (float)($item['quantidade'] ?? 0);
        if ($item_id <= 0 || $quantidade <= 0) { continue; }

        if ($tipo === 'insumo') {
            if (!isset($insumos_map[$item_id])) { continue; }
            $totais[$item_id] = ($totais[$item_id] ?? 0) + $quantidade;
        } else {
            $yield = (float)($receitas_yield[$item_id] ?? 0);
            if ($yield <= 0) {
                $errors[] = 'Receita sem rendimento (ID ' . $item_id . ').';
                continue;
            }
            $multiplicador = $quantidade / $yield;
            $stack = [];
            explode_receita($item_id, $multiplicador, $componentes_by_receita, $insumos_map, $receitas_yield, $totais, $stack, $errors);
        }
    }
    return $totais;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'log_unmapped') {
        $event_id = (int)($_POST['evento_id'] ?? 0);
        if ($event_id > 0) {
            $stmt = $pdo->prepare("
                SELECT nome_evento, localevento, unidade_interna_id
                FROM logistica_eventos_espelho
                WHERE id = :id
            ");
            $stmt->execute([':id' => $event_id]);
            $ev = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($ev && empty($ev['unidade_interna_id'])) {
                $pdo->prepare("
                    INSERT INTO logistica_alertas_log
                        (tipo, unidade_id, referencia_tipo, referencia_id, mensagem, criado_em, criado_por)
                    VALUES
                        ('local_nao_mapeado', NULL, 'evento', :ref_id, :mensagem, NOW(), :criado_por)
                ")->execute([
                    ':ref_id' => $event_id,
                    ':mensagem' => 'Local não mapeado: ' . ($ev['nome_evento'] ?? 'Evento') . ' — ' . ($ev['localevento'] ?? ''),
                    ':criado_por' => (int)($_SESSION['id'] ?? 0)
                ]);
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }
    $payload_raw = $_POST['payload'] ?? '';
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) {
        $errors[] = 'Payload inválido.';
    }

    $eventos_map = [];
    $stmt = $pdo->query("
        SELECT e.*, u.nome AS unidade_nome
        FROM logistica_eventos_espelho e
        LEFT JOIN logistica_unidades u ON u.id = e.unidade_interna_id
        WHERE e.arquivado IS FALSE
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eventos_map[(int)$row['id']] = $row;
    }

    $insumos_map = [];
    $stmt = $pdo->query("SELECT id, nome_oficial, tipologia_insumo_id, unidade_medida_padrao_id FROM logistica_insumos");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $insumos_map[(int)$row['id']] = $row;
    }

    $receitas_yield = [];
    $stmt = $pdo->query("SELECT id, rendimento_base_pessoas FROM logistica_receitas");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $receitas_yield[(int)$row['id']] = (float)($row['rendimento_base_pessoas'] ?? 0);
    }

    $componentes_by_receita = [];
    $stmt = $pdo->query("SELECT * FROM logistica_receita_componentes ORDER BY id");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = (int)$row['receita_id'];
        if (!isset($componentes_by_receita[$rid])) {
            $componentes_by_receita[$rid] = [];
        }
        $componentes_by_receita[$rid][] = $row;
    }

    if (!$errors) {
        [$totais, $unit_lock] = build_lista_consolidada($payload, $eventos_map, $insumos_map, $receitas_yield, $componentes_by_receita, $errors);
        if (!$errors) {
            $result_totals = $totais;
            $payload_saved = $payload;
            $result_meta['unidade_interna_id'] = $unit_lock;

            if ($action === 'save') {
                try {
                    $pdo->beginTransaction();
                    $user_id = (int)($_SESSION['id'] ?? 0);
                    $space_visivel = null;
                    if (!empty($payload['eventos'][0]['space_visivel'])) {
                        $space_visivel = $payload['eventos'][0]['space_visivel'];
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO logistica_listas (unidade_interna_id, space_visivel, criado_por, criado_em, status)
                        VALUES (:unidade_interna_id, :space_visivel, :criado_por, NOW(), 'gerada')
                        RETURNING id
                    ");
                    $stmt->execute([
                        ':unidade_interna_id' => $unit_lock,
                        ':space_visivel' => $space_visivel,
                        ':criado_por' => $user_id > 0 ? $user_id : null
                    ]);
                    $lista_id = (int)$stmt->fetchColumn();

                    $stmtEvento = $pdo->prepare("
                        INSERT INTO logistica_lista_eventos
                        (lista_id, evento_id, me_event_id, nome_evento, data_evento, hora_inicio, convidados, localevento, space_visivel, unidade_interna_id)
                        VALUES
                        (:lista_id, :evento_id, :me_event_id, :nome_evento, :data_evento, :hora_inicio, :convidados, :localevento, :space_visivel, :unidade_interna_id)
                    ");
                    foreach ($payload['eventos'] as $ev) {
                        $evento = $eventos_map[(int)$ev['evento_id']];
                        $stmtEvento->execute([
                            ':lista_id' => $lista_id,
                            ':evento_id' => (int)$evento['id'],
                            ':me_event_id' => (int)$evento['me_event_id'],
                            ':nome_evento' => $evento['nome_evento'],
                            ':data_evento' => $evento['data_evento'],
                            ':hora_inicio' => $evento['hora_inicio'],
                            ':convidados' => $evento['convidados'],
                            ':localevento' => $evento['localevento'],
                            ':space_visivel' => $evento['space_visivel'],
                            ':unidade_interna_id' => $evento['unidade_interna_id']
                        ]);
                    }

                    $stmtListaEvento = $pdo->prepare("
                        INSERT INTO logistica_lista_evento_itens
                            (lista_id, evento_id, insumo_id, unidade_medida_id, quantidade_total_bruto)
                        VALUES
                            (:lista_id, :evento_id, :insumo_id, :unidade_medida_id, :quantidade_total_bruto)
                    ");
                    foreach ($payload['eventos'] as $ev) {
                        $event_id = (int)($ev['evento_id'] ?? 0);
                        if ($event_id <= 0) { continue; }
                        $totais_evento = build_totais_evento(
                            $ev['itens'] ?? [],
                            $insumos_map,
                            $receitas_yield,
                            $componentes_by_receita,
                            $errors
                        );
                        foreach ($totais_evento as $insumo_id => $quantidade) {
                            $un_medida_id = $insumos_map[$insumo_id]['unidade_medida_padrao_id'] ?? null;
                            $stmtListaEvento->execute([
                                ':lista_id' => $lista_id,
                                ':evento_id' => $event_id,
                                ':insumo_id' => $insumo_id,
                                ':unidade_medida_id' => $un_medida_id,
                                ':quantidade_total_bruto' => $quantidade
                            ]);
                        }
                    }

                    $stmtItem = $pdo->prepare("
                        INSERT INTO logistica_lista_itens
                        (lista_id, insumo_id, tipologia_insumo_id, unidade_medida_id, quantidade_total_bruto)
                        VALUES
                        (:lista_id, :insumo_id, :tipologia_insumo_id, :unidade_medida_id, :quantidade_total_bruto)
                    ");
                    foreach ($result_totals as $insumo_id => $quantidade) {
                        $insumo = $insumos_map[$insumo_id];
                        $stmtItem->execute([
                            ':lista_id' => $lista_id,
                            ':insumo_id' => $insumo_id,
                            ':tipologia_insumo_id' => $insumo['tipologia_insumo_id'] ?: null,
                            ':unidade_medida_id' => $insumo['unidade_medida_padrao_id'] ?: null,
                            ':quantidade_total_bruto' => $quantidade
                        ]);
                    }

                    $pdo->commit();
                    $messages[] = 'Lista salva com sucesso.';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $errors[] = 'Erro ao salvar lista: ' . $e->getMessage();
                }
            }
        }
    }
}

$eventos = $pdo->query("
    SELECT e.id, e.me_event_id, e.data_evento, e.hora_inicio, e.convidados, e.localevento,
           e.nome_evento, e.space_visivel, e.unidade_interna_id, e.status_mapeamento,
           u.nome AS unidade_nome
    FROM logistica_eventos_espelho e
    LEFT JOIN logistica_unidades u ON u.id = e.unidade_interna_id
    WHERE e.arquivado IS FALSE
      AND e.data_evento BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '30 days')
    ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST
")->fetchAll(PDO::FETCH_ASSOC);

$insumos_select = $pdo->query("
    SELECT id, nome_oficial, unidade_medida_padrao_id, sinonimos
    FROM logistica_insumos
    WHERE ativo IS TRUE
    ORDER BY nome_oficial
")->fetchAll(PDO::FETCH_ASSOC);

$receitas_select = $pdo->query("
    SELECT id, nome, rendimento_base_pessoas
    FROM logistica_receitas
    WHERE ativo IS TRUE
    ORDER BY nome
")->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Logística - Gerar Lista');
?>

<style>
.page-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}
.section-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.form-input {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
}
.btn-primary {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}
.btn-secondary {
    background: #e2e8f0;
    color: #1f2937;
    border: none;
    padding: 0.5rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    text-align: left;
    padding: 0.6rem;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
}
.event-card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
    background: #fff;
    cursor: pointer;
}
.event-card.pending {
    border-color: #fecaca;
    background: #fff5f5;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.1rem 0.5rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    margin-left: 0.5rem;
}
.status-badge.mapeado { background: #dcfce7; color: #166534; }
.status-badge.pendente { background: #fee2e2; color: #991b1b; }
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    z-index: 2000;
    align-items: center;
    justify-content: center;
}
.item-row {
    display: grid;
    grid-template-columns: 1.5fr 0.6fr 0.8fr 0.4fr;
    gap: 0.5rem;
    align-items: center;
    margin-bottom: 0.5rem;
}
.item-label {
    min-height: 36px;
    padding: 0.5rem 0.75rem;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
    background: #f8fafc;
    display: flex;
    align-items: center;
}
.item-controls {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}
.alert-error { background: #fee2e2; color: #991b1b; }
.alert-success { background: #dcfce7; color: #166534; }
</style>

<div class="page-container">
    <h1>Gerar Lista de Compras</h1>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>

    <div class="section-card">
        <h2>Selecionar eventos</h2>
        <div style="display:flex;gap:0.75rem;align-items:center;">
            <input class="form-input" id="evento-search" placeholder="Buscar evento por nome, data ou local">
            <button class="btn-secondary" type="button" id="btn-add-evento">Adicionar evento</button>
        </div>
        <div id="evento-sugestoes" style="margin-top:0.5rem;"></div>
    </div>

    <form method="POST" id="form-gerar">
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="payload" id="payload-input">
        <div class="section-card">
            <h2>Eventos selecionados</h2>
            <div id="eventos-lista"></div>
        </div>

        <div class="section-card">
            <h2>Resultado consolidado</h2>
            <?php if ($result_totals): ?>
                <?php
                    $tipologias = $pdo->query("SELECT id, nome FROM logistica_tipologias_insumo")->fetchAll(PDO::FETCH_KEY_PAIR);
                    $unidades = $pdo->query("SELECT id, nome FROM logistica_unidades_medida")->fetchAll(PDO::FETCH_KEY_PAIR);
                    foreach ($result_totals as $insumo_id => $qtd) {
                        $insumo = $insumo_id;
                    }
                    $by_tip = [];
                    foreach ($result_totals as $insumo_id => $qtd) {
                        $insumo = $insumos_map[$insumo_id] ?? null;
                        if (!$insumo) { continue; }
                        $tid = $insumo['tipologia_insumo_id'] ?: 0;
                        if (!isset($by_tip[$tid])) { $by_tip[$tid] = []; }
                        $by_tip[$tid][] = [
                            'nome' => $insumo['nome_oficial'],
                            'unidade' => $unidades[$insumo['unidade_medida_padrao_id']] ?? '',
                            'quantidade' => $qtd
                        ];
                    }
                ?>
                <?php foreach ($by_tip as $tid => $items): ?>
                    <h3><?= h($tid && isset($tipologias[$tid]) ? $tipologias[$tid] : 'Sem tipologia') ?></h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Unidade</th>
                                <th>Quantidade total (bruto)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $it): ?>
                                <tr>
                                    <td><?= h($it['nome']) ?></td>
                                    <td><?= h($it['unidade']) ?></td>
                                    <td><?= number_format((float)$it['quantidade'], 4, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
                <div style="margin-top:1rem;">
                    <button class="btn-primary" type="button" onclick="saveLista()">Salvar lista</button>
                </div>
            <?php else: ?>
                <p>Nenhum item calculado ainda.</p>
            <?php endif; ?>
        </div>

        <div style="margin-bottom:1rem;">
            <button class="btn-primary" type="button" onclick="gerarLista()">Gerar lista final</button>
        </div>
    </form>
</div>

<div class="modal-overlay" id="modal-item">
    <div class="modal" style="width:min(900px,94vw);max-height:90vh;overflow:auto;padding:1rem;border-radius:12px;background:#fff;">
        <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;">
            <div class="modal-title" style="font-weight:700;">Selecionar item</div>
            <button class="btn-secondary" type="button" onclick="closeItemModal()">X</button>
        </div>
        <div class="item-controls" style="margin-bottom:0.75rem;">
            <button type="button" class="btn-secondary" id="tab-insumos">Insumos</button>
            <button type="button" class="btn-secondary" id="tab-receitas">Receitas</button>
            <input class="form-input" id="item-search" placeholder="Buscar...">
        </div>
        <div id="item-list" style="max-height:50vh;overflow:auto;border:1px solid #e5e7eb;border-radius:8px;padding:0.5rem;"></div>
        <div style="margin-top:0.75rem;">
            <button class="btn-primary" type="button" onclick="confirmItem()">Selecionar</button>
        </div>
    </div>
</div>

<script>
const EVENTOS = <?= json_encode($eventos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const INSUMOS = <?= json_encode(array_map(fn($i) => ['id' => (int)$i['id'], 'nome' => $i['nome_oficial'], 'unidade_padrao' => (int)($i['unidade_medida_padrao_id'] ?? 0), 'sinonimos' => $i['sinonimos'] ?? ''], $insumos_select), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const RECEITAS = <?= json_encode(array_map(fn($r) => ['id' => (int)$r['id'], 'nome' => $r['nome'], 'rendimento' => (int)($r['rendimento_base_pessoas'] ?? 1)], $receitas_select), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

let selectedEvents = [];
let unitLock = null;

const searchInput = document.getElementById('evento-search');
const suggestions = document.getElementById('evento-sugestoes');
const addBtn = document.getElementById('btn-add-evento');

function renderSuggestions() {
    const q = (searchInput.value || '').toLowerCase();
    const list = EVENTOS.filter(ev => {
        const nome = (ev.nome_evento || '').toLowerCase();
        const local = (ev.localevento || '').toLowerCase();
        const data = (ev.data_evento || '').toLowerCase();
        const hora = (ev.hora_inicio || '').toLowerCase();
        return nome.includes(q) || local.includes(q) || data.includes(q) || hora.includes(q);
    }).slice(0, 10);
    suggestions.innerHTML = list.map(ev => `
        <div class="event-card ${ev.unidade_interna_id ? '' : 'pending'}" data-id="${ev.id}">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <strong>${ev.nome_evento || 'Evento'}</strong>
                <span class="status-badge ${ev.unidade_interna_id ? 'mapeado' : 'pendente'}">
                    ${ev.unidade_interna_id ? 'MAPEADO' : 'PENDENTE'}
                </span>
            </div>
            <div style="margin-top:0.35rem;font-size:0.9rem;color:#334155;">
                ${ev.data_evento || ''} ${ev.hora_inicio || ''} · ${ev.localevento || ''} · ${ev.space_visivel || ''}
            </div>
            <div style="margin-top:0.25rem;font-size:0.85rem;color:#64748b;">
                Convidados: ${ev.convidados || 0} · Unidade: ${ev.unidade_nome || '-'}
            </div>
        </div>
    `).join('');
    suggestions.querySelectorAll('.event-card').forEach(card => {
        card.addEventListener('click', () => {
            const ev = EVENTOS.find(e => e.id == card.dataset.id);
            if (ev) {
                searchInput.value = ev.nome_evento || '';
                searchInput.dataset.selectedId = ev.id;
            }
        });
    });
}

searchInput.addEventListener('input', renderSuggestions);

addBtn.addEventListener('click', () => {
    const id = parseInt(searchInput.dataset.selectedId || '0', 10);
    const ev = EVENTOS.find(e => e.id === id);
    if (!ev) {
        alert('Selecione um evento válido.');
        return;
    }
    if (!ev.unidade_interna_id) {
        fetch('index.php?page=logistica_gerar_lista', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'log_unmapped', evento_id: ev.id })
        });
        alert('Evento sem unidade mapeada. Faça o mapeamento antes.');
        return;
    }
    if (unitLock && unitLock !== ev.unidade_interna_id) {
        alert('Você tentou misturar unidades. Separe por unidade.');
        return;
    }
    if (!unitLock) unitLock = ev.unidade_interna_id;
    if (selectedEvents.some(e => e.id === ev.id)) {
        alert('Evento já adicionado.');
        return;
    }
    selectedEvents.push({
        id: ev.id,
        nome: ev.nome_evento || 'Evento',
        data_evento: ev.data_evento,
        hora_inicio: ev.hora_inicio,
        convidados: ev.convidados || 0,
        localevento: ev.localevento,
        space_visivel: ev.space_visivel,
        unidade_interna_id: ev.unidade_interna_id,
        itens: []
    });
    renderSelectedEvents();
});

function renderSelectedEvents() {
    const container = document.getElementById('eventos-lista');
    container.innerHTML = selectedEvents.map(ev => `
        <div class="event-card" data-id="${ev.id}">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <strong>${ev.nome}</strong><br>
                    ${ev.data_evento} ${ev.hora_inicio || ''} · ${ev.localevento || ''} · ${ev.space_visivel || ''}
                    · Convidados: ${ev.convidados || 0}
                </div>
                <button class="btn-secondary" type="button" onclick="removerEvento(${ev.id})">Remover</button>
            </div>
            <div style="margin-top:0.75rem;">
                <strong>Itens do Evento</strong>
                <div id="itens-${ev.id}"></div>
                <button class="btn-secondary" type="button" onclick="adicionarItem(${ev.id})">Adicionar item</button>
            </div>
        </div>
    `).join('');
    selectedEvents.forEach(ev => renderItems(ev.id));
}

function renderItems(eventId) {
    const ev = selectedEvents.find(e => e.id === eventId);
    const container = document.getElementById(`itens-${eventId}`);
    if (!ev || !container) return;
    container.innerHTML = ev.itens.map((it, idx) => `
        <div class="item-row">
            <div class="item-controls">
                <div class="item-label">${it.nome || '-'}</div>
                <button class="btn-secondary" type="button" onclick="openItemModal(${eventId}, ${idx})">🔍</button>
            </div>
            <input class="form-input" type="text" value="${it.quantidade || ''}" oninput="updateQuantidade(${eventId}, ${idx}, this.value)">
            <select class="form-input" onchange="updateTipoQtd(${eventId}, ${idx}, this.value)">
                <option value="pessoas" ${it.tipo_qtd === 'pessoas' ? 'selected' : ''}>Pessoas</option>
                <option value="unidade" ${it.tipo_qtd === 'unidade' ? 'selected' : ''}>Unidade</option>
            </select>
            <button class="btn-secondary" type="button" onclick="removerItem(${eventId}, ${idx})">X</button>
        </div>
    `).join('');
}

function adicionarItem(eventId) {
    const ev = selectedEvents.find(e => e.id === eventId);
    if (!ev) return;
    ev.itens.push({
        tipo: 'receita',
        item_id: 0,
        nome: '',
        quantidade: ev.convidados || 0,
        tipo_qtd: 'pessoas'
    });
    renderItems(eventId);
}

function removerEvento(eventId) {
    selectedEvents = selectedEvents.filter(e => e.id !== eventId);
    if (!selectedEvents.length) unitLock = null;
    renderSelectedEvents();
}

function removerItem(eventId, idx) {
    const ev = selectedEvents.find(e => e.id === eventId);
    if (!ev) return;
    ev.itens.splice(idx, 1);
    renderItems(eventId);
}

function updateQuantidade(eventId, idx, value) {
    const ev = selectedEvents.find(e => e.id === eventId);
    if (!ev) return;
    ev.itens[idx].quantidade = value;
}

function updateTipoQtd(eventId, idx, value) {
    const ev = selectedEvents.find(e => e.id === eventId);
    if (!ev) return;
    ev.itens[idx].tipo_qtd = value;
}

let modalTarget = null;
let modalTab = 'receitas';
let modalSelected = null;

function openItemModal(eventId, idx) {
    modalTarget = { eventId, idx };
    modalTab = 'receitas';
    modalSelected = null;
    document.getElementById('item-search').value = '';
    renderItemList();
    document.getElementById('modal-item').style.display = 'flex';
}

function closeItemModal() {
    document.getElementById('modal-item').style.display = 'none';
    modalTarget = null;
    modalSelected = null;
}

function renderItemList() {
    const listEl = document.getElementById('item-list');
    const q = (document.getElementById('item-search').value || '').toLowerCase();
    const source = modalTab === 'insumos' ? INSUMOS : RECEITAS;
    const items = source.filter(item => {
        const name = (item.nome || '').toLowerCase();
        if (name.includes(q)) return true;
        if (modalTab === 'insumos' && item.sinonimos) {
            return item.sinonimos.toLowerCase().includes(q);
        }
        return q === '';
    });
    listEl.innerHTML = items.map(item => {
        const selected = modalSelected && modalSelected.id === item.id ? ' selected' : '';
        return `<button type="button" class="item-option${selected}" data-id="${item.id}" data-nome="${item.nome}">${item.nome}</button>`;
    }).join('');
    listEl.querySelectorAll('.item-option').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id || '0', 10);
            const nome = btn.dataset.nome || '';
            modalSelected = { id, nome, tipo: modalTab === 'insumos' ? 'insumo' : 'receita' };
            renderItemList();
        });
    });
}

document.getElementById('tab-insumos').addEventListener('click', () => { modalTab = 'insumos'; modalSelected = null; renderItemList(); });
document.getElementById('tab-receitas').addEventListener('click', () => { modalTab = 'receitas'; modalSelected = null; renderItemList(); });
document.getElementById('item-search').addEventListener('input', renderItemList);

function confirmItem() {
    if (!modalTarget || !modalSelected) return;
    const ev = selectedEvents.find(e => e.id === modalTarget.eventId);
    if (!ev) return;
    const item = ev.itens[modalTarget.idx];
    item.tipo = modalSelected.tipo;
    item.item_id = modalSelected.id;
    item.nome = modalSelected.nome;
    if (item.tipo === 'receita') {
        item.tipo_qtd = 'pessoas';
        if (!item.quantidade) {
            item.quantidade = ev.convidados || 0;
        }
    } else {
        item.tipo_qtd = 'unidade';
    }
    renderItems(ev.id);
    closeItemModal();
}

document.getElementById('modal-item').addEventListener('click', (e) => {
    if (e.target.id === 'modal-item') {
        closeItemModal();
    }
});

function buildPayload() {
    return {
        eventos: selectedEvents.map(ev => ({
            evento_id: ev.id,
            space_visivel: ev.space_visivel,
            itens: ev.itens.map(it => ({
                tipo: it.tipo,
                item_id: it.item_id,
                quantidade: parseFloat((it.quantidade || '0').toString().replace(',', '.')) || 0
            }))
        }))
    };
}

function gerarLista() {
    if (!selectedEvents.length) {
        alert('Adicione pelo menos um evento.');
        return;
    }
    document.getElementById('payload-input').value = JSON.stringify(buildPayload());
    document.querySelector('#form-gerar input[name="action"]').value = 'generate';
    document.getElementById('form-gerar').submit();
}

function saveLista() {
    if (!selectedEvents.length) {
        alert('Adicione pelo menos um evento.');
        return;
    }
    document.getElementById('payload-input').value = JSON.stringify(buildPayload());
    document.querySelector('#form-gerar input[name="action"]').value = 'save';
    document.getElementById('form-gerar').submit();
}
</script>

<?php endSidebar(); ?>
