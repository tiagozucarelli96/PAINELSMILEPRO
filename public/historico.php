<?php
declare(strict_types=1);
// public/lc_index.php — Painel do módulo Lista de Compras (PostgreSQL/Railway)

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

// detecta se existe sidebar
$temSidebar = is_file(__DIR__.'/sidebar.php');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Compras — Painel</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- CSS base do painel -->
<link rel="stylesheet" href="estilo.css?v=1">

<!-- Tailwind CDN com config CORRETA (antes do script) -->
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        primary: '#004AAD',
        'primary-light': '#e9efff',
        'primary-dark': '#003d8a'
      }
    }
  },
  corePlugins: { preflight: false } // evita reset agressivo que estica img/body
};
</script>
<script src="https://cdn.tailwindcss.com"></script>

<style>
:root { --sidebar-w: 240px; }

/* Fallback mínimo (se estilo.css não carregar por algum motivo) */
.sidebar{display:block!important;visibility:visible!important;}
header img,.logo img,img.site-logo{max-height:72px!important;width:auto!important;height:auto!important;}

/* Dá espaço pro conteúdo quando há sidebar */
<?php if ($temSidebar): ?>
body.has-sidebar .main-content { margin-left: var(--sidebar-w); }
<?php endif; ?>

/* Container principal */
.main-content { min-height: 100vh; }

/* Utilitário local */
.line-clamp-3 { display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
</style>
</head>

<body class="bg-gray-50 min-h-screen panel <?php echo $temSidebar ? 'has-sidebar' : ''; ?>">
<?php if ($temSidebar) { include __DIR__.'/sidebar.php'; } ?>

<div class="main-content">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 mb-2">Lista de Compras</h1>
        <p class="text-gray-600">Gerencie suas listas de compras e encomendas</p>
      </div>
      <div class="flex flex-col sm:flex-row gap-3">
        <a class="inline-flex items-center px-6 py-3 bg-primary hover:bg-primary-dark text-white font-semibold rounded-xl shadow-md hover:shadow-lg transition-all duration-200 transform hover:-translate-y-0.5" href="lista_compras.php">
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
          Gerar nova
        </a>
        <a class="inline-flex items-center px-6 py-3 bg-primary-light hover:bg-blue-100 text-primary font-semibold rounded-xl shadow-sm hover:shadow-md transition-all duration-200" href="historico.php">
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          Histórico
        </a>
        <a class="inline-flex items-center px-6 py-3 bg-primary-light hover:bg-blue-100 text-primary font-semibold rounded-xl shadow-sm hover:shadow-md transition-all duration-200" href="configurar.php">
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
          Configurar
        </a>
      </div>
    </div>

    <?php if (!$rows): ?>
      <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8 text-center">
        <div class="w-16 h-16 bg-primary-light rounded-full flex items-center justify-center mx-auto mb-4">
          <svg class="w-8 h-8 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
        </div>
        <h3 class="text-lg font-semibold text-gray-900 mb-2">Nenhum grupo encontrado</h3>
        <p class="text-gray-600 mb-6">Clique em <strong>"Gerar nova"</strong> para começar a criar suas listas de compras.</p>
        <a class="inline-flex items-center px-6 py-3 bg-primary hover:bg-primary-dark text-white font-semibold rounded-xl shadow-md hover:shadow-lg transition-all duration-200" href="lista_compras.php">
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
          Criar primeira lista
        </a>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php foreach ($rows as $r): ?>
          <div class="bg-white rounded-2xl shadow-sm hover:shadow-md border border-gray-200 p-6 transition-all duration-200 hover:-translate-y-1">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-bold text-gray-900">Grupo #<?= (int)$r['grupo_id'] ?></h3>
              <div class="w-2 h-2 bg-green-500 rounded-full"></div>
            </div>

            <div class="text-sm text-gray-500 mb-4">
              <div class="flex items-center">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                <?= h(brDate($r['data_gerada'])) ?>
              </div>
            </div>

            <?php if (!empty($r['espaco_consolidado'])): ?>
              <div class="mb-4">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary-light text-primary">
                  <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                  <?= h((string)$r['espaco_consolidado']) ?>
                </span>
              </div>
            <?php endif; ?>

            <?php if (!empty($r['eventos_resumo'])): ?>
              <div class="mb-6">
                <p class="text-sm text-gray-600 line-clamp-3" title="<?= h((string)$r['eventos_resumo']) ?>">
                  <?= h((string)$r['eventos_resumo']) ?>
                </p>
              </div>
            <?php endif; ?>

            <div class="flex flex-col sm:flex-row gap-3 mt-auto">
              <a class="flex-1 inline-flex items-center justify-center px-4 py-2.5 bg-primary-light hover:bg-blue-100 text-primary font-medium rounded-xl text-sm transition-colors duration-200" href="ver.php?g=<?= (int)$r['grupo_id'] ?>&tab=compras">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                Compras
              </a>
              <a class="flex-1 inline-flex items-center justify-center px-4 py-2.5 bg-primary hover:bg-primary-dark text-white font-medium rounded-xl text-sm shadow-md hover:shadow-lg transition-all duration-200" href="ver.php?g=<?= (int)$r['grupo_id'] ?>&tab=encomendas">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                Encomendas
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

</body>
</html>
