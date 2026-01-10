<?php
// public/lc_config_helper.php
// Funções utilitárias para ler/gravar configurações do módulo

function lc_get_config(PDO $pdo, string $key, $default = null) {
  $st = $pdo->prepare("SELECT valor FROM lc_config WHERE chave = :k");
  $st->execute([':k'=>$key]);
  $v = $st->fetchColumn();
  return ($v === false) ? $default : $v;
}

function lc_set_config(PDO $pdo, string $key, $value): void {
  $st = $pdo->prepare("
    INSERT INTO lc_config (chave, valor)
    VALUES (:k,:v)
    ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor
  ");
  $st->execute([':k'=>$key, ':v'=>(string)$value]);
}

function lc_get_configs(PDO $pdo, array $keys, array $defaults = []): array {
  if (!$keys) return [];
  $in = implode(',', array_fill(0, count($keys), '?'));
  $st = $pdo->prepare("SELECT chave, valor FROM lc_config WHERE chave IN ($in)");
  $st->execute($keys);
  $map = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
  // aplicar defaults
  foreach ($keys as $k) {
    if (!array_key_exists($k, $map)) $map[$k] = $defaults[$k] ?? null;
  }
  return $map;
}
