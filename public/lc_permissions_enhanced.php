<?php
// lc_permissions_enhanced.php
// Sistema de permissões simplificado - apenas 10 permissões da sidebar + superadmin

/**
 * Lista das permissões válidas do sistema (10 da sidebar + superadmin)
 */
define('VALID_PERMISSIONS', [
    'perm_agenda',
    'perm_demandas',
    'perm_comercial',
    'perm_logistico',
    'perm_configuracoes',
    'perm_cadastros',
    'perm_financeiro',
    'perm_administrativo',
    'perm_banco_smile',
    'perm_superadmin'
]);

/**
 * Obtém o perfil do usuário baseado nas permissões existentes
 * @return string Perfil do usuário
 */
function lc_get_user_profile(): string {
    // Verifica se já tem perfil definido
    if (isset($_SESSION['perfil']) && !empty($_SESSION['perfil'])) {
        return $_SESSION['perfil'];
    }
    
    // Superadmin tem acesso total
    if (!empty($_SESSION['perm_superadmin'])) {
        return 'ADM';
    }
    
    // Determina perfil baseado nas permissões da sidebar
    if (!empty($_SESSION['perm_configuracoes'])) {
        return 'ADM';
    } elseif (!empty($_SESSION['perm_financeiro'])) {
        return 'FINANCEIRO';
    } elseif (!empty($_SESSION['perm_administrativo'])) {
        return 'GERENTE';
    } elseif (!empty($_SESSION['perm_logistico'])) {
        return 'OPER';
    } elseif (!empty($_SESSION['perm_comercial'])) {
        return 'COMERCIAL';
    }
    
    return 'CONSULTA';
}

/**
 * Verifica se o usuário tem permissão para um módulo específico
 * @param string $module Nome do módulo
 * @return bool
 */
function lc_can_access_module(string $module): bool {
    // Superadmin tem acesso total
    if (!empty($_SESSION['perm_superadmin'])) {
        return true;
    }
    
    // Mapear módulos para permissões da sidebar
    $module_to_perm = [
        'agenda' => 'perm_agenda',
        'demandas' => 'perm_demandas',
        'comercial' => 'perm_comercial',
        'logistico' => 'perm_logistico',
        'logistica' => 'perm_logistico',
        'configuracoes' => 'perm_configuracoes',
        'usuarios' => 'perm_configuracoes', // Usuários está em Configurações
        'cadastros' => 'perm_cadastros',
        'financeiro' => 'perm_financeiro',
        'administrativo' => 'perm_administrativo',
        'contabilidade' => 'perm_administrativo', // Contabilidade usa perm_administrativo
        'banco_smile' => 'perm_banco_smile',
        'rh' => 'perm_administrativo', // RH usa perm_administrativo
    ];
    
    $perm_key = $module_to_perm[$module] ?? null;
    
    if ($perm_key && !empty($_SESSION[$perm_key])) {
        return true;
    }
    
    return false;
}

/**
 * Verifica se o usuário pode editar
 * @return bool
 */
function lc_can_edit(): bool {
    // Superadmin ou qualquer permissão administrativa
    return !empty($_SESSION['perm_superadmin']) || 
           !empty($_SESSION['perm_configuracoes']) ||
           !empty($_SESSION['perm_administrativo']);
}

/**
 * Verifica se o usuário é administrador (superadmin ou configurações)
 * @return bool
 */
function lc_is_admin(): bool {
    return !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_configuracoes']);
}

/**
 * Verifica se o usuário é financeiro
 * @return bool
 */
function lc_is_financeiro(): bool {
    return !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_financeiro']);
}

/**
 * Verifica se o usuário pode acessar RH (administrativo)
 * @return bool
 */
function lc_can_access_rh(): bool {
    return !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_administrativo']);
}

/**
 * Verifica se o usuário pode acessar Contabilidade (administrativo)
 * @return bool
 */
function lc_can_access_contabilidade(): bool {
    return !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_administrativo']);
}

/**
 * Verifica se o usuário pode acessar Logística
 * @return bool
 */
function lc_can_access_estoque(): bool {
    return !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);
}

/**
 * Verifica se o usuário pode acessar Comercial
 * @return bool
 */
function lc_can_access_comercial(): bool {
    return !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_comercial']);
}

/**
 * Verifica se o usuário pode editar degustações
 * @return bool
 */
function lc_can_edit_degustacoes(): bool {
    return !empty($_SESSION['perm_superadmin']) || 
           !empty($_SESSION['perm_comercial']) ||
           !empty($_SESSION['perm_configuracoes']);
}

/**
 * Verifica se o usuário pode gerenciar inscritos
 * @return bool
 */
function lc_can_manage_inscritos(): bool {
    return !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_comercial']);
}

/**
 * Verifica se o usuário pode ver conversão
 * @return bool
 */
function lc_can_view_conversao(): bool {
    return !empty($_SESSION['perm_superadmin']) || 
           !empty($_SESSION['perm_comercial']) ||
           !empty($_SESSION['perm_financeiro']);
}

/**
 * Obtém mensagem de erro baseada na ação
 * @param string $action Ação que foi negada
 * @return string Mensagem de erro
 */
function lc_get_permission_message(string $action): string {
    $messages = [
        'usuarios' => 'Você precisa da permissão "Configurações" para gerenciar usuários.',
        'rh' => 'Você precisa da permissão "Administrativo" para acessar RH.',
        'contabilidade' => 'Você precisa da permissão "Administrativo" para acessar Contabilidade.',
        'logistica' => 'Você precisa da permissão "Logística" para acessar este módulo.',
        'logistico' => 'Você precisa da permissão "Logística" para acessar este módulo.',
        'configuracoes' => 'Você precisa da permissão "Configurações" para acessar este módulo.',
        'comercial' => 'Você precisa da permissão "Comercial" para acessar este módulo.',
        'financeiro' => 'Você precisa da permissão "Financeiro" para acessar este módulo.',
        'administrativo' => 'Você precisa da permissão "Administrativo" para acessar este módulo.',
        'agenda' => 'Você precisa da permissão "Agenda" para acessar este módulo.',
        'demandas' => 'Você precisa da permissão "Demandas" para acessar este módulo.',
        'banco_smile' => 'Você precisa da permissão "Banco Smile" para acessar este módulo.',
        'degustacoes_editar' => 'Você precisa da permissão "Comercial" para editar degustações.',
        'inscritos_manage' => 'Você precisa da permissão "Comercial" para gerenciar inscritos.',
        'conversao_view' => 'Você precisa da permissão "Comercial" ou "Financeiro" para ver conversão.',
    ];
    
    return $messages[$action] ?? 'Acesso negado. Você não tem permissão para esta ação.';
}

/**
 * Verifica se uma permissão é válida (está na lista oficial)
 * @param string $perm Nome da permissão
 * @return bool
 */
function lc_is_valid_permission(string $perm): bool {
    return in_array($perm, VALID_PERMISSIONS);
}
