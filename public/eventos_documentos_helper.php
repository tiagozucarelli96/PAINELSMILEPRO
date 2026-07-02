<?php
/**
 * eventos_documentos_helper.php
 * Documentos e tags gerais por evento.
 */

require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/contratos_modelos_helper.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';

function eventos_documentos_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eventos_documentos_token(): string
{
    return bin2hex(random_bytes(24));
}

function eventos_documentos_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    contratos_modelos_ensure_schema($pdo);
    eventos_financeiro_ensure_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_documentos (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            modelo_id BIGINT NULL REFERENCES contrato_modelos(id) ON DELETE SET NULL,
            titulo VARCHAR(220) NOT NULL,
            conteudo_html TEXT NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'criado',
            minuta_token VARCHAR(80) NULL UNIQUE,
            assinaturas_realizadas INTEGER NOT NULL DEFAULT 0,
            assinaturas_total INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            deleted_at TIMESTAMP NULL,
            deleted_by INTEGER NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_documentos_evento ON eventos_documentos(evento_id, deleted_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_documentos_token ON eventos_documentos(minuta_token)");
    $pdo->exec("ALTER TABLE eventos_documentos ADD COLUMN IF NOT EXISTS minuta_aprovada_em TIMESTAMP NULL");
    $pdo->exec("ALTER TABLE eventos_documentos ADD COLUMN IF NOT EXISTS minuta_aprovada_ip VARCHAR(80) NULL");
    $done = true;
}

function eventos_documentos_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT to_regclass(:table) IS NOT NULL");
    $stmt->execute([':table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function eventos_documentos_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table
          AND column_name = :column
        LIMIT 1
    ");
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (bool)$stmt->fetchColumn();
}

function eventos_documentos_modelos(PDO $pdo): array
{
    contratos_modelos_ensure_schema($pdo);
    $stmt = $pdo->query("
        SELECT id, nome, conteudo_html
        FROM contrato_modelos
        WHERE COALESCE(ativo, TRUE) = TRUE
        ORDER BY nome ASC, id ASC
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function eventos_documentos_evento(PDO $pdo, int $eventoId): ?array
{
    $hasHoraFim = eventos_documentos_column_exists($pdo, 'logistica_eventos_espelho', 'hora_fim');
    $hasClienteCadastro = eventos_documentos_column_exists($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id');
    $hasClientes = eventos_documentos_table_exists($pdo, 'comercial_cadastro_clientes');
    $selectHoraFim = $hasHoraFim ? "COALESCE(TO_CHAR(e.hora_fim, 'HH24:MI'), '') AS hora_fim," : "'' AS hora_fim,";
    $selectClienteId = $hasClienteCadastro ? "COALESCE(e.cliente_cadastro_id, 0) AS cliente_cadastro_id," : "0 AS cliente_cadastro_id,";
    $joinCliente = ($hasClienteCadastro && $hasClientes) ? "LEFT JOIN comercial_cadastro_clientes c ON c.id = e.cliente_cadastro_id" : "";
    $clienteExpr = static function (string $column) use ($pdo, $hasClientes): string {
        if (!$hasClientes || !eventos_documentos_column_exists($pdo, 'comercial_cadastro_clientes', $column)) {
            return "''";
        }
        return "COALESCE(NULLIF(TRIM(c.{$column}), ''), '')";
    };
    $clienteSelects = ($hasClienteCadastro && $hasClientes) ? "
            {$clienteExpr('nome_completo')} AS cliente_nome,
            {$clienteExpr('documento_numero')} AS cliente_documento,
            {$clienteExpr('rg')} AS cliente_rg,
            {$clienteExpr('email')} AS cliente_email,
            {$clienteExpr('telefone_whatsapp')} AS cliente_telefone,
            {$clienteExpr('cep')} AS cliente_cep,
            {$clienteExpr('endereco_logradouro')} AS cliente_endereco,
            {$clienteExpr('endereco_numero')} AS cliente_numero,
            {$clienteExpr('endereco_complemento')} AS cliente_complemento,
            {$clienteExpr('endereco_bairro')} AS cliente_bairro,
            {$clienteExpr('endereco_cidade')} AS cliente_cidade,
            {$clienteExpr('endereco_estado')} AS cliente_estado
    " : "
            '' AS cliente_nome,
            '' AS cliente_documento,
            '' AS cliente_rg,
            '' AS cliente_email,
            '' AS cliente_telefone,
            '' AS cliente_cep,
            '' AS cliente_endereco,
            '' AS cliente_numero,
            '' AS cliente_complemento,
            '' AS cliente_bairro,
            '' AS cliente_cidade,
            '' AS cliente_estado
    ";

    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.me_event_id,
            e.data_evento::text AS data_evento,
            COALESCE(TO_CHAR(e.hora_inicio, 'HH24:MI'), '') AS hora_inicio,
            {$selectHoraFim}
            COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento') AS nome_evento,
            COALESCE(NULLIF(TRIM(e.localevento), ''), 'Local não informado') AS local_evento,
            COALESCE(NULLIF(TRIM(e.space_visivel), ''), 'Não mapeado') AS space_visivel,
            COALESCE(e.convidados, 0) AS convidados,
            {$selectClienteId}
            {$clienteSelects}
        FROM logistica_eventos_espelho e
        {$joinCliente}
        WHERE e.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function eventos_documentos_data_br(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('d/m/Y', $time) : $date;
}

function eventos_documentos_itens_contratados_html(array $itens): string
{
    $rows = [];
    $total = 0.0;
    foreach ($itens as $item) {
        $descricao = trim((string)($item['descricao'] ?? ''));
        $detalhes = trim((string)($item['detalhes_html'] ?? ''));
        $quantidade = max(1, (int)($item['quantidade'] ?? 1));
        $valorTotal = (float)($item['valor'] ?? 0);
        $valorUnitario = (float)($item['valor_base'] ?? 0);
        if ($valorUnitario <= 0 && $quantidade > 0) {
            $valorUnitario = $valorTotal / $quantidade;
        }
        $total += $valorTotal;

        if ($detalhes !== '') {
            $permitidos = '<p><br><strong><b><em><i><u><ul><ol><li><span><div><table><thead><tbody><tr><th><td><h1><h2><h3><h4><h5><h6>';
            $detalhesHtml = preg_match('/<[^>]+>/', $detalhes) === 1
                ? strip_tags($detalhes, $permitidos)
                : nl2br(eventos_documentos_e($detalhes));
        } else {
            $detalhesHtml = '';
        }

        $nomeHtml = '<strong>' . eventos_documentos_e($descricao !== '' ? $descricao : 'Item contratado') . '</strong>';
        $rows[] = '<tr>'
            . '<td style="border:1px solid #111;vertical-align:top;padding:5px;">' . $nomeHtml . ($detalhesHtml !== '' ? '<br>' . $detalhesHtml : '') . '</td>'
            . '<td style="border:1px solid #111;vertical-align:top;text-align:right;padding:5px;white-space:nowrap;">R$ ' . number_format($valorUnitario, 2, ',', '.') . '</td>'
            . '<td style="border:1px solid #111;vertical-align:top;text-align:center;padding:5px;white-space:nowrap;">' . $quantidade . '</td>'
            . '<td style="border:1px solid #111;vertical-align:top;text-align:right;padding:5px;white-space:nowrap;">R$ ' . number_format($valorTotal, 2, ',', '.') . '</td>'
            . '</tr>';
    }

    if (!$rows) {
        return '<p>Nenhum item contratado lançado.</p>';
    }

    return '<table style="width:100%;border-collapse:collapse;font-size:11px;line-height:1.25;">'
        . '<thead><tr>'
        . '<th style="border:1px solid #111;background:#d9d9d9;text-align:left;padding:5px;">Nome/Descrição</th>'
        . '<th style="border:1px solid #111;background:#d9d9d9;text-align:right;padding:5px;width:110px;">Valor Unit.</th>'
        . '<th style="border:1px solid #111;background:#d9d9d9;text-align:center;padding:5px;width:70px;">Quant.</th>'
        . '<th style="border:1px solid #111;background:#d9d9d9;text-align:right;padding:5px;width:120px;">Valor Total</th>'
        . '</tr></thead><tbody>' . implode('', $rows) . '</tbody>'
        . '<tfoot><tr><td colspan="3" style="border:1px solid #111;text-align:left;padding:5px;"><strong>TOTAL</strong></td>'
        . '<td style="border:1px solid #111;text-align:right;padding:5px;white-space:nowrap;"><strong>R$ ' . number_format($total, 2, ',', '.') . '</strong></td></tr></tfoot>'
        . '</table>';
}

function eventos_documentos_mapa_tags(PDO $pdo, int $eventoId, ?array $evento = null): array
{
    $evento = $evento ?: eventos_documentos_evento($pdo, $eventoId) ?: [];
    $resumo = eventos_financeiro_resumo($pdo, $eventoId);
    $itens = eventos_financeiro_listar_pedidos($pdo, $eventoId);
    $pacote = '';
    if (!empty($itens[0]['pacote_nome'])) {
        $pacote = (string)$itens[0]['pacote_nome'];
    } elseif (!empty($itens[0]['descricao'])) {
        $pacote = (string)$itens[0]['descricao'];
    }
    $horario = trim((string)($evento['hora_inicio'] ?? ''));
    if (!empty($evento['hora_fim'])) {
        $horario .= ($horario !== '' ? ' às ' : '') . trim((string)$evento['hora_fim']);
    }

    return [
        '#NOME#' => (string)($evento['cliente_nome'] ?? ''),
        '#CPF#' => (string)($evento['cliente_documento'] ?? ''),
        '#RG#' => (string)($evento['cliente_rg'] ?? ''),
        '#EMAIL#' => (string)($evento['cliente_email'] ?? ''),
        '#TELEFONE#' => (string)($evento['cliente_telefone'] ?? ''),
        '#CEP#' => (string)($evento['cliente_cep'] ?? ''),
        '#ENDERECO#' => (string)($evento['cliente_endereco'] ?? ''),
        '#NUMERO#' => (string)($evento['cliente_numero'] ?? ''),
        '#COMPLEMENTO#' => (string)($evento['cliente_complemento'] ?? ''),
        '#BAIRRO#' => (string)($evento['cliente_bairro'] ?? ''),
        '#CIDADE#' => (string)($evento['cliente_cidade'] ?? ''),
        '#ESTADO#' => (string)($evento['cliente_estado'] ?? ''),
        '#NOME_EVENTO#' => (string)($evento['nome_evento'] ?? ''),
        '#DATA_EVENTO#' => eventos_documentos_data_br((string)($evento['data_evento'] ?? '')),
        '#HORARIO_EVENTO#' => $horario,
        '#LOCAL_EVENTO#' => (string)($evento['local_evento'] ?? ''),
        '#UNIDADE#' => (string)($evento['space_visivel'] ?? ''),
        '#CONVIDADOS#' => (string)($evento['convidados'] ?? ''),
        '#PACOTE#' => $pacote,
        '#ITENS_CONTRATADOS#' => eventos_documentos_itens_contratados_html($itens),
        '#VALOR_TOTAL#' => 'R$ ' . number_format((float)$resumo['contratado'], 2, ',', '.'),
        '#VALOR_RECEBIDO#' => 'R$ ' . number_format((float)$resumo['recebido'], 2, ',', '.'),
        '#VALOR_A_RECEBER#' => 'R$ ' . number_format((float)$resumo['falta_receber'], 2, ',', '.'),
        '#DATA_HOJE#' => date('d/m/Y'),
    ];
}

function eventos_documentos_renderizar_modelo(string $html, array $tags): string
{
    return strtr($html, $tags);
}

function eventos_documentos_listar(PDO $pdo, int $eventoId): array
{
    eventos_documentos_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT d.*,
               m.nome AS modelo_nome,
               COALESCE(NULLIF(TRIM(u.nome), ''), NULLIF(TRIM(u.email), ''), 'Sistema') AS criado_por_nome,
               'geral' AS origem
        FROM eventos_documentos d
        LEFT JOIN contrato_modelos m ON m.id = d.modelo_id
        LEFT JOIN usuarios u ON u.id = d.created_by
        WHERE d.evento_id = :evento_id
          AND d.deleted_at IS NULL
        ORDER BY d.created_at DESC, d.id DESC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_documentos_listar_formatura(PDO $pdo, int $eventoId): array
{
    if (!eventos_documentos_table_exists($pdo, 'eventos_formatura_documentos')) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT d.id,
               d.evento_id,
               d.modelo_id,
               d.titulo,
               d.conteudo_html,
               d.status,
               d.minuta_token,
               d.assinaturas_realizadas,
               d.assinaturas_total,
               d.created_by,
               d.created_at,
               d.updated_at,
               d.deleted_at,
               m.nome AS modelo_nome,
               COALESCE(NULLIF(TRIM(u.nome), ''), NULLIF(TRIM(u.email), ''), 'Sistema') AS criado_por_nome,
               f.nome_formando,
               COALESCE(NULLIF(TRIM(c.nome_completo), ''), '') AS responsavel_nome,
               'formatura' AS origem
        FROM eventos_formatura_documentos d
        LEFT JOIN contrato_modelos m ON m.id = d.modelo_id
        LEFT JOIN usuarios u ON u.id = d.created_by
        LEFT JOIN eventos_formatura_formandos f ON f.id = d.formando_id
        LEFT JOIN comercial_cadastro_clientes c ON c.id = f.cliente_cadastro_id
        WHERE d.evento_id = :evento_id
          AND d.deleted_at IS NULL
        ORDER BY d.created_at DESC, d.id DESC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_documentos_buscar_modelo(PDO $pdo, int $modeloId): ?array
{
    contratos_modelos_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT id, nome, conteudo_html
        FROM contrato_modelos
        WHERE id = :id
          AND COALESCE(ativo, TRUE) = TRUE
        LIMIT 1
    ");
    $stmt->execute([':id' => $modeloId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function eventos_documentos_criar(PDO $pdo, int $eventoId, int $modeloId, string $titulo, string $conteudoHtml, int $userId): int
{
    eventos_documentos_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO eventos_documentos
            (evento_id, modelo_id, titulo, conteudo_html, status, minuta_token, created_by)
        VALUES
            (:evento_id, :modelo_id, :titulo, :conteudo_html, 'criado', :minuta_token, :created_by)
        RETURNING id
    ");
    $stmt->execute([
        ':evento_id' => $eventoId,
        ':modelo_id' => $modeloId,
        ':titulo' => $titulo,
        ':conteudo_html' => $conteudoHtml,
        ':minuta_token' => eventos_documentos_token(),
        ':created_by' => $userId > 0 ? $userId : null,
    ]);
    return (int)$stmt->fetchColumn();
}

function eventos_documentos_atualizar(PDO $pdo, int $eventoId, int $documentoId, string $titulo, string $conteudoHtml): void
{
    eventos_documentos_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        UPDATE eventos_documentos
        SET titulo = :titulo,
            conteudo_html = :conteudo_html,
            updated_at = NOW()
        WHERE id = :id
          AND evento_id = :evento_id
          AND deleted_at IS NULL
    ");
    $stmt->execute([
        ':titulo' => $titulo,
        ':conteudo_html' => $conteudoHtml,
        ':id' => $documentoId,
        ':evento_id' => $eventoId,
    ]);
}

function eventos_documentos_excluir(PDO $pdo, int $eventoId, int $documentoId, int $userId): void
{
    eventos_documentos_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        UPDATE eventos_documentos
        SET deleted_at = NOW(),
            deleted_by = :deleted_by,
            updated_at = NOW()
        WHERE id = :id
          AND evento_id = :evento_id
          AND deleted_at IS NULL
    ");
    $stmt->execute([
        ':deleted_by' => $userId > 0 ? $userId : null,
        ':id' => $documentoId,
        ':evento_id' => $eventoId,
    ]);
}

function eventos_documentos_public_url(string $path): string
{
    $path = ltrim($path, '/');
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($forwardedProto === 'http' || $forwardedProto === 'https') {
        $scheme = $forwardedProto;
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = strtolower((string)$_SERVER['REQUEST_SCHEME']);
    } else {
        $scheme = 'https';
    }

    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return $path;
    }
    if ($scheme === 'http' && !preg_match('/^(localhost|127\.0\.0\.1|\[?::1\]?)(:\d+)?$/', $host)) {
        $scheme = 'https';
    }

    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/\\');
    if ($base === '' || $base === '.') {
        $base = '';
    }
    return $scheme . '://' . $host . $base . '/' . $path;
}
