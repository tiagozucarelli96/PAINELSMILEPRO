<?php
// lista_compras.php — Dashboard do módulo “Lista de Compras”
session_start();
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão mínima: usuário logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt($s){ return $s ? date('d/m/Y H:i', strtotime($s)) : ''; }

// AÇÕES: soft delete
$msg=''; $err='';
try{
    if (isset($_GET['action']) && $_GET['action']==='delete') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id>0){
            $st = $pdo->prepare("UPDATE lc_listas SET deleted_at=CURRENT_TIMESTAMP WHERE id=? AND deleted_at IS NULL");
            $st->execute([$id]);
            $msg='Lista movida para a Lixeira.';
            header("Location: lista_compras.php?msg=".urlencode($msg)); exit;
        }
    }
}catch(Throwable $e){
    $err = $e->getMessage();
}

// Paginação separada
$perPage = 10;
$pc = max(1, (int)($_GET['pc'] ?? 1)); // página compras
$pe = max(1, (int)($_GET['pe'] ?? 1)); // página encomendas
$offC = ($pc-1)*$perPage;
$offE = ($pe-1)*$perPage;

// Contagens
$totalC = (int)$pdo->query("SELECT COUNT(*) FROM lc_listas WHERE deleted_at IS NULL AND tipo='compras'")->fetchColumn();
$totalE = (int)$pdo->query("SELECT COUNT(*) FROM lc_listas WHERE deleted_at IS NULL AND tipo='encomendas'")->fetchColumn();

// Listas
$sqlBase = "SELECT id, grupo_id, tipo, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome
            FROM lc_listas
            WHERE deleted_at IS NULL AND tipo=:tipo
            ORDER BY data_gerada DESC
            LIMIT :lim OFFSET :off";

$stc = $pdo->prepare($sqlBase);
$stc->bindValue(':tipo','compras');
$stc->bindValue(':lim',$perPage, PDO::PARAM_INT);
$stc->bindValue(':off',$offC, PDO::PARAM_INT);
$stc->execute();
$rowsC = $stc->fetchAll(PDO::FETCH_ASSOC);

$ste = $pdo->prepare($sqlBase);
$ste->bindValue(':tipo','encomendas');
$ste->bindValue(':lim',$perPage, PDO::PARAM_INT);
$ste->bindValue(':off',$offE, PDO::PARAM_INT);
$ste->execute();
$rowsE = $ste->fetchAll(PDO::FETCH_ASSOC);

// Helper de paginação
function render_pagination($total,$perPage,$page,$param){
    $pages = max(1, (int)ceil($total/$perPage));
    if ($pages<=1) return '';
    $html = '<div class="pagination">';
    for($p=1;$p<=$pages;$p++){
        $cls = $p===$page ? 'active' : '';
        $href = 'lista_compras.php?'.$param.'='.$p;
        $html .= '<a class="'.$cls.'" href="'.$href.'">'.$p.'</a>';
    }
    $html .= '</div>';
    return $html;
}

// Flags de permissão de admin para mostrar Configurações
$isAdmin = !empty($_SESSION['perm_usuarios']); // ajuste se houver outra flag
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Compras • Dashboard</title>
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
.empty{padding:18px;border:1px dashed #bcd; border-radius:10px; background:#f7fbff}
.note{font-size:13px;color:#555}
.actions a{margin-right:10px}
.pagination{display:flex; gap:6px; margin-top:12px}
.pagination a{padding:6px 10px; border:1px solid #ddd; border-radius:6px; text-decoration:none}
.pagination a.active{background:#004aad; color:#fff; border-color:#004aad}
.header-actions{display:flex; gap:8px; align-items:center}
@media (max-width:900px){
  .hide-sm{display:none}
}
</style>
</head>
<body>
<div class="sidebar">
    <img src="logo-smile.png" alt="Logo" />
    <nav>
        <a href="index.php?page=dashboard">🏠 Painel</a>
        <a href="lista_compras.php" style="background:#003580;border-bottom:3px solid #fff;">🛒 Lista de Compras</a>
        <?php if ($isAdmin): ?>
          <a href="config_categorias.php">⚙️ Configurações</a>
        <?php endif; ?>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Lista de Compras • Dashboard</h1>

    <div class="topbar">
        <div class="header-actions">
            <a class="btn" href="lista_compras_gerar.php">Gerar Lista de Compras</a>
            <?php if ($isAdmin): ?>
            <div class="dropdown">
                <a class="btn link" href="config_categorias.php">Configurações</a>
            </div>
            <?php endif; ?>
        </div>
        <div class="grow"></div>
        <span class="note">As últimas listas geradas aparecem abaixo. Excluir = envia para Lixeira.</span>
    </div>

    <?php if ($err): ?>
      <div class="card" style="border-left:4px solid #b00020"><p><?=h($err)?></p></div>
    <?php elseif (isset($_GET['msg'])): ?>
      <div class="card" style="border-left:4px solid #2e7d32"><p><?=h($_GET['msg'])?></p></div>
    <?php endif; ?>

    <!-- Compras -->
    <div class="card">
        <h2 style="margin:8px 0 14px">Últimas listas de compras</h2>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:80px">Nº</th>
                    <th style="width:200px">Data gerada</th>
                    <th style="width:180px">Espaço</th>
                    <th>Eventos</th>
                    <th class="hide-sm" style="width:220px">Criado por</th>
                    <th style="width:260px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rowsC): ?>
                <tr><td colspan="6">
                    <div class="empty">
                        Nenhuma lista de compras gerada.
                        <div style="margin-top:8px">
                            <a class="btn" href="lista_compras_gerar.php">Gerar Lista de Compras</a>
                        </div>
                    </div>
                </td></tr>
            <?php else: foreach ($rowsC as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h(dt($r['data_gerada'])) ?></td>
                    <td><?= h($r['espaco_consolidado']) ?></td>
                    <td><?= h($r['eventos_resumo']) ?></td>
                    <td class="hide-sm"><?= h($r['criado_por_nome'] ?: ('ID '.$r['criado_por'])) ?></td>
                    <td class="actions">
                        <a class="btn link" href="lista_compras_gerar.php?action=edit&grupo_id=<?= (int)$r['grupo_id'] ?>">Editar</a>
                        <a class="btn link" target="_blank" href="pdf_compras.php?grupo_id=<?= (int)$r['grupo_id'] ?>">Visualizar PDF</a>
                        <a class="btn warn" href="lista_compras.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Mover esta lista para a Lixeira?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?= render_pagination($totalC,$perPage,$pc,'pc') ?>
    </div>

    <!-- Encomendas -->
    <div class="card">
        <h2 style="margin:8px 0 14px">Últimas listas de encomendas</h2>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:80px">Nº</th>
                    <th style="width:200px">Data gerada</th>
                    <th style="width:180px">Espaço</th>
                    <th>Eventos</th>
                    <th class="hide-sm" style="width:220px">Criado por</th>
                    <th style="width:260px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rowsE): ?>
                <tr><td colspan="6">
                    <div class="empty">
                        Nenhuma lista de encomendas gerada.
                        <div style="margin-top:8px">
                            <a class="btn" href="lista_compras_gerar.php">Gerar Lista de Compras</a>
                        </div>
                    </div>
                </td></tr>
            <?php else: foreach ($rowsE as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h(dt($r['data_gerada'])) ?></td>
                    <td><?= h($r['espaco_consolidado']) ?></td>
                    <td><?= h($r['eventos_resumo']) ?></td>
                    <td class="hide-sm"><?= h($r['criado_por_nome'] ?: ('ID '.$r['criado_por'])) ?></td>
                    <td class="actions">
                        <a class="btn link" href="lista_compras_gerar.php?action=edit&grupo_id=<?= (int)$r['grupo_id'] ?>">Editar</a>
                        <a class="btn link" target="_blank" href="pdf_encomendas.php?grupo_id=<?= (int)$r['grupo_id'] ?>">Visualizar PDF</a>
                        <a class="btn warn" href="lista_compras.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Mover esta lista para a Lixeira?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?= render_pagination($totalE,$perPage,$pe,'pe') ?>
    </div>

    <p class="note">Dica: a Lixeira e restauração ficarão em <b>Configurações</b>.</p>
</div>
</div>
</body>
</html>
