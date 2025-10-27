<?php
// sidebar_integration.php — Integrador de layout unificado

if (!function_exists('includeSidebar')) {
    function includeSidebar(string $pageTitle = ''): void {
        require_once __DIR__ . '/sidebar_unified.php';
        if ($pageTitle) {
            echo "<script>document.title = " . json_encode($pageTitle) . ";</script>";
        }
    }
}

if (!function_exists('endSidebar')) {
    function endSidebar(): void {
        global $current_page;
        
        // Se for página especial, as divs já foram fechadas no sidebar_unified.php
        if (!in_array($current_page, ['dashboard', 'comercial', 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo'])) {
            echo '</div>'; // fecha #pageContent se ainda estiver aberto
            echo '</div>'; // fecha .main-content se ainda estiver aberto
        }
        
        echo "</body></html>";
    }
}
