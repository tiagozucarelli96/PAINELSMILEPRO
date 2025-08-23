<?php
// lista_compras_gerar.php (esqueleto)
session_start();
@include_once __DIR__.'/conexao.php';
if (!isset($pdo)) { die('Sem conexão.'); }

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
