<?php
// lc_permissions_helper.php
// Helper para gerenciar permissões do sistema

/**
 * Obtém o perfil do usuário atual
 * @return string Perfil do usuário (ADM, OPER, CONSULTA)
 */
function lc_get_user_perfil(): string {
    $perfil = $_SESSION['perfil'] ?? 'ADM';
    
    // Validar perfil
    if (!in_array($perfil, ['ADM', 'OPER', 'CONSULTA'])) {
        $perfil = 'CONSULTA'; // Fallback mais restritivo
    }
    
    return $perfil;
}

/**
 * Verifica se o usuário pode fechar contagens
 * @return bool
 */
function lc_can_close_contagem(): bool {
    return lc_get_user_perfil() === 'ADM';
}

/**
 * Verifica se o usuário pode ver valor total do estoque
 * @return bool
 */
function lc_can_view_stock_value(): bool {
    return lc_get_user_perfil() === 'ADM';
}

/**
 * Verifica se o usuário pode editar contagens
 * @return bool
 */
function lc_can_edit_contagem(): bool {
    $perfil = lc_get_user_perfil();
    return in_array($perfil, ['ADM', 'OPER']);
}

/**
 * Verifica se o usuário pode criar contagens
 * @return bool
 */
function lc_can_create_contagem(): bool {
    $perfil = lc_get_user_perfil();
    return in_array($perfil, ['ADM', 'OPER']);
}

/**
 * Verifica se o usuário pode apenas visualizar
 * @return bool
 */
function lc_is_readonly(): bool {
    return lc_get_user_perfil() === 'CONSULTA';
}

/**
 * Redireciona usuário sem permissão
 * @param string $required_permission Tipo de permissão necessária
 */
function lc_check_permission(string $required_permission): void {
    $allowed = false;
    
    switch ($required_permission) {
        case 'create':
            $allowed = lc_can_create_contagem();
            break;
        case 'edit':
            $allowed = lc_can_edit_contagem();
            break;
        case 'close':
            $allowed = lc_can_close_contagem();
            break;
        case 'view_value':
            $allowed = lc_can_view_stock_value();
            break;
        default:
            $allowed = true; // Permissão não especificada
    }
    
    if (!$allowed) {
        header('Location: estoque_contagens.php?error=permission_denied');
        exit;
    }
}

/**
 * Verifica se o usuário pode acessar o módulo LC (Lista de Compras)
 * @return bool
 */
function lc_can_access_lc(): bool {
    $perfil = lc_get_user_perfil();
    return in_array($perfil, ['ADM', 'OPER']);
}

/**
 * Verifica se o usuário pode acessar o módulo de demandas
 * @return bool
 */
function lc_can_access_demandas(): bool {
    $perfil = lc_get_user_perfil();
    return in_array($perfil, ['ADM', 'OPER']);
}

/**
 * Obtém mensagem de erro baseada no perfil
 * @param string $action Ação que foi negada
 * @return string Mensagem de erro
 */
function lc_get_permission_message(string $action): string {
    $perfil = lc_get_user_perfil();
    
    switch ($action) {
        case 'close':
            return "Apenas administradores podem fechar contagens. Seu perfil: $perfil";
        case 'view_value':
            return "Apenas administradores podem ver o valor total do estoque. Seu perfil: $perfil";
        case 'edit':
            return "Apenas administradores e operadores podem editar contagens. Seu perfil: $perfil";
        case 'create':
            return "Apenas administradores e operadores podem criar contagens. Seu perfil: $perfil";
        default:
            return "Acesso negado. Seu perfil: $perfil";
    }
}
