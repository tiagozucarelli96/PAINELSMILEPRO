<?php
declare(strict_types=1);
// public/historico.php — Histórico de Listas de Compras (PostgreSQL/Railway)

// ========= Sessão / Auth =========
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); }
if (!$uid || !is_numeric($uid) || !$estaLogado) {
    http_response_code(403);
    echo "Acesso negado. Faça login para continuar.";
    exit;
}

// ========= Conexão =========
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

// ========= Helpers =========

function brDate(string $isoTs): string {
    // aceita timestamptz/texto 'YYYY-MM-DD ...'
    if (!$isoTs) return '';
    $t = strtotime($isoTs);
    return $t ? date('d/m/Y H:i', $t) : $isoTs;
}

// ========= Filtros / Paginação =========
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// ========= Consultas =========
$searchSql = "";
$params = [':lim'=>$limit, ':off'=>$offset, ':q'=>'', ':pat'=>''];
if ($q !== '') {
    $searchSql = "WHERE (espaco_consolidado ILIKE :pat OR eventos_resumo ILIKE :pat OR coalesce(criado_por_nome,'') ILIKE :pat)";
    $params[':q'] = $q;
    $params[':pat'] = '%'.$q.'%';
}

// total de grupos (para paginação)
$sqlCount = "
WITH base AS (
  SELECT grupo_id,
         max(data_gerada) AS data_gerada,
         max(espaco_consolidado) AS espaco_consolidado,
         max(eventos_resumo) AS eventos_resumo,
         max(criado_por) AS criado_por,
         max(criado_por_nome) AS criado_por_nome
  FROM lc_listas
  GROUP BY grupo_id
)
SELECT count(*)::int AS total FROM base
$searchSql
";
$stCount = $pdo->prepare($sqlCount);
if ($q!=='') $stCount->bindValue(':pat', $params[':pat'], PDO::PARAM_STR);
$stCount->execute();
$total = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($total / $limit));

// lista paginada
$sqlList = "
WITH base AS (
  SELECT grupo_id,
         max(data_gerada) AS data_gerada,
         max(espaco_consolidado) AS espaco_consolidado,
         max(eventos_resumo) AS eventos_resumo,
         max(criado_por) AS criado_por,
         max(criado_por_nome) AS criado_por_nome
  FROM lc_listas
  GROUP BY grupo_id
)
SELECT * FROM base
$searchSql
ORDER BY data_gerada DESC
LIMIT :lim OFFSET :off
";
$st = $pdo->prepare($sqlList);
if ($q!=='') $st->bindValue(':pat', $params[':pat'], PDO::PARAM_STR);
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// montar querystring para paginação preservando q
function qs(array $extra=[]): string {
    $base = $_GET;
    foreach ($extra as $k=>$v) $base[$k]=$v;
    return http_build_query($base);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Histórico — Lista de Compras</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="estilo.css">
    <style>
        .wrap{padding:16px}
        .card{background:#fff;border:1px solid #dfe7f4;border-radius:12px;padding:16px}
        .toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
        .input{padding:10px;border:1px solid #cfe0ff;border-radius:8px}
        .btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
        .btn.gray{background:#e9efff;color:#004aad}
        table{width:100%;border-collapse:separate;border-spacing:0}
        th,td{padding:10px;border-bottom:1px solid #eef3ff;vertical-align:top}
        th{text-align:left;font-size:13px;color:#37517e}
        .muted{color:#667b9f;font-size:12px}
        .actions a{margin-right:8px}
        .pagination{display:flex;gap:6px;justify-content:flex-end;margin-top:12px}
        .pagination a, .pagination span{padding:8px 12px;border:1px solid #e1ebff;border-radius:8px;text-decoration:none}
        .pagination .active{background:#004aad;color:#fff;border-color:#004aad}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
        h1{margin:0}
    </style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
    <div class="wrap">
    <div class="header">
    <h1>Histórico de Listas</h1>
    <div>
        <a class="btn gray" href="lc_index.php">← Painel</a>
        <a class="btn" href="lista_compras.php">+ Gerar nova</a>
        <a class="btn gray" href="configurar.php">Configurar</a>
    </div>
</div>

        <div class="card">
            <form method="get" class="toolbar">
                <input class="input" type="text" name="q" value="<?=h($q)?>" placeholder="Buscar por espaço, eventos, criado por...">
                <button class="btn" type="submit">Buscar</button>
                <?php if ($q!==''): ?>
                    <a class="btn gray" href="historico.php">Limpar</a>
                <?php endif; ?>
                <span class="muted" style="margin-left:auto"><?= $total ?> grupo(s)</span>
            </form>

            <div style="overflow:auto">
            <table>
                <thead>
                    <tr>
                        <th style="width:80px">Grupo</th>
                        <th style="width:180px">Data</th>
                        <th>Espaço(s)</th>
                        <th>Eventos</th>
                        <th style="width:180px">Criado por</th>
                        <th style="width:200px">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="muted">Nenhuma lista encontrada.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td>#<?= (int)$r['grupo_id'] ?></td>
                        <td>
                            <?= h(brDate((string)$r['data_gerada'])) ?><br>
                            <span class="muted">mais recente</span>
                        </td>
                        <td><?= h((string)$r['espaco_consolidado']) ?></td>
                        <td><div class="muted" style="max-width:520px"><?= h((string)$r['eventos_resumo']) ?></div></td>
                        <td>
                            <?= h((string)($r['criado_por_nome'] ?? '')) ?><br>
                            <span class="muted">ID: <?= (int)($r['criado_por'] ?? 0) ?></span>
                        </td>
                        <td class="actions">
                            <a class="btn gray" href="ver.php?g=<?= (int)$r['grupo_id'] ?>&tab=compras">Ver Compras</a>
                            <a class="btn" href="ver.php?g=<?= (int)$r['grupo_id'] ?>&tab=encomendas">Ver Encomendas</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                // páginas vizinhas simples
                $start = max(1, $page-2);
                $end   = min($totalPages, $page+2);
                if ($page > 1) {
                    echo '<a href="historico.php?'.h(qs(['page'=>$page-1])).'">« Anterior</a>';
                }
                for ($p=$start; $p<=$end; $p++) {
                    if ($p === $page) {
                        echo '<span class="active">'.(int)$p.'</span>';
                    } else {
                        echo '<a href="historico.php?'.h(qs(['page'=>$p])).'">'.(int)$p.'</a>';
                    }
                }
                if ($page < $totalPages) {
                    echo '<a href="historico.php?'.h(qs(['page'=>$page+1])).'">Próxima »</a>';
                }
                ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
