<?php
// config_fichas.php
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



//
// Helpers de ficha técnica
//
function ficha_has_cycle(PDO $pdo, int $start_ficha_id, int $candidate_sub_id): bool {
    // Existe caminho candidate_sub_id -> ... -> start_ficha_id ?
    $visited = [];
    $stack = [$candidate_sub_id];
    while ($stack) {
        $fid = array_pop($stack);
        if (isset($visited[$fid])) continue;
        $visited[$fid] = true;
        if ($fid === $start_ficha_id) return true;
        $st = $pdo->prepare("SELECT sub_ficha_id FROM lc_ficha_componentes WHERE componente_tipo='sub_ficha' AND ficha_id=?");
        $st->execute([$fid]);
        $subs = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($subs as $s) {
            $sid = (int)$s;
            if ($sid > 0 && !isset($visited[$sid])) $stack[] = $sid;
        }
    }
    return false;
}

// Dicionários
$insumos = $pdo->query("SELECT id, nome FROM lc_insumos WHERE ativo=1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$itensComprados = $pdo->query("SELECT id, nome FROM lc_itens WHERE ativo=1 AND tipo='comprado' ORDER BY nome ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$fichasRef = $pdo->query("SELECT id, nome FROM lc_fichas WHERE ativo=1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_KEY_PAIR);

$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ''; $err = '';

try {
    //
    // POST: criar/atualizar ficha
    //
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action = $_POST['post_action'] ?? '';

        if ($post_action === 'create' || $post_action === 'update') {
            $nome = trim($_POST['nome'] ?? '');
            $r_qtd = isset($_POST['rendimento_qtd']) ? (float)$_POST['rendimento_qtd'] : 0;
            $r_un  = trim($_POST['rendimento_unid'] ?? '');
            $obs   = trim($_POST['observacoes'] ?? '');
            $ativo = isset($_POST['ativo']) ? 1 : 0;

            if ($nome === '') throw new Exception('Informe o nome da ficha.');
            if ($r_qtd <= 0) throw new Exception('Rendimento deve ser maior que 0.');
            if ($r_un === '') throw new Exception('Informe a unidade do rendimento.');

            if ($post_action === 'create') {
                $sql = "INSERT INTO lc_fichas (nome, rendimento_qtd, rendimento_unid, observacoes, ativo)
                        VALUES (:n,:q,:u,:o,:a)";
                $st = $pdo->prepare($sql);
                $st->execute([':n'=>$nome, ':q'=>$r_qtd, ':u'=>$r_un, ':o'=>$obs!==''?$obs:null, ':a'=>$ativo]);
                $fid = (int)$pdo->lastInsertId();
                header("Location: config_fichas.php?action=edit&id={$fid}&msg=".urlencode('Ficha criada. Agora adicione os componentes.'));
                exit;
            } else {
                $fid = (int)($_POST['id'] ?? 0);
                if ($fid <= 0) throw new Exception('ID inválido.');
                $sql = "UPDATE lc_fichas SET nome=:n, rendimento_qtd=:q, rendimento_unid=:u, observacoes=:o, ativo=:a WHERE id=:id";
                $st = $pdo->prepare($sql);
                $st->execute([':n'=>$nome, ':q'=>$r_qtd, ':u'=>$r_un, ':o'=>$obs!==''?$obs:null, ':a'=>$ativo, ':id'=>$fid]);
                header("Location: config_fichas.php?action=edit&id={$fid}&msg=".urlencode('Ficha atualizada.'));
                exit;
            }
        }

        //
        // POST: adicionar componente
        //
        if ($post_action === 'add_comp') {
            $fid = (int)($_POST['ficha_id'] ?? 0);
            if ($fid <= 0) throw new Exception('Ficha inválida.');

            $tipo = $_POST['componente_tipo'] ?? '';
            if (!in_array($tipo, ['insumo','item_comprado','sub_ficha'], true)) throw new Exception('Tipo de componente inválido.');

            $insumo_id = isset($_POST['insumo_id']) && $_POST['insumo_id'] !== '' ? (int)$_POST['insumo_id'] : null;
            $item_id   = isset($_POST['item_id'])   && $_POST['item_id']   !== '' ? (int)$_POST['item_id']   : null;
            $sub_id    = isset($_POST['sub_ficha_id']) && $_POST['sub_ficha_id'] !== '' ? (int)$_POST['sub_ficha_id'] : null;

            $qtd = isset($_POST['quantidade']) ? (float)$_POST['quantidade'] : 0;
            $un  = trim($_POST['unidade'] ?? '');
            $obs = trim($_POST['observacao'] ?? '');
            $ord = isset($_POST['ordem']) ? (int)$_POST['ordem'] : 0;

            if ($qtd <= 0) throw new Exception('Quantidade deve ser maior que 0.');
            if ($un === '') throw new Exception('Informe a unidade.');

            if ($tipo === 'insumo') {
                if (!$insumo_id) throw new Exception('Selecione o insumo.');
                $ok = $pdo->prepare("SELECT COUNT(*) FROM lc_insumos WHERE id=? AND ativo=1");
                $ok->execute([$insumo_id]);
                if ((int)$ok->fetchColumn() === 0) throw new Exception('Insumo inválido.');
            }

            if ($tipo === 'item_comprado') {
                if (!$item_id) throw new Exception('Selecione o item comprado.');
                $st = $pdo->prepare("SELECT tipo, fornecedor_id FROM lc_itens WHERE id=? AND ativo=1");
                $st->execute([$item_id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new Exception('Item inválido.');
                if ($row['tipo'] !== 'comprado') throw new Exception('O item selecionado não é do tipo comprado.');
                if (empty($row['fornecedor_id'])) throw new Exception('O item comprado precisa ter fornecedor padrão cadastrado.');
            }

            if ($tipo === 'sub_ficha') {
                if (!$sub_id) throw new Exception('Selecione a sub-ficha.');
                if ($sub_id === $fid) throw new Exception('Não é permitido referenciar a própria ficha.');
                // evita ciclo
                if (ficha_has_cycle($pdo, $fid, $sub_id)) {
                    throw new Exception('Ciclo detectado: a sub-ficha selecionada já referencia esta ficha.');
                }
                $ok = $pdo->prepare("SELECT COUNT(*) FROM lc_fichas WHERE id=? AND ativo=1");
                $ok->execute([$sub_id]);
                if ((int)$ok->fetchColumn() === 0) throw new Exception('Sub-ficha inválida.');
            }

            $sql = "INSERT INTO lc_ficha_componentes
                        (ficha_id, componente_tipo, insumo_id, item_id, sub_ficha_id, quantidade, unidade, observacao, ordem)
                    VALUES (:fid,:tipo,:iid,:itid,:sfid,:qtd,:un,:obs,:ord)";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':fid'=>$fid, ':tipo'=>$tipo,
                ':iid'=>$insumo_id, ':itid'=>$item_id, ':sfid'=>$sub_id,
                ':qtd'=>$qtd, ':un'=>$un, ':obs'=>$obs!==''?$obs:null, ':ord'=>$ord
            ]);
            header("Location: config_fichas.php?action=edit&id={$fid}&msg=".urlencode('Componente adicionado.'));
            exit;
        }

        //
        // POST: atualizar componente
        //
        if ($post_action === 'upd_comp') {
            $fid = (int)($_POST['ficha_id'] ?? 0);
            $cid = (int)($_POST['comp_id'] ?? 0);
            if ($fid<=0 || $cid<=0) throw new Exception('IDs inválidos.');

            $qtd = isset($_POST['quantidade']) ? (float)$_POST['quantidade'] : 0;
            $un  = trim($_POST['unidade'] ?? '');
            $obs = trim($_POST['observacao'] ?? '');
            $ord = isset($_POST['ordem']) ? (int)$_POST['ordem'] : 0;

            if ($qtd <= 0) throw new Exception('Quantidade deve ser maior que 0.');
            if ($un === '') throw new Exception('Informe a unidade.');

            $st = $pdo->prepare("UPDATE lc_ficha_componentes SET quantidade=:q, unidade=:u, observacao=:o, ordem=:ord WHERE id=:id AND ficha_id=:fid");
            $st->execute([':q'=>$qtd, ':u'=>$un, ':o'=>$obs!==''?$obs:null, ':ord'=>$ord, ':id'=>$cid, ':fid'=>$fid]);

            header("Location: config_fichas.php?action=edit&id={$fid}&msg=".urlencode('Componente atualizado.'));
            exit;
        }
    }

    //
    // GET actions simples
    //
    if ($action === 'toggle' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT ativo FROM lc_fichas WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['ativo'] ? 0 : 1;
        $pdo->prepare("UPDATE lc_fichas SET ativo=? WHERE id=?")->execute([$novo, $id]);
        $pdo->commit();
        $msg = 'Ficha '.($novo?'ativada':'desativada').'.';
        header("Location: config_fichas.php?msg=".urlencode($msg));
        exit;
    }

    if ($action === 'delete' && $id > 0) {
        // Impede exclusão se for usada como sub-ficha
        $stc = $pdo->prepare("SELECT COUNT(*) FROM lc_ficha_componentes WHERE sub_ficha_id=?");
        $stc->execute([$id]);
        if ((int)$stc->fetchColumn() > 0) {
            throw new Exception('Não é possível excluir: esta ficha é usada como sub-ficha em outra ficha.');
        }
        // Exclui componentes e a ficha
        $pdo->prepare("DELETE FROM lc_ficha_componentes WHERE ficha_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM lc_fichas WHERE id=?")->execute([$id]);
        $msg = 'Ficha excluída.';
        header("Location: config_fichas.php?msg=".urlencode($msg));
        exit;
    }

    if ($action === 'del_comp') {
        $fid = isset($_GET['fid']) ? (int)$_GET['fid'] : 0;
        $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;
        if ($fid<=0 || $cid<=0) throw new Exception('IDs inválidos.');
        $pdo->prepare("DELETE FROM lc_ficha_componentes WHERE id=? AND ficha_id=?")->execute([$cid, $fid]);
        header("Location: config_fichas.php?action=edit&id={$fid}&msg=".urlencode('Componente removido.'));
        exit;
    }

} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'uk_ficha_nome') || str_contains($e->getMessage(), 'Duplicate')) {
        $err = 'Já existe uma ficha com esse nome.';
    } else {
        $err = $e->getMessage();
    }
}

// Busca/listagem
$busca = trim($_GET['q'] ?? '');
$params = [];
$sqlList = "SELECT id, nome, rendimento_qtd, rendimento_unid, ativo, created_at, updated_at FROM lc_fichas";
if ($busca !== '') {
    $sqlList .= " WHERE nome LIKE :q";
    $params[':q'] = "%{$busca}%";
}
$sqlList .= " ORDER BY nome ASC";
$st = $pdo->prepare($sqlList);
$st->execute($params);
$fichas = $st->fetchAll(PDO::FETCH_ASSOC);

// Edição
$editRow = null;
$compRows = [];
if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM lc_fichas WHERE id=?");
    $st->execute([$id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) {
        $err = 'Registro não encontrado.';
        $action = '';
    } else {
        $stc = $pdo->prepare("SELECT * FROM lc_ficha_componentes WHERE ficha_id=? ORDER BY ordem ASC, id ASC");
        $stc->execute([$id]);
        $compRows = $stc->fetchAll(PDO::FETCH_ASSOC);
    }
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Configurações • Fichas técnicas</title>
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
.badge.mode{background:#e9efff;color:#004aad}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border-bottom:1px solid #eee;padding:10px;text-align:left;vertical-align:top}
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
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;}
</style>
<script>
function syncTipoComp(){
  const sel = document.getElementById('comp-tipo');
  if (!sel) return;
  const v = sel.value;
  const rInsumo = document.getElementById('comp-row-insumo');
  const rItem   = document.getElementById('comp-row-item');
  const rSub    = document.getElementById('comp-row-sub');
  if (rInsumo) rInsumo.style.display = (v==='insumo') ? 'block' : 'none';
  if (rItem)   rItem.style.display   = (v==='item_comprado') ? 'block' : 'none';
  if (rSub)    rSub.style.display    = (v==='sub_ficha') ? 'block' : 'none';
}
window.addEventListener('DOMContentLoaded', syncTipoComp);
</script>
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
        <a href="config_fichas.php" style="background:#003580;border-bottom:3px solid #fff;">📑 Fichas técnicas</a>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Configurações • Fichas técnicas</h1>

    <div class="topbar">
        <form class="inline" method="get" action="config_fichas.php">
            <input class="input-sm" type="text" name="q" placeholder="Buscar ficha..." value="<?=h($busca)?>">
            <button class="btn" type="submit">Buscar</button>
            <a class="btn link" href="config_fichas.php">Limpar</a>
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
            <legend><?= $editRow ? 'Editar ficha' : 'Nova ficha' ?></legend>
            <form method="post" action="config_fichas.php<?= $editRow ? '?action=edit&id='.$editRow['id'] : '' ?>">
                <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>
                <input type="hidden" name="post_action" value="<?= $editRow ? 'update' : 'create' ?>">

                <div class="grid">
                    <div>
                        <label>Nome *</label>
                        <input type="text" name="nome" value="<?= h($editRow['nome'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Rendimento *</label>
                        <div class="row">
                            <input type="number" step="0.0001" min="0" name="rendimento_qtd" style="max-width:160px" value="<?= h($editRow['rendimento_qtd'] ?? '') ?>" required>
                            <input type="text" name="rendimento_unid" style="max-width:220px" placeholder="ex.: un, kg, L, lote" value="<?= h($editRow['rendimento_unid'] ?? '') ?>" required>
                        </div>
                        <div class="small">Ex.: 50 un • 2 kg • 1 lote</div>
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
                    <button class="btn" type="submit"><?= $editRow ? 'Salvar alterações' : 'Criar ficha' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn link" href="config_fichas.php">Nova ficha</a>
                    <?php endif; ?>
                </div>
            </form>
        </fieldset>
    </div>

    <?php if ($editRow): ?>
    <div class="card">
        <fieldset>
            <legend>Componentes da ficha “<?= h($editRow['nome']) ?>”</legend>

            <h3 style="margin-top:0">Adicionar componente</h3>
            <form method="post" action="config_fichas.php?action=edit&id=<?= (int)$editRow['id'] ?>">
                <input type="hidden" name="post_action" value="add_comp">
                <input type="hidden" name="ficha_id" value="<?= (int)$editRow['id'] ?>">

                <div class="row">
                    <div>
                        <label>Tipo *</label>
                        <select name="componente_tipo" id="comp-tipo" onchange="syncTipoComp()">
                            <option value="insumo">Insumo</option>
                            <option value="item_comprado">Item comprado</option>
                            <option value="sub_ficha">Sub-ficha</option>
                        </select>
                    </div>

                    <div id="comp-row-insumo">
                        <label>Insumo *</label>
                        <select name="insumo_id">
                            <option value="">Selecione</option>
                            <?php foreach ($insumos as $iid=>$nm): ?>
                                <option value="<?= (int)$iid ?>"><?= h($nm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="comp-row-item" style="display:none">
                        <label>Item comprado *</label>
                        <select name="item_id">
                            <option value="">Selecione</option>
                            <?php foreach ($itensComprados as $iid=>$nm): ?>
                                <option value="<?= (int)$iid ?>"><?= h($nm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="comp-row-sub" style="display:none">
                        <label>Sub-ficha *</label>
                        <select name="sub_ficha_id">
                            <option value="">Selecione</option>
                            <?php foreach ($fichasRef as $fid=>$nm): ?>
                                <?php if ((int)$editRow['id'] === (int)$fid) continue; ?>
                                <option value="<?= (int)$fid ?>"><?= h($nm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Quantidade *</label>
                        <input type="number" step="0.0001" min="0" name="quantidade" required style="max-width:160px">
                    </div>
                    <div>
                        <label>Unidade *</label>
                        <input type="text" name="unidade" placeholder="ex.: kg, g, L, un, lote" required style="max-width:180px">
                    </div>
                    <div>
                        <label>Ordem</label>
                        <input type="number" name="ordem" min="0" value="0" style="max-width:120px">
                    </div>
                </div>

                <div style="margin-top:10px">
                    <label>Observação</label>
                    <input type="text" name="observacao" style="width:100%;" placeholder="opcional">
                </div>

                <div style="margin-top:12px">
                    <button class="btn" type="submit">Adicionar componente</button>
                </div>
            </form>

            <h3>Componentes cadastrados</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:70px">ID</th>
                        <th style=
