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
            $pixgo = new PixGoHelper();
            $payment = $pixgo->createPayment([
                'amount' => (float)$calculo['total'],
                'description' => mb_substr($descricao . ($eventoNome !== '' ? ' - ' . $eventoNome : ''), 0, 200, 'UTF-8'),
                'external_id' => 'comercial_pagto:' . (int)$locked['id'],
                'receiver_name' => (string)$locked['pagador_nome'],
                'receiver_cpf' => (string)$locked['pagador_documento'],
                'receiver_email' => (string)($locked['pagador_email'] ?? ''),
                'receiver_phone' => (string)($locked['pagador_telefone'] ?? ''),
                'idempotency_key' => $idempotencyKey,
            ]);
            $paymentId = trim((string)($payment['payment_id'] ?? $payment['id'] ?? ''));
            if ($paymentId === '') {
                throw new RuntimeException('A PixGo não retornou o identificador da cobrança.');
            }

            $payloadJson = json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
                ':expires_at' => $payment['expires_at'] ?? '',
                ':payload' => $payloadJson,
            ]);
            $stmt = $pdo->prepare("
                UPDATE comercial_pagamento_solicitacoes
                SET status = :status,
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
                ':payment_id' => $paymentId,
                ':qr_code' => $payment['qr_code'] ?? null,
                ':qr_image_url' => $payment['qr_image_url'] ?? null,
                ':expires_at' => $payment['expires_at'] ?? '',
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
        :root { color-scheme: light; font-family: Inter, system-ui, -apple-system, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #f1f5f9; color: #172033; display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 620px); background: #fff; border-radius: 20px; padding: 28px; box-shadow: 0 18px 50px rgba(15, 23, 42, .12); }
        h1 { margin: 0 0 6px; font-size: 1.55rem; }
        .muted { color: #64748b; line-height: 1.5; }
        .summary { display: grid; gap: 10px; margin: 22px 0; }
        .row { display: flex; justify-content: space-between; gap: 1rem; padding: 10px 0; border-bottom: 1px solid #e5e7eb; }
        .row strong { color: #0f172a; }
        .total { font-size: 1.35rem; font-weight: 900; color: #0f766e; }
        .status { border-radius: 12px; padding: 12px 14px; font-weight: 800; background: #eff6ff; color: #1d4ed8; margin: 14px 0; }
        .status.pago { background: #ecfdf5; color: #047857; }
        .status.vencido, .status.cancelado { background: #fef2f2; color: #b91c1c; }
        .alert { border-radius: 12px; padding: 12px 14px; margin: 14px 0; background: #fef2f2; color: #991b1b; }
        .notice { border-radius: 12px; padding: 12px 14px; margin: 14px 0; background: #f0fdf4; color: #166534; }
        .disclosure { margin-top: 16px; padding: 12px; background: #f8fafc; border-radius: 10px; color: #475569; font-size: .88rem; line-height: 1.5; }
        button { width: 100%; border: 0; border-radius: 10px; padding: 14px 16px; background: #16a34a; color: #fff; font-weight: 900; cursor: pointer; font-size: 1rem; }
        button[disabled] { opacity: .6; cursor: wait; }
        .qr { display: block; width: min(100%, 300px); margin: 22px auto; border-radius: 12px; }
        textarea { width: 100%; min-height: 96px; resize: none; padding: 12px; border: 1px solid #cbd5e1; border-radius: 10px; font: 12px/1.4 ui-monospace, monospace; }
        .copy { margin-top: 10px; background: #2563eb; }
    </style>
</head>
<body>
<main>
    <h1>Solicitação de pagamento</h1>
    <p class="muted"><?= h((string)$solicitacao['descricao']) ?></p>

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
        <form method="post">
            <input type="hidden" name="action" value="gerar_pix">
            <button type="submit" id="generateBtn"><?= $status === 'vencido' ? 'Gerar novo Pix' : 'Gerar Pix' ?></button>
        </form>
    <?php endif; ?>

    <p class="disclosure">Ao gerar e efetuar o pagamento, o valor será processado via PIX pelos parceiros financeiros da PixGo e convertido em DEPIX, ativo digital pareado ao Real, para liquidação ao recebedor em carteira Liquid.</p>
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
