<?php
// public/permissoes_boot.php — popula $_SESSION['perm_*'] após login
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['logado']) || empty($_SESSION['id'])) { return; }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

function truthy($v): bool {
  if (is_bool($v)) return $v;
  $s = strtolower((string)$v);
  return $s==='1' || $s==='t' || $s==='true' || $s==='on' || $s==='y' || $s==='yes';
}

try {
  // pega o registro do usuário atual
  $st = $pdo->prepare("select * from usuarios where id = :id limit 1");
  $st->execute([':id' => (int)$_SESSION['id']]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) return;

  // chaves que seu dashboard2 espera
  $permKeys = [
    // Permissões existentes
    'perm_tarefas','perm_lista','perm_demandas','perm_pagamentos','perm_usuarios',
    'perm_portao','perm_banco_smile','perm_banco_smile_admin','perm_notas_fiscais',
    'perm_estoque_logistico','perm_dados_contrato','perm_uso_fiorino',
    // Novas permissões para módulos da sidebar
    'perm_agenda','perm_comercial','perm_logistico','perm_configuracoes',
    'perm_cadastros','perm_financeiro','perm_administrativo','perm_rh'
  ];

  // zera e preenche a partir das colunas (se existirem)
  $any = false;
  foreach ($permKeys as $k) {
    $_SESSION[$k] = array_key_exists($k,$u) ? truthy($u[$k]) : false;
    $any = $any || $_SESSION[$k];
  }

  // fallback: se não veio nenhuma perm, mas for “admin”, libera tudo
  $looksAdmin = truthy($u['is_admin'] ?? false) || truthy($u['admin'] ?? false)
             || (($u['login'] ?? $u['usuario'] ?? '') === 'admin')
             || ((int)($u['id'] ?? 0) === 1);
  if (!$any && $looksAdmin) {
    foreach ($permKeys as $k) $_SESSION[$k] = true;
  }
} catch (Throwable $e) {
  // silencioso em produção
}
