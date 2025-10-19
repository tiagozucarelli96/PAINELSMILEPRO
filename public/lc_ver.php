<?php
// public/lc_ver.php
// Visualiza uma lista gerada (compras ou encomendas)

session_start();
require_once __DIR__ . '/conexao.php';

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'compras'; // compras|encomendas

if ($id <= 0) {
  echo "Lista inválida.";
  exit;
}

// Cabeçalho
$stmt = $pdo->prepare("
  SELECT l.*, u.nome AS criado_por_nome
  FROM lc_listas l
  LEFT JOIN usuarios u ON u.id = l.criado_por
  WHERE l.id = :id
");
$stmt->execute([':id' => $id]);
$lista = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lista) {
  echo "Lista não encontrada.";
  exit;
}

// Eventos vinculados
$stmtEv = $pdo->prepare("SELECT * FROM lc_listas_eventos WHERE lista_id = :id ORDER BY id");
$stmtEv->execute([':id' => $id]);
$eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);

// Itens (dependendo do tipo)
if ($tipo === 'encomendas') {
  $stmtItens = $pdo->prepare("
    SELECT e.*, f.nome AS fornecedor_nome
    FROM lc_encomendas_itens e
    LEFT JOIN fornecedores f ON f.id = e.fornecedor_id
    WHERE e.lista_id = :id
    ORDER BY f.nome NULLS LAST, e.evento_id, e.item_nome
  ");
} else {
  $stmtItens = $pdo->prepare("
    SELECT * FROM lc_compras_consolidadas
    WHERE lista_id = :id
    ORDER BY insumo_nome
  ");
}
$stmtItens->execute([':id' => $id]);
$itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt($s){ return $s ? date('d/m/Y H:i', strtotime($s)) : ''; }

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Lista #<?= (int)$id ?> | Painel Smile PRO</title>
  <link rel="stylesheet" href="estilo.css">
  <style>
    body { padding: 20px; font-family: Arial, sans-serif; }
    h1,h2,h3 { margin-top: 20px; color:#004080; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    th,td { border:1px solid #ccc; padding:6px 8px; text-align:left; }
    th { background:#f2f6ff; }
    .badge { padding:2px 6px; background:#eef; border:1px solid #ccd; border-radius:8px; font-size:12px; }
  </style>
</head>
<body>
  <h1>Lista #<?= (int)$id ?> (<?= strtoupper($tipo) ?>)</h1>

  <h3>Informações gerais</h3>
  <table>
    <tbody>
      <tr><td><strong>Data gerada:</strong></td><td><?= h(dt($lista['criado_em'])) ?></td></tr>
      <tr><td><strong>Espaço:</strong></td><td><?= h($lista['espaco_resumo'] ?: 'Múltiplos') ?></td></tr>
      <tr><td><strong>Eventos:</strong></td><td><?= h($lista['resumo_eventos']) ?></td></tr>
      <tr><td><strong>Criado por:</strong></td><td><?= h($lista['criado_por_nome'] ?: ('#'.$lista['criado_por'])) ?></td></tr>
    </tbody>
  </table>

  <?php if ($eventos): ?>
    <h3>Eventos vinculados</h3>
    <table>
      <thead><tr><th>#</th><th>Evento</th><th>Convidados</th><th>Data</th><th>Resumo</th></tr></thead>
      <tbody>
        <?php foreach ($eventos as $ev): ?>
          <tr>
            <td><?= (int)$ev['evento_id'] ?></td>
            <td>Evento #<?= (int)$ev['evento_id'] ?></td>
            <td><?= (int)$ev['convidados'] ?></td>
            <td><?= h($ev['data_evento']) ?></td>
            <td><?= h($ev['resumo']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php
  // === Resumo financeiro e por convidado (on-the-fly) ===
  // Soma custos em ambas as saídas:
  $totCompras = 0; $totEncom = 0;

  // compras
  $stTmp = $pdo->prepare("SELECT COALESCE(SUM(custo),0) FROM lc_compras_consolidadas WHERE lista_id = :id");
  $stTmp->execute([':id'=>$id]);
  $totCompras = (float)$stTmp->fetchColumn();

  // encomendas
  $stTmp = $pdo->prepare("SELECT COALESCE(SUM(custo),0) FROM lc_encomendas_itens WHERE lista_id = :id");
  $stTmp->execute([':id'=>$id]);
  $totEncom = (float)$stTmp->fetchColumn();

  $totGeral = $totCompras + $totEncom;

  // total convidados (somando todos os eventos vinculados)
  $stTmp = $pdo->prepare("SELECT COALESCE(SUM(convidados),0) FROM lc_listas_eventos WHERE lista_id = :id");
  $stTmp->execute([':id'=>$id]);
  $totConvidados = (int)$stTmp->fetchColumn();

  $custoPorConvidado = $totConvidados > 0 ? ($totGeral / $totConvidados) : 0;
  ?>
  <h3>Resumo financeiro</h3>
  <table>
    <tbody>
      <tr><td>Total Compras</td><td>R$ <?= number_format($totCompras, 2, ',', '.') ?></td></tr>
      <tr><td>Total Encomendas</td><td>R$ <?= number_format($totEncom, 2, ',', '.') ?></td></tr>
      <tr><th>Total Geral</th><th>R$ <?= number_format($totGeral, 2, ',', '.') ?></th></tr>
      <tr><td>Total de convidados</td><td><?= (int)$totConvidados ?></td></tr>
      <tr><td><strong>Custo por convidado</strong></td><td><strong>R$ <?= number_format($custoPorConvidado, 2, ',', '.') ?></strong></td></tr>
    </tbody>
  </table>

  <?php if ($tipo === 'compras'): ?>
    <h3>Itens — Compras (internas)</h3>
    <table>
      <thead><tr><th>Insumo</th><th>Quantidade</th><th>Unidade</th><th>Custo</th></tr></thead>
      <tbody>
        <?php
        $tot = 0;
        foreach ($itens as $i):
          $tot += (float)$i['custo'];
        ?>
          <tr>
            <td><?= h($i['insumo_nome']) ?></td>
            <td><?= number_format((float)$i['qtd'], 3, ',', '.') ?></td>
            <td><?= h($i['unidade_simbolo']) ?></td>
            <td>R$ <?= number_format((float)$i['custo'], 2, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="3" style="text-align:right">Total</th><th>R$ <?= number_format($tot, 2, ',', '.') ?></th></tr>
      </tfoot>
    </table>

  <?php else: ?>
    <h3>Itens — Encomendas (Fornecedor → Evento)</h3>
    
    <!-- Ações rápidas (geral) -->
    <div style="margin:10px 0; display:flex; gap:10px; flex-wrap:wrap;">
      <button type="button" onclick="copyText(`<?= htmlspecialchars($txtAll, ENT_QUOTES, 'UTF-8') ?>`)">
        Copiar texto (todos fornecedores)
      </button>
      <a href="<?= htmlspecialchars($waAll, ENT_QUOTES, 'UTF-8') ?>" target="_blank">
        Enviar via WhatsApp (todos)
      </a>
    </div>
    
    <?php
    $grp = [];
    $tot = 0;
    foreach ($itens as $r) {
      $forn = $r['fornecedor_nome'] ?: 'Sem fornecedor';
      $grp[$forn][$r['evento_id']][] = $r;
      $tot += (float)$r['custo'];
    }
    ?>
    
    <?php
    // === MONTA TEXTOS (WhatsApp) ===
    $whatsAll = [];           // linhas gerais (todos os fornecedores)
    $whatsByForn = [];        // texto por fornecedor

    $headerTxt  = "Grupo Smile Eventos\n";
    $headerTxt .= "Lista de Encomendas #{$lista['id']}\n";
    $headerTxt .= "Gerada em: ".date('d/m/Y H:i', strtotime($lista['criado_em']))."\n";
    $headerTxt .= "Espaço: ".($lista['espaco_resumo'] ?: 'Múltiplos')."\n";
    $headerTxt .= "Eventos: ".$lista['resumo_eventos']."\n";

    $whatsAll[] = $headerTxt;

    foreach ($grp as $fornNome => $evs) {
      $lines = [];
      $lines[] = "Fornecedor: ".($fornNome ?: 'Sem fornecedor');

      foreach ($evs as $eventoId => $rows) {
        $lines[] = "- Evento #{$eventoId}";
        foreach ($rows as $r) {
          $q = number_format((float)$r['qtd'], 3, ',', '.');
          $lines[] = "  • {$r['item_nome']} — {$q} {$r['unidade_simbolo']}";
        }
      }

      $whatsByForn[$fornNome] = implode("\n", array_merge([$headerTxt], $lines));
      $whatsAll[] = implode("\n", $lines);
    }

    // Texto "geral" (todos fornecedores) + URL encoded para link do WhatsApp
    $txtAll = implode("\n\n", $whatsAll);
    $waAll  = 'https://wa.me/?text='.urlencode($txtAll);

    // Texto por fornecedor (URL encoded)
    $waByForn = [];
    foreach ($whatsByForn as $fn => $t) {
      $waByForn[$fn] = 'https://wa.me/?text='.urlencode($t);
    }
    ?>
    
    <?php foreach ($grp as $fornNome => $evs): ?>
      <h4>Fornecedor: <?= h($fornNome) ?></h4>
      <?php
      $waF = $waByForn[$fornNome] ?? '';
      $txF = $whatsByForn[$fornNome] ?? '';
      ?>
      <div style="margin:6px 0; display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" onclick="copyText(`<?= htmlspecialchars($txF, ENT_QUOTES, 'UTF-8') ?>`)">
          Copiar texto (<?= h($fornNome ?: 'Sem fornecedor') ?>)
        </button>
        <?php if ($waF): ?>
          <a href="<?= htmlspecialchars($waF, ENT_QUOTES, 'UTF-8') ?>" target="_blank">
            WhatsApp (<?= h($fornNome ?: 'Sem fornecedor') ?>)
          </a>
        <?php endif; ?>
      </div>
      <?php foreach ($evs as $eventoId => $rows): ?>
        <h5>Evento #<?= (int)$eventoId ?></h5>
        <table>
          <thead><tr><th>Item</th><th>Qtd</th><th>Unidade</th><th>Custo</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= h($r['item_nome']) ?></td>
                <td>
                  <?= number_format((float)$r['qtd'], 3, ',', '.') ?>
                  <?php if (!empty($r['foi_arredondado'])): ?>
                    <span class="badge" title="Quantidade arredondada para múltiplo de embalagem">arred.</span>
                  <?php endif; ?>
                </td>
                <td><?= h($r['unidade_simbolo']) ?></td>
                <td>R$ <?= number_format((float)$r['custo'], 2, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <br>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <table>
      <tfoot>
        <tr><th style="text-align:right">Total</th><th>R$ <?= number_format($tot, 2, ',', '.') ?></th></tr>
      </tfoot>
    </table>
  <?php endif; ?>

<script>
function copyText(t) {
  if (!t) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(t).then(function(){
      alert('Texto copiado para a área de transferência.');
    }).catch(function(){
      fallbackCopy(t);
    });
  } else {
    fallbackCopy(t);
  }
}
function fallbackCopy(t) {
  const ta = document.createElement('textarea');
  ta.value = t;
  ta.style.position = 'fixed';
  ta.style.left = '-1000px';
  ta.style.top = '-1000px';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try { document.execCommand('copy'); alert('Texto copiado.'); }
  catch(e){ alert('Não foi possível copiar automaticamente.'); }
  document.body.removeChild(ta);
}
</script>

</body>
</html>
