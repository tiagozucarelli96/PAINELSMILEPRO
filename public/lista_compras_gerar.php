<?php
// lista_compras_gerar.php (esqueleto)
session_start();
@include_once __DIR__.'/conexao.php';
if (!isset($pdo)) { die('Sem conexão.'); }

// Configurações
require_once __DIR__ . '/lc_config_helper.php';
$precQ = (int)lc_get_config($pdo, 'precisao_quantidade', 3);
$precV = (int)lc_get_config($pdo, 'precisao_valor', 2);
$showCPP = lc_get_config($pdo, 'mostrar_custo_previa', '1') === '1';

// Função para arredondar por embalagem
function round_pack(float $qtd, float $pack = 1.0): float {
  if ($pack === null || $pack <= 1) return ceil($qtd); // default: arredonda pra cima em unidade
  return ceil($qtd / $pack) * $pack;
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

  // === Itens FIXOS: 1x por evento ===
  foreach ($ITENS_FIXOS as $fx) {
    // Quantidade líquida em unidade da linha
    $qtdLinha = (float)$fx['qtd'];

    // Converter para a unidade do insumo
    $qtdInsumo = lc_convert_to_insumo_unit_local($qtdLinha, (float)$fx['linha_fator'], (float)$fx['insumo_fator']);

    // Aplicar custo (preço × FC do insumo)
    $precoUsado = (float)$fx['custo_corrigido'];
    $custo = $qtdInsumo * $precoUsado;

    // Consolidar em COMPRAS (insumo na unidade do insumo)
    $k = $fx['insumo_nome'].'|'.$fx['insumo_simbolo'];
    if (!isset($OUT_COMPRAS[$k])) {
      $OUT_COMPRAS[$k] = [
        'insumo_nome'     => $fx['insumo_nome'],
        'unidade_simbolo' => $fx['insumo_simbolo'],
        'qtd'             => 0.0,
        'custo'           => 0.0
      ];
    }
    $OUT_COMPRAS[$k]['qtd']   += $qtdInsumo;
    $OUT_COMPRAS[$k]['custo'] += $custo;
  }
}

// mapa de múltiplos por nome do item (ajuste para ID se tiver)
$MULTIPLOS = [];
$stmt = $pdo->query("SELECT id, nome, embalagem_multiplo FROM lc_insumos WHERE ativo = true");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
  if (!empty($r['embalagem_multiplo'])) {
    $MULTIPLOS[ $r['nome'] ] = (float)$r['embalagem_multiplo'];
  }
}

// Itens fixos ativos (1x por evento)
$ITENS_FIXOS = $pdo->query("
  SELECT f.*, i.nome AS insumo_nome, i.unidade_id AS insumo_unidade_id,
         i.preco, i.fator_correcao, (i.preco * i.fator_correcao) AS custo_corrigido,
         uLinha.simbolo AS linha_simbolo, uLinha.fator_base AS linha_fator,
         uInsumo.simbolo AS insumo_simbolo, uInsumo.fator_base AS insumo_fator
  FROM lc_itens_fixos f
  JOIN lc_insumos i ON i.id = f.insumo_id
  JOIN lc_unidades uLinha ON uLinha.id = f.unidade_id
  JOIN lc_unidades uInsumo ON uInsumo.id = i.unidade_id
  WHERE f.ativo = true AND i.ativo = true
")->fetchAll(PDO::FETCH_ASSOC);

// Função de conversão
function lc_convert_to_insumo_unit_local(float $qtd, float $fatorLinha, float $fatorInsumo): float {
  if ($fatorLinha <= 0 || $fatorInsumo <= 0) return 0.0;
  return $qtd * ($fatorLinha / $fatorInsumo);
}

// === ARREDONDAMENTO DE ENCOMENDAS POR EMBALAGEM ===
// além de arredondar qtd e reajustar custo, vamos marcar 'foi_arredondado'
if (!empty($OUT_ENCOMENDAS)) {
  foreach ($OUT_ENCOMENDAS as &$row) {
    $nome  = (string)$row['insumo_nome'];
    $pack  = $MULTIPLOS[$nome] ?? null;

    // por padrão, não arredondado
    $row['foi_arredondado'] = false;

    if ($pack && $pack > 0) {
      $qtdOld = (float)$row['qtd'];
      $qtdNew = round_pack($qtdOld, (float)$pack);

      if ($qtdNew > $qtdOld + 1e-9) {
        $row['foi_arredondado'] = true;
      }

      $row['qtd'] = $qtdNew;

      // custo proporcional pela mesma base unitária
      $unit = $qtdOld > 0 ? ((float)$row['custo'] / $qtdOld) : 0;
      $row['custo'] = $unit * (float)$row['qtd'];
    }
  }
  unset($row);
}

// ==== SALVAR EM BANCO (BEGIN → INSERTS → COMMIT) ====

// 1) Cabeçalho (lc_listas)
$usuarioId = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;

// montar resumos simples a partir dos eventos selecionados (ajuste aos seus campos reais)
$nomesEspacos = [];
$resumosEventos = [];
foreach ($eventosSelecionados as $ev) {
  if (!empty($ev['espaco'])) $nomesEspacos[] = trim($ev['espaco']);
  $resumosEventos[] = trim(
    ($ev['data'] ?? '') . ' ' . ($ev['hora'] ?? '') . ' ' . ($ev['titulo'] ?? ('Evento #'.$ev['id']))
  );
}
$espacoResumo = 'Múltiplos';
$unicos = array_values(array_unique(array_filter($nomesEspacos)));
if (count($unicos) === 1) $espacoResumo = $unicos[0];

$resumoEventosTxt = implode(" | ", array_slice($resumosEventos, 0, 6)); // resume primeiros 6

try {
  $pdo->beginTransaction();

  // Cabeçalho único da geração
  $st = $pdo->prepare("INSERT INTO lc_listas (tipo_lista, criado_por, resumo_eventos, espaco_resumo)
                       VALUES ('compras', :u, :r, :e)
                       RETURNING id");
  $st->execute([
    ':u' => $usuarioId,
    ':r' => $resumoEventosTxt,
    ':e' => $espacoResumo
  ]);
  $listaId = (int)$st->fetchColumn();

  // 2) Vínculo dos eventos (lc_listas_eventos)
  $stEv = $pdo->prepare("INSERT INTO lc_listas_eventos (lista_id, evento_id, convidados, data_evento, resumo)
                         VALUES (:l,:ev,:c,:d,:res)");
  foreach ($eventosSelecionados as $ev) {
    $stEv->execute([
      ':l'   => $listaId,
      ':ev'  => (int)($ev['id'] ?? 0),
      ':c'   => (int)($ev['convidados'] ?? 0),
      ':d'   => !empty($ev['data']) ? $ev['data'] : null,
      ':res' => trim(($ev['hora'] ?? '') . ' ' . ($ev['titulo'] ?? ''))
    ]);
  }

  // 3) COMPRAS consolidadas
  if (!empty($OUT_COMPRAS)) {
    $stC = $pdo->prepare("INSERT INTO lc_compras_consolidadas (lista_id, insumo_nome, unidade_simbolo, qtd, custo)
                          VALUES (:l,:n,:u,:q,:c)");
    foreach ($OUT_COMPRAS as $row) {
      $stC->execute([
        ':l' => $listaId,
        ':n' => (string)$row['insumo_nome'],
        ':u' => (string)$row['unidade_simbolo'],
        ':q' => (float)$row['qtd'],
        ':c' => (float)$row['custo']
      ]);
    }
  }

  // 4) ENCOMENDAS (Fornecedor → Evento)
  if (!empty($OUT_ENCOMENDAS)) {
    $stE = $pdo->prepare("
      INSERT INTO lc_encomendas_itens
      (lista_id, fornecedor_id, evento_id, item_nome, unidade_simbolo, qtd, custo, foi_arredondado)
      VALUES (:l,:f,:ev,:n,:u,:q,:c,:fa)
    ");
    foreach ($OUT_ENCOMENDAS as $row) {
      $stE->execute([
        ':l'  => $listaId,
        ':f'  => isset($row['fornecedor_id']) ? (int)$row['fornecedor_id'] : null,
        ':ev' => (int)$row['evento_id'],
        ':n'  => (string)$row['insumo_nome'],
        ':u'  => (string)$row['unidade_simbolo'],
        ':q'  => (float)$row['qtd'],
        ':c'  => (float)$row['custo'],
        ':fa' => !empty($row['foi_arredondado'])
      ]);
    }
  }

  $pdo->commit();

  // Feedback visual mínimo (mantenha seu layout)
  echo '<div class="msg">Lista salva com sucesso. Nº ' . (int)$listaId . '</div>';

} catch (Exception $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo '<div class="err">Erro ao salvar a lista: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
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
        <td><?= number_format((float)$row['qtd'], $precQ, ',', '.') ?></td>
        <td><?= htmlspecialchars($row['unidade_simbolo']) ?></td>
        <td>R$ <?= number_format((float)$row['custo'], $precV, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
  <?php else: ?>
    <tr><td colspan="4">Nenhum item para Compras.</td></tr>
  <?php endif; ?>
  </tbody>
  <tfoot>
    <tr>
      <th colspan="3" style="text-align:right">Total Compras</th>
      <th>R$ <?= number_format($totalCompras, $precV, ',', '.') ?></th>
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
              <td>R$ <?= number_format((float)$row['custo'], $precV, ',', '.') ?></td>
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
        <th>R$ <?= number_format($totalEncomendas, $precV, ',', '.') ?></th>
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

<?php if ($showCPP): ?>
<?php
// === Custo por convidado (prévia) ===
$totalConvidados = 0;
foreach ($eventosSelecionados as $ev) {
  $totalConvidados += (int)($ev['convidados'] ?? 0);
}
$custoPorConvidado = ($totalConvidados > 0) ? ($TOTAL_GERAL / $totalConvidados) : 0;
?>
<h2>Resumo por convidado</h2>
<table>
  <tbody>
    <tr><td>Total de convidados</td><td><?= (int)$totalConvidados ?></td></tr>
    <tr><td>Custo por convidado</td><td>R$ <?= number_format($custoPorConvidado, $precV, ',', '.') ?></td></tr>
  </tbody>
</table>
<?php endif; ?>

<?php
// === RESUMO GERAL ===
$TOTAL_GERAL = $totalCompras + $totalEncomendas;
?>
<h2>Resumo Geral</h2>
<table>
  <tbody>
    <tr><td>Total Compras</td><td>R$ <?= number_format($totalCompras, $precV, ',', '.') ?></td></tr>
    <tr><td>Total Encomendas</td><td>R$ <?= number_format($totalEncomendas, $precV, ',', '.') ?></td></tr>
    <tr><th>Total Geral</th><th>R$ <?= number_format($TOTAL_GERAL, $precV, ',', '.') ?></th></tr>
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
