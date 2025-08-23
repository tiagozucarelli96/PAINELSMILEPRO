<?php
// xhr_ficha.php
session_start();
@include_once __DIR__.'/conexao.php';
if (!isset($pdo)) { http_response_code(500); exit('Sem conexão.'); }

$itemId = (int)($_GET['id'] ?? 0);
if ($itemId<=0){ exit('Item inválido'); }

// pega a ficha a partir do item
$it = $pdo->prepare("SELECT ficha_id FROM lc_itens WHERE id=:id AND tipo='preparo' LIMIT 1");
$it->execute([':id'=>$itemId]);
$row = $it->fetch();
if(!$row || !$row['ficha_id']){ exit('Sem ficha'); }
$fichaId = (int)$row['ficha_id'];

$ficha = $pdo->prepare("SELECT nome_exibicao, rendimento_base_pessoas FROM lc_fichas WHERE id=:id");
$ficha->execute([':id'=>$fichaId]);
$fx = $ficha->fetch();
if(!$fx){ exit('Ficha não encontrada'); }

$comp = $pdo->prepare("
  SELECT fc.quantidade, COALESCE(fc.unidade,'') AS und,
         i.nome AS insumo_nome, sf.nome_exibicao AS sub_nome,
         fc.insumo_id, fc.sub_ficha_id
  FROM lc_ficha_componentes fc
  LEFT JOIN lc_insumos i ON i.id = fc.insumo_id
  LEFT JOIN lc_fichas  sf ON sf.id = fc.sub_ficha_id
  WHERE fc.ficha_id = :fid
  ORDER BY fc.id
");
$comp->execute([':fid'=>$fichaId]);

echo '<h3>'.htmlspecialchars($fx['nome_exibicao'] ?? 'Prato').'</h3>';
echo '<div style="font-size:13px;margin-bottom:8px;color:#666">Rendimento base: '.(int)$fx['rendimento_base_pessoas'].' pessoas</div>';
echo '<ul style="margin:0;padding-left:18px">';
foreach($comp as $c){
  if($c['insumo_id']){
    echo '<li>'.htmlspecialchars($c['insumo_nome']).' — '.(float)$c['quantidade'].' '.htmlspecialchars($c['und']).'</li>';
  } elseif($c['sub_ficha_id']){
    echo '<li><strong>Sub‑preparo:</strong> '.htmlspecialchars($c['sub_nome']).' — '.(float)$c['quantidade'].' '.htmlspecialchars($c['und']).'</li>';
  }
}
echo '</ul>';
