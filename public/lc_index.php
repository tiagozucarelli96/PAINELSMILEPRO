<?php
declare(strict_types=1);
// public/lc_index.php — Painel do módulo Lista de Compras (PostgreSQL/Railway)

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
if (!$uid || !is_numeric($uid) || !$estaLogado) { http_response_code(403); echo "Acesso negado. Faça login para continuar."; exit; }

require_once __DIR__ . '/conexao.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function brDate(string $isoTs): string {
    if (!$isoTs) return '';
    $t = strtotime($isoTs);
    return $t ? date('d/m/Y H:i', $t) : $isoTs;
}

// Últimos 8 grupos
$rows = $pdo->query("
WITH base AS (
  SELECT grupo_id,
         max(data_gerada)          AS data_gerada,
         max(espaco_consolidado)   AS espaco_consolidado,
         max(eventos_resumo)       AS eventos_resumo
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
  <!-- Usa o CSS do painel (mesmo do histórico) -->
  <link rel="stylesheet" href="estilo.css">
  <style>
    /* Complementos mínimos locais */
    .wrap{padding:16px}
    .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .actions-top a{margin-left:8px}
    .grid-cards{display:grid;gap:16px;grid-template-columns:repeat(4,minmax(220px,1fr))}
    @media (max-width:1100px){.grid-cards{grid-template-columns:repeat(3,minmax(220px,1fr))}}
    @media (max-width:900px){.grid-cards{grid-template-columns:repeat(2,minmax(220px,1fr))}}
    @media (max-width:560px){.grid-cards{grid-template-columns:1fr}}
    .muted{color:#667b9f;font-size:12px}
    .line-clamp-3{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
    .card .title{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
    .chip{display:inline-flex;align-items:center;gap:6px;background:#e9efff;color:#004aad;border:1px solid #d0dcff;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600}
    .btn-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
    /* Garantias caso algo tenha mexido no CSS global */
    .sidebar{display:block!important;visibility:visible!important;}
    .main-content{margin-left:240px;}
    header img,.logo img,img.site-logo{max-height:72px!important;width:auto!important;height:auto!important;}
  </style>
</head>
<body class="panel has-sidebar">
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>

<div class="main-content">
  <div class="wrap">

    <div class="header">
      <div>
        <h1>Lista de Compras</h1>
        <p class="text-normal" style="margin:0;color:#6c757d">Gerencie suas listas de compras e encomendas.</p>
      </div>
      <div class="actions-top">
        <a class="btn" href="lista_compras.php">+ Gerar nova</a>
        <a class="btn gray" href="historico.php">⏱ Histórico</a>
        <a class="btn gray" href="configurar.php">⚙️ Configurar</a>
      </div>
    </div>

    <?php if (!$rows): ?>
      <div class="card" style="text-align:center">
        <div style="width:64px;height:64px;background:#e9efff;border-radius:999px;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;">
          <span style="font-size:28px;color:#004aad">🧾</span>
        </div>
        <h3 class="mb-sm">Nenhum grupo encontrado</h3>
        <p class="text-normal" style="color:#6c757d">Clique em <strong>“+ Gerar nova”</strong> para começar a criar suas listas de compras.</p>
        <div style="margin-top:12px">
          <a class="btn" href="lista_compras.php">+ Criar primeira lista</a>
        </div>
      </div>
    <?php else: ?>

      <div class="grid-cards">
        <?php foreach ($rows as $r): ?>
          <div class="card">
            <div class="title">
              <h3 style="margin:0">Grupo #<?= (int)$r['grupo_id'] ?></h3>
              <span style="width:10px;height:10px;background:#2ecc71;border-radius:999px;display:inline-block"></span>
            </div>

            <div class="mb-sm muted">
              <?= h(brDate((string)$r['data_gerada'])) ?>
            </div>

            <?php if (!empty($r['espaco_consolidado'])): ?>
              <div class="mb-sm">
                <span class="chip">🏢 <?= h((string)$r['espaco_consolidado']) ?></span>
              </div>
            <?php endif; ?>

            <?php if (!empty($r['eventos_resumo'])): ?>
              <div class="mb-sm">
                <div class="text-normal line-clamp-3" title="<?= h((string)$r['eventos_resumo']) ?>">
                  <?= h((string)$r['eventos_resumo']) ?>
                </div>
              </div>
            <?php endif; ?>

            <div class="btn-row">
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
