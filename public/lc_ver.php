<?php
// lc_ver.php — detalhe de um grupo (Compras + Encomendas)
require_once __DIR__.'/conexao.php';
header('Content-Type: text/html; charset=utf-8');

$grupo = isset($_GET['grupo']) ? (int)$_GET['grupo'] : 0;
if ($grupo <= 0) { http_response_code(400); echo "grupo inválido"; exit; }

try {
  $pdo = getPdo();

  // Cabeçalhos do grupo (devem existir 2 linhas: compras e encomendas)
  $cab = $pdo->prepare("
    SELECT grupo_id, tipo, COALESCE(espaco_consolidado,'') AS espaco, 
           COALESCE(eventos_resumo,'') AS eventos, COALESCE(criado_por,'') AS criado_por,
           COALESCE(created_at, NOW()) AS dt
    FROM lc_listas
    WHERE grupo_id = :g
    ORDER BY tipo ASC
  ");
  $cab->execute([':g'=>$grupo]);
  $cabecalhos = $cab->fetchAll();

  // Compras (insumos)
  $compras = $pdo->prepare("
    SELECT insumo_nome, unidade, SUM(quantidade) AS qtd
    FROM lc_compras_consolidadas
    WHERE grupo_id = :g
    GROUP BY insumo_nome, unidade
    ORDER BY insumo_nome
  ");
  $compras->execute([':g'=>$grupo]);
  $linhas_compras = $compras->fetchAll();

  // Encomendas (por fornecedor e possivelmente por evento)
  $encomendas = $pdo->prepare("
    SELECT COALESCE(f.nome,'(Fornecedor)') AS fornecedor,
           COALESCE(e.evento_label,'')    AS evento,
           i.item_nome,
           SUM(i.quantidade)              AS qtd,
           COALESCE(i.unidade,'')         AS unidade
    FROM lc_encomendas_itens i
    LEFT JOIN fornecedores f ON f.id = i.fornecedor_id
    LEFT JOIN lc_listas_eventos e ON e.id = i.lista_evento_id
    WHERE i.grupo_id = :g
    GROUP BY fornecedor, evento, item_nome, unidade
    ORDER BY fornecedor, evento, item_nome
  ");
  $encomendas->execute([':g'=>$grupo]);
  $linhas_encomendas = $encomendas->fetchAll();

} catch (Throwable $e) {
  $erro = $e->getMessage();
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Grupo #<?=htmlspecialchars($grupo)?> – Detalhe</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:16px;color:#0f172a}
    h1{font-size:20px;margin:0 0 8px}
    h2{font-size:16px;margin:20px 0 8px}
    .muted{color:#64748b}
    table{width:100%;border-collapse:collapse;margin-top:8px}
    th,td{padding:8px;border-bottom:1px solid #e5e7eb;font-size:14px;vertical-align:top}
    th{background:#f8fafc;text-align:left}
    .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #cbd5e1;font-size:12px}
    a.btn{padding:6px 10px;border:1px solid #0ea5e9;border-radius:6px;text-decoration:none}
  </style>
</head>
<body>
  <div class="row">
    <h1>Grupo <span class="badge">#<?=htmlspecialchars($grupo)?></span></h1>
    <a class="btn" href="lc_index.php">voltar</a>
  </div>

  <?php if(isset($erro)): ?>
    <p style="color:#dc2626">Erro: <?=htmlspecialchars($erro)?></p>
  <?php endif; ?>

  <?php if(!empty($cabecalhos)): ?>
    <h2>Resumo</h2>
    <table>
      <thead>
        <tr><th>Tipo</th><th>Espaço</th><th>Eventos</th><th>Criado por</th><th>Data</th></tr>
      </thead>
      <tbody>
        <?php foreach($cabecalhos as $r): ?>
          <tr>
            <td><?=htmlspecialchars($r['tipo'])?></td>
            <td><?=htmlspecialchars($r['espaco'])?></td>
            <td><?=htmlspecialchars($r['eventos'])?></td>
            <td><?=htmlspecialchars($r['criado_por'])?></td>
            <td><span class="muted"><?=htmlspecialchars($r['dt'])?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>Lista de Compras (insumos)</h2>
  <table>
    <thead>
      <tr><th>Insumo</th><th>Unidade</th><th>Quantidade</th></tr>
    </thead>
    <tbody>
      <?php if(empty($linhas_compras)): ?>
        <tr><td colspan="3" class="muted">Sem itens de compras.</td></tr>
      <?php else: foreach($linhas_compras as $c): ?>
        <tr>
          <td><?=htmlspecialchars($c['insumo_nome'])?></td>
          <td><?=htmlspecialchars($c['unidade'])?></td>
          <td><?=htmlspecialchars((string)$c['qtd'])?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <h2>Encomendas</h2>
  <table>
    <thead>
      <tr><th>Fornecedor</th><th>Evento</th><th>Item</th><th>Unidade</th><th>Quantidade</th></tr>
    </thead>
    <tbody>
      <?php if(empty($linhas_encomendas)): ?>
        <tr><td colspan="5" class="muted">Sem itens de encomenda.</td></tr>
      <?php else: foreach($linhas_encomendas as $e): ?>
        <tr>
          <td><?=htmlspecialchars($e['fornecedor'])?></td>
          <td><?=htmlspecialchars($e['evento'])?></td>
          <td><?=htmlspecialchars($e['item_nome'])?></td>
          <td><?=htmlspecialchars($e['unidade'])?></td>
          <td><?=htmlspecialchars((string)$e['qtd'])?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</body>
</html>
