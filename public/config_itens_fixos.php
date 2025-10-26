<?php
// config_itens_fixos.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão: apenas admin/gestão
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1 || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }



$APLICA_OPTS = [
  'todos'    => 'Todos',
  'garden'   => 'Garden',
  'cristal'  => 'Cristal',
  'lisbon'   => 'Lisbon',
  'diverkids'=> 'Diverkids',
];

$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ''; $err = '';

// Dicionário de insumos
$insumos = $pdo->query("SELECT id, nome FROM lc_insumos WHERE ativo=1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    // CREATE / UPDATE
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action = $_POST['post_action'] ?? '';
        if ($post_action === 'create' || $post_action === 'update') {
            $insumo_id  = isset($_POST['insumo_id']) ? (int)$_POST['insumo_id'] : 0;
            $quantidade = isset($_POST['quantidade']) ? (float)$_POST['quantidade'] : 0;
            $unidade    = trim($_POST['unidade'] ?? '');
            $aplica_em  = $_POST['aplica_em'] ?? 'todos';
            $ordem      = (int)($_POST['ordem'] ?? 0);
            $ativo      = isset($_POST['ativo']) ? 1 : 0;
            $obs        = trim($_POST['observacoes'] ?? '');

            if ($insumo_id <= 0) throw new Exception('Selecione o insumo.');
            if ($quantidade <= 0) throw new Exception('Informe quantidade > 0.');
            if ($unidade === '') throw new Exception('Informe a unidade.');
            if (!array_key_exists($aplica_em, $APLICA_OPTS)) throw new Exception('Campo "Aplica em" inválido.');

            if ($post_action === 'create') {
                $sql = "INSERT INTO lc_itens_fixos (insumo_id, quantidade, unidade, aplica_em, ordem, ativo, observacoes)
                        VALUES (:i,:q,:u,:a,:o,:t,:obs)";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':i'=>$insumo_id, ':q'=>$quantidade, ':u'=>$unidade, ':a'=>$aplica_em,
                    ':o'=>$ordem, ':t'=>$ativo, ':obs'=>$obs!==''?$obs:null
                ]);
                $msg = 'Item fixo criado.';
            } else {
                $idUpd = (int)($_POST['id'] ?? 0);
                if ($idUpd <= 0) throw new Exception('ID inválido.');
                $sql = "UPDATE lc_itens_fixos
                           SET insumo_id=:i, quantidade=:q, unidade=:u, aplica_em=:a, ordem=:o, ativo=:t, observacoes=:obs
                         WHERE id=:id";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':i'=>$insumo_id, ':q'=>$quantidade, ':u'=>$unidade, ':a'=>$aplica_em,
                    ':o'=>$ordem, ':t'=>$ativo, ':obs'=>$obs!==''?$obs:null, ':id'=>$idUpd
                ]);
                $msg = 'Item fixo atualizado.';
            }
            header("Location: config_itens_fixos.php?msg=".urlencode($msg)); exit;
        }
    }

    // TOGGLE
    if ($action === 'toggle' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT ativo FROM lc_itens_fixos WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['ativo'] ? 0 : 1;
        $pdo->prepare("UPDATE lc_itens_fixos SET ativo=? WHERE id=?")->execute([$novo, $id]);
        $pdo->commit();
        header("Location: config_itens_fixos.php?msg=".urlencode('Item '.($novo?'ativado':'desativado').'.')); exit;
    }

    // DELETE
    if ($action === 'delete' && $id > 0) {
        $pdo->prepare("DELETE FROM lc_itens_fixos WHERE id=?")->execute([$id]);
        header("Location: config_itens_fixos.php?msg=".urlencode('Item fixo excluído.')); exit;
    }

} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Filtros
$busca = trim($_GET['q'] ?? '');
$fap   = $_GET['faplica'] ?? '';
$params = [];
$sqlList = "SELECT fx.id, fx.insumo_id, fx.quantidade, fx.unidade, fx.aplica_em, fx.ordem, fx.ativo, fx.observacoes,
                   i.nome AS insumo_nome
            FROM lc_itens_fixos fx
            JOIN lc_insumos i ON i.id = fx.insumo_id
            WHERE 1=1";

if ($busca !== '') {
    $sqlList .= " AND (i.nome LIKE :q OR fx.unidade LIKE :q)";
    $params[':q'] = "%{$busca}%";
}
if ($fap !== '' && array_key_exists($fap, $APLICA_OPTS)) {
    $sqlList .= " AND fx.aplica_em = :ap";
    $params[':ap'] = $fap;
}

$sqlList .= " ORDER BY fx.aplica_em ASC, fx.ordem ASC, i.nome ASC";
$st = $pdo->prepare($sqlList);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Edição
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM lc_itens_fixos WHERE id=?");
    $st->execute([$id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $err = 'Registro não encontrado.'; $action = ''; }
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Configurações • Itens fixos</title>
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
.table th,.table td{border-bottom:1px solid #eee;padding:10px;text-align:left;vertical-align:top}
.card{margin-bottom:18px}
h1{margin-top:0}
.actions a{margin-right:10px;text-decoration:none}
fieldset{border:1px solid #e6eefc;padding:14px;border-radius:10px}
legend{padding:0 8px;color:#004aad;font-weight:700}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr; gap:10px}
@media(max-width:900px){ .grid{grid-template-columns:1fr} }
.small{font-size:12px;color:#666}
.select-wide{min-width:260px}
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
        <a href="config_itens.php">🍽️ Itens</a>
        <a href="config_insumos.php">📦 Insumos</a>
        <a href="config_fichas.php">📑 Fichas</a>
        <a href="config_arredondamentos.php">📏 Arredondamentos</a>
        <a href="config_itens_fixos.php" style="background:#003580;border-bottom:3px solid #fff;">📌 Itens fixos</a>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Configurações • Itens fixos</h1>

    <div class="topbar">
        <form class="inline" method="get" action="config_itens_fixos.php">
            <input class="input-sm" type="text" name="q" placeholder="Buscar por insumo..." value="<?=h($busca)?>">
            <select class="input-sm select-wide" name="faplica">
                <option value="">Todos os espaços</option>
                <?php foreach ($APLICA_OPTS as $k=>$v): ?>
                    <option value="<?= h($k) ?>" <?= $fap===$k?'selected':'' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn link" href="config_itens_fixos.php">Limpar</a>
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
            <legend><?= $editRow ? 'Editar item fixo' : 'Novo item fixo' ?></legend>
            <form method="post" action="config_itens_fixos.php<?= $editRow ? '?action=edit&id='.$editRow['id'] : '' ?>">
                <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
                <input type="hidden" name="post_action" value="<?= $editRow ? 'update' : 'create' ?>">

                <div class="grid">
                    <div>
                        <label>Insumo *</label>
                        <select name="insumo_id" required>
                            <option value="">Selecione</option>
                            <?php
                              $selI = isset($editRow['insumo_id']) ? (int)$editRow['insumo_id'] : 0;
                              foreach ($insumos as $iid=>$nm) {
                                  $sel = $selI===$iid ? 'selected' : '';
                                  echo "<option value=\"".(int)$iid."\" $sel>".h($nm)."</option>";
                              }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Quantidade por evento *</label>
                        <input type="number" step="0.0001" min="0" name="quantidade" value="<?= h($editRow['quantidade'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Unidade *</label>
                        <input type="text" name="unidade" placeholder="kg, un, L..." value="<?= h($editRow['unidade'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Aplica em *</label>
                        <select name="aplica_em" required>
                            <?php
                              $selA = $editRow['aplica_em'] ?? 'todos';
                              foreach ($APLICA_OPTS as $k=>$v) {
                                  $sel = ($selA === $k) ? 'selected' : '';
                                  echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
                              }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Ordem</label>
                        <input type="number" name="ordem" min="0" value="<?= h($editRow['ordem'] ?? 0) ?>">
                    </div>
                    <div>
                        <label>Ativo</label><br>
                        <input type="checkbox" name="ativo" <?= !isset($editRow['ativo']) || (int)($editRow['ativo'])===1 ? 'checked' : '' ?>> habilitar
                    </div>
                </div>

                <div style="margin-top:10px">
                    <label>Observações internas</label>
                    <textarea name="observacoes" rows="3" style="width:100%;"><?= h($editRow['observacoes'] ?? '') ?></textarea>
                </div>

                <div style="margin-top:12px;display:flex;gap:8px">
                    <button class="btn" type="submit"><?= $editRow ? 'Salvar alterações' : 'Adicionar' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn link" href="config_itens_fixos.php">Cancelar</a>
                    <?php endif; ?>
                </div>
                <p class="small" style="margin-top:10px">
                    Itens fixos entram 1x por evento na <b>Lista de Compras</b>. Podem ser desmarcados ou ajustados na geração.
                </p>
            </form>
        </fieldset>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Itens fixos</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Insumo</th>
                    <th style="width:160px">Quantidade</th>
                    <th style="width:100px">Unid</th>
                    <th style="width:140px">Aplica em</th>
                    <th style="width:80px">Ordem</th>
                    <th style="width:110px">Ativo</th>
                    <th style="width:220px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8">Nenhum item fixo cadastrado.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td title="<?= h($r['observacoes'] ?? '') ?>"><?= h($r['insumo_nome']) ?></td>
                    <td><?= h($r['quantidade']) ?></td>
                    <td><?= h($r['unidade']) ?></td>
                    <td><?= h($APLICA_OPTS[$r['aplica_em']] ?? $r['aplica_em']) ?></td>
                    <td><?= (int)$r['ordem'] ?></td>
                    <td>
                        <?php if ($r['ativo']): ?>
                            <span class="badge on">Ativo</span>
                        <?php else: ?>
                            <span class="badge off">Inativo</span>
                        <?php endif; ?>
                        <a class="actions" href="config_itens_fixos.php?action=toggle&id=<?= (int)$r['id'] ?>">alternar</a>
                    </td>
                    <td class="actions">
                        <a href="config_itens_fixos.php?action=edit&id=<?= (int)$r['id'] ?>">Editar</a>
                        <a href="config_itens_fixos.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir este item fixo?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

</div>
</div>
</body>
</html>
