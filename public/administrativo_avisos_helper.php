<?php

if (!function_exists('adminAvisosUsuarioLogadoId')) {
    function adminAvisosUsuarioLogadoId(): int
    {
        return (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
    }
}

if (!function_exists('adminAvisosBoolValue')) {
    function adminAvisosBoolValue($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        $texto = strtolower(trim((string)$valor));
        return in_array($texto, ['1', 't', 'true', 'yes', 'y', 'on', 'sim'], true);
    }
}

if (!function_exists('adminAvisosEnsureSchema')) {
    function adminAvisosEnsureSchema(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS administrativo_avisos (
                id BIGSERIAL PRIMARY KEY,
                assunto VARCHAR(180) NOT NULL,
                conteudo_html TEXT NOT NULL,
                modo_destino VARCHAR(20) NOT NULL DEFAULT 'selecionados',
                visualizacao_unica BOOLEAN NOT NULL DEFAULT FALSE,
                expira_em TIMESTAMPTZ NULL,
                ativo BOOLEAN NOT NULL DEFAULT TRUE,
                criado_por_usuario_id BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
                criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS administrativo_avisos_usuarios (
                id BIGSERIAL PRIMARY KEY,
                aviso_id BIGINT NOT NULL REFERENCES administrativo_avisos(id) ON DELETE CASCADE,
                usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                UNIQUE(aviso_id, usuario_id)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS administrativo_avisos_visualizacoes (
                id BIGSERIAL PRIMARY KEY,
                aviso_id BIGINT NOT NULL REFERENCES administrativo_avisos(id) ON DELETE CASCADE,
                usuario_id BIGINT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                visualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                ultima_visualizacao_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                total_visualizacoes INT NOT NULL DEFAULT 1,
                UNIQUE(aviso_id, usuario_id)
            )
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_avisos_criado_em ON administrativo_avisos(criado_em DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_avisos_ativo_expira ON administrativo_avisos(ativo, expira_em)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_avisos_usuarios_aviso ON administrativo_avisos_usuarios(aviso_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_avisos_usuarios_usuario ON administrativo_avisos_usuarios(usuario_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_avisos_views_aviso ON administrativo_avisos_visualizacoes(aviso_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_avisos_views_usuario ON administrativo_avisos_visualizacoes(usuario_id)");
    }
}

if (!function_exists('adminAvisosBuscarUsuariosAtivos')) {
    function adminAvisosBuscarUsuariosAtivos(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT id, nome, email
            FROM usuarios
            WHERE ativo IS DISTINCT FROM FALSE
            ORDER BY nome ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('adminAvisosNormalizarIds')) {
    function adminAvisosNormalizarIds(array $ids): array
    {
        $normalizados = [];
        foreach ($ids as $id) {
            $idInt = (int)$id;
            if ($idInt > 0) {
                $normalizados[$idInt] = $idInt;
            }
        }

        return array_values($normalizados);
    }
}

if (!function_exists('adminAvisosSalvarDestinatarios')) {
    function adminAvisosSalvarDestinatarios(PDO $pdo, int $avisoId, array $usuariosIds): void
    {
        $usuariosIds = adminAvisosNormalizarIds($usuariosIds);

        $stmtDelete = $pdo->prepare("DELETE FROM administrativo_avisos_usuarios WHERE aviso_id = :aviso_id");
        $stmtDelete->execute([':aviso_id' => $avisoId]);

        if (empty($usuariosIds)) {
            return;
        }

        $stmtInsert = $pdo->prepare("
            INSERT INTO administrativo_avisos_usuarios (aviso_id, usuario_id)
            VALUES (:aviso_id, :usuario_id)
            ON CONFLICT (aviso_id, usuario_id) DO NOTHING
        ");

        foreach ($usuariosIds as $usuarioId) {
            $stmtInsert->execute([
                ':aviso_id' => $avisoId,
                ':usuario_id' => $usuarioId,
            ]);
        }
    }
}

if (!function_exists('adminAvisosCriar')) {
    function adminAvisosCriar(PDO $pdo, array $dados): int
    {
        $stmt = $pdo->prepare("
            INSERT INTO administrativo_avisos (
                assunto, conteudo_html, modo_destino, visualizacao_unica,
                expira_em, ativo, criado_por_usuario_id, atualizado_em
            ) VALUES (
                :assunto, :conteudo_html, :modo_destino, :visualizacao_unica,
                :expira_em, TRUE, :criado_por_usuario_id, NOW()
            )
            RETURNING id
        ");

        $stmt->execute([
            ':assunto' => trim((string)$dados['assunto']),
            ':conteudo_html' => trim((string)$dados['conteudo_html']),
            ':modo_destino' => $dados['modo_destino'] === 'todos' ? 'todos' : 'selecionados',
            ':visualizacao_unica' => !empty($dados['visualizacao_unica']),
            ':expira_em' => !empty($dados['expira_em']) ? $dados['expira_em'] : null,
            ':criado_por_usuario_id' => (int)($dados['criado_por_usuario_id'] ?? 0) > 0 ? (int)$dados['criado_por_usuario_id'] : null,
        ]);

        $avisoId = (int)$stmt->fetchColumn();
        adminAvisosSalvarDestinatarios($pdo, $avisoId, (array)($dados['destinatarios'] ?? []));

        return $avisoId;
    }
}

if (!function_exists('adminAvisosDesativar')) {
    function adminAvisosDesativar(PDO $pdo, int $avisoId): void
    {
        $stmt = $pdo->prepare("
            UPDATE administrativo_avisos
            SET ativo = FALSE, atualizado_em = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $avisoId]);
    }
}

if (!function_exists('adminAvisosBuscarPorId')) {
    function adminAvisosBuscarPorId(PDO $pdo, int $avisoId): ?array
    {
        if ($avisoId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM administrativo_avisos
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $avisoId]);

        $aviso = $stmt->fetch(PDO::FETCH_ASSOC);
        return $aviso ?: null;
    }
}

if (!function_exists('adminAvisosDecodeStorageKey')) {
    function adminAvisosDecodeStorageKey(string $encodedKey): ?string
    {
        $encodedKey = trim($encodedKey);
        if ($encodedKey === '') {
            return null;
        }

        $decoded = base64_decode(strtr($encodedKey, '-_', '+/'), true);
        if (!is_string($decoded) || $decoded === '' || strpos($decoded, '..') !== false) {
            return null;
        }

        if (strpos($decoded, 'administrativo/avisos/') !== 0) {
            return null;
        }

        return $decoded;
    }
}

if (!function_exists('adminAvisosExtrairStorageKeys')) {
    function adminAvisosExtrairStorageKeys(string $conteudoHtml): array
    {
        $keys = [];
        if (trim($conteudoHtml) === '') {
            return [];
        }

        if (preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $conteudoHtml, $matches)) {
            foreach ((array)($matches[1] ?? []) as $src) {
                $src = html_entity_decode((string)$src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $query = parse_url($src, PHP_URL_QUERY);
                if (!is_string($query) || $query === '') {
                    continue;
                }

                parse_str($query, $params);
                $encodedKey = (string)($params['key'] ?? '');
                $key = adminAvisosDecodeStorageKey(rawurldecode($encodedKey));
                if ($key !== null) {
                    $keys[$key] = $key;
                }
            }
        }

        return array_values($keys);
    }
}

if (!function_exists('adminAvisosExcluir')) {
    function adminAvisosExcluir(PDO $pdo, int $avisoId): void
    {
        $stmt = $pdo->prepare("
            DELETE FROM administrativo_avisos
            WHERE id = :id
        ");
        $stmt->execute([':id' => $avisoId]);
    }
}

if (!function_exists('adminAvisosUsuarioPodeVer')) {
    function adminAvisosUsuarioPodeVer(PDO $pdo, int $avisoId, int $usuarioId): bool
    {
        if ($usuarioId <= 0 || $avisoId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT a.id
            FROM administrativo_avisos a
            WHERE a.id = :aviso_id
              AND a.ativo = TRUE
              AND (a.expira_em IS NULL OR a.expira_em > NOW())
              AND (
                    a.modo_destino = 'todos'
                    OR EXISTS (
                        SELECT 1
                        FROM administrativo_avisos_usuarios au
                        WHERE au.aviso_id = a.id
                          AND au.usuario_id = :usuario_id
                    )
              )
              AND (
                    a.visualizacao_unica = FALSE
                    OR NOT EXISTS (
                        SELECT 1
                        FROM administrativo_avisos_visualizacoes av
                        WHERE av.aviso_id = a.id
                          AND av.usuario_id = :usuario_id
                    )
              )
            LIMIT 1
        ");
        $stmt->execute([
            ':aviso_id' => $avisoId,
            ':usuario_id' => $usuarioId,
        ]);

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('adminAvisosBuscarParaDashboard')) {
    function adminAvisosBuscarParaDashboard(PDO $pdo, int $usuarioId, int $limit = 8): array
    {
        if ($usuarioId <= 0) {
            return [];
        }

        $limit = max(1, min(20, $limit));
        $stmt = $pdo->prepare("
            SELECT a.id,
                   a.assunto,
                   a.visualizacao_unica,
                   a.expira_em,
                   a.criado_em
            FROM administrativo_avisos a
            WHERE a.ativo = TRUE
              AND (a.expira_em IS NULL OR a.expira_em > NOW())
              AND (
                    a.modo_destino = 'todos'
                    OR EXISTS (
                        SELECT 1
                        FROM administrativo_avisos_usuarios au
                        WHERE au.aviso_id = a.id
                          AND au.usuario_id = :usuario_id
                    )
              )
              AND (
                    a.visualizacao_unica = FALSE
                    OR NOT EXISTS (
                        SELECT 1
                        FROM administrativo_avisos_visualizacoes av
                        WHERE av.aviso_id = a.id
                          AND av.usuario_id = :usuario_id
                    )
              )
            ORDER BY a.criado_em DESC, a.id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':usuario_id' => $usuarioId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('adminAvisosBuscarDetalheDashboard')) {
    function adminAvisosBuscarDetalheDashboard(PDO $pdo, int $avisoId, int $usuarioId): ?array
    {
        if (!adminAvisosUsuarioPodeVer($pdo, $avisoId, $usuarioId)) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT a.id,
                   a.assunto,
                   a.conteudo_html,
                   a.visualizacao_unica,
                   a.expira_em,
                   a.criado_em,
                   u.nome AS criador_nome
            FROM administrativo_avisos a
            LEFT JOIN usuarios u ON u.id = a.criado_por_usuario_id
            WHERE a.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $avisoId]);
        $aviso = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return $aviso ?: null;
    }
}

if (!function_exists('adminAvisosRegistrarVisualizacao')) {
    function adminAvisosRegistrarVisualizacao(PDO $pdo, int $avisoId, int $usuarioId): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO administrativo_avisos_visualizacoes (
                aviso_id, usuario_id, visualizado_em, ultima_visualizacao_em, total_visualizacoes
            ) VALUES (
                :aviso_id, :usuario_id, NOW(), NOW(), 1
            )
            ON CONFLICT (aviso_id, usuario_id)
            DO UPDATE SET
                ultima_visualizacao_em = NOW(),
                total_visualizacoes = administrativo_avisos_visualizacoes.total_visualizacoes + 1
        ");
        $stmt->execute([
            ':aviso_id' => $avisoId,
            ':usuario_id' => $usuarioId,
        ]);
    }
}

if (!function_exists('adminAvisosBuscarHistorico')) {
    function adminAvisosBuscarHistorico(PDO $pdo, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $pdo->query("
            SELECT a.*,
                   u.nome AS criador_nome,
                   CASE
                       WHEN a.modo_destino = 'todos' THEN (
                           SELECT COUNT(*)
                           FROM usuarios uu
                           WHERE uu.ativo IS DISTINCT FROM FALSE
                       )
                       ELSE (
                           SELECT COUNT(*)
                           FROM administrativo_avisos_usuarios au
                           WHERE au.aviso_id = a.id
                       )
                   END AS total_destinatarios,
                   (
                       SELECT COUNT(*)
                       FROM administrativo_avisos_visualizacoes av
                       WHERE av.aviso_id = a.id
                   ) AS total_visualizados
            FROM administrativo_avisos a
            LEFT JOIN usuarios u ON u.id = a.criado_por_usuario_id
            ORDER BY a.criado_em DESC, a.id DESC
            LIMIT {$limit}
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('adminAvisosBuscarDestinatariosPorAviso')) {
    function adminAvisosBuscarDestinatariosPorAviso(PDO $pdo, array $avisoIds): array
    {
        $avisoIds = adminAvisosNormalizarIds($avisoIds);
        if (empty($avisoIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($avisoIds), '?'));
        $stmt = $pdo->prepare("
            SELECT au.aviso_id, u.id AS usuario_id, u.nome, u.email
            FROM administrativo_avisos_usuarios au
            JOIN usuarios u ON u.id = au.usuario_id
            WHERE au.aviso_id IN ({$placeholders})
            ORDER BY u.nome ASC
        ");
        $stmt->execute($avisoIds);

        $resultado = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $resultado[(int)$row['aviso_id']][] = $row;
        }

        return $resultado;
    }
}

if (!function_exists('adminAvisosBuscarVisualizacoesPorAviso')) {
    function adminAvisosBuscarVisualizacoesPorAviso(PDO $pdo, array $avisoIds): array
    {
        $avisoIds = adminAvisosNormalizarIds($avisoIds);
        if (empty($avisoIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($avisoIds), '?'));
        $stmt = $pdo->prepare("
            SELECT av.aviso_id,
                   av.usuario_id,
                   av.visualizado_em,
                   av.ultima_visualizacao_em,
                   av.total_visualizacoes,
                   u.nome,
                   u.email
            FROM administrativo_avisos_visualizacoes av
            JOIN usuarios u ON u.id = av.usuario_id
            WHERE av.aviso_id IN ({$placeholders})
            ORDER BY av.visualizado_em DESC
        ");
        $stmt->execute($avisoIds);

        $resultado = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $resultado[(int)$row['aviso_id']][] = $row;
        }

        return $resultado;
    }
}

if (!function_exists('adminAvisosResumoHtml')) {
    function adminAvisosResumoHtml(string $html, int $limite = 180): string
    {
        $texto = trim(strip_tags($html));
        if ($texto === '') {
            return '';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($texto, 0, $limite, '...');
        }

        if (strlen($texto) <= $limite) {
            return $texto;
        }

        return substr($texto, 0, max(0, $limite - 3)) . '...';
    }
}
