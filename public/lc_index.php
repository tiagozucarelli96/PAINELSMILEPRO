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
if (!$uid || !is_numeric((string)$uid) || !$estaLogado) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
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
        // Tabela esperada: lc_listas (id, tipo, eventos_resumo, espaco_consolidado, criado_por, criado_por_nome, data_gerada, deleted_at)
        // tipo: lc_tipo_lista => 'compras' | 'encomendas'
        $sqlBase = "
        SELECT l.id,
               l.grupo_id,
               l.tipo,
               COALESCE(l.espaco_consolidado, 'Múltiplos') AS espaco_resumo,
               COALESCE(l.eventos_resumo, '')              AS resumo_eventos,
               l.data_gerada,
               COALESCE(l.criado_por_nome, '—')            AS criado_por
        FROM lc_listas l
        WHERE l.deleted_at IS NULL AND l.tipo = :tipo
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
        $sqlCount = "SELECT COUNT(*)::int FROM lc_listas WHERE deleted_at IS NULL AND tipo = :tipo";
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

// ========= UI =========
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Lista de Compras — Histórico</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{ --azul:#004aad; --bg:#f6f8ff; --cinza:#667085; --borda:#e5e7eb; }
*{ box-sizing: border-box; }
body{ margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial; background:var(--bg); color:#0f172a; }
.container{ max-width:1100px; margin:22px auto; padding:0 16px; }
.topbar{ display:flex; gap:10px; align-items:center; margin-bottom:16px; flex-wrap:wrap; }
.topbar .grow{ flex:1; }
.btn{ background:var(--azul); color:#fff; border:none; border-radius:10px; padding:10px 14px; font-weight:600; cursor:pointer; }
.btn.secondary{ background:#e9efff; color:#0f172a; }
.btn:disabled{ opacity:.5; cursor:not-allowed; }
.card{ background:#fff; border:1px solid var(--borda); border-radius:14px; box-shadow:0 1px 2px rgba(16,24,40,.05); margin-bottom:18px; }
.card h2{ font-size:18px; margin:0; padding:14px 16px; border-bottom:1px solid var(--borda); }
.table-wrap{ overflow:auto; }
table{ width:100%; border-collapse:collapse; }
th,td{ padding:12px 10px; border-bottom:1px solid var(--borda); text-align:left; font-size:14px; vertical-align:top; }
th{ background:#fafafa; color:#111827; font-weight:700; }
.badge{ display:inline-block; background:#eef2ff; border:1px solid #c7d2fe; color:#1e3a8a; padding:3px 8px; border-radius:999px; font-size:12px; }
.meta{ color:var(--cinza); font-size:12px; }
.actions a, .actions button{ font-size:13px; margin-right:10px; text-decoration:none; }
.pagin{ display:flex; justify-content:flex-end; gap:8px; padding:12px 16px; }
.pagin a, .pagin span{ padding:6px 10px; border:1px solid var(--borda); border-radius:8px; background:#fff; text-decoration:none; color:#111827; }
.pagin .atual{ background:#eff6ff; border-color:#bfdbfe; font-weight:700; }
.alert{ background:#fff8e1; border:1px solid #fde68a; padding:10px 12px; border-radius:10px; margin-bottom:12px; font-size:14px; }
.err{ background:#fee2e2; border:1px solid #fecaca; padding:10px 12px; border-radius:10px; margin-bottom:12px; }
.top-buttons{ display:flex; gap:10px; }
@media (max-width:640px){
  th:nth-child(3), td:nth-child(3){ display:none; } /* esconde 'Espaço' no mobile p/ caber */
}
</style>
</head>
<body class="panel has-sidebar">
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
  <div class="container">

    <!-- INÍCIO CONTEÚDO -->
    <div class="topbar">
      ...seu conteúdo (tabelas, cards etc.)...
    </div>
    <!-- FIM CONTEÚDO -->

  </div> <!-- fecha .container -->
</div> <!-- fecha .main-content -->
</body>
</html>
  <div class="topbar">
    <div class="grow">
      <h1 style="margin:0;font-size:22px;">Lista de Compras — Histórico</h1>
      <div class="meta">Gere novas listas e consulte as últimas compras/encomendas.</div>
    </div>
    <div class="top-buttons">
  <a class="btn" href="lista_compras.php">Gerar Lista de Compras</a>
  <?php if ($isAdmin): ?>
    <a class="btn secondary" href="configuracoes.php">Configurações</a>
  <?php else: ?>
    <a class="btn secondary" style="pointer-events:none;opacity:.6">Configurações</a>
  <?php endif; ?>
</div>


  <?php if ($db_error): ?>
    <div class="err"><?php echo h($db_error); ?></div>
  <?php elseif ($erroQuery): ?>
    <div class="err"><?php echo h($erroQuery); ?></div>
  <?php endif; ?>

  <!-- Compras -->
  <div class="card">
    <h2>Últimas listas de compras</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:70px;">Nº</th>
            <th>Data gerada</th>
            <th>Espaço</th>
            <th>Eventos (resumo)</th>
            <th>Criado por</th>
            <th style="width:210px;">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$compras): ?>
          <tr><td colspan="6">
            <div class="alert">Nenhuma lista gerada ainda. Clique em <strong>Gerar Lista de Compras</strong> para começar.</div>
          </td></tr>
        <?php else: foreach ($compras as $r): ?>
          <tr>
            <td>#<?php echo (int)$r['grupo_id']; ?></td>
            <td>
              <?php echo h(fmtDataPt($r['data_gerada'] ?? '')); ?><br>
              <span class="meta">ID interno: <?php echo (int)$r['grupo_id']; ?></span>
            </td>
            <td><span class="badge"><?php echo h($r['espaco_resumo'] ?? ''); ?></span></td>
            <td><?php echo nl2br(h($r['resumo_eventos'] ?? '')); ?></td>
            <td><?php echo h($r['criado_por'] ?? '—'); ?></td>
            <td class="actions">
              <a href="ver.php?g=<?php echo (int)$r['grupo_id']; ?>" target="_blank">Visualizar</a>
              <a href="pdf_compras.php?grupo_id=<?php echo (int)$r['grupo_id']; ?>" target="_blank">PDF</a>
              <button type="button" disabled title="Mover para lixeira (via Configurações)">Excluir</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages1 > 1): ?>
      <div class="pagin">
        <?php for ($i=1; $i<=$pages1; $i++): ?>
          <?php if ($i === $p1): ?>
            <span class="atual"><?php echo $i; ?></span>
          <?php else: ?>
            <a href="?p1=<?php echo $i; ?>&p2=<?php echo $p2; ?>"><?php echo $i; ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Encomendas -->
  <div class="card">
    <h2>Últimas listas de encomendas</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:70px;">Nº</th>
            <th>Data gerada</th>
            <th>Espaço</th>
            <th>Eventos (resumo)</th>
            <th>Criado por</th>
            <th style="width:210px;">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$encomendas): ?>
          <tr><td colspan="6">
            <div class="alert">Sem listas de encomendas ainda. Ao gerar uma lista, a saída é criada automaticamente.</div>
          </td></tr>
        <?php else: foreach ($encomendas as $r): ?>
          <tr>
            <td>#<?php echo (int)$r['grupo_id']; ?></td>
            <td>
              <?php echo h(fmtDataPt($r['data_gerada'] ?? '')); ?><br>
              <span class="meta">ID interno: <?php echo (int)$r['grupo_id']; ?></span>
            </td>
            <td><span class="badge"><?php echo h($r['espaco_resumo'] ?? ''); ?></span></td>
            <td><?php echo nl2br(h($r['resumo_eventos'] ?? '')); ?></td>
            <td><?php echo h($r['criado_por'] ?? '—'); ?></td>
            <td class="actions">
              <a href="ver.php?g=<?php echo (int)$r['grupo_id']; ?>" target="_blank">Visualizar</a>
              <a href="pdf_encomendas.php?grupo_id=<?php echo (int)$r['grupo_id']; ?>" target="_blank">PDF</a>
              <button type="button" disabled title="Mover para lixeira (via Configurações)">Excluir</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages2 > 1): ?>
      <div class="pagin">
        <?php for ($i=1; $i<=$pages2; $i++): ?>
          <?php if ($i === $p2): ?>
            <span class="atual"><?php echo $i; ?></span>
          <?php else: ?>
            <a href="?p1=<?php echo $p1; ?>&p2=<?php echo $i; ?>"><?php echo $i; ?></a>
          <?php endif; ?>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="meta">Dica: “Espaço” mostra a unidade consolidada; quando múltiplas, exibe <strong>Múltiplos</strong>.</div>
</div>
</body>
</html>
