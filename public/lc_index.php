<?php
// lc_index.php — histórico mínimo das listas geradas
require_once __DIR__.'/conexao.php';
header('Content-Type: text/html; charset=utf-8');

try {
  $pdo = getPdo(); // sua função em conexao.php

  // Tenta detectar colunas usuais, mas roda com o que houver
  $sql = "
    SELECT
      grupo_id,
      COALESCE(espaco_consolidado,'') AS espaco,
      COALESCE(eventos_resumo,'')     AS eventos,
      COALESCE(tipo,'')               AS tipo,
      COALESCE(criado_por,'')         AS criado_por,
      COALESCE(created_at, NOW())     AS dt
    FROM lc_listas
    ORDER BY grupo_id DESC, created_at DESC
    LIMIT 100
  ";
  $rows = $pdo->query($sql)->fetchAll();

} catch (Throwable $e) {
  $err = $e->getMessage();
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Histórico – Listas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:20px; color:#0f172a}
    h1{font-size:20px; margin:0 0 12px}
    table{width:100%; border-collapse:collapse}
    th,td{padding:10px; border-bottom:1px solid #e5e7eb; font-size:14px; vertical-align:top}
    th{background:#f8fafc; text-align:left}
    .badge{display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid #cbd5e1}
    .row{display:flex; gap:8px; align-items:center}
    .muted{color:#64748b}
    a.btn{padding:6px 10px; border:1px solid #0ea5e9; border-radius:6px; text-decoration:none}
  </style>
</head>
<body>
  <h1>Últimas listas geradas</h1>

  <?php if(isset($err)): ?>
    <p style="color:#dc2626">Erro: <?=htmlspecialchars($err)?></p>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th># Grupo</th>
        <th>Tipo</th>
        <th>Espaço</th>
        <th>Eventos</th>
        <th>Criado por</th>
        <th>Data</th>
      </tr>
    </thead>
    <tbody>
      <?php if(empty($rows)): ?>
        <tr><td colspan="6" class="muted">Nenhuma lista encontrada.</td></tr>
      <?php else: foreach($rows as $r): ?>
        <tr>
          <td class="row">
            <span class="badge"><?=htmlspecialchars($r['grupo_id'])?></span>
            <a class="btn" href="lc_ver.php?grupo=<?=urlencode($r['grupo_id'])?>">ver</a>
          </td>
          <td><?=htmlspecialchars($r['tipo'])?></td>
          <td><?=htmlspecialchars($r['espaco'])?></td>
          <td><?=htmlspecialchars($r['eventos'])?></td>
          <td><?=htmlspecialchars($r['criado_por'])?></td>
          <td><span class="muted"><?=htmlspecialchars($r['dt'])?></span></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</body>
</html>
