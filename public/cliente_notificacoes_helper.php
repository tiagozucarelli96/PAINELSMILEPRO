<?php
/**
 * cliente_notificacoes_helper.php
 * Modelos e disparos de notificações para clientes.
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/email_global_helper.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

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

    $stmt->execute([
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
    cliente_notificacoes_ensure_schema($pdo);
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

function cliente_notificacoes_enviar_contrato_aprovado(PDO $pdo, array $preContrato, int $meEventId, int $usuarioId = 0): bool
{
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
