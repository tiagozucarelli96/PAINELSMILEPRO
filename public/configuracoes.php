<?php
// configuracoes.php — Painel de Configuração (CRUD completo)
// Requisitos: conexao.php expõe $pdo (PDO MySQL)

ini_set('display_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { http_response_code(500); echo 'Sem conexão com o banco.'; exit; }

// AJAX: não vazar warnings na saída JSON
$IS_AJAX = isset($_GET['action']) && $_GET['action'] !== '';
if ($IS_AJAX) { ini_set('display_errors', 0); error_reporting(0); }

function json_out($arr){ while(ob_get_level()) @ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function to_utf8($s){ if (!mb_detect_encoding($s,'UTF-8',true)) $s=mb_convert_encoding($s,'UTF-8'); return $s; }
function norm($s){ $s=to_utf8($s); $s=str_replace("\r","\n",$s); $s=preg_replace("/[ \t]+/u"," ",$s); $s=preg_replace("/\n{2,}/u","\n",$s); return trim($s); }

// ---------- LOAD STATE ----------
function load_state(PDO $pdo){
  $cats = $pdo->query("SELECT id,nome,slug,ativa,regra_json FROM categorias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
  foreach($cats as &$c){
    $rule = $c['regra_json'] ? json_decode($c['regra_json'], true) : [];
    $c['metric'] = $rule['metric'] ?? null;
    $c['per_person'] = $rule['per_person'] ?? null;
    $c['base_people'] = $rule['base_people'] ?? 100;
    $c['distribute'] = $rule['distribute'] ?? null;
  }

  $insumos = $pdo->query("SELECT id,nome,unidade,embalagem_qtd,arredondamento FROM insumos ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

  $itens = $pdo->query("SELECT id,categoria_id,nome,unidade_saida,tipo_saida,ativo,regra_json FROM itens ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
  foreach($itens as &$i){
    $rule = $i['regra_json'] ? json_decode($i['regra_json'], true) : [];
    $i['metric'] = $rule['metric'] ?? null;
    $i['base_people'] = $rule['base_people'] ?? 100;
  }

  $alias = $pdo->query("SELECT a.id,a.item_id,i.nome AS item_nome,a.termo FROM item_alias a JOIN itens i ON i.id=a.item_id ORDER BY i.nome, a.termo")->fetchAll(PDO::FETCH_ASSOC);

  $comp = $pdo->query("SELECT c.id,c.item_id,i.nome AS item_nome,c.insumo_id,s.nome AS insumo_nome,c.qtd_por_base,c.unidade
                       FROM item_composicao c
                       JOIN itens i ON i.id=c.item_id
                       JOIN insumos s ON s.id=c.insumo_id
                       ORDER BY i.nome, s.nome")->fetchAll(PDO::FETCH_ASSOC);

  $parser = $pdo->query("SELECT p.id,p.categoria_id,c.nome AS categoria_nome,p.termo
                         FROM parser_chaves p
                         JOIN categorias c ON c.id=p.categoria_id
                         ORDER BY c.nome,p.termo")->fetchAll(PDO::FETCH_ASSOC);

  return ['cats'=>$cats,'insumos'=>$insumos,'itens'=>$itens,'alias'=>$alias,'comp'=>$comp,'parser'=>$parser];
}

// ---------- Parser de teste ----------
function is_marked_line($line){ return (bool)preg_match('/^\s*(\[\s*x\s*\]|\(\s*x\s*\)|x|X|✓|✔|\*|-)\s+/u',$line); }

function cfg_for_parser(PDO $pdo){
  // categorias com chaves
  $cats = [];
  $st=$pdo->query("SELECT id,slug FROM categorias WHERE ativa=1");
  foreach($st as $r){ $cats[$r['id']] = ['slug'=>$r['slug'],'keys'=>[],'rule'=>[]]; }

  $st=$pdo->query("SELECT categoria_id, termo FROM parser_chaves");
  foreach($st as $r){ if(isset($cats[$r['categoria_id']])) $cats[$r['categoria_id']]['keys'][] = mb_strtolower(trim($r['termo'])); }

  // aliases
  $alias = [];
  $st=$pdo->query("SELECT a.termo, a.item_id, i.categoria_id FROM item_alias a JOIN itens i ON i.id=a.item_id");
  foreach($st as $r){ $alias[mb_strtolower(trim($r['termo']))] = ['item_id'=>intval($r['item_id']),'categoria_id'=>intval($r['categoria_id'])]; }

  return ['cats'=>$cats,'alias'=>$alias];
}

function parse_text_demo($text, $cfg){
  $lines = preg_split('/\n/u', $text);
  $blocks = []; $items=[];
  foreach($lines as $raw){
    $l=trim($raw); if($l==='') continue;
    $lower=mb_strtolower($l);
    if (preg_match('/\bdoce|doces\b/u',$lower)) continue;
    $clean = preg_replace('/^\s*(\[\s*x\s*\]|\(\s*x\s*\)|x|X|✓|✔|\*|-)\s*/u','',$l);

    // item por alias
    foreach($cfg['alias'] as $term=>$hit){
      if (mb_strpos($lower,$term)!==false){ $items[]=['item_id'=>$hit['item_id']]; continue 2; }
    }
    // categoria por chave
    $placed=null;
    foreach($cfg['cats'] as $cid=>$c){
      foreach($c['keys'] as $k){ if(mb_strpos($lower,$k)!==false){ $placed=$cid; break; } }
      if($placed) break;
    }
    if($placed){
      if(!isset($blocks[$placed])) $blocks[$placed]=[];
      $blocks[$placed][] = $clean;
    }
  }
  return [$blocks,$items];
}

// ---------- ENDPOINTS ----------
$action = $_GET['action'] ?? null;

if ($action === 'state') {
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'save_categoria' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p = json_decode(file_get_contents('php://input'), true);
  $id = intval($p['id'] ?? 0);
  $nome = trim($p['nome'] ?? '');
  $slug = trim($p['slug'] ?? '');
  $ativa = intval($p['ativa'] ?? 1);
  $metric = $p['metric'] ?? null;
  $per = isset($p['per_person']) && $p['per_person']!=='' ? floatval($p['per_person']) : null;
  $base = isset($p['base_people']) && $p['base_people']!=='' ? floatval($p['base_people']) : null;
  $dis = isset($p['distribute']) ? (bool)$p['distribute'] : null;

  $rule = [];
  if ($metric) $rule['metric']=$metric;
  if ($per!==null) $rule['per_person']=$per;
  if ($base!==null) $rule['base_people']=$base;
  if ($dis!==null) $rule['distribute']=$dis;

  if ($id>0){
    $st=$pdo->prepare("UPDATE categorias SET nome=?, slug=?, ativa=?, regra_json=? WHERE id=?");
    $st->execute([$nome,$slug,$ativa, $rule?json_encode($rule):null, $id]);
  } else {
    $st=$pdo->prepare("INSERT INTO categorias (nome,slug,ativa,regra_json) VALUES (?,?,?,?)");
    $st->execute([$nome,$slug,$ativa, $rule?json_encode($rule):null]);
  }
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'del_categoria' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $id=intval($p['id']??0);
  $st=$pdo->prepare("DELETE FROM categorias WHERE id=?");
  $st->execute([$id]);
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'save_insumo' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $id=intval($p['id']??0);
  $nome=trim($p['nome']??'');
  $un=trim($p['unidade']??'');
  $pack = ($p['embalagem_qtd']===''||$p['embalagem_qtd']===null)? null : floatval($p['embalagem_qtd']);
  $round = $p['arredondamento'] ?? 'cima';

  if ($id>0){
    $st=$pdo->prepare("UPDATE insumos SET nome=?, unidade=?, embalagem_qtd=?, arredondamento=? WHERE id=?");
    $st->execute([$nome,$un,$pack,$round,$id]);
  } else {
    $st=$pdo->prepare("INSERT INTO insumos (nome,unidade,embalagem_qtd,arredondamento) VALUES (?,?,?,?)");
    $st->execute([$nome,$un,$pack,$round]);
  }
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'del_insumo' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $id=intval($p['id']??0);
  $st=$pdo->prepare("DELETE FROM insumos WHERE id=?");
  $st->execute([$id]);
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'save_item' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $id=intval($p['id']??0);
  $cat=intval($p['categoria_id']??0);
  $nome=trim($p['nome']??'');
  $metric=$p['metric']??null; $base = ($p['base_people']===''?null:floatval($p['base_people']));
  $rule=[]; if($metric) $rule['metric']=$metric; if($base!==null) $rule['base_people']=$base;

  if ($id>0){
    $st=$pdo->prepare("UPDATE itens SET categoria_id=?, nome=?, regra_json=? WHERE id=?");
    $st->execute([$cat,$nome, $rule?json_encode($rule):null, $id]);
  } else {
    $st=$pdo->prepare("INSERT INTO itens (categoria_id,nome,regra_json) VALUES (?,?,?)");
    $st->execute([$cat,$nome, $rule?json_encode($rule):null]);
  }
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'del_item' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $id=intval($p['id']??0);
  $st=$pdo->prepare("DELETE FROM itens WHERE id=?");
  $st->execute([$id]);
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'add_alias' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $item=intval($p['item_id']??0);
  $termo=trim($p['termo']??'');
  if($termo!==''){
    $st=$pdo->prepare("INSERT INTO item_alias (item_id,termo) VALUES (?,?)");
    $st->execute([$item,$termo]);
  }
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'del_alias' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $id=intval($p['id']??0);
  $st=$pdo->prepare("DELETE FROM item_alias WHERE id=?");
  $st->execute([$id]);
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'add_comp' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $item=intval($p['item_id']??0);
  $ins=intval($p['insumo_id']??0);
  $q = floatval($p['qtd_por_base']??0);
  $un= trim($p['unidade']??'');
  if($item>0 && $ins>0 && $q>0 && $un!==''){
    $st=$pdo->prepare("INSERT INTO item_composicao (item_id,insumo_id,qtd_por_base,unidade) VALUES (?,?,?,?)");
    $st->execute([$item,$ins,$q,$un]);
  }
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'del_comp' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $id=intval($p['id']??0);
  $st=$pdo->prepare("DELETE FROM item_composicao WHERE id=?");
  $st->execute([$id]);
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'add_parser' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $cid=intval($p['categoria_id']??0);
  $termo=trim($p['termo']??'');
  if($cid>0 && $termo!==''){
    $st=$pdo->prepare("INSERT INTO parser_chaves (categoria_id,termo) VALUES (?,?)");
    $st->execute([$cid,$termo]);
  }
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'del_parser' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $id=intval($p['id']??0);
  $st=$pdo->prepare("DELETE FROM parser_chaves WHERE id=?");
  $st->execute([$id]);
  json_out(['ok'=>true] + load_state($pdo));
}

if ($action === 'test_parser' && $_SERVER['REQUEST_METHOD']==='POST') {
  $p=json_decode(file_get_contents('php://input'),true);
  $text = norm($p['texto'] ?? '');
  $cfg = cfg_for_parser($pdo);
  [$blocks,$items] = parse_text_demo($text, $cfg);
  json_out(['ok'=>true,'blocks'=>$blocks,'items'=>$items,'cfg'=>$cfg]);
}

// ---------- HTML ----------
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Configurações</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
.cfg *{box-sizing:border-box}
.cfg .wrap{max-width:1200px;margin:0 auto}
.cfg .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.cfg .card{background:#fff;border:1px solid #e6ecff;border-radius:12px;padding:16px;margin:12px 0}
.cfg .h{color:#004aad;margin:6px 0}
.cfg .btn{background:#004aad;color:#fff;border:none;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
.cfg .btn-alt{background:#e9efff;color:#004aad;border:none;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
.cfg input[type="text"],.cfg input[type="number"],.cfg select,.cfg textarea{padding:8px;border:1px solid #cfd8ea;border-radius:10px;font-size:14px}
.cfg table{width:100%;border-collapse:collapse}
.cfg th,.cfg td{border-bottom:1px solid #eef2ff;padding:6px 8px;text-align:left;vertical-align:top}
.cfg .tabs{display:flex;gap:6px;margin:8px 0}
.cfg .tab{padding:8px 12px;border-radius:10px;border:1px solid #e6ecff;cursor:pointer}
.cfg .tab.active{background:#004aad;color:#fff;border-color:#004aad}
.cfg .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
.cfg .grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.cfg .sm{font-size:12px;color:#567}
</style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content cfg">
  <div class="wrap">
    <h1 class="h">Configurações</h1>

    <div class="tabs">
      <div class="tab active" data-t="cat">Categorias</div>
      <div class="tab" data-t="item">Itens</div>
      <div class="tab" data-t="insumo">Insumos</div>
      <div class="tab" data-t="comp">Composição</div>
      <div class="tab" data-t="alias">Sinônimos</div>
      <div class="tab" data-t="parser">Chaves do Parser</div>
      <div class="tab" data-t="teste">Teste rápido</div>
    </div>

    <!-- Categorias -->
    <section id="p-cat" class="card">
      <h2 class="h">Categorias</h2>
      <div class="sm">Ex.: “Salgados Assados” com <b>per_person</b> configurável.</div>
      <table id="tbl-cat">
        <thead><tr>
          <th>Nome</th><th>Slug</th><th>Ativa</th>
          <th>metric</th><th>per_person</th><th>base_people</th><th>distribute</th><th></th>
        </tr></thead>
        <tbody></tbody>
      </table>
      <div class="row" style="margin-top:8px"><button class="btn" onclick="addCat()">+ Categoria</button></div>
    </section>

    <!-- Itens -->
    <section id="p-item" class="card" style="display:none">
      <h2 class="h">Itens</h2>
      <table id="tbl-item">
        <thead><tr>
          <th>Nome</th><th>Categoria</th><th>metric</th><th>base_people</th><th></th>
        </tr></thead>
        <tbody></tbody>
      </table>
      <div class="row" style="margin-top:8px"><button class="btn" onclick="addItem()">+ Item</button></div>
    </section>

    <!-- Insumos -->
    <section id="p-insumo" class="card" style="display:none">
      <h2 class="h">Insumos</h2>
      <table id="tbl-ins">
        <thead><tr>
          <th>Nome</th><th>Unidade</th><th>Embalagem</th><th>Arredondamento</th><th></th>
        </tr></thead>
        <tbody></tbody>
      </table>
      <div class="row" style="margin-top:8px"><button class="btn" onclick="addIns()">+ Insumo</button></div>
    </section>

    <!-- Composição -->
    <section id="p-comp" class="card" style="display:none">
      <h2 class="h">Composição dos Itens (escala por base)</h2>
      <div class="grid-2">
        <div>
          <label>Item</label>
          <select id="comp_item"></select>
        </div>
        <div class="sm">Dica: para “Mini batata” e “Mini wrap”, use base_people=100 no item.</div>
      </div>
      <table id="tbl-comp" style="margin-top:10px">
        <thead><tr><th>Insumo</th><th>Qtd por base</th><th>Unidade</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
      <div class="grid-3" style="margin-top:8px">
        <div><label>Insumo</label><select id="comp_ins"></select></div>
        <div><label>Qtd por base</label><input id="comp_q" type="number" step="0.01"></div>
        <div><label>Unidade</label><input id="comp_un" type="text" placeholder="kg, pacote, pé..."></div>
      </div>
      <div class="row" style="margin-top:8px"><button class="btn" onclick="saveComp()">Adicionar</button></div>
    </section>

    <!-- Aliases -->
    <section id="p-alias" class="card" style="display:none">
      <h2 class="h">Sinônimos de Itens (para leitura do PDF)</h2>
      <div class="grid-2">
        <div><label>Item</label><select id="al_item"></select></div>
        <div><label>Termo</label><input id="al_termo" type="text" placeholder="ex.: mini batata"></div>
      </div>
      <div class="row" style="margin-top:8px"><button class="btn" onclick="saveAlias()">Adicionar</button></div>
      <table id="tbl-alias" style="margin-top:10px">
        <thead><tr><th>Item</th><th>Termo</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
    </section>

    <!-- Parser -->
    <section id="p-parser" class="card" style="display:none">
      <h2 class="h">Chaves do Parser por Categoria</h2>
      <div class="grid-2">
        <div><label>Categoria</label><select id="ps_cat"></select></div>
        <div><label>Termo</label><input id="ps_termo" type="text" placeholder="ex.: assados"></div>
      </div>
      <div class="row" style="margin-top:8px"><button class="btn" onclick="saveParser()">Adicionar</button></div>
      <table id="tbl-parser" style="margin-top:10px">
        <thead><tr><th>Categoria</th><th>Termo</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
    </section>

    <!-- Teste rápido -->
    <section id="p-teste" class="card" style="display:none">
      <h2 class="h">Teste rápido do Parser</h2>
      <textarea id="tx_teste" placeholder="Cole aqui um trecho do resumo do evento" style="width:100%;min-height:120px"></textarea>
      <div class="row" style="margin-top:8px"><button class="btn" onclick="runTest()">Analisar</button></div>
      <pre id="test_out" class="sm" style="white-space:pre-wrap;margin-top:8px"></pre>
    </section>

  </div>
</main>

<script>
const $ = s=>document.querySelector(s);
const $$ = s=>Array.from(document.querySelectorAll(s));
const api = (a,body)=>fetch('?action='+a,{method:'POST',body:body?JSON.stringify(body):null}).then(r=>r.json());

let ST={cats:[],itens:[],insumos:[],alias:[],comp:[],parser:[]};

function refresh(){
  fetch('?action=state').then(r=>r.json()).then(s=>{
    ST=s;
    renderCats(); renderItens(); renderInsumos(); renderAlias(); renderComp(); renderParser();
    fillSelects();
  });
}

function fillSelects(){
  const optCat = ST.cats.map(c=>`<option value="${c.id}">${c.nome}</option>`).join('');
  $$('#tbl-item select.catSel').forEach(sel=>{ sel.innerHTML=optCat; sel.value = sel.dataset.v||''; });
  $('#comp_item').innerHTML = ST.itens.map(i=>`<option value="${i.id}">${i.nome}</option>`).join('');
  $('#al_item').innerHTML = ST.itens.map(i=>`<option value="${i.id}">${i.nome}</option>`).join('');
  $('#ps_cat').innerHTML = ST.cats.map(c=>`<option value="${c.id}">${c.nome}</option>`).join('');
  $('#comp_ins').innerHTML = ST.insumos.map(s=>`<option value="${s.id}">${s.nome}</option>`).join('');
  // load comp table for first item
  compChange();
}

function renderCats(){
  const tb = $('#tbl-cat tbody'); if(!tb) return;
  tb.innerHTML = ST.cats.map(c=>`
    <tr>
      <td><input type="text" value="${escapeHtml(c.nome)}" data-id="${c.id}" class="c-nome"></td>
      <td><input type="text" value="${escapeHtml(c.slug)}" data-id="${c.id}" class="c-slug"></td>
      <td><select data-id="${c.id}" class="c-ativa"><option value="1"${c.ativa==1?' selected':''}>Sim</option><option value="0"${c.ativa==0?' selected':''}>Não</option></select></td>
      <td>
        <select data-id="${c.id}" class="c-metric">
          ${opt('','')} ${opt('un','un')} ${opt('kg','kg')} ${opt('escala_base','escala_base')} ${opt('evento','evento')}
        </select>
      </td>
      <td><input type="number" step="0.01" value="${c.per_person??''}" data-id="${c.id}" class="c-per"></td>
      <td><input type="number" step="1" value="${c.base_people??''}" data-id="${c.id}" class="c-base"></td>
      <td>
        <select data-id="${c.id}" class="c-dis">
          ${boolOpt(c.distribute)}
        </select>
      </td>
      <td>
        <button class="btn" onclick="saveCat(${c.id})">Salvar</button>
        <button class="btn-alt" onclick="delCat(${c.id})">Excluir</button>
      </td>
    </tr>
  `).join('');
  // set selected metric
  $$('#tbl-cat .c-metric').forEach(sel=>{ const id=sel.dataset.id; const cat=ST.cats.find(x=>x.id==id); sel.value=cat.metric||''; });
}
function addCat(){
  const novo={nome:'Nova categoria',slug:'',ativa:1,metric:'',per_person:'',base_people:'',distribute:''};
  api('save_categoria',novo).then(()=>refresh());
}
function saveCat(id){
  const row = $(`#tbl-cat tbody tr td .btn[onclick="saveCat(${id})"]`).closest('tr');
  const payload={
    id,
    nome: row.querySelector('.c-nome').value.trim(),
    slug: row.querySelector('.c-slug').value.trim(),
    ativa: parseInt(row.querySelector('.c-ativa').value,10),
    metric: row.querySelector('.c-metric').value || null,
    per_person: row.querySelector('.c-per').value,
    base_people: row.querySelector('.c-base').value,
    distribute: row.querySelector('.c-dis').value===''? null : (row.querySelector('.c-dis').value==='1')
  };
  api('save_categoria',payload).then(()=>refresh());
}
function delCat(id){ if(confirm('Excluir categoria? Itens desta categoria podem ser removidos.')) api('del_categoria',{id}).then(()=>refresh()); }

function renderItens(){
  const tb=$('#tbl-item tbody'); if(!tb) return;
  tb.innerHTML = ST.itens.map(i=>`
    <tr>
      <td><input type="text" value="${escapeHtml(i.nome)}" data-id="${i.id}" class="i-nome"></td>
      <td><select class="catSel" data-v="${i.categoria_id}"></select></td>
      <td>
        <select data-id="${i.id}" class="i-metric">
          ${opt('','')} ${opt('un','un')} ${opt('kg','kg')} ${opt('escala_base','escala_base')} ${opt('evento','evento')}
        </select>
      </td>
      <td><input type="number" step="1" value="${i.base_people??''}" data-id="${i.id}" class="i-base"></td>
      <td>
        <button class="btn" onclick="saveItem(${i.id})">Salvar</button>
        <button class="btn-alt" onclick="delItem(${i.id})">Excluir</button>
      </td>
    </tr>
  `).join('');
  $$('#tbl-item .i-metric').forEach(sel=>{ const id=sel.dataset.id; const it=ST.itens.find(x=>x.id==id); sel.value=it.metric||''; });
}
function addItem(){
  const firstCat = ST.cats[0]?.id || 0;
  const novo={categoria_id:firstCat,nome:'Novo item',metric:'',base_people:''};
  api('save_item',novo).then(()=>refresh());
}
function saveItem(id){
  const row = $(`#tbl-item tbody tr td .btn[onclick="saveItem(${id})"]`).closest('tr');
  const payload={
    id,
    nome: row.querySelector('.i-nome').value.trim(),
    categoria_id: parseInt(row.querySelector('select.catSel').value,10),
    metric: row.querySelector('.i-metric').value || null,
    base_people: row.querySelector('.i-base').value
  };
  api('save_item',payload).then(()=>refresh());
}
function delItem(id){ if(confirm('Excluir item e sua composição?')) api('del_item',{id}).then(()=>refresh()); }

function renderInsumos(){
  const tb=$('#tbl-ins tbody'); if(!tb) return;
  tb.innerHTML = ST.insumos.map(s=>`
    <tr>
      <td><input type="text" value="${escapeHtml(s.nome)}" data-id="${s.id}" class="s-nome"></td>
      <td><input type="text" value="${escapeHtml(s.unidade)}" data-id="${s.id}" class="s-un"></td>
      <td><input type="number" step="0.01" value="${s.embalagem_qtd??''}" data-id="${s.id}" class="s-pack"></td>
      <td>
        <select data-id="${s.id}" class="s-round">
          ${sel(s.arredondamento,['cima','normal','nenhum'])}
        </select>
      </td>
      <td>
        <button class="btn" onclick="saveIns(${s.id})">Salvar</button>
        <button class="btn-alt" onclick="delIns(${s.id})">Excluir</button>
      </td>
    </tr>
  `).join('');
}
function addIns(){ api('save_insumo',{nome:'Novo insumo',unidade:'un',embalagem_qtd:'',arredondamento:'cima'}).then(()=>refresh()); }
function saveIns(id){
  const row = $(`#tbl-ins tbody tr td .btn[onclick="saveIns(${id})"]`).closest('tr');
  const payload={
    id,
    nome: row.querySelector('.s-nome').value.trim(),
    unidade: row.querySelector('.s-un').value.trim(),
    embalagem_qtd: row.querySelector('.s-pack').value,
    arredondamento: row.querySelector('.s-round').value
  };
  api('save_insumo',payload).then(()=>refresh());
}
function delIns(id){ if(confirm('Excluir insumo?')) api('del_insumo',{id}).then(()=>refresh()); }

function renderAlias(){
  const tb=$('#tbl-alias tbody'); if(!tb) return;
  tb.innerHTML = ST.alias.map(a=>`
    <tr><td>${escapeHtml(a.item_nome)}</td><td>${escapeHtml(a.termo)}</td>
      <td><button class="btn-alt" onclick="delAlias(${a.id})">Excluir</button></td></tr>
  `).join('');
}
function saveAlias(){
  const payload={ item_id: parseInt($('#al_item').value,10), termo: $('#al_termo').value.trim() };
  if(!payload.termo) return alert('Informe o termo.');
  api('add_alias',payload).then(()=>{ $('#al_termo').value=''; refresh(); });
}
function delAlias(id){ api('del_alias',{id}).then(()=>refresh()); }

function renderComp(){
  // tabela depende do item selecionado
  compChange();
}
function compChange(){
  const iid = parseInt($('#comp_item').value||'0',10);
  const tb=$('#tbl-comp tbody'); if(!tb) return;
  const rows = ST.comp.filter(c=>c.item_id==iid).map(c=>`
    <tr><td>${escapeHtml(c.insumo_nome)}</td><td>${c.qtd_por_base}</td><td>${escapeHtml(c.unidade)}</td>
      <td><button class="btn-alt" onclick="delComp(${c.id})">Excluir</button></td></tr>
  `).join('');
  tb.innerHTML = rows || '<tr><td colspan="4" class="sm">Sem composição cadastrada.</td></tr>';
}
function saveComp(){
  const payload={
    item_id: parseInt($('#comp_item').value,10),
    insumo_id: parseInt($('#comp_ins').value,10),
    qtd_por_base: parseFloat($('#comp_q').value||'0'),
    unidade: $('#comp_un').value.trim()
  };
  if(!payload.item_id || !payload.insumo_id || !payload.qtd_por_base || !payload.unidade){ return alert('Preencha todos os campos.'); }
  api('add_comp',payload).then(()=>{ $('#comp_q').value=''; $('#comp_un').value=''; refresh(); });
}
function delComp(id){ api('del_comp',{id}).then(()=>refresh()); }

function renderParser(){
  const tb=$('#tbl-parser tbody'); if(!tb) return;
  tb.innerHTML = ST.parser.map(p=>`
    <tr><td>${escapeHtml(p.categoria_nome)}</td><td>${escapeHtml(p.termo)}</td>
      <td><button class="btn-alt" onclick="delParser(${p.id})">Excluir</button></td></tr>
  `).join('');
}
function saveParser(){
  const payload={ categoria_id: parseInt($('#ps_cat').value,10), termo: $('#ps_termo').value.trim() };
  if(!payload.termo) return alert('Informe o termo.');
  api('add_parser',payload).then(()=>{ $('#ps_termo').value=''; refresh(); });
}
function delParser(id){ api('del_parser',{id}).then(()=>refresh()); }

// Teste rápido
function runTest(){
  const texto = $('#tx_teste').value || '';
  fetch('?action=test_parser',{method:'POST',body:JSON.stringify({texto})})
    .then(r=>r.json()).then(j=>{
      const mapCat = Object.fromEntries(ST.cats.map(c=>[c.id,c.nome]));
      let out='Itens reconhecidos por sinônimo: '+ (j.items?.length||0)+'\n';
      out += '\nBlocos por categoria:\n';
      for (const cid in j.blocks){
        out += '- '+ (mapCat[cid]||('cat '+cid)) +':\n  • '+ (j.blocks[cid].join('\n  • ')||'—') + '\n';
      }
      $('#test_out').textContent = out;
    });
}

// helpers
function escapeHtml(x){ return (x||'').replace(/[&<>"]/g,s=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s])); }
function opt(v,t){ return `<option value="${v}">${t}</option>`; }
function sel(cur, arr){ return arr.map(v=>`<option value="${v}"${cur===v?' selected':''}>${v}</option>`).join(''); }
function boolOpt(val){ return `<option value=""${val===null?' selected':''}></option><option value="1"${val===true?' selected':''}>true</option><option value="0"${val===false?' selected':''}>false</option>`; }

// Tabs
$$('.tab').forEach(t=>t.onclick=()=>{
  $$('.tab').forEach(x=>x.classList.remove('active'));
  t.classList.add('active');
  const want=t.dataset.t;
  ['cat','item','insumo','comp','alias','parser','teste'].forEach(k=>$('#p-'+k).style.display = (k===want)?'block':'none');
  if (want==='comp') compChange();
});

document.addEventListener('change', e=>{
  if (e.target && e.target.id==='comp_item') compChange();
});

refresh();
</script>
</body>
</html>
