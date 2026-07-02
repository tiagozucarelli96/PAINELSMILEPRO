<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Não autorizado'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/upload_magalu.php';
require_once __DIR__ . '/core/notification_dispatcher.php';

ob_clean();
header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);

function demandasInternasIsAdmin(): bool
{
    return !empty($_SESSION['perm_superadmin'])
        || !empty($_SESSION['perm_administrativo'])
        || (isset($_SESSION['permissao']) && stripos((string)$_SESSION['permissao'], 'admin') !== false);
}

function demandasInternasJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function demandasInternasEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demandas_internas (
            id SERIAL PRIMARY KEY,
            titulo VARCHAR(180) NOT NULL,
            descricao TEXT,
            criador_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
            responsavel_tipo VARCHAR(20) NOT NULL DEFAULT 'usuario' CHECK (responsavel_tipo IN ('usuario', 'setor')),
            responsavel_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
            responsavel_setor VARCHAR(120),
            evento_tipo VARCHAR(40),
            evento_id INTEGER,
            evento_data DATE,
            evento_local VARCHAR(180),
            evento_nome VARCHAR(220),
            evento_whatsapp VARCHAR(40),
            status VARCHAR(20) NOT NULL DEFAULT 'aberta' CHECK (status IN ('aberta', 'em_andamento', 'aguardando', 'resolvida', 'encerrada', 'cancelada')),
            prioridade VARCHAR(20) NOT NULL DEFAULT 'normal' CHECK (prioridade IN ('baixa', 'normal', 'alta', 'urgente')),
            prazo DATE NOT NULL,
            encerrada_em TIMESTAMPTZ,
            criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demandas_internas_mensagens (
            id SERIAL PRIMARY KEY,
            demanda_id INTEGER NOT NULL REFERENCES demandas_internas(id) ON DELETE CASCADE,
            autor_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
            mensagem TEXT NOT NULL,
            criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demandas_internas_citacoes (
            id SERIAL PRIMARY KEY,
            demanda_id INTEGER NOT NULL REFERENCES demandas_internas(id) ON DELETE CASCADE,
            usuario_id INTEGER REFERENCES usuarios(id) ON DELETE CASCADE,
            setor VARCHAR(120),
            mensagem_id INTEGER REFERENCES demandas_internas_mensagens(id) ON DELETE SET NULL,
            citado_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
            criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demandas_internas_anexos (
            id SERIAL PRIMARY KEY,
            demanda_id INTEGER NOT NULL REFERENCES demandas_internas(id) ON DELETE CASCADE,
            upload_por INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
            nome_original TEXT NOT NULL,
            mime_type VARCHAR(120),
            tamanho_bytes BIGINT,
            chave_storage TEXT,
            url TEXT,
            criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demandas_internas_historico (
            id SERIAL PRIMARY KEY,
            demanda_id INTEGER NOT NULL REFERENCES demandas_internas(id) ON DELETE CASCADE,
            usuario_id INTEGER REFERENCES usuarios(id) ON DELETE SET NULL,
            acao VARCHAR(60) NOT NULL,
            resumo TEXT NOT NULL,
            dados JSONB,
            criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_internas_prazo ON demandas_internas(prazo)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_internas_responsavel ON demandas_internas(responsavel_tipo, responsavel_id, responsavel_setor)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_internas_criador ON demandas_internas(criador_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_internas_status ON demandas_internas(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_internas_citacoes_usuario ON demandas_internas_citacoes(usuario_id)");
}

function demandasInternasLog(PDO $pdo, int $demandaId, int $userId, string $acao, string $resumo, array $dados = []): void
{
    $stmt = $pdo->prepare("
        INSERT INTO demandas_internas_historico (demanda_id, usuario_id, acao, resumo, dados)
        VALUES (:demanda_id, :usuario_id, :acao, :resumo, CAST(:dados AS jsonb))
    ");
    $stmt->execute([
        ':demanda_id' => $demandaId,
        ':usuario_id' => $userId ?: null,
        ':acao' => $acao,
        ':resumo' => $resumo,
        ':dados' => json_encode($dados, JSON_UNESCAPED_UNICODE),
    ]);
}

function demandasInternasBuildUrl(int $demandaId = 0): string
{
    $path = '/index.php?page=demandas';
    if ($demandaId > 0) {
        $path .= '&demanda_id=' . $demandaId;
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return ltrim($path, '/');
    }

    $proto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === '') {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    return $proto . '://' . $host . $path;
}

function demandasInternasUsuariosDestino(PDO $pdo, string $responsavelTipo, int $responsavelId, string $responsavelSetor): array
{
    if ($responsavelTipo === 'usuario' && $responsavelId > 0) {
        return [['id' => $responsavelId]];
    }

    if ($responsavelTipo !== 'setor' || $responsavelSetor === '') {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT id, email
        FROM usuarios
        WHERE cargo IS NOT NULL
          AND LOWER(TRIM(cargo)) = LOWER(TRIM(:setor))
          AND COALESCE(NULLIF(LOWER(status::TEXT), ''), 'ativo') IN ('ativo', '1', 'true', 't')
    ");
    $stmt->execute([':setor' => $responsavelSetor]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function demandasInternasNotificarCriacao(
    PDO $pdo,
    int $demandaId,
    string $titulo,
    string $descricao,
    string $responsavelTipo,
    int $responsavelId,
    string $responsavelSetor,
    string $prazo
): void {
    try {
        $destinatarios = demandasInternasUsuariosDestino($pdo, $responsavelTipo, $responsavelId, $responsavelSetor);
        if (empty($destinatarios)) {
            return;
        }

        $url = demandasInternasBuildUrl($demandaId);
        $mensagem = "Nova demanda criada: {$titulo}";
        if ($prazo !== '') {
            $prazoFormatado = $prazo;
            try {
                $prazoFormatado = (new DateTimeImmutable($prazo))->format('d/m/Y');
            } catch (Throwable $e) {
                $prazoFormatado = $prazo;
            }
            $mensagem .= "\nPrazo: " . $prazoFormatado;
        }
        if ($descricao !== '') {
            $descricaoResumo = strlen($descricao) > 500 ? substr($descricao, 0, 500) . '...' : $descricao;
            $mensagem .= "\n\n" . $descricaoResumo;
        }
        $whatsappMensagem = demandasInternasWhatsappMensagemCriacao(
            $pdo,
            $demandaId,
            $url
        );

        $dispatcher = new NotificationDispatcher($pdo);
        $dispatcher->dispatch($destinatarios, [
            'tipo' => 'demanda_interna_criada',
            'referencia_id' => $demandaId,
            'titulo' => 'Nova demanda interna',
            'mensagem' => $mensagem,
            'url_destino' => $url,
            'email_assunto' => 'Nova demanda interna: ' . $titulo,
            'push_titulo' => 'Nova demanda interna',
            'push_mensagem' => $titulo,
            'whatsapp_mensagem' => $whatsappMensagem,
        ], [
            'internal' => true,
            'push' => true,
            'email' => true,
            'whatsapp' => true,
        ]);
    } catch (Throwable $e) {
        error_log('[DEMANDAS INTERNAS] Falha ao notificar criação da demanda #' . $demandaId . ': ' . $e->getMessage());
    }
}

function demandasInternasWhatsappMensagemCriacao(
    PDO $pdo,
    int $demandaId,
    string $url
): string {
    $stmt = $pdo->prepare("
        SELECT d.*, u.nome AS criador_nome, u.login AS criador_login
        FROM demandas_internas d
        LEFT JOIN usuarios u ON u.id = d.criador_id
        WHERE d.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $demandaId]);
    $demanda = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $titulo = trim((string)($demanda['titulo'] ?? 'Demanda interna'));
    $descricao = trim((string)($demanda['descricao'] ?? ''));
    $responsavelTipo = (string)($demanda['responsavel_tipo'] ?? 'usuario');
    $responsavelSetor = trim((string)($demanda['responsavel_setor'] ?? ''));
    $prazo = trim((string)($demanda['prazo'] ?? ''));
    $prioridade = trim((string)($demanda['prioridade'] ?? 'normal'));
    $criador = trim((string)($demanda['criador_nome'] ?? $demanda['criador_login'] ?? ''));
    $prazoFormatado = 'Sem prazo informado';
    if ($prazo !== '') {
        $prazoFormatado = $prazo;
        try {
            $prazoFormatado = (new DateTimeImmutable($prazo))->format('d/m/Y');
        } catch (Throwable $e) {
            $prazoFormatado = $prazo;
        }
    }

    $destino = $responsavelTipo === 'setor' && $responsavelSetor !== ''
        ? 'Setor ' . $responsavelSetor
        : 'Você';

    if ($descricao !== '') {
        $descricao = strlen($descricao) > 600 ? substr($descricao, 0, 600) . '...' : $descricao;
    }

    $eventoLinhas = '';
    if (!empty($demanda['evento_id']) || !empty($demanda['evento_nome']) || !empty($demanda['evento_local']) || !empty($demanda['evento_data'])) {
        $eventoData = trim((string)($demanda['evento_data'] ?? ''));
        if ($eventoData !== '') {
            try {
                $eventoData = (new DateTimeImmutable($eventoData))->format('d/m/Y');
            } catch (Throwable $e) {
                // Mantém valor original.
            }
        }
        $eventoLinhas = "\n🎉 *Evento vinculado:*";
        if (!empty($demanda['evento_nome'])) {
            $eventoLinhas .= "\n• " . trim((string)$demanda['evento_nome']);
        }
        if ($eventoData !== '') {
            $eventoLinhas .= "\n• Data: {$eventoData}";
        }
        if (!empty($demanda['evento_local'])) {
            $eventoLinhas .= "\n• Local: " . trim((string)$demanda['evento_local']);
        }
        if (!empty($demanda['evento_whatsapp'])) {
            $eventoLinhas .= "\n• WhatsApp: " . trim((string)$demanda['evento_whatsapp']);
        }
        $eventoLinhas .= "\n";
    }

    $stmt = $pdo->prepare("
        SELECT nome_original, url
        FROM demandas_internas_anexos
        WHERE demanda_id = :id
        ORDER BY criado_em ASC, id ASC
        LIMIT 5
    ");
    $stmt->execute([':id' => $demandaId]);
    $anexos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $anexosTexto = '';
    if ($anexos) {
        $anexosTexto = "\n📎 *Anexos:*";
        foreach ($anexos as $anexo) {
            $nome = trim((string)($anexo['nome_original'] ?? 'Arquivo'));
            $anexosTexto .= "\n• {$nome}";
        }
        $anexosTexto .= "\n";
    }

    return trim(
        "📌 *Nova demanda interna criada!*\n\n" .
        "📝 *Demanda:* {$titulo}\n" .
        "👤 *Responsável:* {$destino}\n" .
        ($criador !== '' ? "🙋 *Criada por:* {$criador}\n" : '') .
        "⚡ *Prioridade:* {$prioridade}\n" .
        "📅 *Prazo:* {$prazoFormatado}\n" .
        "🔎 *Código:* #{$demandaId}\n" .
        ($descricao !== '' ? "\n💬 *Conteúdo da demanda:*\n{$descricao}\n" : '') .
        $eventoLinhas .
        $anexosTexto .
        "\n➡️ Acesse a demanda pelo painel:\n{$url}"
    );
}

function demandasInternasNotificarAtualizacaoWhatsapp(PDO $pdo, int $demandaId, int $autorId, string $tipo, string $conteudo, ?array $anexo = null): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT titulo, responsavel_tipo, responsavel_id, responsavel_setor, criador_id
            FROM demandas_internas
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $demandaId]);
        $demanda = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$demanda) {
            return;
        }

        $destinatarios = demandasInternasUsuariosDestino(
            $pdo,
            (string)($demanda['responsavel_tipo'] ?? 'usuario'),
            (int)($demanda['responsavel_id'] ?? 0),
            (string)($demanda['responsavel_setor'] ?? '')
        );
        $criadorId = (int)($demanda['criador_id'] ?? 0);
        if ($criadorId > 0) {
            $destinatarios[] = ['id' => $criadorId];
        }

        $destinatariosPorId = [];
        foreach ($destinatarios as $destinatario) {
            $id = (int)($destinatario['id'] ?? 0);
            if ($id > 0 && $id !== $autorId) {
                $destinatariosPorId[$id] = ['id' => $id];
            }
        }
        if (!$destinatariosPorId) {
            return;
        }

        $url = demandasInternasBuildUrl($demandaId);
        $tituloDemanda = trim((string)($demanda['titulo'] ?? 'Demanda interna'));
        $conteudo = trim($conteudo);
        if ($conteudo !== '') {
            $conteudo = strlen($conteudo) > 700 ? substr($conteudo, 0, 700) . '...' : $conteudo;
        }

        if ($tipo === 'anexo') {
            $nomeAnexo = trim((string)($anexo['nome_original'] ?? 'Arquivo anexado'));
            $linkAnexo = trim((string)($anexo['url'] ?? ''));
            $whatsappMensagem = trim(
                "📎 *Novo anexo em demanda interna!*\n\n" .
                "📝 *Demanda:* {$tituloDemanda}\n" .
                "🔎 *Código:* #{$demandaId}\n\n" .
                "Arquivo: {$nomeAnexo}\n" .
                ($linkAnexo !== '' ? "Link: {$linkAnexo}\n\n" : "\n") .
                "➡️ Acesse a demanda pelo painel:\n{$url}"
            );
        } else {
            $whatsappMensagem = trim(
                "💬 *Nova mensagem em demanda interna!*\n\n" .
                "📝 *Demanda:* {$tituloDemanda}\n" .
                "🔎 *Código:* #{$demandaId}\n\n" .
                ($conteudo !== '' ? "*Mensagem:*\n{$conteudo}\n\n" : '') .
                "➡️ Acesse a demanda pelo painel:\n{$url}"
            );
        }

        $dispatcher = new NotificationDispatcher($pdo);
        $dispatcher->dispatch(array_values($destinatariosPorId), [
            'tipo' => 'demanda_interna_atualizada',
            'referencia_id' => $demandaId,
            'titulo' => $tipo === 'anexo' ? 'Novo anexo em demanda' : 'Nova mensagem em demanda',
            'mensagem' => $tipo === 'anexo' ? 'Novo anexo na demanda: ' . $tituloDemanda : 'Nova mensagem na demanda: ' . $tituloDemanda,
            'url_destino' => $url,
            'whatsapp_mensagem' => $whatsappMensagem,
        ], [
            'whatsapp' => true,
        ]);
    } catch (Throwable $e) {
        error_log('[DEMANDAS INTERNAS] Falha ao notificar atualização WhatsApp da demanda #' . $demandaId . ': ' . $e->getMessage());
    }
}

function demandasInternasNotificarMencoes(PDO $pdo, int $demandaId, array $destinatarios, int $autorId, bool $isNovaDemanda): void
{
    $destinatarios = array_values(array_unique(array_filter(array_map('intval', $destinatarios), static fn($id) => $id > 0 && $id !== $autorId)));
    if (empty($destinatarios)) {
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT titulo FROM demandas_internas WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $demandaId]);
        $tituloDemanda = trim((string)$stmt->fetchColumn());
        if ($tituloDemanda === '') {
            $tituloDemanda = 'demanda';
        }

        $titulo = $isNovaDemanda ? 'Você foi citado em uma nova demanda' : 'Você foi citado em uma demanda';
        $mensagem = $titulo . ': ' . $tituloDemanda;
        $url = demandasInternasBuildUrl($demandaId);

        $dispatcher = new NotificationDispatcher($pdo);
        $dispatcher->dispatch($destinatarios, [
            'tipo' => 'demanda_interna_mencao',
            'referencia_id' => $demandaId,
            'titulo' => $titulo,
            'mensagem' => $mensagem,
            'url_destino' => $url,
            'push_titulo' => $titulo,
            'push_mensagem' => $tituloDemanda,
        ], [
            'internal' => true,
            'push' => true,
        ]);
    } catch (Throwable $e) {
        error_log('[DEMANDAS INTERNAS] Falha ao notificar menções da demanda #' . $demandaId . ': ' . $e->getMessage());
    }
}

function demandasInternasVisibleWhere(bool $isAdmin, int $userId, string $alias = 'd'): string
{
    if ($isAdmin) {
        return '1 = 1';
    }

    return "(
        {$alias}.criador_id = {$userId}
        OR ({$alias}.responsavel_tipo = 'usuario' AND {$alias}.responsavel_id = {$userId})
        OR (
            {$alias}.responsavel_tipo = 'setor'
            AND EXISTS (
                SELECT 1 FROM usuarios u_resp
                WHERE u_resp.id = {$userId}
                  AND {$alias}.responsavel_setor IS NOT NULL
                  AND LOWER(TRIM(u_resp.cargo)) = LOWER(TRIM({$alias}.responsavel_setor))
            )
        )
        OR EXISTS (
            SELECT 1 FROM demandas_internas_citacoes dic_vis
            WHERE dic_vis.demanda_id = {$alias}.id
              AND (
                  dic_vis.usuario_id = {$userId}
                  OR EXISTS (
                      SELECT 1 FROM usuarios u_vis
                      WHERE u_vis.id = {$userId}
                        AND dic_vis.setor IS NOT NULL
                        AND LOWER(TRIM(u_vis.cargo)) = LOWER(TRIM(dic_vis.setor))
                  )
              )
        )
    )";
}

function demandasInternasUserCanAccess(PDO $pdo, int $demandaId, int $userId, bool $isAdmin): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM demandas_internas d
        WHERE d.id = :id AND " . demandasInternasVisibleWhere($isAdmin, $userId, 'd') . "
    ");
    $stmt->execute([':id' => $demandaId]);
    return (int)$stmt->fetchColumn() > 0;
}

function demandasInternasColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = CURRENT_SCHEMA() AND table_name = :table
    ");
    $stmt->execute([':table' => $table]);
    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

function demandasInternasParseMentions(PDO $pdo, int $demandaId, int $mensagemId, int $autorId, string $texto): void
{
    if (!preg_match_all('/@([\p{L}\p{N}._ -]{2,80})/u', $texto, $matches)) {
        return;
    }

    $usuarios = $pdo->query("SELECT id, nome FROM usuarios ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $setores = $pdo->query("SELECT DISTINCT cargo FROM usuarios WHERE cargo IS NOT NULL AND TRIM(cargo) <> '' ORDER BY cargo")->fetchAll(PDO::FETCH_COLUMN);
    $destinatariosMencao = [];

    foreach ($matches[1] as $rawMention) {
        $mention = mb_strtolower(trim((string)$rawMention));
        $matched = false;
        foreach ($usuarios as $usuario) {
            $nome = mb_strtolower(trim((string)$usuario['nome']));
            if ($nome !== '' && (str_starts_with($nome, $mention) || str_starts_with($mention, $nome))) {
                $stmt = $pdo->prepare("
                    INSERT INTO demandas_internas_citacoes (demanda_id, usuario_id, mensagem_id, citado_por)
                    VALUES (:demanda_id, :usuario_id, :mensagem_id, :citado_por)
                ");
                $stmt->execute([
                    ':demanda_id' => $demandaId,
                    ':usuario_id' => (int)$usuario['id'],
                    ':mensagem_id' => $mensagemId ?: null,
                    ':citado_por' => $autorId ?: null,
                ]);
                demandasInternasLog($pdo, $demandaId, $autorId, 'usuario_citado', '@' . $usuario['nome'] . ' foi citado.');
                $destinatariosMencao[] = (int)$usuario['id'];
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            foreach ($setores as $setor) {
                $setorNorm = mb_strtolower(trim((string)$setor));
                if ($setorNorm !== '' && (str_starts_with($setorNorm, $mention) || str_starts_with($mention, $setorNorm))) {
                    $stmt = $pdo->prepare("
                        INSERT INTO demandas_internas_citacoes (demanda_id, setor, mensagem_id, citado_por)
                        VALUES (:demanda_id, :setor, :mensagem_id, :citado_por)
                    ");
                    $stmt->execute([
                        ':demanda_id' => $demandaId,
                        ':setor' => $setor,
                        ':mensagem_id' => $mensagemId ?: null,
                        ':citado_por' => $autorId ?: null,
                    ]);
                    demandasInternasLog($pdo, $demandaId, $autorId, 'setor_citado', '@' . $setor . ' foi citado.');
                    foreach (demandasInternasUsuariosDestino($pdo, 'setor', 0, (string)$setor) as $usuarioDestino) {
                        $destinatariosMencao[] = (int)($usuarioDestino['id'] ?? 0);
                    }
                    break;
                }
            }
        }
    }

    demandasInternasNotificarMencoes($pdo, $demandaId, $destinatariosMencao, $autorId, $mensagemId <= 0);
}

function demandasInternasList(PDO $pdo, int $userId, bool $isAdmin): void
{
    $aba = (string)($_GET['aba'] ?? 'todas');
    $usuarioFiltro = (int)($_GET['usuario_id'] ?? 0);
    $where = [demandasInternasVisibleWhere($isAdmin, $userId, 'd')];
    $params = [];

    if ($aba === 'encerradas') {
        $where[] = "d.status IN ('encerrada', 'cancelada')";
    } else {
        $where[] = "d.status NOT IN ('encerrada', 'cancelada')";
    }

    if ($aba === 'minhas') {
        $where[] = "d.responsavel_tipo = 'usuario' AND d.responsavel_id = :user_id";
        $params[':user_id'] = $userId;
    } elseif ($aba === 'criadas') {
        $where[] = "d.criador_id = :user_id";
        $params[':user_id'] = $userId;
    } elseif ($aba === 'citacoes') {
        $where[] = "EXISTS (
            SELECT 1 FROM demandas_internas_citacoes dic
            WHERE dic.demanda_id = d.id
              AND (
                  dic.usuario_id = :user_id
                  OR EXISTS (
                      SELECT 1 FROM usuarios u_cit
                      WHERE u_cit.id = :user_id
                        AND dic.setor IS NOT NULL
                        AND LOWER(TRIM(u_cit.cargo)) = LOWER(TRIM(dic.setor))
                  )
              )
        )";
        $params[':user_id'] = $userId;
    } elseif ($aba === 'demais' && $isAdmin) {
        $where[] = "(d.criador_id <> :admin_user AND COALESCE(d.responsavel_id, 0) <> :admin_user)";
        $params[':admin_user'] = $userId;
        if ($usuarioFiltro > 0) {
            $where[] = "(d.criador_id = :usuario_filtro OR d.responsavel_id = :usuario_filtro OR EXISTS (SELECT 1 FROM demandas_internas_citacoes dic2 WHERE dic2.demanda_id = d.id AND dic2.usuario_id = :usuario_filtro))";
            $params[':usuario_filtro'] = $usuarioFiltro;
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            d.*,
            criador.nome AS criador_nome,
            criador.login AS criador_login,
            responsavel.nome AS responsavel_nome,
            responsavel.login AS responsavel_login,
            COALESCE(msgs.total, 0) AS mensagens_total,
            COALESCE(anexos.total, 0) AS anexos_total
        FROM demandas_internas d
        LEFT JOIN usuarios criador ON criador.id = d.criador_id
        LEFT JOIN usuarios responsavel ON responsavel.id = d.responsavel_id
        LEFT JOIN (
            SELECT demanda_id, COUNT(*) AS total FROM demandas_internas_mensagens GROUP BY demanda_id
        ) msgs ON msgs.demanda_id = d.id
        LEFT JOIN (
            SELECT demanda_id, COUNT(*) AS total FROM demandas_internas_anexos GROUP BY demanda_id
        ) anexos ON anexos.demanda_id = d.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY d.prazo ASC NULLS LAST, d.criado_em DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    demandasInternasJson(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'is_admin' => $isAdmin]);
}

function demandasInternasDetail(PDO $pdo, int $userId, bool $isAdmin): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0 || !demandasInternasUserCanAccess($pdo, $id, $userId, $isAdmin)) {
        demandasInternasJson(['success' => false, 'error' => 'Demanda não encontrada'], 404);
    }

    $stmt = $pdo->prepare("
        SELECT d.*,
               criador.nome AS criador_nome,
               criador.login AS criador_login,
               responsavel.nome AS responsavel_nome,
               responsavel.login AS responsavel_login
        FROM demandas_internas d
        LEFT JOIN usuarios criador ON criador.id = d.criador_id
        LEFT JOIN usuarios responsavel ON responsavel.id = d.responsavel_id
        WHERE d.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $demanda = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT m.*, u.nome AS autor_nome
        FROM demandas_internas_mensagens m
        LEFT JOIN usuarios u ON u.id = m.autor_id
        WHERE m.demanda_id = :id
        ORDER BY m.criado_em ASC
    ");
    $stmt->execute([':id' => $id]);
    $mensagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT a.*, u.nome AS upload_por_nome
        FROM demandas_internas_anexos a
        LEFT JOIN usuarios u ON u.id = a.upload_por
        WHERE a.demanda_id = :id
        ORDER BY a.criado_em DESC
    ");
    $stmt->execute([':id' => $id]);
    $anexos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    demandasInternasJson(['success' => true, 'demanda' => $demanda, 'mensagens' => $mensagens, 'anexos' => $anexos]);
}

function demandasInternasCreate(PDO $pdo, int $userId): void
{
    $titulo = trim((string)($_POST['titulo'] ?? ''));
    $descricao = trim((string)($_POST['descricao'] ?? ''));
    $responsavelTipo = (string)($_POST['responsavel_tipo'] ?? 'usuario');
    $responsavelId = (int)($_POST['responsavel_id'] ?? 0);
    $responsavelSetor = trim((string)($_POST['responsavel_setor'] ?? ''));
    $prazo = trim((string)($_POST['prazo'] ?? ''));
    $status = 'aberta';
    $prioridade = (string)($_POST['prioridade'] ?? 'normal');

    if ($titulo === '' || $prazo === '') {
        demandasInternasJson(['success' => false, 'error' => 'Título e prazo são obrigatórios.'], 422);
    }
    if ($responsavelTipo === 'usuario' && $responsavelId <= 0) {
        demandasInternasJson(['success' => false, 'error' => 'Selecione um responsável.'], 422);
    }
    if ($responsavelTipo === 'setor' && $responsavelSetor === '') {
        demandasInternasJson(['success' => false, 'error' => 'Informe o setor responsável.'], 422);
    }

    $stmt = $pdo->prepare("
        INSERT INTO demandas_internas (
            titulo, descricao, criador_id, responsavel_tipo, responsavel_id, responsavel_setor,
            evento_tipo, evento_id, evento_data, evento_local, evento_nome, evento_whatsapp,
            status, prioridade, prazo
        ) VALUES (
            :titulo, :descricao, :criador_id, :responsavel_tipo, :responsavel_id, :responsavel_setor,
            :evento_tipo, :evento_id, :evento_data, :evento_local, :evento_nome, :evento_whatsapp,
            :status, :prioridade, :prazo
        )
        RETURNING id
    ");
    $stmt->execute([
        ':titulo' => $titulo,
        ':descricao' => $descricao,
        ':criador_id' => $userId,
        ':responsavel_tipo' => $responsavelTipo,
        ':responsavel_id' => $responsavelTipo === 'usuario' ? $responsavelId : null,
        ':responsavel_setor' => $responsavelTipo === 'setor' ? $responsavelSetor : null,
        ':evento_tipo' => trim((string)($_POST['evento_tipo'] ?? '')) ?: null,
        ':evento_id' => (int)($_POST['evento_id'] ?? 0) ?: null,
        ':evento_data' => trim((string)($_POST['evento_data'] ?? '')) ?: null,
        ':evento_local' => trim((string)($_POST['evento_local'] ?? '')) ?: null,
        ':evento_nome' => trim((string)($_POST['evento_nome'] ?? '')) ?: null,
        ':evento_whatsapp' => trim((string)($_POST['evento_whatsapp'] ?? '')) ?: null,
        ':status' => $status,
        ':prioridade' => $prioridade,
        ':prazo' => $prazo,
    ]);
    $demandaId = (int)$stmt->fetchColumn();

    demandasInternasLog($pdo, $demandaId, $userId, 'demanda_criada', 'Demanda criada.');
    if ($responsavelTipo === 'usuario') {
        demandasInternasLog($pdo, $demandaId, $userId, 'responsavel_definido', 'Responsável definido.', ['responsavel_id' => $responsavelId]);
    } else {
        demandasInternasLog($pdo, $demandaId, $userId, 'responsavel_definido', 'Setor responsável definido.', ['setor' => $responsavelSetor]);
    }
    demandasInternasParseMentions($pdo, $demandaId, 0, $userId, $titulo . "\n" . $descricao);
    demandasInternasNotificarCriacao(
        $pdo,
        $demandaId,
        $titulo,
        $descricao,
        $responsavelTipo,
        $responsavelId,
        $responsavelSetor,
        $prazo
    );

    demandasInternasJson(['success' => true, 'id' => $demandaId]);
}

function demandasInternasMessage(PDO $pdo, int $userId, bool $isAdmin): void
{
    $id = (int)($_POST['demanda_id'] ?? 0);
    $mensagem = trim((string)($_POST['mensagem'] ?? ''));
    if ($id <= 0 || $mensagem === '' || !demandasInternasUserCanAccess($pdo, $id, $userId, $isAdmin)) {
        demandasInternasJson(['success' => false, 'error' => 'Mensagem inválida.'], 422);
    }

    $stmt = $pdo->prepare("
        INSERT INTO demandas_internas_mensagens (demanda_id, autor_id, mensagem)
        VALUES (:demanda_id, :autor_id, :mensagem)
        RETURNING id
    ");
    $stmt->execute([':demanda_id' => $id, ':autor_id' => $userId, ':mensagem' => $mensagem]);
    $mensagemId = (int)$stmt->fetchColumn();
    demandasInternasParseMentions($pdo, $id, $mensagemId, $userId, $mensagem);
    demandasInternasNotificarAtualizacaoWhatsapp($pdo, $id, $userId, 'mensagem', $mensagem);
    demandasInternasJson(['success' => true]);
}

function demandasInternasUpdate(PDO $pdo, int $userId, bool $isAdmin): void
{
    if (!$isAdmin) {
        demandasInternasJson(['success' => false, 'error' => 'Sem permissão para alterar campos administrativos.'], 403);
    }

    $id = (int)($_POST['demanda_id'] ?? 0);
    if ($id <= 0 || !demandasInternasUserCanAccess($pdo, $id, $userId, $isAdmin)) {
        demandasInternasJson(['success' => false, 'error' => 'Demanda não encontrada.'], 404);
    }

    $status = (string)($_POST['status'] ?? 'aberta');
    $prioridade = (string)($_POST['prioridade'] ?? 'normal');
    $prazo = trim((string)($_POST['prazo'] ?? ''));
    $encerradaEm = in_array($status, ['encerrada', 'cancelada'], true) ? 'NOW()' : 'NULL';

    $stmt = $pdo->prepare("
        UPDATE demandas_internas
        SET status = :status,
            prioridade = :prioridade,
            prazo = :prazo,
            encerrada_em = {$encerradaEm},
            atualizado_em = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':status' => $status,
        ':prioridade' => $prioridade,
        ':prazo' => $prazo,
    ]);

    demandasInternasLog($pdo, $id, $userId, 'demanda_alterada', 'Status, prazo ou prioridade alterado.');
    demandasInternasJson(['success' => true]);
}

function demandasInternasClose(PDO $pdo, int $userId, bool $isAdmin): void
{
    $id = (int)($_POST['demanda_id'] ?? 0);
    if ($id <= 0 || !demandasInternasUserCanAccess($pdo, $id, $userId, $isAdmin)) {
        demandasInternasJson(['success' => false, 'error' => 'Demanda não encontrada.'], 404);
    }

    $stmt = $pdo->prepare("
        UPDATE demandas_internas
        SET status = 'encerrada',
            encerrada_em = NOW(),
            atualizado_em = NOW()
        WHERE id = :id
          AND status NOT IN ('encerrada', 'cancelada')
    ");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() > 0) {
        demandasInternasLog($pdo, $id, $userId, 'demanda_encerrada', 'Demanda encerrada e arquivada.');
    }

    demandasInternasJson(['success' => true]);
}

function demandasInternasForward(PDO $pdo, int $userId, bool $isAdmin): void
{
    $id = (int)($_POST['demanda_id'] ?? 0);
    if ($id <= 0 || !demandasInternasUserCanAccess($pdo, $id, $userId, $isAdmin)) {
        demandasInternasJson(['success' => false, 'error' => 'Demanda não encontrada.'], 404);
    }

    $tipo = (string)($_POST['responsavel_tipo'] ?? 'usuario');
    $responsavelId = (int)($_POST['responsavel_id'] ?? 0);
    $setor = trim((string)($_POST['responsavel_setor'] ?? ''));
    if ($tipo === 'usuario' && $responsavelId <= 0) {
        demandasInternasJson(['success' => false, 'error' => 'Selecione o novo responsável.'], 422);
    }
    if ($tipo === 'setor' && $setor === '') {
        demandasInternasJson(['success' => false, 'error' => 'Informe o setor.'], 422);
    }

    $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $autorNome = (string)($stmt->fetchColumn() ?: 'Usuário');
    $destino = $setor;
    if ($tipo === 'usuario') {
        $stmt->execute([':id' => $responsavelId]);
        $destino = (string)($stmt->fetchColumn() ?: 'novo responsável');
    }

    $stmt = $pdo->prepare("
        UPDATE demandas_internas
        SET responsavel_tipo = :tipo,
            responsavel_id = :responsavel_id,
            responsavel_setor = :setor,
            atualizado_em = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':tipo' => $tipo,
        ':responsavel_id' => $tipo === 'usuario' ? $responsavelId : null,
        ':setor' => $tipo === 'setor' ? $setor : null,
    ]);

    demandasInternasLog($pdo, $id, $userId, 'demanda_encaminhada', "{$autorNome} encaminhou esta demanda para {$destino}.");
    demandasInternasJson(['success' => true]);
}

function demandasInternasAttach(PDO $pdo, int $userId, bool $isAdmin): void
{
    $id = (int)($_POST['demanda_id'] ?? 0);
    if ($id <= 0 || !demandasInternasUserCanAccess($pdo, $id, $userId, $isAdmin)) {
        demandasInternasJson(['success' => false, 'error' => 'Demanda não encontrada.'], 404);
    }
    if (empty($_FILES['arquivo']) || (int)$_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        demandasInternasJson(['success' => false, 'error' => 'Arquivo inválido.'], 422);
    }

    $uploader = new MagaluUpload();
    $upload = $uploader->upload($_FILES['arquivo'], 'demandas_internas');
    $stmt = $pdo->prepare("
        INSERT INTO demandas_internas_anexos (demanda_id, upload_por, nome_original, mime_type, tamanho_bytes, chave_storage, url)
        VALUES (:demanda_id, :upload_por, :nome_original, :mime_type, :tamanho_bytes, :chave_storage, :url)
    ");
    $stmt->execute([
        ':demanda_id' => $id,
        ':upload_por' => $userId,
        ':nome_original' => $upload['nome_original'],
        ':mime_type' => $upload['mime_type'],
        ':tamanho_bytes' => $upload['tamanho_bytes'],
        ':chave_storage' => $upload['chave_storage'],
        ':url' => $upload['url'],
    ]);
    demandasInternasLog($pdo, $id, $userId, 'arquivo_anexado', 'Arquivo anexado: ' . $upload['nome_original']);
    demandasInternasNotificarAtualizacaoWhatsapp($pdo, $id, $userId, 'anexo', '', $upload);
    demandasInternasJson(['success' => true]);
}

function demandasInternasGallerySearch(PDO $pdo): void
{
    $q = '%' . trim((string)($_GET['q'] ?? '')) . '%';
    $cols = demandasInternasColumns($pdo, 'eventos_galeria');
    if (!$cols) {
        demandasInternasJson(['success' => true, 'data' => []]);
    }

    $thumbSelect = isset($cols['thumb_public_url']) ? ', thumb_public_url' : '';
    $searchParts = [];
    foreach (['nome', 'categoria', 'tags', 'descricao'] as $searchColumn) {
        if (isset($cols[$searchColumn])) {
            $searchParts[] = "{$searchColumn} ILIKE :q";
        }
    }
    $searchSql = $searchParts ? '(' . implode(' OR ', $searchParts) . ')' : 'CAST(id AS TEXT) ILIKE :q';
    $stmt = $pdo->prepare("
        SELECT id, nome, categoria, public_url{$thumbSelect}
        FROM eventos_galeria
        WHERE deleted_at IS NULL
          AND {$searchSql}
        ORDER BY uploaded_at DESC NULLS LAST
        LIMIT 24
    ");
    $stmt->execute([':q' => $q]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $url = trim((string)($row['public_url'] ?? ''));
        if ($url === '') {
            $url = 'eventos_galeria_imagem.php?id=' . (int)$row['id'];
        }
        $items[] = [
            'id' => (int)$row['id'],
            'nome' => $row['nome'] ?: ('Imagem #' . (int)$row['id']),
            'categoria' => $row['categoria'] ?? '',
            'url' => $url,
            'thumb' => $row['thumb_public_url'] ?? $url,
        ];
    }
    demandasInternasJson(['success' => true, 'data' => $items]);
}

function demandasInternasAttachGallery(PDO $pdo, int $userId, bool $isAdmin): void
{
    $id = (int)($_POST['demanda_id'] ?? 0);
    $galleryId = (int)($_POST['gallery_id'] ?? 0);
    if ($id <= 0 || $galleryId <= 0 || !demandasInternasUserCanAccess($pdo, $id, $userId, $isAdmin)) {
        demandasInternasJson(['success' => false, 'error' => 'Imagem inválida.'], 422);
    }

    $stmt = $pdo->prepare("
        SELECT id, nome, public_url, storage_key
        FROM eventos_galeria
        WHERE id = :id AND deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':id' => $galleryId]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$image) {
        demandasInternasJson(['success' => false, 'error' => 'Imagem da galeria não encontrada.'], 404);
    }

    $url = trim((string)($image['public_url'] ?? ''));
    if ($url === '') {
        $url = 'eventos_galeria_imagem.php?id=' . (int)$image['id'];
    }
    $nome = trim((string)($image['nome'] ?? '')) ?: ('Imagem da galeria #' . (int)$image['id']);
    $stmt = $pdo->prepare("
        INSERT INTO demandas_internas_anexos (demanda_id, upload_por, nome_original, mime_type, tamanho_bytes, chave_storage, url)
        VALUES (:demanda_id, :upload_por, :nome_original, 'image/*', 0, :chave_storage, :url)
    ");
    $stmt->execute([
        ':demanda_id' => $id,
        ':upload_por' => $userId,
        ':nome_original' => $nome,
        ':chave_storage' => $image['storage_key'] ?? null,
        ':url' => $url,
    ]);
    demandasInternasLog($pdo, $id, $userId, 'arquivo_anexado', 'Imagem anexada pela Galeria Smile: ' . $nome);
    demandasInternasNotificarAtualizacaoWhatsapp($pdo, $id, $userId, 'anexo', '', [
        'nome_original' => $nome,
        'url' => $url,
    ]);
    demandasInternasJson(['success' => true]);
}

function demandasInternasHistory(PDO $pdo, int $userId, bool $isAdmin): void
{
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0 || !demandasInternasUserCanAccess($pdo, $id, $userId, $isAdmin)) {
        demandasInternasJson(['success' => false, 'error' => 'Demanda não encontrada.'], 404);
    }

    $stmt = $pdo->prepare("
        SELECT h.*, u.nome AS usuario_nome
        FROM demandas_internas_historico h
        LEFT JOIN usuarios u ON u.id = h.usuario_id
        WHERE h.demanda_id = :id
        ORDER BY h.criado_em DESC
    ");
    $stmt->execute([':id' => $id]);
    demandasInternasJson(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function demandasInternasBootstrap(PDO $pdo, bool $isAdmin): void
{
    $usuarios = $pdo->query("
        SELECT
            id,
            nome,
            login,
            COALESCE(NULLIF(TRIM(login), ''), nome) AS label_usuario,
            email,
            cargo
        FROM usuarios
        WHERE COALESCE(NULLIF(LOWER(status::TEXT), ''), 'ativo') IN ('ativo', '1', 'true', 't')
        ORDER BY COALESCE(NULLIF(TRIM(login), ''), nome)
    ")->fetchAll(PDO::FETCH_ASSOC);
    $setores = $pdo->query("SELECT DISTINCT cargo FROM usuarios WHERE cargo IS NOT NULL AND TRIM(cargo) <> '' ORDER BY cargo")->fetchAll(PDO::FETCH_COLUMN);
    demandasInternasJson(['success' => true, 'usuarios' => $usuarios, 'setores' => $setores, 'is_admin' => $isAdmin]);
}

function demandasInternasEventSearch(PDO $pdo): void
{
    $termo = trim((string)($_GET['q'] ?? ''));
    $q = '%' . $termo . '%';
    $items = [];

    try {
        $cols = demandasInternasColumns($pdo, 'logistica_eventos_espelho');
        if ($cols) {
            $idExpr = isset($cols['me_event_id']) ? 'me_event_id' : 'id';
            $nomeExpr = isset($cols['nome_evento']) ? 'nome_evento' : "'Evento ME #' || {$idExpr}::TEXT";
            $dataExpr = isset($cols['data_evento']) ? 'data_evento' : 'NULL::date';
            $localExpr = isset($cols['localevento']) ? 'localevento' : (isset($cols['local_evento']) ? 'local_evento' : "''");
            $whatsExpr = isset($cols['whatsapp_cliente']) && isset($cols['telefone_cliente'])
                ? "COALESCE(NULLIF(whatsapp_cliente, ''), telefone_cliente, '')"
                : (isset($cols['whatsapp_cliente']) ? 'whatsapp_cliente' : (isset($cols['telefone_cliente']) ? 'telefone_cliente' : "''"));
            $whereParts = [
                "CAST({$idExpr} AS TEXT) ILIKE :q",
                "CAST({$nomeExpr} AS TEXT) ILIKE :q",
                "CAST({$localExpr} AS TEXT) ILIKE :q",
            ];
            $arquivadoSql = isset($cols['arquivado']) ? "AND COALESCE(arquivado, FALSE) = FALSE" : "";
            $stmt = $pdo->prepare("
                SELECT 'me' AS tipo, {$idExpr} AS id, {$nomeExpr} AS nome, {$dataExpr} AS data_evento,
                       {$localExpr} AS local, {$whatsExpr} AS whatsapp
                FROM logistica_eventos_espelho
                WHERE (" . implode(' OR ', $whereParts) . ")
                  {$arquivadoSql}
                ORDER BY {$dataExpr} DESC NULLS LAST
                LIMIT 20
            ");
            $stmt->execute([':q' => $q]);
            $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    } catch (Throwable $e) {
        error_log('[DEMANDAS INTERNAS] Busca ME falhou: ' . $e->getMessage());
    }

    try {
        $cols = demandasInternasColumns($pdo, 'agenda_eventos');
        if ($cols) {
            $tituloExpr = isset($cols['titulo']) ? 'titulo' : "'Evento interno #' || id::TEXT";
            $inicioExpr = isset($cols['inicio']) ? 'inicio' : (isset($cols['data_inicio']) ? 'data_inicio' : 'criado_em');
            $localExpr = isset($cols['local']) ? 'local' : (isset($cols['observacoes']) ? 'observacoes' : "''");
            $whereParts = [
                "CAST(id AS TEXT) ILIKE :q",
                "CAST({$tituloExpr} AS TEXT) ILIKE :q",
                "CAST({$localExpr} AS TEXT) ILIKE :q",
            ];
            $stmt = $pdo->prepare("
                SELECT 'agenda' AS tipo, id, {$tituloExpr} AS nome, DATE({$inicioExpr}) AS data_evento,
                       {$localExpr} AS local, '' AS whatsapp
                FROM agenda_eventos
                WHERE " . implode(' OR ', $whereParts) . "
                ORDER BY {$inicioExpr} DESC
                LIMIT 20
            ");
            $stmt->execute([':q' => $q]);
            $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    } catch (Throwable $e) {
        error_log('[DEMANDAS INTERNAS] Busca agenda falhou: ' . $e->getMessage());
    }

    demandasInternasJson(['success' => true, 'data' => array_slice($items, 0, 30)]);
}

try {
    demandasInternasEnsureSchema($pdo);
    $action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
    $isAdmin = demandasInternasIsAdmin();

    if ($action === 'bootstrap') {
        demandasInternasBootstrap($pdo, $isAdmin);
    } elseif ($action === 'list') {
        demandasInternasList($pdo, $userId, $isAdmin);
    } elseif ($action === 'detail') {
        demandasInternasDetail($pdo, $userId, $isAdmin);
    } elseif ($action === 'create') {
        demandasInternasCreate($pdo, $userId);
    } elseif ($action === 'message') {
        demandasInternasMessage($pdo, $userId, $isAdmin);
    } elseif ($action === 'update') {
        demandasInternasUpdate($pdo, $userId, $isAdmin);
    } elseif ($action === 'close') {
        demandasInternasClose($pdo, $userId, $isAdmin);
    } elseif ($action === 'forward') {
        demandasInternasForward($pdo, $userId, $isAdmin);
    } elseif ($action === 'attach') {
        demandasInternasAttach($pdo, $userId, $isAdmin);
    } elseif ($action === 'gallery_search') {
        demandasInternasGallerySearch($pdo);
    } elseif ($action === 'attach_gallery') {
        demandasInternasAttachGallery($pdo, $userId, $isAdmin);
    } elseif ($action === 'history') {
        demandasInternasHistory($pdo, $userId, $isAdmin);
    } elseif ($action === 'event_search') {
        demandasInternasEventSearch($pdo);
    }

    demandasInternasJson(['success' => false, 'error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
    error_log('[DEMANDAS INTERNAS] ' . $e->getMessage());
    demandasInternasJson(['success' => false, 'error' => $e->getMessage()], 500);
}
