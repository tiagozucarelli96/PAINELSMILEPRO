<?php
/**
 * demandas_trello.php
 * Interface estilo Trello para sistema de Demandas
 * REFATORADO: UI completa com sidebar interna, drawer de fixas, notificações aprimoradas
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    header('Location: login.php');
    exit;
}

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 1);
$is_admin = isset($_SESSION['permissao']) && strpos($_SESSION['permissao'], 'admin') !== false;

// Buscar usuários para menções e atribuições
$stmt = $pdo->prepare("SELECT id, nome, email FROM usuarios ORDER BY nome");
$stmt->execute();
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar quadros disponíveis para sidebar
$board_id = isset($_GET['board_id']) ? (int)$_GET['board_id'] : null;

includeSidebar('Demandas');
?>

<style>
/* ============================================
   SCOPO: Página Demandas (.page-demandas)
   ============================================ */
.page-demandas {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f4f5f7;
    color: #172b4d;
    height: calc(100vh - 60px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    --page-header-height: 72px;
    --board-header-height: 56px;
    --list-width: 360px;
    --list-gap: 1.25rem;
    --card-gap: 0.75rem;
    --card-padding: 0.9rem;
    --card-radius: 10px;
}

/* ============================================
   HEADER LIMPO
   ============================================ */
.page-demandas-header {
    background: white;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    z-index: 100;
    position: sticky;
    top: 0;
}

.page-demandas-header h1 {
    font-size: 1.5rem;
    color: #172b4d;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.page-demandas-header-actions {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}

.btn {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    font-size: 0.875rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-outline {
    background: white;
    color: #3b82f6;
    border: 1px solid #3b82f6;
}

.btn-outline:hover {
    background: #eff6ff;
}

.btn-icon {
    background: transparent;
    border: none;
    padding: 0.5rem;
    cursor: pointer;
    border-radius: 6px;
    color: #6b7280;
    font-size: 1.25rem;
    transition: all 0.2s;
}

.btn-icon:hover {
    background: #f3f4f6;
    color: #374151;
}

.notificacoes-badge {
    position: relative;
    cursor: pointer;
}

.notificacoes-count {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ef4444;
    color: white;
    border-radius: 10px;
    padding: 2px 6px;
    font-size: 0.7rem;
    font-weight: 600;
    min-width: 18px;
    text-align: center;
    border: 2px solid white;
}

/* ============================================
   LAYOUT PRINCIPAL (Sidebar + Board)
   ============================================ */
.page-demandas-main {
    flex: 1;
    display: flex;
    overflow: hidden;
    position: relative;
}

/* ============================================
   SIDEBAR INTERNA DE NAVEGAÇÃO
   ============================================ */
.page-demandas-sidebar {
    width: 260px;
    background: white;
    border-right: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex-shrink: 0;
    transition: transform 0.3s ease;
}

.page-demandas-sidebar-header {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.page-demandas-sidebar-search {
    width: 100%;
    padding: 0.625rem 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.875rem;
    outline: none;
    transition: border-color 0.2s;
}

.page-demandas-sidebar-search:focus {
    border-color: #3b82f6;
}

.page-demandas-sidebar-content {
    flex: 1;
    overflow-y: auto;
    padding: 0.75rem;
}

.page-demandas-sidebar-section {
    margin-bottom: 1.5rem;
}

.page-demandas-sidebar-section-title {
    font-size: 0.75rem;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.75rem;
    padding: 0 0.5rem;
}

.page-demandas-board-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.625rem 0.75rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 0.25rem;
    position: relative;
}

.page-demandas-board-item:hover {
    background: #f9fafb;
}

.page-demandas-board-item.active {
    background: #eff6ff;
    color: #3b82f6;
    font-weight: 500;
}

.page-demandas-board-item-color {
    width: 12px;
    height: 12px;
    border-radius: 2px;
    flex-shrink: 0;
}

.page-demandas-board-item-name {
    flex: 1;
    font-size: 0.875rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.page-demandas-board-item-star {
    color: #fbbf24;
    font-size: 0.875rem;
    opacity: 0;
    transition: opacity 0.2s;
}

.page-demandas-board-item:hover .page-demandas-board-item-star {
    opacity: 1;
}

.page-demandas-board-item.favorito .page-demandas-board-item-star {
    opacity: 1;
}

.page-demandas-sidebar-footer {
    padding: 1rem;
    border-top: 1px solid #e5e7eb;
}

.page-demandas-sidebar-footer .btn {
    width: 100%;
    justify-content: center;
}

/* Drawer mobile */
.page-demandas-sidebar.drawer {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 1000;
    box-shadow: 4px 0 12px rgba(0,0,0,0.15);
    transform: translateX(-100%);
}

.page-demandas-sidebar.drawer.open {
    transform: translateX(0);
}

@media (max-width: 1279px) {
    .page-demandas-sidebar {
        position: fixed;
        left: 0;
        top: 0;
        bottom: 0;
        z-index: 1000;
        box-shadow: 4px 0 12px rgba(0,0,0,0.15);
        transform: translateX(-100%);
    }
    
    .page-demandas-sidebar.open {
        transform: translateX(0);
    }
    
    .page-demandas-main-content {
        width: 100%;
    }
}

/* ============================================
   ÁREA DE BOARD (Colunas Kanban)
   ============================================ */
.page-demandas-main-content {
    flex: 1;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    background: #f4f5f7;
}

.page-demandas-board-header {
    padding: 0.9rem 1.5rem;
    background: white;
    border-bottom: 1px solid #e5e7eb;
    flex-shrink: 0;
    position: sticky;
    top: var(--page-header-height);
    z-index: 90;
}

.page-demandas-board-header-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #172b4d;
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.page-demandas-board-header-color-bar {
    height: 4px;
    border-radius: 2px;
    min-width: 60px;
}

.page-demandas-board-container {
    flex: 1;
    overflow-x: auto;
    overflow-y: hidden;
    padding: 1.5rem 1.75rem 2rem;
    display: flex;
    gap: var(--list-gap);
    align-items: flex-start;
    scroll-snap-type: x proximity;
    scrollbar-gutter: stable both-edges;
}

.page-demandas-list {
    background: #ebecf0;
    border-radius: 10px;
    padding: 0.85rem;
    min-width: var(--list-width);
    max-width: var(--list-width);
    width: var(--list-width);
    display: flex;
    flex-direction: column;
    min-height: 70vh;
    max-height: calc(100vh - 200px);
    scroll-snap-align: start;
}

.page-demandas-list-header {
    font-weight: 600;
    padding: 0.85rem 0.75rem;
    margin-bottom: 0.5rem;
    color: #172b4d;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.page-demandas-list-count {
    background: rgba(0,0,0,0.1);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
}

.page-demandas-list-cards {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    min-height: 200px;
    padding-right: 4px;
    display: flex;
    flex-direction: column;
    gap: var(--card-gap);
}

.page-demandas-list-cards::-webkit-scrollbar {
    width: 8px;
}

.page-demandas-list-cards::-webkit-scrollbar-track {
    background: transparent;
}

.page-demandas-list-cards::-webkit-scrollbar-thumb {
    background: rgba(0,0,0,0.2);
    border-radius: 4px;
}

.page-demandas-list-cards::-webkit-scrollbar-thumb:hover {
    background: rgba(0,0,0,0.3);
}

.page-demandas-list-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: #6b7280;
    font-size: 0.875rem;
}

.page-demandas-card {
    background: white;
    border-radius: var(--card-radius);
    padding: var(--card-padding);
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.08);
    transition: all 0.2s;
    position: relative;
}

.page-demandas-card:hover {
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
    transform: translateY(-1px);
}

.page-demandas-card-preview {
    width: 100%;
    height: 150px;
    overflow: hidden;
    border-radius: 4px 4px 0 0;
    margin: -0.75rem -0.75rem 0.5rem -0.75rem;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
}

.page-demandas-card-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: cover;
    cursor: pointer;
}

.page-demandas-card-title {
    font-weight: 500;
    margin-bottom: 0.5rem;
    color: #172b4d;
    font-size: 0.875rem;
    line-height: 1.35;
}

.page-demandas-card-badges {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
    flex-wrap: wrap;
}

.page-demandas-card-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.75rem;
    color: #6b7280;
    margin-top: 0.5rem;
}

.page-demandas-card-users {
    display: flex;
    gap: 0.25rem;
}

.page-demandas-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #3b82f6;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: 600;
}

.page-demandas-add-card-btn {
    background: transparent;
    border: none;
    color: #6b7280;
    padding: 0.75rem;
    width: 100%;
    text-align: left;
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.2s;
    margin-top: 0.5rem;
    flex-shrink: 0;
}

.main-content.expanded .page-demandas-header,
.main-content.expanded .page-demandas-board-header {
    padding-left: 4.25rem;
}

.page-demandas.density-compact {
    --list-width: 320px;
    --list-gap: 1rem;
    --card-gap: 0.5rem;
    --card-padding: 0.7rem;
    --card-radius: 8px;
}

.page-demandas-add-card-btn:hover {
    background: rgba(0,0,0,0.05);
}

/* Badges */
.badge {
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 500;
}

.badge-prioridade {
    color: white;
    font-weight: 600;
}

.prioridade-baixa { background: #10b981; }
.prioridade-media { background: #3b82f6; }
.prioridade-alta { background: #f59e0b; }
.prioridade-urgente { background: #ef4444; }

.badge-comentarios {
    background: #eff6ff;
    color: #1e40af;
}

.badge-anexos {
    background: #f0f9ff;
    color: #0369a1;
}

/* ============================================
   DRAWER DE DEMANDAS FIXAS
   ============================================ */
.page-demandas-drawer-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 2000;
    display: none;
    animation: fadeIn 0.2s;
}

.page-demandas-drawer-overlay.open {
    display: block;
}

.page-demandas-drawer {
    position: fixed;
    right: 0;
    top: 0;
    bottom: 0;
    width: 600px;
    max-width: 90vw;
    background: white;
    box-shadow: -4px 0 12px rgba(0,0,0,0.15);
    z-index: 2001;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.page-demandas-drawer.open {
    transform: translateX(0);
}

.page-demandas-drawer-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.page-demandas-drawer-content {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
}

.page-demandas-drawer-close {
    background: transparent;
    border: none;
    font-size: 1.5rem;
    color: #6b7280;
    cursor: pointer;
    padding: 0.25rem;
    line-height: 1;
}

/* ============================================
   NOTIFICAÇÕES DROPDOWN
   ============================================ */
.page-demandas-notificacoes-dropdown {
    position: fixed;
    top: 70px;
    right: 1rem;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    min-width: 380px;
    max-width: 90vw;
    max-height: 500px;
    overflow: hidden;
    z-index: 2000;
    display: none;
    animation: slideDown 0.2s;
}

.page-demandas-notificacoes-dropdown.open {
    display: block;
}

.page-demandas-notificacoes-header {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.page-demandas-notificacoes-tabs {
    display: flex;
    gap: 0.5rem;
    border-bottom: 1px solid #e5e7eb;
    padding: 0 1rem;
}

.page-demandas-notificacoes-tab {
    padding: 0.75rem 1rem;
    background: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    font-size: 0.875rem;
    color: #6b7280;
    transition: all 0.2s;
    margin-bottom: -1px;
}

.page-demandas-notificacoes-tab.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
    font-weight: 500;
}

.page-demandas-notificacoes-content {
    max-height: 400px;
    overflow-y: auto;
    padding: 0.5rem;
}

.page-demandas-notificacoes-group {
    margin-bottom: 1rem;
}

.page-demandas-notificacoes-group-title {
    font-size: 0.75rem;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    padding: 0.5rem 1rem;
}

.page-demandas-notificacao-item {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #f3f4f6;
    cursor: pointer;
    transition: background 0.2s;
}

.page-demandas-notificacao-item:hover {
    background: #f9fafb;
}

.page-demandas-notificacao-item.nao-lida {
    background: #eff6ff;
}

.page-demandas-notificacao-titulo {
    font-weight: 500;
    margin-bottom: 0.25rem;
    color: #172b4d;
    font-size: 0.875rem;
}

.page-demandas-notificacao-trecho {
    font-size: 0.75rem;
    color: #6b7280;
    margin-bottom: 0.25rem;
}

.page-demandas-notificacao-meta {
    font-size: 0.7rem;
    color: #9ca3af;
    display: flex;
    justify-content: space-between;
}

/* ============================================
   MODAIS
   ============================================ */
.page-demandas-modal {
    display: none;
    position: fixed;
    z-index: 3000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    overflow-y: auto;
    animation: fadeIn 0.2s;
}

.page-demandas-modal.open {
    display: flex;
    align-items: center;
    justify-content: center;
}

.page-demandas-modal-content {
    background: white;
    margin: 2rem auto;
    padding: 2rem;
    border-radius: 12px;
    max-width: 700px;
    width: 90%;
    box-shadow: 0 20px 25px rgba(0,0,0,0.15);
    animation: slideUp 0.3s;
    max-height: 90vh;
    overflow-y: auto;
}

.page-demandas-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.page-demandas-modal-header h2 {
    font-size: 1.5rem;
    color: #172b4d;
    margin: 0;
}

.page-demandas-modal-close {
    font-size: 2rem;
    font-weight: 300;
    color: #6b7280;
    cursor: pointer;
    line-height: 1;
    background: transparent;
    border: none;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.page-demandas-form-group {
    margin-bottom: 1rem;
}

.page-demandas-form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #374151;
    font-size: 0.875rem;
}

.page-demandas-form-group input,
.page-demandas-form-group textarea,
.page-demandas-form-group select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.875rem;
    font-family: inherit;
    outline: none;
    transition: border-color 0.2s;
}

.page-demandas-form-group input:focus,
.page-demandas-form-group textarea:focus,
.page-demandas-form-group select:focus {
    border-color: #3b82f6;
}

.page-demandas-form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.page-demandas-color-picker-wrapper {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.page-demandas-color-picker-preview {
    width: 40px;
    height: 40px;
    border-radius: 6px;
    border: 2px solid #e5e7eb;
    cursor: pointer;
}

.page-demandas-color-picker-input {
    flex: 1;
    padding: 0.75rem !important;
}

.page-demandas-color-hex {
    font-size: 0.75rem;
    color: #6b7280;
    font-family: monospace;
}

/* ============================================
   SKELETON LOADING
   ============================================ */
.page-demandas-skeleton {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: skeleton-loading 1.5s infinite;
    border-radius: 4px;
}

.page-demandas-skeleton-card {
    height: 120px;
    margin-bottom: 0.5rem;
}

.page-demandas-skeleton-list {
    height: 40px;
    margin-bottom: 1rem;
}

@keyframes skeleton-loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ============================================
   TOAST
   ============================================ */
.page-demandas-toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #10b981;
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10000;
    animation: slideInRight 0.3s;
    max-width: 400px;
}

.page-demandas-toast.error {
    background: #ef4444;
}

.page-demandas-toast.warning {
    background: #f59e0b;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* ============================================
   ANIMAÇÕES
   ============================================ */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* ============================================
   RESPONSIVIDADE
   ============================================ */
@media (min-width: 1440px) {
    .page-demandas {
        --list-width: 380px;
    }
}

@media (max-width: 1439px) and (min-width: 1280px) {
    .page-demandas {
        --list-width: 350px;
    }
}

@media (max-width: 1279px) and (min-width: 1024px) {
    .page-demandas {
        --list-width: 320px;
    }
}

@media (max-width: 1023px) {
    .page-demandas {
        --list-width: 280px;
        --page-header-height: 64px;
    }
}

/* ============================================
   CUSTOM ALERTS (reutilizado)
   ============================================ */
.custom-alert-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    animation: fadeIn 0.2s;
}

.custom-alert {
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    padding: 0;
    max-width: 400px;
    width: 90%;
    animation: slideUp 0.3s;
    overflow: hidden;
}

.custom-alert-header {
    padding: 1.5rem;
    background: #3b82f6;
    color: white;
    font-weight: 600;
    font-size: 1.1rem;
}

.custom-alert-body {
    padding: 1.5rem;
    color: #374151;
    line-height: 1.6;
}

.custom-alert-actions {
    padding: 1rem 1.5rem;
    display: flex;
    gap: 0.75rem;
    justify-content: flex-end;
    border-top: 1px solid #e5e7eb;
}

.custom-alert-btn {
    padding: 0.625rem 1.25rem;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    font-size: 0.875rem;
}

.custom-alert-btn-primary {
    background: #3b82f6;
    color: white;
}

.custom-alert-btn-primary:hover {
    background: #2563eb;
}

.custom-alert-btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.custom-alert-btn-secondary:hover {
    background: #e5e7eb;
}

.custom-alert-btn-danger {
    background: #ef4444;
    color: white;
}

.custom-alert-btn-danger:hover {
    background: #dc2626;
}
</style>

<div class="page-demandas">
    <!-- Header Limpo -->
    <div class="page-demandas-header">
        <h1>📋 Demandas</h1>
        <div class="page-demandas-header-actions">
            <button class="btn btn-primary" onclick="abrirModalNovoCard()">➕ Novo Card</button>
            <button class="btn btn-outline" onclick="abrirModalNovaLista()">📋 Nova Lista</button>
            <button class="btn btn-outline" onclick="toggleDrawerFixas()">📅 Demandas Fixas</button>
            <button class="btn btn-outline" id="btn-density-toggle" type="button">↔ Modo compacto</button>
            <button class="btn-icon" onclick="toggleMenuActions()" aria-label="Mais ações">⋯</button>
        </div>
    </div>
    
    <!-- Layout Principal -->
    <div class="page-demandas-main">
        <!-- Sidebar Interna -->
        <div id="sidebar-boards" class="page-demandas-sidebar">
            <div class="page-demandas-sidebar-header">
                <input type="text" 
                       id="search-boards" 
                       class="page-demandas-sidebar-search" 
                       placeholder="🔍 Buscar quadros..."
                       oninput="filtrarQuadros(this.value)">
            </div>
            <div class="page-demandas-sidebar-content">
                <div class="page-demandas-sidebar-section">
                    <div class="page-demandas-sidebar-section-title">⭐ Favoritos</div>
                    <div id="boards-favoritos"></div>
                </div>
                <div class="page-demandas-sidebar-section">
                    <div class="page-demandas-sidebar-section-title">Todos os Quadros</div>
                    <div id="boards-todos"></div>
                </div>
            </div>
            <div class="page-demandas-sidebar-footer">
                <button class="btn btn-primary" onclick="abrirModalNovoQuadro()">➕ Novo Quadro</button>
            </div>
        </div>
        
        <!-- Área de Board -->
        <div class="page-demandas-main-content">
            <div class="page-demandas-board-header" id="board-header">
                <div class="page-demandas-board-header-title">
                    <span id="board-header-color-bar" class="page-demandas-board-header-color-bar"></span>
                    <span id="board-header-title">Selecione um quadro</span>
                </div>
            </div>
            <div class="page-demandas-board-container" id="trello-board">
                <div class="page-demandas-list-empty">Selecione ou crie um quadro para começar</div>
            </div>
        </div>
    </div>
    
    <!-- Botão para abrir sidebar no mobile -->
    <button class="btn btn-outline" 
            id="btn-toggle-sidebar"
            style="display: none; position: fixed; top: calc(var(--page-header-height) + 12px); left: 1.5rem; z-index: 1200;"
            onclick="toggleSidebarBoards()">
        📋 Quadros
    </button>
</div>

<!-- Drawer de Demandas Fixas -->
<div id="drawer-fixas-overlay" class="page-demandas-drawer-overlay" onclick="toggleDrawerFixas()"></div>
<div id="drawer-fixas" class="page-demandas-drawer">
    <div class="page-demandas-drawer-header">
        <h2 style="margin: 0; font-size: 1.25rem;">📅 Demandas Fixas</h2>
        <button class="page-demandas-drawer-close" onclick="toggleDrawerFixas()">&times;</button>
    </div>
    <div class="page-demandas-drawer-content" id="drawer-fixas-content">
        <!-- Preenchido via JS -->
    </div>
</div>

<!-- Modal: Nova/Editar Demanda Fixa -->
<div id="modal-fixa" class="page-demandas-modal">
    <div class="page-demandas-modal-content">
        <div class="page-demandas-modal-header">
            <h2 id="modal-fixa-titulo">Nova Demanda Fixa</h2>
            <button class="page-demandas-modal-close" onclick="fecharModal('modal-fixa')">&times;</button>
        </div>
        <form id="form-fixa">
            <input type="hidden" id="fixa-id">
            <div class="page-demandas-form-group">
                <label for="fixa-titulo">Título *</label>
                <input type="text" id="fixa-titulo" required>
            </div>
            <div class="page-demandas-form-group">
                <label for="fixa-descricao">Descrição</label>
                <textarea id="fixa-descricao" rows="3"></textarea>
            </div>
            <div class="page-demandas-form-group">
                <label for="fixa-board">Quadro *</label>
                <select id="fixa-board" required onchange="carregarListasFixa(this.value)">
                    <option value="">Selecione...</option>
                </select>
            </div>
            <div class="page-demandas-form-group">
                <label for="fixa-lista">Lista *</label>
                <select id="fixa-lista" required>
                    <option value="">Selecione um quadro primeiro</option>
                </select>
            </div>
            <div class="page-demandas-form-group">
                <label for="fixa-periodicidade">Periodicidade *</label>
                <select id="fixa-periodicidade" required onchange="atualizarCamposPeriodicidade()">
                    <option value="">Selecione...</option>
                    <option value="diaria">Diária</option>
                    <option value="semanal">Semanal</option>
                    <option value="mensal">Mensal</option>
                </select>
            </div>
            <div id="fixa-dia-semana-container" style="display: none;">
                <div class="page-demandas-form-group">
                    <label for="fixa-dia-semana">Dia da Semana *</label>
                    <select id="fixa-dia-semana">
                        <option value="0">Domingo</option>
                        <option value="1">Segunda-feira</option>
                        <option value="2">Terça-feira</option>
                        <option value="3">Quarta-feira</option>
                        <option value="4">Quinta-feira</option>
                        <option value="5">Sexta-feira</option>
                        <option value="6">Sábado</option>
                    </select>
                </div>
            </div>
            <div id="fixa-dia-mes-container" style="display: none;">
                <div class="page-demandas-form-group">
                    <label for="fixa-dia-mes">Dia do Mês *</label>
                    <input type="number" id="fixa-dia-mes" min="1" max="31">
                </div>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-fixa')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<!-- Dropdown de Notificações -->
<div id="notificacoes-dropdown" class="page-demandas-notificacoes-dropdown">
    <div class="page-demandas-notificacoes-header">
        <h3 style="margin: 0; font-size: 1rem;">Notificações</h3>
        <button class="btn-icon" onclick="marcarTodasNotificacoesLidas()" title="Marcar todas como lidas">✓</button>
    </div>
            <div class="page-demandas-notificacoes-tabs">
        <button class="page-demandas-notificacoes-tab active" onclick="switchNotificacoesTab('todas', this)">Todas</button>
        <button class="page-demandas-notificacoes-tab" onclick="switchNotificacoesTab('mencoes', this)">Menções (@)</button>
    </div>
    <div class="page-demandas-notificacoes-content" id="notificacoes-content">
        <!-- Preenchido via JS -->
    </div>
</div>

<!-- Modais -->
<!-- Modal: Novo Quadro -->
<div id="modal-novo-quadro" class="page-demandas-modal">
    <div class="page-demandas-modal-content">
        <div class="page-demandas-modal-header">
            <h2>Novo Quadro</h2>
            <button class="page-demandas-modal-close" onclick="fecharModal('modal-novo-quadro')">&times;</button>
        </div>
        <form id="form-novo-quadro">
            <div class="page-demandas-form-group">
                <label for="quadro-nome">Nome *</label>
                <input type="text" id="quadro-nome" required>
            </div>
            <div class="page-demandas-form-group">
                <label for="quadro-descricao">Descrição</label>
                <textarea id="quadro-descricao" rows="3"></textarea>
            </div>
            <div class="page-demandas-form-group">
                <label for="quadro-cor">Cor</label>
                <div class="page-demandas-color-picker-wrapper">
                    <div class="page-demandas-color-picker-preview" 
                         id="quadro-cor-preview" 
                         style="background: #3b82f6;"
                         onclick="document.getElementById('quadro-cor').click()"></div>
                    <input type="color" id="quadro-cor" value="#3b82f6" onchange="atualizarPreviewCor(this.value)">
                    <input type="text" 
                           id="quadro-cor-hex" 
                           class="page-demandas-color-picker-input" 
                           value="#3b82f6"
                           placeholder="#3b82f6"
                           pattern="^#[0-9A-Fa-f]{6}$"
                           onchange="atualizarCorDoHex(this.value)">
                    <span class="page-demandas-color-hex" id="quadro-cor-hex-display">#3b82f6</span>
                </div>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-novo-quadro')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Quadro</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Nova Lista -->
<div id="modal-nova-lista" class="page-demandas-modal">
    <div class="page-demandas-modal-content">
        <div class="page-demandas-modal-header">
            <h2>Nova Lista</h2>
            <button class="page-demandas-modal-close" onclick="fecharModal('modal-nova-lista')">&times;</button>
        </div>
        <form id="form-nova-lista">
            <div class="page-demandas-form-group">
                <label for="lista-nome">Nome *</label>
                <input type="text" id="lista-nome" required>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-nova-lista')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Lista</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Novo Card -->
<div id="modal-novo-card" class="page-demandas-modal">
    <div class="page-demandas-modal-content">
        <div class="page-demandas-modal-header">
            <h2>Novo Card</h2>
            <button class="page-demandas-modal-close" onclick="fecharModal('modal-novo-card')">&times;</button>
        </div>
        <form id="form-novo-card">
            <div class="page-demandas-form-group">
                <label for="card-titulo">Título *</label>
                <input type="text" id="card-titulo" required>
            </div>
            <div class="page-demandas-form-group">
                <label for="card-lista">Lista *</label>
                <select id="card-lista" required></select>
            </div>
            <div class="page-demandas-form-group">
                <label for="card-descricao">Descrição</label>
                <textarea id="card-descricao"></textarea>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="page-demandas-form-group">
                    <label for="card-prazo">Prazo</label>
                    <input type="date" id="card-prazo">
                </div>
                <div class="page-demandas-form-group">
                    <label for="card-prioridade">Prioridade</label>
                    <select id="card-prioridade">
                        <option value="media">Média</option>
                        <option value="baixa">Baixa</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
            </div>
            <div class="page-demandas-form-group">
                <label for="card-categoria">Categoria</label>
                <input type="text" id="card-categoria" placeholder="Ex: Marketing, Vendas...">
            </div>
            <div class="page-demandas-form-group">
                <label for="card-usuarios">Responsáveis</label>
                <select id="card-usuarios" multiple style="min-height: 100px;">
                    <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #6b7280; font-size: 0.75rem;">Mantenha Ctrl/Cmd pressionado para selecionar múltiplos</small>
            </div>
            <div class="page-demandas-form-group">
                <label for="card-anexo">📎 Anexar Arquivo (opcional)</label>
                <input type="file" id="card-anexo" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                <small style="color: #6b7280; font-size: 0.75rem;">Formatos: PDF, imagens, Word, Excel (máx. 10MB)</small>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-novo-card')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Card</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Ver Card -->
<div id="modal-ver-card" class="page-demandas-modal">
    <div class="page-demandas-modal-content">
        <div class="page-demandas-modal-header">
            <h2 id="modal-card-titulo">Card</h2>
            <button class="page-demandas-modal-close" onclick="fecharModal('modal-ver-card')">&times;</button>
        </div>
        <div id="modal-card-content">
            <!-- Preenchido via JS -->
        </div>
    </div>
</div>

<!-- Modal: Editar Card -->
<div id="modal-editar-card" class="page-demandas-modal">
    <div class="page-demandas-modal-content">
        <div class="page-demandas-modal-header">
            <h2>Editar Card</h2>
            <button class="page-demandas-modal-close" onclick="fecharModal('modal-editar-card')">&times;</button>
        </div>
        <form id="form-editar-card">
            <input type="hidden" id="edit-card-id">
            <div class="page-demandas-form-group">
                <label for="edit-card-titulo">Título *</label>
                <input type="text" id="edit-card-titulo" required>
            </div>
            <div class="page-demandas-form-group">
                <label for="edit-card-descricao">Descrição</label>
                <textarea id="edit-card-descricao" rows="4"></textarea>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="page-demandas-form-group">
                    <label for="edit-card-prazo">Prazo</label>
                    <input type="date" id="edit-card-prazo">
                </div>
                <div class="page-demandas-form-group">
                    <label for="edit-card-prioridade">Prioridade</label>
                    <select id="edit-card-prioridade">
                        <option value="baixa">Baixa</option>
                        <option value="media">Média</option>
                        <option value="alta">Alta</option>
                        <option value="urgente">Urgente</option>
                    </select>
                </div>
            </div>
            <div class="page-demandas-form-group">
                <label for="edit-card-categoria">Categoria</label>
                <input type="text" id="edit-card-categoria">
            </div>
            <div class="page-demandas-form-group">
                <label for="edit-card-usuarios">Responsáveis</label>
                <select id="edit-card-usuarios" multiple style="min-height: 100px;">
                    <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #6b7280; font-size: 0.75rem;">Mantenha Ctrl/Cmd pressionado para selecionar múltiplos</small>
            </div>
            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="fecharModal('modal-editar-card')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar</button>
                <button type="button" class="btn" onclick="deletarCardAtual()" style="background: #ef4444; color: white;">🗑️ Deletar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
// ============================================
// ESTADO GLOBAL
// ============================================
let boards = [];
let boardsFavoritos = [];
let boardsFiltrados = [];
let currentBoardId = null;
let currentBoard = null;
let lists = [];
let cards = {};
let notificacoes = [];
let notificacoesTab = 'todas';
let usuarios = <?= json_encode($usuarios) ?>;
let favoritosStorage = JSON.parse(localStorage.getItem('demandas_favoritos') || '[]');

// API Base
const API_BASE = 'demandas_trello_api.php';
const API_FIXAS = 'demandas_fixas_api.php';

// ============================================
// UTILITÁRIOS
// ============================================
async function apiFetch(url, options = {}) {
    const defaultOptions = {
        credentials: 'same-origin',
        headers: {
            'Content-Type': options.body instanceof FormData ? undefined : 'application/json',
            ...options.headers
        }
    };
    
    Object.keys(defaultOptions.headers).forEach(key => {
        if (defaultOptions.headers[key] === undefined) {
            delete defaultOptions.headers[key];
        }
    });
    
    return fetch(url, {
        ...defaultOptions,
        ...options,
        headers: {
            ...defaultOptions.headers,
            ...options.headers
        }
    });
}

// ============================================
// INICIALIZAÇÃO
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Verificar responsividade
    verificarResponsividade();
    window.addEventListener('resize', verificarResponsividade);

    // Densidade visual
    const demandasRoot = document.querySelector('.page-demandas');
    const densityButton = document.getElementById('btn-density-toggle');
    const densityStored = localStorage.getItem('demandas_density') || 'comfort';
    if (demandasRoot && densityStored === 'compact') {
        demandasRoot.classList.add('density-compact');
    }
    if (densityButton) {
        densityButton.textContent = densityStored === 'compact' ? '⬍ Modo confortável' : '↔ Modo compacto';
        densityButton.addEventListener('click', function() {
            if (!demandasRoot) return;
            demandasRoot.classList.toggle('density-compact');
            const isCompact = demandasRoot.classList.contains('density-compact');
            localStorage.setItem('demandas_density', isCompact ? 'compact' : 'comfort');
            densityButton.textContent = isCompact ? '⬍ Modo confortável' : '↔ Modo compacto';
        });
    }
    
    // Carregar dados
    carregarQuadros();
    carregarNotificacoes();
    
    // Polling de notificações (30s)
    setInterval(carregarNotificacoes, 30000);
    
    // Forms
    document.getElementById('form-novo-card').addEventListener('submit', function(e) {
        e.preventDefault();
        criarCard();
    });
    
    document.getElementById('form-novo-quadro').addEventListener('submit', function(e) {
        e.preventDefault();
        criarQuadro();
    });
    
    document.getElementById('form-nova-lista').addEventListener('submit', function(e) {
        e.preventDefault();
        criarLista();
    });
    
    document.getElementById('form-editar-card').addEventListener('submit', function(e) {
        e.preventDefault();
        salvarEdicaoCard();
    });
    
    document.getElementById('form-fixa')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const fixaId = document.getElementById('fixa-id').value;
        const periodicidade = document.getElementById('fixa-periodicidade').value;
        const dados = {
            titulo: document.getElementById('fixa-titulo').value.trim(),
            descricao: document.getElementById('fixa-descricao').value || null,
            board_id: parseInt(document.getElementById('fixa-board').value),
            lista_id: parseInt(document.getElementById('fixa-lista').value),
            periodicidade: periodicidade
        };
        
        if (periodicidade === 'semanal') {
            dados.dia_semana = parseInt(document.getElementById('fixa-dia-semana').value);
        } else if (periodicidade === 'mensal') {
            dados.dia_mes = parseInt(document.getElementById('fixa-dia-mes').value);
        }
        
        try {
            // Validar dados antes de enviar
            if (!dados.titulo || !dados.board_id || !dados.lista_id || !dados.periodicidade) {
                customAlert('Por favor, preencha todos os campos obrigatórios (*)', '⚠️ Validação');
                return;
            }
            
            let response;
            if (fixaId) {
                // Editar
                response = await apiFetch(`${API_FIXAS}?id=${fixaId}`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(dados)
                });
            } else {
                // Criar
                response = await apiFetch(`${API_FIXAS}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(dados)
                });
            }
            
            // Verificar status HTTP
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ error: `Erro HTTP ${response.status}` }));
                throw new Error(errorData.error || `Erro ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success) {
                fecharModal('modal-fixa');
                await carregarDrawerFixas();
                mostrarToast(`✅ Demanda fixa ${fixaId ? 'atualizada' : 'criada'} com sucesso!`);
            } else {
                customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
            }
        } catch (error) {
            console.error('Erro ao salvar demanda fixa:', error);
            customAlert('Erro ao salvar demanda fixa: ' + (error.message || 'Erro desconhecido'), '❌ Erro');
        }
    });
    
    // Fechar modais ao clicar fora
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('page-demandas-modal')) {
            fecharModal(e.target.id);
        }
    });
});

function verificarResponsividade() {
    const sidebar = document.getElementById('sidebar-boards');
    const btnToggle = document.getElementById('btn-toggle-sidebar');
    
    if (window.innerWidth < 1280) {
        sidebar.classList.add('drawer');
        if (btnToggle) btnToggle.style.display = 'block';
    } else {
        sidebar.classList.remove('drawer', 'open');
        if (btnToggle) btnToggle.style.display = 'none';
    }
}

function toggleSidebarBoards() {
    const sidebar = document.getElementById('sidebar-boards');
    sidebar.classList.toggle('open');
}

// ============================================
// QUADROS - SIDEBAR INTERNA
// ============================================
async function carregarQuadros() {
    try {
        mostrarSkeletonQuadros();
        
        const response = await apiFetch(`${API_BASE}?action=quadros`);
        const data = await response.json();
        
        if (data.success) {
            boards = data.data;
            atualizarFavoritos();
            renderizarSidebarQuadros();
            
            // Se houver board_id na URL, selecionar
            const urlParams = new URLSearchParams(window.location.search);
            const boardId = urlParams.get('board_id');
            if (boardId) {
                selecionarQuadro(parseInt(boardId));
            } else if (boards.length > 0) {
                selecionarQuadro(boards[0].id);
            }
        }
    } catch (error) {
        console.error('Erro ao carregar quadros:', error);
        mostrarToast('Erro ao carregar quadros', 'error');
    }
}

function mostrarSkeletonQuadros() {
    document.getElementById('boards-favoritos').innerHTML = '<div class="page-demandas-skeleton page-demandas-skeleton-list"></div>'.repeat(3);
    document.getElementById('boards-todos').innerHTML = '<div class="page-demandas-skeleton page-demandas-skeleton-list"></div>'.repeat(5);
}

function atualizarFavoritos() {
    boardsFavoritos = boards.filter(b => favoritosStorage.includes(b.id));
    boardsFavoritos.sort((a, b) => favoritosStorage.indexOf(a.id) - favoritosStorage.indexOf(b.id));
}

function renderizarSidebarQuadros() {
    // Renderizar favoritos
    const favoritosEl = document.getElementById('boards-favoritos');
    if (boardsFavoritos.length === 0) {
        favoritosEl.innerHTML = '<div style="padding: 0.5rem; color: #9ca3af; font-size: 0.75rem;">Nenhum favorito</div>';
    } else {
        favoritosEl.innerHTML = boardsFavoritos.map(board => renderizarItemQuadro(board, true)).join('');
    }
    
    // Renderizar todos (excluindo favoritos)
    const todosEl = document.getElementById('boards-todos');
    const boardsSemFavoritos = boardsFiltrados.length > 0 
        ? boardsFiltrados.filter(b => !favoritosStorage.includes(b.id))
        : boards.filter(b => !favoritosStorage.includes(b.id));
    
    if (boardsSemFavoritos.length === 0) {
        todosEl.innerHTML = '<div style="padding: 0.5rem; color: #9ca3af; font-size: 0.75rem;">Nenhum quadro encontrado</div>';
    } else {
        todosEl.innerHTML = boardsSemFavoritos.map(board => renderizarItemQuadro(board, false)).join('');
    }
}

function renderizarItemQuadro(board, isFavorito) {
    const cor = board.cor || '#3b82f6';
    const isActive = currentBoardId === board.id;
    
    return `
        <div class="page-demandas-board-item ${isActive ? 'active' : ''} ${isFavorito ? 'favorito' : ''}" 
             onclick="selecionarQuadro(${board.id})"
             data-board-id="${board.id}">
            <div class="page-demandas-board-item-color" style="background: ${cor}"></div>
            <div class="page-demandas-board-item-name">${escapeHtml(board.nome)}</div>
            <span class="page-demandas-board-item-star" onclick="event.stopPropagation(); toggleFavorito(${board.id})">⭐</span>
        </div>
    `;
}

function filtrarQuadros(termo) {
    const termoLower = termo.toLowerCase().trim();
    
    if (!termoLower) {
        boardsFiltrados = [];
        renderizarSidebarQuadros();
        return;
    }
    
    boardsFiltrados = boards.filter(board => 
        board.nome.toLowerCase().includes(termoLower) ||
        (board.descricao && board.descricao.toLowerCase().includes(termoLower))
    );
    
    renderizarSidebarQuadros();
}

function toggleFavorito(boardId) {
    const index = favoritosStorage.indexOf(boardId);
    
    if (index > -1) {
        favoritosStorage.splice(index, 1);
    } else {
        favoritosStorage.push(boardId);
    }
    
    localStorage.setItem('demandas_favoritos', JSON.stringify(favoritosStorage));
    atualizarFavoritos();
    renderizarSidebarQuadros();
}

function selecionarQuadro(boardId) {
    currentBoardId = boardId;
    currentBoard = boards.find(b => b.id === boardId);
    
    // Fechar sidebar mobile após seleção
    if (window.innerWidth < 1280) {
        document.getElementById('sidebar-boards').classList.remove('open');
    }
    
    renderizarSidebarQuadros();
    atualizarHeaderQuadro();
    carregarListas(boardId);
    window.history.replaceState({}, '', `?page=demandas&board_id=${boardId}`);
}

function atualizarHeaderQuadro() {
    if (!currentBoard) {
        document.getElementById('board-header-title').textContent = 'Selecione um quadro';
        document.getElementById('board-header-color-bar').style.display = 'none';
        return;
    }
    
    const cor = currentBoard.cor || '#3b82f6';
    document.getElementById('board-header-title').textContent = currentBoard.nome;
    const colorBar = document.getElementById('board-header-color-bar');
    colorBar.style.background = cor;
    colorBar.style.display = 'block';
    
    // Aplicar cor nas bordas das colunas (via CSS custom property)
    document.documentElement.style.setProperty('--board-color', cor);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================
// LISTAS E CARDS
// ============================================
async function carregarListas(boardId) {
    try {
        mostrarSkeletonListas();
        
        const response = await apiFetch(`${API_BASE}?action=listas&id=${boardId}`);
        const data = await response.json();
        
        if (data.success) {
            lists = data.data;
            await carregarTodosCards();
            renderizarBoard();
        }
    } catch (error) {
        console.error('Erro ao carregar listas:', error);
        mostrarToast('Erro ao carregar listas', 'error');
    }
}

function mostrarSkeletonListas() {
    const container = document.getElementById('trello-board');
    container.innerHTML = '<div class="page-demandas-skeleton page-demandas-skeleton-card"></div>'.repeat(3);
}

async function carregarTodosCards() {
    cards = {};
    
    // Carregar em paralelo para melhor performance
    const promises = lists.map(async (lista) => {
        try {
            const response = await apiFetch(`${API_BASE}?action=cards&id=${lista.id}`);
            const data = await response.json();
            
            if (data.success) {
                cards[lista.id] = data.data;
            } else {
                cards[lista.id] = [];
            }
        } catch (error) {
            console.error(`Erro ao carregar cards da lista ${lista.id}:`, error);
            cards[lista.id] = [];
        }
    });
    
    await Promise.all(promises);
}

function renderizarBoard() {
    const container = document.getElementById('trello-board');
    
    if (lists.length === 0) {
        container.innerHTML = '<div class="page-demandas-list-empty"><p>Nenhuma lista encontrada neste quadro</p></div>';
        return;
    }
    
    container.innerHTML = lists.map(lista => `
        <div class="page-demandas-list" data-lista-id="${lista.id}" style="border-left: 2px solid var(--board-color, #3b82f6);">
            <div class="page-demandas-list-header">
                <span>${escapeHtml(lista.nome)}</span>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <span class="page-demandas-list-count">${cards[lista.id]?.length || 0}</span>
                    <button onclick="deletarLista(${lista.id})" 
                            class="btn-icon" 
                            title="Deletar lista"
                            aria-label="Deletar lista">🗑️</button>
                </div>
            </div>
            <div class="page-demandas-list-cards" id="cards-${lista.id}">
                ${renderizarCards(lista.id)}
            </div>
            <button class="page-demandas-add-card-btn" onclick="abrirModalNovoCard(${lista.id})">
                ➕ Adicionar card
            </button>
        </div>
    `).join('');
    
    // Inicializar Sortable.js para cada lista
    lists.forEach(lista => {
        const cardsContainer = document.getElementById(`cards-${lista.id}`);
        if (cardsContainer) {
            new Sortable(cardsContainer, {
                group: 'shared',
                animation: 150,
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                onEnd: function(evt) {
                    const cardId = parseInt(evt.item.dataset.cardId);
                    const novaListaId = parseInt(evt.to.closest('.page-demandas-list').dataset.listaId);
                    const novaPosicao = evt.newIndex;
                    moverCard(cardId, novaListaId, novaPosicao);
                }
            });
        }
    });
}

function renderizarCards(listaId) {
    const cardsList = cards[listaId] || [];
    
    if (cardsList.length === 0) {
        return '<div class="page-demandas-list-empty">Nenhum card</div>';
    }
    
    return cardsList.map(card => {
        const hoje = new Date();
        const prazo = card.prazo ? new Date(card.prazo) : null;
        let prazoClass = '';
        let prazoText = '';
        
        if (prazo) {
            const diffDays = Math.ceil((prazo - hoje) / (1000 * 60 * 60 * 24));
            if (diffDays < 0) {
                prazoClass = 'vencido';
                prazoText = 'Vencido';
            } else if (diffDays <= 3) {
                prazoClass = 'proximo';
                prazoText = `Em ${diffDays} dias`;
            } else {
                prazoText = prazo.toLocaleDateString('pt-BR');
            }
        }
        
        // Preview de imagem
        let previewHtml = '';
        if (card.preview_imagem && card.preview_imagem.url_preview) {
            previewHtml = `
                <div class="page-demandas-card-preview">
                    <img src="${card.preview_imagem.url_preview}" 
                         alt="${escapeHtml(card.preview_imagem.nome)}" 
                         onclick="event.stopPropagation(); verCard(${card.id})"
                         onerror="this.style.display='none'">
                </div>
            `;
        }
        
        return `
            <div class="page-demandas-card" 
                 data-card-id="${card.id}"
                 onclick="verCard(${card.id})"
                 ${prazoClass ? `data-prazo-class="${prazoClass}"` : ''}>
                ${previewHtml}
                <div class="page-demandas-card-title">${escapeHtml(card.titulo)}</div>
                ${card.descricao ? `<div style="font-size: 0.75rem; color: #6b7280; margin: 0.5rem 0;">${escapeHtml(card.descricao.substring(0, 100))}${card.descricao.length > 100 ? '...' : ''}</div>` : ''}
                <div class="page-demandas-card-badges">
                    ${card.prioridade && card.prioridade !== 'media' ? `<span class="badge badge-prioridade prioridade-${card.prioridade}">${card.prioridade}</span>` : ''}
                    ${card.total_comentarios > 0 ? `<span class="badge badge-comentarios">💬 ${card.total_comentarios}</span>` : ''}
                    ${card.total_anexos > 0 ? `<span class="badge badge-anexos">📎 ${card.total_anexos}</span>` : ''}
                </div>
                <div class="page-demandas-card-meta">
                    ${prazo ? `<span style="font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; ${prazoClass === 'vencido' ? 'background: #fee2e2; color: #991b1b;' : prazoClass === 'proximo' ? 'background: #fef3c7; color: #92400e;' : ''}">📅 ${prazoText}</span>` : ''}
                    <div class="page-demandas-card-users">
                        ${(card.usuarios || []).slice(0, 3).map(u => 
                            `<div class="page-demandas-avatar" title="${escapeHtml(u.nome)}">${u.nome.charAt(0).toUpperCase()}</div>`
                        ).join('')}
                        ${(card.usuarios || []).length > 3 ? `<div class="page-demandas-avatar">+${(card.usuarios || []).length - 3}</div>` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// ============================================
// CRIAÇÃO E EDIÇÃO
// ============================================
async function criarCard(listaIdPredefinida = null) {
    const titulo = document.getElementById('card-titulo').value;
    const listaId = listaIdPredefinida || parseInt(document.getElementById('card-lista').value);
    const descricao = document.getElementById('card-descricao').value;
    const prazo = document.getElementById('card-prazo').value || null;
    const prioridade = document.getElementById('card-prioridade').value;
    const categoria = document.getElementById('card-categoria').value || null;
    const usuarios = Array.from(document.getElementById('card-usuarios').selectedOptions).map(opt => parseInt(opt.value));
    const anexoInput = document.getElementById('card-anexo');
    const temAnexo = anexoInput && anexoInput.files[0];
    
    try {
        const response = await apiFetch(`${API_BASE}?action=criar_card`, {
            method: 'POST',
            body: JSON.stringify({
                lista_id: listaId,
                titulo,
                descricao,
                prazo,
                prioridade,
                categoria,
                usuarios
            })
        });
        
        if (response.status === 401) {
            customAlert('Erro de autenticação. Por favor, faça login novamente.', '🔒 Sessão Expirada');
            setTimeout(() => window.location.href = 'login.php', 2000);
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            const cardId = data.data.id;
            
            if (temAnexo) {
                const formData = new FormData();
                formData.append('arquivo', anexoInput.files[0]);
                
                try {
                    await fetch(`${API_BASE}?action=anexo&id=${cardId}`, {
                        method: 'POST',
                        body: formData
                    });
                } catch (anexoError) {
                    console.error('Erro ao anexar arquivo:', anexoError);
                }
            }
            
            fecharModal('modal-novo-card');
            document.getElementById('form-novo-card').reset();
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card criado com sucesso!' + (temAnexo ? ' Arquivo anexado.' : ''));
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro ao criar card:', error);
        customAlert('Erro ao criar card', '❌ Erro');
    }
}

async function moverCard(cardId, novaListaId, novaPosicao) {
    try {
        await apiFetch(`${API_BASE}?action=mover_card&id=${cardId}`, {
            method: 'POST',
            body: JSON.stringify({
                nova_lista_id: novaListaId,
                nova_posicao: novaPosicao
            })
        });
        
        await carregarTodosCards();
        renderizarBoard();
    } catch (error) {
        console.error('Erro ao mover card:', error);
    }
}

async function criarQuadro() {
    const nome = document.getElementById('quadro-nome').value.trim();
    const descricao = document.getElementById('quadro-descricao').value;
    const cor = document.getElementById('quadro-cor').value;
    
    if (!nome) {
        customAlert('Nome é obrigatório', '⚠️ Atenção');
        return;
    }
    
    try {
        const response = await apiFetch(`${API_BASE}?action=criar_quadro`, {
            method: 'POST',
            body: JSON.stringify({ nome, descricao, cor })
        });
        
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-novo-quadro');
            document.getElementById('form-novo-quadro').reset();
            atualizarPreviewCor('#3b82f6');
            await carregarQuadros();
            selecionarQuadro(data.data.id);
            mostrarToast('✅ Quadro criado com sucesso!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao criar quadro', '❌ Erro');
    }
}

async function criarLista() {
    if (!currentBoardId) {
        customAlert('Selecione um quadro primeiro', '⚠️ Atenção');
        return;
    }
    
    const nome = document.getElementById('lista-nome').value.trim();
    if (!nome) {
        customAlert('Nome é obrigatório', '⚠️ Atenção');
        return;
    }
    
    try {
        const response = await apiFetch(`${API_BASE}?action=criar_lista&id=${currentBoardId}`, {
            method: 'POST',
            body: JSON.stringify({ nome })
        });
        
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-nova-lista');
            document.getElementById('form-nova-lista').reset();
            await carregarListas(currentBoardId);
            mostrarToast('✅ Lista criada com sucesso!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao criar lista', '❌ Erro');
    }
}

async function deletarLista(listaId) {
    const confirmado = await customConfirm('Tem certeza? Todos os cards desta lista serão deletados.', '⚠️ Confirmar Exclusão');
    if (!confirmado) return;
    
    try {
        const response = await apiFetch(`${API_BASE}?action=deletar_lista&id=${listaId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            await carregarListas(currentBoardId);
            mostrarToast('✅ Lista deletada!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao deletar lista', '❌ Erro');
    }
}

async function deletarQuadro(quadroId) {
    const confirmado = await customConfirm('Tem certeza? Todo o quadro, listas, cards e arquivos serão deletados permanentemente.', '⚠️ Confirmar Exclusão de Quadro');
    if (!confirmado) return;
    
    try {
        const response = await apiFetch(`${API_BASE}?action=deletar_quadro&id=${quadroId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            mostrarToast('✅ Quadro deletado com sucesso!');
            await carregarQuadros();
            
            if (boards.length > 0) {
                selecionarQuadro(boards[0].id);
            } else {
                document.getElementById('trello-board').innerHTML = '<div class="page-demandas-list-empty"><p>Nenhum quadro disponível. Crie um novo quadro para começar.</p></div>';
                currentBoardId = null;
                atualizarHeaderQuadro();
            }
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao deletar quadro', '❌ Erro');
    }
}

// ============================================
// VER/EDITAR CARDS
// ============================================
async function verCard(cardId) {
    try {
        const response = await apiFetch(`${API_BASE}?action=card&id=${cardId}`);
        const data = await response.json();
        
        if (data.success) {
            const card = data.data;
            document.getElementById('modal-card-titulo').textContent = card.titulo;
            
            const hoje = new Date();
            const prazo = card.prazo ? new Date(card.prazo) : null;
            let prazoHtml = '';
            
            if (prazo) {
                const diffDays = Math.ceil((prazo - hoje) / (1000 * 60 * 60 * 24));
                if (diffDays < 0) {
                    prazoHtml = `<p><strong>Prazo:</strong> <span style="background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 4px;">Vencido há ${Math.abs(diffDays)} dias</span></p>`;
                } else if (diffDays <= 3) {
                    prazoHtml = `<p><strong>Prazo:</strong> <span style="background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px;">Em ${diffDays} dias (${prazo.toLocaleDateString('pt-BR')})</span></p>`;
                } else {
                    prazoHtml = `<p><strong>Prazo:</strong> ${prazo.toLocaleDateString('pt-BR')}</p>`;
                }
            }
            
            document.getElementById('modal-card-content').innerHTML = `
                <div>
                    ${prazoHtml}
                    ${card.descricao ? `<p style="margin: 1rem 0; line-height: 1.6;">${escapeHtml(card.descricao)}</p>` : ''}
                    <p><strong>Status:</strong> ${card.status}</p>
                    <p><strong>Prioridade:</strong> ${card.prioridade || 'Média'}</p>
                    ${card.categoria ? `<p><strong>Categoria:</strong> ${escapeHtml(card.categoria)}</p>` : ''}
                    <p><strong>Criado por:</strong> ${escapeHtml(card.criador_nome || 'Desconhecido')}</p>
                    <p><strong>Responsáveis:</strong> ${(card.usuarios || []).map(u => escapeHtml(u.nome)).join(', ') || 'Nenhum'}</p>
                </div>
                
                <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #e5e7eb;">
                    <h3>💬 Comentários</h3>
                    <div id="comentarios-list" style="margin-top: 1rem;">
                        ${(card.comentarios || []).map(c => `
                            <div style="background: #f9fafb; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-size: 0.875rem;">
                                    <span style="font-weight: 600; color: #172b4d;">${escapeHtml(c.autor_nome || 'Anônimo')}</span>
                                    <span style="color: #6b7280;">${new Date(c.criado_em).toLocaleString('pt-BR')}</span>
                                </div>
                                <div style="color: #374151; line-height: 1.5;">${escapeHtml(c.mensagem)}</div>
                            </div>
                        `).join('')}
                    </div>
                    <div style="margin-top: 1rem;">
                        <textarea id="novo-comentario" placeholder="Digite seu comentário... Use @usuario para mencionar" rows="3" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px;"></textarea>
                        <button class="btn btn-primary" onclick="adicionarComentario(${card.id})" style="margin-top: 0.5rem;">Adicionar</button>
                    </div>
                </div>
                
                <div style="margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #e5e7eb;">
                    <h3>📎 Anexos</h3>
                    <div id="anexos-list" style="margin-top: 1rem;">
                        ${(card.anexos || []).map(a => `
                            <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: #f9fafb; border-radius: 6px; margin-bottom: 0.5rem;">
                                <span>📄 ${escapeHtml(a.nome_original)}</span>
                                <div style="display: flex; gap: 0.5rem; margin-left: auto;">
                                    <button class="btn btn-outline" onclick="downloadAnexo(${a.id})">Download</button>
                                    <button class="btn" onclick="deletarAnexoTrello(${a.id}, ${card.id})" style="background: #ef4444; color: white;">🗑️</button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: #f9fafb; border-radius: 6px; border: 2px dashed #d1d5db;">
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem; color: #374151;">➕ Adicionar Novo Anexo</label>
                        <input type="file" id="novo-anexo" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" style="margin-bottom: 0.75rem; width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 4px;" onchange="if(this.files[0]) adicionarAnexo(${card.id})">
                        <div id="upload-status-${card.id}" style="display: none; margin-bottom: 0.5rem; padding: 0.5rem; background: #dbeafe; border-radius: 4px; font-size: 0.875rem;"></div>
                        <small style="display: block; margin-top: 0.5rem; color: #6b7280; font-size: 0.75rem;">Formatos aceitos: PDF, imagens, Word, Excel (máx. 10MB)</small>
                    </div>
                </div>
                
                <div style="margin-top: 2rem; display: flex; gap: 1rem;">
                    <button class="btn btn-primary" onclick="editarCard(${card.id})">✏️ Editar</button>
                    ${card.status === 'concluido' 
                        ? `<button class="btn btn-outline" onclick="reabrirCard(${card.id})">🔄 Reabrir</button>`
                        : `<button class="btn btn-primary" onclick="concluirCard(${card.id})">✅ Concluir</button>`
                    }
                    <button class="btn" onclick="deletarCardConfirmado(${card.id})" style="background: #ef4444; color: white;">🗑️ Deletar</button>
                </div>
            `;
            
            abrirModal('modal-ver-card');
        }
    } catch (error) {
        console.error('Erro ao carregar card:', error);
        customAlert('Erro ao carregar card', '❌ Erro');
    }
}

async function editarCard(cardId) {
    try {
        const response = await apiFetch(`${API_BASE}?action=card&id=${cardId}`);
        const data = await response.json();
        
        if (data.success) {
            const card = data.data;
            
            document.getElementById('edit-card-id').value = card.id;
            document.getElementById('edit-card-titulo').value = card.titulo;
            document.getElementById('edit-card-descricao').value = card.descricao || '';
            document.getElementById('edit-card-prazo').value = card.prazo || '';
            document.getElementById('edit-card-prioridade').value = card.prioridade || 'media';
            document.getElementById('edit-card-categoria').value = card.categoria || '';
            
            const selectUsuarios = document.getElementById('edit-card-usuarios');
            Array.from(selectUsuarios.options).forEach(opt => opt.selected = false);
            (card.usuarios || []).forEach(u => {
                const option = selectUsuarios.querySelector(`option[value="${u.id}"]`);
                if (option) option.selected = true;
            });
            
            fecharModal('modal-ver-card');
            abrirModal('modal-editar-card');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao carregar card', '❌ Erro');
    }
}

async function salvarEdicaoCard() {
    const cardId = parseInt(document.getElementById('edit-card-id').value);
    const titulo = document.getElementById('edit-card-titulo').value.trim();
    const descricao = document.getElementById('edit-card-descricao').value;
    const prazo = document.getElementById('edit-card-prazo').value || null;
    const prioridade = document.getElementById('edit-card-prioridade').value;
    const categoria = document.getElementById('edit-card-categoria').value || null;
    const usuarios = Array.from(document.getElementById('edit-card-usuarios').selectedOptions).map(opt => parseInt(opt.value));
    
    try {
        const response = await apiFetch(`${API_BASE}?action=atualizar_card&id=${cardId}`, {
            method: 'PATCH',
            body: JSON.stringify({
                titulo,
                descricao,
                prazo,
                prioridade,
                categoria,
                usuarios
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-editar-card');
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card atualizado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao atualizar card', '❌ Erro');
    }
}

async function deletarCardAtual() {
    const cardId = parseInt(document.getElementById('edit-card-id').value);
    deletarCardConfirmado(cardId);
}

async function deletarCardConfirmado(cardId) {
    const confirmado = await customConfirm('Tem certeza que deseja deletar este card? Esta ação não pode ser desfeita.', '⚠️ Confirmar Exclusão');
    if (!confirmado) return;
    
    try {
        const response = await apiFetch(`${API_BASE}?action=deletar_card&id=${cardId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-ver-card');
            fecharModal('modal-editar-card');
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card deletado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao deletar card', '❌ Erro');
    }
}

async function concluirCard(cardId) {
    try {
        const response = await apiFetch(`${API_BASE}?action=concluir&id=${cardId}`, { method: 'POST' });
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-ver-card');
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card concluído!');
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}

async function reabrirCard(cardId) {
    try {
        const response = await apiFetch(`${API_BASE}?action=reabrir&id=${cardId}`, { method: 'POST' });
        const data = await response.json();
        
        if (data.success) {
            fecharModal('modal-ver-card');
            await carregarTodosCards();
            renderizarBoard();
            mostrarToast('✅ Card reaberto!');
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}

// ============================================
// COMENTÁRIOS E ANEXOS
// ============================================
async function adicionarComentario(cardId) {
    const mensagem = document.getElementById('novo-comentario').value.trim();
    if (!mensagem) {
        customAlert('Digite um comentário', '⚠️ Atenção');
        return;
    }
    
    try {
        const response = await apiFetch(`${API_BASE}?action=comentario&id=${cardId}`, {
            method: 'POST',
            body: JSON.stringify({ mensagem })
        });
        
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('novo-comentario').value = '';
            verCard(cardId);
            mostrarToast('✅ Comentário adicionado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro ao adicionar comentário:', error);
        customAlert('Erro ao adicionar comentário', '❌ Erro');
    }
}

async function adicionarAnexo(cardId) {
    const input = document.getElementById('novo-anexo');
    const statusDiv = document.getElementById(`upload-status-${cardId}`);
    
    if (!input || !input.files[0]) return;
    
    if (statusDiv) {
        statusDiv.style.display = 'block';
        statusDiv.textContent = '⏳ Enviando arquivo...';
        statusDiv.style.background = '#dbeafe';
    }
    
    const formData = new FormData();
    formData.append('arquivo', input.files[0]);
    
    try {
        const response = await fetch(`${API_BASE}?action=anexo&id=${cardId}`, {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            input.value = '';
            if (statusDiv) {
                statusDiv.textContent = '✅ Arquivo anexado com sucesso!';
                statusDiv.style.background = '#d1fae5';
                setTimeout(() => statusDiv.style.display = 'none', 2000);
            }
            await verCard(cardId);
            mostrarToast('✅ Anexo adicionado!');
        } else {
            if (statusDiv) {
                statusDiv.textContent = '❌ Erro: ' + (data.error || 'Erro desconhecido');
                statusDiv.style.background = '#fee2e2';
            }
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro ao adicionar anexo:', error);
        if (statusDiv) {
            statusDiv.textContent = '❌ Erro ao anexar arquivo';
            statusDiv.style.background = '#fee2e2';
        }
        customAlert('Erro ao adicionar anexo', '❌ Erro');
    }
}

async function downloadAnexo(anexoId) {
    try {
        window.location.href = `${API_BASE}?action=download_anexo&id=${anexoId}`;
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao baixar arquivo', '❌ Erro');
    }
}

async function deletarAnexoTrello(anexoId, cardId) {
    const confirmado = await customConfirm('Deseja realmente excluir este anexo?', '⚠️ Confirmar Exclusão');
    if (!confirmado) return;
    
    try {
        const response = await apiFetch(`${API_BASE}?action=deletar_anexo&id=${anexoId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            verCard(cardId);
            mostrarToast('✅ Anexo deletado!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao deletar anexo', '❌ Erro');
    }
}

// ============================================
// NOTIFICAÇÕES
// ============================================
async function carregarNotificacoes() {
    try {
        const response = await apiFetch(`${API_BASE}?action=notificacoes`);
        const data = await response.json();
        
        if (data.success) {
            notificacoes = data.data;
            const naoLidas = data.nao_lidas || 0;
            
            const countEl = document.getElementById('notificacoes-count');
            if (naoLidas > 0) {
                countEl.textContent = naoLidas;
                countEl.style.display = 'block';
            } else {
                countEl.style.display = 'none';
            }
            
            renderizarNotificacoes();
        }
    } catch (error) {
        console.error('Erro ao carregar notificações:', error);
    }
}

function renderizarNotificacoes() {
    const container = document.getElementById('notificacoes-content');
    
    let notificacoesFiltradas = notificacoes;
    if (notificacoesTab === 'mencoes') {
        notificacoesFiltradas = notificacoes.filter(n => n.mensagem && n.mensagem.includes('@'));
    }
    
    if (notificacoesFiltradas.length === 0) {
        container.innerHTML = '<div style="padding: 2rem; text-align: center; color: #6b7280;">Nenhuma notificação</div>';
        return;
    }
    
    // Agrupar por data
    const hoje = new Date();
    hoje.setHours(0,0,0,0);
    
    const grupos = {
        hoje: [],
        ontem: [],
        semana: [],
        antigas: []
    };
    
    notificacoesFiltradas.forEach(notif => {
        const dataNotif = new Date(notif.criada_em || notif.criado_em || notif.created_at);
        const diffMs = hoje - dataNotif;
        const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
        
        if (diffDays === 0) grupos.hoje.push(notif);
        else if (diffDays === 1) grupos.ontem.push(notif);
        else if (diffDays <= 7) grupos.semana.push(notif);
        else grupos.antigas.push(notif);
    });
    
    let html = '';
    
    if (grupos.hoje.length > 0) {
        html += `<div class="page-demandas-notificacoes-group"><div class="page-demandas-notificacoes-group-title">Hoje</div>`;
        html += grupos.hoje.map(n => renderizarNotificacaoItem(n)).join('');
        html += `</div>`;
    }
    
    if (grupos.ontem.length > 0) {
        html += `<div class="page-demandas-notificacoes-group"><div class="page-demandas-notificacoes-group-title">Ontem</div>`;
        html += grupos.ontem.map(n => renderizarNotificacaoItem(n)).join('');
        html += `</div>`;
    }
    
    if (grupos.semana.length > 0) {
        html += `<div class="page-demandas-notificacoes-group"><div class="page-demandas-notificacoes-group-title">Esta semana</div>`;
        html += grupos.semana.map(n => renderizarNotificacaoItem(n)).join('');
        html += `</div>`;
    }
    
    if (grupos.antigas.length > 0) {
        html += `<div class="page-demandas-notificacoes-group"><div class="page-demandas-notificacoes-group-title">Mais antigas</div>`;
        html += grupos.antigas.map(n => renderizarNotificacaoItem(n)).join('');
        html += `</div>`;
    }
    
    container.innerHTML = html;
}

function renderizarNotificacaoItem(notif) {
    const dataNotif = new Date(notif.criada_em || notif.criado_em || notif.created_at);
    const tempoRelativo = getTempoRelativo(dataNotif);
    
    return `
        <div class="page-demandas-notificacao-item ${!notif.lida ? 'nao-lida' : ''}" 
             onclick="marcarNotificacaoLida(${notif.id})">
            <div class="page-demandas-notificacao-titulo">${escapeHtml(notif.titulo || notif.mensagem || 'Notificação')}</div>
            ${notif.mensagem && notif.mensagem !== notif.titulo ? `<div class="page-demandas-notificacao-trecho">${escapeHtml(notif.mensagem.substring(0, 100))}${notif.mensagem.length > 100 ? '...' : ''}</div>` : ''}
            <div class="page-demandas-notificacao-meta">
                <span>${tempoRelativo}</span>
                ${notif.autor_nome ? `<span>${escapeHtml(notif.autor_nome)}</span>` : ''}
            </div>
        </div>
    `;
}

function getTempoRelativo(data) {
    const agora = new Date();
    const diffMs = agora - data;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) return 'Agora';
    if (diffMins < 60) return `${diffMins} min atrás`;
    if (diffHours < 24) return `${diffHours}h atrás`;
    if (diffDays === 1) return 'Ontem';
    if (diffDays < 7) return `${diffDays} dias atrás`;
    return data.toLocaleDateString('pt-BR');
}

function toggleNotificacoes(event) {
    if (event) event.stopPropagation();
    const dropdown = document.getElementById('notificacoes-dropdown');
    const isOpen = dropdown.classList.contains('open');
    
    // Fechar outros dropdowns
    document.querySelectorAll('.page-demandas-notificacoes-dropdown.open').forEach(el => el.classList.remove('open'));
    
    if (!isOpen) {
        dropdown.classList.add('open');
    }
    
    // Fechar ao clicar fora
    setTimeout(() => {
        document.addEventListener('click', function fecharAoClicarFora(e) {
            if (!e.target.closest('.notificacoes-badge') && !e.target.closest('.page-demandas-notificacoes-dropdown')) {
                dropdown.classList.remove('open');
                document.removeEventListener('click', fecharAoClicarFora);
            }
        }, { once: true });
    }, 100);
}

function switchNotificacoesTab(tab, element) {
    notificacoesTab = tab;
    document.querySelectorAll('.page-demandas-notificacoes-tab').forEach(t => t.classList.remove('active'));
    if (element) element.classList.add('active');
    renderizarNotificacoes();
}

async function marcarNotificacaoLida(notifId, recarregar = true) {
    try {
        await apiFetch(`${API_BASE}?action=marcar_notificacao&id=${notifId}`, { method: 'POST' });
        
        if (recarregar) {
            await carregarNotificacoes();
        }
        
        // Se tiver referencia_id, abrir o card
        const notif = notificacoes.find(n => n.id === notifId);
        if (notif && notif.referencia_id) {
            fecharModal('modal-ver-card'); // Fechar se aberto
            verCard(notif.referencia_id);
        }
    } catch (error) {
        console.error('Erro:', error);
    }
}

async function marcarTodasNotificacoesLidas() {
    try {
        const naoLidas = notificacoes.filter(n => !n.lida);
        for (const notif of naoLidas) {
            await apiFetch(`${API_BASE}?action=marcar_notificacao&id=${notif.id}`, { method: 'POST' });
        }
        await carregarNotificacoes();
        mostrarToast('✅ Todas as notificações foram marcadas como lidas');
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao marcar notificações', '❌ Erro');
    }
}

// ============================================
// DEMANDAS FIXAS (DRAWER)
// ============================================
function toggleDrawerFixas() {
    const overlay = document.getElementById('drawer-fixas-overlay');
    const drawer = document.getElementById('drawer-fixas');
    
    overlay.classList.toggle('open');
    drawer.classList.toggle('open');
    
    if (drawer.classList.contains('open')) {
        carregarDrawerFixas();
    }
}

async function carregarDrawerFixas() {
    try {
        const content = document.getElementById('drawer-fixas-content');
        content.innerHTML = `
            <div style="margin-bottom: 1.5rem;">
                <button class="btn btn-primary" onclick="abrirModalNovaFixa()">➕ Nova Demanda Fixa</button>
            </div>
            <div id="fixas-tabela">
                <p style="color: #6b7280; text-align: center; padding: 2rem;">Carregando...</p>
            </div>
        `;
        
        // Buscar demandas fixas via API
        const response = await apiFetch(`${API_FIXAS}`);
        
        // Verificar status HTTP
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ error: `Erro HTTP ${response.status}` }));
            throw new Error(errorData.error || `Erro ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            const fixas = data.data || [];
            renderizarTabelaFixas(fixas);
        } else {
            content.querySelector('#fixas-tabela').innerHTML = `
                <p style="color: #ef4444; text-align: center; padding: 2rem;">Erro ao carregar: ${escapeHtml(data.error || 'Erro desconhecido')}</p>
            `;
        }
    } catch (error) {
        console.error('Erro ao carregar demandas fixas:', error);
        const content = document.getElementById('drawer-fixas-content');
        if (content && content.querySelector('#fixas-tabela')) {
            content.querySelector('#fixas-tabela').innerHTML = `
                <p style="color: #ef4444; text-align: center; padding: 2rem;">Erro ao carregar: ${escapeHtml(error.message || 'Erro desconhecido')}</p>
            `;
        }
    }
}

function renderizarTabelaFixas(fixas) {
    const tabelaEl = document.getElementById('fixas-tabela');
    
    if (fixas.length === 0) {
        tabelaEl.innerHTML = `
            <div style="padding: 2rem; text-align: center; color: #6b7280;">
                <p style="margin-bottom: 1rem;">Nenhuma demanda fixa cadastrada.</p>
                <button class="btn btn-primary" onclick="abrirModalNovaFixa()">➕ Criar Primeira Demanda Fixa</button>
            </div>
        `;
        return;
    }
    
    tabelaEl.innerHTML = `
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; font-size: 0.875rem;">Título</th>
                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; font-size: 0.875rem;">Quadro</th>
                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; font-size: 0.875rem;">Lista</th>
                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; font-size: 0.875rem;">Periodicidade</th>
                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; font-size: 0.875rem;">Status</th>
                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; font-size: 0.875rem;">Ações</th>
                </tr>
            </thead>
            <tbody>
                ${fixas.map(fixa => {
                    const periodicidadeText = fixa.periodicidade === 'diaria' ? 'Diária' :
                                             fixa.periodicidade === 'semanal' ? `Semanal (${['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][fixa.dia_semana || 0]})` :
                                             fixa.periodicidade === 'mensal' ? `Mensal (dia ${fixa.dia_mes})` : fixa.periodicidade;
                    const statusBadge = fixa.ativo ? 
                        '<span style="background: #10b981; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem;">Ativo</span>' :
                        '<span style="background: #6b7280; color: white; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.75rem;">Inativo</span>';
                    
                    return `
                        <tr style="border-bottom: 1px solid #e5e7eb;">
                            <td style="padding: 0.75rem; font-size: 0.875rem;">${escapeHtml(fixa.titulo)}</td>
                            <td style="padding: 0.75rem; font-size: 0.875rem; color: #6b7280;">${escapeHtml(fixa.board_nome || 'N/A')}</td>
                            <td style="padding: 0.75rem; font-size: 0.875rem; color: #6b7280;">${escapeHtml(fixa.lista_nome || 'N/A')}</td>
                            <td style="padding: 0.75rem; font-size: 0.875rem;">${periodicidadeText}</td>
                            <td style="padding: 0.75rem;">${statusBadge}</td>
                            <td style="padding: 0.75rem;">
                                <div style="display: flex; gap: 0.5rem;">
                                    <button class="btn-icon" onclick="toggleFixaDrawer(${fixa.id}, ${fixa.ativo ? 'false' : 'true'})" 
                                            title="${fixa.ativo ? 'Pausar' : 'Ativar'}" 
                                            style="font-size: 0.875rem;">${fixa.ativo ? '⏸️' : '▶️'}</button>
                                    <button class="btn-icon" onclick="editarFixaDrawer(${fixa.id})" 
                                            title="Editar"
                                            style="font-size: 0.875rem;">✏️</button>
                                    <button class="btn-icon" onclick="deletarFixaDrawer(${fixa.id})" 
                                            title="Deletar"
                                            style="font-size: 0.875rem; color: #ef4444;">🗑️</button>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
        </table>
    `;
}

async function abrirModalNovaFixa() {
    // Garantir que quadros estão carregados
    if (boards.length === 0) {
        await carregarQuadros();
    }
    
    // Preencher selects de quadros e listas
    const selectBoard = document.getElementById('fixa-board');
    const selectLista = document.getElementById('fixa-lista');
    
    if (selectBoard) {
        selectBoard.innerHTML = '<option value="">Selecione...</option>' + 
            boards.map(b => `<option value="${b.id}">${escapeHtml(b.nome)}</option>`).join('');
    }
    
    if (selectLista) {
        selectLista.innerHTML = '<option value="">Selecione um quadro primeiro</option>';
    }
    
    document.getElementById('form-fixa').reset();
    document.getElementById('fixa-id').value = '';
    document.getElementById('modal-fixa-titulo').textContent = 'Nova Demanda Fixa';
    atualizarCamposPeriodicidade();
    abrirModal('modal-fixa');
}

async function editarFixaDrawer(id) {
    try {
        const response = await apiFetch(`${API_FIXAS}?id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            const fixa = data.data;
            
            document.getElementById('fixa-id').value = fixa.id;
            document.getElementById('fixa-titulo').value = fixa.titulo;
            document.getElementById('fixa-descricao').value = fixa.descricao || '';
            document.getElementById('fixa-board').value = fixa.board_id;
            document.getElementById('fixa-periodicidade').value = fixa.periodicidade;
            
            await carregarListasFixa(fixa.board_id);
            
            setTimeout(() => {
                document.getElementById('fixa-lista').value = fixa.lista_id;
                if (fixa.periodicidade === 'semanal') {
                    document.getElementById('fixa-dia-semana').value = fixa.dia_semana;
                } else if (fixa.periodicidade === 'mensal') {
                    document.getElementById('fixa-dia-mes').value = fixa.dia_mes;
                }
                atualizarCamposPeriodicidade();
            }, 300);
            
            document.getElementById('modal-fixa-titulo').textContent = 'Editar Demanda Fixa';
            abrirModal('modal-fixa');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao carregar demanda fixa', '❌ Erro');
    }
}

async function toggleFixaDrawer(id, novoStatus) {
    try {
        const response = await apiFetch(`${API_FIXAS}?id=${id}`, {
            method: 'PATCH',
            body: JSON.stringify({ ativo: novoStatus })
        });
        
        const data = await response.json();
        
        if (data.success) {
            await carregarDrawerFixas();
            mostrarToast(`✅ Demanda fixa ${novoStatus ? 'ativada' : 'pausada'}!`);
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao atualizar demanda fixa', '❌ Erro');
    }
}

async function deletarFixaDrawer(id) {
    const confirmado = await customConfirm('Tem certeza que deseja deletar esta demanda fixa? Esta ação não pode ser desfeita.', '⚠️ Confirmar Exclusão');
    if (!confirmado) return;
    
    try {
        const response = await apiFetch(`${API_FIXAS}?id=${id}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            await carregarDrawerFixas();
            mostrarToast('✅ Demanda fixa deletada!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao deletar demanda fixa', '❌ Erro');
    }
}

async function carregarListasFixa(boardId) {
    const selectLista = document.getElementById('fixa-lista');
    
    if (!boardId) {
        selectLista.innerHTML = '<option value="">Selecione um quadro primeiro</option>';
        return;
    }
    
    try {
        const response = await apiFetch(`${API_BASE}?action=listas&id=${boardId}`);
        const data = await response.json();
        
        if (data.success) {
            selectLista.innerHTML = '<option value="">Selecione...</option>' +
                data.data.map(l => `<option value="${l.id}">${escapeHtml(l.nome)}</option>`).join('');
        }
    } catch (error) {
        console.error('Erro ao carregar listas:', error);
    }
}

function atualizarCamposPeriodicidade() {
    const periodicidade = document.getElementById('fixa-periodicidade').value;
    const diaSemanaContainer = document.getElementById('fixa-dia-semana-container');
    const diaMesContainer = document.getElementById('fixa-dia-mes-container');
    
    if (diaSemanaContainer) diaSemanaContainer.style.display = periodicidade === 'semanal' ? 'block' : 'none';
    if (diaMesContainer) diaMesContainer.style.display = periodicidade === 'mensal' ? 'block' : 'none';
    
    const diaSemana = document.getElementById('fixa-dia-semana');
    const diaMes = document.getElementById('fixa-dia-mes');
    if (diaSemana) diaSemana.required = periodicidade === 'semanal';
    if (diaMes) diaMes.required = periodicidade === 'mensal';
}

// ============================================
// UTILITÁRIOS
// ============================================
function abrirModal(modalId) {
    document.getElementById(modalId).classList.add('open');
}

function fecharModal(modalId) {
    document.getElementById(modalId).classList.remove('open');
}

function abrirModalNovoQuadro() {
    abrirModal('modal-novo-quadro');
}

function abrirModalNovaLista() {
    if (!currentBoardId) {
        customAlert('Selecione um quadro primeiro', '⚠️ Atenção');
        return;
    }
    abrirModal('modal-nova-lista');
}

function abrirModalNovoCard(listaIdPredefinida = null) {
    if (!currentBoardId) {
        customAlert('Selecione um quadro primeiro', '⚠️ Atenção');
        return;
    }
    
    const select = document.getElementById('card-lista');
    select.innerHTML = lists.map(l => 
        `<option value="${l.id}" ${listaIdPredefinida === l.id ? 'selected' : ''}>${escapeHtml(l.nome)}</option>`
    ).join('');
    
    abrirModal('modal-novo-card');
}

function toggleMenuActions() {
    if (!currentBoardId) {
        customAlert('Selecione um quadro primeiro', '⚠️ Atenção');
        return;
    }
    
    const menu = document.getElementById('menu-actions');
    if (menu) {
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        return;
    }
    
    // Criar menu dropdown
    const menuEl = document.createElement('div');
    menuEl.id = 'menu-actions';
    menuEl.style.cssText = 'position: fixed; top: 70px; right: 1rem; background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 2000; min-width: 200px; padding: 0.5rem;';
    menuEl.innerHTML = `
        <button class="btn btn-outline" onclick="renomearQuadro()" style="width: 100%; justify-content: flex-start; margin-bottom: 0.5rem;">✏️ Renomear Quadro</button>
        <button class="btn btn-outline" onclick="arquivarQuadro()" style="width: 100%; justify-content: flex-start; margin-bottom: 0.5rem;">📦 Arquivar Quadro</button>
        <button class="btn btn-outline" onclick="exportarCSV()" style="width: 100%; justify-content: flex-start;">📥 Exportar CSV</button>
    `;
    document.body.appendChild(menuEl);
    
    // Fechar ao clicar fora
    setTimeout(() => {
        document.addEventListener('click', function fecharMenu(e) {
            if (!e.target.closest('#menu-actions') && !e.target.closest('.btn-icon[onclick="toggleMenuActions()"]')) {
                menuEl.remove();
                document.removeEventListener('click', fecharMenu);
            }
        }, { once: true });
    }, 100);
}

async function renomearQuadro() {
    const novoNome = await customPrompt('Digite o novo nome do quadro:', 'Renomear Quadro', currentBoard?.nome || '');
    if (!novoNome || novoNome.trim() === '') return;
    
    try {
        const response = await apiFetch(`${API_BASE}?action=atualizar_quadro&id=${currentBoardId}`, {
            method: 'PATCH',
            body: JSON.stringify({ nome: novoNome.trim() })
        });
        
        const data = await response.json();
        
        if (data.success) {
            await carregarQuadros();
            selecionarQuadro(currentBoardId);
            mostrarToast('✅ Quadro renomeado com sucesso!');
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao renomear quadro', '❌ Erro');
    }
    
    document.getElementById('menu-actions')?.remove();
}

async function arquivarQuadro() {
    const confirmado = await customConfirm('Deseja arquivar este quadro? Ele ficará oculto mas não será deletado permanentemente.', '⚠️ Arquivar Quadro');
    if (!confirmado) {
        document.getElementById('menu-actions')?.remove();
        return;
    }
    
    try {
        const response = await apiFetch(`${API_BASE}?action=deletar_quadro&id=${currentBoardId}`, {
            method: 'DELETE'
        });
        
        const data = await response.json();
        
        if (data.success) {
            mostrarToast('✅ Quadro arquivado com sucesso!');
            await carregarQuadros();
            
            if (boards.length > 0) {
                selecionarQuadro(boards[0].id);
            } else {
                document.getElementById('trello-board').innerHTML = '<div class="page-demandas-list-empty"><p>Nenhum quadro disponível. Crie um novo quadro para começar.</p></div>';
                currentBoardId = null;
                atualizarHeaderQuadro();
            }
        } else {
            customAlert('Erro: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
        }
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao arquivar quadro', '❌ Erro');
    }
    
    document.getElementById('menu-actions')?.remove();
}

async function exportarCSV() {
    if (!currentBoardId) {
        customAlert('Selecione um quadro primeiro', '⚠️ Atenção');
        return;
    }
    
    try {
        // Carregar todas as listas e cards
        await carregarListas(currentBoardId);
        await carregarTodosCards();
        
        // Gerar CSV
        let csv = 'Título,Lista,Descrição,Prazo,Prioridade,Status,Responsáveis\n';
        
        lists.forEach(lista => {
            (cards[lista.id] || []).forEach(card => {
                const responsaveis = (card.usuarios || []).map(u => u.nome).join('; ') || '';
                const linha = [
                    `"${(card.titulo || '').replace(/"/g, '""')}"`,
                    `"${lista.nome.replace(/"/g, '""')}"`,
                    `"${(card.descricao || '').replace(/"/g, '""')}"`,
                    card.prazo || '',
                    card.prioridade || '',
                    card.status || '',
                    `"${responsaveis.replace(/"/g, '""')}"`
                ].join(',');
                csv += linha + '\n';
            });
        });
        
        // Download
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `${currentBoard?.nome || 'quadro'}_${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        mostrarToast('✅ CSV exportado com sucesso!');
    } catch (error) {
        console.error('Erro:', error);
        customAlert('Erro ao exportar CSV', '❌ Erro');
    }
    
    document.getElementById('menu-actions')?.remove();
}

function customPrompt(mensagem, titulo = 'Input', valorPadrao = '') {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                <div class="custom-alert-body">
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 500;">${escapeHtml(mensagem)}</label>
                    <input type="text" id="custom-prompt-input" value="${escapeHtml(valorPadrao)}" style="width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem;" autofocus>
                </div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-secondary" onclick="resolveCustomPrompt(null)">Cancelar</button>
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="resolveCustomPrompt(document.getElementById('custom-prompt-input').value)">OK</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        const input = overlay.querySelector('#custom-prompt-input');
        input.focus();
        input.select();
        
        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                resolveCustomPrompt(input.value);
            } else if (e.key === 'Escape') {
                resolveCustomPrompt(null);
            }
        });
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                resolve(null);
            }
        });
        
        window.resolveCustomPrompt = (resultado) => {
            overlay.remove();
            resolve(resultado);
        };
    });
}

function atualizarPreviewCor(cor) {
    document.getElementById('quadro-cor-preview').style.background = cor;
    document.getElementById('quadro-cor-hex-display').textContent = cor;
    document.getElementById('quadro-cor').value = cor;
}

function atualizarCorDoHex(hex) {
    if (/^#[0-9A-Fa-f]{6}$/.test(hex)) {
        atualizarPreviewCor(hex);
    }
}

function mostrarToast(mensagem, tipo = 'success') {
    const toast = document.createElement('div');
    toast.className = `page-demandas-toast ${tipo}`;
    toast.textContent = mensagem;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ============================================
// CUSTOM ALERTS
// ============================================
function customAlert(mensagem, titulo = 'Aviso') {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="this.closest('.custom-alert-overlay').remove(); resolveCustomAlert()">OK</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                resolveCustomAlert();
            }
        });
        
        window.resolveCustomAlert = () => {
            overlay.remove();
            resolve();
        };
    });
}

function customConfirm(mensagem, titulo = 'Confirmar') {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.className = 'custom-alert-overlay';
        overlay.innerHTML = `
            <div class="custom-alert">
                <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                <div class="custom-alert-actions">
                    <button class="custom-alert-btn custom-alert-btn-secondary" onclick="resolveCustomConfirm(false)">Cancelar</button>
                    <button class="custom-alert-btn custom-alert-btn-primary" onclick="resolveCustomConfirm(true)">Confirmar</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.remove();
                resolve(false);
            }
        });
        
        window.resolveCustomConfirm = (resultado) => {
            overlay.remove();
            resolve(resultado);
        };
    });
}
</script>

<?php endSidebar(); ?>
