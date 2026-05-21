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
        . '<tr><td style="background:#0f766e;padding:28px 30px;color:#ffffff;">'
        . '<div style="font-size:13px;letter-spacing:.04em;text-transform:uppercase;opacity:.9;">Grupo Smile Eventos</div>'
        . '<h1 style="margin:8px 0 0;font-size:26px;line-height:1.2;">Seu painel do evento está disponível</h1>'
        . '</td></tr>'
        . '<tr><td style="padding:28px 30px;">'
        . '<p style="margin:0 0 18px;color:#0f172a;font-size:18px;line-height:1.5;">Olá, <strong>' . $nomeCliente . '</strong>.</p>'
        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:0 0 22px;">'
        . '<div style="font-size:13px;color:#64748b;margin-bottom:4px;">Evento</div>'
        . '<div style="font-size:16px;color:#0f172a;font-weight:700;">' . $nomeEvento . ($dataEvento !== '' ? ' - ' . $dataEvento : '') . '</div>'
        . '</div>'
        . $body
        . '<div style="text-align:center;margin:28px 0 24px;">'
        . '<a href="' . $linkPainel . '" style="display:inline-block;background:#0f766e;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;padding:14px 22px;border-radius:8px;">' . $botaoTexto . '</a>'
        . '</div>'
        . '<p style="margin:0;color:#64748b;font-size:13px;line-height:1.5;">Se o botão não abrir, copie este link no navegador:<br>'
        . '<a href="' . $linkPainel . '" style="color:#0f766e;word-break:break-all;">' . $linkPainel . '</a></p>'
        . '</td></tr>'
        . '<tr><td style="background:#f8fafc;padding:18px 30px;color:#64748b;font-size:12px;text-align:center;">Este é um e-mail automático do Painel Smile Pro.</td></tr>'
        . '</table></td></tr></table></body></html>';
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
