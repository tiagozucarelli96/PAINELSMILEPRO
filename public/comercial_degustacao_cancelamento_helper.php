<?php

declare(strict_types=1);

const DEGUSTACAO_CANCELAMENTO_TIMEZONE = 'America/Sao_Paulo';
const DEGUSTACAO_CANCELAMENTO_PRAZO_DIAS = 2;

function degustacao_cancelamento_data_hora(?string $valor = null): DateTimeImmutable
{
    $timezone = new DateTimeZone(DEGUSTACAO_CANCELAMENTO_TIMEZONE);

    return $valor === null || trim($valor) === ''
        ? new DateTimeImmutable('now', $timezone)
        : new DateTimeImmutable($valor, $timezone);
}

/**
 * Cancela inscrições ativas que possuem cobrança e permanecem sem pagamento
 * por 48 horas. Inscrições gratuitas e pagas nunca entram nesta seleção.
 *
 * @return array{
 *     success: bool,
 *     dry_run: bool,
 *     prazo_dias: int,
 *     data_referencia: string,
 *     data_limite: string,
 *     candidatos: int,
 *     cancelados: int,
 *     inscricoes: array<int, array<string, mixed>>
 * }
 */
function degustacao_cancelamento_processar(PDO $pdo, array $opcoes = []): array
{
    $referencia = degustacao_cancelamento_data_hora($opcoes['ref_datetime'] ?? null);
    $limite = $referencia->modify('-' . DEGUSTACAO_CANCELAMENTO_PRAZO_DIAS . ' days');
    $dryRun = !empty($opcoes['dry_run']);

    $stmt = $pdo->prepare("
        SELECT id, degustacao_id, status, pagamento_status, criado_em
        FROM comercial_inscricoes
        WHERE status IN ('confirmado', 'lista_espera')
          AND pagamento_status IN ('aguardando', 'expirado')
          AND criado_em <= :data_limite
        ORDER BY id
    ");
    $stmt->execute([':data_limite' => $limite->format('Y-m-d H:i:s')]);
    $inscricoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cancelados = 0;
    if (!$dryRun && $inscricoes !== []) {
        $params = [];
        $placeholders = [];

        foreach ($inscricoes as $indice => $inscricao) {
            $placeholder = ':id_' . $indice;
            $placeholders[] = $placeholder;
            $params[$placeholder] = (int)$inscricao['id'];
        }

        // O status da inscrição é cancelado. A cobrança aguardando passa para
        // expirada, preservando compatibilidade com bancos antigos cujo CHECK
        // de pagamento_status ainda não inclui o valor "cancelado".
        $update = $pdo->prepare("
            UPDATE comercial_inscricoes
            SET status = 'cancelado',
                pagamento_status = CASE
                    WHEN pagamento_status = 'aguardando' THEN 'expirado'
                    ELSE pagamento_status
                END,
                atualizado_em = CURRENT_TIMESTAMP
            WHERE id IN (" . implode(', ', $placeholders) . ")
              AND status IN ('confirmado', 'lista_espera')
              AND pagamento_status IN ('aguardando', 'expirado')
        ");
        $update->execute($params);
        $cancelados = $update->rowCount();
    }

    return [
        'success' => true,
        'dry_run' => $dryRun,
        'prazo_dias' => DEGUSTACAO_CANCELAMENTO_PRAZO_DIAS,
        'data_referencia' => $referencia->format(DateTimeInterface::ATOM),
        'data_limite' => $limite->format(DateTimeInterface::ATOM),
        'candidatos' => count($inscricoes),
        'cancelados' => $cancelados,
        'inscricoes' => $inscricoes,
    ];
}
