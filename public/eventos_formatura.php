<?php
/**
 * eventos_formatura.php
 * Gestão de formandos vinculados a eventos do tipo Formatura.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_financeiro_helper.php';
require_once __DIR__ . '/contratos_modelos_helper.php';
require_once __DIR__ . '/eventos_documentos_helper.php';
require_once __DIR__ . '/config_env.php';

if (empty($_SESSION['perm_agenda_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function eventos_formatura_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eventos_formatura_money($value): float
{
    return eventos_financeiro_money($value);
}

function eventos_formatura_config_padrao(): array
{
    return [
        'pessoas_por_mesa' => 8,
        'valor_mesa' => 0.0,
        'valor_convidado_adicional' => 0.0,
        'valor_crianca_meia' => 90.0,
    ];
}

function eventos_formatura_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_formatura_formandos (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            cliente_cadastro_id BIGINT NOT NULL REFERENCES comercial_cadastro_clientes(id) ON DELETE RESTRICT,
            nome_formando VARCHAR(180) NOT NULL,
            convidados INTEGER NOT NULL DEFAULT 0,
            criancas_meia INTEGER NOT NULL DEFAULT 0,
            mesas INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            deleted_at TIMESTAMP NULL
        )
    ");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_formandos ADD COLUMN IF NOT EXISTS criancas_meia INTEGER NOT NULL DEFAULT 0");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_formatura_financeiro (
            id BIGSERIAL PRIMARY KEY,
            formando_id BIGINT NOT NULL REFERENCES eventos_formatura_formandos(id) ON DELETE CASCADE,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            descricao TEXT NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            vencimento DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            forma_pagamento VARCHAR(30) NOT NULL DEFAULT 'manual',
            carteira VARCHAR(20) NOT NULL DEFAULT 'manual',
            modo_pagamento VARCHAR(30) NULL,
            unidade VARCHAR(120) NULL,
            parcelamento_grupo VARCHAR(80) NULL,
            parcela_numero INTEGER NOT NULL DEFAULT 1,
            parcelas_total INTEGER NOT NULL DEFAULT 1,
            asaas_payment_id VARCHAR(120) NULL,
            asaas_invoice_url TEXT NULL,
            asaas_payload JSONB NULL,
            pixgo_payment_id VARCHAR(120) NULL,
            pixgo_payment_url TEXT NULL,
            pixgo_qr_code TEXT NULL,
            pixgo_qr_image_url TEXT NULL,
            pixgo_expires_at TIMESTAMPTZ NULL,
            pixgo_idempotency_key VARCHAR(120) NULL,
            pixgo_payload JSONB NULL,
            pago_em TIMESTAMP NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS carteira VARCHAR(20) NOT NULL DEFAULT 'manual'");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS modo_pagamento VARCHAR(30) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS unidade VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS parcelamento_grupo VARCHAR(80) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS parcela_numero INTEGER NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS parcelas_total INTEGER NOT NULL DEFAULT 1");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS asaas_payment_id VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS asaas_invoice_url TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS asaas_payload JSONB NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_payment_id VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_payment_url TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_qr_code TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_qr_image_url TEXT NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_expires_at TIMESTAMPTZ NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_idempotency_key VARCHAR(120) NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pixgo_payload JSONB NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_financeiro ADD COLUMN IF NOT EXISTS pago_em TIMESTAMP NULL");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_formatura_financeiro_pixgo ON eventos_formatura_financeiro(pixgo_payment_id) WHERE pixgo_payment_id IS NOT NULL");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_formatura_config (
            evento_id BIGINT PRIMARY KEY REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            pessoas_por_mesa INTEGER NOT NULL DEFAULT 8,
            valor_mesa NUMERIC(12,2) NOT NULL DEFAULT 0,
            valor_convidado_adicional NUMERIC(12,2) NOT NULL DEFAULT 0,
            valor_crianca_meia NUMERIC(12,2) NOT NULL DEFAULT 90,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_config ADD COLUMN IF NOT EXISTS pessoas_por_mesa INTEGER NOT NULL DEFAULT 8");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_config ADD COLUMN IF NOT EXISTS valor_mesa NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_config ADD COLUMN IF NOT EXISTS valor_convidado_adicional NUMERIC(12,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_config ADD COLUMN IF NOT EXISTS valor_crianca_meia NUMERIC(12,2) NOT NULL DEFAULT 90");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_config ADD COLUMN IF NOT EXISTS created_by INTEGER NULL");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_config ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW()");
    $pdo->exec("ALTER TABLE IF EXISTS eventos_formatura_config ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW()");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_formandos_evento ON eventos_formatura_formandos(evento_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_formandos_cliente ON eventos_formatura_formandos(cliente_cadastro_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_financeiro_formando ON eventos_formatura_financeiro(formando_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_financeiro_evento ON eventos_formatura_financeiro(evento_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_financeiro_grupo ON eventos_formatura_financeiro(parcelamento_grupo)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_financeiro_asaas ON eventos_formatura_financeiro(asaas_payment_id)");

    contratos_modelos_ensure_schema($pdo);
    $stmtTag = $pdo->prepare("
        INSERT INTO contrato_tags (tag_codigo, nome, origem_tipo, origem_campo, descricao, ativo, created_at, updated_at)
        VALUES (:tag_codigo, :nome, :origem_tipo, :origem_campo, :descricao, TRUE, NOW(), NOW())
        ON CONFLICT (tag_codigo) DO NOTHING
    ");
    foreach (contratos_modelos_default_tag_options() as $tagOption) {
        if (($tagOption['origem_tipo'] ?? '') !== 'formatura') {
            continue;
        }
        $stmtTag->execute([
            ':tag_codigo' => $tagOption['tag'],
            ':nome' => $tagOption['nome'],
            ':origem_tipo' => $tagOption['origem_tipo'],
            ':origem_campo' => $tagOption['origem_campo'],
            ':descricao' => 'Tag padrão de formatura para modelos de contrato.',
        ]);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_formatura_documentos (
            id BIGSERIAL PRIMARY KEY,
            evento_id BIGINT NOT NULL REFERENCES logistica_eventos_espelho(id) ON DELETE CASCADE,
            formando_id BIGINT NOT NULL REFERENCES eventos_formatura_formandos(id) ON DELETE CASCADE,
            modelo_id BIGINT NULL REFERENCES contrato_modelos(id) ON DELETE SET NULL,
            titulo VARCHAR(220) NOT NULL,
            conteudo_html TEXT NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'criado',
            assinaturas_realizadas INTEGER NOT NULL DEFAULT 0,
            assinaturas_total INTEGER NOT NULL DEFAULT 0,
            minuta_token VARCHAR(80) NOT NULL UNIQUE,
            minuta_aprovada_em TIMESTAMP NULL,
            minuta_aprovada_ip VARCHAR(80) NULL,
            clicksign_document_key VARCHAR(160) NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            deleted_at TIMESTAMP NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_documentos_evento ON eventos_formatura_documentos(evento_id, deleted_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_documentos_formando ON eventos_formatura_documentos(formando_id, deleted_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_eventos_formatura_documentos_token ON eventos_formatura_documentos(minuta_token)");

    $done = true;
}

function eventos_formatura_evento(PDO $pdo, int $eventoId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            e.id,
            er.id AS meeting_id,
            e.me_event_id,
            e.data_evento::text AS data_evento,
            COALESCE(TO_CHAR(e.hora_inicio, 'HH24:MI'), '') AS hora_inicio,
            COALESCE(TO_CHAR(e.hora_fim, 'HH24:MI'), '') AS hora_fim,
            COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento') AS nome_evento,
            COALESCE(NULLIF(TRIM(e.localevento), ''), 'Local não informado') AS local_evento,
            COALESCE(NULLIF(TRIM(e.space_visivel), ''), 'Não mapeado') AS space_visivel,
            COALESCE(e.convidados, 0) AS convidados,
            COALESCE(
                NULLIF(TRIM(cep.tipo_evento_real), ''),
                NULLIF(TRIM(er.tipo_evento_real), ''),
                NULLIF(TRIM(er.me_event_snapshot->>'tipo_evento_real'), ''),
                ''
            ) AS tipo_evento_real
        FROM logistica_eventos_espelho e
        LEFT JOIN LATERAL (
            SELECT tipo_evento_real
            FROM comercial_eventos_painel
            WHERE espelho_evento_id = e.id
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ) cep ON TRUE
        LEFT JOIN LATERAL (
            SELECT id, tipo_evento_real, me_event_snapshot
            FROM eventos_reunioes
            WHERE me_event_id = e.me_event_id
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ) er ON TRUE
        WHERE e.id = :id
          AND COALESCE(e.arquivado, FALSE) = FALSE
        LIMIT 1
    ");
    $stmt->execute([':id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function eventos_formatura_clientes(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            id,
            COALESCE(NULLIF(TRIM(nome_completo), ''), 'Cliente sem nome') AS nome,
            COALESCE(NULLIF(TRIM(documento_numero), ''), '') AS documento,
            COALESCE(NULLIF(TRIM(email), ''), '') AS email,
            COALESCE(NULLIF(TRIM(telefone_whatsapp), ''), '') AS telefone
        FROM comercial_cadastro_clientes
        WHERE COALESCE(ativo, TRUE) = TRUE
        ORDER BY nome_completo ASC NULLS LAST, id ASC
        LIMIT 1200
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function eventos_formatura_unidades(PDO $pdo, string $eventoUnidade): array
{
    $unidades = [];
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT TRIM(space_visivel) AS unidade
            FROM logistica_eventos_espelho
            WHERE COALESCE(TRIM(space_visivel), '') <> ''
            ORDER BY TRIM(space_visivel)
            LIMIT 80
        ");
        $unidades = $stmt ? array_values(array_filter(array_map(static fn($row) => (string)$row['unidade'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []))) : [];
    } catch (Throwable $e) {
        $unidades = [];
    }

    if ($eventoUnidade !== '' && !in_array($eventoUnidade, $unidades, true)) {
        array_unshift($unidades, $eventoUnidade);
    }

    return $unidades ?: ['Não informado'];
}

function eventos_formatura_label_modo(string $modo): string
{
    return [
        'pix' => 'Pix',
        'cartao_credito' => 'Cartão de crédito',
        'dinheiro' => 'Dinheiro',
        'cartao_debito' => 'Cartão de débito',
        'nao_informado' => 'Não informado',
        'cartao' => 'Cartão',
        'transferencia' => 'Transferência',
        'outro' => 'Outro',
        'manual' => 'Manual',
    ][$modo] ?? $modo;
}

function eventos_formatura_label_status(string $status): string
{
    return [
        'pendente' => 'Pendente',
        'pago' => 'Pago',
        'vencido' => 'Vencido',
        'cancelado' => 'Cancelado',
    ][$status] ?? $status;
}

function eventos_formatura_formandos(PDO $pdo, int $eventoId): array
{
    $stmt = $pdo->prepare("
        SELECT
            f.*,
            c.nome_completo AS cliente_nome,
            c.documento_numero AS cliente_documento,
            c.rg AS cliente_rg,
            c.email AS cliente_email,
            c.telefone_whatsapp AS cliente_telefone,
            c.cep AS cliente_cep,
            c.endereco_logradouro AS cliente_endereco,
            c.endereco_numero AS cliente_numero,
            c.endereco_complemento AS cliente_complemento,
            c.endereco_bairro AS cliente_bairro,
            c.endereco_cidade AS cliente_cidade,
            c.endereco_estado AS cliente_estado,
            COALESCE(fin.total_lancado, 0) AS total_lancado,
            COALESCE(fin.total_pago, 0) AS total_pago
        FROM eventos_formatura_formandos f
        JOIN comercial_cadastro_clientes c ON c.id = f.cliente_cadastro_id
        LEFT JOIN (
            SELECT
                formando_id,
                SUM(CASE WHEN status <> 'cancelado' THEN valor ELSE 0 END) AS total_lancado,
                SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) AS total_pago
            FROM eventos_formatura_financeiro
            GROUP BY formando_id
        ) fin ON fin.formando_id = f.id
        WHERE f.evento_id = :evento_id
          AND f.deleted_at IS NULL
        ORDER BY f.nome_formando ASC, f.id ASC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_formatura_config(PDO $pdo, int $eventoId): array
{
    $config = eventos_formatura_config_padrao();
    $stmt = $pdo->prepare("
        SELECT pessoas_por_mesa, valor_mesa, valor_convidado_adicional, valor_crianca_meia
        FROM eventos_formatura_config
        WHERE evento_id = :evento_id
        LIMIT 1
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $config;
    }

    return [
        'pessoas_por_mesa' => max(0, (int)($row['pessoas_por_mesa'] ?? $config['pessoas_por_mesa'])),
        'valor_mesa' => (float)($row['valor_mesa'] ?? 0),
        'valor_convidado_adicional' => (float)($row['valor_convidado_adicional'] ?? 0),
        'valor_crianca_meia' => (float)($row['valor_crianca_meia'] ?? $config['valor_crianca_meia']),
    ];
}

function eventos_formatura_calcular_formando(array $formando, array $config): array
{
    $mesas = max(0, (int)($formando['mesas'] ?? 0));
    $convidados = max(0, (int)($formando['convidados'] ?? 0));
    $criancasMeia = max(0, (int)($formando['criancas_meia'] ?? 0));
    $pessoasPorMesa = max(0, (int)($config['pessoas_por_mesa'] ?? 0));
    $valorMesa = max(0.0, (float)($config['valor_mesa'] ?? 0));
    $valorAdicional = max(0.0, (float)($config['valor_convidado_adicional'] ?? 0));
    $valorCriancaMeia = max(0.0, (float)($config['valor_crianca_meia'] ?? 0));
    $capacidadeBase = $mesas * $pessoasPorMesa;
    $convidadosAdicionais = max(0, $convidados - $capacidadeBase);
    $valorBaseMesas = $mesas * $valorMesa;
    $valorAdicionais = $convidadosAdicionais * $valorAdicional;
    $valorCriancasMeia = $criancasMeia * $valorCriancaMeia;
    $valorCalculado = $valorBaseMesas + $valorAdicionais + $valorCriancasMeia;

    return [
        'pessoas_por_mesa' => $pessoasPorMesa,
        'valor_mesa' => $valorMesa,
        'valor_convidado_adicional' => $valorAdicional,
        'valor_crianca_meia' => $valorCriancaMeia,
        'criancas_meia' => $criancasMeia,
        'capacidade_base' => $capacidadeBase,
        'convidados_adicionais' => $convidadosAdicionais,
        'valor_base_mesas' => $valorBaseMesas,
        'valor_adicionais' => $valorAdicionais,
        'valor_criancas_meia' => $valorCriancasMeia,
        'valor_calculado' => $valorCalculado,
        'valor_lancado_display' => $valorCalculado > 0 ? $valorCalculado : (float)($formando['total_lancado'] ?? 0),
        'calculo_configurado' => $pessoasPorMesa > 0 && ($valorMesa > 0 || $valorAdicional > 0 || $valorCriancaMeia > 0),
    ];
}

function eventos_formatura_aplicar_config(array $formandos, array $config): array
{
    foreach ($formandos as &$formando) {
        $formando += eventos_formatura_calcular_formando($formando, $config);
    }
    unset($formando);
    return $formandos;
}

function eventos_formatura_financeiro(PDO $pdo, int $eventoId): array
{
    $stmt = $pdo->prepare("
        SELECT fin.*, f.nome_formando
        FROM eventos_formatura_financeiro fin
        JOIN eventos_formatura_formandos f ON f.id = fin.formando_id
        WHERE fin.evento_id = :evento_id
          AND f.deleted_at IS NULL
        ORDER BY COALESCE(fin.vencimento, fin.created_at::date) ASC,
                 fin.parcela_numero ASC,
                 fin.id ASC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_formatura_modelos_contrato(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, nome, conteudo_html
        FROM contrato_modelos
        WHERE COALESCE(ativo, TRUE) = TRUE
        ORDER BY nome ASC, id ASC
    ");
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function eventos_formatura_documentos(PDO $pdo, int $eventoId): array
{
    $stmt = $pdo->prepare("
        SELECT
            d.*,
            f.nome_formando,
            c.nome_completo AS responsavel_nome,
            m.nome AS modelo_nome,
            COALESCE(NULLIF(TRIM(u.nome), ''), NULLIF(TRIM(u.email), ''), 'Sistema') AS criado_por_nome
        FROM eventos_formatura_documentos d
        JOIN eventos_formatura_formandos f ON f.id = d.formando_id
        JOIN comercial_cadastro_clientes c ON c.id = f.cliente_cadastro_id
        LEFT JOIN contrato_modelos m ON m.id = d.modelo_id
        LEFT JOIN usuarios u ON u.id = d.created_by
        WHERE d.evento_id = :evento_id
          AND d.deleted_at IS NULL
        ORDER BY d.created_at DESC, d.id DESC
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function eventos_formatura_documento_token(): string
{
    return bin2hex(random_bytes(24));
}

function eventos_formatura_data_br(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('d/m/Y', $time) : $date;
}

function eventos_formatura_data_hora_br(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('d/m/Y H:i', $time) : $date;
}

function eventos_formatura_public_url(string $path): string
{
    return function_exists('eventos_documentos_public_url')
        ? eventos_documentos_public_url($path)
        : '/' . ltrim($path, '/');
}

function eventos_formatura_mapa_tags(array $evento, array $formando, array $financeiroFormando, array $itensContratados = []): array
{
    $totalLancado = array_sum(array_map(static fn($item) => (float)$item['valor'], array_filter($financeiroFormando, static fn($item) => (string)$item['status'] !== 'cancelado')));
    if (!empty($formando['valor_lancado_display'])) {
        $totalLancado = (float)$formando['valor_lancado_display'];
    }
    $totalPago = array_sum(array_map(static fn($item) => (float)$item['valor'], array_filter($financeiroFormando, static fn($item) => (string)$item['status'] === 'pago')));
    $saldo = max(0, $totalLancado - $totalPago);
    $valorMesa = (float)($formando['valor_mesa'] ?? 0);
    $valorConvidadoAdicional = (float)($formando['valor_convidado_adicional'] ?? 0);
    $valorCriancaMeia = (float)($formando['valor_crianca_meia'] ?? 0);
    $valorBaseMesas = (float)($formando['valor_base_mesas'] ?? 0);
    $valorAdicionais = (float)($formando['valor_adicionais'] ?? 0);
    $valorCriancasMeia = (float)($formando['valor_criancas_meia'] ?? 0);

    $parcelas = [];
    foreach ($financeiroFormando as $item) {
        if ((string)$item['status'] === 'cancelado') {
            continue;
        }
        $vencimento = !empty($item['vencimento']) ? eventos_formatura_data_br((string)$item['vencimento']) : 'Sem vencimento';
        $status = eventos_formatura_label_status((string)$item['status']);
        $descricao = trim((string)$item['descricao']);
        $parcelas[] = eventos_formatura_e($vencimento . ' - R$ ' . number_format((float)$item['valor'], 2, ',', '.') . ' - ' . $status . ($descricao !== '' ? ' - ' . $descricao : ''));
    }
    $parcelasHtml = $parcelas ? implode('<br>', $parcelas) : 'Nenhuma parcela lançada.';

    $horario = trim((string)($evento['hora_inicio'] ?? ''));
    if (!empty($evento['hora_fim'])) {
        $horario .= ($horario !== '' ? ' às ' : '') . trim((string)$evento['hora_fim']);
    }

    return [
        '#NOME#' => (string)($formando['cliente_nome'] ?? ''),
        '#CPF#' => (string)($formando['cliente_documento'] ?? ''),
        '#RG#' => (string)($formando['cliente_rg'] ?? ''),
        '#EMAIL#' => (string)($formando['cliente_email'] ?? ''),
        '#TELEFONE#' => (string)($formando['cliente_telefone'] ?? ''),
        '#CEP#' => (string)($formando['cliente_cep'] ?? ''),
        '#ENDERECO#' => (string)($formando['cliente_endereco'] ?? ''),
        '#NUMERO#' => (string)($formando['cliente_numero'] ?? ''),
        '#COMPLEMENTO#' => (string)($formando['cliente_complemento'] ?? ''),
        '#BAIRRO#' => (string)($formando['cliente_bairro'] ?? ''),
        '#CIDADE#' => (string)($formando['cliente_cidade'] ?? ''),
        '#ESTADO#' => (string)($formando['cliente_estado'] ?? ''),
        '#NOME_EVENTO#' => (string)($evento['nome_evento'] ?? ''),
        '#ID_EVENTO#' => (string)($evento['id'] ?? ''),
        '#DATA_EVENTO#' => eventos_formatura_data_br((string)($evento['data_evento'] ?? '')),
        '#HORARIO_EVENTO#' => $horario,
        '#LOCAL_EVENTO#' => (string)($evento['local_evento'] ?? ''),
        '#UNIDADE#' => (string)($evento['space_visivel'] ?? ''),
        '#CONVIDADOS#' => (string)($evento['convidados'] ?? ''),
        '#ITENS_CONTRATADOS#' => eventos_documentos_itens_contratados_html($itensContratados),
        '#VALOR_TOTAL#' => 'R$ ' . number_format($totalLancado, 2, ',', '.'),
        '#VALOR_RECEBIDO#' => 'R$ ' . number_format($totalPago, 2, ',', '.'),
        '#VALOR_A_RECEBER#' => 'R$ ' . number_format($saldo, 2, ',', '.'),
        '#NOME_FORMANDO#' => (string)($formando['nome_formando'] ?? ''),
        '#CONVIDADOS_FORMANDO#' => (string)($formando['convidados'] ?? '0'),
        '#CRIANCAS_MEIA_FORMANDO#' => (string)($formando['criancas_meia'] ?? '0'),
        '#MESAS_FORMANDO#' => (string)($formando['mesas'] ?? '0'),
        '#VALOR_MESA#' => 'R$ ' . number_format($valorMesa, 2, ',', '.'),
        '#PESSOAS_POR_MESA#' => (string)($formando['pessoas_por_mesa'] ?? '0'),
        '#CONVIDADOS_ADICIONAIS#' => (string)($formando['convidados_adicionais'] ?? '0'),
        '#VALOR_CONVIDADO_ADICIONAL#' => 'R$ ' . number_format($valorConvidadoAdicional, 2, ',', '.'),
        '#VALOR_CRIANCA_MEIA#' => 'R$ ' . number_format($valorCriancaMeia, 2, ',', '.'),
        '#VALOR_MESAS_FORMATURA#' => 'R$ ' . number_format($valorBaseMesas, 2, ',', '.'),
        '#VALOR_ADICIONAIS_FORMATURA#' => 'R$ ' . number_format($valorAdicionais, 2, ',', '.'),
        '#VALOR_CRIANCAS_MEIA_FORMATURA#' => 'R$ ' . number_format($valorCriancasMeia, 2, ',', '.'),
        '#RESPONSAVEL_FORMANDO#' => (string)($formando['cliente_nome'] ?? ''),
        '#VALOR_FORMANDO#' => 'R$ ' . number_format($totalLancado, 2, ',', '.'),
        '#PARCELAS_FORMANDO#' => $parcelasHtml,
        '#DATA_HOJE#' => date('d/m/Y'),
    ];
}

function eventos_formatura_renderizar_modelo(string $html, array $tags): string
{
    return strtr($html, $tags);
}

function eventos_formatura_redirect(int $eventoId): void
{
    header('Location: index.php?page=eventos_formatura&evento_id=' . $eventoId);
    exit;
}

function eventos_formatura_redirect_financeiro(int $eventoId, int $formandoId): void
{
    header('Location: index.php?page=eventos_formatura&evento_id=' . $eventoId . '&formando_id=' . $formandoId);
    exit;
}

function eventos_formatura_salvar_receitas_manual_lote(PDO $pdo, int $eventoId, int $formandoId, array $dados, int $userId): array
{
    $descricaoBase = trim((string)($dados['descricao'] ?? 'Receita do formando')) ?: 'Receita do formando';
    $unidade = trim((string)($dados['unidade'] ?? ''));
    $modo = eventos_financeiro_normalizar_modo((string)($dados['modo_pagamento'] ?? ''), 'manual');
    $status = (string)($dados['status_pagamento'] ?? 'pendente');
    $status = in_array($status, ['pendente', 'pago'], true) ? $status : 'pendente';
    $vencimentos = is_array($dados['parcela_vencimento'] ?? null) ? $dados['parcela_vencimento'] : [];
    $valores = is_array($dados['parcela_valor'] ?? null) ? $dados['parcela_valor'] : [];
    $totalParcelas = max(1, count($valores));
    $grupo = 'formatura_manual_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

    if (empty($valores)) {
        return ['ok' => false, 'error' => 'Informe pelo menos uma parcela.'];
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($valores as $index => $valorRaw) {
            $valor = eventos_formatura_money($valorRaw);
            if ($valor <= 0) {
                throw new RuntimeException('Todas as parcelas precisam ter valor maior que zero.');
            }

            $numero = $index + 1;
            $descricao = $totalParcelas > 1 ? $descricaoBase . " ({$numero}/{$totalParcelas})" : $descricaoBase;
            $vencimento = trim((string)($vencimentos[$index] ?? ''));
            $stmt = $pdo->prepare("
                INSERT INTO eventos_formatura_financeiro
                    (formando_id, evento_id, descricao, valor, vencimento, status, forma_pagamento,
                     carteira, modo_pagamento, unidade, parcelamento_grupo, parcela_numero, parcelas_total,
                     pago_em, created_by, updated_at)
                VALUES
                    (:formando_id, :evento_id, :descricao, :valor, CAST(NULLIF(:vencimento, '') AS DATE), :status,
                     :forma_pagamento, 'manual', :modo_pagamento, :unidade, :parcelamento_grupo, :parcela_numero,
                     :parcelas_total, CASE WHEN :status_pago = 'pago' THEN NOW() ELSE NULL END, :created_by, NOW())
            ");
            $stmt->execute([
                ':formando_id' => $formandoId,
                ':evento_id' => $eventoId,
                ':descricao' => $descricao,
                ':valor' => $valor,
                ':vencimento' => $vencimento,
                ':status' => $status,
                ':status_pago' => $status,
                ':forma_pagamento' => $modo,
                ':modo_pagamento' => $modo,
                ':unidade' => $unidade !== '' ? $unidade : null,
                ':parcelamento_grupo' => $grupo,
                ':parcela_numero' => $numero,
                ':parcelas_total' => $totalParcelas,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
        }
        if ($startedTransaction) {
            $pdo->commit();
        }
        return ['ok' => true, 'created' => $totalParcelas];
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function eventos_formatura_criar_pix_asaas(PDO $pdo, int $eventoId, array $evento, array $formando, array $dados, int $userId): array
{
    require_once __DIR__ . '/asaas_helper.php';

    $valoresPost = is_array($dados['parcela_valor'] ?? null) ? $dados['parcela_valor'] : [];
    $vencimentosPost = is_array($dados['parcela_vencimento'] ?? null) ? $dados['parcela_vencimento'] : [];
    $valorTotal = eventos_formatura_money($dados['valor_total'] ?? 0);
    $parcelas = max(1, min(24, (int)($dados['parcelas'] ?? count($valoresPost) ?: 1)));
    $primeiroVencimento = trim((string)($dados['primeiro_vencimento'] ?? date('Y-m-d')));
    $descricaoBase = trim((string)($dados['descricao'] ?? 'Receita do formando')) ?: 'Receita do formando';
    $unidade = trim((string)($dados['unidade'] ?? ($evento['space_visivel'] ?? '')));

    if (!empty($valoresPost)) {
        $valores = array_map('eventos_formatura_money', $valoresPost);
        $parcelas = count($valores);
        $valorTotal = array_sum($valores);
    } else {
        $valores = eventos_financeiro_split_parcelas($valorTotal, $parcelas);
    }
    if ($valorTotal <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }

    $responsavelNome = trim((string)($formando['cliente_nome'] ?? ''));
    $responsavelEmail = trim((string)($formando['cliente_email'] ?? ''));
    $responsavelTelefone = trim((string)($formando['cliente_telefone'] ?? ''));
    $responsavelDocumento = preg_replace('/\D+/', '', (string)($formando['cliente_documento'] ?? ''));
    if ($responsavelNome === '') {
        return ['ok' => false, 'error' => 'O responsável do formando não tem nome cadastrado.'];
    }
    if ($responsavelDocumento === '') {
        return ['ok' => false, 'error' => 'O responsável do formando precisa ter CPF/CNPJ cadastrado para gerar cobrança no Asaas.'];
    }
    if (!eventos_financeiro_documento_valido($responsavelDocumento)) {
        return ['ok' => false, 'error' => 'O CPF/CNPJ do responsável do formando é inválido. Corrija o documento no cadastro do cliente antes de gerar cobrança no Asaas.'];
    }

    $asaas = new AsaasHelper();
    $created = 0;
    $grupo = 'formatura_asaas_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    $startedTransaction = !$pdo->inTransaction();

    try {
        $customer = $asaas->createCustomer([
            'name' => $responsavelNome,
            'email' => $responsavelEmail,
            'phone' => $responsavelTelefone,
            'cpf_cnpj' => $responsavelDocumento,
            'external_reference' => 'formatura_formando:' . (int)$formando['id'],
        ]);
        $customerId = (string)($customer['id'] ?? '');
        if ($customerId === '') {
            return ['ok' => false, 'error' => 'Não foi possível criar o responsável do formando no Asaas.'];
        }

        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        foreach ($valores as $index => $valorParcela) {
            $numero = $index + 1;
            $vencimento = trim((string)($vencimentosPost[$index] ?? ''));
            if ($vencimento === '') {
                $vencimento = date('Y-m-d', strtotime($primeiroVencimento . ' +' . ($index * 30) . ' days'));
            }
            $descricao = $parcelas > 1 ? $descricaoBase . " ({$numero}/{$parcelas})" : $descricaoBase;

            $stmt = $pdo->prepare("
                INSERT INTO eventos_formatura_financeiro
                    (formando_id, evento_id, descricao, valor, vencimento, status, forma_pagamento,
                     carteira, modo_pagamento, unidade, parcelamento_grupo, parcela_numero, parcelas_total,
                     created_by, updated_at)
                VALUES
                    (:formando_id, :evento_id, :descricao, :valor, :vencimento, 'pendente', 'pix_asaas',
                     'asaas', 'pix', :unidade, :parcelamento_grupo, :parcela_numero, :parcelas_total,
                     :created_by, NOW())
                RETURNING id
            ");
            $stmt->execute([
                ':formando_id' => (int)$formando['id'],
                ':evento_id' => $eventoId,
                ':descricao' => $descricao,
                ':valor' => $valorParcela,
                ':vencimento' => $vencimento,
                ':unidade' => $unidade !== '' ? $unidade : null,
                ':parcelamento_grupo' => $grupo,
                ':parcela_numero' => $numero,
                ':parcelas_total' => $parcelas,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
            $receitaId = (int)$stmt->fetchColumn();
            $externalReference = 'formatura_financeiro_receita:' . $receitaId;

            $payment = $asaas->createPixPayment([
                'customer_id' => $customerId,
                'value' => (float)$valorParcela,
                'due_date' => $vencimento,
                'description' => $descricao . ' - ' . (string)($formando['nome_formando'] ?? 'Formando') . ' - ' . (string)($evento['nome_evento'] ?? 'Formatura'),
                'external_reference' => $externalReference,
            ]);

            $statusLocal = eventos_financeiro_status_from_asaas((string)($payment['status'] ?? ''));
            $stmt = $pdo->prepare("
                UPDATE eventos_formatura_financeiro
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

        if ($startedTransaction) {
            $pdo->commit();
        }
        return ['ok' => true, 'created' => $created];
    } catch (Throwable $e) {
        if ($created > 0) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
            return [
                'ok' => true,
                'created' => $created,
                'warning' => 'Parte das cobranças foi criada no Asaas, mas houve falha na sequência: ' . $e->getMessage(),
            ];
        }
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function eventos_formatura_criar_pix_pixgo(PDO $pdo, int $eventoId, array $evento, array $formando, array $dados, int $userId): array
{
    require_once __DIR__ . '/pixgo_helper.php';

    $valores = is_array($dados['parcela_valor'] ?? null) ? $dados['parcela_valor'] : [];
    if (count($valores) !== 1) {
        return ['ok' => false, 'error' => 'A PixGo aceita somente cobrança à vista. O QR Code expira em cerca de 20 minutos.'];
    }
    $valor = eventos_formatura_money($valores[0] ?? 0);
    if ($valor < 10) {
        return ['ok' => false, 'error' => 'A cobrança mínima da PixGo é R$ 10,00.'];
    }

    $nome = trim((string)($formando['cliente_nome'] ?? ''));
    $documento = preg_replace('/\D+/', '', (string)($formando['cliente_documento'] ?? ''));
    if ($nome === '') {
        return ['ok' => false, 'error' => 'O responsável do formando não tem nome cadastrado.'];
    }
    if (!eventos_financeiro_documento_valido($documento)) {
        return ['ok' => false, 'error' => 'O responsável precisa ter CPF/CNPJ válido para gerar a cobrança PixGo.'];
    }

    $descricao = trim((string)($dados['descricao'] ?? 'Receita do formando')) ?: 'Receita do formando';
    $unidade = trim((string)($dados['unidade'] ?? ($evento['space_visivel'] ?? '')));
    $vencimento = trim((string)(($dados['parcela_vencimento'][0] ?? null) ?: ($dados['primeiro_vencimento'] ?? date('Y-m-d'))));
    $grupo = 'formatura_pixgo_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

    $stmt = $pdo->prepare("
        INSERT INTO eventos_formatura_financeiro
            (formando_id, evento_id, descricao, valor, vencimento, status, forma_pagamento,
             carteira, modo_pagamento, unidade, parcelamento_grupo, parcela_numero, parcelas_total,
             created_by, updated_at)
        VALUES
            (:formando_id, :evento_id, :descricao, :valor, :vencimento, 'pendente', 'pix_pixgo',
             'pixgo', 'pix', :unidade, :grupo, 1, 1, :created_by, NOW())
        RETURNING id
    ");
    $stmt->execute([
        ':formando_id' => (int)$formando['id'],
        ':evento_id' => $eventoId,
        ':descricao' => $descricao,
        ':valor' => $valor,
        ':vencimento' => $vencimento,
        ':unidade' => $unidade !== '' ? $unidade : null,
        ':grupo' => $grupo,
        ':created_by' => $userId > 0 ? $userId : null,
    ]);
    $receitaId = (int)$stmt->fetchColumn();
    $idempotencyKey = 'smile-formatura-' . $receitaId . '-' . bin2hex(random_bytes(8));

    try {
        $pixgo = new PixGoHelper();
        $payment = $pixgo->createPayment([
            'amount' => $valor,
            'description' => $descricao . ' - ' . (string)($formando['nome_formando'] ?? 'Formando') . ' - ' . (string)($evento['nome_evento'] ?? 'Formatura'),
            'external_id' => 'formatura_financeiro_receita:' . $receitaId,
            'receiver_name' => $nome,
            'receiver_cpf' => $documento,
            'receiver_email' => (string)($formando['cliente_email'] ?? ''),
            'receiver_phone' => (string)($formando['cliente_telefone'] ?? ''),
            'receiver_address' => trim(implode(', ', array_filter([
                (string)($formando['cliente_endereco'] ?? ''),
                (string)($formando['cliente_numero'] ?? ''),
                (string)($formando['cliente_bairro'] ?? ''),
                (string)($formando['cliente_cidade'] ?? ''),
                (string)($formando['cliente_estado'] ?? ''),
                (string)($formando['cliente_cep'] ?? ''),
            ]))),
            'idempotency_key' => $idempotencyKey,
        ]);
        $paymentId = trim((string)($payment['payment_id'] ?? $payment['id'] ?? ''));
        if ($paymentId === '') {
            throw new RuntimeException('A PixGo não retornou o identificador da cobrança.');
        }
        $paymentUrl = eventos_financeiro_pixgo_payment_url($paymentId);
        $stmt = $pdo->prepare("
            UPDATE eventos_formatura_financeiro
            SET pixgo_payment_id = :payment_id,
                pixgo_payment_url = :payment_url,
                pixgo_qr_code = :qr_code,
                pixgo_qr_image_url = :qr_image_url,
                pixgo_expires_at = CAST(NULLIF(:expires_at, '') AS TIMESTAMPTZ),
                pixgo_idempotency_key = :idempotency_key,
                pixgo_payload = CAST(:payload AS JSONB),
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':payment_url' => $paymentUrl,
            ':qr_code' => $payment['qr_code'] ?? null,
            ':qr_image_url' => $payment['qr_image_url'] ?? null,
            ':expires_at' => $payment['expires_at'] ?? '',
            ':idempotency_key' => $idempotencyKey,
            ':payload' => json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':status' => eventos_financeiro_status_from_pixgo((string)($payment['status'] ?? 'pending')),
            ':id' => $receitaId,
        ]);
        return ['ok' => true, 'created' => 1, 'payment_url' => $paymentUrl];
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("UPDATE eventos_formatura_financeiro SET status = 'cancelado', pixgo_idempotency_key = :key, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':key' => $idempotencyKey, ':id' => $receitaId]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function eventos_formatura_atualizar_cobranca(PDO $pdo, int $eventoId, int $formandoId, array $dados): array
{
    $cobrancaId = (int)($dados['cobranca_id'] ?? 0);
    $descricao = trim((string)($dados['descricao'] ?? ''));
    $vencimento = trim((string)($dados['vencimento'] ?? ''));
    $valor = eventos_formatura_money($dados['valor'] ?? 0);
    $statusSolicitado = (string)($dados['status_pagamento'] ?? '');
    $statusPermitidos = ['pendente', 'pago', 'vencido', 'cancelado'];

    if ($cobrancaId <= 0) {
        return ['ok' => false, 'error' => 'Cobrança não encontrada.'];
    }
    if ($descricao === '') {
        return ['ok' => false, 'error' => 'Informe a descrição da cobrança.'];
    }
    if ($vencimento === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencimento)) {
        return ['ok' => false, 'error' => 'Informe um vencimento válido.'];
    }
    if ($valor <= 0) {
        return ['ok' => false, 'error' => 'Informe um valor maior que zero.'];
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_formatura_financeiro
        WHERE id = :id
          AND evento_id = :evento_id
          AND formando_id = :formando_id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $cobrancaId,
        ':evento_id' => $eventoId,
        ':formando_id' => $formandoId,
    ]);
    $cobranca = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cobranca) {
        return ['ok' => false, 'error' => 'Cobrança não encontrada para este formando.'];
    }
    if ((string)($cobranca['carteira'] ?? '') === 'pixgo') {
        return ['ok' => false, 'error' => 'A cobrança PixGo é imutável. Gere uma nova cobrança para alterar valor, descrição ou vencimento.'];
    }
    if ((string)($cobranca['carteira'] ?? '') === 'asaas' && in_array((string)$cobranca['status'], ['pago', 'cancelado'], true)) {
        return ['ok' => false, 'error' => 'Cobranças Asaas pagas ou canceladas não podem ser editadas pelo painel.'];
    }

    $status = (string)$cobranca['status'];
    if ((string)($cobranca['carteira'] ?? '') === 'manual') {
        $status = in_array($statusSolicitado, $statusPermitidos, true) ? $statusSolicitado : 'pendente';
    }

    $asaasPayload = null;
    if ((string)($cobranca['carteira'] ?? '') === 'asaas' && !empty($cobranca['asaas_payment_id'])) {
        require_once __DIR__ . '/asaas_helper.php';
        $asaas = new AsaasHelper();
        $asaasPayload = $asaas->updatePayment((string)$cobranca['asaas_payment_id'], [
            'description' => $descricao,
            'due_date' => $vencimento,
            'value' => $valor,
        ]);
    }

    $stmt = $pdo->prepare("
        UPDATE eventos_formatura_financeiro
        SET descricao = :descricao,
            vencimento = :vencimento,
            valor = :valor,
            status = :status,
            pago_em = CASE WHEN :status_pago = 'pago' THEN COALESCE(pago_em, NOW()) ELSE NULL END,
            asaas_payload = CASE WHEN :asaas_payload <> '' THEN CAST(:asaas_payload AS JSONB) ELSE asaas_payload END,
            updated_at = NOW()
        WHERE id = :id
          AND evento_id = :evento_id
          AND formando_id = :formando_id
    ");
    $stmt->execute([
        ':descricao' => $descricao,
        ':vencimento' => $vencimento,
        ':valor' => $valor,
        ':status' => $status,
        ':status_pago' => $status,
        ':asaas_payload' => $asaasPayload ? json_encode($asaasPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        ':id' => $cobrancaId,
        ':evento_id' => $eventoId,
        ':formando_id' => $formandoId,
    ]);

    return ['ok' => true];
}

function eventos_formatura_sincronizar_status_asaas(PDO $pdo, int $eventoId): array
{
    $resultado = [
        'consultadas' => 0,
        'atualizadas' => 0,
        'falhas' => 0,
    ];

    if ($eventoId <= 0) {
        return $resultado;
    }

    $stmt = $pdo->prepare("
        SELECT asaas_payment_id
        FROM eventos_formatura_financeiro
        WHERE evento_id = :evento_id
          AND carteira = 'asaas'
          AND asaas_payment_id IS NOT NULL
          AND TRIM(asaas_payment_id) <> ''
          AND status NOT IN ('pago', 'cancelado')
        ORDER BY COALESCE(vencimento, created_at::date) ASC, id ASC
        LIMIT 50
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    $paymentIds = array_values(array_filter(array_map(static function ($row): string {
        return trim((string)($row['asaas_payment_id'] ?? ''));
    }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [])));

    if (!$paymentIds) {
        return $resultado;
    }

    require_once __DIR__ . '/asaas_helper.php';
    $asaas = new AsaasHelper();

    foreach ($paymentIds as $paymentId) {
        $resultado['consultadas']++;
        try {
            $payment = $asaas->getPaymentStatus($paymentId);
            if (is_array($payment) && !empty($payment['id'])) {
                if (eventos_formatura_financeiro_atualizar_asaas_payment($pdo, $paymentId, $payment)) {
                    $resultado['atualizadas']++;
                }
            }
        } catch (Throwable $e) {
            $resultado['falhas']++;
            error_log('Falha ao sincronizar status Asaas da formatura: payment_id=' . $paymentId . ' erro=' . $e->getMessage());
        }
    }

    return $resultado;
}

$eventoId = (int)($_GET['evento_id'] ?? $_POST['evento_id'] ?? 0);
$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$messages = [];
$errors = [];

eventos_formatura_ensure_schema($pdo);
$evento = $eventoId > 0 ? eventos_formatura_evento($pdo, $eventoId) : null;

if (!$evento) {
    includeSidebar('Formatura');
    echo '<div style="padding:2rem"><div class="alert alert-error">Evento não encontrado.</div></div>';
    endSidebar();
    return;
}

$tipoEvento = eventos_reuniao_normalizar_tipo_evento_real((string)($evento['tipo_evento_real'] ?? ''), $pdo);
if ($tipoEvento !== 'formatura') {
    $errors[] = 'Este evento não está marcado como Formatura.';
}
$eventoUnidade = trim((string)($evento['space_visivel'] ?? ''));
$unidades = eventos_formatura_unidades($pdo, $eventoUnidade);
$formaturaConfig = eventos_formatura_config($pdo, $eventoId);

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_config') {
        $pessoasPorMesa = max(0, (int)($_POST['pessoas_por_mesa'] ?? 0));
        $valorMesa = max(0.0, eventos_formatura_money($_POST['valor_mesa'] ?? 0));
        $valorAdicional = max(0.0, eventos_formatura_money($_POST['valor_convidado_adicional'] ?? 0));
        $valorCriancaMeia = max(0.0, eventos_formatura_money($_POST['valor_crianca_meia'] ?? 0));

        if ($pessoasPorMesa <= 0) {
            $errors[] = 'Informe a quantidade de pessoas por mesa.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO eventos_formatura_config
                    (evento_id, pessoas_por_mesa, valor_mesa, valor_convidado_adicional, valor_crianca_meia, created_by, updated_at)
                VALUES
                    (:evento_id, :pessoas_por_mesa, :valor_mesa, :valor_convidado_adicional, :valor_crianca_meia, :created_by, NOW())
                ON CONFLICT (evento_id) DO UPDATE
                SET pessoas_por_mesa = EXCLUDED.pessoas_por_mesa,
                    valor_mesa = EXCLUDED.valor_mesa,
                    valor_convidado_adicional = EXCLUDED.valor_convidado_adicional,
                    valor_crianca_meia = EXCLUDED.valor_crianca_meia,
                    updated_at = NOW()
            ");
            $stmt->execute([
                ':evento_id' => $eventoId,
                ':pessoas_por_mesa' => $pessoasPorMesa,
                ':valor_mesa' => $valorMesa,
                ':valor_convidado_adicional' => $valorAdicional,
                ':valor_crianca_meia' => $valorCriancaMeia,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
            $_SESSION['eventos_formatura_message'] = 'Configurações financeiras da formatura salvas.';
            eventos_formatura_redirect($eventoId);
        }
    }

    if ($action === 'save_formando') {
        $formandoId = (int)($_POST['formando_id'] ?? 0);
        $clienteId = (int)($_POST['cliente_cadastro_id'] ?? 0);
        $nomeFormando = trim((string)($_POST['nome_formando'] ?? ''));
        $convidados = max(0, (int)($_POST['convidados'] ?? 0));
        $criancasMeia = max(0, (int)($_POST['criancas_meia'] ?? 0));
        $mesas = max(0, (int)($_POST['mesas'] ?? 0));

        if ($clienteId <= 0) {
            $errors[] = 'Selecione o cliente do formando.';
        }
        if ($nomeFormando === '') {
            $errors[] = 'Informe o nome do formando.';
        }

        if (empty($errors)) {
            $stmtCliente = $pdo->prepare("SELECT id FROM comercial_cadastro_clientes WHERE id = :id LIMIT 1");
            $stmtCliente->execute([':id' => $clienteId]);
            if (!$stmtCliente->fetchColumn()) {
                $errors[] = 'Cliente selecionado não encontrado.';
            }
        }

        if (empty($errors)) {
            if ($formandoId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE eventos_formatura_formandos
                    SET cliente_cadastro_id = :cliente_id,
                        nome_formando = :nome_formando,
                        convidados = :convidados,
                        criancas_meia = :criancas_meia,
                        mesas = :mesas,
                        updated_at = NOW()
                    WHERE id = :id
                      AND evento_id = :evento_id
                      AND deleted_at IS NULL
                ");
                $stmt->execute([
                    ':cliente_id' => $clienteId,
                    ':nome_formando' => $nomeFormando,
                    ':convidados' => $convidados,
                    ':criancas_meia' => $criancasMeia,
                    ':mesas' => $mesas,
                    ':id' => $formandoId,
                    ':evento_id' => $eventoId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO eventos_formatura_formandos
                        (evento_id, cliente_cadastro_id, nome_formando, convidados, criancas_meia, mesas, created_by, updated_at)
                    VALUES
                        (:evento_id, :cliente_id, :nome_formando, :convidados, :criancas_meia, :mesas, :created_by, NOW())
                ");
                $stmt->execute([
                    ':evento_id' => $eventoId,
                    ':cliente_id' => $clienteId,
                    ':nome_formando' => $nomeFormando,
                    ':convidados' => $convidados,
                    ':criancas_meia' => $criancasMeia,
                    ':mesas' => $mesas,
                    ':created_by' => $userId > 0 ? $userId : null,
                ]);
            }

            $_SESSION['eventos_formatura_message'] = $formandoId > 0 ? 'Formando atualizado.' : 'Formando cadastrado.';
            eventos_formatura_redirect($eventoId);
        }
    }

    if ($action === 'delete_formando') {
        $formandoId = (int)($_POST['formando_id'] ?? 0);
        if ($formandoId > 0) {
            $stmt = $pdo->prepare("
                UPDATE eventos_formatura_formandos
                SET deleted_at = NOW(), updated_at = NOW()
                WHERE id = :id
                  AND evento_id = :evento_id
            ");
            $stmt->execute([':id' => $formandoId, ':evento_id' => $eventoId]);
            $_SESSION['eventos_formatura_message'] = 'Formando excluído.';
            eventos_formatura_redirect($eventoId);
        }
    }

    if ($action === 'generate_documento') {
        $formandoId = (int)($_POST['formando_id'] ?? 0);
        $modeloId = (int)($_POST['modelo_id'] ?? 0);

        if ($formandoId <= 0) {
            $errors[] = 'Selecione o formando para gerar o documento.';
        }
        if ($modeloId <= 0) {
            $errors[] = 'Selecione o modelo de contrato/documento.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                SELECT
                    f.*,
                    c.nome_completo AS cliente_nome,
                    c.documento_numero AS cliente_documento,
                    c.rg AS cliente_rg,
                    c.email AS cliente_email,
                    c.telefone_whatsapp AS cliente_telefone,
                    c.cep AS cliente_cep,
                    c.endereco_logradouro AS cliente_endereco,
                    c.endereco_numero AS cliente_numero,
                    c.endereco_complemento AS cliente_complemento,
                    c.endereco_bairro AS cliente_bairro,
                    c.endereco_cidade AS cliente_cidade,
                    c.endereco_estado AS cliente_estado
                FROM eventos_formatura_formandos f
                JOIN comercial_cadastro_clientes c ON c.id = f.cliente_cadastro_id
                WHERE f.id = :id
                  AND f.evento_id = :evento_id
                  AND f.deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([':id' => $formandoId, ':evento_id' => $eventoId]);
            $formandoDocumento = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $stmtModelo = $pdo->prepare("
                SELECT id, nome, conteudo_html
                FROM contrato_modelos
                WHERE id = :id
                  AND COALESCE(ativo, TRUE) = TRUE
                LIMIT 1
            ");
            $stmtModelo->execute([':id' => $modeloId]);
            $modeloDocumento = $stmtModelo->fetch(PDO::FETCH_ASSOC) ?: null;

            if (!$formandoDocumento) {
                $errors[] = 'Formando não encontrado.';
            }
            if (!$modeloDocumento) {
                $errors[] = 'Modelo não encontrado ou inativo.';
            }
        }

        if (empty($errors) && is_array($formandoDocumento ?? null) && is_array($modeloDocumento ?? null)) {
            $formandoDocumento += eventos_formatura_calcular_formando($formandoDocumento, $formaturaConfig);
            $stmtFin = $pdo->prepare("
                SELECT *
                FROM eventos_formatura_financeiro
                WHERE evento_id = :evento_id
                  AND formando_id = :formando_id
                ORDER BY COALESCE(vencimento, created_at::date), id
            ");
            $stmtFin->execute([':evento_id' => $eventoId, ':formando_id' => $formandoId]);
            $financeiroDocumento = $stmtFin->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $itensContratadosDocumento = eventos_financeiro_listar_pedidos($pdo, $eventoId);

            $conteudo = eventos_formatura_renderizar_modelo(
                (string)$modeloDocumento['conteudo_html'],
                eventos_formatura_mapa_tags($evento, $formandoDocumento, $financeiroDocumento, $itensContratadosDocumento)
            );
            $titulo = trim((string)$modeloDocumento['nome']) . ' - ' . trim((string)$formandoDocumento['cliente_nome']);
            $stmtInsert = $pdo->prepare("
                INSERT INTO eventos_formatura_documentos
                    (evento_id, formando_id, modelo_id, titulo, conteudo_html, status, minuta_token, created_by, updated_at)
                VALUES
                    (:evento_id, :formando_id, :modelo_id, :titulo, :conteudo_html, 'criado', :minuta_token, :created_by, NOW())
            ");
            $stmtInsert->execute([
                ':evento_id' => $eventoId,
                ':formando_id' => $formandoId,
                ':modelo_id' => $modeloId,
                ':titulo' => $titulo,
                ':conteudo_html' => $conteudo,
                ':minuta_token' => eventos_formatura_documento_token(),
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
            $_SESSION['eventos_formatura_message'] = 'Documento gerado para o responsável do formando.';
            eventos_formatura_redirect($eventoId);
        }
    }

    if ($action === 'save_documento') {
        $documentoId = (int)($_POST['documento_id'] ?? 0);
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $conteudo = trim((string)($_POST['conteudo_html'] ?? ''));

        if ($documentoId <= 0) {
            $errors[] = 'Documento não encontrado.';
        }
        if ($titulo === '') {
            $errors[] = 'Informe o título do documento.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                UPDATE eventos_formatura_documentos
                SET titulo = :titulo,
                    conteudo_html = :conteudo_html,
                    updated_at = NOW()
                WHERE id = :id
                  AND evento_id = :evento_id
                  AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':titulo' => $titulo,
                ':conteudo_html' => $conteudo,
                ':id' => $documentoId,
                ':evento_id' => $eventoId,
            ]);
            $_SESSION['eventos_formatura_message'] = 'Documento atualizado.';
            eventos_formatura_redirect($eventoId);
        }
    }

    if ($action === 'delete_documento') {
        $documentoId = (int)($_POST['documento_id'] ?? 0);
        if ($documentoId > 0) {
            $stmt = $pdo->prepare("
                UPDATE eventos_formatura_documentos
                SET deleted_at = NOW(), updated_at = NOW()
                WHERE id = :id
                  AND evento_id = :evento_id
            ");
            $stmt->execute([':id' => $documentoId, ':evento_id' => $eventoId]);
            $_SESSION['eventos_formatura_message'] = 'Documento excluído.';
            eventos_formatura_redirect($eventoId);
        }
    }

    if ($action === 'save_financeiro') {
        $formandoId = (int)($_POST['formando_id'] ?? 0);
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $valoresParcelas = is_array($_POST['parcela_valor'] ?? null) ? $_POST['parcela_valor'] : [];

        if ($formandoId <= 0) {
            $errors[] = 'Selecione o formando para o lançamento.';
        }
        if ($descricao === '') {
            $errors[] = 'Informe a descrição do lançamento.';
        }
        if (empty($valoresParcelas)) {
            $errors[] = 'Informe pelo menos uma parcela.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM eventos_formatura_formandos WHERE id = :id AND evento_id = :evento_id AND deleted_at IS NULL");
            $stmt->execute([':id' => $formandoId, ':evento_id' => $eventoId]);
            if (!$stmt->fetchColumn()) {
                $errors[] = 'Formando não encontrado.';
            }
        }

        if (empty($errors)) {
            $formandoSelecionado = null;
            foreach ($formandos ?? [] as $formandoItem) {
                if ((int)$formandoItem['id'] === $formandoId) {
                    $formandoSelecionado = $formandoItem;
                    break;
                }
            }
            if (!$formandoSelecionado) {
                $formandoSelecionado = eventos_formatura_formandos($pdo, $eventoId);
                $formandoSelecionado = current(array_filter($formandoSelecionado, static fn($item) => (int)$item['id'] === $formandoId)) ?: null;
            }

            $carteira = (string)($_POST['carteira'] ?? 'manual');
            if ($carteira === 'asaas' && is_array($formandoSelecionado)) {
                $result = eventos_formatura_criar_pix_asaas($pdo, $eventoId, $evento, $formandoSelecionado, $_POST, $userId);
            } elseif ($carteira === 'pixgo' && is_array($formandoSelecionado)) {
                $result = eventos_formatura_criar_pix_pixgo($pdo, $eventoId, $evento, $formandoSelecionado, $_POST, $userId);
            } else {
                $result = eventos_formatura_salvar_receitas_manual_lote($pdo, $eventoId, $formandoId, $_POST, $userId);
            }
            if (!empty($result['ok'])) {
                $created = (int)($result['created'] ?? 1);
                $_SESSION['eventos_formatura_message'] = $created > 1 ? 'Receitas criadas.' : 'Receita criada.';
                if (!empty($result['warning'])) {
                    $_SESSION['eventos_formatura_message'] .= ' ' . (string)$result['warning'];
                }
                eventos_formatura_redirect_financeiro($eventoId, $formandoId);
            }
            $errors[] = (string)($result['error'] ?? 'Não foi possível salvar a receita.');
        }
    }

    if ($action === 'update_financeiro') {
        $formandoId = (int)($_POST['formando_id'] ?? 0);
        if ($formandoId <= 0) {
            $errors[] = 'Selecione o formando para editar a cobrança.';
        }

        if (empty($errors)) {
            try {
                $result = eventos_formatura_atualizar_cobranca($pdo, $eventoId, $formandoId, $_POST);
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => $e->getMessage()];
            }

            if (!empty($result['ok'])) {
                $_SESSION['eventos_formatura_message'] = 'Cobrança atualizada.';
                eventos_formatura_redirect_financeiro($eventoId, $formandoId);
            }
            $errors[] = (string)($result['error'] ?? 'Não foi possível atualizar a cobrança.');
        }
    }
}

if ($requestMethod !== 'POST') {
    eventos_formatura_sincronizar_status_asaas($pdo, $eventoId);
}

if (!empty($_SESSION['eventos_formatura_message'])) {
    $messages[] = (string)$_SESSION['eventos_formatura_message'];
    unset($_SESSION['eventos_formatura_message']);
}

$clientes = eventos_formatura_clientes($pdo);
$formandos = eventos_formatura_aplicar_config(eventos_formatura_formandos($pdo, $eventoId), $formaturaConfig);
$financeiro = eventos_formatura_financeiro($pdo, $eventoId);
$modelosContrato = eventos_formatura_modelos_contrato($pdo);
$formandoFinanceiroId = (int)($_GET['formando_id'] ?? 0);
$mostrarNovaCobranca = (string)($_GET['nova_cobranca'] ?? '') === '1';
$formandoFinanceiro = null;
$financeiroFormando = [];
$clientesJson = [];
foreach ($clientes as $cliente) {
    $labelParts = [(string)$cliente['nome']];
    if (!empty($cliente['telefone'])) {
        $labelParts[] = (string)$cliente['telefone'];
    }
    if (!empty($cliente['documento'])) {
        $labelParts[] = (string)$cliente['documento'];
    }
    $clientesJson[] = [
        'id' => (int)$cliente['id'],
        'nome' => (string)$cliente['nome'],
        'documento' => (string)$cliente['documento'],
        'email' => (string)$cliente['email'],
        'telefone' => (string)$cliente['telefone'],
        'label' => implode(' - ', array_filter($labelParts)),
    ];
}

foreach ($formandos as $formandoItem) {
    if ((int)$formandoItem['id'] === $formandoFinanceiroId) {
        $formandoFinanceiro = $formandoItem;
        break;
    }
}

if ($formandoFinanceiro) {
    foreach ($financeiro as $financeiroItem) {
        if ((int)$financeiroItem['formando_id'] === $formandoFinanceiroId) {
            $financeiroFormando[] = $financeiroItem;
        }
    }
}

$totalConvidados = array_sum(array_map(static fn($item) => (int)$item['convidados'], $formandos));
$totalCriancasMeia = array_sum(array_map(static fn($item) => (int)($item['criancas_meia'] ?? 0), $formandos));
$totalMesas = array_sum(array_map(static fn($item) => (int)$item['mesas'], $formandos));
$totalLancado = array_sum(array_map(static fn($item) => (float)($item['valor_lancado_display'] ?? $item['total_lancado'] ?? 0), $formandos));
$totalPago = array_sum(array_map(static fn($item) => (float)$item['valor'], array_filter($financeiro, static fn($item) => (string)$item['status'] === 'pago')));

includeSidebar('Formatura');
?>

<style>
.formatura-page {
    max-width: 1260px;
    margin: 0 auto;
    padding: 1.5rem;
    background: #f8fafc;
}
.formatura-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.2rem;
    flex-wrap: wrap;
}
.formatura-title {
    margin: 0;
    color: #1e3a8a;
    font-size: 1.8rem;
    font-weight: 900;
}
.formatura-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-weight: 650;
}
.formatura-btn {
    border: 0;
    border-radius: 8px;
    padding: 0.72rem 1rem;
    font-weight: 850;
    color: #fff;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    cursor: pointer;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
}
.formatura-btn--primary { background: #16c784; }
.formatura-btn--info { background: #33b7c9; }
.formatura-btn--warning { background: #f5ba28; color: #fff; }
.formatura-btn--dark { background: #2f4050; }
.formatura-btn--light { background: #fff; color: #1f2a44; border: 1px solid #d6e0ec; }
.formatura-panel {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    overflow: hidden;
}
.formatura-panel-head {
    padding: 1.2rem 1.4rem;
    border-bottom: 1px solid #e5edf6;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.formatura-panel-head h2 {
    margin: 0;
    color: #334155;
    font-size: 1.2rem;
    font-weight: 900;
}
.formatura-toolbar {
    padding: 1.2rem 1.4rem;
    display: flex;
    gap: 0.7rem;
    align-items: center;
    flex-wrap: wrap;
}
.formatura-financeiro-head {
    padding: 0 1.4rem 1.25rem;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(360px, 0.9fr);
    gap: 1rem;
    align-items: stretch;
}
.formatura-financeiro-person {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #fbfdff;
    padding: 1rem;
}
.formatura-financeiro-head h3 {
    margin: 0;
    color: #1f2937;
    font-size: 1.5rem;
    font-weight: 900;
}
.formatura-financeiro-head p {
    margin: 0.35rem 0 0;
    color: #475569;
    font-weight: 700;
}
.formatura-financeiro-resumo {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.7rem;
}
.formatura-financeiro-card {
    border: 1px solid #dbe3ef;
    border-radius: 10px;
    background: #fff;
    padding: 0.9rem;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
}
.formatura-financeiro-card span {
    display: block;
    color: #64748b;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}
.formatura-financeiro-card strong {
    display: block;
    margin-top: 0.28rem;
    color: #1e3a8a;
    font-size: 1.05rem;
    font-weight: 950;
    white-space: nowrap;
}
.formatura-financeiro-card--saldo strong {
    color: #0f766e;
}
.formatura-calc-note {
    margin: 0 1.4rem 1rem;
    padding: 0.9rem;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    background: #eff6ff;
    color: #1e3a8a;
    display: flex;
    gap: 0.6rem;
    align-items: center;
    flex-wrap: wrap;
}
.formatura-calc-note strong {
    margin-right: 0.2rem;
    font-weight: 950;
}
.formatura-calc-chip {
    display: inline-flex;
    align-items: center;
    min-height: 32px;
    padding: 0.35rem 0.6rem;
    border-radius: 999px;
    background: #fff;
    border: 1px solid #dbeafe;
    color: #334155;
    font-size: 0.86rem;
    font-weight: 850;
}
.formatura-receita-card {
    margin: 0 1.4rem 1.2rem;
    padding: 1rem;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    background: #f8fafc;
}
.formatura-receita-card h3,
.formatura-section-title {
    margin: 0 0 0.9rem;
    color: #475569;
    font-size: 1rem;
    font-weight: 900;
}
.formatura-choice-row {
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
}
.formatura-choice {
    border: 1px solid #dbe3ef;
    background: #fff;
    color: #1f2937;
    border-radius: 999px;
    padding: 0.62rem 0.9rem;
    font-weight: 900;
    cursor: pointer;
}
.formatura-choice.is-active {
    background: #1e3a8a;
    color: #fff;
    border-color: #1e3a8a;
}
.formatura-parcelas-box {
    margin-top: 1rem;
    border: 1px solid #dbe3ef;
    border-radius: 10px;
    padding: 1rem;
    background: #fff;
}
.formatura-parcelas-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
}
.formatura-parcelas-head strong {
    color: #1f2937;
    font-weight: 900;
}
.formatura-parcelas-table {
    width: 100%;
    border-collapse: collapse;
}
.formatura-parcelas-table th {
    text-align: left;
    color: #64748b;
    text-transform: uppercase;
    font-size: 0.78rem;
    padding: 0.42rem;
}
.formatura-parcelas-table td {
    padding: 0.42rem;
}
.formatura-parcelas-table input {
    padding: 0.58rem 0.65rem;
}
.formatura-inline-actions {
    margin-top: 1rem;
    display: flex;
    justify-content: flex-end;
    gap: 0.7rem;
    flex-wrap: wrap;
}
.formatura-search {
    margin-left: auto;
    min-width: 240px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    padding: 0.72rem 0.85rem;
    font-size: 0.95rem;
}
.formatura-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(140px, 1fr));
    gap: 0.8rem;
    margin-bottom: 1rem;
}
.formatura-stat {
    background: #fff;
    border: 1px solid #dbe3ef;
    border-radius: 8px;
    padding: 1rem;
}
.formatura-stat strong {
    display: block;
    color: #1e3a8a;
    font-size: 1.25rem;
}
.formatura-stat span {
    color: #64748b;
    font-weight: 750;
}
.formatura-table-wrap {
    padding: 0 1.4rem 1.4rem;
    overflow-x: auto;
}
.formatura-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}
.formatura-table th {
    background: #e5e7eb;
    color: #4b5563;
    text-align: left;
    padding: 0.9rem;
    font-weight: 900;
    border-right: 2px solid #d6d6d6;
}
.formatura-table td {
    padding: 0.9rem;
    border-bottom: 1px solid #dbe3ef;
    border-right: 2px solid #eeeeee;
    color: #334155;
    vertical-align: middle;
}
.formatura-table strong {
    display: block;
    color: #1f2937;
}
.formatura-muted {
    color: #64748b;
    font-size: 0.88rem;
}
.formatura-actions {
    display: flex;
    gap: 0.45rem;
    align-items: center;
}
.formatura-icon-btn {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #334155;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-weight: 900;
    text-decoration: none;
}
.formatura-icon-btn--text {
    width: auto;
    min-width: 72px;
    padding: 0 0.75rem;
    border-radius: 8px;
}
.formatura-icon-btn:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.formatura-icon-btn--danger {
    color: #dc2626;
    border-color: #fecaca;
}
.formatura-icon-btn--danger:hover {
    background: #fef2f2;
    border-color: #f87171;
}
.formatura-icon-btn--accent {
    color: #0f766e;
    border-color: #99f6e4;
}
.formatura-chip {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.25rem 0.55rem;
    background: #e2e8f0;
    color: #334155;
    font-size: 0.8rem;
    font-weight: 900;
}
.formatura-chip--warning { background: #fef3c7; color: #92400e; }
.formatura-chip--success { background: #dcfce7; color: #166534; }
.formatura-doc-preview {
    min-height: 280px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    line-height: 1.45;
}
.formatura-link-box {
    display: flex;
    gap: 0.6rem;
    align-items: center;
}
.formatura-link-box input {
    flex: 1;
}
.formatura-panel + .formatura-panel {
    margin-top: 1rem;
}
.formatura-empty {
    padding: 2rem 1.4rem;
    color: #64748b;
    font-weight: 700;
}
.alert {
    margin-bottom: 1rem;
    padding: 0.9rem 1rem;
    border-radius: 8px;
    font-weight: 750;
}
.alert-success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #86efac;
}
.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.formatura-modal {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.2rem;
    background: rgba(15, 23, 42, 0.5);
    z-index: 1000;
}
.formatura-modal.is-open {
    display: flex;
}
.formatura-modal-dialog {
    width: min(860px, 96vw);
    max-height: 90vh;
    overflow: auto;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
}
.formatura-modal-head {
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e5edf6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.formatura-modal-head h3 {
    margin: 0;
    color: #1f2937;
    font-size: 1.15rem;
    font-weight: 900;
}
.formatura-close {
    border: 0;
    background: #eef2f7;
    color: #1f2937;
    width: 34px;
    height: 34px;
    border-radius: 999px;
    font-size: 1.25rem;
    cursor: pointer;
}
.formatura-modal-body {
    padding: 1.2rem;
}
.formatura-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.9rem;
}
.formatura-field label {
    display: block;
    margin-bottom: 0.35rem;
    color: #334155;
    font-weight: 850;
}
.formatura-field input,
.formatura-field select,
.formatura-field textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 0.72rem 0.8rem;
    font: inherit;
}
.formatura-field textarea {
    min-height: 92px;
    resize: vertical;
}
.formatura-field.full {
    grid-column: 1 / -1;
}
.formatura-hidden {
    display: none !important;
}
.cliente-preview {
    margin-top: 0.55rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.75rem;
    color: #475569;
    font-weight: 650;
}
.formatura-modal-actions {
    padding: 1rem 1.2rem;
    border-top: 1px solid #e5edf6;
    display: flex;
    justify-content: flex-end;
    gap: 0.7rem;
}
@media (max-width: 860px) {
    .formatura-stats,
    .formatura-grid,
    .formatura-financeiro-head,
    .formatura-financeiro-resumo {
        grid-template-columns: 1fr;
    }
    .formatura-search {
        width: 100%;
        margin-left: 0;
    }
}
</style>

<div class="formatura-page">
    <div class="formatura-header">
        <div>
            <h1 class="formatura-title">Formatura</h1>
            <p class="formatura-subtitle">
                <?= eventos_formatura_e((string)$evento['nome_evento']) ?> ·
                <?= eventos_formatura_e((string)$evento['local_evento']) ?> ·
                <?= eventos_formatura_e((string)$evento['data_evento']) ?>
            </p>
        </div>
        <a class="formatura-btn formatura-btn--light" href="index.php?page=agenda_eventos&evento_id=<?= (int)$eventoId ?>">← Voltar ao evento</a>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= eventos_formatura_e($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= eventos_formatura_e($error) ?></div>
    <?php endforeach; ?>

    <?php if ($formandoFinanceiro): ?>
        <?php
        $financeiroFormandoLancadoManual = array_sum(array_map(static fn($item) => (float)$item['valor'], array_filter($financeiroFormando, static fn($item) => (string)$item['status'] !== 'cancelado')));
        $financeiroFormandoLancado = (float)($formandoFinanceiro['valor_lancado_display'] ?? $financeiroFormandoLancadoManual);
        $financeiroFormandoPago = array_sum(array_map(static fn($item) => (float)$item['valor'], array_filter($financeiroFormando, static fn($item) => (string)$item['status'] === 'pago')));
        $financeiroFormandoSaldo = max(0, $financeiroFormandoLancado - $financeiroFormandoPago);
        ?>
        <section class="formatura-panel">
            <div class="formatura-panel-head">
                <h2>Financeiro do Formando</h2>
            </div>
            <div class="formatura-toolbar">
                <a class="formatura-btn formatura-btn--warning" href="index.php?page=eventos_formatura&evento_id=<?= (int)$eventoId ?>">← Voltar</a>
                <a class="formatura-btn formatura-btn--primary" href="index.php?page=eventos_formatura&evento_id=<?= (int)$eventoId ?>&formando_id=<?= (int)$formandoFinanceiroId ?>&nova_cobranca=1">＋ Adicionar Cobrança</a>
            </div>

            <div class="formatura-financeiro-head">
                <div class="formatura-financeiro-person">
                    <h3><?= eventos_formatura_e((string)$formandoFinanceiro['nome_formando']) ?></h3>
                    <p><?= eventos_formatura_e((string)($formandoFinanceiro['cliente_telefone'] ?? '')) ?></p>
                    <p class="formatura-muted"><?= eventos_formatura_e((string)($formandoFinanceiro['cliente_email'] ?? '')) ?></p>
                </div>
                <div class="formatura-financeiro-resumo">
                    <div class="formatura-financeiro-card">
                        <span>Lançado</span>
                        <strong>R$ <?= number_format($financeiroFormandoLancado, 2, ',', '.') ?></strong>
                    </div>
                    <div class="formatura-financeiro-card">
                        <span>Pago</span>
                        <strong>R$ <?= number_format($financeiroFormandoPago, 2, ',', '.') ?></strong>
                    </div>
                    <div class="formatura-financeiro-card formatura-financeiro-card--saldo">
                        <span>Saldo</span>
                        <strong>R$ <?= number_format($financeiroFormandoSaldo, 2, ',', '.') ?></strong>
                    </div>
                </div>
            </div>
            <?php if (!empty($formandoFinanceiro['calculo_configurado'])): ?>
                <div class="formatura-calc-note">
                    <strong>Cálculo</strong>
                    <span class="formatura-calc-chip"><?= (int)$formandoFinanceiro['mesas'] ?> mesa(s) x R$ <?= number_format((float)$formaturaConfig['valor_mesa'], 2, ',', '.') ?></span>
                    <?php if ((int)$formandoFinanceiro['convidados_adicionais'] > 0): ?>
                        <span class="formatura-calc-chip"><?= (int)$formandoFinanceiro['convidados_adicionais'] ?> adicional(is) x R$ <?= number_format((float)$formaturaConfig['valor_convidado_adicional'], 2, ',', '.') ?></span>
                    <?php endif; ?>
                    <?php if ((int)($formandoFinanceiro['criancas_meia'] ?? 0) > 0): ?>
                        <span class="formatura-calc-chip"><?= (int)$formandoFinanceiro['criancas_meia'] ?> criança(s) 5 a 8 anos x R$ <?= number_format((float)$formaturaConfig['valor_crianca_meia'], 2, ',', '.') ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($mostrarNovaCobranca): ?>
                <div class="formatura-receita-card">
                    <h3>Nova Receita</h3>
                    <form method="post" id="formaturaReceitaForm">
                        <input type="hidden" name="action" value="save_financeiro">
                        <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                        <input type="hidden" name="formando_id" value="<?= (int)$formandoFinanceiroId ?>">
                        <input type="hidden" name="tipo_valor" id="formaturaTipoValor" value="avista">
                        <div class="formatura-grid">
                            <div class="formatura-field full">
                                <label>Tipo de valor</label>
                                <div class="formatura-choice-row">
                                    <button class="formatura-choice is-active" type="button" data-formatura-tipo="avista">Valor à vista</button>
                                    <button class="formatura-choice" type="button" data-formatura-tipo="parcelado">Valor parcelado</button>
                                </div>
                            </div>
                            <div class="formatura-field">
                                <label for="financeiroDescricao">Descrição</label>
                                <input id="financeiroDescricao" name="descricao" value="Receita do formando" placeholder="Ex.: Entrada do formando, parcela, adicional..." required>
                            </div>
                            <div class="formatura-field">
                                <label for="financeiroUnidade">Unidade</label>
                                <select id="financeiroUnidade" name="unidade">
                                    <?php foreach ($unidades as $unidade): ?>
                                        <option value="<?= eventos_formatura_e($unidade) ?>" <?= $unidade === $eventoUnidade ? 'selected' : '' ?>><?= eventos_formatura_e($unidade) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="formatura-field">
                                <label for="financeiroCarteira">Carteira</label>
                                <select id="financeiroCarteira" name="carteira">
                                    <option value="manual">Manual</option>
                                    <option value="asaas">Asaas</option>
                                    <option value="pixgo" <?= PIXGO_ENABLED ? '' : 'disabled' ?>>PixGo<?= PIXGO_ENABLED ? '' : ' (configurar Railway)' ?></option>
                                </select>
                            </div>
                            <div class="formatura-field">
                                <label for="financeiroModo">Modo de pagamento</label>
                                <select id="financeiroModo" name="modo_pagamento">
                                    <option value="pix">Pix</option>
                                    <option value="cartao_credito">Cartão de crédito</option>
                                    <option value="dinheiro">Dinheiro</option>
                                    <option value="cartao_debito">Cartão de débito</option>
                                    <option value="nao_informado">Não informado</option>
                                </select>
                            </div>
                            <div class="formatura-field">
                                <label for="financeiroStatus">Status de pagamento</label>
                                <select id="financeiroStatus" name="status_pagamento">
                                    <option value="pendente">Pendente</option>
                                    <option value="pago">Pago</option>
                                </select>
                            </div>
                            <div class="formatura-field">
                                <label for="financeiroValorTotal">Valor total</label>
                                <input id="financeiroValorTotal" name="valor_total" type="text" inputmode="decimal" placeholder="R$ 0,00" required>
                            </div>
                            <div class="formatura-field" id="financeiroParcelasField">
                                <label for="financeiroParcelas">Quantidade de parcelas</label>
                                <input id="financeiroParcelas" name="parcelas" type="number" min="1" max="24" value="1">
                            </div>
                            <div class="formatura-field">
                                <label for="financeiroPrimeiroVencimento">Primeiro vencimento</label>
                                <input id="financeiroPrimeiroVencimento" name="primeiro_vencimento" type="date" value="<?= eventos_formatura_e(date('Y-m-d')) ?>">
                            </div>
                        </div>
                        <div class="formatura-parcelas-box">
                            <div class="formatura-parcelas-head">
                                <strong>Parcelas</strong>
                                <span class="formatura-muted">Datas e valores podem ser alterados antes de salvar.</span>
                            </div>
                            <table class="formatura-parcelas-table">
                                <thead>
                                    <tr>
                                        <th>Parcela</th>
                                        <th>Vencimento</th>
                                        <th>Valor</th>
                                    </tr>
                                </thead>
                                <tbody id="financeiroParcelasBody"></tbody>
                            </table>
                        </div>
                        <div class="formatura-inline-actions">
                            <a class="formatura-btn formatura-btn--light" href="index.php?page=eventos_formatura&evento_id=<?= (int)$eventoId ?>&formando_id=<?= (int)$formandoFinanceiroId ?>">Cancelar</a>
                            <button type="submit" class="formatura-btn formatura-btn--primary">Salvar receita</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <div class="formatura-table-wrap">
                <h3 class="formatura-section-title">Gerenciar Cobranças</h3>
                <?php if (empty($financeiroFormando)): ?>
                    <div class="formatura-empty">Nenhum valor lançado.</div>
                <?php else: ?>
                    <table class="formatura-table">
                        <thead>
                            <tr>
                                <th>Descrição</th>
                                <th>Vencimento</th>
                                <th>Carteira</th>
                                <th>Status</th>
                                <th>Valor</th>
                                <th>Link</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($financeiroFormando as $cobranca): ?>
                                <tr>
                                    <td>
                                        <strong><?= eventos_formatura_e((string)$cobranca['descricao']) ?></strong>
                                        <?php if ((int)($cobranca['parcelas_total'] ?? 1) > 1): ?>
                                            <div class="formatura-muted">Parcela <?= (int)$cobranca['parcela_numero'] ?>/<?= (int)$cobranca['parcelas_total'] ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= !empty($cobranca['vencimento']) ? eventos_formatura_e(date('d/m/Y', strtotime((string)$cobranca['vencimento']))) : '-' ?></td>
                                    <td>
                                        <strong><?= eventos_formatura_e(ucfirst((string)($cobranca['carteira'] ?? 'manual'))) ?></strong>
                                        <div class="formatura-muted"><?= eventos_formatura_e(eventos_formatura_label_modo((string)($cobranca['modo_pagamento'] ?? $cobranca['forma_pagamento'] ?? ''))) ?></div>
                                    </td>
                                    <td><?= eventos_formatura_e(eventos_formatura_label_status((string)$cobranca['status'])) ?></td>
                                    <td><strong>R$ <?= number_format((float)$cobranca['valor'], 2, ',', '.') ?></strong></td>
                                    <td>
                                        <?php $paymentLink = (string)($cobranca['pixgo_payment_url'] ?? $cobranca['asaas_invoice_url'] ?? ''); ?>
                                        <?php if ($paymentLink !== ''): ?>
                                            <button
                                                type="button"
                                                class="formatura-icon-btn formatura-icon-btn--text btnCopiarLinkCobranca"
                                                data-link="<?= eventos_formatura_e($paymentLink) ?>"
                                            >Copiar</button>
                                        <?php else: ?>
                                            <span class="formatura-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $cobrancaCarteira = (string)($cobranca['carteira'] ?? 'manual');
                                        $bloquearEdicaoCobranca = $cobrancaCarteira === 'pixgo'
                                            || ($cobrancaCarteira === 'asaas' && in_array((string)$cobranca['status'], ['pago', 'cancelado'], true));
                                        ?>
                                        <?php if (!$bloquearEdicaoCobranca): ?>
                                            <button
                                                type="button"
                                                class="formatura-icon-btn formatura-icon-btn--text btnEditarCobranca"
                                                data-id="<?= (int)$cobranca['id'] ?>"
                                                data-descricao="<?= eventos_formatura_e((string)$cobranca['descricao']) ?>"
                                                data-vencimento="<?= eventos_formatura_e((string)($cobranca['vencimento'] ?? '')) ?>"
                                                data-valor="<?= eventos_formatura_e(number_format((float)$cobranca['valor'], 2, ',', '.')) ?>"
                                                data-status="<?= eventos_formatura_e((string)$cobranca['status']) ?>"
                                                data-carteira="<?= eventos_formatura_e((string)($cobranca['carteira'] ?? 'manual')) ?>"
                                            >Editar</button>
                                        <?php else: ?>
                                            <span class="formatura-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>

    <div class="formatura-stats">
        <div class="formatura-stat"><strong><?= count($formandos) ?></strong><span>Formandos</span></div>
        <div class="formatura-stat"><strong><?= (int)$totalConvidados ?></strong><span>Convidados</span></div>
        <div class="formatura-stat"><strong><?= (int)$totalCriancasMeia ?></strong><span>Crianças meia</span></div>
        <div class="formatura-stat"><strong><?= (int)$totalMesas ?></strong><span>Mesas</span></div>
        <div class="formatura-stat"><strong>R$ <?= number_format($totalPago, 2, ',', '.') ?></strong><span>Recebido de R$ <?= number_format($totalLancado, 2, ',', '.') ?></span></div>
    </div>

    <section class="formatura-panel">
        <div class="formatura-panel-head">
            <h2>Formandos Cadastrados</h2>
        </div>
        <div class="formatura-toolbar">
            <button type="button" class="formatura-btn formatura-btn--primary" id="btnNovoFormando">👥 Cadastrar Formando</button>
            <button type="button" class="formatura-btn formatura-btn--info" disabled>🖨️ Imprimir Formandos</button>
            <button type="button" class="formatura-btn formatura-btn--warning" disabled>📊 Relatório de Pagamentos</button>
            <button type="button" class="formatura-btn formatura-btn--dark" id="btnFormaturaConfig">⚙️ Configurações</button>
            <input class="formatura-search" id="formaturaBusca" type="search" placeholder="Pesquisar...">
        </div>

        <?php if (empty($formandos)): ?>
            <div class="formatura-empty">Nenhum formando cadastrado ainda.</div>
        <?php else: ?>
            <div class="formatura-table-wrap">
                <table class="formatura-table" id="formaturaTabela">
                    <thead>
                        <tr>
                            <th>Nome/CPF</th>
                            <th>Contato</th>
                            <th>Convidados/Mesas</th>
                            <th>Financeiro</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($formandos as $formando): ?>
                            <?php
                            $valorLancadoDisplay = (float)($formando['valor_lancado_display'] ?? $formando['total_lancado']);
                            $saldo = $valorLancadoDisplay - (float)$formando['total_pago'];
                            $rowSearch = strtolower(implode(' ', [
                                $formando['nome_formando'] ?? '',
                                $formando['cliente_nome'] ?? '',
                                $formando['cliente_documento'] ?? '',
                                $formando['cliente_email'] ?? '',
                                $formando['cliente_telefone'] ?? '',
                            ]));
                            ?>
                            <tr data-search="<?= eventos_formatura_e($rowSearch) ?>">
                                <td>
                                    <strong><?= eventos_formatura_e((string)$formando['nome_formando']) ?></strong>
                                    <div class="formatura-muted"><?= eventos_formatura_e((string)$formando['cliente_nome']) ?></div>
                                    <?php if (!empty($formando['cliente_documento'])): ?>
                                        <div class="formatura-muted"><?= eventos_formatura_e((string)$formando['cliente_documento']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= eventos_formatura_e((string)($formando['cliente_telefone'] ?? '-')) ?></div>
                                    <div class="formatura-muted"><?= eventos_formatura_e((string)($formando['cliente_email'] ?? '')) ?></div>
                                </td>
                                <td>
                                    <strong><?= (int)$formando['convidados'] ?> convidados</strong>
                                    <?php if ((int)($formando['criancas_meia'] ?? 0) > 0): ?>
                                        <div class="formatura-muted"><?= (int)$formando['criancas_meia'] ?> criança(s) meia</div>
                                    <?php endif; ?>
                                    <div class="formatura-muted"><?= (int)$formando['mesas'] ?> mesas</div>
                                </td>
                                <td>
                                    <strong>R$ <?= number_format($valorLancadoDisplay, 2, ',', '.') ?></strong>
                                    <?php if (!empty($formando['calculo_configurado'])): ?>
                                        <div class="formatura-muted"><?= (int)$formando['mesas'] ?> mesa(s)<?= (int)$formando['convidados_adicionais'] > 0 ? ' + ' . (int)$formando['convidados_adicionais'] . ' adicional(is)' : '' ?><?= (int)($formando['criancas_meia'] ?? 0) > 0 ? ' + ' . (int)$formando['criancas_meia'] . ' criança(s) meia' : '' ?></div>
                                    <?php endif; ?>
                                    <div class="formatura-muted">Pago: R$ <?= number_format((float)$formando['total_pago'], 2, ',', '.') ?></div>
                                    <div class="formatura-muted">Saldo: R$ <?= number_format($saldo, 2, ',', '.') ?></div>
                                </td>
                                <td>
                                    <div class="formatura-actions">
                                        <a class="formatura-icon-btn" title="Financeiro" href="index.php?page=eventos_formatura&evento_id=<?= (int)$eventoId ?>&formando_id=<?= (int)$formando['id'] ?>">$</a>
                                        <button
                                            type="button"
                                            class="formatura-icon-btn formatura-icon-btn--accent btnDocumentoFormando"
                                            title="Gerar contrato"
                                            data-id="<?= (int)$formando['id'] ?>"
                                            data-nome-formando="<?= eventos_formatura_e((string)$formando['nome_formando']) ?>"
                                            data-cliente-nome="<?= eventos_formatura_e((string)$formando['cliente_nome']) ?>"
                                        >📄</button>
                                        <button
                                            type="button"
                                            class="formatura-icon-btn btnEditarFormando"
                                            title="Editar"
                                            data-id="<?= (int)$formando['id'] ?>"
                                            data-cliente-id="<?= (int)$formando['cliente_cadastro_id'] ?>"
                                            data-nome-formando="<?= eventos_formatura_e((string)$formando['nome_formando']) ?>"
                                            data-convidados="<?= (int)$formando['convidados'] ?>"
                                            data-criancas-meia="<?= (int)($formando['criancas_meia'] ?? 0) ?>"
                                            data-mesas="<?= (int)$formando['mesas'] ?>"
                                        >✎</button>
                                        <form method="post" onsubmit="return confirm('Excluir este formando?');">
                                            <input type="hidden" name="action" value="delete_formando">
                                            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                                            <input type="hidden" name="formando_id" value="<?= (int)$formando['id'] ?>">
                                            <button type="submit" class="formatura-icon-btn" title="Excluir">🗑️</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>
</div>

<div class="formatura-modal" id="modalEditarCobranca" aria-hidden="true">
    <div class="formatura-modal-dialog">
        <div class="formatura-modal-head">
            <h3>Editar cobrança</h3>
            <button type="button" class="formatura-close" data-close-modal>×</button>
        </div>
        <form method="post" id="formEditarCobranca">
            <div class="formatura-modal-body">
                <input type="hidden" name="action" value="update_financeiro">
                <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                <input type="hidden" name="formando_id" value="<?= (int)$formandoFinanceiroId ?>">
                <input type="hidden" name="cobranca_id" id="editarCobrancaId" value="">
                <div class="formatura-grid">
                    <div class="formatura-field full">
                        <label for="editarCobrancaDescricao">Descrição</label>
                        <input id="editarCobrancaDescricao" name="descricao" type="text" required>
                    </div>
                    <div class="formatura-field">
                        <label for="editarCobrancaVencimento">Vencimento</label>
                        <input id="editarCobrancaVencimento" name="vencimento" type="date" required>
                    </div>
                    <div class="formatura-field">
                        <label for="editarCobrancaValor">Valor</label>
                        <input id="editarCobrancaValor" name="valor" type="text" inputmode="decimal" required>
                    </div>
                    <div class="formatura-field">
                        <label for="editarCobrancaStatus">Status de pagamento</label>
                        <select id="editarCobrancaStatus" name="status_pagamento">
                            <option value="pendente">Pendente</option>
                            <option value="pago">Pago</option>
                            <option value="vencido">Vencido</option>
                            <option value="cancelado">Cancelado</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="formatura-modal-actions">
                <button type="button" class="formatura-btn formatura-btn--light" data-close-modal>Cancelar</button>
                <button type="submit" class="formatura-btn formatura-btn--primary">Salvar cobrança</button>
            </div>
        </form>
    </div>
</div>

<div class="formatura-modal" id="modalFormaturaConfig" aria-hidden="true">
    <div class="formatura-modal-dialog">
        <div class="formatura-modal-head">
            <h3>Configurações financeiras</h3>
            <button type="button" class="formatura-close" data-close-modal>×</button>
        </div>
        <form method="post">
            <div class="formatura-modal-body">
                <input type="hidden" name="action" value="save_config">
                <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                <div class="formatura-grid">
                    <div class="formatura-field">
                        <label for="configPessoasMesa">Pessoas por mesa</label>
                        <input id="configPessoasMesa" name="pessoas_por_mesa" type="number" min="1" step="1" value="<?= (int)$formaturaConfig['pessoas_por_mesa'] ?>" required>
                    </div>
                    <div class="formatura-field">
                        <label for="configValorMesa">Valor da mesa</label>
                        <input id="configValorMesa" name="valor_mesa" type="text" inputmode="decimal" value="<?= eventos_formatura_e(number_format((float)$formaturaConfig['valor_mesa'], 2, ',', '.')) ?>" placeholder="0,00" required>
                    </div>
                    <div class="formatura-field">
                        <label for="configValorAdicional">Valor por convidado adicional</label>
                        <input id="configValorAdicional" name="valor_convidado_adicional" type="text" inputmode="decimal" value="<?= eventos_formatura_e(number_format((float)$formaturaConfig['valor_convidado_adicional'], 2, ',', '.')) ?>" placeholder="0,00" required>
                    </div>
                    <div class="formatura-field">
                        <label for="configValorCriancaMeia">Valor criança 5 a 8 anos</label>
                        <input id="configValorCriancaMeia" name="valor_crianca_meia" type="text" inputmode="decimal" value="<?= eventos_formatura_e(number_format((float)$formaturaConfig['valor_crianca_meia'], 2, ',', '.')) ?>" placeholder="90,00" required>
                    </div>
                    <div class="formatura-field">
                        <label>Cálculo</label>
                        <div class="cliente-preview">
                            Mesas x valor da mesa. Convidados acima da capacidade das mesas entram como adicional. Crianças de 5 a 8 anos entram no valor meia.
                        </div>
                    </div>
                </div>
            </div>
            <div class="formatura-modal-actions">
                <button type="button" class="formatura-btn formatura-btn--light" data-close-modal>Cancelar</button>
                <button type="submit" class="formatura-btn formatura-btn--primary">Salvar configurações</button>
            </div>
        </form>
    </div>
</div>

<div class="formatura-modal" id="modalDocumentoFormando" aria-hidden="true">
    <div class="formatura-modal-dialog">
        <div class="formatura-modal-head">
            <h3>Gerar contrato do formando</h3>
            <button type="button" class="formatura-close" data-close-modal>×</button>
        </div>
        <form method="post" id="formDocumentoFormando">
            <div class="formatura-modal-body">
                <input type="hidden" name="action" value="generate_documento">
                <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                <input type="hidden" name="formando_id" id="documentoFormandoId" value="">
                <div class="formatura-grid">
                    <div class="formatura-field full">
                        <label>Formando</label>
                        <div class="cliente-preview" id="documentoFormandoPreview">Selecione o formando na tabela.</div>
                    </div>
                    <div class="formatura-field full">
                        <label for="documentoModeloId">Modelo de contrato/documento</label>
                        <select id="documentoModeloId" name="modelo_id" required>
                            <option value="">Selecione um modelo</option>
                            <?php foreach ($modelosContrato as $modelo): ?>
                                <option value="<?= (int)$modelo['id'] ?>"><?= eventos_formatura_e((string)$modelo['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($modelosContrato)): ?>
                            <div class="cliente-preview">Nenhum modelo ativo encontrado em Cadastros > Contratos.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="formatura-modal-actions">
                <a class="formatura-btn formatura-btn--light" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">Ver documentos</a>
                <button type="button" class="formatura-btn formatura-btn--light" data-close-modal>Cancelar</button>
                <button type="submit" class="formatura-btn formatura-btn--primary" <?= empty($modelosContrato) ? 'disabled' : '' ?>>Gerar contrato</button>
            </div>
        </form>
    </div>
</div>

<div class="formatura-modal" id="modalFormando" aria-hidden="true">
    <div class="formatura-modal-dialog">
        <div class="formatura-modal-head">
            <h3 id="modalFormandoTitulo">Cadastrar Formando</h3>
            <button type="button" class="formatura-close" data-close-modal>×</button>
        </div>
        <form method="post" id="formFormando">
            <div class="formatura-modal-body">
                <input type="hidden" name="action" value="save_formando">
                <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                <input type="hidden" name="formando_id" id="formandoId" value="">
                <input type="hidden" name="cliente_cadastro_id" id="clienteCadastroId" value="">
                <div class="formatura-grid">
                    <div class="formatura-field full">
                        <label for="clienteBusca">Cliente</label>
                        <input id="clienteBusca" type="text" list="clientesList" placeholder="Digite para buscar cliente" autocomplete="off" required>
                        <datalist id="clientesList">
                            <?php foreach ($clientesJson as $cliente): ?>
                                <option value="<?= eventos_formatura_e((string)$cliente['label']) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="cliente-preview" id="clientePreview">Selecione um cliente cadastrado.</div>
                    </div>
                    <div class="formatura-field">
                        <label for="nomeFormando">Nome do formando</label>
                        <input id="nomeFormando" name="nome_formando" type="text" required>
                    </div>
                    <div class="formatura-field">
                        <label for="convidados">Quantia de convidados</label>
                        <input id="convidados" name="convidados" type="number" min="0" step="1" value="0" required>
                    </div>
                    <div class="formatura-field">
                        <label for="criancasMeia">Crianças 5 a 8 anos</label>
                        <input id="criancasMeia" name="criancas_meia" type="number" min="0" step="1" value="0" required>
                    </div>
                    <div class="formatura-field">
                        <label for="mesas">Quantia de mesas</label>
                        <input id="mesas" name="mesas" type="number" min="0" step="1" value="0" required>
                    </div>
                </div>
            </div>
            <div class="formatura-modal-actions">
                <button type="button" class="formatura-btn formatura-btn--light" data-close-modal>Cancelar</button>
                <button type="submit" class="formatura-btn formatura-btn--primary">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
const clientesFormatura = <?= json_encode($clientesJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function openModal(modal) {
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

function clienteById(id) {
    return clientesFormatura.find((cliente) => Number(cliente.id) === Number(id)) || null;
}

function clienteByLabel(label) {
    const normalized = String(label || '').trim().toLowerCase();
    return clientesFormatura.find((cliente) => String(cliente.label || '').trim().toLowerCase() === normalized) || null;
}

function setCliente(cliente) {
    const hidden = document.getElementById('clienteCadastroId');
    const input = document.getElementById('clienteBusca');
    const preview = document.getElementById('clientePreview');
    if (!cliente) {
        hidden.value = '';
        preview.textContent = 'Selecione um cliente cadastrado.';
        return;
    }
    hidden.value = cliente.id;
    input.value = cliente.label;
    preview.innerHTML = `<strong>${cliente.nome}</strong><br>${cliente.telefone || '-'} ${cliente.email ? ' · ' + cliente.email : ''} ${cliente.documento ? ' · CPF: ' + cliente.documento : ''}`;
}

document.querySelectorAll('[data-close-modal]').forEach((btn) => {
    btn.addEventListener('click', () => closeModal(btn.closest('.formatura-modal')));
});

document.querySelectorAll('.formatura-modal').forEach((modal) => {
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
    });
});

const modalFormando = document.getElementById('modalFormando');
const modalFormaturaConfig = document.getElementById('modalFormaturaConfig');
const modalDocumentoFormando = document.getElementById('modalDocumentoFormando');
const modalEditarCobranca = document.getElementById('modalEditarCobranca');
const formFormando = document.getElementById('formFormando');

document.getElementById('btnFormaturaConfig')?.addEventListener('click', () => {
    openModal(modalFormaturaConfig);
});

document.getElementById('btnNovoFormando')?.addEventListener('click', () => {
    formFormando.reset();
    document.getElementById('formandoId').value = '';
    document.getElementById('clienteCadastroId').value = '';
    document.getElementById('criancasMeia').value = '0';
    document.getElementById('clientePreview').textContent = 'Selecione um cliente cadastrado.';
    document.getElementById('modalFormandoTitulo').textContent = 'Cadastrar Formando';
    openModal(modalFormando);
});

document.getElementById('clienteBusca')?.addEventListener('input', (event) => {
    setCliente(clienteByLabel(event.target.value));
});

document.querySelectorAll('.btnEditarFormando').forEach((btn) => {
    btn.addEventListener('click', () => {
        formFormando.reset();
        document.getElementById('modalFormandoTitulo').textContent = 'Editar Formando';
        document.getElementById('formandoId').value = btn.dataset.id || '';
        document.getElementById('nomeFormando').value = btn.dataset.nomeFormando || '';
        document.getElementById('convidados').value = btn.dataset.convidados || '0';
        document.getElementById('criancasMeia').value = btn.dataset.criancasMeia || '0';
        document.getElementById('mesas').value = btn.dataset.mesas || '0';
        setCliente(clienteById(btn.dataset.clienteId || 0));
        openModal(modalFormando);
    });
});

document.querySelectorAll('.btnDocumentoFormando').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.getElementById('documentoFormandoId').value = btn.dataset.id || '';
        const preview = document.getElementById('documentoFormandoPreview');
        const responsavel = btn.dataset.clienteNome ? `\nResponsável: ${btn.dataset.clienteNome}` : '';
        preview.textContent = `${btn.dataset.nomeFormando || '-'}${responsavel}`;
        openModal(modalDocumentoFormando);
    });
});

document.querySelectorAll('.btnCopiarLinkCobranca').forEach((btn) => {
    btn.addEventListener('click', async () => {
        const link = btn.dataset.link || '';
        if (!link) return;
        const originalText = btn.textContent;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(link);
            } else {
                const input = document.createElement('textarea');
                input.value = link;
                input.setAttribute('readonly', 'readonly');
                input.style.position = 'fixed';
                input.style.left = '-9999px';
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                input.remove();
            }
            btn.textContent = 'Copiado';
            setTimeout(() => { btn.textContent = originalText; }, 1800);
        } catch (error) {
            btn.textContent = 'Erro';
            setTimeout(() => { btn.textContent = originalText; }, 1800);
        }
    });
});

document.querySelectorAll('.btnEditarCobranca').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.getElementById('editarCobrancaId').value = btn.dataset.id || '';
        document.getElementById('editarCobrancaDescricao').value = btn.dataset.descricao || '';
        document.getElementById('editarCobrancaVencimento').value = btn.dataset.vencimento || '';
        document.getElementById('editarCobrancaValor').value = btn.dataset.valor || '';
        const editarStatus = document.getElementById('editarCobrancaStatus');
        if (editarStatus) {
            editarStatus.value = btn.dataset.status || 'pendente';
            editarStatus.disabled = (btn.dataset.carteira || 'manual') !== 'manual';
        }
        openModal(modalEditarCobranca);
    });
});

function formaturaMoneyToNumber(value) {
    let raw = String(value || '').replace(/R\$/g, '').replace(/\s/g, '').trim();
    if (raw.includes(',')) raw = raw.replace(/\./g, '').replace(',', '.');
    const number = Number(raw);
    return Number.isFinite(number) ? number : 0;
}

function formaturaNumberToMoney(value) {
    return Number(value || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formaturaAddDays(dateString, days) {
    const fallback = new Date();
    const date = dateString ? new Date(`${dateString}T00:00:00`) : fallback;
    if (Number.isNaN(date.getTime())) return fallback.toISOString().slice(0, 10);
    date.setDate(date.getDate() + days);
    return date.toISOString().slice(0, 10);
}

const receitaValorTotal = document.getElementById('financeiroValorTotal');
const receitaParcelasInput = document.getElementById('financeiroParcelas');
const receitaPrimeiroVencimento = document.getElementById('financeiroPrimeiroVencimento');
const receitaParcelasBody = document.getElementById('financeiroParcelasBody');
const receitaParcelasField = document.getElementById('financeiroParcelasField');
const receitaTipoValor = document.getElementById('formaturaTipoValor');
const receitaCarteira = document.getElementById('financeiroCarteira');
const receitaModo = document.getElementById('financeiroModo');
const receitaStatus = document.getElementById('financeiroStatus');
let formaturaReceitaTipo = receitaTipoValor?.value || 'avista';

function renderFormaturaParcelas() {
    if (!receitaParcelasBody || !receitaValorTotal || !receitaParcelasInput || !receitaPrimeiroVencimento) return;

    const qtd = formaturaReceitaTipo === 'parcelado'
        ? Math.max(1, Math.min(24, Number(receitaParcelasInput.value || 1)))
        : 1;
    receitaParcelasInput.value = String(qtd);
    const total = formaturaMoneyToNumber(receitaValorTotal.value);
    const cents = Math.round(total * 100);
    const base = qtd > 0 ? Math.floor(cents / qtd) : cents;
    const resto = cents - (base * qtd);

    receitaParcelasBody.innerHTML = '';
    for (let i = 1; i <= qtd; i++) {
        const valorCents = base + (i <= resto ? 1 : 0);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><strong>${i}/${qtd}</strong></td>
            <td><input type="date" name="parcela_vencimento[]" value="${formaturaAddDays(receitaPrimeiroVencimento.value, (i - 1) * 30)}"></td>
            <td><input type="text" name="parcela_valor[]" inputmode="decimal" value="${formaturaNumberToMoney(valorCents / 100)}"></td>
        `;
        receitaParcelasBody.appendChild(tr);
    }
}

function updateFormaturaReceitaMode() {
    const isProvider = receitaCarteira?.value === 'asaas' || receitaCarteira?.value === 'pixgo';
    const isPixGo = receitaCarteira?.value === 'pixgo';
    if (isPixGo) {
        formaturaReceitaTipo = 'avista';
        if (receitaParcelasInput) receitaParcelasInput.value = '1';
    }
    if (receitaTipoValor) receitaTipoValor.value = formaturaReceitaTipo;
    document.querySelectorAll('[data-formatura-tipo]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.formaturaTipo === formaturaReceitaTipo);
    });
    receitaParcelasField?.classList.toggle('formatura-hidden', formaturaReceitaTipo !== 'parcelado');
    document.querySelectorAll('[data-formatura-tipo="parcelado"]').forEach((button) => { button.disabled = isPixGo; });
    if (isProvider && receitaModo) {
        receitaModo.value = 'pix';
    }
    receitaModo?.querySelectorAll('option').forEach((option) => {
        option.disabled = isProvider && option.value !== 'pix';
    });
    if (isProvider && receitaStatus) {
        receitaStatus.value = 'pendente';
        receitaStatus.disabled = true;
    } else if (receitaStatus) {
        receitaStatus.disabled = false;
    }
    renderFormaturaParcelas();
}

document.querySelectorAll('[data-formatura-tipo]').forEach((button) => {
    button.addEventListener('click', () => {
        formaturaReceitaTipo = button.dataset.formaturaTipo || 'avista';
        updateFormaturaReceitaMode();
    });
});

[receitaValorTotal, receitaParcelasInput, receitaPrimeiroVencimento].forEach((input) => {
    input?.addEventListener('input', renderFormaturaParcelas);
});
receitaCarteira?.addEventListener('change', updateFormaturaReceitaMode);

receitaValorTotal?.addEventListener('blur', () => {
    const value = formaturaMoneyToNumber(receitaValorTotal.value);
    receitaValorTotal.value = value > 0 ? `R$ ${formaturaNumberToMoney(value)}` : '';
    renderFormaturaParcelas();
});

document.getElementById('formaturaReceitaForm')?.addEventListener('submit', () => {
    if (!receitaParcelasBody?.children.length) renderFormaturaParcelas();
});

updateFormaturaReceitaMode();

document.getElementById('formaturaBusca')?.addEventListener('input', (event) => {
    const term = String(event.target.value || '').trim().toLowerCase();
    document.querySelectorAll('#formaturaTabela tbody tr').forEach((row) => {
        row.style.display = !term || String(row.dataset.search || '').includes(term) ? '' : 'none';
    });
});
</script>

<?php endSidebar(); ?>
