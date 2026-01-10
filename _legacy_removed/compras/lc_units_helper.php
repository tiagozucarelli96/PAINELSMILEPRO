<?php
// lc_units_helper.php
// Helper para conversão de unidades no módulo de contagem de estoque

/**
 * Carrega todas as unidades do sistema
 * @param PDO $pdo Conexão com banco
 * @return array Mapa de unidades [id => dados]
 */
function lc_load_unidades(PDO $pdo): array {
    $rows = $pdo->query("SELECT id, nome, simbolo, fator_base FROM lc_unidades ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) { 
        $map[(int)$r['id']] = $r; 
    }
    return $map;
}

/**
 * Converte quantidade para unidade base do insumo
 * @param float $qtd Quantidade digitada
 * @param float $fatorLinha Fator da unidade digitada
 * @param float $fatorBaseInsumo Fator da unidade base do insumo
 * @return float Quantidade convertida para base
 */
function lc_convert_to_base(float $qtd, float $fatorLinha, float $fatorBaseInsumo): float {
    if ($fatorLinha <= 0 || $fatorBaseInsumo <= 0) return 0.0;
    return $qtd * ($fatorLinha / $fatorBaseInsumo);
}

/**
 * Carrega insumos agrupados por categoria
 * @param PDO $pdo Conexão com banco
 * @return array Insumos agrupados por categoria
 */
function lc_load_insumos_por_categoria(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT 
            i.id, i.nome, i.unidade_padrao, i.preco, i.fator_correcao,
            c.id as categoria_id, c.nome as categoria_nome, c.ordem as categoria_ordem,
            u.simbolo as unidade_simbolo, u.fator_base as unidade_fator_base
        FROM lc_insumos i
        LEFT JOIN lc_categorias c ON c.id = i.categoria_id
        LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
        WHERE i.ativo = true
        ORDER BY c.ordem ASC, c.nome ASC, i.nome ASC
    ");
    
    $insumos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $agrupados = [];
    
    foreach ($insumos as $insumo) {
        $categoria = $insumo['categoria_nome'] ?: 'Sem Categoria';
        $agrupados[$categoria][] = $insumo;
    }
    
    return $agrupados;
}

/**
 * Calcula valor total do estoque baseado na última contagem
 * @param PDO $pdo Conexão com banco
 * @param int $contagem_id ID da contagem (opcional, usa a mais recente se não informado)
 * @return float Valor total em R$
 */
function lc_calcular_valor_estoque(PDO $pdo, int $contagem_id = null): float {
    if ($contagem_id) {
        $stmt = $pdo->prepare("
            SELECT SUM(ci.qtd_contada_base * i.preco * COALESCE(i.fator_correcao, 1.0)) as total
            FROM estoque_contagem_itens ci
            JOIN lc_insumos i ON i.id = ci.insumo_id
            WHERE ci.contagem_id = :contagem_id
        ");
        $stmt->execute([':contagem_id' => $contagem_id]);
    } else {
        // Usar a contagem fechada mais recente
        $stmt = $pdo->query("
            SELECT SUM(ci.qtd_contada_base * i.preco * COALESCE(i.fator_correcao, 1.0)) as total
            FROM estoque_contagem_itens ci
            JOIN lc_insumos i ON i.id = ci.insumo_id
            JOIN estoque_contagens c ON c.id = ci.contagem_id
            WHERE c.status = 'fechada'
            AND c.id = (
                SELECT MAX(id) FROM estoque_contagens WHERE status = 'fechada'
            )
        ");
    }
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return (float)($result['total'] ?? 0);
}
