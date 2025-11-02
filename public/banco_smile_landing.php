<?php
// banco_smile_landing.php - Landing page do módulo Banco Smile
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';

// Verificar permissões
if (empty($_SESSION['logado'])) {
    header('Location: index.php?page=login');
    exit;
}

// Suprimir warnings durante renderização
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', 0);

// Criar conteúdo da página usando output buffering
ob_start();
?>

<style>
        .page-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0 0 0.5rem 0;
        }
        
        .page-header p {
            font-size: 1.125rem;
            color: #64748b;
            margin: 0;
        }
        
        .funcionalidades-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }
        
        .funcionalidade-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .funcionalidade-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
            border-color: #1e3a8a;
        }
        
        .funcionalidade-card-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .funcionalidade-card h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e3a8a;
            margin: 0 0 0.5rem 0;
        }
        
        .funcionalidade-card p {
            color: #64748b;
            font-size: 0.875rem;
            margin: 0 0 1rem 0;
            flex: 1;
        }
        
        .funcionalidade-card .btn-link {
            background: #1e3a8a;
            color: white;
            padding: 0.625rem 1.25rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            display: inline-block;
            transition: all 0.3s ease;
            text-align: center;
            width: 100%;
            margin-top: auto;
        }
        
        .funcionalidade-card .btn-link:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(30, 58, 138, 0.2);
        }
    </style>

<div style="max-width: 1400px; margin: 0 auto; padding: 1.5rem;">
        <div class="page-header">
            <h1>🏦 Banco Smile</h1>
            <p>Gestão financeira e controle bancário</p>
        </div>
        
        <div class="funcionalidades-grid">
            <div class="funcionalidade-card">
                <div class="funcionalidade-card-icon">💳</div>
                <h3>Acesso ao Banco</h3>
                <p>Acesse as funcionalidades do banco Smile</p>
                <a href="index.php?page=banco_smile_main" class="btn-link">Acessar Banco</a>
            </div>
            
            <?php if (!empty($_SESSION['perm_banco_smile_admin'])): ?>
            <div class="funcionalidade-card">
                <div class="funcionalidade-card-icon">⚙️</div>
                <h3>Administração</h3>
                <p>Painel administrativo do banco Smile</p>
                <a href="index.php?page=banco_smile_admin" class="btn-link">Acessar Admin</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

<?php
// Restaurar error_reporting antes de incluir sidebar
error_reporting(E_ALL);
@ini_set('display_errors', 0);

$conteudo = ob_get_clean();

includeSidebar('Banco Smile');
echo $conteudo;
endSidebar();
?>

