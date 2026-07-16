<?php
declare(strict_types=1);

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/pixgo_helper.php';
require_once __DIR__ . '/comercial_pagamento_solicitacao_helper.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https://pixgo.org data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token) || !$pdo instanceof PDO) {
    http_response_code(404);
    exit('Solicitação não encontrada.');
}

$solicitacao = comercial_pagamento_buscar_por_token($pdo, $token);
if (!$solicitacao) {
    http_response_code(404);
    exit('Solicitação não encontrada.');
}

$error = '';
$notice = '';
$payerDocumentInput = trim((string)($_POST['pagador_documento_conta'] ?? ''));

function comercial_public_payment_pending(array $row): bool
{
    $status = (string)($row['status'] ?? '');
    $expiresAt = trim((string)($row['pixgo_expires_at'] ?? ''));
    $expired = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
    return $status === 'pendente' && !$expired && trim((string)($row['pixgo_payment_id'] ?? '')) !== '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'gerar_pix') {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT * FROM comercial_pagamento_solicitacoes WHERE token = :token FOR UPDATE");
        $stmt->execute([':token' => $token]);
        $locked = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$locked) {
            throw new RuntimeException('Solicitação não encontrada.');
        }
        if ((string)$locked['status'] === 'pago') {
            $pdo->commit();
            $notice = 'Este pagamento já foi confirmado.';
        } elseif (comercial_public_payment_pending($locked)) {
            $pdo->commit();
            $notice = 'Já existe um Pix válido para esta solicitação.';
        } else {
            $payerDocument = preg_replace('/\D+/', '', $payerDocumentInput);
            if (!eventos_financeiro_documento_valido($payerDocument)) {
                throw new RuntimeException('Informe um CPF/CNPJ válido da conta que fará o Pix.');
            }

            $stmt = $pdo->prepare("UPDATE comercial_pagamento_solicitacoes SET status = 'gerando_pix', ultimo_erro = NULL, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => (int)$locked['id']]);
            $pdo->commit();

            $calculo = comercial_pagamento_calcular_total((float)$locked['valor_original'], (string)$locked['vencimento']);
            if ((float)$calculo['total'] < 10) {
                throw new RuntimeException('O valor mínimo para gerar PixGo é R$ 10,00.');
            }

            $idempotencyKey = 'smile-comercial-' . (int)$locked['id'] . '-' . bin2hex(random_bytes(8));
            $descricao = trim((string)$locked['descricao']);
            $eventoNome = trim((string)($locked['evento_nome'] ?? ''));
            $payerName = trim((string)($locked['pagador_nome'] ?? ''));
            if ($payerName === '') {
                $payerName = 'Cliente Smile Eventos';
            }
            $pixgo = new PixGoHelper();
            $payment = $pixgo->createPayment([
                'amount' => (float)$calculo['total'],
                'description' => mb_substr($descricao . ($eventoNome !== '' ? ' - ' . $eventoNome : ''), 0, 200, 'UTF-8'),
                'external_id' => 'comercial_pagto:' . (int)$locked['id'],
                'receiver_name' => $payerName,
                'receiver_cpf' => $payerDocument,
                'receiver_email' => (string)($locked['pagador_email'] ?? ''),
                'receiver_phone' => (string)($locked['pagador_telefone'] ?? ''),
                'idempotency_key' => $idempotencyKey,
            ]);
            $paymentId = trim((string)($payment['payment_id'] ?? $payment['id'] ?? ''));
            if ($paymentId === '') {
                throw new RuntimeException('A PixGo não retornou o identificador da cobrança.');
            }

            $payloadJson = json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $expiresAt = PixGoHelper::normalizeTimestampForDatabase((string)($payment['expires_at'] ?? ''));
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO comercial_pagamento_pixgo_tentativas
                    (solicitacao_id, payment_id, idempotency_key, valor_cobrado, valor_original,
                     multa_valor, juros_valor, dias_atraso, status, qr_code, qr_image_url, expires_at, payload)
                VALUES
                    (:solicitacao_id, :payment_id, :idempotency_key, :valor_cobrado, :valor_original,
                     :multa_valor, :juros_valor, :dias_atraso, :status, :qr_code, :qr_image_url,
                     CAST(NULLIF(:expires_at, '') AS TIMESTAMPTZ), CAST(:payload AS JSONB))
            ");
            $stmt->execute([
                ':solicitacao_id' => (int)$locked['id'],
                ':payment_id' => $paymentId,
                ':idempotency_key' => $idempotencyKey,
                ':valor_cobrado' => (float)$calculo['total'],
                ':valor_original' => (float)$calculo['valor_original'],
                ':multa_valor' => (float)$calculo['multa_valor'],
                ':juros_valor' => (float)$calculo['juros_valor'],
                ':dias_atraso' => (int)$calculo['dias_atraso'],
                ':status' => eventos_financeiro_status_from_pixgo((string)($payment['status'] ?? 'pending')),
                ':qr_code' => $payment['qr_code'] ?? null,
                ':qr_image_url' => $payment['qr_image_url'] ?? null,
                ':expires_at' => $expiresAt,
                ':payload' => $payloadJson,
            ]);
            $stmt = $pdo->prepare("
                UPDATE comercial_pagamento_solicitacoes
                SET status = :status,
                    pagador_documento = :pagador_documento,
                    pixgo_payment_id = :payment_id,
                    pixgo_qr_code = :qr_code,
                    pixgo_qr_image_url = :qr_image_url,
                    pixgo_expires_at = CAST(NULLIF(:expires_at, '') AS TIMESTAMPTZ),
                    pixgo_idempotency_key = :idempotency_key,
                    pixgo_payload = CAST(:payload AS JSONB),
                    ultimo_erro = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => eventos_financeiro_status_from_pixgo((string)($payment['status'] ?? 'pending')),
                ':pagador_documento' => $payerDocument,
                ':payment_id' => $paymentId,
                ':qr_code' => $payment['qr_code'] ?? null,
                ':qr_image_url' => $payment['qr_image_url'] ?? null,
                ':expires_at' => $expiresAt,
                ':idempotency_key' => $idempotencyKey,
                ':payload' => $payloadJson,
                ':id' => (int)$locked['id'],
            ]);
            $pdo->commit();
            $notice = 'Pix gerado. Use o QR Code ou o Copia e Cola abaixo.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        try {
            $stmt = $pdo->prepare("UPDATE comercial_pagamento_solicitacoes SET status = 'solicitado', ultimo_erro = :erro, updated_at = NOW() WHERE token = :token AND status = 'gerando_pix'");
            $stmt->execute([':erro' => $error, ':token' => $token]);
        } catch (Throwable $ignored) {
        }
    }
    $solicitacao = comercial_pagamento_buscar_por_token($pdo, $token) ?: $solicitacao;
}

$calculo = comercial_pagamento_calcular_total((float)$solicitacao['valor_original'], (string)$solicitacao['vencimento']);
$status = (string)($solicitacao['status'] ?? 'solicitado');
$expiresAt = trim((string)($solicitacao['pixgo_expires_at'] ?? ''));
$expiredByTime = $expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time();
if ($status === 'pendente' && $expiredByTime) {
    $status = 'vencido';
}
$qrImageUrl = trim((string)($solicitacao['pixgo_qr_image_url'] ?? ''));
$qrHost = strtolower((string)parse_url($qrImageUrl, PHP_URL_HOST));
if ($qrHost !== 'pixgo.org' && !str_ends_with($qrHost, '.pixgo.org')) {
    $qrImageUrl = '';
}
$qrCode = trim((string)($solicitacao['pixgo_qr_code'] ?? ''));
$canGenerate = !in_array($status, ['pago', 'gerando_pix'], true);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($status === 'pendente'): ?><meta http-equiv="refresh" content="10"><?php endif; ?>
    <title>Solicitação de pagamento - Smile Eventos</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            --smile-blue: #1e3a8a;
            --smile-blue-soft: #2563eb;
            --smile-green: #16a34a;
            --smile-teal: #0f766e;
            --ink: #172033;
            --muted: #64748b;
            --line: #e2e8f0;
            --paper: #ffffff;
            --surface: #f8fafc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            display: grid;
            place-items: center;
            padding: 28px;
            background:
                radial-gradient(circle at 18% 18%, rgba(37, 99, 235, .14), transparent 34%),
                linear-gradient(135deg, #eef4ff 0%, #f8fafc 46%, #e8f7f1 100%);
        }
        main {
            width: min(100%, 640px);
            background: var(--paper);
            border: 1px solid rgba(226, 232, 240, .85);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(30, 58, 138, .18);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 24px 28px;
            background: linear-gradient(135deg, var(--smile-blue), var(--smile-blue-soft));
            color: #fff;
        }
        .brand img {
            width: 58px;
            height: 58px;
            object-fit: contain;
            border-radius: 14px;
            background: #fff;
            padding: 8px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .18);
        }
        .brand-kicker { margin: 0 0 4px; font-size: .78rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; opacity: .86; }
        h1 { margin: 0; font-size: 1.55rem; line-height: 1.18; }
        .content { padding: 28px; }
        .muted { color: var(--muted); line-height: 1.5; }
        .description { margin: 0 0 8px; font-weight: 700; color: #334155; }
        .summary {
            display: grid;
            gap: 0;
            margin: 22px 0;
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            background: var(--surface);
        }
        .row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 13px 16px;
            border-bottom: 1px solid var(--line);
            background: #fff;
        }
        .row strong { color: #0f172a; font-weight: 900; text-align: right; }
        .row span { color: #475569; font-weight: 650; }
        .total {
            font-size: 1.28rem;
            font-weight: 900;
            color: var(--smile-teal);
            background: linear-gradient(90deg, #ecfdf5, #fff);
            border-bottom: 0;
        }
        .total span, .total strong { color: var(--smile-teal); }
        .status { border-radius: 12px; padding: 12px 14px; font-weight: 800; background: #eff6ff; color: #1d4ed8; margin: 14px 0; }
        .status.pago { background: #ecfdf5; color: #047857; }
        .status.vencido, .status.cancelado { background: #fef2f2; color: #b91c1c; }
        .alert { border-radius: 12px; padding: 12px 14px; margin: 14px 0; background: #fef2f2; color: #991b1b; }
        .notice { border-radius: 12px; padding: 12px 14px; margin: 14px 0; background: #f0fdf4; color: #166534; }
        button {
            width: 100%;
            border: 0;
            border-radius: 12px;
            padding: 15px 16px;
            background: linear-gradient(135deg, var(--smile-green), #15803d);
            color: #fff;
            font-weight: 900;
            cursor: pointer;
            font-size: 1rem;
            box-shadow: 0 12px 28px rgba(22, 163, 74, .25);
        }
        button:hover { filter: brightness(.98); transform: translateY(-1px); }
        button[disabled] { opacity: .6; cursor: wait; }
        .qr {
            display: block;
            width: min(100%, 300px);
            margin: 22px auto;
            border-radius: 16px;
            border: 1px solid var(--line);
            padding: 10px;
            background: #fff;
        }
        textarea { width: 100%; min-height: 96px; resize: none; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px; font: 12px/1.4 ui-monospace, monospace; background: #f8fafc; }
        .payer-document-form { display: grid; gap: 12px; margin-top: 16px; }
        .payer-document-form label { font-weight: 900; color: #334155; }
        .payer-document-form input { width: 100%; border: 1px solid #cbd5e1; border-radius: 12px; padding: 13px 14px; font: inherit; background: #fff; }
        .payer-warning { border: 1px solid #fed7aa; border-radius: 12px; padding: 12px 14px; background: #fff7ed; color: #9a3412; line-height: 1.45; font-size: .92rem; }
        .copy { margin-top: 10px; background: linear-gradient(135deg, var(--smile-blue), var(--smile-blue-soft)); box-shadow: 0 12px 28px rgba(37, 99, 235, .22); }
        .footer-note { margin: 18px 0 0; text-align: center; color: #94a3b8; font-size: .82rem; }
        @media (max-width: 560px) {
            body { padding: 14px; align-items: start; }
            .brand { padding: 20px; }
            .brand img { width: 50px; height: 50px; }
            .content { padding: 20px; }
            .row { align-items: flex-start; flex-direction: column; gap: 5px; }
            .row strong { text-align: left; }
        }
    </style>
</head>
<body>
<main>
    <div class="brand">
        <img src="logo-smile.png" alt="Smile Eventos">
        <div>
            <p class="brand-kicker">Grupo Smile Eventos</p>
            <h1>Solicitação de pagamento</h1>
        </div>
    </div>
    <div class="content">
    <p class="description"><?= h((string)$solicitacao['descricao']) ?></p>

    <?php if (!empty($solicitacao['evento_nome_atual'])): ?>
        <p class="muted"><strong>Evento:</strong> <?= h((string)$solicitacao['evento_nome_atual']) ?><?= !empty($solicitacao['evento_data']) ? ' · ' . h(brDateOnly((string)$solicitacao['evento_data'])) : '' ?></p>
    <?php endif; ?>

    <div class="summary">
        <div class="row"><span>Valor original</span><strong><?= h(format_currency($calculo['valor_original'])) ?></strong></div>
        <div class="row"><span>Vencimento</span><strong><?= h(brDateOnly((string)$solicitacao['vencimento'])) ?></strong></div>
        <div class="row"><span>Multa 2%</span><strong><?= h(format_currency($calculo['multa_valor'])) ?></strong></div>
        <div class="row"><span>Juros 1% ao mês</span><strong><?= h(format_currency($calculo['juros_valor'])) ?></strong></div>
        <div class="row"><span>Dias em atraso</span><strong><?= (int)$calculo['dias_atraso'] ?></strong></div>
        <div class="row total"><span>Total para hoje</span><strong><?= h(format_currency($calculo['total'])) ?></strong></div>
    </div>

    <div class="status <?= h($status) ?>"><?= h(comercial_pagamento_status_label($status)) ?></div>
    <?php if ($error !== ''): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?>

    <?php if ($status === 'pendente' && $qrCode !== ''): ?>
        <?php if ($qrImageUrl !== ''): ?><img class="qr" src="<?= h($qrImageUrl) ?>" alt="QR Code PIX"><?php endif; ?>
        <label for="pixCode"><strong>PIX Copia e Cola</strong></label>
        <textarea id="pixCode" readonly><?= h($qrCode) ?></textarea>
        <button class="copy" type="button" id="copyPix">Copiar código PIX</button>
        <?php if ($expiresAt !== ''): ?><p class="muted">Válido até <?= h(date('d/m/Y H:i', strtotime($expiresAt))) ?>. Esta página atualiza automaticamente.</p><?php endif; ?>
    <?php elseif ($canGenerate): ?>
        <form method="post" class="payer-document-form">
            <input type="hidden" name="action" value="gerar_pix">
            <label for="payerDocument">CPF/CNPJ da conta pagadora</label>
            <input
                id="payerDocument"
                name="pagador_documento_conta"
                inputmode="numeric"
                autocomplete="off"
                placeholder="Digite somente números"
                value="<?= h($payerDocumentInput) ?>"
                required
            >
            <div class="payer-warning">
                Digite o CPF/CNPJ da conta que fará o pagamento. Se o Pix for pago por uma conta de outro CPF/CNPJ, ele poderá ser rejeitado e extornado pela PixGo.
            </div>
            <button type="submit" id="generateBtn"><?= $status === 'vencido' ? 'Gerar novo Pix' : 'Gerar Pix' ?></button>
        </form>
    <?php endif; ?>

    <p class="footer-note">Ambiente seguro Smile Eventos</p>
    </div>
</main>
<script>
document.getElementById('generateBtn')?.closest('form')?.addEventListener('submit', () => {
    const btn = document.getElementById('generateBtn');
    btn.disabled = true;
    btn.textContent = 'Gerando Pix...';
});
document.getElementById('copyPix')?.addEventListener('click', async () => {
    const field = document.getElementById('pixCode');
    try {
        await navigator.clipboard.writeText(field.value);
    } catch (_) {
        field.select();
        document.execCommand('copy');
    }
    document.getElementById('copyPix').textContent = 'Código copiado';
});
</script>
</body>
</html>
