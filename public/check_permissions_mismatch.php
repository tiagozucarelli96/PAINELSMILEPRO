<?php
/**
 * Script para verificar quais permissões do formulário existem no banco
 */

require_once __DIR__ . '/conexao.php';

echo "=== VERIFICAÇÃO DE PERMISSÕES ===\n\n";

// Permissões do formulário
$form_permissions = [
    // Módulos da sidebar
    'perm_agenda',
    'perm_comercial',
    // 'perm_logistico', // REMOVIDO: Módulo desativado
    'perm_configuracoes',
    'perm_cadastros',
    'perm_financeiro',
    'perm_administrativo',
    'perm_banco_smile',
    'perm_banco_smile_admin',
    // Permissões específicas
    'perm_usuarios',
    'perm_pagamentos',
    'perm_tarefas',
    'perm_demandas',
    'perm_portao',
    'perm_notas_fiscais',
    // 'perm_estoque_logistico', // REMOVIDO: Módulo desativado
    'perm_dados_contrato',
    'perm_uso_fiorino'
];

// Buscar colunas do banco
$stmt = $pdo->query("
    SELECT column_name 
    FROM information_schema.columns 
    WHERE table_schema = 'public' 
    AND table_name = 'usuarios'
    AND column_name LIKE 'perm_%'
    ORDER BY column_name
");
$db_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "PERMISSÕES NO FORMULÁRIO (" . count($form_permissions) . "):\n";
foreach ($form_permissions as $perm) {
    $exists = in_array($perm, $db_permissions);
    $status = $exists ? "✅" : "❌";
    echo "  $status $perm\n";
}

echo "\nPERMISSÕES NO BANCO (" . count($db_permissions) . "):\n";
foreach ($db_permissions as $perm) {
    $in_form = in_array($perm, $form_permissions);
    $status = $in_form ? "✅" : "⚠️";
    echo "  $status $perm\n";
}

echo "\n=== PERMISSÕES QUE NÃO EXISTEM NO BANCO ===\n";
$missing = array_diff($form_permissions, $db_permissions);
if (empty($missing)) {
    echo "Nenhuma! Todas as permissões do formulário existem no banco.\n";
} else {
    foreach ($missing as $perm) {
        echo "  ❌ $perm\n";
    }
}

echo "\n=== PERMISSÕES NO BANCO QUE NÃO ESTÃO NO FORMULÁRIO ===\n";
$extra = array_diff($db_permissions, $form_permissions);
if (empty($extra)) {
    echo "Nenhuma! Todas as permissões do banco estão no formulário.\n";
} else {
    foreach ($extra as $perm) {
        echo "  ⚠️ $perm\n";
    }
}

