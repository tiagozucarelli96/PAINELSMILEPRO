<?php
// config_insumos.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão: apenas admin/gestão (ajuste se houver flag específica)
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1 || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }
require_once __DIR__ . '/sidebar_integration.php';

// Iniciar sidebar
includeSidebar();
setPageTitle('Configuração de Insumos');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg    = '';
$err    = '';

// Handlers
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action     = $_POST['post_action'] ?? '';
        if ($post_action === 'create' || $post_action === 'update') {
            $nome            = trim($_POST['nome'] ?? '');
            $unidade_compra  = trim($_POST['unidade_compra'] ?? '');
            $conversoes_raw  = trim($_POST['conversoes'] ?? '');
            $sku             = trim($_POST['sku'] ?? '');
            $custo_raw       = trim($_POST['custo_unitario'] ?? '');
            $ativo           = isset($_POST['ativo']) ? 1 : 0;

            if ($nome === '') throw new Exception('Informe o nome do insumo.');
            if ($unidade_compra === '') throw new Exception('Informe a unidade de compra.');

            // Valida JSON de conversões (opcional)
            $conversoes = null;
            if ($conversoes_raw !== '') {
                $tmp = json_decode($conversoes_raw, true);
                if (!is_array($tmp)) throw new Exception('Conversões inválidas. Use JSON válido.');
                $conversoes = json_encode($tmp, JSON_UNESCAPED_UNICODE);
            }

            // Normaliza custo (ponto decimal)
            $custo_unitario = null;
            if ($custo_raw !== '') {
                $custo_raw = str_replace(['.', ','], ['.', '.'], $custo_raw); // se vier vírgula, troca por ponto
                if (!is_numeric($custo_raw)) throw new Exception('Custo inválido.');
                $custo_unitario = (float)$custo_raw;
            }

            if ($post_action === 'create') {
                $sql = "INSERT INTO lc_insumos (nome, unidade_compra, conversoes, sku, custo_unitario, ativo)
                        VALUES (:nome, :unidade, :conv, :sku, :custo, :ativo)";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':nome'=>$nome, ':unidade'=>$unidade_compra, ':conv'=>$conversoes,
                    ':sku'=>$sku !== '' ? $sku : null,
                    ':custo'=>$custo_unitario,
                    ':ativo'=>$ativo
                ]);
                $msg = 'Insumo criado.';
            } else {
                $idUpd = (int)($_POST['id'] ?? 0);
                if ($idUpd <= 0) throw new Exception('ID inválido.');
                $sql = "UPDATE lc_insumos
                           SET nome=:nome, unidade_compra=:unidade, conversoes=:conv, sku=:sku,
                               custo_unitario=:custo, ativo=:ativo
                         WHERE id=:id";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':nome'=>$nome, ':unidade'=>$unidade_compra, ':conv'=>$conversoes,
                    ':sku'=>$sku !== '' ? $sku : null,
                    ':custo'=>$custo_unitario,
                    ':ativo'=>$ativo, ':id'=>$idUpd
                ]);
                $msg = 'Insumo atualizado.';
            }
            header("Location: config_insumos.php?msg=".urlencode($msg));
            exit;
        }
    }

    if ($action === 'toggle' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT ativo FROM lc_insumos WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['ativo'] ? 0 : 1;
        $st = $pdo->prepare("UPDATE lc_insumos SET ativo=? WHERE id=?");
        $st->execute([$novo, $id]);
        $pdo->commit();
        $msg = 'Insumo '.($novo? 'ativado' : 'desativado').'.';
        header("Location: config_insumos.php?msg=".urlencode($msg)); exit;
    }

    if ($action === 'delete' && $id > 0) {
        // Impede exclusão se usado
        // 1) em fichas técnicas
        $stc = $pdo->prepare("SELECT COUNT(*) FROM lc_ficha_componentes WHERE insumo_id=?");
        $stc->execute([$id]);
        $usos_ficha = (int)$stc->fetchColumn();

        // 2) em arredondamentos
        $sta = $pdo->prepare("SELECT COUNT(*) FROM lc_arredondamentos WHERE insumo_id=?");
        $sta->execute([$id]);
        $usos_arr = (int)$sta->fetchColumn();

        // 3) em itens fixos
        $stf = $pdo->prepare("SELECT COUNT(*) FROM lc_itens_fixos WHERE insumo_id=?");
        $stf->execute([$id]);
        $usos_fix = (int)$stf->fetchColumn();

        if (($usos_ficha + $usos_arr + $usos_fix) > 0) {
            throw new Exception('Não é possível excluir: insumo vinculado a ficha/arredondamento/itens fixos.');
        }

        $st = $pdo->prepare("DELETE FROM lc_insumos WHERE id=?");
        $st->execute([$id]);
        $msg = 'Insumo excluído.';
        header("Location: config_insumos.php?msg=".urlencode($msg)); exit;
    }

} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'uk_insumo_nome') || str_contains($e->getMessage(), 'Duplicate')) {
        $err = 'Já existe um insumo com esse nome.';
    } else {
        $err = $e->getMessage();
    }
}

// Filtros e busca
$busca = trim($_GET['q'] ?? '');
$fativo = isset($_GET['fativo']) ? $_GET['fativo'] : '';
$params = [];
$sqlList = "SELECT id, nome, unidade_compra, conversoes, sku, custo_unitario, ativo, created_at, updated_at
            FROM lc_insumos WHERE 1=1";
if ($busca !== '') {
    $sqlList .= " AND (nome LIKE :q OR sku LIKE :q)";
    $params[':q'] = "%{$busca}%";
}
if ($fativo === '1') {
    $sqlList .= " AND ativo=1";
} elseif ($fativo === '0') {
    $sqlList .= " AND ativo=0";
}
$sqlList .= " ORDER BY nome ASC";
$st = $pdo->prepare($sqlList);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Edição
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM lc_insumos WHERE id=?");
    $st->execute([$id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $err = 'Registro não encontrado.'; $action = ''; }
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Configurações • Insumos</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
.content-narrow{ max-width:1100px; margin:0 auto; }
.topbar{display:flex; gap:10px; align-items:center; margin-bottom:16px; flex-wrap:wrap}
.topbar .grow{flex:1}
.input-sm{padding:9px;border:1px solid #ccc;border-radius:8px;font-size:14px;width:100%;max-width:340px}
.btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer}
.btn.link{background:#e9efff;color:#004aad}
.btn.danger{background:#b00020}
.badge{font-size:12px;padding:4px 8px;border-radius:999px;background:#eee}
.badge.on{background:#d3f4d1;color:#1b5e20}
.badge.off{background:#ffe1e1;color:#7f0000}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border-bottom:1px solid #eee;padding:10px;text-align:left}
.card{margin-bottom:18px}
h1{margin-top:0}
.actions a{margin-right:10px;text-decoration:none}
.note{font-size:13px;color:#555}
form.inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
fieldset{border:1px solid #e6eefc;padding:14px;border-radius:10px}
legend{padding:0 8px;color:#004aad;font-weight:700}
.grid{display:grid;grid-template-columns:1fr 1fr; gap:10px}
@media(max-width:900px){ .grid{grid-template-columns:1fr} }
.small{font-size:12px;color:#666}
.mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;}
</style>
</head>
<body>
<div class="sidebar">
    <img src="logo-smile.png" alt="Logo" />
    <nav>
        <a href="index.php?page=dashboard">🏠 Painel</a>
        <a href="lista_compras.php">🛒 Lista de Compras</a>
        <a href="config_categorias.php">⚙️ Categorias</a>
        <a href="config_fornecedores.php">🤝 Fornecedores</a>
        <a href="config_itens.php">🍽️ Itens do cardápio</a>
        <a href="config_insumos.php" style="background:#003580;border-bottom:3px solid #fff;">📦 Insumos</a>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Configurações • Insumos</h1>

    <div class="topbar">
        <form class="inline" method="get" action="config_insumos.php">
            <input class="input-sm" type="text" name="q" placeholder="Buscar por nome ou SKU..." value="<?=h($busca)?>">
            <select class="input-sm" name="fativo">
                <option value="">Todos</option>
                <option value="1" <?= $fativo==='1'?'selected':'' ?>>Ativos</option>
                <option value="0" <?= $fativo==='0'?'selected':'' ?>>Inativos</option>
            </select>
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn link" href="config_insumos.php">Limpar</a>
        </form>
        <div class="grow"></div>
        <a class="btn link" href="lista_compras.php">Voltar ao módulo</a>
    </div>

    <?php if ($err): ?>
        <div class="card" style="border-left:4px solid #b00020"><p><?=h($err)?></p></div>
    <?php elseif (isset($_GET['msg'])): ?>
        <div class="card" style="border-left:4px solid #2e7d32"><p><?=h($_GET['msg'])?></p></div>
    <?php endif; ?>

    <div class="card">
        <fieldset>
            <legend><?= $editRow ? 'Editar insumo' : 'Novo insumo' ?></legend>
            <form method="post" action="config_insumos.php<?= $editRow ? '?action=edit&id='.$editRow['id'] : '' ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="post_action" value="<?= $editRow ? 'update' : 'create' ?>">

                <div class="grid">
                    <div>
                        <label>Nome *</label>
                        <input type="text" name="nome" value="<?= h($editRow['nome'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Unidade de compra *</label>
                        <input type="text" name="unidade_compra" placeholder="kg, g, L, un, pacote..." value="<?= h($editRow['unidade_compra'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>SKU</label>
                        <input type="text" name="sku" value="<?= h($editRow['sku'] ?? '') ?>">
                    </div>
                    <div>
                        <label>Custo unitário</label>
                        <input type="text" name="custo_unitario" placeholder="ex.: 12.50" value="<?= h($editRow['custo_unitario'] ?? '') ?>">
                        <div class="small">Use ponto como separador decimal.</div>
                    </div>
                    <div>
                        <label>Ativo</label><br>
                        <input type="checkbox" name="ativo" <?= !isset($editRow['ativo']) || (int)($editRow['ativo'])===1 ? 'checked' : '' ?>> habilitar
                    </div>
                </div>

                <div style="margin-top:10px">
                    <label>Conversões (JSON)</label>
                    <textarea class="mono" name="conversoes" rows="4" style="width:100%;" placeholder='{"kg":{"g":1000},"L":{"ml":1000}}'><?= h($editRow['conversoes'] ?? '') ?></textarea>
                    <div class="small">Opcional. Ex.: {"kg":{"g":1000}} significa 1 kg = 1000 g.</div>
                </div>

                <div style="margin-top:12px;display:flex;gap:8px">
                    <button class="btn" type="submit"><?= $editRow ? 'Salvar alterações' : 'Adicionar' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn link" href="config_insumos.php">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </fieldset>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Insumos</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Nome</th>
                    <th style="width:90px">Unid</th>
                    <th>SKU</th>
                    <th style="width:120px">Custo</th>
                    <th style="width:100px">Ativo</th>
                    <th style="width:220px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7">Nenhum insumo cadastrado.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td title="Conversões: <?= h($r['conversoes'] ?? '') ?>"><?= h($r['nome']) ?></td>
                    <td><?= h($r['unidade_compra']) ?></td>
                    <td><?= h($r['sku'] ?? '') ?></td>
                    <td><?= $r['custo_unitario'] !== null ? number_format((float)$r['custo_unitario'], 2, ',', '.') : '' ?></td>
                    <td>
                        <?php if ($r['ativo']): ?>
                            <span class="badge on">Ativo</span>
                        <?php else: ?>
                            <span class="badge off">Inativo</span>
                        <?php endif; ?>
                        <a class="actions" href="config_insumos.php?action=toggle&id=<?= (int)$r['id'] ?>">alternar</a>
                    </td>
                    <td class="actions">
                        <a href="config_insumos.php?action=edit&id=<?= (int)$r['id'] ?>">Editar</a>
                        <a href="config_insumos.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir este insumo?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>

<?php endSidebar(); ?>
