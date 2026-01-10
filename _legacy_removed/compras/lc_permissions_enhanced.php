<?php
// lc_permissions_enhanced.php
// Sistema de permissões melhorado que funciona com o sistema atual

/**
 * Obtém o perfil do usuário baseado nas permissões existentes
 * @return string Perfil do usuário
 */
function lc_get_user_profile(): string {
    // Verifica se já tem perfil definido
    if (isset($_SESSION['perfil']) && !empty($_SESSION['perfil'])) {
        return $_SESSION['perfil'];
    }
    
    // Determina perfil baseado nas permissões existentes
    if (isset($_SESSION['perm_usuarios']) && $_SESSION['perm_usuarios'] == 1) {
        return 'ADM';
    } elseif (isset($_SESSION['perm_pagamentos']) && $_SESSION['perm_pagamentos'] == 1) {
        return 'FINANCEIRO';
    } elseif (isset($_SESSION['perm_tarefas']) && $_SESSION['perm_tarefas'] == 1) {
        return 'GERENTE';
    } elseif (isset($_SESSION['perm_estoque_logistico']) && $_SESSION['perm_estoque_logistico'] == 1) {
        return 'OPER';
    }
    
    return 'CONSULTA';
}

/**
 * Verifica se o usuário tem permissão para um módulo específico
 * @param string $module Nome do módulo
 * @return bool
 */
function lc_can_access_module(string $module): bool {
    $profile = lc_get_user_profile();
    
    // Mapeamento de módulos por perfil
    $module_permissions = [
        'ADM' => ['usuarios', 'pagamentos', 'tarefas', 'demandas', 'portao', 'banco_smile', 'banco_smile_admin', 'notas_fiscais', 'estoque_logistico', 'dados_contrato', 'uso_fiorino', 'rh', 'contabilidade', 'estoque', 'configuracoes', 'comercial'],
        'FINANCEIRO' => ['pagamentos', 'rh', 'contabilidade', 'banco_smile', 'notas_fiscais', 'comercial'],
        'GERENTE' => ['tarefas', 'demandas', 'pagamentos', 'rh', 'comercial'],
        'OPER' => ['tarefas', 'demandas', 'estoque', 'comercial'],
        'CONSULTA' => ['comercial']
    ];
    
    return in_array($module, $module_permissions[$profile] ?? []);
}

/**
 * Verifica se o usuário pode editar
 * @return bool
 */
function lc_can_edit(): bool {
    $profile = lc_get_user_profile();
    return in_array($profile, ['ADM', 'FINANCEIRO', 'GERENTE', 'OPER']);
}

/**
 * Verifica se o usuário é administrador
 * @return bool
 */
function lc_is_admin(): bool {
    return lc_get_user_profile() === 'ADM';
}

/**
 * Verifica se o usuário é financeiro
 * @return bool
 */
function lc_is_financeiro(): bool {
    $profile = lc_get_user_profile();
    return in_array($profile, ['ADM', 'FINANCEIRO']);
}

/**
 * Verifica se o usuário pode acessar RH
 * @return bool
 */
function lc_can_access_rh(): bool {
    return lc_can_access_module('rh');
}

/**
 * Verifica se o usuário pode acessar Contabilidade
 * @return bool
 */
function lc_can_access_contabilidade(): bool {
    return lc_can_access_module('contabilidade');
}

/**
 * Verifica se o usuário pode acessar Estoque
 * @return bool
 */
function lc_can_access_estoque(): bool {
    return lc_can_access_module('estoque');
}

/**
 * Verifica se o usuário pode acessar Comercial
 * @return bool
 */
function lc_can_access_comercial(): bool {
    return lc_can_access_module('comercial');
}

/**
 * Verifica se o usuário pode editar degustações
 * @return bool
 */
function lc_can_edit_degustacoes(): bool {
    $profile = lc_get_user_profile();
    return in_array($profile, ['ADM', 'FINANCEIRO', 'GERENTE']);
}

/**
 * Verifica se o usuário pode gerenciar inscritos
 * @return bool
 */
function lc_can_manage_inscritos(): bool {
    $profile = lc_get_user_profile();
    return in_array($profile, ['ADM', 'FINANCEIRO', 'GERENTE', 'OPER']);
}

/**
 * Verifica se o usuário pode ver conversão
 * @return bool
 */
function lc_can_view_conversao(): bool {
    $profile = lc_get_user_profile();
    return in_array($profile, ['ADM', 'FINANCEIRO', 'GERENTE']);
}

/**
 * Obtém mensagem de erro baseada no perfil
 * @param string $action Ação que foi negada
 * @return string Mensagem de erro
 */
function lc_get_permission_message(string $action): string {
    $profile = lc_get_user_profile();
    
    switch ($action) {
        case 'usuarios':
            return "Apenas administradores podem gerenciar usuários. Seu perfil: $profile";
        case 'rh':
            return "Apenas administradores e financeiro podem acessar RH. Seu perfil: $profile";
        case 'contabilidade':
            return "Apenas administradores e financeiro podem acessar Contabilidade. Seu perfil: $profile";
        case 'estoque':
            return "Apenas administradores e operadores podem acessar Estoque. Seu perfil: $profile";
        case 'configuracoes':
            return "Apenas administradores podem acessar Configurações. Seu perfil: $profile";
        case 'comercial':
            return "Acesso ao módulo Comercial negado. Seu perfil: $profile";
        case 'degustacoes_editar':
            return "Apenas administradores, financeiro e gerentes podem editar degustações. Seu perfil: $profile";
        case 'inscritos_manage':
            return "Apenas administradores, financeiro, gerentes e operadores podem gerenciar inscritos. Seu perfil: $profile";
        case 'conversao_view':
            return "Apenas administradores, financeiro e gerentes podem ver conversão. Seu perfil: $profile";
        default:
            return "Acesso negado. Seu perfil: $profile";
    }
}
