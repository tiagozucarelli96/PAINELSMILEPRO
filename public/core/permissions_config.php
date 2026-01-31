<?php
/**
 * permissions_config.php
 * Definição centralizada das permissões do sistema
 * 
 * O sistema possui apenas 10 permissões da sidebar + superadmin:
 * - Dashboard: todos têm acesso (sem permissão específica)
 * - Agenda: perm_agenda
 * - Demandas: perm_demandas
 * - Comercial: perm_comercial
 * - Logística: perm_logistico
 * - Configurações: perm_configuracoes
 * - Cadastros: perm_cadastros
 * - Financeiro: perm_financeiro
 * - Administrativo: perm_administrativo
 * - Banco Smile: perm_banco_smile
 * - Superadmin: perm_superadmin (acesso total)
 */

// Lista das permissões válidas do sistema
define('SYSTEM_PERMISSIONS', [
    'perm_agenda' => [
        'label' => 'Agenda',
        'icon' => '📅',
        'description' => 'Acesso ao módulo de agenda e calendário'
    ],
    'perm_demandas' => [
        'label' => 'Demandas',
        'icon' => '📝',
        'description' => 'Acesso ao quadro de demandas e tarefas'
    ],
    'perm_comercial' => [
        'label' => 'Comercial',
        'icon' => '📋',
        'description' => 'Acesso ao módulo comercial (degustações, vendas, etc)'
    ],
    'perm_logistico' => [
        'label' => 'Logística',
        'icon' => '📦',
        'description' => 'Acesso ao módulo de logística e estoque'
    ],
    'perm_configuracoes' => [
        'label' => 'Configurações',
        'icon' => '⚙️',
        'description' => 'Acesso às configurações do sistema e gerenciamento de usuários'
    ],
    'perm_cadastros' => [
        'label' => 'Cadastros',
        'icon' => '📝',
        'description' => 'Acesso ao módulo de cadastros'
    ],
    'perm_financeiro' => [
        'label' => 'Financeiro',
        'icon' => '💰',
        'description' => 'Acesso ao módulo financeiro'
    ],
    'perm_administrativo' => [
        'label' => 'Administrativo',
        'icon' => '👥',
        'description' => 'Acesso ao módulo administrativo, contabilidade e RH'
    ],
    'perm_banco_smile' => [
        'label' => 'Banco Smile',
        'icon' => '🏦',
        'description' => 'Acesso ao Banco Smile'
    ],
]);

// Permissão especial de super administrador
define('SUPERADMIN_PERMISSION', 'perm_superadmin');

/**
 * Obtém a lista de nomes de permissões válidas
 * @return array
 */
function get_valid_permission_names(): array {
    $names = array_keys(SYSTEM_PERMISSIONS);
    $names[] = SUPERADMIN_PERMISSION;
    return $names;
}

/**
 * Verifica se uma permissão é válida
 * @param string $perm
 * @return bool
 */
function is_valid_permission(string $perm): bool {
    return isset(SYSTEM_PERMISSIONS[$perm]) || $perm === SUPERADMIN_PERMISSION;
}

/**
 * Obtém informações de uma permissão
 * @param string $perm
 * @return array|null
 */
function get_permission_info(string $perm): ?array {
    if ($perm === SUPERADMIN_PERMISSION) {
        return [
            'label' => 'Super Admin',
            'icon' => '⭐',
            'description' => 'Acesso total a todos os módulos do sistema'
        ];
    }
    return SYSTEM_PERMISSIONS[$perm] ?? null;
}

/**
 * Verifica se o usuário tem acesso a um módulo específico
 * @param string $module Nome do módulo
 * @return bool
 */
function user_can_access(string $module): bool {
    // Superadmin tem acesso a tudo
    if (!empty($_SESSION[SUPERADMIN_PERMISSION])) {
        return true;
    }
    
    // Mapear módulos para permissões
    $module_map = [
        'agenda' => 'perm_agenda',
        'demandas' => 'perm_demandas',
        'comercial' => 'perm_comercial',
        'logistica' => 'perm_logistico',
        'logistico' => 'perm_logistico',
        'configuracoes' => 'perm_configuracoes',
        'usuarios' => 'perm_configuracoes',
        'cadastros' => 'perm_cadastros',
        'financeiro' => 'perm_financeiro',
        'administrativo' => 'perm_administrativo',
        'contabilidade' => 'perm_administrativo',
        'rh' => 'perm_administrativo',
        'banco_smile' => 'perm_banco_smile',
    ];
    
    $perm = $module_map[$module] ?? null;
    
    return $perm && !empty($_SESSION[$perm]);
}
