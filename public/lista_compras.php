<?php
declare(strict_types=1);
// public/lista_compras.php - Gerador de Listas de Compras (PostgreSQL/Railway)
// Versão: 2025-09-03 (rascunho in-page + integração ME Eventos)

ini_set('display_errors','1'); error_reporting(E_ALL);

// ========= Sessão / Auth =========
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); }
if (!$uid || !is_numeric($uid) || !$estaLogado) { http_response_code(403); echo "Acesso negado. Faça login para continuar."; exit; }
$uid = (int)$uid;
$usuarioNome = (string)($_SESSION['user_name'] ?? ($_SESSION['nome'] ?? ''));

// ========= Conexão =========
require_once __DIR__ . '/conexao.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

// ========= Util =========
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dow_pt(\DateTime $d): string { static $dias=['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado']; return $dias[(int)$d->format('w')]; }

// ========= Integração ME Eventos (AJAX) =========
if (($_GET['ajax'] ?? '') === 'me_buscar') {
  header('Content-Type: application/json; charset=utf-8');

  // BASE e KEY
  $ME_BASE = rtrim(getenv('ME_BASE_URL') ?: '', '/');   // ex.: https://app2.meeventos.com.br/lisbonbuffet
  $ME_KEY  = getenv('ME_API_KEY') ?: '';
  if (!$ME_BASE || !$ME_KEY) {
    echo json_encode(['ok'=>false,'error'=>'Configuração ausente: ME_BASE_URL e ME_API_KEY.']); 
    exit;
  }

  // Params seguros
  $start = trim((string)($_GET['start'] ?? ''));
  $end   = trim((string)($_GET['end']   ?? ''));
  $search= trim((string)($_GET['search']?? ''));
  $page  = max(1, (int)($_GET['page']  ?? 1));
  $limit = (int)($_GET['limit'] ?? 50);
  if ($limit < 1) $limit = 50; elseif ($limit > 200) $limit = 200;

  // datas apenas YYYY-MM-DD
  $reDate = '/^\d{4}-\d{2}-\d{2}$/';
  if ($start !== '' && !preg_match($reDate,$start)) $start = '';
  if ($end   !== '' && !preg_match($reDate,$end))   $end   = '';

  $params = ['page'=>$page,'limit'=>$limit];
  if ($start !== '') $params['start']  = $start;
  if ($end   !== '') $params['end']    = $end;
  if ($search!== '') $params['search'] = $search;

  // Query RFC3986 (espaço = %20, evita '+')
  $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

  // Tenta /events e /events/ (alguns WAFs exigem barra final)
  $candidates = [
    $ME_BASE . '/api/v1/events'  . ($qs ? ('?' . $qs) : ''),
    $ME_BASE . '/api/v1/events/' . ($qs ? ('?' . $qs) : ''),
  ];

  $lastErr = null; $lastHttp = 0; $lastBody = null; $tried = [];

  foreach ($candidates as $url) {
    $tried[] = $url;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer '.$ME_KEY,
        'Accept: application/json'
      ],
      CURLOPT_USERAGENT      => 'PainelSmile/1.0 (+PHP cURL)',
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$err && $resp && $http < 400) {
      $j = json_decode($resp, true);
      $rows = $j['data'] ?? $j ?? [];
      $out  = [];
      foreach ($rows as $e) {
        $out[] = [
          'espaco'     => (string)($e['localevento'] ?? ''),
          'convidados' => (int)($e['convidados'] ?? 0),
          'horario'    => (string)($e['horaevento'] ?? ''),
          'evento'     => (string)($e['nomeevento'] ?? ''),
          'data'       => substr((string)($e['dataevento'] ?? ''), 0, 10),
        ];
      }
      echo json_encode(['ok'=>true, 'events'=>$out, 'pagination'=>$j['pagination'] ?? null], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $lastErr  = $err;
    $lastHttp = $http;
    $lastBody = $resp;
  }

  echo json_encode([
    'ok'    => false,
    'error' => "Erro na API ($lastHttp): " . ($lastErr ?: ($lastBody ?: 'sem resposta')),
    'debug' => ['tried' => $tried]
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
// ========= Rascunhos (tudo na mesma página) =========
$pdo->exec("
CREATE TABLE IF NOT EXISTS lc_rascunhos (
  id              BIGSERIAL PRIMARY KEY,
  criado_por      BIGINT NOT NULL,
  criado_por_nome TEXT,
  payload         JSONB NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at      TIMESTAMP NOT NULL DEFAULT NOW()
)");
function rascunho_carregar(PDO $pdo, int $id, int $uid): ?array {
    $st = $pdo->prepare("SELECT id, payload FROM lc_rascunhos WHERE id=:i AND criado_por=:u");
    $st->execute([':i'=>$id, ':u'=>$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? ['id'=>(int)$row['id'], 'payload'=>json_decode($row['payload'], true)] : null;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_rascunho') {
    $evs = $_POST['eventos'] ?? [];
    $sel = $_POST['itens'] ?? [];
    $ov  = $_POST['fornecedor_modo'] ?? [];
    $itIds = [];
    foreach ($sel as $ids) { foreach ((array)$ids as $iid) { $iid=(int)$iid; if ($iid>0) $itIds[$iid]=true; } }
    $payload = ['eventos'=>array_values($evs), 'itens_ids'=>array_map('intval', array_keys($itIds)), 'overrides'=>$ov];
    if (!empty($_POST['rascunho_id'])) {
        $st = $pdo->prepare("UPDATE lc_rascunhos SET payload=:p, updated_at=NOW() WHERE id=:i AND criado_por=:u");
        $st->execute([':p'=>json_encode($payload, JSON_UNESCAPED_UNICODE), ':i'=>(int)$_POST['rascunho_id'], ':u'=>$uid]);
        header('Location: lista_compras.php?ok=rascunho_atualizado'); exit;
    } else {
        $st = $pdo->prepare("INSERT INTO lc_rascunhos (criado_por, criado_por_nome, payload) VALUES (:u,:n,:p)");
        $st->execute([':u'=>$uid, ':n'=>$usuarioNome, ':p'=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        header('Location: lista_compras.php?ok=rascunho_salvo'); exit;
    }
}
if (($_GET['acao'] ?? '') === 'excluir_rascunho' && !empty($_GET['id'])) {
    $st = $pdo->prepare("DELETE FROM lc_rascunhos WHERE id=:i AND criado_por=:u");
    $st->execute([':i'=>(int)$_GET['id'], ':u'=>$uid]);
    header('Location: lista_compras.php?ok=rascunho_excluido'); exit;
}
$RAS = [];
$stR = $pdo->prepare("SELECT id, to_char(updated_at,'DD/MM HH24:MI') AS quando, jsonb_array_length(payload->'eventos') AS qtd_ev, jsonb_array_length(payload->'itens_ids') AS qtd_it FROM lc_rascunhos WHERE criado_por=:u ORDER BY updated_at DESC LIMIT 20");
$stR->execute([':u'=>$uid]);
$RAS = $stR->fetchAll(PDO::FETCH_ASSOC);

$RAS_PAYLOAD = null;
if (($_GET['acao'] ?? '') === 'editar_rascunho' && !empty($_GET['id'])) {
    $R = rascunho_carregar($pdo, (int)$_GET['id'], $uid);
    if ($R) { $RAS_PAYLOAD = $R; }
}

// ========= Cargas (formulário) =========
// Observação: se as colunas 'ativo'/'ordem' não existirem no seu schema, troque as duas linhas abaixo para remover esses filtros/ordenadores.
$cats = $pdo->query("SELECT id, nome FROM lc_categorias WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);

$itensByCat = [];
$st = $pdo->query("SELECT id, categoria_id, tipo::text AS tipo, nome, unidade, fornecedor_id, ficha_id 
                   FROM lc_itens 
                   WHERE ativo IS TRUE 
                   ORDER BY nome");
foreach ($st as $r) { $itensByCat[$r['categoria_id']][] = $r; }

$forns = $pdo->query("SELECT id, nome, modo_padrao::text AS modo_padrao FROM fornecedores ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

$err = '';

// ========= POST (Finalizar = gerar listas) =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['acao'] ?? '') !== 'salvar_rascunho')) {
    try {
        // Eventos
        $evs = $_POST['eventos'] ?? [];
        if (!$evs || !is_array($evs)) { throw new Exception('Informe ao menos um evento.'); }

        // Itens selecionados
        $sel = $_POST['itens'] ?? []; // itens[categoria_id][] = item_id
        $selItemIds = [];
        foreach ($sel as $ids) {
            foreach ((array)$ids as $iid) { $iid = (int)$iid; if ($iid>0) $selItemIds[$iid]=true; }
        }
        if (!$selItemIds) { throw new Exception('Selecione itens em alguma categoria.'); }

        // Overrides por fornecedor
        $ov = $_POST['fornecedor_modo'] ?? []; // [fornecedor_id] => 'consolidado'|'separado'

        // Cabeçalho/resumo
        $espacos = [];
        $eventosRows = [];
        foreach ($evs as $e) {
            $espacos[] = trim((string)($e['espaco'] ?? ''));
            $dt = new DateTime($e['data'] ?? 'now');
            $eventosRows[] = sprintf('%s (%s %s %s)',
                trim((string)($e['evento'] ?? '')),
                dow_pt($dt),
                $dt->format('d/m'),
                trim((string)($e['horario'] ?? ''))
            );
        }
        $espacos = array_values(array_unique(array_filter($espacos)));
        $espacoConsolidado = count($espacos) > 1 ? 'Múltiplos' : ($espacos[0] ?? '');
        $eventosResumo = count($eventosRows) . ' evento(s): ' . implode(' • ', $eventosRows);
        $numEvs = count($evs);

        // Transação
        $pdo->beginTransaction();

        // grupo_id (simples): max+1 (mantido conforme seu fluxo atual)
        $grupoId = (int)$pdo->query("SELECT COALESCE(MAX(grupo_id),0)+1 FROM lc_listas")->fetchColumn();

        // Cria cabeçalhos
        $insLista = $pdo->prepare("
            INSERT INTO lc_listas (grupo_id, tipo, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome)
            VALUES (:g, :t, :e, :r, :u, :un)
        ");
        foreach (['compras','encomendas'] as $tipo) {
            $insLista->execute([
                ':g'=>$grupoId, ':t'=>$tipo,
                ':e'=>$espacoConsolidado, ':r'=>$eventosResumo,
                ':u'=>$uid, ':un'=>$usuarioNome
            ]);
        }

        // Salva eventos (RETURNING)
        $eventosIdx = [];
        $insEv = $pdo->prepare("
            INSERT INTO lc_listas_eventos (grupo_id, espaco, convidados, horario, evento, data, dia_semana)
            VALUES (:g,:es,:cv,:hr,:ev,:dt,:dw)
            RETURNING id, espaco, to_char(data,'DD/MM') AS d, horario
        ");
        foreach ($evs as $e) {
            $esp = trim((string)($e['espaco'] ?? ''));
            $cv  = (int)($e['convidados'] ?? 0);
            $hr  = trim((string)($e['horario'] ?? ''));
            $evn = trim((string)($e['evento'] ?? ''));
            $dt  = new DateTime($e['data'] ?? 'now');

            $insEv->execute([
                ':g'=>$grupoId, ':es'=>$esp, ':cv'=>$cv, ':hr'=>$hr, ':ev'=>$evn,
                ':dt'=>$dt->format('Y-m-d'), ':dw'=>dow_pt($dt)
            ]);
            $eventosIdx[] = $insEv->fetch(PDO::FETCH_ASSOC);
        }

        // Carrega apenas ITENS selecionados
        $ids = implode(',', array_map('intval', array_keys($selItemIds)));
        $itens = $pdo->query("
            SELECT id, tipo::text AS tipo, nome, unidade, fornecedor_id, ficha_id
            FROM lc_itens
            WHERE ativo IS TRUE AND id IN ($ids)
            ORDER BY nome
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Auxiliares
        $compras = []; // "nome|unidade" => qtd
        $encom   = []; // "fornecedorId|eventoId" => ["nome|un" => qtd]

        $getFichaComp = $pdo->prepare("
            SELECT i.nome AS nome_insumo, c.quantidade, c.unidade
            FROM lc_ficha_componentes c
            JOIN lc_insumos i ON i.id=c.insumo_id
            WHERE c.ficha_id=:f
        ");

        $fornMap = [];
        foreach ($forns as $f) { $fornMap[(int)$f['id']] = $f; }

        // Processa itens
        foreach ($itens as $it) {
            $tipo = $it['tipo'];

            if ($tipo === 'preparo') {
                if (!empty($it['ficha_id'])) {
                    $getFichaComp->execute([':f'=>(int)$it['ficha_id']]);
                    foreach ($getFichaComp as $fc) {
                        $key = $fc['nome_insumo'].'|'.$fc['unidade'];
                        $compras[$key] = ($compras[$key] ?? 0) + (float)$fc['quantidade'] * $numEvs;
                    }
                }
            } elseif ($tipo === 'comprado') {
                $fid  = (int)($it['fornecedor_id'] ?? 0);
                $modo = $ov[$fid] ?? ($fornMap[$fid]['modo_padrao'] ?? 'consolidado'); // 'consolidado'|'separado'

                $nk = $it['nome'].'|'.$it['unidade'];
                if ($modo === 'separado') {
                    foreach ($eventosIdx as $e) {
                        $k = $fid.'|'.(int)$e['id']; // por evento
                        $encom[$k][$nk] = ($encom[$k][$nk] ?? 0) + 1;
                    }
                } else {
                    $k = $fid.'|0'; // consolidado
                    $encom[$k][$nk] = ($encom[$k][$nk] ?? 0) + $numEvs;
                }
            } else { // fixo
                $key = $it['nome'].'|'.$it['unidade'];
                $compras[$key] = ($compras[$key] ?? 0) + 1 * $numEvs;
            }
        }

        // Persiste COMPRAS
        $insC = $pdo->prepare("
            INSERT INTO lc_compras_consolidadas (grupo_id, insumo_id, nome_insumo, unidade, quantidade)
            VALUES (:g, NULL, :n, :u, :q)
        ");
        foreach ($compras as $k=>$qtd) {
            [$nome,$uni] = explode('|',$k,2);
            $insC->execute([':g'=>$grupoId, ':n'=>$nome, ':u'=>$uni, ':q'=>$qtd]);
        }

        // Persiste ENCOMENDAS
        $insE = $pdo->prepare("
            INSERT INTO lc_encomendas_itens
                (grupo_id, fornecedor_id, fornecedor_nome, evento_id, evento_label, item_id, nome_item, unidade, quantidade)
            VALUES (:g,:f,:fn,:ev,:el,:it,:n,:u,:q)
        ");
        $evLabelById = [];
        foreach ($eventosIdx as $e) { $evLabelById[(int)$e['id']] = $e['espaco'].' • '.$e['d'].' '.$e['horario']; }

        foreach ($encom as $k=>$map) {
            [$fid,$evId] = array_map('intval', explode('|',$k,2));
            $fn = $fornMap[$fid]['nome'] ?? 'Sem fornecedor';
            $el = $evId>0 ? ($evLabelById[$evId] ?? null) : null;

            foreach ($map as $nk=>$qtd) {
                [$nome,$uni] = explode('|',$nk,2);
                $insE->execute([
                    ':g'=>$grupoId,
                    ':f'=>$fid ?: null,
                    ':fn'=>$fn,
                    ':ev'=>$evId>0 ? $evId : null,
                    ':el'=>$el,
                    ':it'=>null,
                    ':n'=>$nome,
                    ':u'=>$uni,
                    ':q'=>$qtd
                ]);
            }
        }

        // Overrides escolhidos
        if (!empty($ov)) {
            // precisa de UNIQUE (grupo_id, fornecedor_id)
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_over_grupo_forn ON lc_encomendas_overrides (grupo_id, fornecedor_id)");
            $insOv = $pdo->prepare("
                INSERT INTO lc_encomendas_overrides (grupo_id, fornecedor_id, modo)
                VALUES (:g,:f,:m)
                ON CONFLICT (grupo_id, fornecedor_id) DO UPDATE SET modo = EXCLUDED.modo
            ");
            foreach ($ov as $fid=>$m) {
                if ($m==='consolidado' || $m==='separado') {
                    $insOv->execute([':g'=>$grupoId, ':f'=>(int)$fid, ':m'=>$m]);
                }
            }
        }

        $pdo->commit();
        header('Location: lc_index.php?msg='.urlencode('Listas geradas com sucesso!')); exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Gerar Lista de Compras</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
.form{background:#fff;border:1px solid #dfe7f4;border-radius:12px;padding:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid .full{grid-column:1/-1}
.block{border:1px dashed #dbe6ff;border-radius:10px;padding:12px;margin-top:12px}
h2{margin:10px 0}
label.small{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
.input{width:100%;padding:10px;border:1px solid #cfe0ff;border-radius:8px}
.category{margin-bottom:10px}
.items{display:none;margin-top:8px;padding-left:12px}
.items.active{display:block}
.btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}
.btn.gray{background:#e9efff;color:#004aad}
.alert{padding:10px;border-radius:8px;margin-bottom:10px}
.alert.err{background:#ffeded;border:1px solid #ffb3b3;color:#8a0c0c}
.alert.success{background:#edffed;border:1px solid #b3ffb3;color:#0c8a0c}
.table{width:100%;border-collapse:collapse}
.table th,.table td{padding:8px;border-bottom:1px solid #eee;text-align:left}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef3ff;color:#004aad;font-weight:700;font-size:12px}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;padding:16px}
.modal .card{background:#fff;max-width:920px;width:100%;border-radius:12px;padding:16px}
.modal h3{margin:0 0 10px}
</style>
<script>
function toggleCat(id){ const el=document.getElementById('items-'+id); if(el) el.classList.toggle('active'); }
function salvarRascunho(){ document.getElementById('acao').value='salvar_rascunho'; document.getElementById('formLC').submit(); }
async function abrirME(){
  const m=document.getElementById('modalME'); m.style.display='flex';
}
function fecharME(){ document.getElementById('modalME').style.display='none'; }
async function buscarME(ev){
  ev.preventDefault();
  const form = ev.target;
  const params = new URLSearchParams(new FormData(form));
  const btn = form.querySelector('button[type=submit]');
  btn.disabled = true; btn.textContent = 'Buscando...';
  try{
    const r = await fetch('lista_compras.php?ajax=me_buscar&'+params.toString(), {headers:{'Accept':'application/json'}});
    const j = await r.json();
    const box = document.getElementById('meResultados');
    box.innerHTML = '';
    if(!j.ok){ box.innerHTML = '<div class="alert err">'+(j.error||'Erro')+'</div>'; return; }
    if(!Array.isArray(j.events) || !j.events.length){ box.innerHTML = '<em>Sem eventos no período/termo informado.</em>'; return; }
    j.events.forEach((e,i)=>{
      const row = document.createElement('div');
      row.className = 'block';
      row.innerHTML = `
        <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
          <div>
            <div><strong>${e.evento||'(sem título)'}</strong> <span class="badge">${e.data||''} ${e.horario||''}</span></div>
            <div style="font-size:13px;color:#333">Espaço: ${e.espaco||''} • Convidados: ${e.convidados||0}</div>
          </div>
          <button type="button" class="btn" onclick='addEventoME(${JSON.stringify(e).replace(/'/g,"&#39;")})'>Adicionar</button>
        </div>`;
      box.appendChild(row);
    });
  }catch(err){ alert('Erro na busca: '+err); }
  finally{ btn.disabled=false; btn.textContent='Buscar'; }
}
function addEventoME(e){
  // encontra próximo índice de evento na tela
  const wrap=document.getElementById('ev-wrap');
  const idx = wrap.querySelectorAll('.ev-row').length;
  const html = `
  <div class="grid ev-row">
    <div>
      <label class="small">Espaço</label>
      <input class="input" name="eventos[${idx}][espaco]" required value="${e.espaco||''}">
    </div>
    <div>
      <label class="small">Convidados</label>
      <input class="input" type="number" name="eventos[${idx}][convidados]" min="0" value="${parseInt(e.convidados||0,10)}">
    </div>
    <div>
      <label class="small">Horário</label>
      <input class="input" name="eventos[${idx}][horario]" value="${e.horario||''}">
    </div>
    <div>
      <label class="small">Evento</label>
      <input class="input" name="eventos[${idx}][evento]" value="${e.evento||''}">
    </div>
    <div class="full">
      <label class="small">Data</label>
      <input class="input" type="date" name="eventos[${idx}][data]" value="${e.data||''}">
    </div>
  </div>`;
  wrap.insertAdjacentHTML('beforeend', html);
  // fecha modal para agilizar
  fecharME();
}
</script>
</head>
<body class="panel">
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
  <h1>Gerar Lista de Compras</h1>

  <div class="form">
    <?php if (!empty($err)): ?><div class="alert err"><?=h($err)?></div><?php endif; ?>
    <?php if (isset($_GET['ok'])): ?>
      <div class="alert success">
        <?php if ($_GET['ok']==='rascunho_salvo')  echo 'Rascunho salvo.'; ?>
        <?php if ($_GET['ok']==='rascunho_atualizado') echo 'Rascunho atualizado.'; ?>
        <?php if ($_GET['ok']==='rascunho_excluido')  echo 'Rascunho excluído.'; ?>
      </div>
    <?php endif; ?>

    <!-- RASCUNHOS -->
    <div class="block">
      <h2>📝 Rascunhos (seus)</h2>
      <?php if (!$RAS): ?>
        <div><em>Sem rascunhos salvos.</em></div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th>#</th><th>Atualizado</th><th>Eventos</th><th>Itens</th><th>Ações</th></tr></thead>
          <tbody>
          <?php foreach ($RAS as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['quando']) ?></td>
              <td><?= (int)$r['qtd_ev'] ?></td>
              <td><?= (int)$r['qtd_it'] ?></td>
              <td>
                <a class="btn gray" href="lista_compras.php?acao=editar_rascunho&id=<?= (int)$r['id'] ?>">Editar</a>
                <a class="btn" style="background:#ff5a5a" href="lista_compras.php?acao=excluir_rascunho&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir este rascunho?')">Excluir</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <?php
    // Pré-carrega eventos/itens do rascunho se houver
    $evDraft = $RAS_PAYLOAD['payload']['eventos'] ?? [];
    if (!$evDraft) {
      $evDraft = [[ 'espaco'=>'', 'convidados'=>0, 'horario'=>'', 'evento'=>'', 'data'=>'' ]];
    }
    $draftIds = $RAS_PAYLOAD['payload']['itens_ids'] ?? [];
    $draftSet = array_flip(array_map('intval',$draftIds));
    ?>

    <form id="formLC" method="post">
      <?php if ($RAS_PAYLOAD): ?>
        <input type="hidden" name="rascunho_id" value="<?= (int)$RAS_PAYLOAD['id'] ?>">
      <?php endif; ?>
      <input type="hidden" name="acao" id="acao" value="">

      <div class="block">
        <h2 style="display:flex;align-items:center;gap:8px">
          Eventos
          <button class="btn gray" type="button" onclick="abrirME()">Buscar na ME Eventos</button>
        </h2>
        <div id="ev-wrap">
          <?php foreach ($evDraft as $i=>$e): ?>
            <div class="grid ev-row">
              <div>
                <label class="small">Espaço</label>
                <input class="input" name="eventos[<?= $i ?>][espaco]" required value="<?= h($e['espaco'] ?? '') ?>">
              </div>
              <div>
                <label class="small">Convidados</label>
                <input class="input" type="number" name="eventos[<?= $i ?>][convidados]" min="0" value="<?= (int)($e['convidados'] ?? 0) ?>">
              </div>
              <div>
                <label class="small">Horário</label>
                <input class="input" name="eventos[<?= $i ?>][horario]" value="<?= h($e['horario'] ?? '') ?>">
              </div>
              <div>
                <label class="small">Evento</label>
                <input class="input" name="eventos[<?= $i ?>][evento]" value="<?= h($e['evento'] ?? '') ?>">
              </div>
              <div class="full">
                <label class="small">Data</label>
                <input class="input" type="date" name="eventos[<?= $i ?>][data]" value="<?= h($e['data'] ?? '') ?>">
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="block">
        <h2>Categorias e Itens</h2>
        <?php foreach ($cats as $c): ?>
          <div class="category">
            <label>
              <input type="checkbox" onclick="toggleCat(<?= (int)$c['id'] ?>)">
              <?= h($c['nome']) ?>
            </label>
            <div class="items" id="items-<?= (int)$c['id'] ?>">
              <?php foreach (($itensByCat[$c['id']] ?? []) as $it): ?>
                <?php $checked = isset($draftSet[(int)$it['id']]) ? 'checked' : ''; ?>
                <label style="display:block;margin:6px 0">
                  <input type="checkbox" <?=$checked?> name="itens[<?= (int)$c['id'] ?>][]" value="<?= (int)$it['id'] ?>">
                  <?= h($it['nome']) ?>
                  <small>(
                    <?= h($it['tipo']) ?>
                    <?php
                      if ($it['tipo']==='preparo'  && $it['ficha_id'])      echo ', ficha #'.(int)$it['ficha_id'];
                      if ($it['tipo']==='comprado' && $it['fornecedor_id']) echo ', forn #'.(int)$it['fornecedor_id'];
                    ?>
                  )</small>
                </label>
              <?php endforeach; ?>
              <?php if (empty($itensByCat[$c['id']])): ?>
                <div><em>Nenhum item nesta categoria.</em></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($forns): ?>
      <div class="block">
        <h2>Encomendas — Modo por Fornecedor</h2>
        <?php foreach ($forns as $f): ?>
          <label style="display:block;margin:6px 0">
            <?= h($f['nome']) ?>:
            <select name="fornecedor_modo[<?= (int)$f['id'] ?>]" class="input" style="max-width:220px; display:inline-block">
              <option value="">Padrão: <?= h($f['modo_padrao']) ?></option>
              <option value="consolidado">Consolidado</option>
              <option value="separado">Separado por evento</option>
            </select>
          </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap">
        <button class="btn gray" type="button" onclick="salvarRascunho()">Salvar rascunho</button>
        <button class="btn" type="submit" onclick="document.getElementById('acao').value=''">Gerar (finalizar)</button>
        <a class="btn gray" href="dashboard.php">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<!-- Modal ME -->
<div class="modal" id="modalME">
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
      <h3>Buscar eventos na ME</h3>
      <button class="btn gray" type="button" onclick="fecharME()">Fechar</button>
    </div>
    <form onsubmit="buscarME(event)" style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 12px">
      <div>
        <label class="small">Início</label>
        <input class="input" type="date" name="start" required>
      </div>
      <div>
        <label class="small">Fim</label>
        <input class="input" type="date" name="end" required>
      </div>
      <div style="flex:1;min-width:220px">
        <label class="small">Buscar (nome/cliente/obs)</label>
        <input class="input" type="text" name="search" placeholder="Ex: Ouro, aniversário, cliente...">
      </div>
      <div>
        <label class="small">Página</label>
        <input class="input" type="number" name="page" value="1" min="1">
      </div>
      <div>
        <label class="small">Limite</label>
        <input class="input" type="number" name="limit" value="50" min="1" max="200">
      </div>
      <div style="align-self:end">
        <button class="btn" type="submit">Buscar</button>
      </div>
    </form>
    <div id="meResultados"></div>
    <div style="margin-top:8px;font-size:12px;color:#666">
      Dica: Informe um intervalo curto (ex.: fim de semana) para carregar mais rápido.
    </div>
  </div>
</div>
</body>
</html>
