<?php
// config_categorias.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão: apenas admin/gestão de usuários (ajuste se necessário)
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1 || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }



// Ações
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg    = '';
$err    = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action = $_POST['post_action'] ?? '';
        if ($post_action === 'create' || $post_action === 'update') {
            $nome   = trim($_POST['nome'] ?? '');
            $ordem  = (int)($_POST['ordem'] ?? 0);
            $ativo  = isset($_POST['ativo']) ? 1 : 0;
            $mostrar= isset($_POST['mostrar_no_gerar']) ? 1 : 0;

            if ($nome === '') { throw new Exception('Informe o nome.'); }

            if ($post_action === 'create') {
                $sql = "INSERT INTO lc_categorias (nome, ordem, ativo, mostrar_no_gerar) VALUES (:nome, :ordem, :ativo, :mostrar)";
                $st = $pdo->prepare($sql);
                $st->execute([':nome'=>$nome, ':ordem'=>$ordem, ':ativo'=>$ativo, ':mostrar'=>$mostrar]);
                $msg = 'Categoria criada.';
            } else {
                $idUpd = (int)($_POST['id'] ?? 0);
                if ($idUpd <= 0) throw new Exception('ID inválido.');
                $sql = "UPDATE lc_categorias SET nome=:nome, ordem=:ordem, ativo=:ativo, mostrar_no_gerar=:mostrar WHERE id=:id";
                $st = $pdo->prepare($sql);
                $st->execute([':nome'=>$nome, ':ordem'=>$ordem, ':ativo'=>$ativo, ':mostrar'=>$mostrar, ':id'=>$idUpd]);
                $msg = 'Categoria atualizada.';
            }
            header("Location: config_categorias.php?msg=".urlencode($msg));
            exit;
        }
    }

    if ($action === 'toggle' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT ativo FROM lc_categorias WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['ativo'] ? 0 : 1;
        $st = $pdo->prepare("UPDATE lc_categorias SET ativo=? WHERE id=?");
        $st->execute([$novo, $id]);
        $pdo->commit();
        $msg = 'Categoria '.($novo? 'ativada' : 'desativada').'.';
        header("Location: config_categorias.php?msg=".urlencode($msg));
        exit;
    }

    if ($action === 'toggle_mostrar' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT mostrar_no_gerar FROM lc_categorias WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['mostrar_no_gerar'] ? 0 : 1;
        $st = $pdo->prepare("UPDATE lc_categorias SET mostrar_no_gerar=? WHERE id=?");
        $st->execute([$novo, $id]);
        $pdo->commit();
        $msg = 'Campo "Mostrar no Gerar" atualizado.';
        header("Location: config_categorias.php?msg=".urlencode($msg));
        exit;
    }

    if ($action === 'delete' && $id > 0) {
        $st = $pdo->prepare("DELETE FROM lc_categorias WHERE id=?");
        $st->execute([$id]);
        $msg = 'Categoria excluída.';
        header("Location: config_categorias.php?msg=".urlencode($msg));
        exit;
    }

} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Busca para listagem
$busca = trim($_GET['q'] ?? '');
$params = [];
$sqlList = "SELECT id, nome, ordem, ativo, mostrar_no_gerar, created_at, updated_at
            FROM lc_categorias";
if ($busca !== '') {
    $sqlList .= " WHERE nome LIKE :q";
    $params[':q'] = "%{$busca}%";
}
$sqlList .= " ORDER BY ordem ASC, nome ASC";
$st = $pdo->prepare($sqlList);
$st->execute($params);
$categorias = $st->fetchAll(PDO::FETCH_ASSOC);

// Se for edição
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM lc_categorias WHERE id=?");
    $st->execute([$id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $err = 'Registro não encontrado.'; $action = ''; }
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Configurações • Categorias</title>
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
</style>
</head>
<body>
<div class="sidebar">
    <img src="logo-smile.png" alt="Logo" />
    <nav>
        <a href="index.php?page=dashboard">🏠 Painel</a>
        <a href="lista_compras.php">🛒 Lista de Compras</a>
        <a href="config_categorias.php" style="background:#003580;border-bottom:3px solid #fff;">⚙️ Configurações</a>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Configurações • Categorias</h1>

    <div class="topbar">
        <form class="inline" method="get" action="config_categorias.php">
            <input class="input-sm" type="text" name="q" placeholder="Buscar categoria..." value="<?=h($busca)?>">
            <button class="btn" type="submit">Buscar</button>
            <a class="btn link" href="config_categorias.php">Limpar</a>
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
            <legend><?= $editRow ? 'Editar categoria' : 'Nova categoria' ?></legend>
            <form method="post" action="config_categorias.php<?= $editRow ? '?action=edit&id='.$editRow['id'] : '' ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="post_action" value="<?= $editRow ? 'update' : 'create' ?>">
                <div style="display:grid;grid-template-columns:1fr 140px 160px 200px;gap:10px;align-items:end">
                    <div>
                        <label>Nome</label>
                        <input type="text" name="nome" value="<?= h($editRow['nome'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Ordem</label>
                        <input type="number" name="ordem" value="<?= h($editRow['ordem'] ?? 0) ?>" min="0">
                    </div>
                    <div>
                        <label>Ativo</label><br>
                        <input type="checkbox" name="ativo" <?= !isset($editRow['ativo']) || (int)($editRow['ativo'])===1 ? 'checked' : '' ?>> mostrar
                    </div>
                    <div>
                        <label>Mostrar no Gerar Lista</label><br>
                        <input type="checkbox" name="mostrar_no_gerar" <?= !isset($editRow['mostrar_no_gerar']) || (int)($editRow['mostrar_no_gerar'])===1 ? 'checked' : '' ?>> habilitar
                    </div>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px">
                    <button class="btn" type="submit"><?= $editRow ? 'Salvar alterações' : 'Adicionar' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn link" href="config_categorias.php">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
            <p class="note" style="margin-top:10px">“Ativo” controla se a categoria aparece no sistema. “Mostrar no Gerar Lista” controla se o bloco aparece para seleção na tela de geração.</p>
        </fieldset>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Categorias</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Nome</th>
                    <th style="width:90px">Ordem</th>
                    <th style="width:120px">Ativo</th>
                    <th style="width:170px">Mostrar no Gerar</th>
                    <th style="width:220px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$categorias): ?>
                <tr><td colspan="6">Nenhuma categoria cadastrada.</td></tr>
            <?php else: foreach ($categorias as $c): ?>
                <tr>
                    <td><?= (int)$c['id'] ?></td>
                    <td><?= h($c['nome']) ?></td>
                    <td><?= (int)$c['ordem'] ?></td>
                    <td>
                        <?php if ($c['ativo']): ?>
                            <span class="badge on">Ativo</span>
                        <?php else: ?>
                            <span class="badge off">Inativo</span>
                        <?php endif; ?>
                        <a class="actions" href="config_categorias.php?action=toggle&id=<?= (int)$c['id'] ?>">alternar</a>
                    </td>
                    <td>
                        <?php if ($c['mostrar_no_gerar']): ?>
                            <span class="badge on">Habilitado</span>
                        <?php else: ?>
                            <span class="badge off">Desabilitado</span>
                        <?php endif; ?>
                        <a class="actions" href="config_categorias.php?action=toggle_mostrar&id=<?= (int)$c['id'] ?>">alternar</a>
                    </td>
                    <td class="actions">
                        <a href="config_categorias.php?action=edit&id=<?= (int)$c['id'] ?>">Editar</a>
                        <a href="config_categorias.php?action=delete&id=<?= (int)$c['id'] ?>" onclick="return confirm('Excluir esta categoria?')">Excluir</a>
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
