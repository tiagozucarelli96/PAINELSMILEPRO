<?php
// logistica_revisar_custos.php — Revisão mensal de custos
date_default_timezone_set('America/Sao_Paulo');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

$can_finance = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_financeiro']);
if (!$can_finance) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

function parse_decimal_local(string $value): ?float {
    $raw = trim($value);
    if ($raw === '') return null;
    $normalized = preg_replace('/[^0-9,\\.]/', '', $raw);
    if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    } else {
        $normalized = str_replace(',', '.', $normalized);
    }
    return $normalized === '' ? null : (float)$normalized;
}

$messages = [];
$errors = [];
$user_id = (int)($_SESSION['id'] ?? 0);

if ($user_id <= 0) {
    $errors[] = 'Usuário inválido.';
}

if ($user_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM logistica_revisao_custos WHERE usuario_id = :uid");
    $stmt->execute([':uid' => $user_id]);
    $progress = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$progress) {
        $pdo->prepare("INSERT INTO logistica_revisao_custos (usuario_id, posicao_atual, iniciado_em) VALUES (:uid, 1, NOW())")
            ->execute([':uid' => $user_id]);
        $progress = ['posicao_atual' => 1];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_id > 0) {
    $action = $_POST['action'] ?? '';
    $insumo_id = (int)($_POST['insumo_id'] ?? 0);
    $custo_novo = parse_decimal_local((string)($_POST['custo_novo'] ?? ''));
    $posicao = (int)($_POST['posicao'] ?? 1);

    if ($insumo_id > 0) {
        $stmt = $pdo->prepare("SELECT custo_padrao FROM logistica_insumos WHERE id = :id");
        $stmt->execute([':id' => $insumo_id]);
        $custo_atual = $stmt->fetchColumn();
        $custo_atual = $custo_atual !== null ? (float)$custo_atual : null;

        if ($custo_novo !== null && $custo_novo !== $custo_atual) {
            $pdo->prepare("UPDATE logistica_insumos SET custo_padrao = :custo WHERE id = :id")
                ->execute([':custo' => $custo_novo, ':id' => $insumo_id]);

            $pdo->prepare("
                INSERT INTO logistica_custos_log (insumo_id, usuario_id, custo_anterior, custo_novo, criado_em)
                VALUES (:insumo_id, :usuario_id, :custo_anterior, :custo_novo, NOW())
            ")->execute([
                ':insumo_id' => $insumo_id,
                ':usuario_id' => $user_id,
                ':custo_anterior' => $custo_atual,
                ':custo_novo' => $custo_novo
            ]);
        }
    }

    if ($action === 'next') {
        $posicao++;
    } elseif ($action === 'prev') {
        $posicao = max(1, $posicao - 1);
    }

    $pdo->prepare("UPDATE logistica_revisao_custos SET posicao_atual = :pos, posicao_atual_em = NOW() WHERE usuario_id = :uid")
        ->execute([':pos' => $posicao, ':uid' => $user_id]);
    $messages[] = 'Progresso salvo.';
}

$insumos = $pdo->query("
    SELECT id, nome_oficial, foto_url, custo_padrao
    FROM logistica_insumos
    WHERE ativo IS TRUE
    ORDER BY nome_oficial
")->fetchAll(PDO::FETCH_ASSOC);

$total_insumos = count($insumos);
$posicao_atual = (int)($progress['posicao_atual'] ?? 1);
$posicao_atual = max(1, min($total_insumos, $posicao_atual));
$insumo_atual = $insumos[$posicao_atual - 1] ?? null;

includeSidebar('Revisão de Custos - Logística');
?>

<style>
.page-container { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
.card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.5rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); }
.btn-primary { background:#2563eb; color:#fff; border:none; padding:.5rem .9rem; border-radius:8px; cursor:pointer; }
.btn-secondary { background:#e2e8f0; color:#0f172a; border:none; padding:.5rem .9rem; border-radius:8px; cursor:pointer; }
.thumb { width:120px; height:120px; border-radius:12px; background:#f1f5f9; overflow:hidden; border:1px solid #e5e7eb; display:flex; align-items:center; justify-content:center; }
.thumb img { width:100%; height:100%; object-fit:cover; }
.alert-success { background:#dcfce7; color:#166534; padding:.6rem .9rem; border-radius:8px; margin-bottom:1rem; }
.alert-error { background:#fee2e2; color:#991b1b; padding:.6rem .9rem; border-radius:8px; margin-bottom:1rem; }
</style>

<div class="page-container">
    <h1>Revisão de custos</h1>

    <?php foreach ($messages as $m): ?>
        <div class="alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <?php if ($insumo_atual): ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">
                <div>Item <?= $posicao_atual ?> de <?= $total_insumos ?></div>
            </div>
            <div style="display:flex;gap:1.5rem;margin-top:1rem;align-items:center;">
                <div class="thumb">
                    <?php if (!empty($insumo_atual['foto_url'])): ?>
                        <img src="<?= htmlspecialchars($insumo_atual['foto_url']) ?>" alt="">
                    <?php else: ?>
                        📦
                    <?php endif; ?>
                </div>
                <div style="flex:1;">
                    <h2 style="margin:0 0 .5rem 0;"><?= htmlspecialchars($insumo_atual['nome_oficial']) ?></h2>
                    <form method="post" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">
                        <input type="hidden" name="insumo_id" value="<?= (int)$insumo_atual['id'] ?>">
                        <input type="hidden" name="posicao" value="<?= $posicao_atual ?>">
                        <div>
                            <label>Custo atual</label>
                            <input type="text" name="custo_novo" value="<?= $insumo_atual['custo_padrao'] !== null ? number_format((float)$insumo_atual['custo_padrao'], 2, ',', '.') : '' ?>" placeholder="0,00">
                        </div>
                        <button class="btn-secondary" type="submit" name="action" value="prev">Anterior</button>
                        <button class="btn-primary" type="submit" name="action" value="next">Salvar e Próximo</button>
                    </form>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card">Nenhum insumo ativo.</div>
    <?php endif; ?>
</div>

<?php endSidebar(); ?>
