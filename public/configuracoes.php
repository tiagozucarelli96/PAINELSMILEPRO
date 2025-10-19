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
    // Verificar se a tabela existe
    $tableExists = $pdo->query("
      SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'public' AND table_name = 'lc_insumos'
      )
    ")->fetchColumn();
    
    if (!$tableExists) {
      throw new Exception('Tabela lc_insumos não existe. Execute o script de criação de tabelas primeiro.');
    }
    
    $id     = input('id');
    $nome   = input('nome');
    $unid   = (int)input('unidade_id');
    $preco  = (float)str_replace(',', '.', input('preco','0'));
    $fc     = (float)str_replace(',', '.', input('fator_correcao','1'));
    $emb    = input('embalagem_multiplo');
    $emb    = ($emb === '' ? null : (float)str_replace(',', '.', $emb));
    $tipo   = input('tipo_padrao','comprado'); // comprado | preparo | fixo
    $forn   = input('fornecedor_id'); // opcional (FK futura)
    $obs    = input('observacao');
    $ativo  = bool01(input('ativo','1'));

    if ($nome === '') throw new Exception('Nome do insumo é obrigatório.');
    if ($unid <= 0) throw new Exception('Unidade é obrigatória.');
    if ($preco < 0) throw new Exception('Preço não pode ser negativo.');
    if ($fc < 1) throw new Exception('Fator de correção (FC) deve ser >= 1,00.');

    // Verificar quais colunas existem na tabela
    $cols = $pdo->query("
      SELECT column_name 
      FROM information_schema.columns 
      WHERE table_name = 'lc_insumos'
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    // Buscar símbolo da unidade para salvar em unidade_padrao
    $unidadeSimbolo = '';
    if ($unid > 0) {
      $unidadeStmt = $pdo->prepare("SELECT simbolo FROM lc_unidades WHERE id = ?");
      $unidadeStmt->execute([$unid]);
      $unidadeSimbolo = $unidadeStmt->fetchColumn() ?: '';
    }
    
    if ($id === '') {
      // INSERT - usar colunas corretas
      $insertCols = ['nome', 'unidade_padrao', 'unidade'];
      $insertVals = [':n', ':up', ':u'];
      $params = [':n'=>$nome, ':up'=>$unidadeSimbolo, ':u'=>$unidadeSimbolo];
      
      if (in_array('custo_unit', $cols)) { $insertCols[] = 'custo_unit'; $insertVals[] = ':p'; $params[':p'] = $preco; }
      if (in_array('aquisicao', $cols)) { $insertCols[] = 'aquisicao'; $insertVals[] = ':aq'; $params[':aq'] = $tipo; }
      if (in_array('fornecedor_id', $cols)) { $insertCols[] = 'fornecedor_id'; $insertVals[] = ':f'; $params[':f'] = ($forn === '' ? null : $forn); }
      if (in_array('observacoes', $cols)) { $insertCols[] = 'observacoes'; $insertVals[] = ':o'; $params[':o'] = $obs; }
      if (in_array('embalagem_multiplo', $cols)) { $insertCols[] = 'embalagem_multiplo'; $insertVals[] = ':emb'; $params[':emb'] = $emb; }
      
      $sql = "INSERT INTO lc_insumos (" . implode(',', $insertCols) . ") VALUES (" . implode(',', $insertVals) . ")";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $msg = 'Insumo criado.';
    } else {
      // UPDATE - usar colunas corretas
      $updateParts = ['nome=:n', 'unidade_padrao=:up', 'unidade=:u'];
      $params = [':n'=>$nome, ':up'=>$unidadeSimbolo, ':u'=>$unidadeSimbolo, ':id'=>$id];
      
      if (in_array('custo_unit', $cols)) { $updateParts[] = 'custo_unit=:p'; $params[':p'] = $preco; }
      if (in_array('aquisicao', $cols)) { $updateParts[] = 'aquisicao=:aq'; $params[':aq'] = $tipo; }
      if (in_array('fornecedor_id', $cols)) { $updateParts[] = 'fornecedor_id=:f'; $params[':f'] = ($forn === '' ? null : $forn); }
      if (in_array('observacoes', $cols)) { $updateParts[] = 'observacoes=:o'; $params[':o'] = $obs; }
      if (in_array('embalagem_multiplo', $cols)) { $updateParts[] = 'embalagem_multiplo=:emb'; $params[':emb'] = $emb; }
      
      $sql = "UPDATE lc_insumos SET " . implode(', ', $updateParts) . " WHERE id=:id";
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $msg = 'Insumo atualizado.';
    }
    $tab = 'insumos';
  }

  // ITENS FIXOS
  if (input('action') === 'save_item_fixo') {
    // Verificar se a tabela existe
    $tableExists = $pdo->query("
      SELECT EXISTS (
        SELECT FROM information_schema.tables 
        WHERE table_schema = 'public' AND table_name = 'lc_itens_fixos'
      )
    ")->fetchColumn();
    
    if (!$tableExists) {
      throw new Exception('Tabela lc_itens_fixos não existe. Execute o script de criação de tabelas primeiro.');
    }
    
    $id   = input('id');
    $ins  = (int)input('insumo_id');
    $qtd  = (float)str_replace(',', '.', input('qtd','0'));
    $unid = (int)input('unidade_id');
    $obs  = input('observacao');
    $ativo= bool01(input('ativo','1'));

    if ($ins <= 0) throw new Exception('Selecione um insumo.');
    if ($unid <= 0) throw new Exception('Selecione a unidade.');
    if ($qtd <= 0) throw new Exception('Quantidade > 0 é obrigatória.');

    if ($id === '') {
      $stmt = $pdo->prepare("INSERT INTO lc_itens_fixos (insumo_id, qtd, unidade_id, observacao, ativo)
                             VALUES (:i,:q,:u,:o,:a)");
      $stmt->execute([':i'=>$ins, ':q'=>$qtd, ':u'=>$unid, ':o'=>$obs, ':a'=>$ativo]);
      $msg = 'Item fixo criado.';
    } else {
      $stmt = $pdo->prepare("UPDATE lc_itens_fixos
                             SET insumo_id=:i, qtd=:q, unidade_id=:u, observacao=:o, ativo=:a
                             WHERE id=:id");
      $stmt->execute([':i'=>$ins, ':q'=>$qtd, ':u'=>$unid, ':o'=>$obs, ':a'=>$ativo, ':id'=>$id]);
      $msg = 'Item fixo atualizado.';
    }
    $tab = 'fixos';
  }

} catch (Exception $e) {
  $err = $e->getMessage();
}

// --- LISTAGENS ---
$cat = $pdo->query("SELECT * FROM lc_categorias ORDER BY ativo DESC, ordem ASC, nome ASC")->fetchAll(PDO::FETCH_ASSOC);
$uni = $pdo->query("SELECT * FROM lc_unidades   ORDER BY ativo DESC, tipo ASC, nome ASC")->fetchAll(PDO::FETCH_ASSOC);
// Tentar carregar insumos de forma segura
$ins = [];
try {
    // Primeiro, verificar quais colunas existem
    $cols = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'lc_insumos'
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('custo_unit', $cols)) {
        // Estrutura com custo
        $ins = $pdo->query("
          SELECT i.*, u.simbolo
          , i.custo_unit AS custo_corrigido
          FROM lc_insumos i
          LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
          ORDER BY i.nome ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Estrutura básica
        $ins = $pdo->query("
          SELECT i.*, u.simbolo
          FROM lc_insumos i
          LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
          ORDER BY i.nome ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Se der erro, carregar sem JOIN
    $ins = $pdo->query("SELECT * FROM lc_insumos ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
}
// Carregar itens fixos de forma segura
$fixos = [];
try {
    $fixos = $pdo->query("
      SELECT f.*, i.nome AS insumo_nome, u.simbolo AS unidade_simbolo
      FROM lc_itens_fixos f
      LEFT JOIN lc_insumos i ON i.id = f.insumo_id
      LEFT JOIN lc_unidades u ON u.id = f.unidade_id
      ORDER BY f.ativo DESC, i.nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Se der erro, carregar sem JOIN
    $fixos = $pdo->query("SELECT * FROM lc_itens_fixos ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

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
    <a href="?tab=fixos" class="<?= $tab==='fixos'?'active':'' ?>">Itens Fixos</a>
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
    <h2>Insumos <span class="small">(Estrutura: custo_unit, aquisição, embalagem_multiplo)</span></h2>
    <form method="post">
      <input type="hidden" name="action" value="save_insumo">
      <input type="hidden" name="tab" value="insumos">
      <table>
        <thead>
          <tr>
            <th>#</th><th>Nome</th><th>Unid.</th><th>Custo Unit.</th><th>FC</th><th>Emb. (múltiplo)</th><th>Custo</th>
            <th>Aquisição</th><th>Fornecedor</th><th>Obs.</th><th>Ativo</th><th class="actions">Ação</th>
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
                    <option value="<?=$u['id']?>" <?= ($i['unidade_padrao']==$u['simbolo']?'selected':'')?>>
                      <?=h($u['simbolo'])?> (<?=h($u['nome'])?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" step="0.0001" name="preco" value="<?=h($i['custo_unit'] ?? '')?>" style="width:120px"></td>
              <td><input type="number" step="0.000001" name="fator_correcao" value="1.000000" style="width:120px" disabled title="FC não disponível nesta estrutura"></td>
              <td><input type="number" step="0.000001" name="embalagem_multiplo" value="<?= h($i['embalagem_multiplo'] ?? '') ?>" style="width:120px" placeholder="ex.: 50"></td>
              <td><span class="badge"><?= number_format($i['custo_corrigido'] ?? 0, 4, ',', '.') . ' / ' . h($i['simbolo'] ?? '') ?></span></td>
              <td>
                <select name="tipo_padrao">
                  <?php foreach (['mercado','preparo','fixo'] as $t): ?>
                    <option value="<?=$t?>" <?= ($i['aquisicao'] ?? '')===$t?'selected':''?>><?=$t?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" name="fornecedor_id" value="<?=h($i['fornecedor_id'] ?? '')?>" placeholder="(opcional)" style="width:110px"></td>
              <td><input type="text" name="observacao" value="<?=h($i['observacoes'] ?? '')?>"></td>
              <td>
                <select name="ativo" style="width:90px">
                  <option value="1" selected>Sim</option>
                  <option value="0">Não</option>
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
            <td><input type="number" step="0.000001" name="fator_correcao" value="1.000000" style="width:120px" disabled title="FC não disponível nesta estrutura"></td>
            <td><input type="number" step="0.000001" name="embalagem_multiplo" placeholder="ex.: 50" style="width:120px"></td>
            <td><span class="badge">—</span></td>
            <td>
              <select name="tipo_padrao">
                <option value="mercado" selected>mercado</option><option value="preparo">preparo</option><option value="fixo">fixo</option>
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

  <?php if ($tab==='fixos'): ?>
    <h2>Itens Fixos (1× por evento)</h2>
    <form method="post">
      <input type="hidden" name="action" value="save_item_fixo">
      <input type="hidden" name="tab" value="fixos">
      <table>
        <thead>
          <tr>
            <th>#</th><th>Insumo</th><th>Qtd</th><th>Unidade</th><th>Obs.</th><th>Ativo</th><th class="actions">Ação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fixos as $f): ?>
            <tr>
              <td><?= (int)$f['id'] ?></td>
              <td>
                <select name="insumo_id" required>
                  <?php foreach ($ins as $i2): ?>
                    <option value="<?= $i2['id'] ?>" <?= ($f['insumo_id']==$i2['id']?'selected':'') ?>>
                      <?= h($i2['nome']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" step="0.000001" name="qtd" value="<?= h($f['qtd']) ?>"></td>
              <td>
                <select name="unidade_id" required>
                  <?php foreach ($uni as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= ($f['unidade_id']==$u['id']?'selected':'') ?>>
                      <?= h($u['simbolo']) ?> (<?= h($u['nome']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="text" name="observacao" value="<?= h($f['observacao']) ?>"></td>
              <td>
                <select name="ativo">
                  <option value="1" <?= $f['ativo']?'selected':'' ?>>Sim</option>
                  <option value="0" <?= !$f['ativo']?'selected':'' ?>>Não</option>
                </select>
              </td>
              <td class="actions">
                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                <button type="submit">Salvar</button>
              </td>
            </tr>
          <?php endforeach; ?>

          <tr class="row-new">
            <td>novo</td>
            <td>
              <select name="insumo_id" required>
                <option value="">— selecione —</option>
                <?php foreach ($ins as $i2): ?>
                  <option value="<?= $i2['id'] ?>"><?= h($i2['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" step="0.000001" name="qtd" placeholder="ex.: 1"></td>
            <td>
              <select name="unidade_id" required>
                <?php foreach ($uni as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= h($u['simbolo']) ?> (<?= h($u['nome']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="observacao" placeholder="(opcional)"></td>
            <td>
              <select name="ativo"><option value="1" selected>Sim</option><option value="0">Não</option></select>
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
