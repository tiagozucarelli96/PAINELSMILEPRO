<?php
// lc_permissions_stub.php
// Stub seguro para funções de permissões do módulo removido
// Este arquivo mantém compatibilidade com código que ainda referencia essas funções

/**
 * Retorna o perfil do usuário logado
 * Stub: retorna perfil baseado em permissões administrativas
 */
function lc_get_user_perfil() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verificar permissões para determinar perfil
    if (!empty($_SESSION['perm_administrativo'])) {
        return 'ADM';
    }
    
    if (!empty($_SESSION['perm_financeiro'])) {
        return 'FIN';
    }
    
    if (!empty($_SESSION['perm_agenda']) || !empty($_SESSION['perm_demandas'])) {
        return 'GERENTE';
    }
    
    // Padrão: consulta
    return 'CONSULTA';
}

/**
 * Verifica se o usuário pode acessar funcionalidades de lista de compras
 * Stub: sempre retorna false (módulo removido)
 */
function lc_can_access_lc($perfil) {
    // Módulo removido - sempre retorna false
    return false;
}
