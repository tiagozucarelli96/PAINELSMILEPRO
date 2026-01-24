<?php
/**
 * vendas_helper.php
 * Funções auxiliares para o módulo de Vendas
 */

require_once __DIR__ . '/conexao.php';

/**
 * Verifica se o usuário é admin/Tiago
 * Usa o mesmo padrão do sistema (permissoes_boot.php)
 */
function vendas_is_admin(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verificar permissão administrativa
    if (!empty($_SESSION['perm_administrativo'])) {
        return true;
    }
    
    // Verificar se é admin por ID, login ou flag
    $usuario_id = (int)($_SESSION['id'] ?? 0);
    $login = $_SESSION['login'] ?? $_SESSION['usuario'] ?? '';
    
    if ($usuario_id === 1 || strtolower($login) === 'admin') {
        return true;
    }
    
    // Verificar flag is_admin na sessão (se existir)
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    
    return false;
}

/**
 * Busca locais mapeados via logistica_me_locais
 * Retorna apenas locais com status MAPEADO e com me_local_id válido
 */
function vendas_buscar_locais_mapeados(): array {
    $pdo = $GLOBALS['pdo'];
    
    try {
        $stmt = $pdo->query("
            SELECT 
                me_local_id,
                me_local_nome,
                space_visivel,
                unidade_interna_id
            FROM logistica_me_locais
            WHERE status_mapeamento = 'MAPEADO'
            AND me_local_id IS NOT NULL
            AND me_local_id > 0
            ORDER BY me_local_nome
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[VENDAS] Erro ao buscar locais mapeados: ' . $e->getMessage());
        return [];
    }
}

/**
 * Valida se um local está mapeado e retorna o me_local_id
 * Retorna null se não estiver mapeado
 */
function vendas_validar_local_mapeado(string $unidade_nome): ?int {
    $pdo = $GLOBALS['pdo'];
    
    try {
        // Tentar buscar por nome exato (case-insensitive)
        $stmt = $pdo->prepare("
            SELECT me_local_id
            FROM logistica_me_locais
            WHERE LOWER(me_local_nome) = LOWER(?)
            AND status_mapeamento = 'MAPEADO'
            AND me_local_id IS NOT NULL
            AND me_local_id > 0
            LIMIT 1
        ");
        $stmt->execute([$unidade_nome]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['me_local_id'])) {
            return (int)$result['me_local_id'];
        }
        
        return null;
    } catch (Exception $e) {
        error_log('[VENDAS] Erro ao validar local mapeado: ' . $e->getMessage());
        return null;
    }
}

/**
 * Busca o me_local_id a partir do nome do local/unidade
 * Usado na criação do evento na ME
 */
function vendas_obter_me_local_id(string $unidade_nome): ?int {
    return vendas_validar_local_mapeado($unidade_nome);
}

/**
 * Obtém o space_visivel do local (Logística > Conexão).
 * Ex.: "Lisbon 1", "DiverKids", "Lisbon Garden", "Cristal"
 */
function vendas_obter_space_visivel(string $unidade_nome): ?string {
    $pdo = $GLOBALS['pdo'];
    try {
        $stmt = $pdo->prepare("
            SELECT space_visivel
            FROM logistica_me_locais
            WHERE LOWER(me_local_nome) = LOWER(?)
            AND status_mapeamento = 'MAPEADO'
            LIMIT 1
        ");
        $stmt->execute([$unidade_nome]);
        $space = $stmt->fetchColumn();
        $space = is_string($space) ? trim($space) : '';
        return $space !== '' ? $space : null;
    } catch (Throwable $e) {
        error_log('[VENDAS] Erro ao obter space_visivel: ' . $e->getMessage());
        return null;
    }
}

/**
 * Distância mínima (em segundos) para conflito de agenda, baseada no space_visivel.
 * Regras:
 * - Lisbon (Lisbon 1): 2h
 * - Diverkids (DiverKids): 1h30
 * - Garden/Cristal (Lisbon Garden / Cristal): 3h
 */
function vendas_distancia_minima_conflito_segundos(string $unidade_nome): int {
    $space = vendas_obter_space_visivel($unidade_nome) ?? $unidade_nome;
    $spaceNorm = mb_strtolower(trim($space));

    if (strpos($spaceNorm, 'diver') !== false) {
        return (int)round(1.5 * 3600);
    }
    if (strpos($spaceNorm, 'garden') !== false || strpos($spaceNorm, 'cristal') !== false) {
        return 3 * 3600;
    }
    // Lisbon 1 (ou fallback)
    return 2 * 3600;
}

/**
 * Validação básica de CPF (dígitos verificadores).
 */
function vendas_validar_cpf(string $cpf): bool {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    if (preg_match('/(\d)\1{10}/', $cpf)) return false;

    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += ((int)$cpf[$c]) * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int)$cpf[$c] !== $d) return false;
    }
    return true;
}

/**
 * Helpers de schema (evitar fatals quando o SQL não foi aplicado).
 */
function vendas_has_table(PDO $pdo, string $table): bool {
    try {
        // Importante: em produção o projeto usa search_path (ex.: smilee12_painel_smile, public).
        // Então a checagem precisa respeitar o search_path, e não só "public".
        $stmt = $pdo->prepare("SELECT to_regclass(:t)");
        $stmt->execute([':t' => $table]);
        $reg = $stmt->fetchColumn();
        return is_string($reg) && trim($reg) !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function vendas_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        // Checar coluna via pg_attribute usando to_regclass (respeita search_path)
        $stmt = $pdo->prepare("
            SELECT 1
            FROM pg_attribute
            WHERE attrelid = to_regclass(:t)
              AND attname = :c
              AND NOT attisdropped
            LIMIT 1
        ");
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Garante que o schema do módulo Vendas exista (executa SQL 041/042 se necessário).
 * Retorna false se não conseguir garantir.
 */
function vendas_ensure_schema(PDO $pdo, array &$errors, array &$messages): bool {
    $requiredTables = [
        'vendas_pre_contratos',
        'vendas_adicionais',
        'vendas_anexos',
        'vendas_kanban_boards',
        'vendas_kanban_colunas',
        'vendas_kanban_cards',
        'vendas_kanban_historico',
        'vendas_logs',
    ];

    $missing = [];
    foreach ($requiredTables as $t) {
        if (!vendas_has_table($pdo, $t)) $missing[] = $t;
    }

    // Se faltam tabelas, NÃO executar SQL via web.
    // O provisionamento deve ser feito por migration/psql.
    if (!empty($missing)) {
        $sp = '';
        try { $sp = (string)$pdo->query("SHOW search_path")->fetchColumn(); } catch (Throwable $e) {}
        $errors[] = 'Base de Vendas ausente. Tabelas faltando: ' . implode(', ', $missing);
        if ($sp !== '') {
            $errors[] = 'search_path atual: ' . $sp;
        }
        $errors[] = 'Aplique as migrations: sql/041_modulo_vendas.sql e sql/042_vendas_ajustes.sql.';
        return false;
    }

    // Se tabelas existem mas faltam colunas do 042, tentar aplicar 042
    $cols042 = ['origem', 'rg', 'cep', 'endereco_completo', 'nome_noivos', 'num_convidados', 'como_conheceu', 'forma_pagamento', 'observacoes_internas', 'responsavel_comercial_id'];
    $needs042 = false;
    foreach ($cols042 as $c) {
        if (!vendas_has_column($pdo, 'vendas_pre_contratos', $c)) {
            $needs042 = true;
            break;
        }
    }

    if ($needs042) {
        $sql042 = __DIR__ . '/../sql/042_vendas_ajustes.sql';
        try {
            if (!is_file($sql042)) {
                $errors[] = 'Base de Vendas desatualizada. Execute o SQL sql/042_vendas_ajustes.sql.';
                return false;
            }
            $errors[] = 'Base de Vendas desatualizada. Execute o SQL sql/042_vendas_ajustes.sql.';
            return false;
        } catch (Throwable $e) {
            $errors[] = 'Base de Vendas desatualizada. Execute o SQL sql/042_vendas_ajustes.sql.';
            return false;
        }
    }

    return true;
}
