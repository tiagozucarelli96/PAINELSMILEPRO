<?php
declare(strict_types=1);

require_once __DIR__ . '/config_env.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';

function comercial_pagamento_solicitacao_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comercial_pagamento_solicitacoes (
            id BIGSERIAL PRIMARY KEY,
            token VARCHAR(96) NOT NULL UNIQUE,
            evento_id BIGINT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE SET NULL,
            evento_nome TEXT NULL,
            descricao TEXT NOT NULL,
            valor_original NUMERIC(12,2) NOT NULL,
            vencimento DATE NOT NULL,
            multa_percent NUMERIC(6,3) NOT NULL DEFAULT 2.000,
            juros_mensal_percent NUMERIC(6,3) NOT NULL DEFAULT 1.000,
            pagador_nome VARCHAR(160) NOT NULL,
            pagador_documento VARCHAR(20) NOT NULL,
            pagador_email VARCHAR(255) NULL,
            pagador_telefone VARCHAR(40) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'solicitado',
            pixgo_payment_id VARCHAR(120) NULL,
            pixgo_qr_code TEXT NULL,
            pixgo_qr_image_url TEXT NULL,
            pixgo_expires_at TIMESTAMPTZ NULL,
            pixgo_idempotency_key VARCHAR(120) NULL,
            pixgo_payload JSONB NULL,
            ultimo_erro TEXT NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            pago_em TIMESTAMPTZ NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS comercial_pagamento_pixgo_tentativas (
            id BIGSERIAL PRIMARY KEY,
            solicitacao_id BIGINT NOT NULL REFERENCES comercial_pagamento_solicitacoes(id) ON DELETE CASCADE,
            payment_id VARCHAR(120) NOT NULL UNIQUE,
            idempotency_key VARCHAR(120) NOT NULL,
            valor_cobrado NUMERIC(12,2) NOT NULL,
            valor_original NUMERIC(12,2) NOT NULL,
            multa_valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            juros_valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            dias_atraso INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            qr_code TEXT NULL,
            qr_image_url TEXT NULL,
            expires_at TIMESTAMPTZ NULL,
            payload JSONB NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_pagamento_solicitacoes_evento ON comercial_pagamento_solicitacoes(evento_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_pagamento_solicitacoes_status ON comercial_pagamento_solicitacoes(status, created_at DESC)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_comercial_pagamento_solicitacoes_pixgo ON comercial_pagamento_solicitacoes(pixgo_payment_id) WHERE pixgo_payment_id IS NOT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comercial_pagamento_pixgo_tentativas_solicitacao ON comercial_pagamento_pixgo_tentativas(solicitacao_id, created_at DESC)");

    $done = true;
}

function comercial_pagamento_money($value): float
{
    return eventos_financeiro_money($value);
}

function comercial_pagamento_public_url(string $token): string
{
    return rtrim((string)APP_URL, '/') . '/index.php?page=comercial_solicitacao_pagamento_public&token=' . rawurlencode($token);
}

function comercial_pagamento_calcular_total(float $valorOriginal, string $vencimento, ?DateTimeImmutable $hoje = null): array
{
    $hoje = $hoje ?: new DateTimeImmutable('today');
    $dataVencimento = DateTimeImmutable::createFromFormat('!Y-m-d', $vencimento) ?: $hoje;
    $diasAtraso = max(0, (int)$dataVencimento->diff($hoje)->format('%r%a'));

    $valorOriginal = round(max(0, $valorOriginal), 2);
    $multa = $diasAtraso > 0 ? round($valorOriginal * 0.02, 2) : 0.0;
    $juros = $diasAtraso > 0 ? round($valorOriginal * 0.01 * ($diasAtraso / 30), 2) : 0.0;
    $total = round($valorOriginal + $multa + $juros, 2);

    return [
        'valor_original' => $valorOriginal,
        'dias_atraso' => $diasAtraso,
        'multa_valor' => $multa,
        'juros_valor' => $juros,
        'total' => $total,
    ];
}

function comercial_pagamento_buscar_por_token(PDO $pdo, string $token): ?array
{
    comercial_pagamento_solicitacao_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT s.*,
               e.data_evento::text AS evento_data,
               COALESCE(e.nome_evento, s.evento_nome, '') AS evento_nome_atual,
               COALESCE(e.localevento, '') AS evento_local
        FROM comercial_pagamento_solicitacoes s
        LEFT JOIN logistica_eventos_espelho e ON e.id = s.evento_id
        WHERE s.token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function comercial_pagamento_status_label(string $status): string
{
    return [
        'solicitado' => 'Aguardando geração do Pix',
        'gerando_pix' => 'Gerando Pix',
        'pendente' => 'Aguardando pagamento',
        'pago' => 'Pagamento confirmado',
        'vencido' => 'Pix expirado',
        'cancelado' => 'Cancelado',
    ][$status] ?? ucfirst($status);
}

function comercial_pagamento_atualizar_pixgo_payment(PDO $pdo, string $paymentId, array $paymentData = [], string $event = ''): bool
{
    comercial_pagamento_solicitacao_ensure_schema($pdo);
    if ($paymentId === '') {
        return false;
    }

    $statusLocal = eventos_financeiro_status_from_pixgo((string)($paymentData['status'] ?? ''), $event);
    $statusSolicitacao = $statusLocal === 'pendente' ? 'pendente' : $statusLocal;
    $payload = $paymentData ? json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

    $stmt = $pdo->prepare("
        UPDATE comercial_pagamento_pixgo_tentativas
        SET status = :status,
            payload = CASE WHEN :payload <> '' THEN CAST(:payload AS JSONB) ELSE payload END,
            updated_at = NOW()
        WHERE payment_id = :payment_id
    ");
    $stmt->execute([
        ':status' => $statusSolicitacao,
        ':payload' => $payload,
        ':payment_id' => $paymentId,
    ]);

    $stmt = $pdo->prepare("
        UPDATE comercial_pagamento_solicitacoes s
        SET status = :status,
            pago_em = CASE WHEN :status_pago = 'pago' THEN COALESCE(pago_em, NOW()) ELSE pago_em END,
            pixgo_payload = CASE WHEN :payload <> '' THEN CAST(:payload AS JSONB) ELSE pixgo_payload END,
            updated_at = NOW()
        WHERE s.pixgo_payment_id = :payment_id
           OR EXISTS (
                SELECT 1
                FROM comercial_pagamento_pixgo_tentativas t
                WHERE t.solicitacao_id = s.id
                  AND t.payment_id = :payment_id
           )
    ");
    $stmt->execute([
        ':status' => $statusSolicitacao,
        ':status_pago' => $statusSolicitacao,
        ':payload' => $payload,
        ':payment_id' => $paymentId,
    ]);

    return $stmt->rowCount() > 0;
}
