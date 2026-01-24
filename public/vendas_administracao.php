<?php
/**
 * vendas_administracao.php
 * Entrada para a área "Vendas > Administração (Tiago/admin)"
 *
 * Reaproveita a tela de pré-contratos, habilitando o contexto admin via GET.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/vendas_helper.php';

if (!vendas_is_admin()) {
    header('Location: index.php?page=vendas_pre_contratos&error=admin_only');
    exit;
}

$_GET['admin'] = '1';
require __DIR__ . '/vendas_pre_contratos.php';

