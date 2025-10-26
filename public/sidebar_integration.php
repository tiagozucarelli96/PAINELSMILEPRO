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
        echo "</div><!-- main-content-end -->";
        echo "</body></html>";
    }
}
