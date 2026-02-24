<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/sidebar_integration.php';

includeSidebar('Realizar evento');
?>

<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">Realizar evento</h1>
        <p class="page-subtitle">Tela preparada para a próxima etapa.</p>
    </div>

    <div class="dashboard-grid">
        <div class="dashboard-card">
            <div class="card-content">
                <p>Este módulo foi criado e está pronto para receber as funcionalidades que você definir.</p>
            </div>
        </div>
    </div>
</div>

<?php endSidebar(); ?>
