<?php
// public/configuracoes.php
// Painel Smile PRO - Configurações base: Categorias, Unidades, Insumos
// Usa PDO (PostgreSQL) via conexao.php

session_start();
require_once __DIR__ . '/conexao.php'; // deve expor $pdo (PDO)

function input($key, $default = '') {
  return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}

function bool01($v) { return ($v === '1' || $v === 1 || $v === true || $v === 'true') ? 1 : 0; }

// --- AÇÕES ---
$tab = isset($_GET['tab']) ? $_GET['tab'] : (isset($_POST['tab']) ? $_POST['tab'] : 'categorias');
$msg = '';
$err = '';

try {
  // CATEGORIAS
  if (input('action') === 'save_categoria') {
    $id    = input('id');
    $nome  = input('nome');
    $ordem = (int)input('ordem', 0);
    $ativo = bool01(input('ativo','1'));

    if ($nome === '') throw new Exception('Nome da categoria é obrigatório.');

    if ($id === '') {
      $stmt = $pdo->prepare("INSERT INTO lc_categorias (nome, ordem, ativo) VALUES (:n,:o,:a)");
      $stmt->execute([':n'=>$nome, ':o'=>$ordem, ':a'=>$ativo]);
      $msg = 'Categoria criada.';
    } else {
      $stmt = $pdo->prepare("UPDATE lc_categorias SET nome=:n, ordem=:o, ativo=:a WHERE id=:id");
      $stmt->execute([':n'=>$nome, ':o'=>$ordem, ':a'=>$ativo, ':id'=>$id]);
      $msg = 'Categoria atualizada.';
    }
    $tab = 'categorias';
  }

  // UNIDADES
  if (input('action') === 'save_unidade') {
    $id    = input('id');
    $nome  = input('nome');
    $simb  = input('simbolo');
    $tipo  = input('tipo');
    $fator = (float)input('fator_base', '1');
    $ativo = bool01(input('ativo','1'));

    if ($nome === '' || $simb === '' || $tipo === '') throw new Exception('Preencha nome, símbolo e tipo.');
    if ($fator <= 0) throw new Exception('Fator base deve ser > 0.');

    if ($id === '') {
      $stmt = $pdo->prepare("INSERT INTO lc_unidades (nome, simbolo, tipo, fator_base, ativo) VALUES (:n,:s,:t,:f,:a)");
      $stmt->execute([':n'=>$nome, ':s'=>$simb, ':t'=>$tipo, ':f'=>$fator, ':a'=>$ativo]);
      $msg = 'Unidade criada.';
    } else {
      $stmt = $pdo->prepare("UPDATE lc_unidades SET nome=:n, simbolo=:s, tipo=:t, fator_base=:f, ativo=:a WHERE id=:id");
      $stmt->execute([':n'=>$nome, ':s'=>$simb, ':t'=>$tipo, ':f'=>$fator, ':a'=>$ativo, ':id'=>$id]);
      $msg = 'Unidade atualizada.';
    }
    $tab = 'unidades';
  }

  // INSUMOS
  if (input('action') === 'save_insumo') {
    $id     = input('id');
    $nome   = input('nome');
    $unid   = (int)input('unidade_id');
    $preco  = (float)str_replace(',', '.', input('preco','0'));
    $fc     = (float)str_replace(',', '.', input('fator_correcao','1'));
    $tipo   = input('tipo_padrao','comprado'); // comprado | preparo | fixo
    $forn   = input('fornecedor_id'); // opcional (FK futura)
    $obs    = input('observacao');
    $ativo  = bool01(input('ativo','1'));

    if ($nome === '') throw new Exception('Nome do insumo é obrigatório.');
    if ($unid <= 0) throw new Exception('Unidade é obrigatória.');
    if ($preco < 0) throw new Exception('Preço não pode ser negativo.');
    if ($fc < 1) throw new Exception('Fator de correção (FC) deve ser >= 1,00.');

    if ($id === '') {
      $stmt = $pdo->prepare("
        INSERT INTO lc_insumos (nome, unidade_id, preco, fator_correcao, tipo_padrao, fornecedor_id, observacao, ativo)
        VALUES (:n,:u,:p,:fc,:t,:f,:o,:a)
      ");
      $stmt->execute([
        ':n'=>$nome, ':u'=>$unid, ':p'=>$preco, ':fc'=>$fc, ':t'=>$tipo,
        ':f'=>($forn === '' ? null : $forn), ':o'=>$obs, ':a'=>$ativo
      ]);
      $msg = 'Insumo criado.';
    } else {
      $stmt = $pdo->prepare("
        UPDATE lc_insumos SET
          nome=:n, unidade_id=:u, preco=:p, fator_correcao=:fc,
          tipo_padrao=:t, fornecedor_id=:f, observacao=:o, ativo=:a
        WHERE id=:id
      ");
      $stmt->execute([
        ':n'=>$nome, ':u'=>$unid, ':p'=>$preco, ':fc'=>$fc, ':t'=>$tipo,
        ':f'=>($forn === '' ? null : $forn), ':o'=>$obs, ':a'=>$ativo, ':id'=>$id
      ]);
      $msg = 'Insumo atualizado.';
    }
    $tab = 'insumos';
  }

} catch (Exception $e) {
  $err = $e->getMessage();
}

// --- LISTAGENS ---
$cat = $pdo->query("SELECT * FROM lc_categorias ORDER BY ativo DESC, ordem ASC, nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$uni = $pdo->query("SELECT * FROM lc_unidades   ORDER BY ativo DESC, tipo ASC, nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$ins = $pdo->query("
  SELECT i.*, u.simbolo
  , ROUND(i.preco * i.fator_correcao, 4) AS custo_corrigido
  FROM lc_insumos i
  JOIN lc_unidades u ON u.id = i.unidade_id
  ORDER BY i.ativo DESC, i.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Configurações | Painel Smile PRO</title>
  <link rel="stylesheet" href="estilo.css">
  <style>
    .tabs a { padding:8px 12px; display:inline-block; border-bottom:2px solid transparent; color:#0a4; text-decoration:none; }
    .tabs a.active { border-color:#0a4; font-weight:600; }
    .msg { padding:8px 10px; border-radius:6px; margin:10px 0; background:#e7fff0; border:1px solid #bfe9cc; }
    .err { padding:8px 10px; border-radius:6px; margin:10px 0; background:#ffecec; border:1px solid #ffb3b3; }
    table { width:100%; border-collapse:collapse; margin-top:8px; }
    th, td { border:1px solid #e5e5e5; padding:6px 8px; text-align:left; }
    th { background:#f7f9fb; }
    .row-new { background:#fbfff5; }
    .actions { white-space:nowrap; }
    .small { font-size:12px; color:#555; }
    .badge { padding:2px 6px; border-radius:8px; font-size:12px; background:#eef; border:1px solid #ccd; }
  </style>
</head>
<body>
  <h1>Configurações</h1>

  <div class="tabs">
    <a href="?tab=categorias" class="<?= $tab==='categorias'?'active':'' ?>">Categorias</a>
    <a href="?tab=unidades"   class="<?= $tab==='unidades'  ?'active':'' ?>">Unidades</a>
    <a href="?tab=insumos"    class="<?= $tab==='insumos'   ?'active':'' ?>">Insumos</a>
  </div>

  <?php if ($msg): ?><div class="msg"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>

  <?php if ($tab==='categorias'): ?>
    <h2>Categorias</h2>
    <form method="post">
      <input type="hidden" name="action" value="save_categoria">
      <input type="hidden" name="tab" value="categorias">
      <table>
        <thead>
          <tr><th>#</th><th>Nome</th><th>Ordem</th><th>Ativa</th><th class="actions">Ação</th></tr>
        </thead>
        <tbody>
          <?php foreach ($cat as $c): ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td><input type="text" name="nome" value="<?=h($c['nome'])?>" required></td>
              <td><input type="number" name="ordem" value="<?= (int)$c['ordem'] ?>" style="width:90px"></td>
              <td>
                <select name="ativo" style="width:90px">
                  <option value="1" <?= $c['ativo']?'selected':''?>>Sim</option>
                  <option value="0" <?= !$c['ativo']?'selected':''?>>Não</option>
                </select>
              </td>
              <td class="actions">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button type="submit">Salvar</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr class="row-new">
            <td>novo</td>
            <td><input type="text" name="nome" placeholder="Nome da categoria" required></td>
            <td><input type="number" name="ordem" value="0" style="width:90px"></td>
            <td>
              <select name="ativo" style="width:90px">
                <option value="1" selected>Sim</option>
                <option value="0">Não</option>
              </select>
            </td>
            <td class="actions">
              <input type="hidden" name="id" value="">
              <button type="submit">Adicionar</button>
            </td>
          </tr>
        </tbody>
      </table>
    </form>
  <?php endif; ?>

  <?php if ($tab==='unidades'): ?>
    <h2>Unidades de medida <span class="small">(conversões via fator_base)</span></h2>
    <form method="post">
      <input type="hidden" name="action" value="save_unidade">
      <input type="hidden" name="tab" value="unidades">
      <table>
        <thead>
          <tr><th>#</th><th>Nome</th><th>Símbolo</th><th>Tipo</th><th>Fator base</th><th>Ativa</th><th class="actions">Ação</th></tr>
        </thead>
        <tbody>
          <?php foreach ($uni as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><input type="text" name="nome" value="<?=h($u['nome'])?>" required></td>
              <td><input type="text" name="simbolo" value="<?=h($u['simbolo'])?>" required style="width:90px"></td>
              <td>
                <select name="tipo">
                  <?php foreach (['massa','volume','unidade','embalagem','outro'] as $t): ?>
                    <option value="<?=$t?>" <?= $u['tipo']===$t?'selected':''?>><?=$t?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" step="0.000001" name="fator_base" value="<?=h($u['fator_base'])?>" style="width:140px"></td>
              <td>
                <select name="ativo" style="width:90px">
                  <option value="1" <?= $u['ativo']?'selected':''?>>Sim</option>
                  <option value="0" <?= !$u['ativo']?'selected':''?>>Não</option>
                </select>
              </td>
              <td class="actions">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button type="submit">Salvar</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr class="row-new">
            <td>novo</td>
            <td><input type="text" name="nome" placeholder="Quilograma" required></td>
            <td><input type="text" name="simbolo" placeholder="kg" required style="width:90px"></td>
            <td>
              <select name="tipo">
                <option>massa</option><option>volume</option><option>unidade</option><option>embalagem</option><option>outro</option>
              </select>
            </td>
            <td><input type="number" step="0.000001" name="fator_base" value="1.000000" style="width:140px"></td>
            <td>
              <select name="ativo" style="width:90px">
                <option value="1" selected>Sim</option>
                <option value="0">Não</option>
              </select>
            </td>
            <td class="actions">
              <input type="hidden" name="id" value="">
              <button type="submit">Adicionar</button>
            </td>
          </tr>
        </tbody>
      </table>
    </form>
  <?php endif; ?>

  <?php if ($tab==='insumos'): ?>
    <h2>Insumos <span class="small">(Custo corrigido = Preço × FC)</span></h2>
    <form method="post">
      <input type="hidden" name="action" value="save_insumo">
      <input type="hidden" name="tab" value="insumos">
      <table>
        <thead>
          <tr>
            <th>#</th><th>Nome</th><th>Unid.</th><th>Preço</th><th>FC</th><th>Custo corrigido</th>
            <th>Tipo padrão</th><th>Fornecedor</th><th>Obs.</th><th>Ativo</th><th class="actions">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ins as $i): ?>
            <tr>
              <td><?= (int)$i['id'] ?></td>
              <td><input type="text" name="nome" value="<?=h($i['nome'])?>" required></td>
              <td>
                <select name="unidade_id" required>
                  <?php foreach ($uni as $u): ?>
                    <option value="<?=$u['id']?>" <?= ($i['unidade_id']==$u['id']?'selected':'')?>>
                      <?=h($u['simbolo'])?> (<?=h($u['nome'])?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" step="0.0001" name="preco" value="<?=h($i['preco'])?>" style="width:120px"></td>
              <td><input type="number" step="0.000001" name="fator_correcao" value="<?=h($i['fator_correcao'])?>" style="width:120px"></td>
              <td><span class="badge"><?= number_format($i['custo_corrigido'], 4, ',', '.') . ' / ' . h($i['simbolo']) ?></span></td>
              <td>
                <select name="tipo_padrao">
                  <?php foreach (['comprado','preparo','fixo'] as $t): ?>
                    <option value="<?=$t?>" <?= $i['tipo_padrao']===$t?'selected':''?>><?=$t?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" name="fornecedor_id" value="<?=h($i['fornecedor_id'])?>" placeholder="(opcional)" style="width:110px"></td>
              <td><input type="text" name="observacao" value="<?=h($i['observacao'])?>"></td>
              <td>
                <select name="ativo" style="width:90px">
                  <option value="1" <?= $i['ativo']?'selected':''?>>Sim</option>
                  <option value="0" <?= !$i['ativo']?'selected':''?>>Não</option>
                </select>
              </td>
              <td class="actions">
                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                <button type="submit">Salvar</button>
              </td>
            </tr>
          <?php endforeach; ?>

          <tr class="row-new">
            <td>novo</td>
            <td><input type="text" name="nome" placeholder="Peito de frango" required></td>
            <td>
              <select name="unidade_id" required>
                <?php foreach ($uni as $u): ?>
                  <option value="<?=$u['id']?>"><?=h($u['simbolo'])?> (<?=h($u['nome'])?>)</option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" step="0.0001" name="preco" placeholder="10.90" style="width:120px"></td>
            <td><input type="number" step="0.000001" name="fator_correcao" value="1.000000" style="width:120px"></td>
            <td><span class="badge">—</span></td>
            <td>
              <select name="tipo_padrao">
                <option>comprado</option><option>preparo</option><option>fixo</option>
              </select>
            </td>
            <td><input type="text" name="fornecedor_id" placeholder="(opcional)" style="width:110px"></td>
            <td><input type="text" name="observacao" placeholder="ex.: marca preferida"></td>
            <td>
              <select name="ativo" style="width:90px">
                <option value="1" selected>Sim</option>
                <option value="0">Não</option>
              </select>
            </td>
            <td class="actions">
              <input type="hidden" name="id" value="">
              <button type="submit">Adicionar</button>
            </td>
          </tr>

        </tbody>
      </table>
    </form>
  <?php endif; ?>

</body>
</html>
