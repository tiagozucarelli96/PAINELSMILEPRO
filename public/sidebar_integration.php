<?php
// sidebar_integration.php — Sistema de integração da sidebar em todas as páginas

// Função para incluir a sidebar
function includeSidebar() {
    // Verificar se já foi incluída
    if (defined('SIDEBAR_INCLUDED')) {
        return;
    }
    
    define('SIDEBAR_INCLUDED', true);
    
    // Incluir a sidebar macro
    include __DIR__ . '/sidebar_macro.php';
    
    // Capturar o conteúdo da página
    ob_start();
}

// Função para finalizar a sidebar
function endSidebar() {
    if (!defined('SIDEBAR_INCLUDED')) {
        return;
    }
    
    // Obter o conteúdo da página
    $pageContent = ob_get_clean();
    
    // Inserir o conteúdo da página diretamente no main-content
    echo '<div id="mainContent">' . $pageContent . '</div>';
    echo '</div>'; // Fechar main-content
    echo '</div>'; // Fechar app-container
    echo '</body></html>';
}

// Função para incluir CSS adicional da página
function addPageCSS($css) {
    echo '<style>' . $css . '</style>';
}

// Função para incluir JavaScript adicional da página
function addPageJS($js) {
    echo '<script>' . $js . '</script>';
}

// Função para definir título da página
function setPageTitle($title) {
    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            document.title = "' . addslashes($title) . ' - GRUPO Smile EVENTOS";
        });
    </script>';
}

// Função para adicionar breadcrumb
function addBreadcrumb($items) {
    $breadcrumb = '<nav class="breadcrumb" style="padding: 15px 20px; background: white; border-bottom: 1px solid #e5e7eb; margin-bottom: 20px;">
        <ol style="list-style: none; display: flex; gap: 8px; margin: 0; padding: 0;">
    ';
    
    foreach ($items as $index => $item) {
        if ($index > 0) {
            $breadcrumb .= '<li style="color: #6b7280;">›</li>';
        }
        
        if (isset($item['url'])) {
            $breadcrumb .= '<li><a href="' . htmlspecialchars($item['url']) . '" style="color: #3b82f6; text-decoration: none;">' . htmlspecialchars($item['title']) . '</a></li>';
        } else {
            $breadcrumb .= '<li style="color: #374151; font-weight: 500;">' . htmlspecialchars($item['title']) . '</li>';
        }
    }
    
    $breadcrumb .= '</ol></nav>';
    
    echo $breadcrumb;
}

// Função para adicionar alertas
function addAlert($message, $type = 'info') {
    $alertClass = [
        'success' => 'smile-alert-success',
        'error' => 'smile-alert-danger',
        'warning' => 'smile-alert-warning',
        'info' => 'smile-alert-info'
    ][$type] ?? 'smile-alert-info';
    
    echo '<div class="smile-alert ' . $alertClass . '" style="margin: 20px;">
        ' . htmlspecialchars($message) . '
    </div>';
}

// CSS adicional para páginas
$additionalCSS = '
    .smile-alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 500;
    }
    
    .smile-alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .smile-alert-danger {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }
    
    .smile-alert-warning {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    
    .smile-alert-info {
        background: #dbeafe;
        color: #1e40af;
        border: 1px solid #93c5fd;
    }
    
    .page-container {
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .page-header {
        background: white;
        border-radius: 12px;
        padding: 30px;
        margin-bottom: 30px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .page-title {
        font-size: 2rem;
        font-weight: 800;
        color: #1e3a8a;
        margin-bottom: 10px;
    }
    
    .page-subtitle {
        font-size: 1.1rem;
        color: #6b7280;
    }
    
    .card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }
    
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 20px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
        color: white;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
    }
    
    .btn-secondary {
        background: #6b7280;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #4b5563;
    }
    
    .btn-success {
        background: #059669;
        color: white;
    }
    
    .btn-success:hover {
        background: #047857;
    }
    
    .btn-danger {
        background: #dc2626;
        color: white;
    }
    
    .btn-danger:hover {
        background: #b91c1c;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #374151;
    }
    
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .table th {
        background: #f8f9fa;
        color: #555;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 13px;
        padding: 15px;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .table td {
        padding: 15px;
        border-bottom: 1px solid #f0f0f0;
        font-size: 14px;
        color: #444;
    }
    
    .table tbody tr:hover {
        background: #f8fafc;
    }
    
    @media (max-width: 768px) {
        .page-container {
            padding: 10px;
        }
        
        .page-header {
            padding: 20px;
        }
        
        .page-title {
            font-size: 1.5rem;
        }
        
        .table {
            font-size: 12px;
        }
        
        .table th,
        .table td {
            padding: 10px 8px;
        }
    }
';

// Incluir CSS adicional
echo '<style>' . $additionalCSS . '</style>';
?>
