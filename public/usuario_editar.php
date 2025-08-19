<?php
// public/usuario_editar.php — edição com layout alinhado (flexível ao schema)
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403); echo "Acesso negado."; exit;
}

require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { http_response_code(500); echo "Falha na conexão."; exit; }

// Helpers de schema
function cols(PDO $pdo, string $table): array {
  $st = $pdo->prepare("select column_name from information_schema.columns where table_schema=current_schema() and table_name=:t");
  $st->execute([":t"=>$table]);
  return $st->fetchAll(PDO::FETCH_COLUMN);
}
function hascol(array $cols, string $c): bool { return in_array($c, $cols, true); }
function truthy($v): bool { $s=strtolower((string)$v); return $s==='1'||$s==='t'||$s==='true'||$s==='on'||$s==='y'||$s==='yes'; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$T = 'usuarios';
$C = cols($pdo, $T);

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { header("Location: index.php?page=usuarios"); exit; }

// Descobre colunas “principais”
$colNome   = hascol($C,'nome') ? 'nome' : (hascol($C,'nome_completo') ? 'nome_completo' : (hascol($C,'name') ? 'name' : null));
$loginCands= array_values(array_filter(['loguin','login','usuario','username','user','email'], fn($c)=>hascol($C,$c)));
$colLogin  = $loginCands[0] ?? null;
$colAtivo  = hascol($C,'ativo') ? 'ativo' : (hascol($C,'status') ? 'status' : null);
$colFuncao = hascol($C,'funcao') ? 'funcao' : (hascol($C,'cargo') ? 'cargo' : null);
$colSenhaHash = hascol($C,'senha_hash') ? 'senha_hash' : null;
$colSenhaText = hascol($C,'senha') ? 'senha' : null;

// Permissões conhecidas
$permKeys = array_values(array_filter([
  hascol($C,'perm_usuarios') ? 'perm_usuarios' : null,
  hascol($C,'perm_pagamentos') ? 'perm_pagamentos' : null,
  hascol($C,'perm_tarefas') ? 'perm_tarefas' : null,
  hascol($C,'perm_demandas') ? 'perm_demandas' : null,
  hascol($C,'perm_portao') ? 'perm_portao' : null,
  hascol($C,'perm_banco_smile') ? 'perm_banco_smile' : null,
  hascol($C,'perm_banco_smile_admin') ? 'perm_banco_smile_admin' : null,
  hascol($C,'perm_notas_fiscais') ? 'perm_notas_fiscais' : null,
  hascol($C,'perm_estoque_logistico') ? 'perm_estoque_logistico' : null,
  hascol($C,'perm_dados_contrato') ? 'perm_dados_contrato' : null,
  hascol($C,'perm_uso_fiorino') ? 'perm_uso_fiorino' : null,
]));

// Carrega usuário
$st = $pdo->prepare("select * from {$T} where id=:id limit 1");
$st->execute([':id'=>$id]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { header("Location: index.php?page=usuarios"); exit; }

// POST: salvar
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    $set = []; $bind = [':id'=>$id];

    if ($colNome)  { $set[]="{$colNome}=:nome";   $bind[':nome']  = trim((string)($_POST['nome']  ?? $u[$colNome]  ?? '')); }
    if ($colLogin) { $set[]="{$colLogin}=:login"; $bind[':login'] = trim((string)($_POST['l]()_
