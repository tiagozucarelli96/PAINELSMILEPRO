<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_contagem.php — Contagem guiada de estoque
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_logistico'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

function parse_decimal_local(string $value): float {
    $raw = trim($value);
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

function parse_decimal_local_nullable(string $value): ?float {
    $raw = trim($value);
    if ($raw === '') {
        return null;
    }
    return parse_decimal_local($raw);
}

function ensure_saldo(PDO $pdo, int $unidade_id, int $insumo_id, float $quantidade, ?int $unidade_medida_id): void {
    $stmt = $pdo->prepare("
        INSERT INTO logistica_estoque_saldos (unidade_id, insumo_id, quantidade_atual, unidade_medida_id, updated_at)
        VALUES (:unidade_id, :insumo_id, :quantidade, :unidade_medida_id, NOW())
        ON CONFLICT (unidade_id, insumo_id)
        DO UPDATE SET quantidade_atual = EXCLUDED.quantidade_atual,
                     unidade_medida_id = COALESCE(EXCLUDED.unidade_medida_id, logistica_estoque_saldos.unidade_medida_id),
                     updated_at = NOW()
    ");
    $stmt->execute([
        ':unidade_id' => $unidade_id,
        ':insumo_id' => $insumo_id,
        ':quantidade' => $quantidade,
        ':unidade_medida_id' => $unidade_medida_id
    ]);
}

function add_movimento(PDO $pdo, array $data): void {
    $stmt = $pdo->prepare("
        INSERT INTO logistica_estoque_movimentos
            (unidade_id_origem, unidade_id_destino, insumo_id, tipo, quantidade, referencia_tipo, referencia_id, criado_por, criado_em, observacao)
        VALUES
            (:origem, :destino, :insumo_id, :tipo, :quantidade, :ref_tipo, :ref_id, :criado_por, NOW(), :observacao)
    ");
    $stmt->execute([
        ':origem' => $data['origem'] ?? null,
        ':destino' => $data['destino'] ?? null,
        ':insumo_id' => $data['insumo_id'],
        ':tipo' => $data['tipo'],
        ':quantidade' => $data['quantidade'],
        ':ref_tipo' => $data['ref_tipo'] ?? null,
        ':ref_id' => $data['ref_id'] ?? null,
        ':criado_por' => $data['criado_por'] ?? null,
        ':observacao' => $data['observacao'] ?? null
    ]);
}

$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_item') {
        header('Content-Type: application/json');
        $contagem_id = (int)($_POST['contagem_id'] ?? 0);
        $insumo_id = (int)($_POST['insumo_id'] ?? 0);
        $unidade_medida_id = (int)($_POST['unidade_medida_id'] ?? 0);
        $quantidade = parse_decimal_local_nullable((string)($_POST['quantidade'] ?? ''));

        if ($contagem_id <= 0 || $insumo_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Dados inválidos.']);
            exit;
        }

        $status_stmt = $pdo->prepare("SELECT status FROM logistica_estoque_contagens WHERE id = :id");
        $status_stmt->execute([':id' => $contagem_id]);
        $status = $status_stmt->fetchColumn();
        if (!$status) {
            echo json_encode(['ok' => false, 'error' => 'Contagem não encontrada.']);
            exit;
        }
        if ($status !== 'rascunho') {
            echo json_encode(['ok' => false, 'error' => 'Contagem já finalizada.']);
            exit;
        }

        if ($quantidade === null) {
            $pdo->prepare("
                DELETE FROM logistica_estoque_contagens_itens
                WHERE contagem_id = :contagem_id AND insumo_id = :insumo_id
            ")->execute([
                ':contagem_id' => $contagem_id,
                ':insumo_id' => $insumo_id
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO logistica_estoque_contagens_itens
                    (contagem_id, insumo_id, quantidade_contada, unidade_medida_id, ordem)
                VALUES
                    (:contagem_id, :insumo_id, :quantidade, :unidade_medida_id, :ordem)
                ON CONFLICT (contagem_id, insumo_id)
                DO UPDATE SET quantidade_contada = EXCLUDED.quantidade_contada,
                             unidade_medida_id = COALESCE(EXCLUDED.unidade_medida_id, logistica_estoque_contagens_itens.unidade_medida_id),
                             ordem = EXCLUDED.ordem
            ");
            $stmt->execute([
                ':contagem_id' => $contagem_id,
                ':insumo_id' => $insumo_id,
                ':quantidade' => $quantidade,
                ':unidade_medida_id' => $unidade_medida_id ?: null,
                ':ordem' => (int)($_POST['ordem'] ?? 0)
            ]);
        }

        $posicao = (int)($_POST['posicao_atual'] ?? 0);
        if ($posicao > 0) {
            $pdo->prepare("UPDATE logistica_estoque_contagens SET posicao_atual = :pos, posicao_atual_em = NOW() WHERE id = :id")
                ->execute([':pos' => $posicao, ':id' => $contagem_id]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'start') {
        $unidade_id = (int)($_POST['unidade_id'] ?? 0);
        if ($unidade_id <= 0) {
            $errors[] = 'Selecione a unidade.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM logistica_estoque_contagens WHERE unidade_id = :uid AND status = 'rascunho' ORDER BY id DESC LIMIT 1");
            $stmt->execute([':uid' => $unidade_id]);
            $existing = $stmt->fetchColumn();
            if ($existing) {
                header('Location: index.php?page=logistica_contagem&contagem_id=' . (int)$existing);
                exit;
            }

            $stmt = $pdo->prepare("
                INSERT INTO logistica_estoque_contagens (unidade_id, status, iniciada_em, criado_por)
                VALUES (:uid, 'rascunho', NOW(), :criado_por)
                RETURNING id
            ");
            $stmt->execute([
                ':uid' => $unidade_id,
                ':criado_por' => (int)($_SESSION['id'] ?? 0)
            ]);
            $new_id = $stmt->fetchColumn();
            header('Location: index.php?page=logistica_contagem&contagem_id=' . (int)$new_id);
            exit;
        }
    }

    if ($action === 'finalize') {
        $contagem_id = (int)($_POST['contagem_id'] ?? 0);
        $allow_all_zero = !empty($_POST['allow_all_zero']);
        if ($contagem_id <= 0) {
            $errors[] = 'Contagem inválida.';
        } else {
            try {
                $pdo->beginTransaction();

                $contagem = $pdo->prepare("SELECT * FROM logistica_estoque_contagens WHERE id = :id FOR UPDATE");
                $contagem->execute([':id' => $contagem_id]);
                $contagem_row = $contagem->fetch(PDO::FETCH_ASSOC);
                if (!$contagem_row) {
                    throw new RuntimeException('Contagem não encontrada.');
                }
                if (($contagem_row['status'] ?? '') !== 'rascunho') {
                    throw new RuntimeException('Esta contagem já foi finalizada.');
                }

                $unidade_id = (int)$contagem_row['unidade_id'];
                $itens = $pdo->prepare("
                    SELECT ci.insumo_id, ci.quantidade_contada, ci.unidade_medida_id
                    FROM logistica_estoque_contagens_itens ci
                    WHERE ci.contagem_id = :cid
                      AND ci.quantidade_contada IS NOT NULL
                ");
                $itens->execute([':cid' => $contagem_id]);
                $itens = $itens->fetchAll(PDO::FETCH_ASSOC);
                if (!$itens) {
                    throw new RuntimeException('Nenhum item foi contado. Informe ao menos um item antes de finalizar.');
                }

                $ajustes = [];
                $total_itens = count($itens);
                $itens_zerados = 0;
                $itens_com_saldo_positivo = 0;

                foreach ($itens as $item) {
                    $insumo_id = (int)$item['insumo_id'];
                    $quantidade = (float)($item['quantidade_contada'] ?? 0);
                    $un_medida_id = !empty($item['unidade_medida_id']) ? (int)$item['unidade_medida_id'] : null;
                    if (abs($quantidade) < 0.000001) {
                        $itens_zerados++;
                    }

                    $saldo_stmt = $pdo->prepare("SELECT quantidade_atual FROM logistica_estoque_saldos WHERE unidade_id = :uid AND insumo_id = :iid");
                    $saldo_stmt->execute([':uid' => $unidade_id, ':iid' => $insumo_id]);
                    $saldo_atual = (float)($saldo_stmt->fetchColumn() ?? 0);
                    if ($saldo_atual > 0) {
                        $itens_com_saldo_positivo++;
                    }

                    $delta = $quantidade - $saldo_atual;
                    $ajustes[] = [
                        'insumo_id' => $insumo_id,
                        'quantidade' => $quantidade,
                        'saldo_atual' => $saldo_atual,
                        'delta' => $delta,
                        'unidade_medida_id' => $un_medida_id,
                    ];
                }

                if ($itens_zerados === $total_itens && $itens_com_saldo_positivo > 0 && !$allow_all_zero) {
                    throw new RuntimeException(
                        'Bloqueado por segurança: todos os itens contados ficaram zerados. Marque "Permitir zerar todos os itens" para confirmar.'
                    );
                }

                foreach ($ajustes as $ajuste) {
                    ensure_saldo(
                        $pdo,
                        $unidade_id,
                        (int)$ajuste['insumo_id'],
                        (float)$ajuste['quantidade'],
                        $ajuste['unidade_medida_id']
                    );

                    $delta = (float)$ajuste['delta'];
                    if (abs($delta) < 0.000001) {
                        continue;
                    }
                    $observacao = 'Ajuste contagem (' . ($delta >= 0 ? '+' : '') . number_format($delta, 4, ',', '.') . ')';
                    add_movimento($pdo, [
                        'origem' => null,
                        'destino' => $unidade_id,
                        'insumo_id' => (int)$ajuste['insumo_id'],
                        'tipo' => 'ajuste_contagem',
                        'quantidade' => abs($delta),
                        'ref_tipo' => 'contagem',
                        'ref_id' => $contagem_id,
                        'criado_por' => (int)($_SESSION['id'] ?? 0),
                        'observacao' => $observacao
                    ]);
                }

                $pdo->prepare("UPDATE logistica_estoque_contagens SET status = 'finalizada', finalizada_em = NOW() WHERE id = :id")
                    ->execute([':id' => $contagem_id]);

                $pdo->commit();
                $messages[] = 'Contagem finalizada e saldos ajustados.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Erro ao finalizar contagem: ' . $e->getMessage();
            }
        }
    }
}

$unidades = $pdo->query("SELECT id, nome FROM logistica_unidades WHERE ativo IS TRUE ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$contagem_id = (int)($_GET['contagem_id'] ?? 0);
$contagem = null;
$insumos = [];
$itens_map = [];
$last_counts = [];

if ($contagem_id > 0) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.nome AS unidade_nome
        FROM logistica_estoque_contagens c
        LEFT JOIN logistica_unidades u ON u.id = c.unidade_id
        WHERE c.id = :id
    ");
    $stmt->execute([':id' => $contagem_id]);
    $contagem = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($contagem) {
        $insumos = $pdo->query("
            SELECT i.id, i.nome_oficial, i.foto_url, i.unidade_medida_padrao_id, u.nome AS unidade_nome
            FROM logistica_insumos i
            LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
            WHERE i.ativo IS TRUE
            ORDER BY i.nome_oficial
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM logistica_estoque_contagens_itens WHERE contagem_id = :cid");
        $stmt->execute([':cid' => $contagem_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itens_map[(int)$row['insumo_id']] = $row;
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT ON (ci.insumo_id)
                ci.insumo_id, ci.quantidade_contada
            FROM logistica_estoque_contagens c
            JOIN logistica_estoque_contagens_itens ci ON ci.contagem_id = c.id
            WHERE c.status = 'finalizada' AND c.unidade_id = :uid
            ORDER BY ci.insumo_id, c.finalizada_em DESC NULLS LAST
        ");
        $stmt->execute([':uid' => (int)$contagem['unidade_id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $last_counts[(int)$row['insumo_id']] = $row['quantidade_contada'];
        }
    } else {
        $errors[] = 'Contagem não encontrada.';
    }
}

includeSidebar('Contagem de Estoque - Logística');
?>

<style>
.contagem-container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem;
}
.wizard-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.wizard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.wizard-item {
    display: flex;
    gap: 1.25rem;
    align-items: flex-start;
    margin-top: 1rem;
}
.wizard-photo {
    width: 120px;
    height: 120px;
    background: #f1f5f9;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    flex-shrink: 0;
}
.wizard-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.wizard-fields {
    flex: 1;
}
.wizard-fields h3 {
    margin: 0 0 .5rem 0;
}
.wizard-actions {
    display: flex;
    gap: .75rem;
    margin-top: 1rem;
}
.wizard-actions .btn {
    padding: .6rem 1rem;
    border-radius: 8px;
    border: 0;
    cursor: pointer;
}
.btn-primary { background:#2563eb; color:#fff; }
.btn-secondary { background:#e2e8f0; color:#0f172a; }
.badge-status { padding: .25rem .6rem; border-radius: 999px; font-size: .85rem; background:#e2e8f0; }
.alert-error, .alert-success { margin-bottom: 1rem; }
</style>

<div class="contagem-container">
    <h1>Contagem semanal</h1>

    <?php foreach ($errors as $e): ?>
        <div class="alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>

    <?php if (!$contagem): ?>
        <div class="wizard-card">
            <form method="post">
                <input type="hidden" name="action" value="start">
                <label>Unidade</label>
                <select name="unidade_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($unidades as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top:1rem;">
                    <button class="btn btn-primary" type="submit">Iniciar contagem</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="wizard-card" id="wizard">
            <div class="wizard-header">
                <div>
                    <strong>Unidade:</strong> <?= htmlspecialchars($contagem['unidade_nome'] ?? '') ?>
                    <span class="badge-status"><?= htmlspecialchars($contagem['status']) ?></span>
                </div>
                <div id="wizard-progress">Item 1 de <?= count($insumos) ?></div>
            </div>
            <div style="margin-bottom:.75rem;">
                <label>Buscar insumo</label>
                <input type="text" id="wizard-search" placeholder="Digite para pular">
            </div>
            <div class="wizard-item">
                <div class="wizard-photo" id="wizard-photo">📦</div>
                <div class="wizard-fields">
                    <h3 id="wizard-name">-</h3>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
                        <div style="min-width:220px;">
                            <label>Quantidade contada</label>
                            <input type="text" id="wizard-quantidade" placeholder="0,00">
                            <div id="wizard-last" style="color:#64748b;font-size:.85rem;margin-top:.35rem;"></div>
                        </div>
                        <div style="min-width:180px;">
                            <label>Unidade</label>
                            <input type="text" id="wizard-unidade" readonly>
                        </div>
                    </div>
                    <div class="wizard-actions">
                        <button class="btn btn-secondary" type="button" id="prev-btn">Anterior</button>
                        <button class="btn btn-secondary" type="button" id="next-btn">Próximo</button>
                        <form method="post" style="margin:0;" id="finalize-form">
                            <input type="hidden" name="action" value="finalize">
                            <input type="hidden" name="contagem_id" value="<?= (int)$contagem['id'] ?>">
                            <label style="display:flex;align-items:center;gap:.35rem;font-size:.8rem;color:#475569;margin-bottom:.4rem;">
                                <input type="checkbox" name="allow_all_zero" value="1">
                                Permitir zerar todos os itens
                            </label>
                            <button class="btn btn-primary" type="submit">Finalizar contagem</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($contagem): ?>
<script>
const CONTAGEM_ID = <?= (int)$contagem['id'] ?>;
const INSUMOS = <?= json_encode($insumos, JSON_UNESCAPED_UNICODE) ?>;
const ITENS_MAP = <?= json_encode($itens_map, JSON_UNESCAPED_UNICODE) ?>;
const LAST_COUNTS = <?= json_encode($last_counts, JSON_UNESCAPED_UNICODE) ?>;
let currentIndex = Math.max(0, <?= (int)($contagem['posicao_atual'] ?? 1) ?> - 1);
let debounce = null;
let pendingIndex = null;
let pendingValue = '';

function parseDecimalLocal(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return null;
    let normalized = raw.replace(/[^0-9,\\.]/g, '');
    if (normalized.includes(',') && normalized.includes('.')) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
    } else {
        normalized = normalized.replace(',', '.');
    }
    const parsed = Number(normalized);
    return Number.isNaN(parsed) ? null : parsed;
}

function formatNumber(value) {
    if (value === null || value === undefined) return '';
    const num = Number(value);
    if (Number.isNaN(num)) return '';
    return num.toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
}

function renderCurrent() {
    if (!INSUMOS.length) return;
    const item = INSUMOS[currentIndex];
    const saved = ITENS_MAP[item.id] || {};
    const hasSavedValue =
        saved.quantidade_contada !== undefined &&
        saved.quantidade_contada !== null &&
        saved.quantidade_contada !== '';
    document.getElementById('wizard-progress').textContent = `Item ${currentIndex + 1} de ${INSUMOS.length}`;
    document.getElementById('wizard-name').textContent = item.nome_oficial || '-';
    document.getElementById('wizard-quantidade').value = hasSavedValue ? formatNumber(saved.quantidade_contada) : '';
    document.getElementById('wizard-unidade').value = item.unidade_nome || 'Sem unidade';
    const last = LAST_COUNTS[item.id];
    const hasLast = last !== undefined && last !== null;
    document.getElementById('wizard-last').textContent = hasLast ? `Última contagem: ${formatNumber(last)}` : '';

    const photo = document.getElementById('wizard-photo');
    if (item.foto_url) {
        photo.innerHTML = `<img src="${item.foto_url}" alt="">`;
    } else {
        photo.textContent = '📦';
    }
}

function saveItem(index, quantidade) {
    if (index < 0 || index >= INSUMOS.length) {
        return Promise.resolve();
    }
    const item = INSUMOS[index];
    const payload = new URLSearchParams();
    payload.set('action', 'save_item');
    payload.set('contagem_id', CONTAGEM_ID);
    payload.set('insumo_id', item.id);
    payload.set('unidade_medida_id', item.unidade_medida_padrao_id || '');
    payload.set('quantidade', quantidade);
    payload.set('ordem', index + 1);
    payload.set('posicao_atual', index + 1);

    return fetch('index.php?page=logistica_contagem', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payload.toString()
    }).then((response) => response.json())
      .then((data) => {
        if (!data || data.ok !== true) {
            throw new Error(data?.error || 'Falha ao salvar item.');
        }
        const parsed = parseDecimalLocal(quantidade);
        if (parsed === null) {
            delete ITENS_MAP[item.id];
        } else {
            ITENS_MAP[item.id] = { quantidade_contada: parsed };
        }
      })
      .catch((error) => {
        console.error(error);
        throw error;
    });
}

function queueSaveCurrent() {
    pendingIndex = currentIndex;
    pendingValue = document.getElementById('wizard-quantidade').value || '';
    clearTimeout(debounce);
    debounce = setTimeout(() => {
        if (pendingIndex === null) return;
        saveItem(pendingIndex, pendingValue);
        pendingIndex = null;
    }, 400);
}

async function flushPendingSave() {
    clearTimeout(debounce);
    if (pendingIndex === null) return;
    const index = pendingIndex;
    const value = pendingValue;
    pendingIndex = null;
    await saveItem(index, value);
}

async function goToIndex(nextIndex) {
    if (nextIndex < 0 || nextIndex >= INSUMOS.length || nextIndex === currentIndex) {
        return;
    }
    try {
        await flushPendingSave();
        currentIndex = nextIndex;
        renderCurrent();
    } catch (error) {
        alert('Erro ao salvar o item atual. Tente novamente.');
    }
}

document.getElementById('wizard-quantidade').addEventListener('input', queueSaveCurrent);

document.getElementById('wizard-quantidade').addEventListener('blur', () => {
    flushPendingSave().catch((error) => console.error(error));
});

document.getElementById('wizard-quantidade').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (e.shiftKey) {
            if (currentIndex > 0) {
                goToIndex(currentIndex - 1);
            }
        } else {
            if (currentIndex < INSUMOS.length - 1) {
                goToIndex(currentIndex + 1);
            }
        }
    }
});

document.getElementById('prev-btn').addEventListener('click', () => {
    if (currentIndex > 0) {
        goToIndex(currentIndex - 1);
    }
});
document.getElementById('next-btn').addEventListener('click', () => {
    if (currentIndex < INSUMOS.length - 1) {
        goToIndex(currentIndex + 1);
    }
});

document.getElementById('wizard-search').addEventListener('input', (e) => {
    const term = (e.target.value || '').toLowerCase();
    if (!term) return;
    const idx = INSUMOS.findIndex(i => (i.nome_oficial || '').toLowerCase().includes(term));
    if (idx >= 0) {
        goToIndex(idx);
    }
});

document.getElementById('finalize-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
        await flushPendingSave();
        e.target.submit();
    } catch (error) {
        alert('Erro ao salvar o item atual. Corrija e tente finalizar novamente.');
    }
});

renderCurrent();
</script>
<?php endif; ?>

<?php endSidebar(); ?>
