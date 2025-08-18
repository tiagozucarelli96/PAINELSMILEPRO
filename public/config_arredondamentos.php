<?php
// config_arredondamentos.php
session_start();
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão: apenas admin/gestão
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1 || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ''; $err = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action = $_POST['post_action'] ?? '';

        if ($post_action === 'create' || $post_action === 'update') {
            $insumo_id = isset($_POST['insumo_id']) ? (int)$_POST['insumo_id'] : 0;
            $metodo    = ($_POST['metodo'] ?? 'ceil');
            $passo     = isset($_POST['passo']) ? (float)$_POST['passo'] : 1;
            $minimo    = isset($_POST['minimo']) && $_POST['minimo'] !== '' ? (float)$_POST['minimo'] : null;

            if ($insumo_id <= 0) throw new Exception('Selecione o insumo.');
            if (!in_array($metodo, ['ceil','floor','round'], true)) throw new Exception('Método inválido.');
            if ($passo <= 0) throw new Exception('Passo deve ser maior que 0.');

            if ($post_action === 'create') {
                $sql = "INSERT INTO lc_arredondamentos (insumo_id, metodo, passo, minimo) VALUES (:i,:m,:p,:n)";
                $st = $pdo->prepare($sql);
                $st->execute([':i'=>$insumo_id, ':m'=>$metodo, ':p'=>$passo, ':n'=>$minimo]);
                $msg = 'Regra criada.';
            } else {
                $idUpd = (int)($_POST['id'] ?? 0);
                if ($idUpd <= 0) throw new Exception('ID inválido.');
                $sql = "UPDATE lc_arredondamentos SET insumo_id=:i, metodo=:m, passo=:p, minimo=:n WHERE id=:id";
                $st = $pdo->prepare($sql);
                $st->execute([':i'=>$insumo_id, ':m'=>$metodo, ':p'=>$passo, ':n'=>$minimo, ':id'=>$idUpd]);
                $msg = 'Regra atualizada.';
            }
            header("Location: config_arredondamentos.php?msg=".urlencode($msg)); exit;
        }
    }

    if ($action === 'delete' && $id > 0) {
        $pdo->prepare("DELETE FROM lc_arredondamentos WHERE id=?")->execute([$id]);
        header("Location: config_arredondamentos.php?msg=".urlencode('Regra excluída.')); exit;
    }

} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'uk_arr_insumo') || str_contains($e->getMessage(), 'Duplicate')) {
        $err = 'Já existe uma regra para este insumo.';
    } else {
        $err = $e->getMessage();
    }
}

// Dicionário
$insumos = $pdo->query("SELECT id, nome FROM lc_insumos WHERE ativo=1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_KEY_PAIR);

// Listagem
$rows = $pdo->query("SELECT a.id, a.metodo, a.passo, a.minimo, i.nome AS insumo_nome
                     FROM lc_arredondamentos a
                     JOIN lc_insumos i ON i.id=a.insumo_id
                     ORDER BY i.nome ASC")->fetchAll(PDO::FETCH_ASSOC);

// Edição
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM lc_arredondamentos WHERE id=?");
    $st->execute([$id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $err = 'Registro não encontrado.'; $action = ''; }
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Configurações • Arredondamentos</title>
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
        <a href="config_arredondamentos.php" style="background:#003580;border-bottom:3px solid #fff;">📏 Arredondamentos</a>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Configurações • Arredondamentos</h1>

    <div class="card">
        <fieldset>
            <legend><?= $editRow ? 'Editar regra' : 'Nova regra' ?></legend>
            <form method="post" action="config_arredondamentos.php<?= $editRow ? '?action=edit&id='.$editRow['id'] : '' ?>">
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
                        <label>Método *</label>
                        <select name="metodo">
                            <?php
                              $m = $editRow['metodo'] ?? 'ceil';
                              $ops = ['ceil'=>'Arredondar pra cima (ceil)','round'=>'Arredondar normal (round)','floor'=>'Arredondar pra baixo (floor)'];
                              foreach ($ops as $val=>$lab) {
                                  $sel = ($m === $val) ? 'selected' : '';
                                  echo "<option value=\"".h($val)."\" $sel>".h($lab)."</option>";
                              }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Passo *</label>
                        <input type="number" step="0.0001" min="0" name="passo" value="<?= h($editRow['passo'] ?? 1) ?>" required>
                        <div class="small">Ex.: pacote de 50 unidades → 50</div>
                    </div>
                    <div>
                        <label>Mínimo</label>
                        <input type="number" step="0.0001" min="0" name="minimo" value="<?= h($editRow['minimo'] ?? '') ?>">
                        <div class="small">Ex.: compra mínima 2 kg</div>
                    </div>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px">
                    <button class="btn" type="submit"><?= $editRow ? 'Salvar alterações' : 'Adicionar' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn link" href="config_arredondamentos.php">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </fieldset>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Regras cadastradas</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Insumo</th>
                    <th style="width:180px">Método</th>
                    <th style="width:140px">Passo</th>
                    <th style="width:140px">Mínimo</th>
                    <th style="width:220px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6">Nenhuma regra cadastrada.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($r['insumo_nome']) ?></td>
                    <td><?= h($r['metodo']) ?></td>
                    <td><?= h($r['passo']) ?></td>
                    <td><?= h($r['minimo'] ?? '') ?></td>
                    <td class="actions">
                        <a href="config_arredondamentos.php?action=edit&id=<?= (int)$r['id'] ?>">Editar</a>
                        <a href="config_arredondamentos.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir esta regra?')">Excluir</a>
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
