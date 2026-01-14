<?php
declare(strict_types=1);

// ============================================
// CRÍTICO: Verificar webhooks ANTES de iniciar sessão
// ============================================
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';

// Se for requisição ao webhook Asaas, servir DIRETAMENTE sem sessão
if (strpos($request_uri, 'asaas_webhook.php') !== false || 
    strpos($script_name, 'asaas_webhook.php') !== false ||
    (isset($_SERVER['PHP_SELF']) && strpos($_SERVER['PHP_SELF'], 'asaas_webhook.php') !== false)) {
    // Não iniciar sessão para webhooks
    require_once __DIR__ . '/asaas_webhook.php';
    exit;
}

session_start();

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
  $files = array_values(array_diff(scandir(__DIR__), ['.', '..']));
  ?><!doctype html><meta charset="utf-8"><title>Diag</title><body style="font-family:system-ui;background:#0b1220;color:#fff">
  <div style="max-width:860px;margin:40px auto;padding:24px;background:#0f1b35;border-radius:16px">
    <h1>/public</h1><ul><?php foreach ($files as $f): ?><li><code><?=htmlspecialchars($f)?></code></li><?php endforeach; ?></ul>
    <p><a href="/">Voltar</a></p>
  </div></body><?php
  exit;
}

/* Arquivos de cron devem ser servidos diretamente SEM passar pelo sistema de rotas */
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($request_uri, 'cron_') !== false || strpos($request_uri, '/cron_') !== false) {
  // Deixar o router.php ou servidor PHP servir o arquivo diretamente
  return false;
}

/* sem ?page -> manda para login ou dashboard */
// CRÍTICO: Garantir que todos os parâmetros GET sejam preservados
// Parsear QUERY_STRING manualmente para não perder parâmetros extras (como degustacao_id)
if (isset($_SERVER['QUERY_STRING']) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $parsed_query);
    // Mesclar com $_GET para garantir que temos todos os parâmetros
    $_GET = array_merge($parsed_query, $_GET);
}

// CRÍTICO: Verificar link público da contabilidade ANTES de qualquer coisa
// Se o caminho da URL corresponder a um link público da contabilidade, redirecionar para login
$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
if ($request_path !== '/' && !isset($_GET['page']) && !isset($_GET['action'])) {
    try {
        require_once __DIR__ . '/conexao.php';
        $stmt = $GLOBALS['pdo']->prepare("SELECT * FROM contabilidade_acesso WHERE link_publico = :path AND status = 'ativo' LIMIT 1");
        $stmt->execute([':path' => $request_path]);
        $acesso_contabilidade = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($acesso_contabilidade) {
            // Link público da contabilidade encontrado - redirecionar para login
            header('Location: contabilidade_login.php');
            exit;
        }
    } catch (Exception $e) {
        // Se houver erro, continuar processamento normal
        error_log("Erro ao verificar link público da contabilidade: " . $e->getMessage());
    }
}

// CRÍTICO: Processar endpoint de upload de foto ANTES de qualquer coisa
// Este endpoint deve ser servido DIRETAMENTE, sem passar por router ou verificações
if (isset($_GET['page']) && $_GET['page'] === 'upload_foto_usuario_endpoint') {
    $endpoint_file = __DIR__ . '/upload_foto_usuario_endpoint.php';
    if (file_exists($endpoint_file)) {
        require $endpoint_file;
        exit; // Não continuar processamento
    }
}

// IMPORTANTE: Processar requisições AJAX ANTES de qualquer verificação de login/redirecionamento
// Isso permite que endpoints AJAX retornem JSON mesmo quando há problemas de sessão
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$is_ajax_request = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Se for requisição AJAX com action, processar ANTES de tudo
if ((!empty($action) || $is_ajax_request) && !empty($_GET['page'])) {
    // Se for uma requisição AJAX, incluir a página diretamente para processar o action
    $ajax_pages = ['usuarios']; // Páginas que têm endpoints AJAX
    if (in_array($_GET['page'], $ajax_pages)) {
        $ajax_page_file = __DIR__ . '/' . $_GET['page'] . '.php';
        if (file_exists($ajax_page_file)) {
            // Iniciar sessão se necessário (já está iniciada acima, mas garantir)
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Carregar permissões antes de processar
            require_once __DIR__ . '/permissoes_boot.php';
            
            // Incluir o arquivo que processará o action e fará exit
            require $ajax_page_file;
            exit; // Não continuar processamento normal
        }
    }
}

$page = $_GET['page'] ?? '';
if ($page === '' || $page === null) {
  if (!empty($_SESSION['logado'])) {
    // Verificar push para usuários internos (antes de carregar qualquer página)
    require_once __DIR__ . '/permissoes_boot.php';
    $is_admin = !empty($_SESSION['perm_administrativo']);
    $is_internal = $is_admin || !empty($_SESSION['perm_agenda']) || !empty($_SESSION['perm_demandas']) || 
                   !empty($_SESSION['perm_financeiro']); // REMOVIDO: perm_logistico
    
    if ($is_internal && $page !== 'push_block_screen') {
      try {
        require_once __DIR__ . '/conexao.php';
        $stmt = $GLOBALS['pdo']->prepare("
          SELECT COUNT(*) 
          FROM sistema_notificacoes_navegador 
          WHERE usuario_id = :usuario_id 
          AND consentimento_permitido = TRUE 
          AND ativo = TRUE
        ");
        $stmt->execute([':usuario_id' => $_SESSION['id']]);
        $hasConsent = $stmt->fetchColumn() > 0;
        
        if (!$hasConsent) {
          header('Location: push_block_screen.php');
          exit;
        }
      } catch (Exception $e) {
        error_log("Erro ao verificar push: " . $e->getMessage());
      }
    }
    
    header('Location: index.php?page=dashboard');
  } else {
    header('Location: index.php?page=login');
  }
  exit;
}

/* ===== MAPA DE ROTAS UNIFICADO ===== */
$routes = [
  // Dashboard e blocos
  'dashboard' => 'sidebar_unified.php',
  'comercial' => 'comercial_landing.php',
  // Logística (placeholders)
  'logistica' => 'logistica.php',
  'logistica_operacional' => 'logistica_operacional.php',
  'logistica_divergencias' => 'logistica_divergencias.php',
  'logistica_financeiro' => 'logistica_financeiro.php',
  // 'logistico' => 'lc_index.php', // REMOVIDO: Módulo desativado
  'configuracoes' => 'configuracoes.php',
  'cadastros' => 'cadastros.php',
  'financeiro' => 'financeiro.php',
  'administrativo' => 'administrativo.php',
  'contabilidade' => 'contabilidade.php',
  'contabilidade_admin_guias' => 'contabilidade_admin_guias.php',
  'contabilidade_admin_holerites' => 'contabilidade_admin_holerites.php',
  'contabilidade_admin_honorarios' => 'contabilidade_admin_honorarios.php',
  'contabilidade_admin_conversas' => 'contabilidade_admin_conversas.php',
  'contabilidade_admin_colaboradores' => 'contabilidade_admin_colaboradores.php',
  'config_email_global' => 'config_email_global.php',
  'config_logistica' => 'config_logistica.php',
  'logistica_catalogo' => 'logistica_catalogo.php',
  'logistica_conexao' => 'logistica_conexao.php',
  'logistica_unidades_medida' => 'logistica_unidades_medida.php',
  'logistica_tipologias' => 'logistica_tipologias.php',
  'logistica_insumos' => 'logistica_insumos.php',
  'logistica_receitas' => 'logistica_receitas.php',
  'logistica_gerar_lista' => 'logistica_gerar_lista.php',
  'logistica_listas' => 'logistica_listas.php',
  'logistica_lista_pdf' => 'logistica_lista_pdf.php',
  'logistica_estoque' => 'logistica_estoque.php',
  'logistica_contagem' => 'logistica_contagem.php',
  'logistica_entrada' => 'logistica_entrada.php',
  'logistica_transferencias' => 'logistica_transferencias.php',
  'logistica_transferencia_ver' => 'logistica_transferencia_ver.php',
  'logistica_transferencia_receber' => 'logistica_transferencia_receber.php',
  'logistica_saldo' => 'logistica_saldo.php',
  'logistica_upload' => 'logistica_upload.php',
  'google_calendar_config' => 'google_calendar_config.php',
  'google_calendar_debug' => 'google_calendar_debug.php',
  'agenda' => 'agenda.php',
  'demandas' => 'demandas_trello.php',
  'demandas_old' => 'demandas.php', // Versão antiga mantida
    'demandas_fixas' => 'demandas_fixas_ui.php',
    'test_magalu_buckets' => 'test_magalu_buckets.php',
  'diagnostico_webhook_eventos' => 'diagnostico_webhook_eventos.php',
  'apply_webhook_schema' => 'apply_webhook_schema.php',
  'webhook_logs' => 'webhook_logs_viewer.php',
  'asaas_webhook_logs' => 'asaas_webhook_logs_viewer.php',
  'create_asaas_webhook_table' => 'create_asaas_webhook_table.php',
  'test_asaas_checkout' => 'test_asaas_checkout.php',

  // Comercial
  'comercial_degustacoes' => 'comercial_degustacoes.php',
  'comercial_inscritos' => 'comercial_degust_inscritos.php',
  'fix_token_publico_degustacoes' => 'fix_token_publico_degustacoes.php',
  'exec_fix_token_publico' => 'exec_fix_token_publico.php',
  'comercial_inscritos_todos' => 'comercial_degust_inscritos.php',
  'comercial_degust_inscritos' => 'comercial_degust_inscritos.php',
  'comercial_degust_inscricoes' => 'comercial_degust_inscricoes.php',
  'comercial_degustacao_editar' => 'comercial_degustacao_editar.php',
  'comercial_degust_public' => 'comercial_degust_public.php',
  'comercial_clientes' => 'comercial_clientes.php',
  'comercial_pagamento' => 'comercial_pagamento.php',
  'comercial_inscritos_cadastrados' => 'comercial_inscritos_cadastrados.php',
  'comercial_lista_espera' => 'comercial_lista_espera.php',
  'comercial_realizar_degustacao' => 'comercial_realizar_degustacao.php',
  'comercial_realizar_degustacao_direto' => 'comercial_realizar_degustacao_direto.php', // VERSÃO TESTE - bypass router
  'comercial_realizar_degustacao_ultra_simples' => 'comercial_realizar_degustacao_ultra_simples.php', // VERSÃO ULTRA SIMPLES

  // Logístico - REMOVIDO: Módulo desativado
  // 'lc_index' => 'lc_index.php',
  // 'lista' => 'lista_compras.php',
  // 'lista_compras' => 'lista_compras.php',
  // 'lc_ver' => 'ver.php',
  // 'lc_pdf' => 'lc_pdf.php',
  // 'estoque' => 'estoque_logistico.php',
  // 'estoque_logistico' => 'estoque_logistico.php',
  // 'estoque_kardex' => 'estoque_kardex.php',
  // 'kardex' => 'estoque_kardex.php',
  // 'estoque_contagens' => 'estoque_contagens.php',
  // 'contagens' => 'estoque_contagens.php',
  // 'estoque_alertas' => 'estoque_alertas.php',
  // 'alertas' => 'estoque_alertas.php',

  // Cadastros / Configurações
  // 'config_usuarios' => 'usuarios.php', // REMOVIDO: tela antiga de usuários
  'usuarios' => 'usuarios_new.php',
  'apply_usuarios_schema' => 'apply_usuarios_schema.php',
  'limpar_e_recriar_permissoes' => 'limpar_e_recriar_permissoes.php',
  // 'usuario_novo' => 'usuario_novo.php', // REMOVIDO: tela antiga de usuários
  // 'usuario_editar' => 'usuario_editar.php', // REMOVIDO: tela antiga de usuários
  // REMOVIDO: Módulo desativado
  // 'config_insumos' => 'config_insumos.php',
  // 'config_categorias' => 'config_categorias.php',
  // 'config_fichas' => 'config_fichas.php',
  // 'config_itens' => 'config_itens.php',
  // 'config_itens_fixos' => 'config_itens_fixos.php',
  'config_sistema' => 'configuracoes.php',

  // Financeiro
  // Módulo de pagamentos removido

  // Administrativo
  'administrativo_auditoria' => 'verificacao_completa_erros.php',
  'administrativo_stats' => 'sistema_unificado.php',
  
  // Scripts de migração/setup
  'apply_permissoes_sidebar_columns' => 'apply_permissoes_sidebar_columns.php',
  'habilitar_todas_permissoes' => 'habilitar_todas_permissoes.php',

  // Agenda
  'agenda_config' => 'agenda_config.php',
  'agenda_relatorios' => 'agenda_relatorios.php',

  // Outros
  'ver' => 'ver.php',
  'portao' => 'portao.php',
  'banco_smile' => 'banco_smile_landing.php',
  'banco_smile_main' => 'banco_smile.php',
  'banco_smile_admin' => 'banco_smile_admin.php',
  'rh' => 'rh.php',
  'rh_dashboard' => 'rh_dashboard.php',
  'rh_colaboradores' => 'rh_colaboradores.php',
  'rh_colaborador_ver' => 'rh_colaborador_ver.php',
  'rh_holerite_upload' => 'rh_holerite_upload.php',
  'notas_fiscais' => 'notas_fiscais.php',
  'dados_contrato' => 'dados_contrato.php',
  'uso_fiorino' => 'uso_fiorino.php',
  'webhook_me_eventos' => 'webhook_me_eventos.php',
  'test_asaas_key' => 'test_asaas_key.php',
  'test_asaas_debug' => 'test_asaas_debug.php',
  'comparar_chave_asaas' => 'comparar_chave_asaas.php',
  'forcar_chave_asaas' => 'forcar_chave_asaas.php',
  'verificar_inscricao_checkout' => 'verificar_inscricao_checkout.php',
  'verificar_webhook_qrcode' => 'verificar_webhook_qrcode.php',
  'verificar_pagamento_1real' => 'verificar_pagamento_1real.php',
  'test_qrcode_asaas' => 'test_qrcode_asaas.php',
  'testar_identificacao_pagamento' => 'testar_identificacao_pagamento.php',
  'login' => 'login.php', // Rota de login
  'push_block_screen' => 'push_block_screen.php', // Tela de bloqueio push
  'debug_email_send' => 'debug_email_send.php', // Diagnóstico de e-mail
  'test_magalu_contabilidade' => 'test_magalu_contabilidade.php', // Teste de configuração Magalu para Contabilidade
  'executar_add_chave_storage' => 'executar_add_chave_storage.php', // Adicionar coluna chave_storage na contabilidade
  
  // Testes e Diagnósticos (úteis mantidos)
  // Rotas de teste removidas - manter apenas diagnósticos essenciais
];

/* exige login - EXCETO para páginas públicas */
$public_pages = [
  'comercial_degust_public', 
  'asaas_webhook', 
  'webhook_me_eventos', 
  'login',
  // Versões de teste que bypassam router
  'comercial_realizar_degustacao_direto',
  'comercial_realizar_degustacao_ultra_simples',
];
$is_public_page = in_array($page, $public_pages);

// Debug: verificar se rota existe antes de verificar login
if (getenv('APP_DEBUG') === '1' && !empty($page) && !isset($routes[$page])) {
  error_log("⚠️ Rota não encontrada: '$page'. Rotas disponíveis: " . implode(', ', array_keys($routes)));
}

if (empty($_SESSION['logado']) && !$is_public_page) {
  // Se não tem rota definida, usar login.php direto
  if (empty($routes[$page]) && file_exists(__DIR__ . '/login.php')) {
    require __DIR__ . '/login.php';
    exit;
  }
  header('Location: index.php?page=login');
  exit;
}

/* >>> popula as permissões da sessão sem mexer no login */
require __DIR__ . '/permissoes_boot.php';

/* >>> verifica permissões antes de incluir a página */
require_once __DIR__ . '/permissoes_map.php';
$permissoes_map = require __DIR__ . '/permissoes_map.php';

// Verificar se a página requer permissão
$required_permission = $permissoes_map[$page] ?? null;

$is_superadmin = !empty($_SESSION['perm_superadmin']);
if ($required_permission !== null && !$is_superadmin && (empty($_SESSION[$required_permission]) || !$_SESSION[$required_permission])) {
    // Usuário não tem permissão - exibir mensagem
    http_response_code(403);
    
    // Se for uma requisição AJAX, retornar JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => true,
            'message' => 'Você não tem permissão para acessar esta função.'
        ]);
        exit;
    }
    
    // Se não for AJAX, mostrar página de erro
    require_once __DIR__ . '/core/helpers.php';
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acesso Negado - Sistema Smile</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .error-container {
                background: white;
                border-radius: 16px;
                padding: 3rem;
                max-width: 500px;
                width: 100%;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                text-align: center;
            }
            
            .error-icon {
                font-size: 4rem;
                margin-bottom: 1.5rem;
            }
            
            .error-title {
                font-size: 1.75rem;
                font-weight: 700;
                color: #1e293b;
                margin-bottom: 1rem;
            }
            
            .error-message {
                color: #64748b;
                font-size: 1rem;
                margin-bottom: 2rem;
                line-height: 1.6;
            }
            
            .btn-back {
                display: inline-block;
                background: #1e3a8a;
                color: white;
                padding: 0.75rem 2rem;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s ease;
            }
            
            .btn-back:hover {
                background: #2563eb;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">🚫</div>
            <h1 class="error-title">Acesso Negado</h1>
            <p class="error-message">
                Você não tem permissão para acessar esta função.
            </p>
            <a href="index.php?page=dashboard" class="btn-back">Voltar para Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* resolve e inclui a página */
$file = $routes[$page] ?? null;
$path = $file ? (__DIR__.'/'.$file) : null;

// Debug: log quando rota não encontrada
if (!$file && $page) {
  error_log("⚠️ Rota não encontrada: page='$page'");
  if (getenv('APP_DEBUG') === '1') {
    error_log("Rotas disponíveis: " . implode(', ', array_keys($routes)));
  }
  // Se rota não existe, redirecionar para dashboard ao invés de 404
  header('Location: index.php?page=dashboard&error=route_not_found');
  exit;
}

if ($path && is_file($path)) {
  require $path;
  exit;
} else if ($path) {
  error_log("⚠️ Arquivo da rota não encontrado: $path para page='$page'");
  header('Location: index.php?page=dashboard&error=file_not_found');
  exit;
}

/* 404 simples */
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 - Rota não encontrada para '".htmlspecialchars($page, ENT_QUOTES, 'UTF-8')."'";
if (getenv('APP_DEBUG') === '1') {
  echo "\n\nRotas disponíveis: " . implode(', ', array_keys($routes));
}
