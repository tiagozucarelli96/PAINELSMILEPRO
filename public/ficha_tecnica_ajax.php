<?php
// ficha_tecnica_ajax.php - Carregar ficha técnica via AJAX para modal

declare(strict_types=1);

// ========= Sessão / Auth =========
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}

$receita_id = (int)($_GET['id'] ?? 0);
if (!$receita_id) {
    echo '<div class="alert alert-error">ID da receita não fornecido.</div>';
    exit;
}

// ========= Conexão =========
require_once 'conexao.php';

$msg = '';
$err = '';

try {
    // Carregar receita
    $receita = $pdo->prepare("
        SELECT r.*, c.nome AS categoria_nome
        FROM lc_receitas r
        LEFT JOIN lc_categorias c ON c.id = r.categoria_id
        WHERE r.id = ?
    ");
    $receita->execute([$receita_id]);
    $receita = $receita->fetch(PDO::FETCH_ASSOC);
    
    if (!$receita) {
        throw new Exception('Receita não encontrada.');
    }
    
    // Carregar componentes da receita
    $componentes = $pdo->prepare("
        SELECT rc.*, i.nome AS insumo_nome, i.custo_unit, u.simbolo AS unidade_simbolo
        FROM lc_receita_componentes rc
        LEFT JOIN lc_insumos i ON i.id = rc.insumo_id
        LEFT JOIN lc_unidades u ON u.id = rc.unidade_id
        WHERE rc.receita_id = ?
        ORDER BY rc.ordem, i.nome
    ");
    $componentes->execute([$receita_id]);
    $componentes = $componentes->fetchAll(PDO::FETCH_ASSOC);
    
    // Carregar insumos para adicionar (sem verificar coluna ativo)
    $insumos = $pdo->query("
        SELECT i.*, u.simbolo, c.nome AS categoria_nome
        FROM lc_insumos i
        LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
        LEFT JOIN lc_categorias c ON c.id = i.categoria_id
        ORDER BY c.nome, i.nome
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Carregar unidades
    $unidades = $pdo->query("
        SELECT * FROM lc_unidades WHERE ativo = true ORDER BY nome
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Ações via POST
    if ($_POST) {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_componente') {
            $insumo_id = (int)($_POST['insumo_id'] ?? 0);
            $quantidade = (float)str_replace(',', '.', $_POST['quantidade'] ?? '0');
            $unidade_id = (int)($_POST['unidade_id'] ?? 0);
            $observacoes = $_POST['observacoes'] ?? '';
            
            if (!$insumo_id) throw new Exception('Insumo é obrigatório.');
            if ($quantidade <= 0) throw new Exception('Quantidade deve ser maior que zero.');
            
            // Buscar custo unitário do insumo
            $insumo = $pdo->prepare("SELECT custo_unit FROM lc_insumos WHERE id = ?");
            $insumo->execute([$insumo_id]);
            $custo_unitario = $insumo->fetchColumn() ?: 0;
            
            $custo_total = $quantidade * $custo_unitario;
            
            $stmt = $pdo->prepare("
                INSERT INTO lc_receita_componentes 
                (receita_id, insumo_id, quantidade, unidade_id, custo_unitario, custo_total, observacoes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$receita_id, $insumo_id, $quantidade, $unidade_id, $custo_unitario, $custo_total, $observacoes]);
            
            $msg = 'Componente adicionado à receita.';
            // Recarregar componentes
            $componentes = $pdo->prepare("
                SELECT rc.*, i.nome AS insumo_nome, i.custo_unit, u.simbolo AS unidade_simbolo
                FROM lc_receita_componentes rc
                LEFT JOIN lc_insumos i ON i.id = rc.insumo_id
                LEFT JOIN lc_unidades u ON u.id = rc.unidade_id
                WHERE rc.receita_id = ?
                ORDER BY rc.ordem, i.nome
            ");
            $componentes->execute([$receita_id]);
            $componentes = $componentes->fetchAll(PDO::FETCH_ASSOC);
        }
        
        if ($action === 'update_componente') {
            $componente_id = (int)($_POST['componente_id'] ?? 0);
            $quantidade = (float)str_replace(',', '.', $_POST['quantidade'] ?? '0');
            $observacoes = $_POST['observacoes'] ?? '';
            
            if (!$componente_id) throw new Exception('ID do componente é obrigatório.');
            if ($quantidade <= 0) throw new Exception('Quantidade deve ser maior que zero.');
            
            // Buscar custo unitário
            $comp = $pdo->prepare("SELECT custo_unitario FROM lc_receita_componentes WHERE id = ?");
            $comp->execute([$componente_id]);
            $custo_unitario = $comp->fetchColumn() ?: 0;
            
            $custo_total = $quantidade * $custo_unitario;
            
            $stmt = $pdo->prepare("
                UPDATE lc_receita_componentes 
                SET quantidade = ?, custo_total = ?, observacoes = ?
                WHERE id = ?
            ");
            $stmt->execute([$quantidade, $custo_total, $observacoes, $componente_id]);
            
            $msg = 'Componente atualizado.';
            // Recarregar componentes
            $componentes = $pdo->prepare("
                SELECT rc.*, i.nome AS insumo_nome, i.custo_unit, u.simbolo AS unidade_simbolo
                FROM lc_receita_componentes rc
                LEFT JOIN lc_insumos i ON i.id = rc.insumo_id
                LEFT JOIN lc_unidades u ON u.id = rc.unidade_id
                WHERE rc.receita_id = ?
                ORDER BY rc.ordem, i.nome
            ");
            $componentes->execute([$receita_id]);
            $componentes = $componentes->fetchAll(PDO::FETCH_ASSOC);
        }
        
        if ($action === 'delete_componente') {
            $componente_id = (int)($_POST['componente_id'] ?? 0);
            
            if (!$componente_id) throw new Exception('ID do componente é obrigatório.');
            
            $stmt = $pdo->prepare("DELETE FROM lc_receita_componentes WHERE id = ?");
            $stmt->execute([$componente_id]);
            
            $msg = 'Componente removido da receita.';
            // Recarregar componentes
            $componentes = $pdo->prepare("
                SELECT rc.*, i.nome AS insumo_nome, i.custo_unit, u.simbolo AS unidade_simbolo
                FROM lc_receita_componentes rc
                LEFT JOIN lc_insumos i ON i.id = rc.insumo_id
                LEFT JOIN lc_unidades u ON u.id = rc.unidade_id
                WHERE rc.receita_id = ?
                ORDER BY rc.ordem, i.nome
            ");
            $componentes->execute([$receita_id]);
            $componentes = $componentes->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
} catch (Exception $e) {
    $err = $e->getMessage();
}


?>

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

<!-- Informações da Receita -->
<div class="card mb-6">
  <div class="card-header">
    <h2 class="card-title">📋 Informações da Receita</h2>
  </div>
  <div class="card-body">
    <div class="grid grid-cols-4 gap-4">
      <div>
        <label class="form-label">Nome</label>
        <div class="text-lg font-semibold"><?=h($receita['nome'])?></div>
      </div>
      <div>
        <label class="form-label">Categoria</label>
        <div class="badge badge-info"><?=h($receita['categoria_nome'] ?? 'Sem categoria')?></div>
      </div>
      <div>
        <label class="form-label">Rendimento</label>
        <div class="text-lg"><?=(int)$receita['rendimento']?> porções</div>
      </div>
      <div>
        <label class="form-label">Custo Total</label>
        <div class="text-lg font-bold text-green-600">
          R$ <?=number_format((float)$receita['custo_total'], 4, ',', '.')?>
        </div>
      </div>
    </div>
    <?php if ($receita['descricao']): ?>
      <div class="mt-4">
        <label class="form-label">Descrição</label>
        <div class="text-gray-700"><?=h($receita['descricao'])?></div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Adicionar Componente -->
<div class="card mb-6">
  <div class="card-header">
    <h2 class="card-title">➕ Adicionar Componente</h2>
  </div>
  <div class="card-body">
    <form method="post" class="grid grid-cols-4 gap-4" onsubmit="addComponente(event)">
      <input type="hidden" name="action" value="add_componente">
      
      <div class="form-group">
        <label class="form-label">Insumo *</label>
        <select name="insumo_id" class="form-select" required>
          <option value="">Selecione um insumo...</option>
          <?php foreach ($insumos as $i): ?>
            <option value="<?=$i['id']?>">
              <?=h($i['categoria_nome'] ?? 'Sem categoria')?> - <?=h($i['nome'])?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="form-group">
        <label class="form-label">Quantidade *</label>
        <input type="number" step="0.0001" name="quantidade" class="form-input" required>
      </div>
      
      <div class="form-group">
        <label class="form-label">Unidade</label>
        <select name="unidade_id" class="form-select">
          <option value="">Usar padrão do insumo</option>
          <?php foreach ($unidades as $u): ?>
            <option value="<?=$u['id']?>"><?=h($u['simbolo'])?> (<?=h($u['nome'])?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="form-group">
        <label class="form-label">Observações</label>
        <input type="text" name="observacoes" class="form-input" placeholder="Ex: cortar em cubos">
      </div>
      
      <div class="col-span-4">
        <button type="submit" class="btn btn-primary">
          <span>➕</span> Adicionar Componente
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Lista de Componentes -->
<div class="card">
  <div class="card-header">
    <h2 class="card-title">🧾 Componentes da Receita</h2>
  </div>
  <div class="card-body">
    <?php if (empty($componentes)): ?>
      <div class="text-center text-gray-500 py-8">
        <span class="text-4xl">📝</span>
        <p class="mt-2">Nenhum componente adicionado ainda.</p>
        <p class="text-sm">Use o formulário acima para adicionar insumos à receita.</p>
      </div>
    <?php else: ?>
      <div class="table-container">
        <table class="table">
          <thead>
            <tr>
              <th>Insumo</th>
              <th>Quantidade</th>
              <th>Unidade</th>
              <th>Custo Unit.</th>
              <th>Custo Total</th>
              <th>Observações</th>
              <th class="text-center">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($componentes as $c): ?>
              <tr>
                <td>
                  <div class="font-semibold"><?=h($c['insumo_nome'])?></div>
                </td>
                <td class="text-right">
                  <span class="badge"><?=number_format($c['quantidade'], 4, ',', '.')?></span>
                </td>
                <td><?=h($c['unidade_simbolo'] ?? '—')?></td>
                <td class="text-right">
                  R$ <?=number_format($c['custo_unitario'], 4, ',', '.')?>
                </td>
                <td class="text-right">
                  <span class="badge badge-success">
                    R$ <?=number_format($c['custo_total'], 4, ',', '.')?>
                  </span>
                </td>
                <td><?=h($c['observacoes'] ?? '—')?></td>
                <td class="text-center">
                  <div class="flex gap-2 justify-center">
                    <button onclick="editComponente(<?=htmlspecialchars(json_encode($c))?>)" 
                            class="btn btn-primary btn-sm">
                      <span>✏️</span> Editar
                    </button>
                    <button onclick="deleteComponente(<?=(int)$c['id']?>)" 
                            class="btn btn-outline btn-sm">
                      <span>🗑️</span> Remover
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr class="font-bold bg-gray-50">
              <td colspan="4" class="text-right">TOTAL DA RECEITA:</td>
              <td class="text-right">
                <span class="badge badge-success text-lg">
                  R$ <?=number_format((float)$receita['custo_total'], 4, ',', '.')?>
                </span>
              </td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal para Editar Componente -->
<div id="componenteModal" class="modal-overlay" style="display: none;">
  <div class="modal">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Editar Componente</h3>
        <button onclick="closeComponenteModal()" class="btn btn-outline btn-sm">✕</button>
      </div>
      <div class="card-body">
        <form id="componenteForm" method="post" onsubmit="updateComponente(event)">
          <input type="hidden" name="action" value="update_componente">
          <input type="hidden" name="componente_id" id="componenteId" value="">
          
          <div class="form-group">
            <label class="form-label">Quantidade *</label>
            <input type="number" step="0.0001" name="quantidade" id="componenteQuantidade" 
                   class="form-input" required>
          </div>
          
          <div class="form-group">
            <label class="form-label">Observações</label>
            <input type="text" name="observacoes" id="componenteObservacoes" 
                   class="form-input" placeholder="Ex: cortar em cubos">
          </div>
          
          <div class="flex gap-2 justify-end mt-4">
            <button type="button" onclick="closeComponenteModal()" class="btn btn-outline">
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
function addComponente(event) {
  event.preventDefault();
  const form = event.target;
  const formData = new FormData(form);
  
  fetch('ficha_tecnica_ajax.php?id=<?=$receita_id?>', {
    method: 'POST',
    body: formData
  })
  .then(response => response.text())
  .then(html => {
    // Atualizar todo o conteúdo da ficha técnica
    document.getElementById('fichaTecnicaContent').innerHTML = html;
  })
  .catch(error => {
    console.error('Erro:', error);
    alert('Erro ao adicionar componente: ' + error.message);
  });
}

function updateComponente(event) {
  event.preventDefault();
  const form = event.target;
  const formData = new FormData(form);
  
  fetch('ficha_tecnica_ajax.php?id=<?=$receita_id?>', {
    method: 'POST',
    body: formData
  })
  .then(response => response.text())
  .then(html => {
    // Atualizar todo o conteúdo da ficha técnica
    document.getElementById('fichaTecnicaContent').innerHTML = html;
    closeComponenteModal();
  })
  .catch(error => {
    console.error('Erro:', error);
    alert('Erro ao atualizar componente: ' + error.message);
  });
}

function editComponente(componente) {
  document.getElementById('componenteId').value = componente.id;
  document.getElementById('componenteQuantidade').value = componente.quantidade;
  document.getElementById('componenteObservacoes').value = componente.observacoes || '';
  document.getElementById('componenteModal').style.display = 'flex';
}

function closeComponenteModal() {
  document.getElementById('componenteModal').style.display = 'none';
}

function deleteComponente(id) {
  if (confirm('Tem certeza que deseja remover este componente da receita?')) {
    const formData = new FormData();
    formData.append('action', 'delete_componente');
    formData.append('componente_id', id);
    
    fetch('ficha_tecnica_ajax.php?id=<?=$receita_id?>', {
      method: 'POST',
      body: formData
    })
    .then(response => response.text())
    .then(html => {
      // Atualizar todo o conteúdo da ficha técnica
      document.getElementById('fichaTecnicaContent').innerHTML = html;
    })
    .catch(error => {
      console.error('Erro:', error);
      alert('Erro ao remover componente: ' + error.message);
    });
  }
}
</script>
