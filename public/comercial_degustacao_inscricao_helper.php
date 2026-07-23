<?php

declare(strict_types=1);

/**
 * Localiza uma inscrição anterior que impeça um cliente com contrato fechado
 * de participar de uma segunda degustação.
 *
 * A identificação prioriza os dados validados na ME Eventos (evento e CPF) e
 * também usa o e-mail normalizado. Inscrições canceladas e inscrições da
 * degustação atual não são consideradas.
 */
function degustacao_inscricao_buscar_bloqueio_anterior(
    PDO $pdo,
    int $degustacaoId,
    string $fechouContrato,
    string $email,
    int $meEventId,
    string $meClienteCpf,
    array $colunasExistentes
): ?array {
    $emailNormalizado = strtolower(trim($email));
    $cpfNormalizado = preg_replace('/\D/', '', $meClienteCpf) ?? '';
    $identificadores = [];
    $params = [':degustacao_id_atual' => $degustacaoId];

    if ($emailNormalizado !== '' && in_array('email', $colunasExistentes, true)) {
        $identificadores[] = 'LOWER(TRIM(email)) = :email';
        $params[':email'] = $emailNormalizado;
    }

    if ($meEventId > 0 && in_array('me_event_id', $colunasExistentes, true)) {
        $identificadores[] = 'me_event_id = :me_event_id';
        $params[':me_event_id'] = $meEventId;
    }

    if (strlen($cpfNormalizado) === 11 && in_array('me_cliente_cpf', $colunasExistentes, true)) {
        $identificadores[] = 'me_cliente_cpf = :me_cliente_cpf';
        $params[':me_cliente_cpf'] = $cpfNormalizado;
    }

    if ($degustacaoId <= 0
        || !in_array('degustacao_id', $colunasExistentes, true)
        || !in_array('fechou_contrato', $colunasExistentes, true)
        || $identificadores === []
    ) {
        return null;
    }

    $where = [
        'degustacao_id <> :degustacao_id_atual',
        '(' . implode(' OR ', $identificadores) . ')',
    ];

    if (in_array('status', $colunasExistentes, true)) {
        $where[] = "COALESCE(status, '') <> 'cancelado'";
    }

    if (in_array('pagamento_status', $colunasExistentes, true)) {
        $where[] = "COALESCE(pagamento_status, '') <> 'cancelado'";
    }

    // Se a inscrição atual informa contrato fechado, qualquer participação
    // anterior bloqueia. Caso contrário, um contrato já registrado no
    // histórico também impede que a regra seja contornada pelo formulário.
    if ($fechouContrato !== 'sim') {
        $where[] = "fechou_contrato = 'sim'";
    }

    $select = ['id', 'degustacao_id'];
    foreach (['status', 'pagamento_status', 'fechou_contrato'] as $coluna) {
        if (in_array($coluna, $colunasExistentes, true)) {
            $select[] = $coluna;
        }
    }

    $sql = 'SELECT ' . implode(', ', $select)
        . ' FROM comercial_inscricoes'
        . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY id DESC LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inscricao = $stmt->fetch(PDO::FETCH_ASSOC);

    return $inscricao ?: null;
}
