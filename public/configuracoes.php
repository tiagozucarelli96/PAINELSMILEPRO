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

  // EXCLUIR CATEGORIA
  if (input('action') === 'delete_categoria') {
    $id = input('id');
    if ($id === '') throw new Exception('ID da categoria é obrigatório.');
    
    // Verificar se há insumos usando esta categoria
    $check = $pdo->prepare("SELECT COUNT(*) FROM lc_insumos WHERE categoria_id = ?");
    $check->execute([$id]);
    $count = $check->fetchColumn();
    
    if ($count > 0) {
      throw new Exception("Não é possível excluir esta categoria pois há $count insumo(s) vinculado(s) a ela.");
    }
    
    // Verificar se há receitas usando esta categoria
    $check2 = $pdo->prepare("SELECT COUNT(*) FROM lc_receitas WHERE categoria_id = ?");
    $check2->execute([$id]);
    $count2 = $check2->fetchColumn();
    
    if ($count2 > 0) {
      throw new Exception("Não é possível excluir esta categoria pois há $count2 receita(s) vinculada(s) a ela.");
    }
    
    $stmt = $pdo->prepare("DELETE FROM lc_categorias WHERE id = ?");
    $stmt->execute([$id]);
    $msg = 'Categoria excluída.';
    $tab = 'categorias';
  }

  // EXCLUIR INSUMO
  if (input('action') === 'delete_insumo') {
    $id = input('id');
    if ($id === '') throw new Exception('ID do insumo é obrigatório.');
    
    // Verificar se há itens fixos usando este insumo
    $check = $pdo->prepare("SELECT COUNT(*) FROM lc_itens_fixos WHERE insumo_id = ?");
    $check->execute([$id]);
    $count = $check->fetchColumn();
    
    if ($count > 0) {
      throw new Exception("Não é possível excluir este insumo pois há $count item(s) fixo(s) vinculado(s) a ele.");
    }
    
    // Verificar se há componentes de receita usando este insumo
    $check2 = $pdo->prepare("SELECT COUNT(*) FROM lc_receita_componentes WHERE insumo_id = ?");
    $check2->execute([$id]);
    $count2 = $check2->fetchColumn();
    
    if ($count2 > 0) {
      throw new Exception("Não é possível excluir este insumo pois há $count2 componente(s) de receita vinculado(s) a ele.");
    }
    
    $stmt = $pdo->prepare("DELETE FROM lc_insumos WHERE id = ?");
    $stmt->execute([$id]);
    $msg = 'Insumo excluído.';
    $tab = 'insumos';
  }

  // EXCLUIR UNIDADE
  if (input('action') === 'delete_unidade') {
    $id = input('id');
    if ($id === '') throw new Exception('ID da unidade é obrigatório.');
    
    // Verificar se há insumos usando esta unidade
    $check = $pdo->prepare("SELECT COUNT(*) FROM lc_insumos WHERE unidade_id = ? OR unidade_padrao = (SELECT simbolo FROM lc_unidades WHERE id = ?)");
    $check->execute([$id, $id]);
    $count = $check->fetchColumn();
    
    if ($count > 0) {
      throw new Exception("Não é possível excluir esta unidade pois há $count insumo(s) vinculado(s) a ela.");
    }
    
    // Verificar se há itens fixos usando esta unidade
    $check2 = $pdo->prepare("SELECT COUNT(*) FROM lc_itens_fixos WHERE unidade_id = ?");
    $check2->execute([$id]);
    $count2 = $check2->fetchColumn();
    
    if ($count2 > 0) {
      throw new Exception("Não é possível excluir esta unidade pois há $count2 item(s) fixo(s) vinculado(s) a ela.");
    }
    
    // Verificar se há componentes de receita usando esta unidade
    $check3 = $pdo->prepare("SELECT COUNT(*) FROM lc_receita_componentes WHERE unidade_id = ?");
    $check3->execute([$id]);
    $count3 = $check3->fetchColumn();
    
    if ($count3 > 0) {
      throw new Exception("Não é possível excluir esta unidade pois há $count3 componente(s) de receita vinculado(s) a ela.");
    }
    
    $stmt = $pdo->prepare("DELETE FROM lc_unidades WHERE id = ?");
    $stmt->execute([$id]);
    $msg = 'Unidade excluída.';
    $tab = 'unidades';
  }

  // EXCLUIR ITEM FIXO
  if (input('action') === 'delete_item_fixo') {
    $id = input('id');
    if ($id === '') throw new Exception('ID do item fixo é obrigatório.');
    
    $stmt = $pdo->prepare("DELETE FROM lc_itens_fixos WHERE id = ?");
    $stmt->execute([$id]);
    $msg = 'Item fixo excluído.';
    $tab = 'fixos';
  }

  // SALVAR RECEITA
  if (input('action') === 'save_receita') {
    $id = input('id');
    $nome = input('nome');
    $descricao = input('descricao');
    $rendimento = (int)input('rendimento', 1);
    $quantia_por_pessoa = (float)str_replace(',', '.', input('quantia_por_pessoa', '1'));
    $categoria_id = input('categoria_id');
    $ativo = bool01(input('ativo', '1'));

    if ($nome === '') throw new Exception('Nome da receita é obrigatório.');
    if ($rendimento < 1) throw new Exception('Rendimento deve ser maior que zero.');
    if ($quantia_por_pessoa <= 0) throw new Exception('Quantia por pessoa deve ser maior que zero.');

    if ($id === '') {
      // INSERT
      $stmt = $pdo->prepare("
        INSERT INTO lc_receitas (nome, descricao, rendimento, quantia_por_pessoa, categoria_id, ativo)
        VALUES (:n, :d, :r, :q, :c, :a)
      ");
      $stmt->execute([
        ':n' => $nome,
        ':d' => $descricao,
        ':r' => $rendimento,
        ':q' => $quantia_por_pessoa,
        ':c' => ($categoria_id === '' ? null : $categoria_id),
        ':a' => $ativo
      ]);
      $msg = 'Receita criada.';
    } else {
      // UPDATE
      $stmt = $pdo->prepare("
        UPDATE lc_receitas 
        SET nome=:n, descricao=:d, rendimento=:r, quantia_por_pessoa=:q, categoria_id=:c, ativo=:a
        WHERE id=:id
      ");
      $stmt->execute([
        ':n' => $nome,
        ':d' => $descricao,
        ':r' => $rendimento,
        ':q' => $quantia_por_pessoa,
        ':c' => ($categoria_id === '' ? null : $categoria_id),
        ':a' => $ativo,
        ':id' => $id
      ]);
      $msg = 'Receita atualizada.';
    }
    $tab = 'receitas';
  }

  // EXCLUIR RECEITA
  if (input('action') === 'delete_receita') {
    $id = input('id');
    if ($id === '') throw new Exception('ID da receita é obrigatório.');
    
    // Verificar se há componentes usando esta receita
    $check = $pdo->prepare("SELECT COUNT(*) FROM lc_receita_componentes WHERE receita_id = ?");
    $check->execute([$id]);
    $count = $check->fetchColumn();
    
    if ($count > 0) {
      throw new Exception("Não é possível excluir esta receita pois há $count componente(s) vinculado(s) a ela.");
    }
    
    $stmt = $pdo->prepare("DELETE FROM lc_receitas WHERE id = ?");
    $stmt->execute([$id]);
    $msg = 'Receita excluída.';
    $tab = 'receitas';
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
        WHERE table_schema = 'smilee12_painel_smile' AND table_name = 'lc_insumos'
      )
    ")->fetchColumn();
    
    if (!$tableExists) {
      throw new Exception('Tabela lc_insumos não existe. Execute o script de criação de tabelas primeiro.');
    }
    
    $id     = input('id');
    $nome   = input('nome');
    $categoria = (int)input('categoria_id');
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
      
      if (in_array('categoria_id', $cols)) { $insertCols[] = 'categoria_id'; $insertVals[] = ':cat'; $params[':cat'] = ($categoria > 0 ? $categoria : null); }
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
      
      if (in_array('categoria_id', $cols)) { $updateParts[] = 'categoria_id=:cat'; $params[':cat'] = ($categoria > 0 ? $categoria : null); }
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
          SELECT i.*, u.simbolo, c.nome AS categoria_nome, c.id AS categoria_id
          , i.custo_unit AS custo_corrigido
          FROM lc_insumos i
          LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
          LEFT JOIN lc_categorias c ON c.id = i.categoria_id
          ORDER BY c.nome ASC, i.nome ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Estrutura básica
$ins = $pdo->query("
          SELECT i.*, u.simbolo, c.nome AS categoria_nome, c.id AS categoria_id
  FROM lc_insumos i
          LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
          LEFT JOIN lc_categorias c ON c.id = i.categoria_id
          ORDER BY c.nome ASC, i.nome ASC
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

// Carregar receitas
$receitas = [];
try {
    $receitas = $pdo->query("
      SELECT r.*, c.nome AS categoria_nome
      FROM lc_receitas r
      LEFT JOIN lc_categorias c ON c.id = r.categoria_id
      ORDER BY r.ativo DESC, r.nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Se der erro, carregar sem JOIN
    $receitas = $pdo->query("SELECT * FROM lc_receitas ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Configurações | Painel Smile PRO</title>
  <link rel="stylesheet" href="estilo.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="main-layout">
  <!-- Sidebar Moderna -->
  <?php include 'sidebar_moderna.php'; ?>

  <!-- Conteúdo Principal -->
  <main class="main-content">
    <div class="page-header">
      <div class="flex items-center gap-4 mb-4">
        <a href="lc_index.php" class="btn btn-outline">
          <span>←</span> Voltar
        </a>
        <div>
          <h1 class="page-title">Configurações</h1>
          <p class="page-subtitle">Gerencie categorias, unidades, insumos e itens fixos do sistema</p>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs-container">
  <div class="tabs">
        <a href="?tab=categorias" class="tab <?= $tab==='categorias'?'active':'' ?>">
          <span>📂</span> Categorias
        </a>
        <a href="?tab=unidades" class="tab <?= $tab==='unidades'?'active':'' ?>">
          <span>📏</span> Unidades
        </a>
        <a href="?tab=insumos" class="tab <?= $tab==='insumos'?'active':'' ?>">
          <span>🥘</span> Insumos
        </a>
        <a href="?tab=receitas" class="tab <?= $tab==='receitas'?'active':'' ?>">
          <span>👨‍🍳</span> Receitas
        </a>
        <a href="?tab=fixos" class="tab <?= $tab==='fixos'?'active':'' ?>">
          <span>📌</span> Itens Fixos
        </a>
  </div>

      <div class="tab-content">
        <!-- Mensagens -->
        <?php if ($msg): ?>
          <div class="alert alert-success animate-fade-in">
            <span>✅</span> <?=h($msg)?>
          </div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="alert alert-error animate-fade-in">
            <span>❌</span> <?=h($err)?>
          </div>
        <?php endif; ?>

  <?php if ($tab==='categorias'): ?>
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">📂 Categorias</h2>
              <p class="text-sm text-gray-600">Organize seus produtos por categorias para facilitar a gestão</p>
            </div>
            <div class="card-body">
              <form method="post" class="animate-fade-in">
      <input type="hidden" name="action" value="save_categoria">
      <input type="hidden" name="tab" value="categorias">
                
                <div class="table-container">
                  <table class="table">
        <thead>
                      <tr>
                        <th>ID</th>
                        <th>Nome da Categoria</th>
                        <th>Ordem</th>
                        <th>Status</th>
                        <th class="text-center">Ação</th>
                      </tr>
        </thead>
        <tbody>
          <?php foreach ($cat as $c): ?>
                        <tr class="animate-slide-in">
                          <td>
                            <span class="badge"><?= (int)$c['id'] ?></span>
                          </td>
                          <td>
                            <input type="text" name="nome" value="<?=h($c['nome'])?>" 
                                   class="form-input" required 
                                   placeholder="Nome da categoria">
                          </td>
                          <td>
                            <input type="number" name="ordem" value="<?= (int)$c['ordem'] ?>" 
                                   class="form-input" style="width: 80px; text-align: center;"
                                   min="0" max="999">
                          </td>
                          <td>
                            <select name="ativo" class="form-select" style="width: 100px;">
                              <option value="1" <?= $c['ativo']?'selected':''?>>Ativa</option>
                              <option value="0" <?= !$c['ativo']?'selected':''?>>Inativa</option>
                </select>
              </td>
                          <td class="text-center">
                            <div class="flex gap-2 justify-center">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                              <button type="submit" class="btn btn-primary btn-sm">
                                <span>💾</span> Salvar
                              </button>
                              <button type="submit" class="btn btn-outline btn-sm" 
                                      onclick="return confirm('Tem certeza que deseja excluir esta categoria?')"
                                      formaction="?action=delete_categoria&tab=categorias&id=<?= (int)$c['id'] ?>">
                                <span>🗑️</span> Excluir
                              </button>
                            </div>
              </td>
            </tr>
          <?php endforeach; ?>
                      
                      <tr class="row-new animate-fade-in">
                        <td>
                          <span class="badge badge-success">NOVO</span>
                        </td>
                        <td>
                          <input type="text" name="nome" placeholder="Digite o nome da nova categoria" 
                                 class="form-input" required>
                        </td>
                        <td>
                          <input type="number" name="ordem" value="0" 
                                 class="form-input" style="width: 80px; text-align: center;"
                                 min="0" max="999">
                        </td>
                        <td>
                          <select name="ativo" class="form-select" style="width: 100px;">
                            <option value="1" selected>Ativa</option>
                            <option value="0">Inativa</option>
              </select>
            </td>
                        <td class="text-center">
              <input type="hidden" name="id" value="">
                          <button type="submit" class="btn btn-success btn-sm">
                            <span>➕</span> Adicionar
                          </button>
            </td>
          </tr>
        </tbody>
      </table>
                </div>
    </form>
            </div>
          </div>
  <?php endif; ?>

  <?php if ($tab==='unidades'): ?>
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">📏 Unidades de Medida</h2>
              <p class="text-sm text-gray-600">Configure unidades para conversões automáticas via fator base</p>
            </div>
            <div class="card-body">
              <form method="post" class="animate-fade-in">
      <input type="hidden" name="action" value="save_unidade">
      <input type="hidden" name="tab" value="unidades">
                
                <div class="table-container">
                  <table class="table">
        <thead>
                      <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Símbolo</th>
                        <th>Tipo</th>
                        <th>Fator Base</th>
                        <th>Status</th>
                        <th class="text-center">Ação</th>
                      </tr>
        </thead>
        <tbody>
          <?php foreach ($uni as $u): ?>
                        <tr class="animate-slide-in">
                          <td>
                            <span class="badge"><?= (int)$u['id'] ?></span>
                          </td>
                          <td>
                            <input type="text" name="nome" value="<?=h($u['nome'])?>" 
                                   class="form-input" required 
                                   placeholder="Ex: Quilograma">
                          </td>
                          <td>
                            <input type="text" name="simbolo" value="<?=h($u['simbolo'])?>" 
                                   class="form-input" required 
                                   style="width: 80px; text-align: center;"
                                   placeholder="kg">
                          </td>
                          <td>
                            <select name="tipo" class="form-select" style="width: 120px;">
                              <?php 
                              $tipos = [
                                'massa' => 'Massa',
                                'volume' => 'Volume', 
                                'unidade' => 'Unidade',
                                'embalagem' => 'Embalagem',
                                'outro' => 'Outro'
                              ];
                              foreach ($tipos as $t => $label): ?>
                                <option value="<?=$t?>" <?= $u['tipo']===$t?'selected':''?>><?=$label?></option>
                  <?php endforeach; ?>
                </select>
              </td>
                          <td>
                            <input type="number" step="0.000001" name="fator_base" 
                                   value="<?=h($u['fator_base'])?>" 
                                   class="form-input" 
                                   style="width: 120px; text-align: center;"
                                   min="0.000001" max="999999.999999">
                          </td>
                          <td>
                            <select name="ativo" class="form-select" style="width: 100px;">
                              <option value="1" <?= $u['ativo']?'selected':''?>>Ativa</option>
                              <option value="0" <?= !$u['ativo']?'selected':''?>>Inativa</option>
                </select>
              </td>
                          <td class="text-center">
                            <div class="flex gap-2 justify-center">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                              <button type="submit" class="btn btn-primary btn-sm">
                                <span>💾</span> Salvar
                              </button>
                              <button type="submit" class="btn btn-outline btn-sm" 
                                      onclick="return confirm('Tem certeza que deseja excluir esta unidade?')"
                                      formaction="?action=delete_unidade&tab=unidades&id=<?= (int)$u['id'] ?>">
                                <span>🗑️</span> Excluir
                              </button>
                            </div>
              </td>
            </tr>
          <?php endforeach; ?>
                      
                      <tr class="row-new animate-fade-in">
                        <td>
                          <span class="badge badge-success">NOVO</span>
                        </td>
                        <td>
                          <input type="text" name="nome" placeholder="Ex: Quilograma" 
                                 class="form-input" required>
                        </td>
                        <td>
                          <input type="text" name="simbolo" placeholder="kg" 
                                 class="form-input" required 
                                 style="width: 80px; text-align: center;">
                        </td>
                        <td>
                          <select name="tipo" class="form-select" style="width: 120px;">
                            <option value="massa">Massa</option>
                            <option value="volume">Volume</option>
                            <option value="unidade">Unidade</option>
                            <option value="embalagem">Embalagem</option>
                            <option value="outro">Outro</option>
              </select>
            </td>
                        <td>
                          <input type="number" step="0.000001" name="fator_base" 
                                 value="1.000000" 
                                 class="form-input" 
                                 style="width: 120px; text-align: center;"
                                 min="0.000001" max="999999.999999">
                        </td>
                        <td>
                          <select name="ativo" class="form-select" style="width: 100px;">
                            <option value="1" selected>Ativa</option>
                            <option value="0">Inativa</option>
              </select>
            </td>
                        <td class="text-center">
              <input type="hidden" name="id" value="">
                          <button type="submit" class="btn btn-success btn-sm">
                            <span>➕</span> Adicionar
                          </button>
            </td>
          </tr>
        </tbody>
      </table>
                </div>
    </form>
            </div>
          </div>
  <?php endif; ?>

  <?php if ($tab==='insumos'): ?>
          <div class="card">
            <div class="card-header">
              <div class="flex justify-between items-center">
                <div>
                  <h2 class="card-title">🥘 Insumos</h2>
                  <p class="text-sm text-gray-600">Gerencie ingredientes, preços e informações de aquisição</p>
                </div>
                <button onclick="openInsumoModal()" class="btn btn-success">
                  <span>➕</span> Novo Insumo
                </button>
              </div>
            </div>
            <div class="card-body">
              <div class="table-container">
                <table class="table">
        <thead>
          <tr>
                      <th>ID</th>
                      <th>Categoria</th>
                      <th>Nome do Insumo</th>
                      <th>Unidade</th>
                      <th>Custo Unit.</th>
                      <th>Custo Final</th>
                      <th>Aquisição</th>
                      <th>Status</th>
                      <th class="text-center">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ins as $i): ?>
                      <tr class="animate-slide-in">
                        <td><span class="badge"><?= (int)$i['id'] ?></span></td>
                        <td>
                          <span class="badge badge-info">
                            <?= h($i['categoria_nome'] ?? 'Sem categoria') ?>
                          </span>
                        </td>
                        <td><strong><?= h($i['nome']) ?></strong></td>
                        <td><?= h($i['simbolo'] ?? '—') ?></td>
                        <td class="text-right">R$ <?= number_format($i['custo_unit'] ?? 0, 2, ',', '.') ?></td>
                        <td class="text-right">
                          <span class="badge badge-info">
                            R$ <?= number_format($i['custo_corrigido'] ?? 0, 4, ',', '.') ?>
                          </span>
                        </td>
                        <td>
                          <span class="badge">
                            <?= ucfirst($i['aquisicao'] ?? 'Mercado') ?>
                          </span>
                        </td>
                        <td>
                          <span class="badge <?= ($i['ativo'] ?? 1) ? 'badge-success' : 'badge-error' ?>">
                            <?= ($i['ativo'] ?? 1) ? 'Ativo' : 'Inativo' ?>
                          </span>
                        </td>
                        <td class="text-center">
                          <div class="flex gap-2 justify-center">
                            <button onclick="editInsumo(<?= htmlspecialchars(json_encode($i)) ?>)" 
                                    class="btn btn-primary btn-sm">
                              <span>✏️</span> Editar
                            </button>
                            <button onclick="deleteInsumo(<?= (int)$i['id'] ?>)" 
                                    class="btn btn-outline btn-sm">
                              <span>🗑️</span> Excluir
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Modal para Editar/Criar Insumo -->
          <div id="insumoModal" class="modal-overlay" style="display: none;">
            <div class="modal">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title" id="modalTitle">Novo Insumo</h3>
                  <button onclick="closeInsumoModal()" class="btn btn-outline btn-sm">✕</button>
                </div>
                <div class="card-body">
                  <form id="insumoForm" method="post">
                    <input type="hidden" name="action" value="save_insumo">
                    <input type="hidden" name="tab" value="insumos">
                    <input type="hidden" name="id" id="insumoId" value="">
                    
                    <div class="grid grid-cols-2 gap-4">
                      <div class="form-group">
                        <label class="form-label">Categoria</label>
                        <select name="categoria_id" id="categoriaId" class="form-select">
                          <option value="">Sem categoria</option>
                          <?php foreach ($cat as $c): ?>
                            <option value="<?=$c['id']?>"><?=h($c['nome'])?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Nome do Insumo *</label>
                        <input type="text" name="nome" id="insumoNome" class="form-input" required>
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Unidade *</label>
                        <select name="unidade_id" id="unidadeId" class="form-select" required>
                          <option value="">Selecione...</option>
                          <?php foreach ($uni as $u): ?>
                            <option value="<?=$u['id']?>"><?=h($u['simbolo'])?> (<?=h($u['nome'])?>)</option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Custo Unitário</label>
                        <input type="number" step="0.0001" name="preco" id="insumoPreco" 
                               class="form-input" placeholder="0.00">
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Embalagem (múltiplo)</label>
                        <input type="number" step="0.000001" name="embalagem_multiplo" id="embalagemMultiplo" 
                               class="form-input" placeholder="ex: 50">
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Tipo de Aquisição</label>
                        <select name="tipo_padrao" id="tipoPadrao" class="form-select">
                          <option value="mercado">Mercado</option>
                          <option value="preparo">Preparo</option>
                          <option value="fixo">Fixo</option>
                        </select>
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">ID Fornecedor</label>
                        <input type="text" name="fornecedor_id" id="fornecedorId" 
                               class="form-input" placeholder="ID Fornecedor">
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="ativo" id="insumoAtivo" class="form-select">
                          <option value="1">Ativo</option>
                          <option value="0">Inativo</option>
                        </select>
                      </div>
                    </div>
                    
                    <div class="form-group">
                      <label class="form-label">Observações</label>
                      <textarea name="observacao" id="insumoObservacao" class="form-input" 
                                rows="3" placeholder="Observações opcionais"></textarea>
                    </div>
                    
                    <div class="flex gap-2 justify-end mt-4">
                      <button type="button" onclick="closeInsumoModal()" class="btn btn-outline">
                        Cancelar
                      </button>
                      <button type="submit" class="btn btn-primary">
                        <span>💾</span> Salvar
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>

          <script>
          function openInsumoModal() {
            document.getElementById('modalTitle').textContent = 'Novo Insumo';
            document.getElementById('insumoForm').reset();
            document.getElementById('insumoId').value = '';
            document.getElementById('insumoModal').style.display = 'flex';
          }
          
          function editInsumo(insumo) {
            document.getElementById('modalTitle').textContent = 'Editar Insumo';
            document.getElementById('insumoId').value = insumo.id;
            document.getElementById('categoriaId').value = insumo.categoria_id || '';
            document.getElementById('insumoNome').value = insumo.nome || '';
            document.getElementById('unidadeId').value = insumo.unidade_id || '';
            document.getElementById('insumoPreco').value = insumo.custo_unit || '';
            document.getElementById('embalagemMultiplo').value = insumo.embalagem_multiplo || '';
            document.getElementById('tipoPadrao').value = insumo.aquisicao || 'mercado';
            document.getElementById('fornecedorId').value = insumo.fornecedor_id || '';
            document.getElementById('insumoAtivo').value = insumo.ativo || '1';
            document.getElementById('insumoObservacao').value = insumo.observacoes || '';
            document.getElementById('insumoModal').style.display = 'flex';
          }
          
          function closeInsumoModal() {
            document.getElementById('insumoModal').style.display = 'none';
          }
          
          function deleteInsumo(id) {
            if (confirm('Tem certeza que deseja excluir este insumo?')) {
              const form = document.createElement('form');
              form.method = 'post';
              form.innerHTML = `
                <input type="hidden" name="action" value="delete_insumo">
                <input type="hidden" name="tab" value="insumos">
                <input type="hidden" name="id" value="${id}">
              `;
              document.body.appendChild(form);
              form.submit();
            }
          }
          </script>
        <?php endif; ?>

        <?php if ($tab==='receitas'): ?>
          <div class="card">
            <div class="card-header">
              <div class="flex justify-between items-center">
                <div>
                  <h2 class="card-title">👨‍🍳 Receitas</h2>
                  <p class="text-sm text-gray-600">Gerencie receitas e fichas técnicas do sistema</p>
                </div>
                <button onclick="openReceitaModal()" class="btn btn-success">
                  <span>➕</span> Nova Receita
                </button>
              </div>
            </div>
            <div class="card-body">
              <div class="table-container">
                <table class="table">
                  <thead>
                    <tr>
                      <th>ID</th>
                      <th>Categoria</th>
                      <th>Nome da Receita</th>
                      <th>Rendimento</th>
                      <th>Por Pessoa</th>
                      <th>Custo Total</th>
                      <th>Status</th>
                      <th class="text-center">Ações</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($receitas as $r): ?>
                      <tr class="animate-slide-in">
                        <td><span class="badge"><?= (int)$r['id'] ?></span></td>
                        <td>
                          <span class="badge badge-info">
                            <?= h($r['categoria_nome'] ?? 'Sem categoria') ?>
                          </span>
                        </td>
                        <td>
                          <div>
                            <strong><?= h($r['nome']) ?></strong>
                            <?php if ($r['descricao']): ?>
                              <div class="text-sm text-gray-600"><?= h($r['descricao']) ?></div>
                            <?php endif; ?>
                          </div>
                        </td>
                        <td class="text-center">
                          <span class="badge"><?= (int)$r['rendimento'] ?> porções</span>
                        </td>
                        <td class="text-center">
                          <span class="badge"><?= number_format($r['quantia_por_pessoa'], 3, ',', '.') ?></span>
                        </td>
                        <td class="text-right">
                          <span class="badge badge-success">
                            R$ <?= number_format($r['custo_total'], 4, ',', '.') ?>
                          </span>
                        </td>
                        <td>
                          <span class="badge <?= $r['ativo'] ? 'badge-success' : 'badge-error' ?>">
                            <?= $r['ativo'] ? 'Ativa' : 'Inativa' ?>
                          </span>
                        </td>
                        <td class="text-center">
                          <div class="flex gap-2 justify-center">
                            <button onclick="editReceita(<?= htmlspecialchars(json_encode($r)) ?>)" 
                                    class="btn btn-primary btn-sm">
                              <span>✏️</span> Editar
                            </button>
                            <button onclick="openFichaTecnicaModal(<?= (int)$r['id'] ?>)" 
                                    class="btn btn-info btn-sm">
                              <span>📋</span> Ficha Técnica
                            </button>
                            <button onclick="deleteReceita(<?= (int)$r['id'] ?>)" 
                                    class="btn btn-outline btn-sm">
                              <span>🗑️</span> Excluir
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Modal para Editar/Criar Receita -->
          <div id="receitaModal" class="modal-overlay" style="display: none;">
            <div class="modal">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title" id="receitaModalTitle">Nova Receita</h3>
                  <button onclick="closeReceitaModal()" class="btn btn-outline btn-sm">✕</button>
                </div>
                <div class="card-body">
                  <form id="receitaForm" method="post">
                    <input type="hidden" name="action" value="save_receita">
                    <input type="hidden" name="tab" value="receitas">
                    <input type="hidden" name="id" id="receitaId" value="">
                    
                    <div class="grid grid-cols-2 gap-4">
                      <div class="form-group">
                        <label class="form-label">Categoria</label>
                        <select name="categoria_id" id="receitaCategoriaId" class="form-select">
                          <option value="">Sem categoria</option>
                          <?php foreach ($cat as $c): ?>
                            <option value="<?=$c['id']?>"><?=h($c['nome'])?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Nome da Receita *</label>
                        <input type="text" name="nome" id="receitaNome" class="form-input" required>
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Rendimento (porções) *</label>
                        <input type="number" name="rendimento" id="receitaRendimento" 
                               class="form-input" min="1" value="1" required>
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Quantia por Pessoa *</label>
                        <input type="number" step="0.001" name="quantia_por_pessoa" id="receitaQuantiaPessoa" 
                               class="form-input" min="0.001" value="1.000" required>
                      </div>
                      
                      <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="ativo" id="receitaAtivo" class="form-select">
                          <option value="1">Ativa</option>
                          <option value="0">Inativa</option>
                        </select>
                      </div>
                    </div>
                    
                    <div class="form-group">
                      <label class="form-label">Descrição</label>
                      <textarea name="descricao" id="receitaDescricao" class="form-input" 
                                rows="3" placeholder="Descrição da receita (opcional)"></textarea>
                    </div>
                    
                    <div class="flex gap-2 justify-end mt-4">
                      <button type="button" onclick="closeReceitaModal()" class="btn btn-outline">
                        Cancelar
                      </button>
                      <button type="submit" class="btn btn-primary">
                        <span>💾</span> Salvar
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>

          <script>
          function openReceitaModal() {
            document.getElementById('receitaModalTitle').textContent = 'Nova Receita';
            document.getElementById('receitaForm').reset();
            document.getElementById('receitaId').value = '';
            document.getElementById('receitaModal').style.display = 'flex';
          }
          
          function editReceita(receita) {
            document.getElementById('receitaModalTitle').textContent = 'Editar Receita';
            document.getElementById('receitaId').value = receita.id;
            document.getElementById('receitaCategoriaId').value = receita.categoria_id || '';
            document.getElementById('receitaNome').value = receita.nome || '';
            document.getElementById('receitaRendimento').value = receita.rendimento || 1;
            document.getElementById('receitaQuantiaPessoa').value = receita.quantia_por_pessoa || 1;
            document.getElementById('receitaAtivo').value = receita.ativo || '1';
            document.getElementById('receitaDescricao').value = receita.descricao || '';
            document.getElementById('receitaModal').style.display = 'flex';
          }
          
          function closeReceitaModal() {
            document.getElementById('receitaModal').style.display = 'none';
          }
          
          function openFichaTecnicaModal(receitaId) {
            // Carregar conteúdo da ficha técnica via AJAX
            fetch('ficha_tecnica_ajax.php?id=' + receitaId)
              .then(response => response.text())
              .then(html => {
                document.getElementById('fichaTecnicaContent').innerHTML = html;
                document.getElementById('fichaTecnicaModal').style.display = 'flex';
              })
              .catch(error => {
                console.error('Erro ao carregar ficha técnica:', error);
                alert('Erro ao carregar ficha técnica');
              });
          }
          
          function closeFichaTecnicaModal() {
            document.getElementById('fichaTecnicaModal').style.display = 'none';
          }
          
          function deleteReceita(id) {
            if (confirm('Tem certeza que deseja excluir esta receita?')) {
              const form = document.createElement('form');
              form.method = 'post';
              form.innerHTML = `
                <input type="hidden" name="action" value="delete_receita">
                <input type="hidden" name="tab" value="receitas">
                <input type="hidden" name="id" value="${id}">
              `;
              document.body.appendChild(form);
              form.submit();
            }
          }
          </script>

          <!-- Modal da Ficha Técnica -->
          <div id="fichaTecnicaModal" class="modal-overlay" style="display: none;">
            <div class="modal" style="max-width: 1200px; width: 95%;">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title">📋 Ficha Técnica</h3>
                  <button onclick="closeFichaTecnicaModal()" class="btn btn-outline btn-sm">✕</button>
                </div>
                <div class="card-body" id="fichaTecnicaContent">
                  <div class="text-center py-8">
                    <span class="text-4xl">⏳</span>
                    <p class="mt-2">Carregando ficha técnica...</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($tab==='fixos'): ?>
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">📌 Itens Fixos</h2>
              <p class="text-sm text-gray-600">Itens que são incluídos automaticamente em cada evento (1× por evento)</p>
            </div>
            <div class="card-body">
              <form method="post" class="animate-fade-in">
                <input type="hidden" name="action" value="save_item_fixo">
                <input type="hidden" name="tab" value="fixos">
                
                <div class="table-container">
                  <table class="table">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Insumo</th>
                        <th>Quantidade</th>
                        <th>Unidade</th>
                        <th>Observações</th>
                        <th>Status</th>
                        <th class="text-center">Ação</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($fixos as $f): ?>
                        <tr class="animate-slide-in">
                          <td>
                            <span class="badge"><?= (int)$f['id'] ?></span>
                          </td>
                          <td>
                            <select name="insumo_id" required class="form-select" style="min-width: 200px;">
                              <?php foreach ($ins as $i2): ?>
                                <option value="<?= $i2['id'] ?>" <?= ($f['insumo_id']==$i2['id']?'selected':'') ?>>
                                  <?= h($i2['nome']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
                          <td>
                            <input type="number" step="0.000001" name="qtd" 
                                   value="<?= h($f['qtd']) ?>" 
                                   class="form-input" 
                                   style="width: 100px; text-align: center;"
                                   min="0.000001" max="999999.999999"
                                   placeholder="1.000">
                          </td>
                          <td>
                            <select name="unidade_id" required class="form-select" style="width: 120px;">
                              <?php foreach ($uni as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ($f['unidade_id']==$u['id']?'selected':'') ?>>
                                  <?= h($u['simbolo']) ?> (<?= h($u['nome']) ?>)
                                </option>
                  <?php endforeach; ?>
                </select>
              </td>
                          <td>
                            <input type="text" name="observacao" 
                                   value="<?= h($f['observacao']) ?>" 
                                   class="form-input" 
                                   placeholder="Observações opcionais"
                                   style="min-width: 150px;">
                          </td>
                          <td>
                            <select name="ativo" class="form-select" style="width: 100px;">
                              <option value="1" <?= $f['ativo']?'selected':'' ?>>Ativo</option>
                              <option value="0" <?= !$f['ativo']?'selected':'' ?>>Inativo</option>
                </select>
              </td>
                          <td class="text-center">
                            <div class="flex gap-2 justify-center">
                              <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                              <button type="submit" class="btn btn-primary btn-sm">
                                <span>💾</span> Salvar
                              </button>
                              <button type="submit" class="btn btn-outline btn-sm" 
                                      onclick="return confirm('Tem certeza que deseja excluir este item fixo?')"
                                      formaction="?action=delete_item_fixo&tab=fixos&id=<?= (int)$f['id'] ?>">
                                <span>🗑️</span> Excluir
                              </button>
                            </div>
              </td>
            </tr>
          <?php endforeach; ?>

                      <tr class="row-new animate-fade-in">
                        <td>
                          <span class="badge badge-success">NOVO</span>
                        </td>
                        <td>
                          <select name="insumo_id" required class="form-select" style="min-width: 200px;">
                            <option value="">— Selecione um insumo —</option>
                            <?php foreach ($ins as $i2): ?>
                              <option value="<?= $i2['id'] ?>"><?= h($i2['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
                        <td>
                          <input type="number" step="0.000001" name="qtd" 
                                 placeholder="1.000" 
                                 class="form-input" 
                                 style="width: 100px; text-align: center;"
                                 min="0.000001" max="999999.999999">
                        </td>
                        <td>
                          <select name="unidade_id" required class="form-select" style="width: 120px;">
                            <?php foreach ($uni as $u): ?>
                              <option value="<?= $u['id'] ?>"><?= h($u['simbolo']) ?> (<?= h($u['nome']) ?>)</option>
                            <?php endforeach; ?>
              </select>
            </td>
                        <td>
                          <input type="text" name="observacao" 
                                 placeholder="Observações opcionais" 
                                 class="form-input" 
                                 style="min-width: 150px;">
                        </td>
                        <td>
                          <select name="ativo" class="form-select" style="width: 100px;">
                            <option value="1" selected>Ativo</option>
                            <option value="0">Inativo</option>
              </select>
            </td>
                        <td class="text-center">
              <input type="hidden" name="id" value="">
                          <button type="submit" class="btn btn-success btn-sm">
                            <span>➕</span> Adicionar
                          </button>
            </td>
          </tr>
        </tbody>
      </table>
                </div>
    </form>
            </div>
          </div>
  <?php endif; ?>
      </div>
    </div>
  </main>
</body>
</html>
