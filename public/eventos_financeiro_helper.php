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
            oculto BOOLEAN NOT NULL DEFAULT FALSE,
            created_by_user_id INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            deleted_at TIMESTAMP NULL,
            deleted_by_user_id INTEGER NULL
        )
    ");

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
            pago_em TIMESTAMP NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_pedidos_evento ON eventos_financeiro_pedidos(evento_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_evento ON eventos_financeiro_receitas(evento_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_financeiro_receitas_asaas ON eventos_financeiro_receitas(asaas_payment_id)");
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
               COALESCE(space_visivel, 'Não mapeado') AS space_visivel, COALESCE(convidados, 0) AS convidados
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
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM eventos_financeiro_pedidos WHERE evento_id = :evento_id");
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
        SELECT p.*, pe.nome AS pacote_nome
        FROM eventos_financeiro_pedidos p
        LEFT JOIN logistica_pacotes_evento pe ON pe.id = p.pacote_evento_id
        WHERE p.evento_id = :evento_id
        ORDER BY p.id DESC
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
    eventos_financeiro_ensure_schema($pdo);
    $descricao = trim($descricao);
    if ($descricao === '' && $pacoteId > 0) {
        $stmt = $pdo->prepare("SELECT nome FROM logistica_pacotes_evento WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $pacoteId]);
        $descricao = trim((string)$stmt->fetchColumn());
    }
    if ($descricao === '') {
        return ['ok' => false, 'error' => 'Informe a descrição ou selecione um pacote.'];
    }
    if ($valor <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO eventos_financeiro_pedidos (evento_id, pacote_evento_id, descricao, valor, created_by)
        VALUES (:evento_id, :pacote_evento_id, :descricao, :valor, :created_by)
    ");
    $stmt->execute([
        ':evento_id' => $eventoId,
        ':pacote_evento_id' => $pacoteId > 0 ? $pacoteId : null,
        ':descricao' => $descricao,
        ':valor' => $valor,
        ':created_by' => $userId > 0 ? $userId : null,
    ]);
    return ['ok' => true];
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

    $valorTotal = eventos_financeiro_money($dados['valor_total'] ?? 0);
    $parcelas = max(1, min(24, (int)($dados['parcelas'] ?? 1)));
    $primeiroVencimento = trim((string)($dados['primeiro_vencimento'] ?? date('Y-m-d')));
    $descricaoBase = trim((string)($dados['descricao'] ?? 'Receita do evento')) ?: 'Receita do evento';
    if ($valorTotal <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }

    $customerName = trim((string)($dados['cliente_nome'] ?? $evento['nome_evento'] ?? 'Cliente'));
    $customerEmail = trim((string)($dados['cliente_email'] ?? ''));
    $customerPhone = trim((string)($dados['cliente_telefone'] ?? ''));
    $customerCpf = preg_replace('/\D+/', '', (string)($dados['cliente_cpf_cnpj'] ?? ''));

    $asaas = new AsaasHelper();
    $valores = eventos_financeiro_split_parcelas($valorTotal, $parcelas);
    $created = 0;
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

        $pdo->beginTransaction();
        foreach ($valores as $index => $valorParcela) {
            $numero = $index + 1;
            $vencimento = date('Y-m-d', strtotime($primeiroVencimento . ' +' . $index . ' month'));
            $descricao = $parcelas > 1 ? $descricaoBase . " ({$numero}/{$parcelas})" : $descricaoBase;

            $stmt = $pdo->prepare("
                INSERT INTO eventos_financeiro_receitas
                    (evento_id, descricao, forma_pagamento, valor, parcela_numero, parcelas_total, vencimento, status, created_by)
                VALUES
                    (:evento_id, :descricao, 'pix_asaas', :valor, :parcela_numero, :parcelas_total, :vencimento, 'pendente', :created_by)
                RETURNING id
            ");
            $stmt->execute([
                ':evento_id' => $eventoId,
                ':descricao' => $descricao,
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
        $pdo->commit();
        return ['ok' => true, 'created' => $created];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
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
