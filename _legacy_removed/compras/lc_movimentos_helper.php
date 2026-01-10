<?php
// lc_movimentos_helper.php
// Helper para gerenciar movimentos de estoque

/**
 * Registrar movimento de estoque
 */
function lc_registrar_movimento(PDO $pdo, array $dados): int {
    $stmt = $pdo->prepare("
        INSERT INTO lc_movimentos_estoque 
        (insumo_id, tipo, quantidade_base, unidade_digitada, quantidade_digitada, 
         fator_aplicado, referencia, observacao, custo_unitario, fornecedor_id, 
         usuario_id, usuario_nome, lista_id, evento_id, contagem_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $dados['insumo_id'],
        $dados['tipo'],
        $dados['quantidade_base'],
        $dados['unidade_digitada'] ?? null,
        $dados['quantidade_digitada'] ?? null,
        $dados['fator_aplicado'] ?? 1.0,
        $dados['referencia'] ?? null,
        $dados['observacao'] ?? null,
        $dados['custo_unitario'] ?? null,
        $dados['fornecedor_id'] ?? null,
        $dados['usuario_id'],
        $dados['usuario_nome'],
        $dados['lista_id'] ?? null,
        $dados['evento_id'] ?? null,
        $dados['contagem_id'] ?? null
    ]);
    
    return (int)$pdo->lastInsertId();
}

/**
 * Buscar movimentos de um insumo em um período
 */
function lc_buscar_movimentos_insumo(
    PDO $pdo, 
    int $insumo_id, 
    string $data_inicio, 
    string $data_fim,
    array $tipos_movimento = [],
    int $limite = 50,
    int $offset = 0
): array {
    $where_conditions = ["m.insumo_id = ?", "m.data_movimento BETWEEN ? AND ?", "m.ativo = true"];
    $params = [$insumo_id, $data_inicio, $data_fim];
    
    if (!empty($tipos_movimento)) {
        $placeholders = str_repeat('?,', count($tipos_movimento) - 1) . '?';
        $where_conditions[] = "m.tipo IN ($placeholders)";
        $params = array_merge($params, $tipos_movimento);
    }
    
    $where_sql = implode(' AND ', $where_conditions);
    
    $sql = "
        SELECT 
            m.*,
            i.nome as insumo_nome,
            i.unidade_padrao as insumo_unidade,
            f.nome as fornecedor_nome,
            (
                SELECT lc_calcular_saldo_insumo(m.insumo_id, m.data_movimento)
            ) as saldo_acumulado,
            CASE 
                WHEN m.custo_unitario IS NOT NULL THEN m.quantidade_base * m.custo_unitario
                ELSE NULL
            END as valor_movimento
        FROM lc_movimentos_estoque m
        JOIN lc_insumos i ON i.id = m.insumo_id
        LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
        WHERE $where_sql
        ORDER BY m.data_movimento ASC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limite;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Contar movimentos de um insumo em um período
 */
function lc_contar_movimentos_insumo(
    PDO $pdo, 
    int $insumo_id, 
    string $data_inicio, 
    string $data_fim,
    array $tipos_movimento = []
): int {
    $where_conditions = ["m.insumo_id = ?", "m.data_movimento BETWEEN ? AND ?", "m.ativo = true"];
    $params = [$insumo_id, $data_inicio, $data_fim];
    
    if (!empty($tipos_movimento)) {
        $placeholders = str_repeat('?,', count($tipos_movimento) - 1) . '?';
        $where_conditions[] = "m.tipo IN ($placeholders)";
        $params = array_merge($params, $tipos_movimento);
    }
    
    $where_sql = implode(' AND ', $where_conditions);
    
    $sql = "
        SELECT COUNT(*) 
        FROM lc_movimentos_estoque m
        WHERE $where_sql
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Calcular resumo de movimentos
 */
function lc_calcular_resumo_movimentos(
    PDO $pdo, 
    int $insumo_id, 
    string $data_inicio, 
    string $data_fim
): array {
    $stmt = $pdo->prepare("
        SELECT * FROM lc_calcular_saldo_insumo_data(?, ?, ?)
    ");
    $stmt->execute([$insumo_id, $data_inicio, $data_fim]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Registrar entrada de estoque
 */
function lc_registrar_entrada(
    PDO $pdo,
    int $insumo_id,
    float $quantidade_base,
    string $unidade_digitada,
    float $quantidade_digitada,
    float $fator_aplicado,
    string $referencia,
    string $observacao = '',
    ?float $custo_unitario = null,
    ?int $fornecedor_id = null,
    int $usuario_id,
    string $usuario_nome,
    ?int $lista_id = null
): int {
    return lc_registrar_movimento($pdo, [
        'insumo_id' => $insumo_id,
        'tipo' => 'entrada',
        'quantidade_base' => $quantidade_base,
        'unidade_digitada' => $unidade_digitada,
        'quantidade_digitada' => $quantidade_digitada,
        'fator_aplicado' => $fator_aplicado,
        'referencia' => $referencia,
        'observacao' => $observacao,
        'custo_unitario' => $custo_unitario,
        'fornecedor_id' => $fornecedor_id,
        'usuario_id' => $usuario_id,
        'usuario_nome' => $usuario_nome,
        'lista_id' => $lista_id
    ]);
}

/**
 * Registrar saída de estoque
 */
function lc_registrar_saida(
    PDO $pdo,
    int $insumo_id,
    float $quantidade_base,
    string $unidade_digitada,
    float $quantidade_digitada,
    float $fator_aplicado,
    string $referencia,
    string $observacao = '',
    ?float $custo_unitario = null,
    int $usuario_id,
    string $usuario_nome,
    ?int $lista_id = null,
    ?int $evento_id = null
): int {
    return lc_registrar_movimento($pdo, [
        'insumo_id' => $insumo_id,
        'tipo' => 'consumo_evento',
        'quantidade_base' => $quantidade_base,
        'unidade_digitada' => $unidade_digitada,
        'quantidade_digitada' => $quantidade_digitada,
        'fator_aplicado' => $fator_aplicado,
        'referencia' => $referencia,
        'observacao' => $observacao,
        'custo_unitario' => $custo_unitario,
        'usuario_id' => $usuario_id,
        'usuario_nome' => $usuario_nome,
        'lista_id' => $lista_id,
        'evento_id' => $evento_id
    ]);
}

/**
 * Registrar ajuste de estoque
 */
function lc_registrar_ajuste(
    PDO $pdo,
    int $insumo_id,
    string $tipo_ajuste, // 'entrada' ou 'saida'
    float $quantidade_base,
    string $unidade_digitada,
    float $quantidade_digitada,
    float $fator_aplicado,
    string $motivo,
    string $observacao = '',
    ?float $custo_unitario = null,
    int $usuario_id,
    string $usuario_nome
): int {
    return lc_registrar_movimento($pdo, [
        'insumo_id' => $insumo_id,
        'tipo' => $tipo_ajuste,
        'quantidade_base' => $quantidade_base,
        'unidade_digitada' => $unidade_digitada,
        'quantidade_digitada' => $quantidade_digitada,
        'fator_aplicado' => $fator_aplicado,
        'referencia' => 'Ajuste manual',
        'observacao' => $observacao,
        'custo_unitario' => $custo_unitario,
        'usuario_id' => $usuario_id,
        'usuario_nome' => $usuario_nome
    ]);
}

/**
 * Registrar perda de estoque
 */
function lc_registrar_perda(
    PDO $pdo,
    int $insumo_id,
    float $quantidade_base,
    string $unidade_digitada,
    float $quantidade_digitada,
    float $fator_aplicado,
    string $motivo,
    string $observacao = '',
    ?float $custo_unitario = null,
    int $usuario_id,
    string $usuario_nome
): int {
    return lc_registrar_movimento($pdo, [
        'insumo_id' => $insumo_id,
        'tipo' => 'perda',
        'quantidade_base' => $quantidade_base,
        'unidade_digitada' => $unidade_digitada,
        'quantidade_digitada' => $quantidade_digitada,
        'fator_aplicado' => $fator_aplicado,
        'referencia' => 'Perda registrada',
        'observacao' => $observacao,
        'custo_unitario' => $custo_unitario,
        'usuario_id' => $usuario_id,
        'usuario_nome' => $usuario_nome
    ]);
}

/**
 * Registrar devolução de estoque
 */
function lc_registrar_devolucao(
    PDO $pdo,
    int $insumo_id,
    float $quantidade_base,
    string $unidade_digitada,
    float $quantidade_digitada,
    float $fator_aplicado,
    string $motivo,
    string $observacao = '',
    ?float $custo_unitario = null,
    int $usuario_id,
    string $usuario_nome
): int {
    return lc_registrar_movimento($pdo, [
        'insumo_id' => $insumo_id,
        'tipo' => 'devolucao',
        'quantidade_base' => $quantidade_base,
        'unidade_digitada' => $unidade_digitada,
        'quantidade_digitada' => $quantidade_digitada,
        'fator_aplicado' => $fator_aplicado,
        'referencia' => 'Devolução registrada',
        'observacao' => $observacao,
        'custo_unitario' => $custo_unitario,
        'usuario_id' => $usuario_id,
        'usuario_nome' => $usuario_nome
    ]);
}

/**
 * Buscar saldo atual de um insumo
 */
function lc_buscar_saldo_insumo(PDO $pdo, int $insumo_id, ?string $data_limite = null): float {
    $data_limite = $data_limite ?? date('Y-m-d H:i:s');
    
    $stmt = $pdo->prepare("SELECT lc_calcular_saldo_insumo(?, ?)");
    $stmt->execute([$insumo_id, $data_limite]);
    return (float)$stmt->fetchColumn();
}

/**
 * Buscar insumos com saldo baixo
 */
function lc_buscar_insumos_saldo_baixo(PDO $pdo, float $percentual_alerta = 0.1): array {
    $stmt = $pdo->query("
        SELECT 
            i.id,
            i.nome,
            i.unidade_padrao,
            i.estoque_minimo,
            COALESCE((
                SELECT lc_calcular_saldo_insumo(i.id)
            ), 0) as saldo_atual,
            CASE 
                WHEN i.estoque_minimo > 0 THEN 
                    (COALESCE((
                        SELECT lc_calcular_saldo_insumo(i.id)
                    ), 0) / i.estoque_minimo) * 100
                ELSE 0
            END as percentual_cobertura
        FROM lc_insumos i
        WHERE i.ativo = true
        AND i.estoque_minimo > 0
        AND COALESCE((
            SELECT lc_calcular_saldo_insumo(i.id)
        ), 0) <= (i.estoque_minimo * (1 + $percentual_alerta))
        ORDER BY percentual_cobertura ASC
    ");
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Exportar movimentos para CSV
 */
function lc_exportar_movimentos_csv(
    PDO $pdo,
    int $insumo_id,
    string $data_inicio,
    string $data_fim,
    array $tipos_movimento = [],
    bool $incluir_custos = false
): string {
    $movimentos = lc_buscar_movimentos_insumo($pdo, $insumo_id, $data_inicio, $data_fim, $tipos_movimento, 10000, 0);
    
    $csv = "Data/Hora,Tipo,Quantidade (digitada),Quantidade (base),Saldo Acumulado,Referência,Observação,Usuário";
    
    if ($incluir_custos) {
        $csv .= ",Valor (R$)";
    }
    
    $csv .= "\n";
    
    foreach ($movimentos as $movimento) {
        $linha = [
            date('d/m/Y H:i', strtotime($movimento['data_movimento'])),
            ucfirst(str_replace('_', ' ', $movimento['tipo'])),
            number_format($movimento['quantidade_digitada'], 3) . ' ' . $movimento['unidade_digitada'],
            number_format($movimento['quantidade_base'], 3) . ' ' . $movimento['insumo_unidade'],
            number_format($movimento['saldo_acumulado'], 3) . ' ' . $movimento['insumo_unidade'],
            $movimento['referencia'],
            $movimento['observacao'],
            $movimento['usuario_nome']
        ];
        
        if ($incluir_custos) {
            $linha[] = $movimento['valor_movimento'] ? 'R$ ' . number_format($movimento['valor_movimento'], 2) : '—';
        }
        
        $csv .= '"' . implode('","', $linha) . '"' . "\n";
    }
    
    return $csv;
}

/**
 * Buscar movimentos por referência
 */
function lc_buscar_movimentos_por_referencia(
    PDO $pdo,
    string $referencia,
    int $limite = 100
): array {
    $stmt = $pdo->prepare("
        SELECT 
            m.*,
            i.nome as insumo_nome,
            i.unidade_padrao as insumo_unidade,
            f.nome as fornecedor_nome
        FROM lc_movimentos_estoque m
        JOIN lc_insumos i ON i.id = m.insumo_id
        LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
        WHERE m.referencia LIKE ? 
        AND m.ativo = true
        ORDER BY m.data_movimento DESC
        LIMIT ?
    ");
    
    $stmt->execute(['%' . $referencia . '%', $limite]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Reverter movimento (estornar)
 */
function lc_reverter_movimento(PDO $pdo, int $movimento_id, int $usuario_id, string $usuario_nome): bool {
    try {
        $pdo->beginTransaction();
        
        // Buscar movimento original
        $stmt = $pdo->prepare("SELECT * FROM lc_movimentos_estoque WHERE id = ? AND ativo = true");
        $stmt->execute([$movimento_id]);
        $movimento = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$movimento) {
            throw new Exception('Movimento não encontrado');
        }
        
        // Criar movimento de estorno
        $tipo_estorno = $movimento['tipo'] === 'entrada' ? 'saida' : 'entrada';
        
        $stmt = $pdo->prepare("
            INSERT INTO lc_movimentos_estoque 
            (insumo_id, tipo, quantidade_base, unidade_digitada, quantidade_digitada, 
             fator_aplicado, referencia, observacao, custo_unitario, fornecedor_id, 
             usuario_id, usuario_nome, lista_id, evento_id, contagem_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $movimento['insumo_id'],
            $tipo_estorno,
            $movimento['quantidade_base'],
            $movimento['unidade_digitada'],
            $movimento['quantidade_digitada'],
            $movimento['fator_aplicado'],
            'Estorno: ' . $movimento['referencia'],
            'Estorno do movimento #' . $movimento_id . ' - ' . $movimento['observacao'],
            $movimento['custo_unitario'],
            $movimento['fornecedor_id'],
            $usuario_id,
            $usuario_nome,
            $movimento['lista_id'],
            $movimento['evento_id'],
            $movimento['contagem_id']
        ]);
        
        // Marcar movimento original como inativo
        $stmt = $pdo->prepare("
            UPDATE lc_movimentos_estoque 
            SET ativo = false, modificado_em = NOW(), modificado_por = ?
            WHERE id = ?
        ");
        $stmt->execute([$usuario_id, $movimento_id]);
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Buscar estatísticas de movimentos
 */
function lc_buscar_estatisticas_movimentos(
    PDO $pdo,
    string $data_inicio,
    string $data_fim
): array {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_movimentos,
            COUNT(CASE WHEN tipo IN ('entrada', 'devolucao') THEN 1 END) as total_entradas,
            COUNT(CASE WHEN tipo IN ('consumo_evento', 'ajuste', 'perda') THEN 1 END) as total_saidas,
            SUM(CASE WHEN tipo IN ('entrada', 'devolucao') THEN quantidade_base ELSE 0 END) as volume_entradas,
            SUM(CASE WHEN tipo IN ('consumo_evento', 'ajuste', 'perda') THEN quantidade_base ELSE 0 END) as volume_saidas,
            COUNT(DISTINCT insumo_id) as insumos_movimentados,
            COUNT(DISTINCT usuario_id) as usuarios_ativos
        FROM lc_movimentos_estoque
        WHERE data_movimento BETWEEN ? AND ?
        AND ativo = true
    ");
    
    $stmt->execute([$data_inicio, $data_fim]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
