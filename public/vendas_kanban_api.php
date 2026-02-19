<?php
/**
 * vendas_kanban_api.php
 * API REST para Kanban de Acompanhamento de Contratos
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_comercial'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/notification_dispatcher.php';

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_GET['action'] ?? '');

function vendasKanbanColumnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = :table AND column_name = :column LIMIT 1");
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function vendasKanbanEnsureSchema(PDO $pdo): void
{
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS vendas_kanban_observacoes (\n            id SERIAL PRIMARY KEY,\n            card_id INT NOT NULL REFERENCES vendas_kanban_cards(id) ON DELETE CASCADE,\n            autor_id INT REFERENCES usuarios(id) ON DELETE SET NULL,\n            observacao TEXT NOT NULL,\n            criado_em TIMESTAMPTZ DEFAULT NOW()\n        )\n    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vendas_kanban_observacoes_card ON vendas_kanban_observacoes(card_id, criado_em DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vendas_kanban_observacoes_autor ON vendas_kanban_observacoes(autor_id)");
    $pdo->exec("ALTER TABLE vendas_kanban_cards ADD COLUMN IF NOT EXISTS concluido BOOLEAN NOT NULL DEFAULT FALSE");
    $pdo->exec("ALTER TABLE vendas_kanban_cards ADD COLUMN IF NOT EXISTS concluido_em TIMESTAMPTZ");
    $pdo->exec("ALTER TABLE vendas_kanban_cards ADD COLUMN IF NOT EXISTS concluido_por INT REFERENCES usuarios(id) ON DELETE SET NULL");
    $pdo->exec("ALTER TABLE vendas_kanban_cards ADD COLUMN IF NOT EXISTS arquivado BOOLEAN NOT NULL DEFAULT FALSE");
    $pdo->exec("ALTER TABLE vendas_kanban_cards ADD COLUMN IF NOT EXISTS arquivado_em TIMESTAMPTZ");
    $pdo->exec("ALTER TABLE vendas_kanban_cards ADD COLUMN IF NOT EXISTS arquivado_por INT REFERENCES usuarios(id) ON DELETE SET NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vendas_kanban_cards_arquivado ON vendas_kanban_cards(arquivado)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vendas_kanban_cards_concluido ON vendas_kanban_cards(concluido)");

    try {
        $dispatcher = new NotificationDispatcher($pdo);
        $dispatcher->ensureInternalSchema();
    } catch (Throwable $e) {
        error_log("[VENDAS_KANBAN] Falha ao garantir schema de notificações: " . $e->getMessage());
    }
}

function vendasKanbanNormalizeMentionKey(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') {
        return '';
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && $ascii !== '') {
        $value = strtolower($ascii);
    }

    $value = preg_replace('/[^a-z0-9._-]+/', '', $value);
    return trim((string)$value, '._-');
}

function vendasKanbanBuildAliases(array $user): array
{
    $aliases = [];

    $login = vendasKanbanNormalizeMentionKey((string)($user['login'] ?? ''));
    if ($login !== '') {
        $aliases[] = $login;
    }

    $email = trim((string)($user['email'] ?? ''));
    if ($email !== '') {
        $emailNorm = vendasKanbanNormalizeMentionKey($email);
        if ($emailNorm !== '') {
            $aliases[] = $emailNorm;
        }
        $emailUser = strstr($email, '@', true);
        if ($emailUser !== false) {
            $emailUserNorm = vendasKanbanNormalizeMentionKey($emailUser);
            if ($emailUserNorm !== '') {
                $aliases[] = $emailUserNorm;
            }
        }
    }

    $nome = trim((string)($user['nome'] ?? ''));
    if ($nome !== '') {
        $nomeAscii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
        if ($nomeAscii === false || $nomeAscii === '') {
            $nomeAscii = $nome;
        }
        $nomeAscii = strtolower($nomeAscii);

        $nomeCompacto = preg_replace('/[^a-z0-9]+/', '', $nomeAscii);
        if ($nomeCompacto !== '') {
            $aliases[] = $nomeCompacto;
        }

        $nomeComPonto = preg_replace('/[^a-z0-9]+/', '.', $nomeAscii);
        $nomeComPonto = trim((string)$nomeComPonto, '.');
        if ($nomeComPonto !== '') {
            $aliases[] = $nomeComPonto;
        }
    }

    return array_values(array_unique(array_filter($aliases)));
}

function vendasKanbanBuildMentionTag(array $user): string
{
    $login = vendasKanbanNormalizeMentionKey((string)($user['login'] ?? ''));
    if ($login !== '') {
        return $login;
    }

    $email = trim((string)($user['email'] ?? ''));
    if ($email !== '') {
        $emailUser = strstr($email, '@', true);
        if ($emailUser !== false) {
            $emailTag = vendasKanbanNormalizeMentionKey($emailUser);
            if ($emailTag !== '') {
                return $emailTag;
            }
        }
    }

    $aliases = vendasKanbanBuildAliases($user);
    if (!empty($aliases)) {
        return (string)$aliases[0];
    }

    return 'usuario' . (int)($user['id'] ?? 0);
}

function vendasKanbanFetchUsuariosAtivos(PDO $pdo): array
{
    $selectParts = ['id'];
    $hasNome = vendasKanbanColumnExists($pdo, 'usuarios', 'nome');
    $hasEmail = vendasKanbanColumnExists($pdo, 'usuarios', 'email');
    $hasLogin = vendasKanbanColumnExists($pdo, 'usuarios', 'login');
    $hasAtivo = vendasKanbanColumnExists($pdo, 'usuarios', 'ativo');

    if ($hasNome) {
        $selectParts[] = 'nome';
    }
    if ($hasEmail) {
        $selectParts[] = 'email';
    }
    if ($hasLogin) {
        $selectParts[] = 'login';
    }

    $select = implode(', ', $selectParts);
    $orderBy = $hasNome ? ' ORDER BY nome ASC' : ' ORDER BY id ASC';
    $where = $hasAtivo ? ' WHERE ativo IS DISTINCT FROM FALSE' : '';

    $stmt = $pdo->query("SELECT {$select} FROM usuarios{$where}{$orderBy}");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function vendasKanbanExtrairTokensMencao(string $mensagem): array
{
    preg_match_all('/@([A-Za-z0-9._-]{2,60})/u', $mensagem, $matches);
    $tokens = [];

    foreach (($matches[1] ?? []) as $token) {
        $tokenNorm = vendasKanbanNormalizeMentionKey((string)$token);
        if ($tokenNorm !== '') {
            $tokens[] = $tokenNorm;
        }
    }

    return array_values(array_unique($tokens));
}

function vendasKanbanResolverMencoes(PDO $pdo, array $tokens): array
{
    if (empty($tokens)) {
        return [];
    }

    $tokenMap = array_fill_keys($tokens, true);
    $encontrados = [];

    foreach (vendasKanbanFetchUsuariosAtivos($pdo) as $user) {
        $aliases = vendasKanbanBuildAliases($user);
        foreach ($aliases as $alias) {
            if (isset($tokenMap[$alias])) {
                $encontrados[(int)$user['id']] = $user;
                break;
            }
        }
    }

    return array_values($encontrados);
}

function vendasKanbanInserirNotificacaoMencao(PDO $pdo, int $destinatarioId, int $cardId, string $titulo, string $mensagem, string $urlDestino): void
{
    if ($destinatarioId <= 0) {
        return;
    }

    try {
        static $dispatcher = null;
        if (!$dispatcher) {
            $dispatcher = new NotificationDispatcher($pdo);
            $dispatcher->ensureInternalSchema();
        }

        $dispatcher->dispatch(
            [['id' => $destinatarioId]],
            [
                'tipo' => 'vendas_card_mencao',
                'referencia_id' => $cardId,
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'url_destino' => $urlDestino,
            ],
            ['internal' => true]
        );
    } catch (Throwable $e) {
        error_log("[VENDAS_KANBAN] Erro ao notificar menção: " . $e->getMessage());
    }
}

function vendasKanbanBuscarNomeUsuario(PDO $pdo, int $usuarioId): string
{
    if ($usuarioId <= 0) {
        return 'Usuário';
    }

    $stmt = $pdo->prepare('SELECT nome FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $usuarioId]);

    return (string)($stmt->fetchColumn() ?: 'Usuário');
}

function vendasKanbanDetalhesCard(PDO $pdo, int $cardId): array
{
    $stmt = $pdo->prepare("\n        SELECT vk.*,\n               vc.nome AS coluna_nome,\n               vc.cor AS coluna_cor,\n               vp.nome_completo,\n               vp.nome_noivos,\n               vp.telefone,\n               vp.email,\n               vp.data_evento,\n               vp.horario_inicio,\n               vp.horario_termino,\n               vp.unidade,\n               vp.valor_total,\n               vp.status AS pre_contrato_status,\n               vp.observacoes,\n               vp.observacoes_internas\n        FROM vendas_kanban_cards vk\n        LEFT JOIN vendas_kanban_colunas vc ON vc.id = vk.coluna_id\n        LEFT JOIN vendas_pre_contratos vp ON vp.id = vk.pre_contrato_id\n        WHERE vk.id = :card_id\n        LIMIT 1\n    ");
    $stmt->execute([':card_id' => $cardId]);
    $card = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$card) {
        throw new Exception('Card não encontrado');
    }

    $stmtObs = $pdo->prepare("\n        SELECT o.id,\n               o.card_id,\n               o.autor_id,\n               o.observacao,\n               o.criado_em,\n               u.nome AS autor_nome\n        FROM vendas_kanban_observacoes o\n        LEFT JOIN usuarios u ON u.id = o.autor_id\n        WHERE o.card_id = :card_id\n        ORDER BY o.criado_em DESC, o.id DESC\n        LIMIT 100\n    ");
    $stmtObs->execute([':card_id' => $cardId]);
    $observacoes = $stmtObs->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmtHist = $pdo->prepare("\n        SELECT h.id,\n               h.coluna_anterior_id,\n               h.coluna_nova_id,\n               h.movido_por,\n               h.movido_em,\n               h.observacao,\n               c_ant.nome AS coluna_anterior_nome,\n               c_nova.nome AS coluna_nova_nome,\n               u.nome AS movido_por_nome\n        FROM vendas_kanban_historico h\n        LEFT JOIN vendas_kanban_colunas c_ant ON c_ant.id = h.coluna_anterior_id\n        LEFT JOIN vendas_kanban_colunas c_nova ON c_nova.id = h.coluna_nova_id\n        LEFT JOIN usuarios u ON u.id = h.movido_por\n        WHERE h.card_id = :card_id\n        ORDER BY h.movido_em DESC, h.id DESC\n        LIMIT 40\n    ");
    $stmtHist->execute([':card_id' => $cardId]);
    $historico = $stmtHist->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'card' => $card,
        'observacoes' => $observacoes,
        'historico' => $historico,
    ];
}

try {
    vendasKanbanEnsureSchema($pdo);

    if ($action === 'mover_card') {
        $card_id = (int)($_POST['card_id'] ?? 0);
        $nova_coluna_id = (int)($_POST['coluna_id'] ?? 0);
        $nova_posicao = (int)($_POST['posicao'] ?? 0);

        if ($card_id <= 0 || $nova_coluna_id <= 0) {
            throw new Exception('Dados inválidos para mover card');
        }

        $stmt = $pdo->prepare('SELECT * FROM vendas_kanban_cards WHERE id = ?');
        $stmt->execute([$card_id]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            throw new Exception('Card não encontrado');
        }
        if (!empty($card['arquivado'])) {
            throw new Exception('Card arquivado não pode ser movido');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("\n            UPDATE vendas_kanban_cards\n            SET coluna_id = ?, posicao = ?, atualizado_em = NOW()\n            WHERE id = ?\n        ");
        $stmt->execute([$nova_coluna_id, $nova_posicao, $card_id]);

        $stmt_hist = $pdo->prepare("\n            INSERT INTO vendas_kanban_historico\n            (card_id, coluna_anterior_id, coluna_nova_id, movido_por)\n            VALUES (?, ?, ?, ?)\n        ");
        $stmt_hist->execute([$card_id, $card['coluna_id'], $nova_coluna_id, $usuario_id]);

        $pdo->commit();

        echo json_encode(['success' => true]);

    } elseif ($action === 'listar_colunas') {
        $board_id = (int)($_GET['board_id'] ?? 0);

        if (!$board_id) {
            $stmt = $pdo->prepare('SELECT id FROM vendas_kanban_boards WHERE ativo = TRUE LIMIT 1');
            $stmt->execute();
            $board = $stmt->fetch(PDO::FETCH_ASSOC);
            $board_id = (int)($board['id'] ?? 0);
        }

        $stmt = $pdo->prepare("\n            SELECT vc.*,\n                   COUNT(\n                       CASE\n                           WHEN (vk.pre_contrato_id IS NULL OR vp.id IS NOT NULL)\n                                AND COALESCE(vk.arquivado, FALSE) = FALSE THEN 1\n                           ELSE NULL\n                       END\n                   ) as total_cards\n            FROM vendas_kanban_colunas vc\n            LEFT JOIN vendas_kanban_cards vk ON vk.coluna_id = vc.id\n            LEFT JOIN vendas_pre_contratos vp ON vp.id = vk.pre_contrato_id\n            WHERE vc.board_id = ?\n            GROUP BY vc.id\n            ORDER BY vc.posicao ASC\n        ");
        $stmt->execute([$board_id]);
        $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $colunas]);

    } elseif ($action === 'listar_cards') {
        $coluna_id = (int)($_GET['coluna_id'] ?? 0);

        $stmt = $pdo->prepare("\n            SELECT vk.*,\n                   vp.nome_completo,\n                   vp.nome_noivos,\n                   vp.telefone,\n                   vp.data_evento,\n                   vp.horario_inicio,\n                   vp.unidade,\n                   vp.valor_total\n            FROM vendas_kanban_cards vk\n            LEFT JOIN vendas_pre_contratos vp ON vp.id = vk.pre_contrato_id\n            WHERE vk.coluna_id = ?\n              AND (vk.pre_contrato_id IS NULL OR vp.id IS NOT NULL)\n              AND COALESCE(vk.arquivado, FALSE) = FALSE\n            ORDER BY vk.posicao ASC, vk.id ASC\n        ");
        $stmt->execute([$coluna_id]);
        $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $cards]);

    } elseif ($action === 'toggle_concluido_card') {
        $card_id = (int)($_POST['card_id'] ?? 0);
        $concluidoRaw = $_POST['concluido'] ?? null;

        if ($card_id <= 0) {
            throw new Exception('Card inválido');
        }
        if ($concluidoRaw === null || $concluidoRaw === '') {
            throw new Exception('Status de conclusão inválido');
        }

        $concluido = filter_var($concluidoRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($concluido === null) {
            $concluido = ((string)$concluidoRaw === '1');
        }
        $concluidoInt = $concluido ? 1 : 0;

        $stmtCard = $pdo->prepare('SELECT id, arquivado FROM vendas_kanban_cards WHERE id = :id LIMIT 1');
        $stmtCard->execute([':id' => $card_id]);
        $card = $stmtCard->fetch(PDO::FETCH_ASSOC);
        if (!$card) {
            throw new Exception('Card não encontrado');
        }
        if (!empty($card['arquivado'])) {
            throw new Exception('Card arquivado não pode ser alterado');
        }

        $stmt = $pdo->prepare("
            UPDATE vendas_kanban_cards
            SET concluido = (:concluido = 1),
                concluido_em = CASE WHEN :concluido = 1 THEN NOW() ELSE NULL END,
                concluido_por = CASE WHEN :concluido = 1 THEN :usuario_id ELSE NULL END,
                atualizado_em = NOW()
            WHERE id = :id
            RETURNING id, concluido, concluido_em, concluido_por
        ");
        $stmt->execute([
            ':id' => $card_id,
            ':concluido' => $concluidoInt,
            ':usuario_id' => $usuario_id > 0 ? $usuario_id : null,
        ]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => $concluido ? 'Card marcado como concluído' : 'Card desmarcado',
            'data' => $updated,
        ]);

    } elseif ($action === 'arquivar_card') {
        $card_id = (int)($_POST['card_id'] ?? 0);
        if ($card_id <= 0) {
            throw new Exception('Card inválido');
        }

        $stmtCard = $pdo->prepare('SELECT id, arquivado FROM vendas_kanban_cards WHERE id = :id LIMIT 1');
        $stmtCard->execute([':id' => $card_id]);
        $card = $stmtCard->fetch(PDO::FETCH_ASSOC);
        if (!$card) {
            throw new Exception('Card não encontrado');
        }

        if (!empty($card['arquivado'])) {
            echo json_encode([
                'success' => true,
                'message' => 'Card já está arquivado',
                'data' => [
                    'id' => $card_id,
                    'arquivado' => true,
                ],
            ]);
            return;
        }

        $stmt = $pdo->prepare("
            UPDATE vendas_kanban_cards
            SET arquivado = TRUE,
                arquivado_em = NOW(),
                arquivado_por = :usuario_id,
                atualizado_em = NOW()
            WHERE id = :id
            RETURNING id, arquivado, arquivado_em, arquivado_por
        ");
        $stmt->execute([
            ':id' => $card_id,
            ':usuario_id' => $usuario_id > 0 ? $usuario_id : null,
        ]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Card arquivado com sucesso',
            'data' => $updated,
        ]);

    } elseif ($action === 'detalhes_card') {
        $card_id = (int)($_GET['card_id'] ?? 0);
        if ($card_id <= 0) {
            throw new Exception('Card inválido');
        }

        $detalhes = vendasKanbanDetalhesCard($pdo, $card_id);
        $usuarios = array_map(function (array $user): array {
            return [
                'id' => (int)($user['id'] ?? 0),
                'nome' => (string)($user['nome'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'tag' => vendasKanbanBuildMentionTag($user),
            ];
        }, vendasKanbanFetchUsuariosAtivos($pdo));

        echo json_encode([
            'success' => true,
            'data' => $detalhes,
            'usuarios_mencao' => $usuarios,
        ]);

    } elseif ($action === 'adicionar_observacao') {
        $body = $_POST;
        if (empty($body)) {
            $raw = json_decode((string)file_get_contents('php://input'), true);
            if (is_array($raw)) {
                $body = $raw;
            }
        }

        $card_id = (int)($body['card_id'] ?? 0);
        $observacao = trim((string)($body['observacao'] ?? ''));

        if ($card_id <= 0) {
            throw new Exception('Card inválido');
        }
        if ($observacao === '') {
            throw new Exception('A observação não pode ficar vazia');
        }

        $stmtCard = $pdo->prepare('SELECT id, titulo FROM vendas_kanban_cards WHERE id = :id LIMIT 1');
        $stmtCard->execute([':id' => $card_id]);
        $card = $stmtCard->fetch(PDO::FETCH_ASSOC);
        if (!$card) {
            throw new Exception('Card não encontrado');
        }

        $pdo->beginTransaction();

        $stmtInsert = $pdo->prepare("\n            INSERT INTO vendas_kanban_observacoes (card_id, autor_id, observacao)\n            VALUES (:card_id, :autor_id, :observacao)\n            RETURNING id, card_id, autor_id, observacao, criado_em\n        ");
        $stmtInsert->execute([
            ':card_id' => $card_id,
            ':autor_id' => $usuario_id > 0 ? $usuario_id : null,
            ':observacao' => $observacao,
        ]);
        $obs = $stmtInsert->fetch(PDO::FETCH_ASSOC);

        $autorNome = vendasKanbanBuscarNomeUsuario($pdo, $usuario_id);
        $tokens = vendasKanbanExtrairTokensMencao($observacao);
        $usuariosMencionados = vendasKanbanResolverMencoes($pdo, $tokens);

        $mencoesCriadas = 0;
        $usuariosNotificados = [];
        foreach ($usuariosMencionados as $usuarioMencionado) {
            $destinatarioId = (int)($usuarioMencionado['id'] ?? 0);
            if ($destinatarioId <= 0 || $destinatarioId === $usuario_id || isset($usuariosNotificados[$destinatarioId])) {
                continue;
            }
            $usuariosNotificados[$destinatarioId] = true;

            $tituloNotificacao = 'Nova menção no Kanban de Vendas';
            $mensagemNotificacao = $autorNome . ' mencionou você no card "' . (string)($card['titulo'] ?? ('#' . $card_id)) . '".';
            $urlDestino = 'index.php?page=vendas_kanban&card_id=' . $card_id;

            vendasKanbanInserirNotificacaoMencao(
                $pdo,
                $destinatarioId,
                $card_id,
                $tituloNotificacao,
                $mensagemNotificacao,
                $urlDestino
            );
            $mencoesCriadas++;
        }

        $pdo->commit();

        $obs['autor_nome'] = $autorNome;

        echo json_encode([
            'success' => true,
            'message' => 'Observação registrada com sucesso',
            'data' => $obs,
            'mencoes' => $mencoesCriadas,
        ]);

    } else {
        throw new Exception('Ação não reconhecida');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
