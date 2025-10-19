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
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <img src="logo.png" alt="Painel Smile PRO" class="sidebar-logo">
    </div>
    <nav class="sidebar-nav">
      <a href="dashboard.php" class="nav-item">
        <span>📊</span> Dashboard
      </a>
      <a href="configuracoes.php" class="nav-item active">
        <span>⚙️</span> Configurações
      </a>
      <a href="lista_compras.php" class="nav-item">
        <span>📝</span> Lista de Compras
      </a>
      <a href="lc_index.php" class="nav-item">
        <span>📋</span> Histórico
      </a>
      <a href="usuarios.php" class="nav-item">
        <span>👥</span> Usuários
      </a>
      <a href="logout.php" class="nav-item">
        <span>🚪</span> Sair
      </a>
    </nav>
  </aside>

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
                                'massa' => '⚖️ Massa',
                                'volume' => '📦 Volume', 
                                'unidade' => '🔢 Unidade',
                                'embalagem' => '📦 Embalagem',
                                'outro' => '❓ Outro'
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
                            <option value="massa">⚖️ Massa</option>
                            <option value="volume">📦 Volume</option>
                            <option value="unidade">🔢 Unidade</option>
                            <option value="embalagem">📦 Embalagem</option>
                            <option value="outro">❓ Outro</option>
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
              <h2 class="card-title">🥘 Insumos</h2>
              <p class="text-sm text-gray-600">Gerencie ingredientes, preços e informações de aquisição</p>
            </div>
            <div class="card-body">
              <form method="post" class="animate-fade-in">
                <input type="hidden" name="action" value="save_insumo">
                <input type="hidden" name="tab" value="insumos">
                
                <div class="table-container">
                  <table class="table">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Categoria</th>
                        <th>Nome do Insumo</th>
                        <th>Unidade</th>
                        <th>Custo Unit.</th>
                        <th>FC</th>
                        <th>Embalagem</th>
                        <th>Custo Final</th>
                        <th>Aquisição</th>
                        <th>Fornecedor</th>
                        <th>Observações</th>
                        <th>Status</th>
                        <th class="text-center">Ação</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($ins as $i): ?>
                        <tr class="animate-slide-in">
                          <td>
                            <span class="badge"><?= (int)$i['id'] ?></span>
                          </td>
                          <td>
                            <select name="categoria_id" class="form-select" style="width: 120px;">
                              <option value="">Sem categoria</option>
                              <?php foreach ($cat as $c): ?>
                                <option value="<?=$c['id']?>" <?= ($i['categoria_id'] ?? '')==$c['id']?'selected':''?>>
                                  <?=h($c['nome'])?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td>
                            <input type="text" name="nome" value="<?=h($i['nome'])?>" 
                                   class="form-input" required 
                                   placeholder="Ex: Peito de frango"
                                   style="min-width: 150px;">
                          </td>
                          <td>
                            <select name="unidade_id" required class="form-select" style="width: 120px;">
                              <?php foreach ($uni as $u): ?>
                                <option value="<?=$u['id']?>" <?= ($i['unidade_padrao']==$u['simbolo']?'selected':'')?>>
                                  <?=h($u['simbolo'])?> (<?=h($u['nome'])?>)
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td>
                            <input type="number" step="0.0001" name="preco" 
                                   value="<?=h($i['custo_unit'] ?? '')?>" 
                                   class="form-input" 
                                   style="width: 100px; text-align: center;"
                                   placeholder="0.00">
                          </td>
                          <td>
                            <input type="number" step="0.000001" name="fator_correcao" 
                                   value="1.000000" 
                                   class="form-input" 
                                   style="width: 100px; text-align: center;"
                                   disabled 
                                   title="FC não disponível nesta estrutura">
                          </td>
                          <td>
                            <input type="number" step="0.000001" name="embalagem_multiplo" 
                                   value="<?= h($i['embalagem_multiplo'] ?? '') ?>" 
                                   class="form-input" 
                                   style="width: 100px; text-align: center;"
                                   placeholder="ex: 50">
                          </td>
                          <td>
                            <span class="badge badge-info">
                              <?= number_format($i['custo_corrigido'] ?? 0, 4, ',', '.') . ' / ' . h($i['simbolo'] ?? '') ?>
                            </span>
                          </td>
                          <td>
                            <select name="tipo_padrao" class="form-select" style="width: 100px;">
                              <?php 
                              $tipos = [
                                'mercado' => '🛒 Mercado',
                                'preparo' => '👨‍🍳 Preparo', 
                                'fixo' => '📌 Fixo'
                              ];
                              foreach ($tipos as $t => $label): ?>
                                <option value="<?=$t?>" <?= ($i['aquisicao'] ?? '')===$t?'selected':''?>><?=$label?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td>
                            <input type="text" name="fornecedor_id" 
                                   value="<?=h($i['fornecedor_id'] ?? '')?>" 
                                   class="form-input" 
                                   placeholder="ID Fornecedor"
                                   style="width: 100px; text-align: center;">
                          </td>
                          <td>
                            <input type="text" name="observacao" 
                                   value="<?=h($i['observacoes'] ?? '')?>" 
                                   class="form-input" 
                                   placeholder="Observações"
                                   style="min-width: 120px;">
                          </td>
                          <td>
                            <select name="ativo" class="form-select" style="width: 80px;">
                              <option value="1" <?= ($i['ativo'] ?? 1) ? 'selected' : '' ?>>Ativo</option>
                              <option value="0" <?= !($i['ativo'] ?? 1) ? 'selected' : '' ?>>Inativo</option>
                            </select>
                          </td>
                          <td class="text-center">
                            <div class="flex gap-2 justify-center">
                              <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                              <button type="submit" class="btn btn-primary btn-sm">
                                <span>💾</span> Salvar
                              </button>
                              <button type="submit" class="btn btn-outline btn-sm" 
                                      onclick="return confirm('Tem certeza que deseja excluir este insumo?')"
                                      formaction="?action=delete_insumo&tab=insumos&id=<?= (int)$i['id'] ?>">
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
                          <select name="categoria_id" class="form-select" style="width: 120px;">
                            <option value="">Sem categoria</option>
                            <?php foreach ($cat as $c): ?>
                              <option value="<?=$c['id']?>"><?=h($c['nome'])?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <input type="text" name="nome" placeholder="Ex: Peito de frango" 
                                 class="form-input" required 
                                 style="min-width: 150px;">
                        </td>
                        <td>
                          <select name="unidade_id" required class="form-select" style="width: 120px;">
                            <option value="">Selecione...</option>
                            <?php foreach ($uni as $u): ?>
                              <option value="<?=$u['id']?>"><?=h($u['simbolo'])?> (<?=h($u['nome'])?>)</option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <input type="number" step="0.0001" name="preco" 
                                 placeholder="10.90" 
                                 class="form-input" 
                                 style="width: 100px; text-align: center;">
                        </td>
                        <td>
                          <input type="number" step="0.000001" name="fator_correcao" 
                                 value="1.000000" 
                                 class="form-input" 
                                 style="width: 100px; text-align: center;"
                                 disabled 
                                 title="FC não disponível nesta estrutura">
                        </td>
                        <td>
                          <input type="number" step="0.000001" name="embalagem_multiplo" 
                                 placeholder="ex: 50" 
                                 class="form-input" 
                                 style="width: 100px; text-align: center;">
                        </td>
                        <td>
                          <span class="badge">—</span>
                        </td>
                        <td>
                          <select name="tipo_padrao" class="form-select" style="width: 100px;">
                            <option value="mercado" selected>🛒 Mercado</option>
                            <option value="preparo">👨‍🍳 Preparo</option>
                            <option value="fixo">📌 Fixo</option>
                          </select>
                        </td>
                        <td>
                          <input type="text" name="fornecedor_id" 
                                 placeholder="ID Fornecedor" 
                                 class="form-input" 
                                 style="width: 100px; text-align: center;">
                        </td>
                        <td>
                          <input type="text" name="observacao" 
                                 placeholder="Ex: marca preferida" 
                                 class="form-input" 
                                 style="min-width: 120px;">
                        </td>
                        <td>
                          <select name="ativo" class="form-select" style="width: 80px;">
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
