<?php
// lc_substitutes_helper.php
// Helper para gerenciar substitutos aprovados

/**
 * Buscar substitutos aprovados para um insumo
 */
function lc_buscar_substitutos(PDO $pdo, int $insumo_principal_id): array {
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.insumo_substituto_id,
            s.equivalencia,
            s.prioridade,
            s.observacao,
            s.ativo,
            i.nome as substituto_nome,
            i.unidade_padrao as substituto_unidade,
            i.preco as substituto_preco,
            i.fator_correcao as substituto_fator_correcao,
            i.embalagem_multiplo as substituto_embalagem,
            f.nome as substituto_fornecedor
        FROM lc_insumos_substitutos s
        JOIN lc_insumos i ON i.id = s.insumo_substituto_id
        LEFT JOIN fornecedores f ON f.id = i.fornecedor_id
        WHERE s.insumo_principal_id = :principal_id 
        AND s.ativo = true 
        AND i.ativo = true
        ORDER BY s.prioridade, i.nome
    ");
    
    $stmt->execute([':principal_id' => $insumo_principal_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Adicionar substituto
 */
function lc_adicionar_substituto(PDO $pdo, array $dados): int {
    // Validar se não há loop
    if (lc_verificar_loop_substitutos($pdo, $dados['insumo_principal_id'], $dados['insumo_substituto_id'])) {
        throw new Exception('Não é possível criar loop de substitutos (A→B e B→A).');
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO lc_insumos_substitutos 
        (insumo_principal_id, insumo_substituto_id, equivalencia, prioridade, observacao, ativo)
        VALUES (:principal_id, :substituto_id, :equivalencia, :prioridade, :observacao, :ativo)
    ");
    
    $stmt->execute([
        ':principal_id' => $dados['insumo_principal_id'],
        ':substituto_id' => $dados['insumo_substituto_id'],
        ':equivalencia' => $dados['equivalencia'],
        ':prioridade' => $dados['prioridade'],
        ':observacao' => $dados['observacao'] ?? null,
        ':ativo' => $dados['ativo'] ?? true
    ]);
    
    return (int)$pdo->lastInsertId();
}

/**
 * Atualizar substituto
 */
function lc_atualizar_substituto(PDO $pdo, int $substituto_id, array $dados): bool {
    $stmt = $pdo->prepare("
        UPDATE lc_insumos_substitutos 
        SET equivalencia = :equivalencia,
            prioridade = :prioridade,
            observacao = :observacao,
            ativo = :ativo
        WHERE id = :id
    ");
    
    return $stmt->execute([
        ':id' => $substituto_id,
        ':equivalencia' => $dados['equivalencia'],
        ':prioridade' => $dados['prioridade'],
        ':observacao' => $dados['observacao'] ?? null,
        ':ativo' => $dados['ativo'] ?? true
    ]);
}

/**
 * Remover substituto
 */
function lc_remover_substituto(PDO $pdo, int $substituto_id): bool {
    $stmt = $pdo->prepare("DELETE FROM lc_insumos_substitutos WHERE id = :id");
    return $stmt->execute([':id' => $substituto_id]);
}

/**
 * Verificar se há loop de substitutos
 */
function lc_verificar_loop_substitutos(PDO $pdo, int $principal_id, int $substituto_id): bool {
    // Verificar se já existe substituto_id → principal_id
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM lc_insumos_substitutos 
        WHERE insumo_principal_id = :substituto_id 
        AND insumo_substituto_id = :principal_id 
        AND ativo = true
    ");
    $stmt->execute([
        ':substituto_id' => $substituto_id,
        ':principal_id' => $principal_id
    ]);
    
    return $stmt->fetchColumn() > 0;
}

/**
 * Calcular quantidade de substituto necessária
 */
function lc_calcular_quantidade_substituto(
    float $necessidade_principal,
    float $equivalencia,
    float $percentual_cobertura = 100.0,
    ?float $embalagem_multiplo = null
): array {
    // Necessidade parcial baseada no percentual
    $necessidade_parcial = $necessidade_principal * ($percentual_cobertura / 100.0);
    
    // Necessidade bruta do substituto
    $necessidade_substituto = $necessidade_parcial * $equivalencia;
    
    // Aplicar arredondamento por embalagem
    $sugerido = $necessidade_substituto;
    if ($embalagem_multiplo && $embalagem_multiplo > 0) {
        $sugerido = ceil($necessidade_substituto / $embalagem_multiplo) * $embalagem_multiplo;
    } else {
        $sugerido = ceil($necessidade_substituto * 100) / 100; // Arredondar para cima
    }
    
    return [
        'necessidade_parcial' => $necessidade_parcial,
        'necessidade_substituto' => $necessidade_substituto,
        'sugerido' => $sugerido,
        'percentual_cobertura' => $percentual_cobertura
    ];
}

/**
 * Calcular custo do substituto
 */
function lc_calcular_custo_substituto(
    float $quantidade,
    float $preco,
    float $fator_correcao = 1.0
): float {
    return $quantidade * $preco * $fator_correcao;
}

/**
 * Validar compatibilidade de unidades
 */
function lc_validar_compatibilidade_unidades(
    string $unidade_principal,
    string $unidade_substituto,
    float $equivalencia
): array {
    $compativel = true;
    $aviso = '';
    
    // Verificar se as unidades são diferentes
    if ($unidade_principal !== $unidade_substituto) {
        $aviso = "Atenção: Unidades diferentes ({$unidade_principal} → {$unidade_substituto}). Verifique se a equivalência está correta.";
    }
    
    // Verificar se a equivalência é razoável
    if ($equivalencia <= 0) {
        $compativel = false;
        $aviso = "Equivalência deve ser maior que zero.";
    } elseif ($equivalencia > 10 || $equivalencia < 0.1) {
        $aviso = "Atenção: Equivalência muito alta ou baixa ({$equivalencia}). Verifique se está correto.";
    }
    
    return [
        'compativel' => $compativel,
        'aviso' => $aviso
    ];
}

/**
 * Buscar insumos disponíveis para substituição
 */
function lc_buscar_insumos_para_substituicao(PDO $pdo, int $insumo_principal_id): array {
    $stmt = $pdo->prepare("
        SELECT 
            i.id,
            i.nome,
            i.unidade_padrao,
            i.preco,
            i.fator_correcao,
            i.embalagem_multiplo,
            f.nome as fornecedor_nome
        FROM lc_insumos i
        LEFT JOIN fornecedores f ON f.id = i.fornecedor_id
        WHERE i.id != :principal_id 
        AND i.ativo = true
        ORDER BY i.nome
    ");
    
    $stmt->execute([':principal_id' => $insumo_principal_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Verificar se um insumo tem substitutos cadastrados
 */
function lc_tem_substitutos(PDO $pdo, int $insumo_id): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM lc_insumos_substitutos 
        WHERE insumo_principal_id = :id AND ativo = true
    ");
    $stmt->execute([':id' => $insumo_id]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Gerar observação de substituição
 */
function lc_gerar_observacao_substituicao(
    string $insumo_principal_nome,
    float $percentual_cobertura,
    float $equivalencia
): string {
    return "Substituto de: {$insumo_principal_nome} ({$percentual_cobertura}%, eq. {$equivalencia})";
}
?>
