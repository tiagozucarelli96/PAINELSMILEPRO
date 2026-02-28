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

  // chaves que seu dashboard2 espera - APENAS PERMISSÕES ATIVAS
  $permKeys = [
    // Permissões existentes (ainda em uso)
    'perm_tarefas','perm_lista','perm_demandas','perm_pagamentos','perm_usuarios',
    'perm_portao','perm_banco_smile','perm_banco_smile_admin','perm_notas_fiscais',
    'perm_dados_contrato','perm_uso_fiorino',
    // Logística (novo módulo)
    'perm_superadmin','perm_logistico','perm_logistico_divergencias','perm_logistico_financeiro',
    // Módulos principais da sidebar
    'perm_agenda','perm_comercial','perm_configuracoes',
    'perm_cadastros','perm_financeiro','perm_administrativo',
    // Eventos (Organização)
    'perm_eventos',
    // Eventos (Realizar evento)
    'perm_eventos_realizar',
    // Permissões específicas de Agenda (usadas em agenda_helper.php)
    'perm_agenda_ver','perm_agenda_meus','perm_agenda_relatorios',
    'perm_forcar_conflito','perm_gerir_eventos_outros'
  ];

  // zera e preenche a partir das colunas (se existirem)
  $any = false;
  foreach ($permKeys as $k) {
    $_SESSION[$k] = array_key_exists($k,$u) ? truthy($u[$k]) : false;
    $any = $any || $_SESSION[$k];
  }

  // Compatibilidade: em bases antigas sem coluna própria, Realizar evento segue Eventos.
  if (!array_key_exists('perm_eventos_realizar', $u)) {
    $_SESSION['perm_eventos_realizar'] = $_SESSION['perm_eventos'];
  }

  // Unificação Agenda: trata perm_agenda e perm_agenda_ver como equivalentes.
  // Evita inconsistência entre menu (perm_agenda) e validações legadas (perm_agenda_ver).
  $agendaUnified = truthy($u['perm_agenda'] ?? false) || truthy($u['perm_agenda_ver'] ?? false);
  $_SESSION['perm_agenda'] = $agendaUnified;
  $_SESSION['perm_agenda_ver'] = $agendaUnified;

  // Escopo de unidade (Logística)
  $unidade_scope = array_key_exists('unidade_scope', $u) && !empty($u['unidade_scope'])
    ? (string)$u['unidade_scope']
    : 'nenhuma';

  // Compatibilidade: quando há permissão de Logística mas escopo ficou no default "nenhuma",
  // liberar o escopo para "todas" até existir gestão explícita desse campo no cadastro de usuários.
  $has_logistica_perm = truthy($u['perm_logistico'] ?? false)
    || truthy($u['perm_logistico_divergencias'] ?? false)
    || truthy($u['perm_logistico_financeiro'] ?? false);
  if ($unidade_scope === 'nenhuma' && $has_logistica_perm && !truthy($u['perm_superadmin'] ?? false)) {
    $unidade_scope = 'todas';
  }

  $_SESSION['unidade_scope'] = $unidade_scope;
  $_SESSION['unidade_id'] = array_key_exists('unidade_id', $u) && $u['unidade_id'] !== null && $u['unidade_id'] !== ''
    ? (int)$u['unidade_id']
    : null;

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
