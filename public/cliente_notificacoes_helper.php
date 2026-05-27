<?php
/**
 * cliente_notificacoes_helper.php
 * Modelos e disparos de notificações para clientes.
 */

require_once __DIR__ . '/conexao.php';

function cliente_notificacoes_require_eventos_helpers(): void
{
    require_once __DIR__ . '/eventos_reuniao_helper.php';
}

function cliente_notificacoes_require_email_helper(): void
{
    require_once __DIR__ . '/core/email_global_helper.php';
}

function cliente_notificacoes_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cliente_notificacao_modelos (
            id BIGSERIAL PRIMARY KEY,
            chave VARCHAR(120) NOT NULL UNIQUE,
            nome VARCHAR(180) NOT NULL,
            descricao TEXT NULL,
            gatilho VARCHAR(180) NULL,
            ativo BOOLEAN NOT NULL DEFAULT TRUE,
            envio_automatico BOOLEAN NOT NULL DEFAULT FALSE,
            canal_email BOOLEAN NOT NULL DEFAULT TRUE,
            assunto VARCHAR(220) NOT NULL,
            mensagem_texto TEXT NOT NULL,
            botao_texto VARCHAR(80) NOT NULL DEFAULT 'Acessar painel',
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cliente_notificacao_envios (
            id BIGSERIAL PRIMARY KEY,
            modelo_id BIGINT NULL REFERENCES cliente_notificacao_modelos(id) ON DELETE SET NULL,
            chave_modelo VARCHAR(120) NOT NULL,
            pre_contrato_id BIGINT NULL,
            me_event_id BIGINT NULL,
            meeting_id BIGINT NULL,
            portal_id BIGINT NULL,
            cliente_nome VARCHAR(255) NULL,
            cliente_email VARCHAR(255) NULL,
            canal VARCHAR(40) NOT NULL DEFAULT 'email',
            assunto VARCHAR(220) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'pendente',
            erro TEXT NULL,
            enviado_em TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_cliente_notificacao_envios_pre
        ON cliente_notificacao_envios(pre_contrato_id, chave_modelo, created_at DESC)
    ");

    cliente_notificacoes_seed_modelos($pdo);
}

function cliente_notificacoes_schema_pronto(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SELECT to_regclass('cliente_notificacao_modelos') AS modelos, to_regclass('cliente_notificacao_envios') AS envios");
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        return !empty($row['modelos']) && !empty($row['envios']);
    } catch (Throwable $e) {
        return false;
    }
}

function cliente_notificacoes_seed_modelos(PDO $pdo): void
{
    $mensagem = "Olá {{nome_cliente}}, tudo bem?\n\n"
        . "Estou te enviando o link do seu painel do evento. É por lá que vamos centralizar todas as informações importantes, como organização geral, detalhes de decoração, formulários (DJ, lista de convidados, totém, etc.) e outros pontos essenciais.\n\n"
        . "Acesse aqui: {{link_painel}}\n\n"
        . "Peço, por favor, que você acesse com calma e preencha todos os formulários solicitados dentro do prazo de 15 dias antes do evento, até {{prazo_formularios}}. Essas informações são super importantes para que tudo saia exatamente como você imaginou no grande dia.\n\n"
        . "Qualquer dúvida durante o preenchimento, pode falar comigo, estou por aqui para te ajudar!";

    $stmt = $pdo->prepare("
        INSERT INTO cliente_notificacao_modelos
            (chave, nome, descricao, gatilho, ativo, envio_automatico, canal_email, assunto, mensagem_texto, botao_texto)
        VALUES
            (:chave, :nome, :descricao, :gatilho, TRUE, TRUE, TRUE, :assunto, :mensagem_texto, :botao_texto)
        ON CONFLICT (chave) DO NOTHING
    ");
    $stmt->execute([
        ':chave' => 'contrato_aprovado',
        ':nome' => 'Contrato aprovado',
        ':descricao' => 'Envia o link da área do cliente quando o contrato é aprovado e criado na ME.',
        ':gatilho' => 'Quando o pré-contrato muda para Aprovado / Criado na ME',
        ':assunto' => 'Seu painel do evento está disponível',
        ':mensagem_texto' => $mensagem,
        ':botao_texto' => 'Acessar painel do evento',
    ]);

    $mensagemCampanha = "Olá {{nome_cliente}}, tudo bem?\n\n"
        . "Temos uma novidade para deixar a organização do seu evento ainda mais prática: agora você conta com o Portal do Cliente Smile.\n\n"
        . "Por lá, vamos centralizar as principais informações do seu evento em um só lugar. Você poderá preencher formulários importantes, enviar informações para o DJ, acompanhar detalhes de decoração, revisar opções disponíveis de cardápio, consultar lista de convidados e acessar outros pontos essenciais da organização.\n\n"
        . "Acesse seu portal pelo botão abaixo:\n\n"
        . "{{link_painel}}\n\n"
        . "Caso você já tenha preenchido ou enviado essas informações para nossa equipe, pode desconsiderar este aviso. Se ainda houver algo pendente, pedimos que acesse com calma e complete os formulários disponíveis dentro dos prazos solicitados.\n\n"
        . "Qualquer dúvida durante o acesso ou preenchimento, fale com a nossa equipe. Estamos por aqui para te ajudar!";

    $stmtCampanha = $pdo->prepare("
        INSERT INTO cliente_notificacao_modelos
            (chave, nome, descricao, gatilho, ativo, envio_automatico, canal_email, assunto, mensagem_texto, botao_texto)
        VALUES
            (:chave, :nome, :descricao, :gatilho, TRUE, FALSE, TRUE, :assunto, :mensagem_texto, :botao_texto)
        ON CONFLICT (chave) DO NOTHING
    ");
    $stmtCampanha->execute([
        ':chave' => 'portal_cliente_lancamento',
        ':nome' => 'Lançamento do Portal do Cliente',
        ':descricao' => 'Campanha em massa para apresentar o Portal do Cliente a eventos futuros de casamento e 15 anos.',
        ':gatilho' => 'Disparo em massa para eventos a partir de 30/05/2026',
        ':assunto' => 'Novidade: seu Portal do Cliente Smile está disponível',
        ':mensagem_texto' => $mensagemCampanha,
        ':botao_texto' => 'Acessar meu portal',
    ]);
}

function cliente_notificacoes_codigos(): array
{
    return [
        '{{nome_cliente}}' => 'Nome do cliente',
        '{{nome_evento}}' => 'Nome ou tipo do evento',
        '{{data_evento}}' => 'Data do evento',
        '{{link_painel}}' => 'Link da área do cliente',
        '{{prazo_formularios}}' => 'Data limite para preencher formulários',
    ];
}

function cliente_notificacoes_get_modelo(PDO $pdo, string $chave): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM cliente_notificacao_modelos WHERE chave = :chave LIMIT 1");
    $stmt->execute([':chave' => $chave]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cliente_notificacoes_substituir(string $texto, array $variaveis): string
{
    return strtr($texto, $variaveis);
}

function cliente_notificacoes_data_br(?string $data): string
{
    $data = trim((string)$data);
    if ($data === '') {
        return '';
    }
    $ts = strtotime($data);
    return $ts ? date('d/m/Y', $ts) : $data;
}

function cliente_notificacoes_prazo_formularios(?string $dataEvento): string
{
    $dataEvento = trim((string)$dataEvento);
    if ($dataEvento === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($dataEvento))->modify('-15 days')->format('d/m/Y');
    } catch (Throwable $e) {
        return '';
    }
}

function cliente_notificacoes_render_email(array $modelo, array $variaveis): string
{
    if (($modelo['chave'] ?? '') === 'portal_cliente_lancamento') {
        return cliente_notificacoes_render_email_lancamento($modelo, $variaveis);
    }

    return cliente_notificacoes_render_email_padrao($modelo, $variaveis);
}

function cliente_notificacoes_render_email_padrao(array $modelo, array $variaveis): string
{
    $mensagem = cliente_notificacoes_substituir((string)($modelo['mensagem_texto'] ?? ''), $variaveis);
    $linhas = array_filter(array_map('trim', preg_split('/\R{2,}/', $mensagem) ?: []), static fn($p) => $p !== '');
    $body = '';
    foreach ($linhas as $linha) {
        if ($linha === ($variaveis['{{link_painel}}'] ?? '')) {
            continue;
        }
        $body .= '<p style="margin:0 0 16px;color:#334155;font-size:16px;line-height:1.65;">'
            . nl2br(htmlspecialchars($linha, ENT_QUOTES, 'UTF-8'))
            . '</p>';
    }

    $nomeCliente = htmlspecialchars((string)($variaveis['{{nome_cliente}}'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8');
    $nomeEvento = htmlspecialchars((string)($variaveis['{{nome_evento}}'] ?? 'Evento'), ENT_QUOTES, 'UTF-8');
    $dataEvento = htmlspecialchars((string)($variaveis['{{data_evento}}'] ?? ''), ENT_QUOTES, 'UTF-8');
    $linkPainel = htmlspecialchars((string)($variaveis['{{link_painel}}'] ?? '#'), ENT_QUOTES, 'UTF-8');
    $botaoTexto = htmlspecialchars((string)($modelo['botao_texto'] ?? 'Acessar painel'), ENT_QUOTES, 'UTF-8');

    return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6fb;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #dbe3ef;">'
        . '<tr><td style="background:#1e3a8a;padding:30px 30px 28px;color:#ffffff;text-align:center;">'
        . '<div style="font-size:18px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;">Grupo Smile Eventos</div>'
        . '<h1 style="margin:14px 0 0;font-size:27px;line-height:1.22;">Seu painel do evento está disponível</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 30px;">'
        . '<p style="margin:0 0 18px;color:#0f172a;font-size:18px;line-height:1.5;">Olá, <strong>' . $nomeCliente . '</strong>.</p>'
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:0 0 22px;">'
        . '<div style="font-size:13px;color:#64748b;margin-bottom:4px;">Evento</div>'
        . '<div style="font-size:16px;color:#0f172a;font-weight:700;">' . $nomeEvento . ($dataEvento !== '' ? ' - ' . $dataEvento : '') . '</div>'
        . '</div>'
        . $body
        . '<div style="text-align:center;margin:28px 0 24px;">'
        . '<a href="' . $linkPainel . '" style="display:inline-block;background:#1e3a8a;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;padding:14px 22px;border-radius:8px;">' . $botaoTexto . '</a>'
        . '</div>'
        . '<p style="margin:0;color:#64748b;font-size:13px;line-height:1.5;">Se o botão não abrir, copie este link no navegador:<br>'
        . '<a href="' . $linkPainel . '" style="color:#1e3a8a;word-break:break-all;">' . $linkPainel . '</a></p>'
        . '</td></tr>'
        . '<tr><td style="background:#f8fafc;padding:18px 30px;color:#64748b;font-size:12px;text-align:center;">Este é um e-mail automático do Painel Smile Pro.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function cliente_notificacoes_render_email_lancamento(array $modelo, array $variaveis): string
{
    $mensagem = cliente_notificacoes_substituir((string)($modelo['mensagem_texto'] ?? ''), $variaveis);
    $paragrafos = array_filter(array_map('trim', preg_split('/\R{2,}/', $mensagem) ?: []), static fn($p) => $p !== '');
    $body = '';
    foreach ($paragrafos as $paragrafo) {
        if ($paragrafo === ($variaveis['{{link_painel}}'] ?? '')) {
            continue;
        }
        $body .= '<p style="margin:0 0 16px;color:#27364f;font-size:16px;line-height:1.65;">'
            . nl2br(htmlspecialchars($paragrafo, ENT_QUOTES, 'UTF-8'))
            . '</p>';
    }

    $nomeCliente = htmlspecialchars((string)($variaveis['{{nome_cliente}}'] ?? 'Cliente'), ENT_QUOTES, 'UTF-8');
    $nomeEvento = htmlspecialchars((string)($variaveis['{{nome_evento}}'] ?? 'Evento'), ENT_QUOTES, 'UTF-8');
    $dataEvento = htmlspecialchars((string)($variaveis['{{data_evento}}'] ?? ''), ENT_QUOTES, 'UTF-8');
    $linkPainel = htmlspecialchars((string)($variaveis['{{link_painel}}'] ?? '#'), ENT_QUOTES, 'UTF-8');
    $botaoTexto = htmlspecialchars((string)($modelo['botao_texto'] ?? 'Acessar meu portal'), ENT_QUOTES, 'UTF-8');

    $featureStyle = 'width:50%;padding:8px;vertical-align:top;';
    $featureBoxStyle = 'background:#f8fafc;border:1px solid #dbe3ef;border-radius:10px;padding:14px 12px;min-height:78px;';
    $featureTitleStyle = 'margin:0 0 5px;color:#1e3a8a;font-size:14px;font-weight:800;';
    $featureTextStyle = 'margin:0;color:#52637a;font-size:13px;line-height:1.4;';

    return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;background:#eef3fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef3fb;padding:28px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #dbe3ef;box-shadow:0 10px 30px rgba(30,58,138,.08);">'
        . '<tr><td style="background:#1e3a8a;padding:30px 30px 32px;color:#ffffff;text-align:center;">'
        . '<div style="font-size:18px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;">Grupo Smile Eventos</div>'
        . '<div style="display:inline-block;margin-top:18px;background:#dbeafe;color:#1e3a8a;border-radius:999px;padding:7px 13px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;">Novidade</div>'
        . '<h1 style="margin:16px 0 0;font-size:30px;line-height:1.18;">Seu Portal do Cliente chegou</h1>'
        . '<p style="margin:12px auto 0;max-width:500px;color:#dbeafe;font-size:16px;line-height:1.5;">Organização, formulários e informações importantes do seu evento em um só lugar.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 30px;">'
        . '<p style="margin:0 0 18px;color:#0f172a;font-size:18px;line-height:1.5;">Olá, <strong>' . $nomeCliente . '</strong>.</p>'
        . '<div style="background:#f8fafc;border:1px solid #dbe3ef;border-radius:12px;padding:15px 16px;margin:0 0 22px;">'
        . '<div style="font-size:13px;color:#64748b;margin-bottom:4px;">Evento</div>'
        . '<div style="font-size:16px;color:#0f172a;font-weight:800;">' . $nomeEvento . ($dataEvento !== '' ? ' - ' . $dataEvento : '') . '</div>'
        . '</div>'
        . $body
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:10px 0 20px;"><tr>'
        . '<td style="' . $featureStyle . '"><div style="' . $featureBoxStyle . '"><p style="' . $featureTitleStyle . '">Formulários do DJ</p><p style="' . $featureTextStyle . '">Envie músicas, protocolos e dados importantes.</p></div></td>'
        . '<td style="' . $featureStyle . '"><div style="' . $featureBoxStyle . '"><p style="' . $featureTitleStyle . '">Cardápio</p><p style="' . $featureTextStyle . '">Consulte e revise escolhas disponíveis.</p></div></td>'
        . '</tr><tr>'
        . '<td style="' . $featureStyle . '"><div style="' . $featureBoxStyle . '"><p style="' . $featureTitleStyle . '">Lista de convidados</p><p style="' . $featureTextStyle . '">Centralize informações para a organização.</p></div></td>'
        . '<td style="' . $featureStyle . '"><div style="' . $featureBoxStyle . '"><p style="' . $featureTitleStyle . '">Decoração e detalhes</p><p style="' . $featureTextStyle . '">Acompanhe orientações e solicitações da equipe.</p></div></td>'
        . '</tr></table>'
        . '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:13px 14px;margin:0 0 24px;color:#9a3412;font-size:14px;line-height:1.5;">'
        . '<strong>Aviso:</strong> caso você já tenha preenchido ou enviado essas informações, pode desconsiderar este e-mail.'
        . '</div>'
        . '<div style="text-align:center;margin:26px 0 24px;">'
        . '<a href="' . $linkPainel . '" style="display:inline-block;background:#1e3a8a;color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:15px 24px;border-radius:8px;">' . $botaoTexto . '</a>'
        . '</div>'
        . '<p style="margin:0;color:#64748b;font-size:13px;line-height:1.5;">Se o botão não abrir, copie este link no navegador:<br>'
        . '<a href="' . $linkPainel . '" style="color:#1e3a8a;word-break:break-all;">' . $linkPainel . '</a></p>'
        . '</td></tr>'
        . '<tr><td style="background:#f8fafc;padding:18px 30px;color:#64748b;font-size:12px;text-align:center;">Este é um e-mail automático do Painel Smile Pro.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function cliente_notificacoes_nome_evento(array $preContrato): string
{
    $tipoEvento = trim((string)($preContrato['tipo_evento_real'] ?? ''));
    if ($tipoEvento === '') {
        $tipoEvento = trim((string)($preContrato['tipo_evento'] ?? ''));
    }
    $map = [
        'casamento' => 'Casamento',
        '15anos' => '15 anos',
        '15_anos' => '15 anos',
        'infantil' => 'Infantil',
        'pj' => 'Corporativo',
    ];
    $key = strtolower(str_replace([' ', '-'], '_', $tipoEvento));
    return $map[$key] ?? ($tipoEvento !== '' ? ucfirst(str_replace('_', ' ', $tipoEvento)) : 'Evento');
}

function cliente_notificacoes_variaveis_pre_contrato(array $preContrato, array $portal): array
{
    return [
        '{{nome_cliente}}' => trim((string)($preContrato['nome_completo'] ?? 'Cliente')),
        '{{nome_evento}}' => cliente_notificacoes_nome_evento($preContrato),
        '{{data_evento}}' => cliente_notificacoes_data_br((string)($preContrato['data_evento'] ?? '')),
        '{{link_painel}}' => (string)($portal['url'] ?? ''),
        '{{prazo_formularios}}' => cliente_notificacoes_prazo_formularios((string)($preContrato['data_evento'] ?? '')),
    ];
}

function cliente_notificacoes_me_evento_id(array $event): int
{
    return eventos_me_pick_int($event, ['id', 'idevento', 'idEvento', 'event.id']);
}

function cliente_notificacoes_me_evento_data(array $event): string
{
    $raw = eventos_me_pick_text($event, [
        'dataevento',
        'dataEvento',
        'data_evento',
        'data',
        'date',
        'start',
        'start_date',
        'inicio',
    ]);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : substr($raw, 0, 10);
}

function cliente_notificacoes_me_evento_nome_cliente(array $event): string
{
    return eventos_me_pick_text($event, [
        'nomecliente',
        'nomeCliente',
        'cliente.nome',
        'client.name',
        'cliente',
        'nome_contato',
    ], 'Cliente');
}

function cliente_notificacoes_me_evento_email_cliente(array $event): string
{
    return eventos_me_pick_text($event, [
        'emailcliente',
        'emailCliente',
        'cliente.email',
        'client.email',
        'email',
        'email_contato',
        'contato.email',
    ]);
}

function cliente_notificacoes_me_normalizar_payload_cliente($payload): array
{
    if (!is_array($payload)) {
        return [];
    }

    $isList = static function (array $value): bool {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    };

    $current = $payload;
    for ($i = 0; $i < 3; $i++) {
        if (!isset($current['data']) || !is_array($current['data'])) {
            break;
        }
        $data = $current['data'];
        if ($data === []) {
            return [];
        }
        $current = $isList($data) ? ($data[0] ?? []) : $data;
        if (!is_array($current)) {
            return [];
        }
    }

    if (isset($current['client']) && is_array($current['client'])) {
        $current = $current['client'];
    }

    if ($isList($current)) {
        $first = $current[0] ?? [];
        return is_array($first) ? $first : [];
    }

    return $current;
}

function cliente_notificacoes_extrair_email_payload($payload): string
{
    if (!is_array($payload)) {
        return '';
    }

    $camposEmail = [
        'email',
        'emailcliente',
        'emailCliente',
        'cliente_email',
        'clienteEmail',
        'contato_email',
        'contatoEmail',
        'email_contato',
        'emailContato',
        'e_mail',
        'e-mail',
        'mail',
        'correio',
        'correio_eletronico',
    ];

    $fila = [$payload];
    $normalizado = cliente_notificacoes_me_normalizar_payload_cliente($payload);
    if (!empty($normalizado)) {
        $fila[] = $normalizado;
    }

    while (!empty($fila)) {
        $atual = array_shift($fila);
        if (!is_array($atual)) {
            continue;
        }

        foreach ($camposEmail as $campo) {
            if (!isset($atual[$campo]) || !is_string($atual[$campo])) {
                continue;
            }
            $email = trim($atual[$campo]);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        foreach ($atual as $valor) {
            if (is_string($valor)) {
                $email = trim($valor);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $email;
                }
            } elseif (is_array($valor)) {
                $fila[] = $valor;
            }
        }
    }

    return '';
}

function cliente_notificacoes_me_evento_cliente_id(array $event): int
{
    return eventos_me_pick_int($event, [
        'idcliente',
        'idCliente',
        'cliente_id',
        'id_cliente',
        'clienteId',
        'client_id',
        'cliente.id',
        'client.id',
    ]);
}

function cliente_notificacoes_me_cliente_email_por_id(PDO $pdo, int $clientId): string
{
    cliente_notificacoes_require_eventos_helpers();

    if ($clientId <= 0) {
        return '';
    }

    $cacheKey = 'cliente_notif_me_client_' . $clientId;
    $client = eventos_me_cache_get($pdo, $cacheKey);

    if ($client === null) {
        $resp = eventos_me_request('GET', '/api/v1/clients/' . $clientId);
        if (empty($resp['ok'])) {
            return '';
        }
        $client = $resp['data'] ?? [];
        eventos_me_cache_set($pdo, $cacheKey, $client, 10);
    }

    return cliente_notificacoes_extrair_email_payload(is_array($client) ? $client : []);
}

function cliente_notificacoes_me_evento_email_cliente_completo(PDO $pdo, array $event, string $emailAtual = ''): string
{
    cliente_notificacoes_require_eventos_helpers();

    $emailAtual = trim($emailAtual);
    if ($emailAtual !== '' && filter_var($emailAtual, FILTER_VALIDATE_EMAIL)) {
        return $emailAtual;
    }

    $clientId = cliente_notificacoes_me_evento_cliente_id($event);
    $emailCliente = cliente_notificacoes_me_cliente_email_por_id($pdo, $clientId);
    if ($emailCliente !== '') {
        return $emailCliente;
    }

    $meEventId = cliente_notificacoes_me_evento_id($event);
    if ($meEventId <= 0) {
        return $emailAtual;
    }

    try {
        $detalhe = eventos_me_buscar_por_id($pdo, $meEventId);
        $eventoDetalhado = $detalhe['event'] ?? null;
        if (empty($detalhe['ok']) || !is_array($eventoDetalhado)) {
            return $emailAtual;
        }

        foreach ([
            cliente_notificacoes_me_evento_email_cliente($eventoDetalhado),
            cliente_notificacoes_extrair_email_payload($eventoDetalhado),
        ] as $emailDetalhado) {
            $emailDetalhado = trim((string)$emailDetalhado);
            if ($emailDetalhado !== '' && filter_var($emailDetalhado, FILTER_VALIDATE_EMAIL)) {
                return $emailDetalhado;
            }
        }

        $clientIdDetalhado = cliente_notificacoes_me_evento_cliente_id($eventoDetalhado);
        $emailCliente = cliente_notificacoes_me_cliente_email_por_id($pdo, $clientIdDetalhado);
        if ($emailCliente !== '') {
            return $emailCliente;
        }
    } catch (Throwable $e) {
        error_log('[CLIENTE_NOTIFICACOES] detalhe ME e-mail: ' . $e->getMessage());
    }

    return $emailAtual;
}

function cliente_notificacoes_texto_normalizado(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        if (is_string($ascii) && $ascii !== '') {
            $texto = $ascii;
        }
    }
    $texto = strtolower($texto);
    $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto) ?? '';
    return trim($texto);
}

function cliente_notificacoes_classificar_tipo_evento_me(array $event, array $local = []): string
{
    cliente_notificacoes_require_eventos_helpers();

    foreach ([
        (string)($local['tipo_evento_real'] ?? ''),
        (string)($local['tipo_evento'] ?? ''),
        eventos_me_pick_text($event, ['tipoevento', 'tipoEvento', 'tipo_evento', 'tipo', 'categoria', 'nomeTipoEvento']),
    ] as $candidate) {
        $normalizado = eventos_reuniao_normalizar_tipo_evento_real($candidate);
        if (in_array($normalizado, ['casamento', '15anos'], true)) {
            return $normalizado;
        }
    }

    $texto = cliente_notificacoes_texto_normalizado(implode(' ', [
        eventos_me_pick_text($event, ['nomeevento', 'nome', 'titulo']),
        eventos_me_pick_text($event, ['tipoevento', 'tipoEvento', 'tipo_evento', 'tipo', 'categoria', 'nomeTipoEvento']),
        eventos_me_pick_text($event, ['observacao', 'observacoes', 'descricao']),
    ]));

    if ($texto === '') {
        return '';
    }
    if (preg_match('/(^| )15( |$)|15 anos|debutante/', $texto)) {
        return '15anos';
    }
    if (strpos($texto, 'casamento') !== false || strpos($texto, 'wedding') !== false || strpos($texto, 'noivos') !== false) {
        return 'casamento';
    }

    return '';
}

function cliente_notificacoes_me_buscar_eventos_periodo(PDO $pdo, string $start = '2026-05-30', string $end = '2031-12-31', bool $forceRefresh = false): array
{
    cliente_notificacoes_require_eventos_helpers();

    $cacheKey = 'cliente_notif_me_events_' . $start . '_' . $end;
    if (!$forceRefresh) {
        $cached = eventos_me_cache_get($pdo, $cacheKey);
        if ($cached !== null) {
            return ['ok' => true, 'events' => eventos_me_filtrar_ativos($cached), 'from_cache' => true];
        }
    }

    $resp = eventos_me_request('GET', '/api/v1/events', [
        'start' => $start,
        'end' => $end,
        'limit' => 1000,
    ]);
    if (empty($resp['ok'])) {
        return ['ok' => false, 'error' => (string)($resp['error'] ?? 'Erro ao consultar eventos na ME.'), 'events' => []];
    }

    $events = $resp['data']['data'] ?? $resp['data'] ?? [];
    if (!is_array($events)) {
        $events = [];
    }
    $events = eventos_me_filtrar_ativos($events);
    eventos_me_cache_set($pdo, $cacheKey, $events, 10);

    return ['ok' => true, 'events' => $events, 'from_cache' => false];
}

function cliente_notificacoes_locais_por_me_evento(PDO $pdo, array $eventIds): array
{
    cliente_notificacoes_require_eventos_helpers();

    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn($id) => $id > 0)));
    if (empty($eventIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $local = [];

    try {
        $stmt = $pdo->prepare("
            SELECT
                v.id AS pre_contrato_id,
                v.me_event_id,
                v.nome_completo,
                v.email,
                v.tipo_evento,
                v.tipo_evento_real,
                v.data_evento,
                er.id AS meeting_id,
                er.tipo_evento_real AS reuniao_tipo_evento_real,
                p.id AS portal_id,
                p.token AS portal_token,
                p.is_active AS portal_ativo
            FROM vendas_pre_contratos v
            LEFT JOIN eventos_reunioes er ON er.me_event_id = v.me_event_id
            LEFT JOIN eventos_cliente_portais p ON p.meeting_id = er.id
            WHERE v.me_event_id IN ($placeholders)
            ORDER BY v.aprovado_em DESC NULLS LAST, v.atualizado_em DESC NULLS LAST, v.id DESC
        ");
        $stmt->execute($eventIds);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = (int)($row['me_event_id'] ?? 0);
            if ($id > 0 && !isset($local[$id])) {
                $tipoReal = eventos_reuniao_normalizar_tipo_evento_real((string)($row['tipo_evento_real'] ?? ''));
                if ($tipoReal === '') {
                    $tipoReal = eventos_reuniao_normalizar_tipo_evento_real((string)($row['reuniao_tipo_evento_real'] ?? ''));
                }
                $row['tipo_evento_real'] = $tipoReal;
                $row['portal_url'] = trim((string)($row['portal_token'] ?? '')) !== ''
                    ? eventos_cliente_portal_build_url((string)$row['portal_token'])
                    : '';
                $local[$id] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('[CLIENTE_NOTIFICACOES] locais pre_contratos: ' . $e->getMessage());
    }

    try {
        $missing = array_values(array_diff($eventIds, array_keys($local)));
        if (!empty($missing)) {
            $placeholdersMissing = implode(',', array_fill(0, count($missing), '?'));
            $stmt = $pdo->prepare("
                SELECT
                    er.id AS meeting_id,
                    er.me_event_id,
                    er.tipo_evento_real,
                    er.me_event_snapshot,
                    p.id AS portal_id,
                    p.token AS portal_token,
                    p.is_active AS portal_ativo
                FROM eventos_reunioes er
                LEFT JOIN eventos_cliente_portais p ON p.meeting_id = er.id
                WHERE er.me_event_id IN ($placeholdersMissing)
            ");
            $stmt->execute($missing);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $id = (int)($row['me_event_id'] ?? 0);
                if ($id <= 0 || isset($local[$id])) {
                    continue;
                }
                $snapshot = json_decode((string)($row['me_event_snapshot'] ?? '{}'), true);
                $snapshot = is_array($snapshot) ? $snapshot : [];
                $tipoReal = eventos_reuniao_normalizar_tipo_evento_real((string)($row['tipo_evento_real'] ?? ''));
                if ($tipoReal === '') {
                    $tipoReal = eventos_reuniao_normalizar_tipo_evento_real((string)($snapshot['tipo_evento_real'] ?? ''));
                }
                $local[$id] = [
                    'pre_contrato_id' => null,
                    'me_event_id' => $id,
                    'nome_completo' => eventos_me_snapshot_cliente_nome($snapshot, ''),
                    'email' => eventos_me_snapshot_cliente_email($snapshot, ''),
                    'tipo_evento' => (string)($snapshot['tipo_evento'] ?? ''),
                    'tipo_evento_real' => $tipoReal,
                    'data_evento' => (string)($snapshot['data'] ?? ''),
                    'meeting_id' => (int)($row['meeting_id'] ?? 0),
                    'portal_id' => (int)($row['portal_id'] ?? 0),
                    'portal_token' => (string)($row['portal_token'] ?? ''),
                    'portal_ativo' => !empty($row['portal_ativo']),
                    'portal_url' => trim((string)($row['portal_token'] ?? '')) !== ''
                        ? eventos_cliente_portal_build_url((string)$row['portal_token'])
                        : '',
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('[CLIENTE_NOTIFICACOES] locais reunioes: ' . $e->getMessage());
    }

    return $local;
}

function cliente_notificacoes_envios_enviados_por_me(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT me_event_id
            FROM cliente_notificacao_envios
            WHERE chave_modelo = 'portal_cliente_lancamento'
              AND status = 'enviado'
              AND me_event_id IS NOT NULL
        ");
        $sent = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['me_event_id'] ?? 0);
            if ($id > 0) {
                $sent[$id] = true;
            }
        }
        return $sent;
    } catch (Throwable $e) {
        return [];
    }
}

function cliente_notificacoes_buscar_publico_portal_lancamento(PDO $pdo, int $limit = 500): array
{
    cliente_notificacoes_require_eventos_helpers();

    cliente_notificacoes_ensure_schema($pdo);
    $limit = max(1, min(1000, $limit));

    $result = cliente_notificacoes_me_buscar_eventos_periodo($pdo, '2026-05-31', '2031-12-31');
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? 'Erro ao consultar eventos na ME.'));
    }

    $events = array_values(array_filter((array)($result['events'] ?? []), 'is_array'));
    $eventIds = [];
    foreach ($events as $event) {
        $id = cliente_notificacoes_me_evento_id($event);
        if ($id > 0) {
            $eventIds[] = $id;
        }
    }

    $locais = cliente_notificacoes_locais_por_me_evento($pdo, $eventIds);
    $enviados = cliente_notificacoes_envios_enviados_por_me($pdo);
    $rows = [];

    foreach ($events as $event) {
        $meEventId = cliente_notificacoes_me_evento_id($event);
        if ($meEventId <= 0 || isset($enviados[$meEventId])) {
            continue;
        }

        $dataEvento = cliente_notificacoes_me_evento_data($event);
        if ($dataEvento === '' || $dataEvento <= '2026-05-30') {
            continue;
        }

        $local = $locais[$meEventId] ?? [];
        $tipo = cliente_notificacoes_classificar_tipo_evento_me($event, $local);
        if (!in_array($tipo, ['casamento', '15anos'], true)) {
            continue;
        }

        $email = trim((string)($local['email'] ?? ''));
        if ($email === '') {
            $email = cliente_notificacoes_me_evento_email_cliente($event);
        }
        $email = cliente_notificacoes_me_evento_email_cliente_completo($pdo, $event, $email);

        $nomeCliente = trim((string)($local['nome_completo'] ?? ''));
        if ($nomeCliente === '') {
            $nomeCliente = cliente_notificacoes_me_evento_nome_cliente($event);
        }

        $rows[] = [
            'id' => (int)($local['pre_contrato_id'] ?? 0),
            'origem' => 'me_api',
            'nome_completo' => $nomeCliente !== '' ? $nomeCliente : 'Cliente',
            'email' => $email,
            'tipo_evento' => $tipo,
            'tipo_evento_real' => $tipo,
            'data_evento' => $dataEvento,
            'me_event_id' => $meEventId,
            'status' => (string)($local['status'] ?? 'me_api'),
            'meeting_id' => (int)($local['meeting_id'] ?? 0),
            'portal_id' => (int)($local['portal_id'] ?? 0),
            'portal_token' => (string)($local['portal_token'] ?? ''),
            'portal_ativo' => !empty($local['portal_ativo']),
            'portal_url' => (string)($local['portal_url'] ?? ''),
            'email_valido' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
            'nome_evento_me' => eventos_me_pick_text($event, ['nomeevento', 'nome', 'titulo']),
            'tipo_origem' => $tipo,
        ];

        if (count($rows) >= $limit) {
            break;
        }
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)($a['data_evento'] ?? ''), (string)($b['data_evento'] ?? ''))
            ?: ((int)($a['me_event_id'] ?? 0) <=> (int)($b['me_event_id'] ?? 0));
    });

    return $rows;
}

function cliente_notificacoes_preparar_portal_pre_contrato(PDO $pdo, array $preContrato, int $usuarioId = 0): array
{
    cliente_notificacoes_require_eventos_helpers();

    $meEventId = (int)($preContrato['me_event_id'] ?? 0);
    if ($meEventId <= 0) {
        return ['ok' => false, 'error' => 'Evento sem vínculo ME.'];
    }

    $tipoEventoReal = (string)($preContrato['tipo_evento_real'] ?? $preContrato['tipo_evento'] ?? '');
    $reuniaoResult = eventos_reuniao_get_or_create($pdo, $meEventId, $usuarioId, $tipoEventoReal);
    if (empty($reuniaoResult['ok']) || empty($reuniaoResult['reuniao']['id'])) {
        return ['ok' => false, 'error' => (string)($reuniaoResult['error'] ?? 'Não foi possível criar a reunião.')];
    }

    $meetingId = (int)$reuniaoResult['reuniao']['id'];
    $portalResult = eventos_cliente_portal_get_or_create($pdo, $meetingId, $usuarioId);
    if (empty($portalResult['ok']) || empty($portalResult['portal']['url'])) {
        return ['ok' => false, 'error' => (string)($portalResult['error'] ?? 'Não foi possível criar o portal do cliente.')];
    }

    return [
        'ok' => true,
        'meeting_id' => $meetingId,
        'portal' => $portalResult['portal'],
    ];
}

function cliente_notificacoes_registrar_envio(PDO $pdo, array $modelo, array $preContrato, int $meetingId, array $portal, string $assunto): int
{
    $stmtLog = $pdo->prepare("
        INSERT INTO cliente_notificacao_envios
            (modelo_id, chave_modelo, pre_contrato_id, me_event_id, meeting_id, portal_id, cliente_nome, cliente_email, canal, assunto, status)
        VALUES
            (:modelo_id, :chave_modelo, :pre_contrato_id, :me_event_id, :meeting_id, :portal_id, :cliente_nome, :cliente_email, 'email', :assunto, 'pendente')
        RETURNING id
    ");
    $stmtLog->execute([
        ':modelo_id' => (int)($modelo['id'] ?? 0),
        ':chave_modelo' => (string)($modelo['chave'] ?? ''),
        ':pre_contrato_id' => (int)($preContrato['id'] ?? 0) ?: null,
        ':me_event_id' => (int)($preContrato['me_event_id'] ?? 0) ?: null,
        ':meeting_id' => $meetingId > 0 ? $meetingId : null,
        ':portal_id' => (int)($portal['id'] ?? 0) ?: null,
        ':cliente_nome' => (string)($preContrato['nome_completo'] ?? ''),
        ':cliente_email' => (string)($preContrato['email'] ?? ''),
        ':assunto' => $assunto,
    ]);
    return (int)$stmtLog->fetchColumn();
}

function cliente_notificacoes_atualizar_log_envio(PDO $pdo, int $logId, bool $enviado, string $erro = ''): void
{
    if ($logId <= 0) {
        return;
    }

    if ($enviado) {
        $stmtUpdate = $pdo->prepare("
            UPDATE cliente_notificacao_envios
            SET status = 'enviado', enviado_em = NOW(), erro = NULL
            WHERE id = :id
        ");
        $stmtUpdate->bindValue(':id', $logId, PDO::PARAM_INT);
        $stmtUpdate->execute();
        return;
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE cliente_notificacao_envios
        SET status = 'erro', enviado_em = NULL, erro = :erro
        WHERE id = :id
    ");
    $stmtUpdate->bindValue(':erro', $erro !== '' ? $erro : 'Falha retornada pelo serviço de e-mail.');
    $stmtUpdate->bindValue(':id', $logId, PDO::PARAM_INT);
    $stmtUpdate->execute();
}

function cliente_notificacoes_enviar_modelo_para_pre_contrato(PDO $pdo, array $modelo, array $preContrato, int $usuarioId = 0): bool
{
    cliente_notificacoes_require_email_helper();

    $emailCliente = trim((string)($preContrato['email'] ?? ''));
    if ($emailCliente === '' || !filter_var($emailCliente, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Cliente sem e-mail válido para notificação.');
    }

    $portalContext = cliente_notificacoes_preparar_portal_pre_contrato($pdo, $preContrato, $usuarioId);
    if (empty($portalContext['ok'])) {
        throw new RuntimeException((string)($portalContext['error'] ?? 'Não foi possível preparar o portal.'));
    }

    $meetingId = (int)($portalContext['meeting_id'] ?? 0);
    $portal = (array)($portalContext['portal'] ?? []);
    $variaveis = cliente_notificacoes_variaveis_pre_contrato($preContrato, $portal);
    $assunto = cliente_notificacoes_substituir((string)($modelo['assunto'] ?? ''), $variaveis);
    $html = cliente_notificacoes_render_email($modelo, $variaveis);

    $logId = cliente_notificacoes_registrar_envio($pdo, $modelo, $preContrato, $meetingId, $portal, $assunto);

    $emailHelper = new EmailGlobalHelper();
    $enviado = $emailHelper->enviarEmail($emailCliente, $assunto, $html, true);
    cliente_notificacoes_atualizar_log_envio($pdo, $logId, (bool)$enviado);

    return (bool)$enviado;
}

function cliente_notificacoes_enviar_campanha_portal_lancamento(PDO $pdo, int $usuarioId = 0, int $limit = 500): array
{
    $modelo = cliente_notificacoes_get_modelo($pdo, 'portal_cliente_lancamento');
    if (!$modelo || empty($modelo['ativo']) || empty($modelo['canal_email'])) {
        return ['ok' => false, 'error' => 'Campanha inativa ou sem canal de e-mail habilitado.'];
    }

    $publico = cliente_notificacoes_buscar_publico_portal_lancamento($pdo, $limit);
    $resultado = [
        'ok' => true,
        'total' => count($publico),
        'enviados' => 0,
        'erros' => 0,
        'ignorados' => 0,
        'detalhes' => [],
    ];

    foreach ($publico as $item) {
        $preContrato = (array)$item;
        try {
            if (empty($item['email_valido'])) {
                $resultado['ignorados']++;
                $resultado['detalhes'][] = ['id' => (int)$item['id'], 'status' => 'ignorado', 'erro' => 'E-mail inválido'];
                continue;
            }

            $ok = cliente_notificacoes_enviar_modelo_para_pre_contrato($pdo, $modelo, $preContrato, $usuarioId);
            if ($ok) {
                $resultado['enviados']++;
                $resultado['detalhes'][] = ['id' => (int)$item['id'], 'status' => 'enviado'];
            } else {
                $resultado['erros']++;
                $resultado['detalhes'][] = ['id' => (int)$item['id'], 'status' => 'erro', 'erro' => 'Falha retornada pelo serviço de e-mail'];
            }
        } catch (Throwable $e) {
            $resultado['erros']++;
            $resultado['detalhes'][] = ['id' => (int)$item['id'], 'status' => 'erro', 'erro' => $e->getMessage()];
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO cliente_notificacao_envios
                        (modelo_id, chave_modelo, pre_contrato_id, me_event_id, cliente_nome, cliente_email, canal, status, erro)
                    VALUES
                        (:modelo_id, 'portal_cliente_lancamento', :pre_contrato_id, :me_event_id, :cliente_nome, :cliente_email, 'email', 'erro', :erro)
                ");
                $stmt->execute([
                    ':modelo_id' => (int)($modelo['id'] ?? 0),
                    ':pre_contrato_id' => (int)($item['id'] ?? 0) ?: null,
                    ':me_event_id' => (int)($item['me_event_id'] ?? 0) ?: null,
                    ':cliente_nome' => (string)($item['nome_completo'] ?? ''),
                    ':cliente_email' => (string)($item['email'] ?? ''),
                    ':erro' => $e->getMessage(),
                ]);
            } catch (Throwable $ignored) {
                error_log('[CLIENTE_NOTIFICACOES] campanha erro log: ' . $ignored->getMessage());
            }
        }
    }

    return $resultado;
}

function cliente_notificacoes_enviar_contrato_aprovado(PDO $pdo, array $preContrato, int $meEventId, int $usuarioId = 0): bool
{
    cliente_notificacoes_require_eventos_helpers();
    cliente_notificacoes_require_email_helper();

    try {
        $modelo = cliente_notificacoes_get_modelo($pdo, 'contrato_aprovado');
        if (!$modelo || empty($modelo['ativo']) || empty($modelo['envio_automatico']) || empty($modelo['canal_email'])) {
            return false;
        }

        $emailCliente = trim((string)($preContrato['email'] ?? ''));
        if ($emailCliente === '' || !filter_var($emailCliente, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Cliente sem e-mail válido para notificação.');
        }

        $preContratoId = (int)($preContrato['id'] ?? 0);
        $tipoEventoReal = (string)($preContrato['tipo_evento_real'] ?? $preContrato['tipo_evento'] ?? '');
        $reuniaoResult = eventos_reuniao_get_or_create($pdo, $meEventId, $usuarioId, $tipoEventoReal);
        if (empty($reuniaoResult['ok']) || empty($reuniaoResult['reuniao']['id'])) {
            throw new RuntimeException((string)($reuniaoResult['error'] ?? 'Não foi possível criar a reunião/portal.'));
        }

        $meetingId = (int)$reuniaoResult['reuniao']['id'];
        $portalResult = eventos_cliente_portal_get_or_create($pdo, $meetingId, $usuarioId);
        if (empty($portalResult['ok']) || empty($portalResult['portal']['url'])) {
            throw new RuntimeException((string)($portalResult['error'] ?? 'Não foi possível criar o portal do cliente.'));
        }

        $portal = $portalResult['portal'];
        $nomeCliente = trim((string)($preContrato['nome_completo'] ?? 'Cliente'));
        $tipoEvento = trim((string)($preContrato['tipo_evento_real'] ?? $preContrato['tipo_evento'] ?? 'Evento'));
        $nomeEvento = $tipoEvento !== '' ? ucfirst(str_replace('_', ' ', $tipoEvento)) : 'Evento';
        $dataEvento = cliente_notificacoes_data_br((string)($preContrato['data_evento'] ?? ''));
        $variaveis = [
            '{{nome_cliente}}' => $nomeCliente,
            '{{nome_evento}}' => $nomeEvento,
            '{{data_evento}}' => $dataEvento,
            '{{link_painel}}' => (string)$portal['url'],
            '{{prazo_formularios}}' => cliente_notificacoes_prazo_formularios((string)($preContrato['data_evento'] ?? '')),
        ];

        $assunto = cliente_notificacoes_substituir((string)$modelo['assunto'], $variaveis);
        $html = cliente_notificacoes_render_email($modelo, $variaveis);

        $logId = null;
        $stmtLog = $pdo->prepare("
            INSERT INTO cliente_notificacao_envios
                (modelo_id, chave_modelo, pre_contrato_id, me_event_id, meeting_id, portal_id, cliente_nome, cliente_email, canal, assunto, status)
            VALUES
                (:modelo_id, :chave_modelo, :pre_contrato_id, :me_event_id, :meeting_id, :portal_id, :cliente_nome, :cliente_email, 'email', :assunto, 'pendente')
            RETURNING id
        ");
        $stmtLog->execute([
            ':modelo_id' => (int)$modelo['id'],
            ':chave_modelo' => (string)$modelo['chave'],
            ':pre_contrato_id' => $preContratoId > 0 ? $preContratoId : null,
            ':me_event_id' => $meEventId,
            ':meeting_id' => $meetingId,
            ':portal_id' => (int)($portal['id'] ?? 0),
            ':cliente_nome' => $nomeCliente,
            ':cliente_email' => $emailCliente,
            ':assunto' => $assunto,
        ]);
        $logId = (int)$stmtLog->fetchColumn();

        $emailHelper = new EmailGlobalHelper();
        $enviado = $emailHelper->enviarEmail($emailCliente, $assunto, $html, true);

        if ($enviado) {
            $stmtUpdate = $pdo->prepare("
                UPDATE cliente_notificacao_envios
                SET status = 'enviado', enviado_em = NOW(), erro = NULL
                WHERE id = :id
            ");
            $stmtUpdate->bindValue(':id', $logId, PDO::PARAM_INT);
            $stmtUpdate->execute();
        } else {
            $stmtUpdate = $pdo->prepare("
                UPDATE cliente_notificacao_envios
                SET status = 'erro', enviado_em = NULL, erro = :erro
                WHERE id = :id
            ");
            $stmtUpdate->bindValue(':erro', 'Falha retornada pelo serviço de e-mail.');
            $stmtUpdate->bindValue(':id', $logId, PDO::PARAM_INT);
            $stmtUpdate->execute();
        }

        return (bool)$enviado;
    } catch (Throwable $e) {
        error_log('[CLIENTE_NOTIFICACOES] contrato_aprovado: ' . $e->getMessage());
        try {
            $stmt = $pdo->prepare("
                INSERT INTO cliente_notificacao_envios
                    (chave_modelo, pre_contrato_id, me_event_id, cliente_nome, cliente_email, canal, status, erro)
                VALUES
                    ('contrato_aprovado', :pre_contrato_id, :me_event_id, :cliente_nome, :cliente_email, 'email', 'erro', :erro)
            ");
            $stmt->execute([
                ':pre_contrato_id' => (int)($preContrato['id'] ?? 0) ?: null,
                ':me_event_id' => $meEventId > 0 ? $meEventId : null,
                ':cliente_nome' => (string)($preContrato['nome_completo'] ?? ''),
                ':cliente_email' => (string)($preContrato['email'] ?? ''),
                ':erro' => $e->getMessage(),
            ]);
        } catch (Throwable $ignored) {
            error_log('[CLIENTE_NOTIFICACOES] falha ao registrar erro: ' . $ignored->getMessage());
        }
        return false;
    }
}
