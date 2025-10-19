<?php
// public/fichas_tecnicas.php
// Cadastro de Fichas Técnicas (preparos) + componentes com custo (Preço × FC) e "Preço na ficha" opcional.

session_start();
require_once __DIR__ . '/conexao.php'; // $pdo

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post($k, $d=null){ return isset($_POST[$k]) ? $_POST[$k] : $d; }
function valnum($v,$def=0){ $v=str_replace(',','.',$v); return is_numeric($v)?(float)$v:$def; }

// Carregar dados base
$categorias = $pdo->query("SELECT id, nome FROM lc_categorias WHERE ativo=true ORDER BY ordem ASC, nome ASC")->fetchAll(PDO::FETCH_KEY_PAIR);
$unidades   = $pdo->query("SELECT id, nome, simbolo, tipo, fator_base FROM lc_unidades WHERE ativo=true ORDER BY tipo, nome")->fetchAll(PDO::FETCH_ASSOC);
$unById = []; foreach($unidades as $u){ $unById[$u['id']]=$u; }

$insumos = $pdo->query("
  SELECT i.id, i.nome, i.unidade_id, i.preco, i.fator_correcao,
         (i.preco * i.fator_correcao) AS custo_corrigido
  FROM lc_insumos i
  WHERE i.ativo = true
  ORDER BY i.nome
")->fetchAll(PDO::FETCH_ASSOC);
$inById = []; foreach($insumos as $i){ $inById[$i['id']]=$i; }

// Ações salvar/excluir
$msg=''; $err='';
$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
  if (post('action') === 'save_ficha') {
    $id       = (int)post('id', 0);
    $nome     = trim((string)post('nome',''));
    $cat_id   = (int)post('categoria_id', 0);
    $u_saida  = trim((string)post('unidade_saida','un'));
    $rend     = valnum(post('rendimento','1'));
    $cons     = post('consumo_pessoa','')!=='' ? valnum(post('consumo_pessoa','0')) : null;
    $perdas   = post('perdas_adicionais','')!=='' ? valnum(post('perdas_adicionais','0')) : 0;
    $obs      = trim((string)post('observacao',''));
    $ativo    = post('ativo','1')==='1';

    if ($nome==='') throw new Exception('Informe o nome do preparo.');
    if ($cat_id<=0) throw new Exception('Selecione a categoria.');
    if ($rend<=0) throw new Exception('Rendimento deve ser > 0.');

    if ($id===0) {
      $stmt=$pdo->prepare("INSERT INTO lc_fichas (nome,categoria_id,unidade_saida,rendimento,consumo_pessoa,perdas_adicionais,observacao,ativo)
        VALUES (:n,:c,:u,:r,:cp,:p,:o,:a) RETURNING id");
      $stmt->execute([':n'=>$nome,':c'=>$cat_id,':u'=>$u_saida,':r'=>$rend,':cp'=>$cons,':p'=>$perdas,':o'=>$obs,':a'=>$ativo]);
      $id = (int)$stmt->fetchColumn();
      $msg='Ficha criada.';
    } else {
      $stmt=$pdo->prepare("UPDATE lc_fichas SET nome=:n,categoria_id=:c,unidade_saida=:u,rendimento=:r,consumo_pessoa=:cp,
        perdas_adicionais=:p,observacao=:o,ativo=:a,updated_at=NOW() WHERE id=:id");
      $stmt->execute([':n'=>$nome,':c'=>$cat_id,':u'=>$u_saida,':r'=>$rend,':cp'=>$cons,':p'=>$perdas,':o'=>$obs,':a'=>$ativo,':id'=>$id]);
      $msg='Ficha atualizada.';
    }

    // Componentes
    // Limpa e regrava (simples e seguro)
    $pdo->prepare("DELETE FROM lc_ficha_componentes WHERE ficha_id=:f")->execute([':f'=>$id]);

    $rows = isset($_POST['comp_item_id']) ? $_POST['comp_item_id'] : [];
    for($i=0;$i<count($rows);$i++){
      $item_id = (int)($_POST['comp_item_id'][$i] ?? 0);
      if ($item_id<=0) continue;

      $calc_modo = $_POST['comp_calc_modo'][$i] ?? 'receita';
      $qtd       = valnum($_POST['comp_qtd'][$i] ?? '0');
      $unid_id   = (int)($_POST['comp_unidade_id'][$i] ?? 0);
      $preco_ovr = $_POST['comp_preco_override'][$i]!=='' ? valnum($_POST['comp_preco_override'][$i]) : null;
      $tipo_saida= $_POST['comp_tipo_saida'][$i] ?? 'preparo';
      $forn_id   = $_POST['comp_fornecedor_id'][$i] ?? null;
      $obs_linha = trim((string)($_POST['comp_obs'][$i] ?? ''));

      if ($qtd<=0 || $unid_id<=0) continue;

      $stmt=$pdo->prepare("INSERT INTO lc_ficha_componentes
        (ficha_id,item_tipo,item_id,calc_modo,qtd,unidade_id,preco_override,tipo_saida,fornecedor_id,obs)
        VALUES (:f,'insumo',:item,:modo,:qtd,:unid,:ovr,:tipo,:forn,:obs)");
      $stmt->execute([
        ':f'=>$id, ':item'=>$item_id, ':modo'=>$calc_modo, ':qtd'=>$qtd, ':unid'=>$unid_id,
        ':ovr'=>$preco_ovr, ':tipo'=>$tipo_saida, ':forn'=>($forn_id===''?null:$forn_id), ':obs'=>$obs_linha
      ]);
    }

    header("Location: fichas_tecnicas.php?id=".$id."&ok=1"); exit;
  }

  if (isset($_GET['del']) && ctype_digit($_GET['del'])) {
    $del=(int)$_GET['del'];
    $pdo->prepare("DELETE FROM lc_fichas WHERE id=:id")->execute([':id'=>$del]);
    $msg='Ficha removida.';
    $edit_id=0;
  }

} catch (Exception $e) { $err=$e->getMessage(); }

// Carregar lista + ficha em edição
$fichas = $pdo->query("
  SELECT f.id, f.nome, c.nome AS categoria, f.unidade_saida, f.rendimento, f.ativo
  FROM lc_fichas f JOIN lc_categorias c ON c.id=f.categoria_id
  ORDER BY f.ativo DESC, c.nome ASC, f.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

$edit = null; $comp = [];
if ($edit_id>0){
  $stmt=$pdo->prepare("SELECT * FROM lc_fichas WHERE id=:id"); $stmt->execute([':id'=>$edit_id]); $edit=$stmt->fetch(PDO::FETCH_ASSOC);
  if ($edit){
    $st=$pdo->prepare("SELECT * FROM lc_ficha_componentes WHERE ficha_id=:f ORDER BY id");
    $st->execute([':f'=>$edit_id]); $comp=$st->fetchAll(PDO::FETCH_ASSOC);
  }
}

// PREVIEW de custos (somente quando editando ou post-ok)
function calc_preview_cost($compRows, $inById, $unById, $perdasAdic=0){
  $total = 0.0; $linhas=[];
  foreach($compRows as $r){
    // requer item_id, qtd, unidade_id
    $iid = (int)$r['item_id'];
    $qtd = (float)$r['qtd'];
    $uid = (int)$r['unidade_id'];
    $ovr = isset($r['preco_override']) && $r['preco_override']!=='' ? (float)$r['preco_override'] : null;
    if ($iid<=0 || $qtd<=0 || $uid<=0) { $linhas[]=['desc'=>'—','custo'=>0]; continue; }

    if (!isset($inById[$iid])) { $linhas[]=['desc'=>'(insumo não encontrado)','custo'=>0]; continue; }

    $ins = $inById[$iid];
    $precoFC = (float)$ins['preco'] * (float)$ins['fator_correcao'];   // Preço × FC
    $precoUsado = $ovr!==null ? $ovr : $precoFC;

    // converter qtd da unidade da linha p/ unidade do insumo
    $uLinha = $unById[$uid] ?? null;
    $uInsum = $unById[$ins['unidade_id']] ?? null;
    if (!$uLinha || !$uInsum){ $linhas[]=['desc'=>'(unidade inválida)','custo'=>0]; continue; }

    // qtd em unidade do insumo = qtd * (fatorLinha / fatorInsumo)
    $qtd_insumo = $qtd * ((float)$uLinha['fator_base'] / (float)$uInsum['fator_base']);

    // aplicar perdas adicionais (%) ao final (ex.: 5 = +5%)
    $qtd_final = $qtd_insumo * (1 + max(0,$perdasAdic)/100);

    $custo = $qtd_final * $precoUsado;
    $total += $custo;

    $linhas[]=[
      'desc'=>$ins['nome'].' ('.$uLinha['simbolo'].'→'.$uInsum['simbolo'].')',
      'qtd'=>$qtd, 'custo'=> $custo
    ];
  }
  return [$total,$linhas];
}

// Se houver ficha em edição, montar estrutura de preview
$preview = null;
if ($edit && $comp){
  // map para preview (estrutura mínima)
  $rows=[];
  foreach($comp as $r){
    $rows[]=[
      'item_id'=>$r['item_id'],
      'qtd'=>$r['qtd'],
      'unidade_id'=>$r['unidade_id'],
      'preco_override'=>$r['preco_override']
    ];
  }
  [$tt,$ls]=calc_preview_cost($rows,$inById,$unById,(float)$edit['perdas_adicionais']);
  $preview=['total'=>$tt,'linhas'=>$ls,'custo_unit'=>$tt/max(1,(float)$edit['rendimento'])];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Fichas Técnicas | Painel Smile PRO</title>
  <link rel="stylesheet" href="estilo.css">
  <style>
    table { width:100%; border-collapse:collapse; margin-top:8px; }
    th,td { border:1px solid #e5e5e5; padding:6px 8px; text-align:left; }
    th { background:#f7f9fb; }
    .msg{background:#e7fff0;border:1px solid #bfe9cc;padding:8px;margin:8px 0;border-radius:6px}
    .err{background:#ffecec;border:1px solid #ffb3b3;padding:8px;margin:8px 0;border-radius:6px}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    .small{font-size:12px;color:#555}
    .badge{padding:2px 6px;border:1px solid #ccd;border-radius:8px;font-size:12px;background:#eef}
    .actions{white-space:nowrap}
    .row-new{background:#fbfff5}
    .hint{font-size:12px;color:#666}
    .inline {display:flex;gap:8px;align-items:center}
  </style>
</head>
<body>
  <h1>Fichas Técnicas</h1>
  <?php if(isset($_GET['ok'])): ?><div class="msg">Salvo com sucesso.</div><?php endif; ?>
  <?php if($msg): ?><div class="msg"><?=h($msg)?></div><?php endif; ?>
  <?php if($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>

  <div class="inline">
    <a href="fichas_tecnicas.php">Nova ficha</a>
  </div>

  <h2>Fichas cadastradas</h2>
  <table>
    <thead><tr><th>#</th><th>Nome</th><th>Categoria</th><th>Un. saída</th><th>Rendimento</th><th>Ativa</th><th class="actions">Ações</th></tr></thead>
    <tbody>
      <?php foreach($fichas as $f): ?>
        <tr>
          <td><?= (int)$f['id'] ?></td>
          <td><?= h($f['nome']) ?></td>
          <td><?= h($f['categoria']) ?></td>
          <td><?= h($f['unidade_saida']) ?></td>
          <td><?= h($f['rendimento']) ?></td>
          <td><?= $f['ativo']?'Sim':'Não' ?></td>
          <td class="actions">
            <a href="fichas_tecnicas.php?id=<?= (int)$f['id'] ?>">Editar</a> |
            <a href="fichas_tecnicas.php?del=<?= (int)$f['id'] ?>" onclick="return confirm('Remover esta ficha?')">Excluir</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$fichas): ?>
        <tr><td colspan="7">Nenhuma ficha cadastrada.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <hr>

  <h2><?= $edit ? 'Editar ficha' : 'Nova ficha' ?></h2>
  <form method="post">
    <input type="hidden" name="action" value="save_ficha">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">

    <div class="grid">
      <div>
        <label>Nome do preparo<br>
          <input type="text" name="nome" value="<?= h($edit['nome'] ?? '') ?>" required>
        </label>
      </div>
      <div>
        <label>Categoria<br>
          <select name="categoria_id" required>
            <option value="">Selecione…</option>
            <?php foreach($categorias as $cid=>$cnome): ?>
              <option value="<?= $cid ?>" <?= ($edit && (int)$edit['categoria_id']===$cid)?'selected':'' ?>><?= h($cnome) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div>
        <label>Unidade de saída (texto curto)<br>
          <input type="text" name="unidade_saida" placeholder="ex.: un, porção, kg" value="<?= h($edit['unidade_saida'] ?? 'un') ?>" required>
        </label>
      </div>
      <div>
        <label>Rendimento da receita<br>
          <input type="number" step="0.000001" name="rendimento" value="<?= h($edit['rendimento'] ?? '1') ?>" required>
        </label>
      </div>
      <div>
        <label>Consumo padrão por pessoa (opcional)<br>
          <input type="number" step="0.000001" name="consumo_pessoa" value="<?= h($edit['consumo_pessoa'] ?? '') ?>" placeholder="ex.: 0.5 (un/pessoa)">
        </label>
      </div>
      <div>
        <label>Perdas adicionais % (opcional)<br>
          <input type="number" step="0.01" name="perdas_adicionais" value="<?= h($edit['perdas_adicionais'] ?? '0') ?>">
        </label>
      </div>
      <div>
        <label>Ativa<br>
          <select name="ativo">
            <option value="1" <?= ($edit && !$edit['ativo'])?'':'selected' ?>>Sim</option>
            <option value="0" <?= ($edit && !$edit['ativo'])?'selected':'' ?>>Não</option>
          </select>
        </label>
      </div>
      <div>
        <label>Observações<br>
          <input type="text" name="observacao" value="<?= h($edit['observacao'] ?? '') ?>">
        </label>
      </div>
    </div>

    <h3>Componentes</h3>
    <table id="comp">
      <thead>
        <tr>
          <th>Insumo</th>
          <th>Modo</th>
          <th>Qtd</th>
          <th>Unid.</th>
          <th>Preço na ficha (opcional)</th>
          <th>Tipo (saída)</th>
          <th>Fornecedor (ID opcional)</th>
          <th>Obs</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $rows = $comp ?: [];
        if(!$rows){ $rows = []; for($k=0;$k<3;$k++) $rows[]=['item_id'=>'','calc_modo'=>'receita','qtd'=>'','unidade_id'=>'','preco_override'=>'','tipo_saida'=>'preparo','fornecedor_id'=>'','obs'=>'']; }
        foreach($rows as $r):
        ?>
        <tr>
          <td>
            <select name="comp_item_id[]">
              <option value="">— selecione —</option>
              <?php foreach($insumos as $ins): ?>
                <option value="<?= $ins['id'] ?>" <?= ((int)($r['item_id']??0)===$ins['id'])?'selected':'' ?>>
                  <?= h($ins['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="comp_calc_modo[]">
              <?php foreach(['pessoa'=>'por pessoa','receita'=>'por receita'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= (($r['calc_modo']??'receita')===$k)?'selected':'' ?>><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" step="0.000001" name="comp_qtd[]" value="<?= h($r['qtd']) ?>"></td>
          <td>
            <select name="comp_unidade_id[]">
              <option value="">—</option>
              <?php foreach($unidades as $u): ?>
                <option value="<?= $u['id'] ?>" <?= ((int)($r['unidade_id']??0)===$u['id'])?'selected':'' ?>>
                  <?= h($u['simbolo']) ?> (<?= h($u['nome']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" step="0.0001" name="comp_preco_override[]" value="<?= h($r['preco_override'] ?? '') ?>" placeholder="ex.: 12.90"></td>
          <td>
            <select name="comp_tipo_saida[]">
              <?php foreach(['comprado','preparo','fixo'] as $t): ?>
                <option <?= (($r['tipo_saida']??'preparo')===$t)?'selected':'' ?>><?= $t ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="text" name="comp_fornecedor_id[]" value="<?= h($r['fornecedor_id'] ?? '') ?>" style="width:110px"></td>
          <td><input type="text" name="comp_obs[]" value="<?= h($r['obs'] ?? '') ?>"></td>
        </tr>
        <?php endforeach; ?>
        <!-- linha em branco para novo -->
        <tr class="row-new">
          <td>
            <select name="comp_item_id[]">
              <option value="">— selecione —</option>
              <?php foreach($insumos as $ins): ?>
                <option value="<?= $ins['id'] ?>"><?= h($ins['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="comp_calc_modo[]">
              <option value="pessoa">por pessoa</option>
              <option value="receita" selected>por receita</option>
            </select>
          </td>
          <td><input type="number" step="0.000001" name="comp_qtd[]" placeholder="ex.: 0.030"></td>
          <td>
            <select name="comp_unidade_id[]">
              <option value="">—</option>
              <?php foreach($unidades as $u): ?>
                <option value="<?= $u['id'] ?>"><?= h($u['simbolo']) ?> (<?= h($u['nome']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" step="0.0001" name="comp_preco_override[]" placeholder="(opcional)"></td>
          <td>
            <select name="comp_tipo_saida[]">
              <option>preparo</option>
              <option>comprado</option>
              <option>fixo</option>
            </select>
          </td>
          <td><input type="text" name="comp_fornecedor_id[]" placeholder="(opcional)" style="width:110px"></td>
          <td><input type="text" name="comp_obs[]" placeholder="(opcional)"></td>
        </tr>
      </tbody>
    </table>

    <p class="hint">Dica: “Preço na ficha” congela o custo deste componente. Se vazio, usamos **Preço × FC** do insumo.</p>

    <p><button type="submit">Salvar ficha</button></p>
  </form>

  <?php if($preview): ?>
    <h3>Prévia de custos (receita)</h3>
    <table>
      <thead><tr><th>Componente</th><th>Custo</th></tr></thead>
      <tbody>
        <?php foreach($preview['linhas'] as $L): ?>
          <tr>
            <td><?= h($L['desc']) ?></td>
            <td>R$ <?= number_format($L['custo'], 2, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <th>Total da receita</th>
          <th>R$ <?= number_format($preview['total'], 2, ',', '.') ?></th>
        </tr>
        <tr>
          <td>Custo por unidade de saída (<?= h($edit['unidade_saida']) ?>)</td>
          <td>R$ <?= number_format($preview['custo_unit'], 4, ',', '.') ?></td>
        </tr>
      </tbody>
    </table>
  <?php endif; ?>

</body>
</html>
