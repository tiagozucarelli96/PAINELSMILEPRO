<?php
// public/dashboard.php — Dashboard com sidebar + cards (compatível com seu schema)
declare(strict_types=1);
session_start();
if (empty($_SESSION['logado'])) { header('Location: login.php'); exit; }

require_once __DIR__ . '/conexao.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Conta linhas de uma tabela. Se não existir, retorna null (e o card some).
function count_table(PDO $pdo, string $table): ?int {
  try {
    $st = $pdo->query("SELECT COUNT(*) FROM {$table}");
    return (int)$st->fetchColumn();
  } catch (Throwable $e) {
    return null;
  }
}

// Escolhemos tabelas que o seu schema mostrou no schema_check.php
$tables = [
  ['label'=>'Usuários',        'tbl'=>'usuarios',              'icon'=>'👤'],
  ['label'=>'Categorias',      'tbl'=>'categorias',            'icon'=>'🗂️'],
  ['label'=>'Itens',           'tbl'=>'itens',                 'icon'=>'📦'],
  ['label'=>'Insumos',         'tbl'=>'insumos',               'icon'=>'🧪'],
  ['label'=>'Fornecedores',    'tbl'=>'fornecedores',          'icon'=>'🏭'],
  ['label'=>'Pagamentos',      'tbl'=>'pagamentos',            'icon'=>'💳'],
  ['label'=>'Tarefas',         'tbl'=>'tarefas',               'icon'=>'📝'],
  // novas do módulo LC (podem estar vazias, mas já mostramos quando existirem)
  ['label'=>'Listas (LC)',     'tbl'=>'lc_listas',             'icon'=>'🛒'],
  ['label'=>'Eventos (LC)',    'tbl'=>'lc_lista_eventos',      'icon'=>'📅'],
  ['label'=>'Pref. Encomendas','tbl'=>'lc_pref_encomendas',    'icon'=>'⚙️'],
];

$cards = [];
foreach ($tables as $t) {
  $c = count_table($pdo, $t['tbl']);
  if ($c !== null) $cards[] = ['label'=>$t['label'], 'count'=>$c, 'icon'=>$t['icon']];
}

// Links rápidos (somente se os arquivos existem)
$quick = [];
foreach ([
  ['Gerar Lista','lista_compras_gerar.php','🧮'],
  ['Dashboard Compras','lista_compras.php','📊'],
  ['Notas Fiscais','notas_fiscais.php','🧾'],
  ['Pagamentos','pagamentos.php','💸'],
  ['Demandas','demandas.php','📌'],
  ['Config.','configuracoes.php','⚙️'],
] as $l) {
  [$txt,$href,$ic] = $l;
  if (is_file(__DIR__.'/'.$href)) $quick[] = [$txt,$href,$ic];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Painel • <?=h($_SESSION['nome'] ?? 'Usuário')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
/* fallback leve caso estilo.css não carregue */
:root{--bg:#0b1220;--panel:#0f1b35;--text:#fff;--muted:#9fb0d9}
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text)}
.container{display:flex;min-height:100dvh}
.sidebar-wrap{width:260px;background:var(--panel);padding:16px}
.main{flex:1; padding:24px}
.hi{margin:0 0 18px}
.grid{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
.card{background:#0b1430;border:1px solid #1c2a5a;border-radius:12px;padding:14px}
.kpi{font-size:28px;font-weight:800}
.muted{color:var(--muted)}
.quick{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.quick a{display:inline-block;background:#1a2b5f;color:#fff;text-decoration:none;padding:10px 12px;border-radius:10px;border:1px solid #2b3a64}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.logout a{color:#8ab4ff;text-decoration:none}
</style>
</head>
<body>
<div class="container">
  <div class="sidebar-wrap">
    <?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; }
    else { ?>
      <div style="color:#fff">
        <h2 style="margin-top:0">Menu</h2>
        <nav style="display:flex;flex-direction:column;gap:8px">
          <a href="dashboard.php" style="color:#8ab4ff">🏠 Painel</a>
          <?php foreach ($quick as $q): ?><a href="<?=h($q[1])?>" style="color:#8ab4ff"><?=h($q[2].' '.$q[0])?></a><?php endforeach; ?>
          <a href="logout.php" style="color:#f99">🚪 Sair</a>
        </nav>
      </div>
    <?php } ?>
  </div>

  <div class="main">
    <div class="topbar">
      <h1 class="hi">Bem-vindo, <?=h($_SESSION['nome'] ?? 'Usuário')?>!</h1>
      <div class="logout"><a href="logout.php">Sair</a></div>
    </div>

    <?php if ($cards): ?>
      <div class="grid">
        <?php foreach ($cards as $c): ?>
          <div class="card">
            <div class="muted"><?=h($c['icon'].' '.$c['label'])?></div>
            <div class="kpi"><?=h((string)$c['count'])?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="card">Nenhuma tabela reconhecida no schema atual.</div>
    <?php endif; ?>

    <?php if ($quick): ?>
      <h3 style="margin-top:22px">Acessos rápidos</h3>
      <div class="quick">
        <?php foreach ($quick as $q): ?>
          <a href="<?=h($q[1])?>"><?=h($q[2].' '.$q[0])?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
