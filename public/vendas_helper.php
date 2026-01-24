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
 * Obtém o id do vendedor na ME (idvendedor) a partir do usuário interno.
 * Fonte: logistica_me_vendedores (Logística > Conexão).
 */
function vendas_obter_me_vendedor_id(int $usuario_interno_id): ?int {
    if ($usuario_interno_id <= 0) return null;
    $pdo = $GLOBALS['pdo'];

    try {
        $stmt = $pdo->prepare("
            SELECT me_vendedor_id
            FROM logistica_me_vendedores
            WHERE usuario_interno_id = ?
              AND status_mapeamento = 'MAPEADO'
            LIMIT 1
        ");
        $stmt->execute([$usuario_interno_id]);
        $id = (int)$stmt->fetchColumn();
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        error_log('[VENDAS] Erro ao obter idvendedor ME: ' . $e->getMessage());
        return null;
    }
}
