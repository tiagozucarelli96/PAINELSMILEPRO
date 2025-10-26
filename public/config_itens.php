<?php
// config_itens.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão: apenas admin/gestão (ajuste se houver flag específica para configurações)
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1 || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }



$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg    = '';
$err    = '';

// Carrega dicionários
$cats = $pdo->query("SELECT id, nome FROM lc_categorias WHERE ativo=1 ORDER BY ordem ASC, nome ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$forn = $pdo->query("SELECT id, nome FROM lc_fornecedores WHERE ativo=1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action      = $_POST['post_action'] ?? '';
        $nome             = trim($_POST['nome'] ?? '');
        $categoria_id     = (int)($_POST['categoria_id'] ?? 0);
        $tipo             = ($_POST['tipo'] ?? '') === 'comprado' ? 'comprado' : 'preparo';
        $unidade_compra   = trim($_POST['unidade_compra'] ?? '');
        $regra            = ($_POST['regra_consumo'] ?? '') === 'por_lote' ? 'por_lote' : 'por_pessoa';
        $fator_por_pessoa = isset($_POST['fator_por_pessoa']) && $_POST['fator_por_pessoa'] !== '' ? (float)$_POST['fator_por_pessoa'] : null;
        $qtd_por_lote     = isset($_POST['qtd_por_lote']) && $_POST['qtd_por_lote'] !== '' ? (float)$_POST['qtd_por_lote'] : null;
        $pessoas_por_lote = isset($_POST['pessoas_por_lote']) && $_POST['pessoas_por_lote'] !== '' ? (int)$_POST['pessoas_por_lote'] : null;
        $fornecedor_id    = isset($_POST['fornecedor_id']) && $_POST['fornecedor_id'] !== '' ? (int)$_POST['fornecedor_id'] : null;
        $ordem            = (int)($_POST['ordem'] ?? 0);
        $ativo            = isset($_POST['ativo']) ? 1 : 0;
        $observacoes      = trim($_POST['observacoes'] ?? '');

        // Validações
        if ($nome === '') throw new Exception('Informe o nome do item.');
        if ($categoria_id <= 0) throw new Exception('Selecione a categoria.');
        if ($unidade_compra === '') throw new Exception('Informe a unidade de compra.');
        if ($regra === 'por_pessoa') {
            if ($fator_por_pessoa === null || $fator_por_pessoa <= 0) throw new Exception('Informe o fator por pessoa (> 0).');
            // zera os outros
            $qtd_por_lote = null; $pessoas_por_lote = null;
        } else {
            if ($qtd_por_lote === null || $qtd_por_lote <= 0 || $pessoas_por_lote === null || $pessoas_por_lote <= 0) {
                throw new Exception('Informe quantidade por lote e pessoas por lote (> 0).');
            }
            // zera fator por pessoa
            $fator_por_pessoa = null;
        }
        if ($tipo === 'comprado' && !$fornecedor_id) throw new Exception('Selecione o fornecedor padrão para item comprado.');

        if ($post_action === 'create') {
            $sql = "INSERT INTO lc_itens
                       (nome, categoria_id, tipo, unidade_compra, regra_consumo, fator_por_pessoa, qtd_por_lote, pessoas_por_lote,
                        fornecedor_id, ordem, ativo, observacoes)
                    VALUES
                       (:nome, :categoria_id, :tipo, :unidade_compra, :regra_consumo, :fator_por_pessoa, :qtd_por_lote, :pessoas_por_lote,
                        :fornecedor_id, :ordem, :ativo, :observacoes)";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':nome'=>$nome, ':categoria_id'=>$categoria_id, ':tipo'=>$tipo, ':unidade_compra'=>$unidade_compra,
                ':regra_consumo'=>$regra, ':fator_por_pessoa'=>$fator_por_pessoa, ':qtd_por_lote'=>$qtd_por_lote,
                ':pessoas_por_lote'=>$pessoas_por_lote, ':fornecedor_id'=>$fornecedor_id, ':ordem'=>$ordem,
                ':ativo'=>$ativo, ':observacoes'=>$observacoes
            ]);
            $msg = 'Item criado.';
            header("Location: config_itens.php?msg=".urlencode($msg)); exit;
        }

        if ($post_action === 'update') {
            $idUpd = (int)($_POST['id'] ?? 0);
            if ($idUpd <= 0) throw new Exception('ID inválido.');

            $sql = "UPDATE lc_itens
                       SET nome=:nome, categoria_id=:categoria_id, tipo=:tipo, unidade_compra=:unidade_compra,
                           regra_consumo=:regra_consumo, fator_por_pessoa=:fator_por_pessoa, qtd_por_lote=:qtd_por_lote,
                           pessoas_por_lote=:pessoas_por_lote, fornecedor_id=:fornecedor_id, ordem=:ordem, ativo=:ativo,
                           observacoes=:observacoes
                     WHERE id=:id";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':nome'=>$nome, ':categoria_id'=>$categoria_id, ':tipo'=>$tipo, ':unidade_compra'=>$unidade_compra,
                ':regra_consumo'=>$regra, ':fator_por_pessoa'=>$fator_por_pessoa, ':qtd_por_lote'=>$qtd_por_lote,
                ':pessoas_por_lote'=>$pessoas_por_lote, ':fornecedor_id'=>$fornecedor_id, ':ordem'=>$ordem,
                ':ativo'=>$ativo, ':observacoes'=>$observacoes, ':id'=>$idUpd
            ]);
            $msg = 'Item atualizado.';
            header("Location: config_itens.php?msg=".urlencode($msg)); exit;
        }
    }

    if ($action === 'toggle' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT ativo FROM lc_itens WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['ativo'] ? 0 : 1;
        $st = $pdo->prepare("UPDATE lc_itens SET ativo=? WHERE id=?");
        $st->execute([$novo, $id]);
        $pdo->commit();
        $msg = 'Item '.($novo? 'ativado' : 'desativado').'.';
        header("Location: config_itens.php?msg=".urlencode($msg)); exit;
    }

    if ($action === 'delete' && $id > 0) {
        // Bloqueia exclusão se usado em ficha técnica
        $stc = $pdo->prepare("SELECT COUNT(*) FROM lc_ficha_componentes WHERE item_id=?");
        $stc->execute([$id]);
        $usos = (int)$stc->fetchColumn();
        if ($usos > 0) throw new Exception('Não é possível excluir: item usado em ficha técnica.');
        $st = $pdo->prepare("DELETE FROM lc_itens WHERE id=?");
        $st->execute([$id]);
        $msg = 'Item excluído.';
        header("Location: config_itens.php?msg=".urlencode($msg)); exit;
    }

} catch (Throwable $e) {
    // Trate violação de UNIQUE (nome + categoria)
    if (str_contains($e->getMessage(), 'uk_item_nome_cat') || str_contains($e->getMessage(), 'Duplicate')) {
        $err = 'Já existe um item com esse nome nesta categoria.';
    } else {
        $err = $e->getMessage();
    }
}

// Filtros e busca
$busca = trim($_GET['q'] ?? '');
$fcat  = isset($_GET['fcategoria']) ? (int)$_GET['fcategoria'] : 0;
$ftipo = ($_GET['ftipo'] ?? '');
$ftipo = in_array($ftipo, ['preparo','comprado'], true) ? $ftipo : '';

$params = [];
$sqlList = "SELECT i.id, i.nome, i.tipo, i.unidade_compra, i.regra_consumo, i.fator_por_pessoa,
                   i.qtd_por_lote, i.pessoas_por_lote, i.fornecedor_id, i.ordem, i.ativo, i.observacoes,
                   c.nome AS categoria_nome, f.nome AS fornecedor_nome
            FROM lc_itens i
            JOIN lc_categorias c ON c.id = i.categoria_id
            LEFT JOIN lc_fornecedores f ON f.id = i.fornecedor_id
            WHERE 1=1";
if ($busca !== '') {
    $sqlList .= " AND (i.nome LIKE :q OR c.nome LIKE :q)";
    $params[':q'] = "%{$busca}%";
}
if ($fcat > 0) {
    $sqlList .= " AND i.categoria_id = :fc";
    $params[':fc'] = $fcat;
}
if ($ftipo !== '') {
    $sqlList .= " AND i.tipo = :ft";
    $params[':ft'] = $ftipo;
}
$sqlList .= " ORDER BY c.ordem ASC, c.nome ASC, i.ordem ASC, i.nome ASC";
$st = $pdo->prepare($sqlList);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Edição
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM lc_itens WHERE id=?");
    $st->execute([$id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $err = 'Registro não encontrado.'; $action = ''; }
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Configurações • Itens do cardápio</title>
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
.table th,.table td{border-bottom:1px solid #eee;padding:10px;text-align:left}
.card{margin-bottom:18px}
h1{margin-top:0}
.actions a{margin-right:10px;text-decoration:none}
.note{font-size:13px;color:#555}
form.inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
fieldset{border:1px solid #e6eefc;padding:14px;border-radius:10px}
legend{padding:0 8px;color:#004aad;font-weight:700}
.grid{display:grid;grid-template-columns:1fr 1fr; gap:10px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr; gap:10px}
@media(max-width:1100px){ .grid,.grid-3{grid-template-columns:1fr} }
.small{font-size:12px;color:#666}
</style>
<script>
function syncTipo(){
  const tipo = document.querySelector('select[name="tipo"]').value;
  const fornRow = document.getElementById('row-fornecedor');
  if (fornRow) fornRow.style.display = (tipo === 'comprado') ? 'block' : 'none';
}
function syncRegra(){
  const regra = document.querySelector('select[name="regra_consumo"]').value;
  const rPessoa = document.getElementById('row-por-pessoa');
  const rLote   = document.getElementById('row-por-lote');
  if (rPessoa) rPessoa.style.display = (regra === 'por_pessoa') ? 'block' : 'none';
  if (rLote)   rLote.style.display   = (regra === 'por_lote')   ? 'block' : 'none';
}
window.addEventListener('DOMContentLoaded', () => { syncTipo(); syncRegra(); });
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
        <a href="config_itens.php" style="background:#003580;border-bottom:3px solid #fff;">🍽️ Itens do cardápio</a>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Configurações • Itens do cardápio</h1>

    <div class="topbar">
        <form class="inline" method="get" action="config_itens.php">
            <input class="input-sm" type="text" name="q" placeholder="Buscar por item ou categoria..." value="<?=h($busca)?>">
            <select class="input-sm" name="fcategoria">
                <option value="0">Todas as categorias</option>
                <?php foreach ($cats as $cid=>$cn): ?>
                    <option value="<?= (int)$cid ?>" <?= $fcat===$cid?'selected':'' ?>><?= h($cn) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="input-sm" name="ftipo">
                <option value="">Todos os tipos</option>
                <option value="preparo"   <?= $ftipo==='preparo'?'selected':'' ?>>Preparo</option>
                <option value="comprado"  <?= $ftipo==='comprado'?'selected':'' ?>>Comprado</option>
            </select>
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn link" href="config_itens.php">Limpar</a>
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
            <legend><?= $editRow ? 'Editar item' : 'Novo item' ?></legend>
            <form method="post" action="config_itens.php<?= $editRow ? '?action=edit&id='.$editRow['id'] : '' ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="post_action" value="<?= $editRow ? 'update' : 'create' ?>">

                <div class="grid-3">
                    <div>
                        <label>Nome *</label>
                        <input type="text" name="nome" value="<?= h($editRow['nome'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Categoria *</label>
                        <select name="categoria_id" required>
                            <option value="">Selecione</option>
                            <?php
                            $selCat = isset($editRow['categoria_id']) ? (int)$editRow['categoria_id'] : 0;
                            foreach ($cats as $cid=>$cn) {
                                $sel = $selCat===$cid ? 'selected' : '';
                                echo "<option value=\"".(int)$cid."\" $sel>".h($cn)."</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Tipo *</label>
                        <select name="tipo" onchange="syncTipo()" required>
                            <?php
                              $t = $editRow['tipo'] ?? 'preparo';
                              $opts = ['preparo'=>'Preparo','comprado'=>'Comprado'];
                              foreach ($opts as $val=>$lab) {
                                  $sel = ($t === $val) ? 'selected' : '';
                                  echo "<option value=\"".h($val)."\" $sel>".h($lab)."</option>";
                              }
                            ?>
                        </select>
                    </div>

                    <div>
                        <label>Unidade de compra *</label>
                        <input type="text" name="unidade_compra" placeholder="kg, un, L, pacote..." value="<?= h($editRow['unidade_compra'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Regra de consumo *</label>
                        <select name="regra_consumo" onchange="syncRegra()" required>
                            <?php
                              $r = $editRow['regra_consumo'] ?? 'por_pessoa';
                              $ops = ['por_pessoa'=>'Por pessoa','por_lote'=>'Por lote'];
                              foreach ($ops as $val=>$lab) {
                                  $sel = ($r === $val) ? 'selected' : '';
                                  echo "<option value=\"".h($val)."\" $sel>".h($lab)."</option>";
                              }
                            ?>
                        </select>
                    </div>
                    <div id="row-fornecedor" style="display:none">
                        <label>Fornecedor (se for comprado) *</label>
                        <select name="fornecedor_id">
                            <option value="">Selecione</option>
                            <?php
                            $selF = isset($editRow['fornecedor_id']) ? (int)$editRow['fornecedor_id'] : 0;
                            foreach ($forn as $fid=>$fn) {
                                $sel = $selF===$fid ? 'selected' : '';
                                echo "<option value=\"".(int)$fid."\" $sel>".h($fn)."</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div id="row-por-pessoa">
                        <label>Fator por pessoa *</label>
                        <input type="number" step="0.0001" min="0" name="fator_por_pessoa" value="<?= h($editRow['fator_por_pessoa'] ?? '') ?>">
                        <div class="small">Ex.: 2 doces por pessoa → 2</div>
                    </div>
                    <div id="row-por-lote" style="display:none">
                        <label>Qtd por lote *</label>
                        <input type="number" step="0.0001" min="0" name="qtd_por_lote" value="<?= h($editRow['qtd_por_lote'] ?? '') ?>">
                        <div class="small">Ex.: 1 bisnaga por 100 pessoas → qtd=1</div>
                    </div>
                    <div id="row-por-lote-2" style="display:none">
                        <label>Pessoas por lote *</label>
                        <input type="number" step="1" min="1" name="pessoas_por_lote" value="<?= h($editRow['pessoas_por_lote'] ?? '') ?>">
                        <div class="small">Ex.: 1 bisnaga a cada 100 pessoas → pessoas=100</div>
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
                        <a class="btn link" href="config_itens.php">Cancelar</a>
                    <?php endif; ?>
                </div>
                <p class="note" style="margin-top:10px">
                    • <b>Preparo</b> entra no cálculo de insumos (ficha técnica opcional, definida em “Fichas técnicas”).<br>
                    • <b>Comprado</b> vai para Encomendas. Fornecedor padrão é obrigatório.
                </p>
            </form>
        </fieldset>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Itens do cardápio</h3>
        <table class="table">
            <thead>
            <tr>
                <th style="width:60px">ID</th>
                <th>Nome</th>
                <th>Categoria</th>
                <th style="width:120px">Tipo</th>
                <th style="width:110px">Unid</th>
                <th style="width:160px">Regra</th>
                <th>Fornecedor</th>
                <th style="width:90px">Ordem</th>
                <th style="width:100px">Ativo</th>
                <th style="width:220px">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10">Nenhum item cadastrado.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td title="<?= h($r['observacoes']) ?>"><?= h($r['nome']) ?></td>
                    <td><?= h($r['categoria_nome']) ?></td>
                    <td><span class="badge mode"><?= $r['tipo']==='comprado'?'Comprado':'Preparo' ?></span></td>
                    <td><?= h($r['unidade_compra']) ?></td>
                    <td>
                        <?php if ($r['regra_consumo']==='por_pessoa'): ?>
                            <span class="badge">por pessoa</span>
                        <?php else: ?>
                            <span class="badge">por lote</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($r['fornecedor_nome'] ?? '') ?></td>
                    <td><?= (int)$r['ordem'] ?></td>
                    <td>
                        <?php if ($r['ativo']): ?>
                            <span class="badge on">Ativo</span>
                        <?php else: ?>
                            <span class="badge off">Inativo</span>
                        <?php endif; ?>
                        <a class="actions" href="config_itens.php?action=toggle&id=<?= (int)$r['id'] ?>">alternar</a>
                    </td>
                    <td class="actions">
                        <a href="config_itens.php?action=edit&id=<?= (int)$r['id'] ?>">Editar</a>
                        <a href="config_itens.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir este item?')">Excluir</a>
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
