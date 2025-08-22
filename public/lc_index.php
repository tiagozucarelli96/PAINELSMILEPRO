<?php
declare(strict_types=1);
// public/index.php — Painel do módulo Lista de Compras (PostgreSQL/Railway)

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); }
if (!$uid || !is_numeric($uid) || !$estaLogado) { http_response_code(403); echo "Acesso negado. Faça login para continuar."; exit; }

require_once __DIR__.'/conexao.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function brDate($iso){ $t=strtotime((string)$iso); return $t?date('d/m/Y H:i',$t):(string)$iso; }

// últimos 8 grupos
$rows = $pdo->query("
WITH base AS (
  SELECT grupo_id,
         max(data_gerada) AS data_gerada,
         max(espaco_consolidado) AS espaco_consolidado,
         max(eventos_resumo) AS eventos_resumo
  FROM lc_listas
  GROUP BY grupo_id
)
SELECT * FROM base ORDER BY data_gerada DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Compras — Painel</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
.wrap{padding:16px}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
h1{margin:0}
.btn{background:#004aad;color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.btn.gray{background:#e9efff;color:#004aad}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px}
.card{background:#fff;border:1px solid #dfe7f4;border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:8px}
.muted{color:#667b9f;font-size:12px}
.kv{display:flex;gap:6px;flex-wrap:wrap}
.kv span{background:#f2f6ff;border:1px solid #e6eeff;border-radius:999px;padding:4px 8px;font-size:12px}
.actions{display:flex;gap:8px;margin-top:auto}
</style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

    <div class="header">
        <h1>Lista de Compras — Painel</h1>
        <div>
            <a class="btn" href="lista_compras.php">+ Gerar nova</a>
            <a class="btn gray" href="historico.php">Histórico</a>
            <a class="btn gray" href="configurar.php">Configurar</a>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="card">
            <div class="muted">Sem grupos ainda. Clique em <strong>“+ Gerar nova”</strong> para começar.</div>
        </div>
    <?php else: ?>
        <div class="grid">
        <?php foreach ($rows as $r): ?>
            <div class="card">
                <div><strong>Grupo #<?= (int)$r['grupo_id'] ?></strong></div>
                <div class="muted">Gerado: <?= h(brDate($r['data_gerada'])) ?></div>
                <div class="kv">
                    <span><?= h((string)$r['espaco_consolidado']) ?: '—' ?></span>
                </div>
                <div class="muted" title="<?= h((string)$r['eventos_resumo']) ?>" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">
                    <?= h((string)$r['eventos_resumo']) ?>
                </div>
                <div class="actions">
                    <a class="btn gray" href="ver.php?g=<?= (int)$r['grupo_id'] ?>&tab=compras">Ver Compras</a>
                    <a class="btn" href="ver.php?g=<?= (int)$r['grupo_id'] ?>&tab=encomendas">Ver Encomendas</a>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
</div>
</body>
</html>
