<?php
/**
 * eventos_financeiro_helper.php
 * Financeiro isolado da Agenda Geral.
 */

require_once __DIR__ . '/core/helpers.php';

function eventos_financeiro_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS logistica_pacotes_evento (
            id BIGSERIAL PRIMARY KEY,
            nome VARCHAR(180) NOT NULL,
            descricao TEXT NULL,
            categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote',
            valor_venda NUMERIC(12,2) NULL,
            valor_pacote NUMERIC(12,2) NULL,
            pessoas_base INTEGER NULL,
            valor_convidado_adicional NUMERIC(12,2) NULL,
            oculto BOOLEAN NOT NULL DEFAULT FALSE,
            created_by_user_id INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            deleted_at TIMESTAMP NULL,
            deleted_by_user_id INTEGER NULL
        )
    ");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote'");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_venda NUMERIC(12,2) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_pacote NUMERIC(12,2) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS pessoas_base INTEGER NULL");
    $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_convidado_adicional NUMERIC(12,2) NULL");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_financeiro_pedidos (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            pacote_evento_id BIGINT NULL REFERENCES logistica_pacotes_evento(id) ON DELETE SET NULL,
            descricao TEXT NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_financeiro_receitas (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            descricao TEXT NOT NULL,
            forma_pagamento VARCHAR(30) NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            parcela_numero INTEGER NOT NULL DEFAULT 1,
            parcelas_total INTEGER NOT NULL DEFAULT 1,
            vencimento DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            asaas_payment_id VARCHAR(120) NULL,
            asaas_invoice_url TEXT NULL,
            asaas_payload JSONB NULL,
            carteira VARCHAR(20) NOT NULL DEFAULT 'manual',
            modo_pagamento VARCHAR(30) NULL,
            unidade VARCHAR(120) NULL,
            parcelamento_grupo VARCHAR(80) NULL,
            pago_em TIMESTAMP NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_pedidos_evento ON eventos_financeiro_pedidos(evento_id)");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS quantidade INTEGER NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS valor_base NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS valor_adicional NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS desconto NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS data_venda DATE NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS detalhes_html TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_pedidos ADD COLUMN IF NOT EXISTS deleted_by INTEGER NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_pedidos_evento_ativos ON eventos_financeiro_pedidos(evento_id, deleted_at)");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS carteira VARCHAR(20) NOT NULL DEFAULT 'manual'");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS modo_pagamento VARCHAR(30) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS unidade VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_financeiro_receitas ADD COLUMN IF NOT EXISTS parcelamento_grupo VARCHAR(80) NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_evento ON eventos_financeiro_receitas(evento_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_asaas ON eventos_financeiro_receitas(asaas_payment_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_unidade ON eventos_financeiro_receitas(unidade, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_grupo ON eventos_financeiro_receitas(parcelamento_grupo)");
    $done = true;
}

function eventos_financeiro_money($value): float
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return 0.0;
    }
    $raw = str_replace(['R$', ' '], '', $raw);
    if (preg_match('/,\d{1,2}$/', $raw) === 1) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }
    return round((float)$raw, 2);
}

function eventos_financeiro_split_parcelas(float $valorTotal, int $parcelas): array
{
    $parcelas = max(1, $parcelas);
    $totalCents = (int)round($valorTotal * 100);
    $base = intdiv($totalCents, $parcelas);
    $resto = $totalCents - ($base * $parcelas);
    $valores = [];
    for ($i = 1; $i <= $parcelas; $i++) {
        $cents = $base + ($i <= $resto ? 1 : 0);
        $valores[] = $cents / 100;
    }
    return $valores;
}

function eventos_financeiro_evento(PDO $pdo, int $eventoId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, me_event_id, data_evento::text AS data_evento, COALESCE(TO_CHAR(hora_inicio, 'HH24:MI'), '') AS hora_inicio,
               COALESCE(nome_evento, 'Evento') AS nome_evento, COALESCE(localevento, 'Local não informado') AS local_evento,
               COALESCE(space_visivel, 'Não mapeado') AS space_visivel, COALESCE(unidade_interna_id, 0) AS unidade_interna_id,
               COALESCE(convidados, 0) AS convidados
        FROM logistica_eventos_espelho
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function eventos_financeiro_resumo(PDO $pdo, int $eventoId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_pedidos WHERE evento_id = :evento_id AND deleted_at IS NULL");
    $stmt->execute([':evento_id' => $eventoId]);
    $contratado = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_receitas WHERE evento_id = :evento_id AND status <> 'cancelado'");
    $stmt->execute([':evento_id' => $eventoId]);
    $receitas = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_receitas WHERE evento_id = :evento_id AND status = 'pago'");
    $stmt->execute([':evento_id' => $eventoId]);
    $recebido = (float)$stmt->fetchColumn();

    $faltaReceber = max(0, $receitas - $recebido);
    $resultado = $contratado - $receitas;

    return [
        'contratado' => $contratado,
        'receitas' => $receitas,
        'recebido' => $recebido,
        'falta_receber' => $faltaReceber,
        'resultado' => $resultado,
    ];
}

function eventos_financeiro_listar_pedidos(PDO $pdo, int $eventoId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT p.*,
               pe.nome AS pacote_nome,
               pe.categoria AS pacote_categoria,
               pe.pessoas_base AS pacote_pessoas_base,
               pe.valor_convidado_adicional AS pacote_valor_convidado_adicional
        FROM eventos_financeiro_pedidos p
        LEFT JOIN logistica_pacotes_evento pe ON pe.id = p.pacote_evento_id
        WHERE p.evento_id = :evento_id
          AND p.deleted_at IS NULL
        ORDER BY COALESCE(p.data_venda, p.created_at::date) DESC, p.id DESC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_financeiro_listar_receitas(PDO $pdo, int $eventoId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_financeiro_receitas
        WHERE evento_id = :evento_id
        ORDER BY COALESCE(vencimento, created_at::date) ASC, id ASC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_financeiro_salvar_pedido(PDO $pdo, int $eventoId, int $pacoteId, string $descricao, float $valor, int $userId): array
{
    return eventos_financeiro_salvar_pedido_detalhado($pdo, $eventoId, 0, $pacoteId, $descricao, $valor, 1, 0.0, 0.0, null, '', $userId);
}

function eventos_financeiro_salvar_pedido_detalhado(
    PDO $pdo,
    int $eventoId,
    int $pedidoId,
    int $pacoteId,
    string $descricao,
    float $valorBase,
    int $quantidade,
    float $valorAdicional,
    float $desconto,
    ?string $dataVenda,
    string $detalhesHtml,
    int $userId
): array
{
    eventos_financeiro_ensure_schema($pdo);
    $descricao = trim($descricao);
    $detalhesHtml = trim($detalhesHtml);
    $quantidade = max(1, $quantidade);
    $valorBase = round(max(0, $valorBase), 2);
    $valorAdicional = round(max(0, $valorAdicional), 2);
    $desconto = round(max(0, $desconto), 2);

    if ($pacoteId > 0) {
        $stmt = $pdo->prepare("
            SELECT nome, descricao,
                   COALESCE(valor_pacote, valor_venda, 0) AS valor_padrao,
                   COALESCE(valor_convidado_adicional, 0) AS adicional_padrao
            FROM logistica_pacotes_evento
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $pacoteId]);
        $pacote = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($descricao === '') {
            $descricao = trim((string)($pacote['nome'] ?? ''));
        }
        if ($valorBase <= 0 && isset($pacote['valor_padrao'])) {
            $valorBase = (float)$pacote['valor_padrao'];
        }
        if ($valorAdicional <= 0 && isset($pacote['adicional_padrao'])) {
            $valorAdicional = (float)$pacote['adicional_padrao'];
        }
        if ($detalhesHtml === '' && trim((string)($pacote['descricao'] ?? '')) !== '') {
            $detalhesHtml = trim((string)$pacote['descricao']);
        }
    }
    if ($descricao === '') {
        return ['ok' => false, 'error' => 'Informe a descrição ou selecione um pacote.'];
    }
    $valor = round(max(0, ($valorBase * $quantidade) + $valorAdicional - $desconto), 2);
    if ($valor <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }

    if ($pedidoId > 0) {
        $stmt = $pdo->prepare("
            UPDATE eventos_financeiro_pedidos
            SET pacote_evento_id = :pacote_evento_id,
                descricao = :descricao,
                valor = :valor,
                quantidade = :quantidade,
                valor_base = :valor_base,
                valor_adicional = :valor_adicional,
                desconto = :desconto,
                data_venda = CAST(NULLIF(:data_venda, '') AS DATE),
                detalhes_html = :detalhes_html,
                updated_at = NOW()
            WHERE id = :id
              AND evento_id = :evento_id
              AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':id' => $pedidoId,
            ':evento_id' => $eventoId,
            ':pacote_evento_id' => $pacoteId > 0 ? $pacoteId : null,
            ':descricao' => $descricao,
            ':valor' => $valor,
            ':quantidade' => $quantidade,
            ':valor_base' => $valorBase,
            ':valor_adicional' => $valorAdicional,
            ':desconto' => $desconto,
            ':data_venda' => $dataVenda ?: '',
            ':detalhes_html' => $detalhesHtml !== '' ? $detalhesHtml : null,
        ]);
        return ['ok' => true, 'mode' => 'updated'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO eventos_financeiro_pedidos
            (evento_id, pacote_evento_id, descricao, valor, quantidade, valor_base, valor_adicional, desconto, data_venda, detalhes_html, created_by)
        VALUES
            (:evento_id, :pacote_evento_id, :descricao, :valor, :quantidade, :valor_base, :valor_adicional, :desconto,
             CAST(NULLIF(:data_venda, '') AS DATE), :detalhes_html, :created_by)
    ");
    $stmt->execute([
        ':evento_id' => $eventoId,
        ':pacote_evento_id' => $pacoteId > 0 ? $pacoteId : null,
        ':descricao' => $descricao,
        ':valor' => $valor,
        ':quantidade' => $quantidade,
        ':valor_base' => $valorBase,
        ':valor_adicional' => $valorAdicional,
        ':desconto' => $desconto,
        ':data_venda' => $dataVenda ?: '',
        ':detalhes_html' => $detalhesHtml !== '' ? $detalhesHtml : null,
        ':created_by' => $userId > 0 ? $userId : null,
    ]);
    return ['ok' => true];
}

function eventos_financeiro_excluir_pedido(PDO $pdo, int $eventoId, int $pedidoId, int $userId): bool
{
    eventos_financeiro_ensure_schema($pdo);
    if ($eventoId <= 0 || $pedidoId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("
        DELETE FROM eventos_financeiro_pedidos
        WHERE id = :id
          AND evento_id = :evento_id
    ");
    $stmt->execute([
        ':id' => $pedidoId,
        ':evento_id' => $eventoId,
    ]);
    return $stmt->rowCount() > 0;
}

function eventos_financeiro_salvar_receita_manual(PDO $pdo, int $eventoId, string $descricao, string $forma, float $valor, ?string $vencimento, string $status, int $userId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $formas = ['cartao', 'dinheiro', 'transferencia', 'outro'];
    $statusValidos = ['pendente', 'pago'];
    if (!in_array($forma, $formas, true)) {
        return ['ok' => false, 'error' => 'Forma de pagamento inválida.'];
    }
    if (!in_array($status, $statusValidos, true)) {
        $status = 'pendente';
    }
    if ($valor <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }
    $descricao = trim($descricao) ?: 'Receita do evento';

    $stmt = $pdo->prepare("
        INSERT INTO eventos_financeiro_receitas
            (evento_id, descricao, forma_pagamento, valor, vencimento, status, pago_em, created_by)
        VALUES
            (:evento_id, :descricao, :forma_pagamento, :valor, CAST(NULLIF(:vencimento, '') AS DATE), :status,
             CASE WHEN :status = 'pago' THEN NOW() ELSE NULL END, :created_by)
    ");
    $stmt->execute([
        ':evento_id' => $eventoId,
        ':descricao' => $descricao,
        ':forma_pagamento' => $forma,
        ':valor' => $valor,
        ':vencimento' => $vencimento ?: '',
        ':status' => $status,
        ':created_by' => $userId > 0 ? $userId : null,
    ]);
    return ['ok' => true];
}

function eventos_financeiro_normalizar_modo(string $modo, string $carteira): string
{
    $modo = trim($modo);
    $validosManual = ['pix', 'cartao_credito', 'dinheiro', 'cartao_debito', 'nao_informado'];
    if ($carteira === 'asaas') {
        return 'pix';
    }
    return in_array($modo, $validosManual, true) ? $modo : 'nao_informado';
}

function eventos_financeiro_salvar_receitas_manual_lote(PDO $pdo, int $eventoId, array $dados, int $userId): array
{
    eventos_financeiro_ensure_schema($pdo);
    $descricaoBase = trim((string)($dados['descricao'] ?? 'Receita do evento')) ?: 'Receita do evento';
    $unidade = trim((string)($dados['unidade'] ?? ''));
    $modo = eventos_financeiro_normalizar_modo((string)($dados['modo_pagamento'] ?? ''), 'manual');
    $status = (string)($dados['status_pagamento'] ?? 'pendente');
    $status = in_array($status, ['pendente', 'pago'], true) ? $status : 'pendente';
    $vencimentos = is_array($dados['parcela_vencimento'] ?? null) ? $dados['parcela_vencimento'] : [];
    $valores = is_array($dados['parcela_valor'] ?? null) ? $dados['parcela_valor'] : [];
    $totalParcelas = max(1, count($valores));
    $grupo = 'manual_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

    if (empty($valores)) {
        return ['ok' => false, 'error' => 'Informe pelo menos uma parcela.'];
    }

    $pdo->beginTransaction();
    try {
        foreach ($valores as $index => $valorRaw) {
            $valor = eventos_financeiro_money($valorRaw);
            if ($valor <= 0) {
                throw new RuntimeException('Todas as parcelas precisam ter valor maior que zero.');
            }
            $numero = $index + 1;
            $descricao = $totalParcelas > 1 ? $descricaoBase . " ({$numero}/{$totalParcelas})" : $descricaoBase;
            $vencimento = trim((string)($vencimentos[$index] ?? ''));
            $stmt = $pdo->prepare("
                INSERT INTO eventos_financeiro_receitas
                    (evento_id, descricao, forma_pagamento, carteira, modo_pagamento, unidade, parcelamento_grupo,
                     valor, parcela_numero, parcelas_total, vencimento, status, pago_em, created_by)
                VALUES
                    (:evento_id, :descricao, :forma_pagamento, 'manual', :modo_pagamento, :unidade, :parcelamento_grupo,
                     :valor, :parcela_numero, :parcelas_total, CAST(NULLIF(:vencimento, '') AS DATE), :status,
                     CASE WHEN :status = 'pago' THEN NOW() ELSE NULL END, :created_by)
            ");
            $stmt->execute([
                ':evento_id' => $eventoId,
                ':descricao' => $descricao,
                ':forma_pagamento' => $modo,
                ':modo_pagamento' => $modo,
                ':unidade' => $unidade !== '' ? $unidade : null,
                ':parcelamento_grupo' => $grupo,
                ':valor' => $valor,
                ':parcela_numero' => $numero,
                ':parcelas_total' => $totalParcelas,
                ':vencimento' => $vencimento,
                ':status' => $status,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
        }
        $pdo->commit();
        return ['ok' => true, 'created' => $totalParcelas];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function eventos_financeiro_status_from_asaas(string $status): string
{
    $status = strtoupper(trim($status));
    if (in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
        return 'pago';
    }
    if (in_array($status, ['OVERDUE'], true)) {
        return 'vencido';
    }
    if (in_array($status, ['DELETED', 'REFUNDED', 'REFUND_REQUESTED', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL'], true)) {
        return 'cancelado';
    }
    return 'pendente';
}

function eventos_financeiro_criar_pix_asaas(PDO $pdo, int $eventoId, array $evento, array $dados, int $userId): array
{
    eventos_financeiro_ensure_schema($pdo);
    require_once __DIR__ . '/asaas_helper.php';

    $valoresPost = is_array($dados['parcela_valor'] ?? null) ? $dados['parcela_valor'] : [];
    $vencimentosPost = is_array($dados['parcela_vencimento'] ?? null) ? $dados['parcela_vencimento'] : [];
    $valorTotal = eventos_financeiro_money($dados['valor_total'] ?? 0);
    $parcelas = max(1, min(24, (int)($dados['parcelas'] ?? count($valoresPost) ?: 1)));
    $primeiroVencimento = trim((string)($dados['primeiro_vencimento'] ?? date('Y-m-d')));
    $descricaoBase = trim((string)($dados['descricao'] ?? 'Receita do evento')) ?: 'Receita do evento';
    $unidade = trim((string)($dados['unidade'] ?? ($evento['space_visivel'] ?? '')));
    if (!empty($valoresPost)) {
        $valores = array_map('eventos_financeiro_money', $valoresPost);
        $parcelas = count($valores);
        $valorTotal = array_sum($valores);
    } else {
        $valores = eventos_financeiro_split_parcelas($valorTotal, $parcelas);
    }
    if ($valorTotal <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }

    $customerName = trim((string)($dados['cliente_nome'] ?? $evento['nome_evento'] ?? 'Cliente'));
    $customerEmail = trim((string)($dados['cliente_email'] ?? ''));
    $customerPhone = trim((string)($dados['cliente_telefone'] ?? ''));
    $customerCpf = preg_replace('/\D+/', '', (string)($dados['cliente_cpf_cnpj'] ?? ''));

    $asaas = new AsaasHelper();
    $created = 0;
    $grupo = 'asaas_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    try {
        $customer = $asaas->createCustomer([
            'name' => $customerName,
            'email' => $customerEmail,
            'phone' => $customerPhone,
            'cpf_cnpj' => $customerCpf,
            'external_reference' => 'evento:' . (int)($evento['me_event_id'] ?? $eventoId),
        ]);
        $customerId = (string)($customer['id'] ?? '');
        if ($customerId === '') {
            return ['ok' => false, 'error' => 'Não foi possível criar o cliente no Asaas.'];
        }

        foreach ($valores as $index => $valorParcela) {
            $numero = $index + 1;
            $vencimento = trim((string)($vencimentosPost[$index] ?? ''));
            if ($vencimento === '') {
                $vencimento = date('Y-m-d', strtotime($primeiroVencimento . ' +' . ($index * 30) . ' days'));
            }
            $descricao = $parcelas > 1 ? $descricaoBase . " ({$numero}/{$parcelas})" : $descricaoBase;

            $stmt = $pdo->prepare("
                INSERT INTO eventos_financeiro_receitas
                    (evento_id, descricao, forma_pagamento, carteira, modo_pagamento, unidade, parcelamento_grupo,
                     valor, parcela_numero, parcelas_total, vencimento, status, created_by)
                VALUES
                    (:evento_id, :descricao, 'pix_asaas', 'asaas', 'pix', :unidade, :parcelamento_grupo,
                     :valor, :parcela_numero, :parcelas_total, :vencimento, 'pendente', :created_by)
                RETURNING id
            ");
            $stmt->execute([
                ':evento_id' => $eventoId,
                ':descricao' => $descricao,
                ':unidade' => $unidade !== '' ? $unidade : null,
                ':parcelamento_grupo' => $grupo,
                ':valor' => $valorParcela,
                ':parcela_numero' => $numero,
                ':parcelas_total' => $parcelas,
                ':vencimento' => $vencimento,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
            $receitaId = (int)$stmt->fetchColumn();
            $externalReference = 'evento_financeiro_receita:' . $receitaId;

            $payment = $asaas->createPixPayment([
                'customer_id' => $customerId,
                'value' => $valorParcela,
                'due_date' => $vencimento,
                'description' => $descricao . ' - ' . (string)($evento['nome_evento'] ?? 'Evento'),
                'external_reference' => $externalReference,
            ]);

            $statusLocal = eventos_financeiro_status_from_asaas((string)($payment['status'] ?? ''));
            $stmt = $pdo->prepare("
                UPDATE eventos_financeiro_receitas
                SET asaas_payment_id = :asaas_payment_id,
                    asaas_invoice_url = :asaas_invoice_url,
                    asaas_payload = :asaas_payload,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':asaas_payment_id' => $payment['id'] ?? null,
                ':asaas_invoice_url' => $payment['invoiceUrl'] ?? $payment['bankSlipUrl'] ?? null,
                ':asaas_payload' => json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':status' => $statusLocal,
                ':id' => $receitaId,
            ]);
            $created++;
        }
        return ['ok' => true, 'created' => $created];
    } catch (Throwable $e) {
        if ($created > 0) {
            return [
                'ok' => true,
                'created' => $created,
                'warning' => 'Parte das cobranças foi criada, mas houve falha na sequência: ' . $e->getMessage(),
            ];
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function eventos_financeiro_atualizar_asaas_payment(PDO $pdo, string $paymentId, array $paymentData = []): bool
{
    eventos_financeiro_ensure_schema($pdo);
    if ($paymentId === '') {
        return false;
    }
    $status = eventos_financeiro_status_from_asaas((string)($paymentData['status'] ?? ''));
    $stmt = $pdo->prepare("
        UPDATE eventos_financeiro_receitas
        SET status = :status,
            pago_em = CASE WHEN :status = 'pago' THEN COALESCE(pago_em, NOW()) ELSE pago_em END,
            asaas_payload = CASE WHEN :payload <> '' THEN CAST(:payload AS JSONB) ELSE asaas_payload END,
            updated_at = NOW()
        WHERE asaas_payment_id = :payment_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':payload' => $paymentData ? json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ':payment_id' => $paymentId,
    ]);
    return $stmt->rowCount() > 0;
}

function eventos_formatura_financeiro_atualizar_asaas_payment(PDO $pdo, string $paymentId, array $paymentData = []): bool
{
    if ($paymentId === '') {
        return false;
    }

    $existsStmt = $pdo->query("SELECT to_regclass('public.eventos_formatura_financeiro')");
    if (!$existsStmt || !$existsStmt->fetchColumn()) {
        return false;
    }

    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS asaas_payment_id VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS asaas_payload JSONB NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pago_em TIMESTAMP NULL");

    $status = eventos_financeiro_status_from_asaas((string)($paymentData['status'] ?? ''));
    $stmt = $pdo->prepare("
        UPDATE eventos_formatura_financeiro
        SET status = :status,
            pago_em = CASE WHEN :status_pago = 'pago' THEN COALESCE(pago_em, NOW()) ELSE pago_em END,
            asaas_payload = CASE WHEN :payload <> '' THEN CAST(:payload AS JSONB) ELSE asaas_payload END,
            updated_at = NOW()
        WHERE asaas_payment_id = :payment_id
    ");
    $stmt->execute([
        ':status' => $status,
        ':status_pago' => $status,
        ':payload' => $paymentData ? json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ':payment_id' => $paymentId,
    ]);
    return $stmt->rowCount() > 0;
}
