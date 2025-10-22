<?php
declare(strict_types=1);
// public/index.php — Painel do módulo Lista de Compras (PostgreSQL/Railway) — HISTÓRICO

// ========= Sessão / Auth =========
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); }

// Para testes: permitir acesso se não estiver logado (temporário)
if (!$uid || !is_numeric((string)$uid) || !$estaLogado) {
    // Definir usuário padrão para testes
    $uid = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['logado'] = true;
}

// ========= Conexão =========
$db_error = '';
$pdo = null;
try {
    // Usa DATABASE_URL do Railway, se existir; senão, PGHOST/PGUSER/etc.
    if (!empty($_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL'))) {
        $url = getenv('DATABASE_URL') ?: $_ENV['DATABASE_URL'];
        // formatos comuns: postgres://user:pass@host:port/dbname
        $parts = parse_url($url);
        $dbhost = $parts['host'] ?? 'localhost';
        $dbport = $parts['port'] ?? 5432;
        $dbuser = $parts['user'] ?? '';
        $dbpass = $parts['pass'] ?? '';
        $dbname = ltrim($parts['path'] ?? '', '/');
        $dsn = "pgsql:host={$dbhost};port={$dbport};dbname={$dbname};";
        $pdo = new PDO($dsn, $dbuser, $dbpass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $dbhost = getenv('PGHOST') ?: ($_ENV['PGHOST'] ?? 'localhost');
        $dbport = (int)(getenv('PGPORT') ?: ($_ENV['PGPORT'] ?? 5432));
        $dbuser = getenv('PGUSER') ?: ($_ENV['PGUSER'] ?? '');
        $dbpass = getenv('PGPASSWORD') ?: ($_ENV['PGPASSWORD'] ?? '');
        $dbname = getenv('PGDATABASE') ?: ($_ENV['PGDATABASE'] ?? '');
        $dsn = "pgsql:host={$dbhost};port={$dbport};dbname={$dbname};";
        $pdo = new PDO($dsn, $dbuser, $dbpass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
} catch (Throwable $e) {
    $db_error = 'Falha ao conectar ao banco: ' . $e->getMessage();
}

// ========= Paginação / filtros =========
function intParam(string $key, int $default = 1): int {
    $v = isset($_GET[$key]) ? (int)$_GET[$key] : $default;
    return $v > 0 ? $v : $default;
}
$pp = 10; // itens por página
$p1 = intParam('p1', 1); // página compras
$p2 = intParam('p2', 1); // página encomendas
$off1 = ($p1 - 1) * $pp;
$off2 = ($p2 - 1) * $pp;

// ========= Helpers =========
function fmtDataPt(?string $ts): string {
    if (!$ts) return '-';
    try {
        $d = new DateTime($ts);
        return $d->format('d/m/Y H:i');
    } catch (Throwable) { return $ts; }
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ========= Consultas =========
$compras = $encomendas = [];
$total1 = $total2 = 0;
$erroQuery = '';

if ($pdo) {
    try {
        // Tabela esperada: lc_listas (id, tipo, eventos_resumo, espaco_consolidado, criado_por, criado_por_nome, data_gerada)
        // tipo: lc_tipo_lista => 'compras' | 'encomendas'
        $sqlBase = "
        SELECT l.id,
               l.grupo_id,
               l.tipo,
               COALESCE(l.espaco_consolidado, 'Múltiplos') AS espaco_resumo,
               COALESCE(l.eventos_resumo, '')              AS resumo_eventos,
               l.data_gerada,
               COALESCE(l.criado_por_nome, '—')            AS criado_por
        FROM smilee12_painel_smile.lc_listas l
        WHERE l.tipo = :tipo
        ORDER BY l.data_gerada DESC, l.grupo_id DESC
        LIMIT :limit OFFSET :offset
    ";
        $stmt1 = $pdo->prepare($sqlBase);
        $stmt1->bindValue(':tipo', 'compras', PDO::PARAM_STR);
        $stmt1->bindValue(':limit', $pp, PDO::PARAM_INT);
        $stmt1->bindValue(':offset', $off1, PDO::PARAM_INT);
        $stmt1->execute();
        $compras = $stmt1->fetchAll();

        $stmt2 = $pdo->prepare($sqlBase);
        $stmt2->bindValue(':tipo', 'encomendas', PDO::PARAM_STR);
        $stmt2->bindValue(':limit', $pp, PDO::PARAM_INT);
        $stmt2->bindValue(':offset', $off2, PDO::PARAM_INT);
        $stmt2->execute();
        $encomendas = $stmt2->fetchAll();

        // Totais p/ paginação
        $sqlCount = "SELECT COUNT(*)::int FROM smilee12_painel_smile.lc_listas WHERE tipo = :tipo";
        $c1 = $pdo->prepare($sqlCount);
        $c1->bindValue(':tipo', 'compras', PDO::PARAM_STR);
        $c1->execute();
        $total1 = (int)$c1->fetchColumn();

        $c2 = $pdo->prepare($sqlCount);
        $c2->bindValue(':tipo', 'encomendas', PDO::PARAM_STR);
        $c2->execute();
        $total2 = (int)$c2->fetchColumn();
    } catch (Throwable $e) {
        $erroQuery = 'Erro ao carregar histórico: ' . $e->getMessage();
    }
}

$pages1 = (int)ceil(($total1 ?: 0) / $pp);
$pages2 = (int)ceil(($total2 ?: 0) / $pp);

// ========= Permissões basicas =========
$isAdmin = !empty($_SESSION['perm_usuarios']) || !empty($_SESSION['perm_admin']) || !empty($_SESSION['is_admin']);

// Paginação
$per = 10;
$pgC = max(1, (int)($_GET['pgC'] ?? 1)); // compras
$pgE = max(1, (int)($_GET['pgE'] ?? 1)); // encomendas
$offC = ($pgC-1)*$per;
$offE = ($pgE-1)*$per;

// Histórico — COMPRAS (verificar se tabela existe primeiro)
$totalC = 0;
$rowsC = [];

try {
  // Verificar se a tabela existe
  $tableExists = $pdo->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'lc_compras_consolidadas')")->fetchColumn();
  
  if ($tableExists) {
    $sqlCountC = "
      SELECT COUNT(*) 
      FROM smilee12_painel_smile.lc_listas l 
      WHERE EXISTS (SELECT 1 FROM smilee12_painel_smile.lc_compras_consolidadas c WHERE c.lista_id = l.id)
    ";
    $totalC = (int)$pdo->query($sqlCountC)->fetchColumn();

    $sqlCompras = "
      SELECT l.id, l.data_gerada AS criado_em, l.espaco_consolidado AS espaco_resumo, 
             l.eventos_resumo AS resumo_eventos, l.criado_por, l.criado_por_nome
      FROM smilee12_painel_smile.lc_listas l
      WHERE EXISTS (SELECT 1 FROM smilee12_painel_smile.lc_compras_consolidadas c WHERE c.lista_id = l.id)
      ORDER BY l.data_gerada DESC, l.id DESC
      LIMIT :per OFFSET :off
    ";
    $stC = $pdo->prepare($sqlCompras);
    $stC->bindValue(':per', $per, PDO::PARAM_INT);
    $stC->bindValue(':off', $offC, PDO::PARAM_INT);
    $stC->execute();
    $rowsC = $stC->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Exception $e) {
  // Se houver erro, usar apenas lc_listas com filtro por tipo_lista
  $sqlCountC = "SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas l WHERE l.tipo_lista = 'compras'";
  $totalC = (int)$pdo->query($sqlCountC)->fetchColumn();

    $sqlCompras = "
      SELECT l.id, l.data_gerada AS criado_em, l.espaco_consolidado AS espaco_resumo, 
             l.eventos_resumo AS resumo_eventos, l.criado_por, l.criado_por_nome
      FROM smilee12_painel_smile.lc_listas l
      WHERE l.tipo_lista = 'compras'
      ORDER BY l.data_gerada DESC, l.id DESC
      LIMIT :per OFFSET :off
    ";
    $stC = $pdo->prepare($sqlCompras);
    $stC->bindValue(':per', $per, PDO::PARAM_INT);
    $stC->bindValue(':off', $offC, PDO::PARAM_INT);
    $stC->execute();
    $rowsC = $stC->fetchAll(PDO::FETCH_ASSOC);
}

// Histórico — ENCOMENDAS (verificar se tabela existe primeiro)
$totalE = 0;
$rowsE = [];

try {
  // Verificar se a tabela existe
  $tableExists = $pdo->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'lc_encomendas_itens')")->fetchColumn();
  
  if ($tableExists) {
    $sqlCountE = "
      SELECT COUNT(*) 
      FROM smilee12_painel_smile.lc_listas l 
      WHERE EXISTS (SELECT 1 FROM smilee12_painel_smile.lc_encomendas_itens e WHERE e.lista_id = l.id)
    ";
    $totalE = (int)$pdo->query($sqlCountE)->fetchColumn();

    $sqlEncom = "
      SELECT l.id, l.data_gerada AS criado_em, l.espaco_consolidado AS espaco_resumo, 
             l.eventos_resumo AS resumo_eventos, l.criado_por, l.criado_por_nome
      FROM smilee12_painel_smile.lc_listas l
      WHERE EXISTS (SELECT 1 FROM smilee12_painel_smile.lc_encomendas_itens e WHERE e.lista_id = l.id)
      ORDER BY l.data_gerada DESC, l.id DESC
      LIMIT :per OFFSET :off
    ";
    $stE = $pdo->prepare($sqlEncom);
    $stE->bindValue(':per', $per, PDO::PARAM_INT);
    $stE->bindValue(':off', $offE, PDO::PARAM_INT);
    $stE->execute();
    $rowsE = $stE->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Exception $e) {
  // Se houver erro, usar apenas lc_listas com filtro por tipo_lista
  $sqlCountE = "SELECT COUNT(*) FROM smilee12_painel_smile.lc_listas l WHERE l.tipo_lista = 'encomendas'";
  $totalE = (int)$pdo->query($sqlCountE)->fetchColumn();

    $sqlEncom = "
      SELECT l.id, l.data_gerada AS criado_em, l.espaco_consolidado AS espaco_resumo, 
             l.eventos_resumo AS resumo_eventos, l.criado_por, l.criado_por_nome
      FROM smilee12_painel_smile.lc_listas l
      WHERE l.tipo_lista = 'encomendas'
      ORDER BY l.data_gerada DESC, l.id DESC
      LIMIT :per OFFSET :off
    ";
    $stE = $pdo->prepare($sqlEncom);
    $stE->bindValue(':per', $per, PDO::PARAM_INT);
    $stE->bindValue(':off', $offE, PDO::PARAM_INT);
    $stE->execute();
    $rowsE = $stE->fetchAll(PDO::FETCH_ASSOC);
}

// Helpers
function dt($s){ return $s ? date('d/m/Y H:i', strtotime($s)) : ''; }

// ========= UI =========
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Compras — Histórico</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
  .card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin: 20px 0;
    padding: 20px;
  }
  
  .table-wrap {
    overflow-x: auto;
  }
  
  .badge {
    background: #e3f2fd;
    color: #1976d2;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
  }
  
  .btn-small {
    display: inline-block;
    padding: 4px 8px;
    margin: 2px;
    background: #f5f5f5;
    color: #333;
    text-decoration: none;
    border-radius: 4px;
    font-size: 12px;
    border: 1px solid #ddd;
  }
  
  .btn-small:hover {
    background: #e0e0e0;
  }
  
  .btn-small.danger {
    background: #ffebee;
    color: #c62828;
    border-color: #ffcdd2;
  }
  
  .btn-small.danger:hover {
    background: #ffcdd2;
  }
  
  .actions {
    white-space: nowrap;
  }
  
  .text-center {
    text-align: center;
  }
  
  .alert {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 12px;
    margin: 10px 0;
  }
  
  .alert a {
    color: #007bff;
    text-decoration: none;
  }
  
  .alert a:hover {
    text-decoration: underline;
  }
</style>
</head>
<body class="panel has-sidebar">
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
  <div class="container">

    <!-- Mensagens de Sucesso/Erro -->
    <?php if (isset($_GET['sucesso'])): ?>
      <div class="alert alert-success" style="background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 12px; border-radius: 4px; margin: 10px 0;">
        <span>✅</span> <?= htmlspecialchars($_GET['sucesso']) ?>
      </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['erro'])): ?>
      <div class="alert alert-error" style="background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 12px; border-radius: 4px; margin: 10px 0;">
        <span>❌</span> <?= htmlspecialchars($_GET['erro']) ?>
      </div>
    <?php endif; ?>

    <!-- INÍCIO CONTEÚDO -->
    <div class="topbar">
      <div class="grow">
        <h1 style="margin:0;font-size:22px;">Gestão de Compras</h1>
        <div class="meta">Gerar listas de compras, controlar estoque e consultar histórico de movimentações.</div>
      </div>
      <div class="top-buttons">
        <a class="btn btn-primary btn-lg" href="lista_compras.php">
          <span>📝</span> Gerar Lista de Compras
        </a>
        <a class="btn btn-outline btn-lg" href="estoque_contagens.php">
          <span>📊</span> Controle de Estoque
        </a>
        <a class="btn btn-outline btn-lg" href="estoque_kardex.php">
          <span>📒</span> Kardex
        </a>
        <a class="btn btn-outline btn-lg" href="historico.php">
          <span>📋</span> Histórico
        </a>
        <?php if ($isAdmin): ?>
          <a class="btn btn-outline btn-lg" href="configuracoes.php">
            <span>⚙️</span> Configurações
          </a>
        <?php else: ?>
          <a class="btn btn-outline btn-lg" style="pointer-events:none;opacity:.6">
            <span>⚙️</span> Configurações
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Mensagens de Sucesso/Erro -->
    <?php if (isset($_GET['sucesso']) && $_GET['sucesso'] === 'fornecedor_cadastrado'): ?>
        <div style="background: #d1fae5; color: #065f46; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #6ee7b7;">
            ✅ Fornecedor cadastrado com sucesso!
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['erro'])): ?>
        <div style="background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f87171;">
            ❌ Erro: <?= htmlspecialchars($_GET['erro']) ?>
        </div>
    <?php endif; ?>

    <!-- Seção de Fornecedores -->
    <div class="card">
      <h2>🏢 Cadastro de Fornecedores</h2>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <div>
          <h3>📝 Novo Fornecedor</h3>
          <form method="POST" action="fornecedor_cadastro.php" style="display: flex; flex-direction: column; gap: 10px;">
            <input type="text" name="nome" placeholder="Nome do fornecedor" required style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <input type="text" name="cnpj" placeholder="CNPJ (opcional)" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <input type="text" name="telefone" placeholder="Telefone" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <input type="email" name="email" placeholder="E-mail" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <input type="text" name="contato_responsavel" placeholder="Contato responsável" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            <textarea name="endereco" placeholder="Endereço completo" rows="3" style="padding: 10px; border: 1px solid #ddd; border-radius: 5px;"></textarea>
            <button type="submit" class="btn btn-primary" style="padding: 10px 20px; background: #1e40af; color: white; border: none; border-radius: 5px; cursor: pointer;">
              ➕ Cadastrar Fornecedor
            </button>
          </form>
        </div>
        <div>
          <h3>📋 Fornecedores Cadastrados</h3>
          <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; padding: 10px;">
            <?php
            try {
                $stmt = $pdo->query("SELECT id, nome, telefone, email, contato_responsavel FROM fornecedores WHERE ativo = true ORDER BY nome");
                $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($fornecedores)) {
                    echo "<p style='color: #666; text-align: center; padding: 20px;'>Nenhum fornecedor cadastrado</p>";
                } else {
                    foreach ($fornecedores as $fornecedor) {
                        echo "<div style='padding: 10px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;'>";
                        echo "<div>";
                        echo "<strong>" . htmlspecialchars($fornecedor['nome']) . "</strong><br>";
                        echo "<small style='color: #666;'>" . htmlspecialchars($fornecedor['telefone']) . " • " . htmlspecialchars($fornecedor['email']) . "</small>";
                        echo "</div>";
                        echo "<div style='display: flex; gap: 5px;'>";
                        echo "<a href='fornecedor_editar.php?id=" . $fornecedor['id'] . "' style='padding: 5px 10px; background: #3b82f6; color: white; text-decoration: none; border-radius: 3px; font-size: 12px;'>✏️</a>";
                        echo "<a href='fornecedor_excluir.php?id=" . $fornecedor['id'] . "' style='padding: 5px 10px; background: #dc2626; color: white; text-decoration: none; border-radius: 3px; font-size: 12px;' onclick='return confirm(\"Tem certeza?\")'>🗑️</a>";
                        echo "</div>";
                        echo "</div>";
                    }
                }
            } catch (Exception $e) {
                echo "<p style='color: red;'>Erro ao carregar fornecedores: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabela 1: Últimas listas de COMPRAS -->
    <div class="card">
      <h2>📋 Últimas listas de compras</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:80px;">Nº</th>
              <th>Data gerada</th>
              <th>Espaço</th>
              <th>Eventos (resumo)</th>
              <th>Criado por</th>
              <th style="width:220px;">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rowsC): foreach ($rowsC as $r): ?>
              <tr>
                <td><strong>#<?= (int)$r['id'] ?></strong></td>
                <td><?= h(dt($r['criado_em'])) ?></td>
                <td><span class="badge"><?= h($r['espaco_resumo'] ?: 'Múltiplos') ?></span></td>
                <td><?= h($r['resumo_eventos']) ?></td>
                <td><?= h($r['criado_por_nome'] ?: ('#'.(int)$r['criado_por'])) ?></td>
                <td class="actions">
                  <a href="lc_ver.php?id=<?= (int)$r['id'] ?>&tipo=compras" target="_blank" class="btn-small">👁️ Ver</a>
                  <a href="lc_pdf.php?id=<?= (int)$r['id'] ?>&tipo=compras" target="_blank" class="btn-small">📄 PDF</a>
                  <a href="lc_excluir.php?id=<?= (int)$r['id'] ?>" onclick="return confirm('Enviar para lixeira?')" class="btn-small danger">🗑️ Excluir</a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="6" class="text-center">
                <div class="alert">Nenhuma lista de compras gerada ainda. <a href="lista_compras.php">Clique aqui para gerar uma nova lista</a>.</div>
              </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Paginação COMPRAS -->
    <?php
    $maxPgC = max(1, (int)ceil($totalC / $per));
    if ($maxPgC > 1):
    ?>
      <div style="margin:8px 0;">
        <?php for($i=1;$i<=$maxPgC;$i++): ?>
          <?php if ($i == $pgC): ?>
            <strong>[<?= $i ?>]</strong>
          <?php else: ?>
            <a href="?pgC=<?= $i ?>&pgE=<?= $pgE ?>">[<?= $i ?>]</a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

    <hr>

    <!-- Tabela 2: Últimas listas de ENCOMENDAS -->
    <div class="card">
      <h2>📦 Últimas listas de encomendas</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:80px;">Nº</th>
              <th>Data gerada</th>
              <th>Espaço</th>
              <th>Eventos (resumo)</th>
              <th>Criado por</th>
              <th style="width:220px;">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rowsE): foreach ($rowsE as $r): ?>
              <tr>
                <td><strong>#<?= (int)$r['id'] ?></strong></td>
                <td><?= h(dt($r['criado_em'])) ?></td>
                <td><span class="badge"><?= h($r['espaco_resumo'] ?: 'Múltiplos') ?></span></td>
                <td><?= h($r['resumo_eventos']) ?></td>
                <td><?= h($r['criado_por_nome'] ?: ('#'.(int)$r['criado_por'])) ?></td>
                <td class="actions">
                  <a href="lc_ver.php?id=<?= (int)$r['id'] ?>&tipo=encomendas" target="_blank" class="btn-small">👁️ Ver</a>
                  <a href="lc_pdf.php?id=<?= (int)$r['id'] ?>&tipo=encomendas" target="_blank" class="btn-small">📄 PDF</a>
                  <a href="lc_excluir.php?id=<?= (int)$r['id'] ?>" onclick="return confirm('Enviar para lixeira?')" class="btn-small danger">🗑️ Excluir</a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="6" class="text-center">
                <div class="alert">Nenhuma lista de encomendas gerada ainda. <a href="lista_compras.php">Clique aqui para gerar uma nova lista</a>.</div>
              </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Paginação ENCOMENDAS -->
    <?php
    $maxPgE = max(1, (int)ceil($totalE / $per));
    if ($maxPgE > 1):
    ?>
      <div style="margin:8px 0;">
        <?php for($i=1;$i<=$maxPgE;$i++): ?>
          <?php if ($i == $pgE): ?>
            <strong>[<?= $i ?>]</strong>
          <?php else: ?>
            <a href="?pgC=<?= $pgC ?>&pgE=<?= $i ?>">[<?= $i ?>]</a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>

  <?php if ($db_error): ?>
    <div class="err"><?php echo h($db_error); ?></div>
  <?php elseif ($erroQuery): ?>
    <div class="err"><?php echo h($erroQuery); ?></div>
  <?php endif; ?>


  <div class="meta">Dica: “Espaço” mostra a unidade consolidada; quando múltiplas, exibe <strong>Múltiplos</strong>.</div>
</div>
</body>
</html>
