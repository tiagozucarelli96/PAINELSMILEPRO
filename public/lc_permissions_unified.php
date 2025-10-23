<?php
// lc_permissions_unified.php
// Sistema de permissões unificado para todo o sistema

/**
 * Obtém o perfil do usuário atual
 * @return string Perfil do usuário (ADM, FINANCEIRO, GERENTE, OPER, CONSULTA)
 */
function lc_get_user_perfil(): string {
    // Primeiro tenta o novo sistema
    if (isset($_SESSION['perfil'])) {
        $perfil = $_SESSION['perfil'];
        if (in_array($perfil, ['ADM', 'FINANCEIRO', 'GERENTE', 'OPER', 'CONSULTA'])) {
            return $perfil;
        }
    }
    
    // Fallback: verifica se é admin pelo sistema antigo
    if (isset($_SESSION['perm_usuarios']) && $_SESSION['perm_usuarios'] == 1) {
        return 'ADM';
    }
    
    // Fallback padrão
    return 'CONSULTA';
}

/**
 * Verifica se o usuário tem uma permissão específica
 * @param string $permission Nome da permissão
 * @return bool
 */
function lc_has_permission(string $permission): bool {
    $perfil = lc_get_user_perfil();
    
    // Mapeamento de permissões por perfil
    $permissions = [
        'ADM' => [
            'usuarios', 'pagamentos', 'tarefas', 'demandas', 'portao',
            'banco_smile', 'banco_smile_admin', 'notas_fiscais',
            'estoque_logistico', 'dados_contrato', 'uso_fiorino',
            'rh', 'contabilidade', 'estoque', 'configuracoes'
        ],
        'FINANCEIRO' => [
            'pagamentos', 'rh', 'contabilidade', 'banco_smile', 'notas_fiscais'
        ],
        'GERENTE' => [
            'tarefas', 'demandas', 'pagamentos', 'rh'
        ],
        'OPER' => [
            'tarefas', 'demandas', 'estoque'
        ],
        'CONSULTA' => []
    ];
    
    return in_array($permission, $permissions[$perfil] ?? []);
}

/**
 * Verifica se o usuário pode acessar um módulo específico
 * @param string $module Nome do módulo
 * @return bool
 */
function lc_can_access_module(string $module): bool {
    return lc_has_permission($module);
}

/**
 * Verifica se o usuário pode criar/editar
 * @return bool
 */
function lc_can_edit(): bool {
    $perfil = lc_get_user_perfil();
    return in_array($perfil, ['ADM', 'FINANCEIRO', 'GERENTE', 'OPER']);
}

/**
 * Verifica se o usuário pode apenas visualizar
 * @return bool
 */
function lc_is_readonly(): bool {
    return lc_get_user_perfil() === 'CONSULTA';
}

/**
 * Verifica se o usuário é administrador
 * @return bool
 */
function lc_is_admin(): bool {
    return lc_get_user_perfil() === 'ADM';
}

/**
 * Verifica se o usuário é financeiro
 * @return bool
 */
function lc_is_financeiro(): bool {
    $perfil = lc_get_user_perfil();
    return in_array($perfil, ['ADM', 'FINANCEIRO']);
}

/**
 * Redireciona usuário sem permissão
 * @param string $required_permission Tipo de permissão necessária
 * @param string $redirect_url URL para redirecionamento
 */
function lc_check_permission(string $required_permission, string $redirect_url = 'dashboard.php'): void {
    if (!lc_has_permission($required_permission)) {
        header("Location: $redirect_url?error=permission_denied");
        exit;
    }
}

/**
 * Obtém mensagem de erro baseada no perfil
 * @param string $action Ação que foi negada
 * @return string Mensagem de erro
 */
function lc_get_permission_message(string $action): string {
    $perfil = lc_get_user_perfil();
    
    switch ($action) {
        case 'usuarios':
            return "Apenas administradores podem gerenciar usuários. Seu perfil: $perfil";
        case 'rh':
            return "Apenas administradores e financeiro podem acessar RH. Seu perfil: $perfil";
        case 'contabilidade':
            return "Apenas administradores e financeiro podem acessar Contabilidade. Seu perfil: $perfil";
        case 'estoque':
            return "Apenas administradores e operadores podem acessar Estoque. Seu perfil: $perfil";
        case 'configuracoes':
            return "Apenas administradores podem acessar Configurações. Seu perfil: $perfil";
        default:
            return "Acesso negado. Seu perfil: $perfil";
    }
}

/**
 * Migra permissões do sistema antigo para o novo
 * @param PDO $pdo Conexão com o banco
 */
function lc_migrate_permissions(PDO $pdo): void {
    $id = $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0;
    if (!$id) return;
    
    try {
        // Busca dados do usuário
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) return;
        
        // Determina perfil baseado nas permissões antigas
        $perfil = 'CONSULTA';
        
        if (($user['perm_usuarios'] ?? 0) == 1) {
            $perfil = 'ADM';
        } elseif (($user['perm_pagamentos'] ?? 0) == 1) {
            $perfil = 'FINANCEIRO';
        } elseif (($user['perm_tarefas'] ?? 0) == 1 || ($user['perm_demandas'] ?? 0) == 1) {
            $perfil = 'GERENTE';
        } elseif (($user['perm_estoque_logistico'] ?? 0) == 1) {
            $perfil = 'OPER';
        }
        
        // Atualiza sessão
        $_SESSION['perfil'] = $perfil;
        
    } catch (Exception $e) {
        // Em caso de erro, mantém perfil padrão
        $_SESSION['perfil'] = 'CONSULTA';
    }
}
