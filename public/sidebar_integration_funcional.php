<?php
// sidebar_integration_funcional.php — Sistema de integração da sidebar funcional
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function includeSidebarFuncional() {
    if (defined('SIDEBAR_FUNCIONAL_INCLUDED')) {
        return;
    }
    define('SIDEBAR_FUNCIONAL_INCLUDED', true);
    include __DIR__ . '/sidebar_funcional.php';
    ob_start();
}

function endSidebarFuncional() {
    if (!defined('SIDEBAR_FUNCIONAL_INCLUDED')) {
        return;
    }
    $pageContent = ob_get_clean();
    echo '<div id="mainContent">' . $pageContent . '</div>';
    echo '</div>'; // Fechar main-content
    echo '</div>'; // Fechar app-container
    echo '</body></html>';
}

function setPageTitleFuncional($title) {
    echo "<script>document.title = '$title - GRUPO Smile EVENTOS';</script>";
}

function addBreadcrumbFuncional($breadcrumbs) {
    // Implementar breadcrumbs se necessário
}

function addAlertFuncional($message, $type = 'info') {
    echo "<div class='alert alert-$type'>$message</div>";
}

function addPageCSSFuncional($css) {
    echo "<style>$css</style>";
}
