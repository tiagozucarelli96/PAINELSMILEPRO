<?php
// lista_compras_gerar.php (esqueleto)
session_start();
@include_once __DIR__.'/conexao.php';
if (!isset($pdo)) { die('Sem conexão.'); }

// Função para arredondar por embalagem
function round_pack(float $qtd, int $tamanhoEmbalagem = 1): int {
  if ($tamanhoEmbalagem <= 1) return (int)ceil($qtd);
  return (int)(ceil($qtd / $tamanhoEmbalagem) * $tamanhoEmbalagem);
}

// Carrega categorias
$cats = $pdo->query("SELECT id, nome FROM lc_categorias WHERE ativa = TRUE ORDER BY ordem, nome")->fetchAll();

// Busca itens exibíveis por categoria
$getItens = $pdo->prepare("
  SELECT i.id, i.nome, i.tipo, i.ficha_id, i.insumo_id,
         COALESCE(f.nome_exibicao, i.nome) AS nome_card,
         f.exibir_em_categorias
  FROM lc_itens i
  LEFT JOIN lc_fichas f ON f.id = i.ficha_id
  WHERE i.ativo = TRUE AND i.categoria_id = :cid
    AND (
      (i.tipo = 'preparo'  AND f.exibir_em_categorias = TRUE)
      OR (i.tipo = 'comprado')
    )
  ORDER BY i.ordem, i.nome
");

// Agregadores finais (mantidos fora dos loops)
if (!isset($OUT_COMPRAS))   $OUT_COMPRAS   = []; // chave: unidade_simbolo + nome
if (!isset($OUT_ENCOMENDAS))$OUT_ENCOMENDAS= []; // chave: fornecedor_id + item

foreach ($eventosSelecionados as $ev) {
  $convidados = (int)$ev['convidados']; // já deve estar validado
  $evento_id  = (int)$ev['id'];

  foreach ($itensSelecionados as $item) {
    // Se o "item" for um PREPARO com ficha vinculada, você deve ter o $ficha_id aqui
    $ficha_id = (int)$item['ficha_id'];

    if ($ficha_id <= 0) continue;

    $pack = lc_fetch_ficha($pdo, $ficha_id);
    if (!$pack) continue;

    $res = lc_explode_ficha_para_evento($pack, $convidados);

    // === COMPRAS (insumos internos + fixos) → consolidar por (nome + unidade do insumo)
    foreach ($res['compras'] as $row) {
      $k = $row['insumo_nome'].'|'.$row['unidade_simbolo'];
      if (!isset($OUT_COMPRAS[$k])) {
        $OUT_COMPRAS[$k] = [
          'insumo_nome'     => $row['insumo_nome'],
          'unidade_simbolo' => $row['unidade_simbolo'],
          'qtd'             => 0.0,
          'custo'           => 0.0
        ];
      }
      $OUT_COMPRAS[$k]['qtd']   += (float)$row['qtd'];
      $OUT_COMPRAS[$k]['custo'] += (float)$row['custo'];
    }

    // === ENCOMENDAS (itens comprados) → agrupar por Fornecedor → Evento
    foreach ($res['encomendas'] as $row) {
      $forn = $row['fornecedor_id'] ?? 0; // 0 = sem fornecedor definido
      $k = $forn.'|'.$evento_id.'|'.$row['insumo_nome'].'|'.$row['unidade_simbolo'];
      if (!isset($OUT_ENCOMENDAS[$k])) {
        $OUT_ENCOMENDAS[$k] = [
          'evento_id'       => $evento_id,
          'fornecedor_id'   => $row['fornecedor_id'],
          'insumo_nome'     => $row['insumo_nome'],
          'unidade_simbolo' => $row['unidade_simbolo'],
          'qtd'             => 0.0,
          'custo'           => 0.0
        ];
      }
      $OUT_ENCOMENDAS[$k]['qtd']   += (float)$row['qtd'];
      $OUT_ENCOMENDAS[$k]['custo'] += (float)$row['custo'];
    }
  }
}

// === PRÉVIA: COMPRAS (insumos internos + fixos) ===
$totalCompras = 0.0;
?>
<h2>Prévia — Compras (internas)</h2>
<table>
  <thead>
    <tr>
      <th>Insumo</th>
      <th>Qtd</th>
      <th>Unidade</th>
      <th>Custo (estimado)</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!empty($OUT_COMPRAS)): ?>
    <?php foreach ($OUT_COMPRAS as $row): ?>
      <?php $totalCompras += (float)$row['custo']; ?>
      <tr>
        <td><?= htmlspecialchars($row['insumo_nome']) ?></td>
        <td><?= number_format((float)$row['qtd'], 3, ',', '.') ?></td>
        <td><?= htmlspecialchars($row['unidade_simbolo']) ?></td>
        <td>R$ <?= number_format((float)$row['custo'], 2, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
  <?php else: ?>
    <tr><td colspan="4">Nenhum item para Compras.</td></tr>
  <?php endif; ?>
  </tbody>
  <tfoot>
    <tr>
      <th colspan="3" style="text-align:right">Total Compras</th>
      <th>R$ <?= number_format($totalCompras, 2, ',', '.') ?></th>
    </tr>
  </tfoot>
</table>

<?php
// === PRÉVIA: ENCOMENDAS (itens comprados) — agrupado Fornecedor → Evento ===
// Se você tiver tabela de fornecedores, pode buscar o nome aqui.
// Ex.: $FORN_NOMES[$id] = 'Doceira Padrão'
$FORN_NOMES = []; // opcional: preencher com SELECT fornecedores

$totalEncomendas = 0.0;

// Agrupar por fornecedor → evento
$grp = [];
foreach ($OUT_ENCOMENDAS as $k => $r) {
  $forn = $r['fornecedor_id'] ?? 0;
  $ev   = $r['evento_id'];
  $grp[$forn][$ev][] = $r;
  $totalEncomendas += (float)$r['custo'];
}
?>
<h2>Prévia — Encomendas (Fornecedor → Evento)</h2>

<?php if (!empty($grp)): ?>
  <?php foreach ($grp as $fornId => $eventos): ?>
    <h3>Fornecedor: <?= $fornId ? ($FORN_NOMES[$fornId] ?? ('#'.$fornId)) : '— (sem fornecedor)' ?></h3>

    <?php foreach ($eventos as $eventoId => $items): ?>
      <h4>Evento #<?= (int)$eventoId ?></h4>
      <table>
        <thead>
          <tr>
            <th>Item</th>
            <th>Qtd</th>
            <th>Unidade</th>
            <th>Custo (estimado)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $row): ?>
            <?php
            // Arredondar por embalagem (exemplo: coxinha em caixas de 50)
            $pack = 50; // ex.: definido por você (pode vir do cadastro do insumo depois)
            $qtdArred = round_pack($row['qtd'], $pack);
            ?>
            <tr>
              <td><?= htmlspecialchars($row['insumo_nome']) ?></td>
              <td>
                <?= number_format((float)$row['qtd'], 3, ',', '.') ?>
                <?php if ($qtdArred != $row['qtd']): ?>
                  <br><small class="text-blue-600">→ Arredondado: <?= number_format($qtdArred, 0, ',', '.') ?></small>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($row['unidade_simbolo']) ?></td>
              <td>R$ <?= number_format((float)$row['custo'], 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <br>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <table>
    <tfoot>
      <tr>
        <th style="text-align:right">Total Encomendas</th>
        <th>R$ <?= number_format($totalEncomendas, 2, ',', '.') ?></th>
      </tr>
    </tfoot>
  </table>
<?php else: ?>
  <p>Nenhum item para Encomendas.</p>
<?php endif; ?>

<?php
// === RESUMO GERAL ===
$TOTAL_GERAL = $totalCompras + $totalEncomendas;
?>
<h2>Resumo Geral</h2>
<table>
  <tbody>
    <tr><td>Total Compras</td><td>R$ <?= number_format($totalCompras, 2, ',', '.') ?></td></tr>
    <tr><td>Total Encomendas</td><td>R$ <?= number_format($totalEncomendas, 2, ',', '.') ?></td></tr>
    <tr><th>Total Geral</th><th>R$ <?= number_format($TOTAL_GERAL, 2, ',', '.') ?></th></tr>
  </tbody>
</table>

<?php
// A partir daqui, você pode salvar nas tabelas lc_listas, lc_compras_consolidadas, lc_encomendas_itens, etc.
// respeitando o agrupamento Fornecedor → Evento na renderização das encomendas.
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Gerar Lista de Compras</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.1/dist/tailwind.min.css">
<link rel="stylesheet" href="estilo.css">
<style>
  /* Customização para cor primária */
  .bg-primary { background-color: #004AAD; }
  .text-primary { color: #004AAD; }
  .border-primary { border-color: #004AAD; }
  .focus\:ring-primary:focus { --tw-ring-color: #004AAD; }
  .shadow-custom { box-shadow: 0 2px 8px 0 rgba(0,74,173,0.08), 0 1.5px 0 rgba(0,74,173,0.04) inset; }
  .modal-tail { z-index: 50; }
</style>
<script>
async function verFicha(itemId){
  const r = await fetch('xhr_ficha.php?id='+itemId);
  const html = await r.text();
  document.getElementById('modal-body').innerHTML = html;
  document.getElementById('modal').classList.remove('hidden');
  document.getElementById('modal').classList.add('flex');
}
function fecharModal(){
  document.getElementById('modal').classList.add('hidden');
  document.getElementById('modal').classList.remove('flex');
}
</script>
</head>
<body class="bg-gray-50 min-h-screen font-sans text-gray-900">
<div class="max-w-5xl mx-auto px-4 py-8">
  <h2 class="text-3xl font-bold mb-8 text-primary tracking-tight">Gerar Lista de Compras</h2>

  <?php foreach($cats as $c): ?>
    <h3 class="text-xl font-semibold mb-4 mt-10 text-primary"><?=htmlspecialchars($c['nome'])?></h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
    <?php
      $getItens->execute([':cid'=>$c['id']]);
      foreach($getItens as $it):
        $tipo = $it['tipo']; // preparo|comprado
    ?>
      <div class="bg-white border border-gray-200 rounded-2xl p-5 shadow-custom flex flex-col gap-3 transition hover:shadow-lg">
        <h4 class="text-lg font-semibold mb-1 text-gray-900 truncate"><?=htmlspecialchars($it['nome_card'])?></h4>
        <div class="flex gap-2 mb-2">
          <span class="inline-block px-3 py-1 rounded-full border border-primary text-primary bg-blue-50 text-xs font-medium">
            <?= $tipo === 'preparo' ? 'Prato' : 'Comprado' ?>
          </span>
        </div>
        <div class="flex gap-3 items-center mt-auto">
          <?php if($tipo==='preparo'): ?>
            <button type="button"
              class="bg-primary text-white rounded-lg px-4 py-2 shadow-sm hover:bg-blue-800 transition font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-primary"
              onclick="verFicha(<?= (int)$it['id'] ?>)">
              Ver insumos
            </button>
          <?php endif; ?>
          <label class="flex items-center gap-2 text-sm font-medium text-gray-700 cursor-pointer">
            <input type="checkbox" name="itens[]" value="<?= (int)$it['id'] ?>"
              class="accent-primary w-4 h-4 rounded border-gray-300 focus:ring-primary">
            Selecionar
          </label>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<!-- Modal moderno com Tailwind -->
<div id="modal" class="modal-tail fixed inset-0 bg-black/40 hidden items-center justify-center transition z-50" onclick="fecharModal()">
  <div class="bg-white rounded-2xl shadow-2xl max-w-xl w-[95%] p-6 max-h-[80vh] overflow-auto relative animate-fade-in" onclick="event.stopPropagation()">
    <div id="modal-body" class="text-gray-800 text-base">Carregando...</div>
    <div class="text-right mt-6">
      <button type="button"
        class="bg-primary text-white rounded-lg px-5 py-2 shadow-sm hover:bg-blue-800 transition font-semibold text-sm focus:outline-none focus:ring-2 focus:ring-primary"
        onclick="fecharModal()">
        Fechar
      </button>
    </div>
    <button type="button" aria-label="Fechar"
      class="absolute top-3 right-3 text-gray-400 hover:text-primary transition text-xl font-bold"
      onclick="fecharModal()">
      &times;
    </button>
  </div>
</div>
</body>
</html>
