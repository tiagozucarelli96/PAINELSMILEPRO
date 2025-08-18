<?php
// lista_compras_lixeira.php — Lixeira e restauração das listas (admin)
session_start();
ini_set('display_errors', 1); error_reporting(E_ALL);

// Restrito a admin/gestão
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1 || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt($s){ return $s ? date('d/m/Y H:i', strtotime($s)) : ''; }

$msg=''; $err='';
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$grupo  = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;

try {
    if ($action === 'restore' && $id > 0) {
        $st = $pdo->prepare("UPDATE lc_listas SET deleted_at=NULL WHERE id=?");
        $st->execute([$id]);
        $msg = 'Lista restaurada.';
        header("Location: lista_compras_lixeira.php?msg=".urlencode($msg)); exit;
    }

    if ($action === 'delete' && $id > 0) {
        $st = $pdo->prepare("DELETE FROM lc_listas WHERE id=? AND deleted_at IS NOT NULL");
        $st->execute([$id]);
        $msg = 'Lista excluída definitivamente.';
        header("Location: lista_compras_lixeira.php?msg=".urlencode($msg)); exit;
    }

    if ($action === 'purge_group' && $grupo > 0) {
        // Só permite se não houver linhas ativas em lc_listas para este grupo
        $active = $pdo->prepare("SELECT COUNT(*) FROM lc_listas WHERE grupo_id=? AND deleted_at IS NULL");
        $active->execute([$grupo]);
        if ((int)$active->fetchColumn() > 0) {
            throw new Exception('Não é possível purgar: ainda existem listas ativas para este grupo.');
        }
        // Exclui o grupo e tudo relacionado (CASCADE apaga compras/encomendas/eventos/snapshot)
        $st = $pdo->prepare("DELETE FROM lc_geracoes WHERE id=?");
        $st->execute([$grupo]);
        $msg = 'Grupo purgado com sucesso.';
        header("Location: lista_compras_lixeira.php?msg=".urlencode($msg)); exit;
    }

} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Filtros
$tipo = $_GET['tipo'] ?? ''; // compras | encomendas | ''
$where = "deleted_at IS NOT NULL";
$params = [];
if ($tipo === 'compras' || $tipo === 'encomendas') {
    $where .= " AND tipo=:t";
    $params[':t'] = $tipo;
}

// Paginação
$perPage = 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$off = ($page-1)*$perPage;

$stc = $pdo->prepare("SELECT COUNT(*) FROM lc_listas WHERE $where");
$stc->execute($params);
$total = (int)$stc->fetchColumn();

$sql = "SELECT id, grupo_id, tipo, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, deleted_at
        FROM lc_listas
        WHERE $where
        ORDER BY deleted_at DESC, data_gerada DESC
        LIMIT :lim OFFSET :off";
$st = $pdo->prepare($sql);
foreach ($params as $k=>$v) { $st->bindValue($k, $v); }
$st->bindValue(':lim', $perPage, PDO::PARAM_INT);
$st->bindValue(':off', $off, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Grupos elegíveis a purga (sem listas ativas)
$grp = $pdo->query("SELECT g.id,
           SUM(CASE WHEN l.deleted_at IS NULL THEN 1 ELSE 0 END) AS ativos,
           SUM(CASE WHEN l.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS na_lixeira
        FROM lc_geracoes g
        LEFT JOIN lc_listas l ON l.grupo_id = g.id
        GROUP BY g.id
        HAVING ativos=0 AND na_lixeira>0
        ORDER BY g.id DESC
        LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$purgeable = array_column($grp, 'id');

// Helper paginação
function render_pagination($total,$perPage,$page){
    $pages = max(1, (int)ceil($total/$perPage));
    if ($pages<=1) return '';
    $qs = $_GET; unset($qs['p']);
    $base = 'lista_compras_lixeira.php?'.http_build_query($qs);
    $html = '<div class="pagination">';
    for($p=1;$p<=$pages;$p++){
        $cls = $p===$page ? 'active' : '';
        $href = $base.($base ? '&' : '').'p='.$p;
        $html .= '<a class="'.$cls.'" href="'.$href.'">'.$p.'</a>';
    }
    $html .= '</div>';
    return $html;
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lixeira • Listas de Compras/Encomendas</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
.content-narrow{ max-width:1200px; margin:0 auto; }
.topbar{display:flex; gap:10px; align-items:center; margin-bottom:16px; flex-wrap:wrap}
.topbar .grow{flex:1}
.btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
.btn.link{background:#e9efff;color:#004aad}
.btn.warn{background:#b00020}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border-bottom:1px solid #eee;padding:10px;text-align:left;vertical-align:middle}
.card{margin-bottom:18px}
h1{margin-top:0}
.badge{font-size:12px;padding:4px 8px;border-radius:999px;background:#eee}
.pagination{display:flex; gap:6px; margin-top:12px}
.pagination a{padding:6px 10px; border:1px solid #ddd; border-radius:6px; text-decoration:none}
.pagination a.active{background:#004aad; color:#fff; border-color:#004aad}
.note{font-size:13px;color:#555}
.actions a{margin-right:10px}
.alert{border-left:4px solid #2e7d32; padding:8px 10px; background:#f3fff3}
.alert.err{border-left-color:#b00020; background:#fff3f3}
</style>
</head>
<body>
<div class="sidebar">
    <img src="logo-smile.png" alt="Logo" />
    <nav>
        <a href="index.php?page=dashboard">🏠 Painel</a>
        <a href="lista_compras.php">🛒 Dashboard Lista</a>
        <a href="config_categorias.php">⚙️ Configurações</a>
        <a href="lista_compras_lixeira.php" style="background:#003580;border-bottom:3px solid #fff;">🗑️ Lixeira</a>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Lixeira • Listas</h1>

    <div class="topbar">
        <form class="inline" method="get" action="lista_compras_lixeira.php" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap">
            <label>Tipo</label>
            <select name="tipo">
                <option value="">Todos</option>
                <option value="compras" <?= $tipo==='compras'?'selected':'' ?>>Compras</option>
                <option value="encomendas" <?= $tipo==='encomendas'?'selected':'' ?>>Encomendas</option>
            </select>
            <button class="btn" type="submit">Filtrar</button>
            <a class="btn link" href="lista_compras_lixeira.php">Limpar</a>
        </form>
        <div class="grow"></div>
        <a class="btn link" href="lista_compras.php">Voltar ao Dashboard</a>
    </div>

    <?php if ($err): ?>
        <div class="alert err"><?= h($err) ?></div>
    <?php elseif (isset($_GET['msg'])): ?>
        <div class="alert"><?= h($_GET['msg']) ?></div>
    <?php endif; ?>

    <div class="card">
        <h3 style="margin:0 0 8px">Itens na lixeira</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:70px">Nº</th>
                    <th style="width:120px">Tipo</th>
                    <th style="width:180px">Data gerada</th>
                    <th style="width:160px">Espaço</th>
                    <th>Eventos</th>
                    <th style="width:200px">Criado por</th>
                    <th style="width:180px">Excluída em</th>
                    <th style="width:280px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8">Lixeira vazia.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><span class="badge"><?= h($r['tipo']) ?></span></td>
                    <td><?= h(dt($r['data_gerada'])) ?></td>
                    <td><?= h($r['espaco_consolidado']) ?></td>
                    <td><?= h($r['eventos_resumo']) ?></td>
                    <td><?= h($r['criado_por_nome'] ?: ('ID '.$r['criado_por'])) ?></td>
                    <td><?= h(dt($r['deleted_at'])) ?></td>
                    <td class="actions">
                        <a class="btn link" href="lista_compras_lixeira.php?action=restore&id=<?= (int)$r['id'] ?>">Restaurar</a>
                        <a class="btn warn" href="lista_compras_lixeira.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir definitivamente esta linha? Não afeta o grupo todo.')">Excluir linha</a>
                        <?php if (in_array((int)$r['grupo_id'], $purgeable, true)): ?>
                            <a class="btn warn" href="lista_compras_lixeira.php?action=purge_group&grupo_id=<?= (int)$r['grupo_id'] ?>" onclick="return confirm('Purgar grupo #<?= (int)$r['grupo_id'] ?>? Isso removerá todos os dados deste grupo (eventos, compras, encomendas, snapshots).')">Purgar grupo</a>
                        <?php else: ?>
                            <span class="badge">Grupo #<?= (int)$r['grupo_id'] ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?= render_pagination($total, $perPage, $page) ?>
    </div>

    <p class="note">Regra de purga: só disponível quando <b>todas</b> as listas do grupo estão na lixeira.</p>
</div>
</div>
</body>
</html>
