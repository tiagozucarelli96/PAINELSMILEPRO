<?php
// config.php — carrega todas as permissões novas na sessão
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function exigeLogin() {
    if (empty($_SESSION['logado']) || (int)$_SESSION['logado'] !== 1) {
        header('Location: login.php'); exit;
    }
}
function usuarioId(){ return isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : 0; }
function temPerm(string $k){ return !empty($_SESSION[$k]) && (int)$_SESSION[$k] === 1; }

/**
 * Atualiza a sessão com o estado real do banco
 * Inclui as novas chaves:
 *  - perm_lista
 *  - perm_banco_smile
 *  - perm_banco_smile_admin
 *  - perm_notas_fiscais
 *  - perm_estoque_logistico (REMOVIDO: Módulo desativado)
 *  - perm_dados_contrato
 *  - perm_uso_fiorino
 */
function refreshPermissoes(PDO $pdo) {
    $id = usuarioId(); if (!$id) return;

    $sql = "SELECT id, nome, status,
                   perm_tarefas, perm_lista, perm_demandas, perm_pagamentos, perm_usuarios, perm_portao,
                   perm_banco_smile, perm_banco_smile_admin, perm_notas_fiscais,
                   perm_dados_contrato, perm_uso_fiorino // REMOVIDO: perm_estoque_logistico
            FROM usuarios
            WHERE id = :id
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':id' => $id]);

    if ($u = $st->fetch()) {
        $_SESSION['nome'] = $u['nome'] ?? '';

        // lista completa de chaves que o dashboard usa
        $keys = [
            'perm_tarefas','perm_lista','perm_demandas','perm_pagamentos','perm_usuarios','perm_portao',
            'perm_banco_smile','perm_banco_smile_admin','perm_notas_fiscais',
            'perm_dados_contrato','perm_uso_fiorino' // REMOVIDO: 'perm_estoque_logistico'
        ];
        foreach ($keys as $k) {
            $_SESSION[$k] = (int)($u[$k] ?? 0);
        }

        // status textual → ativo (1/0)
        $status = strtolower(trim((string)($u['status'] ?? 'ativo')));
        $_SESSION['ativo'] = ($status === 'ativo') ? 1 : 0;

        if ($_SESSION['ativo'] !== 1) {
            session_destroy();
            header('Location: login.php?erro=desativado'); exit;
        }
    }
}
