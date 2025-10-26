<?php
// index_moderno.php — Index com sidebar moderna integrada
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* ===== ATALHO TEMPORÁRIO (REMOVER APÓS O TESTE) ===== */
if (
  (isset($_GET['route']) && $_GET['route'] === 'me_proxy') ||
  (isset($_GET['page'])  && $_GET['page']  === 'me_proxy')
) {
  header('Content-Type: application/json; charset=utf-8');
  require __DIR__ . '/me_proxy.php';
  exit;
}
/* ===== FIM DO ATALHO TEMPORÁRIO ===== */

$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));

/* utilidades */
if (isset($_GET['ping'])) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "PONG\nPHP ".PHP_VERSION."\n";
  exit;
}
if (isset($_GET['diag'])) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "DIAG\n";
  echo "PHP ".PHP_VERSION."\n";
  echo "Memory: ".memory_get_usage(true)." bytes\n";
  echo "Peak: ".memory_get_peak_usage(true)." bytes\n";
  exit;
}

// Incluir sistema de sidebar
require_once __DIR__ . '/sidebar_integration.php';

// Verificar se usuário está logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    // Redirecionar para login se não estiver logado
    header('Location: login.php');
    exit;
}

// Iniciar sidebar
includeSidebar();

// Definir página atual
$page = $_GET['page'] ?? 'dashboard';

// Definir título da página
$pageTitles = [
    'dashboard' => 'Dashboard',
    'agenda' => 'Agenda',
    'comercial_degustacoes' => 'Degustações',
    'comercial_degust_inscricoes' => 'Inscrições',
    'comercial_clientes' => 'Conversão',
    'lc_index' => 'Lista de Compras',
    'config_fornecedores' => 'Fornecedores',
    'config_insumos' => 'Insumos',
    'estoque_logistico' => 'Estoque Logístico',
    'ver' => 'Visualizar',
    'pagamentos' => 'Pagamentos',
    'notas_fiscais' => 'Notas Fiscais',
    'usuarios' => 'Usuários',
    'configuracoes' => 'Configurações',
    'banco_smile_admin' => 'Banco Smile'
];

$pageTitle = $pageTitles[$page] ?? 'Página';
setPageTitle($pageTitle);

// Adicionar breadcrumb
$breadcrumbs = [
    'dashboard' => [['title' => 'Dashboard']],
    'agenda' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Agenda']],
    'comercial_degustacoes' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Comercial'], ['title' => 'Degustações']],
    'comercial_degust_inscricoes' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Comercial'], ['title' => 'Inscrições']],
    'comercial_clientes' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Comercial'], ['title' => 'Conversão']],
    'lc_index' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Compras'], ['title' => 'Lista de Compras']],
    'config_fornecedores' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Compras'], ['title' => 'Fornecedores']],
    'config_insumos' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Compras'], ['title' => 'Insumos']],
    'estoque_logistico' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Estoque'], ['title' => 'Logístico']],
    'ver' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Estoque'], ['title' => 'Visualizar']],
    'pagamentos' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Financeiro'], ['title' => 'Pagamentos']],
    'notas_fiscais' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Financeiro'], ['title' => 'Notas Fiscais']],
    'usuarios' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'RH'], ['title' => 'Usuários']],
    'configuracoes' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'RH'], ['title' => 'Configurações']],
    'banco_smile_admin' => [['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'], ['title' => 'Administrativo'], ['title' => 'Banco Smile']]
];

if (isset($breadcrumbs[$page])) {
    addBreadcrumb($breadcrumbs[$page]);
}

// Incluir página específica
$pageFile = __DIR__ . '/' . $page . '.php';

if (file_exists($pageFile)) {
    try {
        ob_start();
        include $pageFile;
        $pageContent = ob_get_clean();
        echo $pageContent;
    } catch (Exception $e) {
        addAlert('Erro ao carregar página: ' . $e->getMessage(), 'error');
        echo '<div class="page-container">
            <div class="card">
                <h1>Erro 404</h1>
                <p>A página solicitada não foi encontrada.</p>
                <a href="index.php?page=dashboard" class="btn btn-primary">Voltar ao Dashboard</a>
            </div>
        </div>';
    }
} else {
    addAlert('Página não encontrada: ' . $page, 'error');
    echo '<div class="page-container">
        <div class="card">
            <h1>Erro 404</h1>
            <p>A página solicitada não foi encontrada.</p>
            <a href="index.php?page=dashboard" class="btn btn-primary">Voltar ao Dashboard</a>
        </div>
    </div>';
}

// Finalizar sidebar
endSidebar();
?>
