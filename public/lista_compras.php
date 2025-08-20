<?php
// public/lista_compras_gerar.php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['logado'])) { http_response_code(403); echo "Acesso negado."; exit; }

require_once __DIR__.'/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function dow_pt(\DateTime $d): string {
  $dias = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
  return $dias[(int)$d->format('w')];
}

$err=''; $msg='';

// carrega categorias + itens para o formulário
$cats = $pdo->query("select id, nome from lc_categorias where (ativo = 1 or ativo is true) order by ordem, nome");fetchAll(PDO::FETCH_ASSOC);
$itensByCat = [];
if ($cats) {
  $st = $pdo->query("select id, categoria_id, tipo, nome, unidade, fornecedor_id, ficha_id from lc_itens where ativo is true order by nome");
  foreach ($st as $r) { $itensByCat[$r['categoria_id']][] = $r; }
}
// fornecedores (para mostrar modo padrão e permitir override)
$forns = $pdo->query("select id, nome, modo_padrao from fornecedores order by nome")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    $usuarioId = (int)($_SESSION['user_id'] ?? 0);
    $usuarioNome = (string)($_SESSION['user_name'] ?? ''); // ajuste aos nomes reais da sessão

    // eventos (array de linhas)
    $evs = $_POST['eventos'] ?? [];
    if (!$evs || !is_array($evs)) throw new Exception('Informe ao menos um evento.');

    // itens selecionados (por categoria)
    $sel = $_POST['itens'] ?? []; // itens[categoria_id][] = item_id
    $selItemIds = [];
    foreach ($sel as $catId => $ids) {
      foreach ((array)$ids as $iid) { $selItemIds[(int)$iid] = true; }
    }
    if (!$selItemIds) throw new Exception('Selecione itens em alguma categoria.');

    // overrides de fornecedores
    $ov = $_POST['fornecedor_modo'] ?? []; // fornecedor_modo[fornecedor_id] = 'consolidado' | 'separado'

    // cria grupo_id (simples, pode migrar depois pra sequence/tabela)
    $grupoId = (int)$pdo->query("select coalesce(max(grupo_id),0)+1 from lc_listas")->fetchColumn();

    // strings de cabeçalho/resumo
    $espacos = [];
    $eventosRows = [];
    foreach ($evs as $e) {
      $espacos[] = trim((string)($e['espaco'] ?? ''));
      $dt = new DateTime($e['data'] ?? 'now');
      $eventosRows[] = sprintf(
        '%s (%s %s %s)',
        trim((string)($e['evento'] ?? '')),
        dow_pt($dt),
        $dt->format('d/m'),
        trim((string)($e['horario'] ?? ''))
      );
    }
    $espacos = array_values(array_unique(array_filter($espacos)));
    $espacoConsolidado = count($espacos) > 1 ? 'Múltiplos' : ($espacos[0] ?? '');
    $eventosResumo = count($eventosRows).' evento(s): '.implode(' • ', $eventosRows);

    // cria 2 registros em lc_listas
    $now = (new DateTime())->format('Y-m-d H:i:s');
    $ins = $pdo->prepare("insert into lc_listas (grupo_id,tipo,data_gerada,espaco_consolidado,eventos_resumo,criado_por,criado_por_nome)
                          values (:g,:t,:d,:e,:r,:u,:un)");
    foreach (['compras','encomendas'] as $tipo) {
      $ins->execute([
        ':g'=>$grupoId, ':t'=>$tipo, ':d'=>$now,
        ':e'=>$espacoConsolidado, ':r'=>$eventosResumo,
        ':u'=>$usuarioId, ':un'=>$usuarioNome
      ]);
    }

    // salva eventos do grupo
    $insEv = $pdo->prepare("insert into lc_listas_eventos (grupo_id,espaco,convidados,horario,evento,data,dia_semana)
                            values (:g,:es,:cv,:hr,:ev,:dt,:dw)");
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
    }

    // carrega mapa de itens selecionados
    $ids = implode(',', array_map('intval', array_keys($selItemIds)));
    $itens = $pdo->query("select * from lc_itens where id in ($ids)")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);

    // auxiliares de acumulação
    $compras = [];   // key: nome_insumo|unidade -> quantidade
    $encom = [];     // key: fornecedor_id|evento_key|null -> (nome_item, unidade) => quantidade

    // funções auxiliares
    $getFichaComp = $pdo->prepare("select c.insumo_id, i.nome as nome_insumo, c.quantidade, c.unidade
                                   from lc_ficha_componentes c
                                   join lc_insumos i on i.id=c.insumo_id
                                   where c.ficha_id=:f");
    $fornMap = [];
    foreach ($forns as $f) { $fornMap[$f['id']] = $f; }

    // index eventos para encomendas separadas
    $evDb = $pdo->prepare("select id, espaco, to_char(data,'DD/MM') as d, horario from lc_listas_eventos where grupo_id=:g order by id");
    $evDb->execute([':g'=>$grupoId]);
    $eventosIdx = $evDb->fetchAll(PDO::FETCH_ASSOC);

    // processa cada item
    foreach ($itens as $itemId => $it) {
      $tipo = $it['tipo'];
      if ($tipo === 'preparo') {
        if (!$it['ficha_id']) continue;
        $getFichaComp->execute([':f'=>$it['ficha_id']]);
        foreach ($getFichaComp as $fc) {
          // soma por insumo
          $key = $fc['nome_insumo'].'|'.$fc['unidade'];
          $compras[$key] = ($compras[$key] ?? 0) + (float)$fc['quantidade'] * count($evs);
        }
      } elseif ($tipo === 'comprado') {
        $fid = (int)$it['fornecedor_id'];
        $modo = $ov[$fid] ?? ($fornMap[$fid]['modo_padrao'] ?? 'consolidado');
        if ($modo === 'separado') {
          foreach ($eventosIdx as $e) {
            $k = $fid.'|'.$e['id']; // por evento
            $encom[$k][$it['nome'].'|'.$it['unidade']] = ($encom[$k][$it['nome'].'|'.$it['unidade']] ?? 0) + 1;
          }
        } else {
          $k = $fid.'|0'; // consolidado
          $encom[$k][$it['nome'].'|'.$it['unidade']] = ($encom[$k][$it['nome'].'|'.$it['unidade']] ?? 0) + count($evs);
        }
      } else { // 'fixo'
        // entra 1x por evento em COMPRAS
        $key = $it['nome'].'|'.$it['unidade'];
        $compras[$key] = ($compras[$key] ?? 0) + 1 * count($evs);
      }
    }

    // persiste COMPRAS
    $insC = $pdo->prepare("insert into lc_compras_consolidadas (grupo_id, insumo_id, nome_insumo, unidade, quantidade)
                           values (:g, null, :n, :u, :q)");
    foreach ($compras as $k => $qtd) {
      [$nome,$uni] = explode('|',$k,2);
      $insC->execute([':g'=>$grupoId, ':n'=>$nome, ':u'=>$uni, ':q'=>$qtd]);
    }

    // persiste ENCOMENDAS
    $insE = $pdo->prepare("insert into lc_encomendas_itens (grupo_id, fornecedor_id, fornecedor_nome, evento_id, evento_label, item_id, nome_item, unidade, quantidade)
                           values (:g,:f,:fn,:ev,:el,:it,:n,:u,:q)");
    foreach ($encom as $k => $map) {
      [$fid,$evId] = array_map('intval', explode('|',$k,2));
      $fn = $fornMap[$fid]['nome'] ?? 'Fornecedor';
      $el = null;
      if ($evId>0) {
        foreach ($eventosIdx as $e) { if ((int)$e['id']===$evId) { $el = $e['espaco'].' • '.$e['d'].' '.$e['horario']; break; } }
      }
      foreach ($map as $nk => $qtd) {
        [$nome,$uni] = explode('|',$nk,2);
        $insE->execute([
          ':g'=>$grupoId, ':f'=>$fid, ':fn'=>$fn,
          ':ev'=>$evId>0?$evId:null, ':el'=>$el,
          ':it'=>null, ':n'=>$nome, ':u'=>$uni, ':q'=>$qtd
        ]);
      }
    }

    // salva overrides escolhidos
    if ($ov) {
      $insOv = $pdo->prepare("insert into lc_encomendas_overrides (grupo_id, fornecedor_id, modo) values (:g,:f,:m)");
      foreach ($ov as $fid=>$m) {
        if ($m==='consolidado' || $m==='separado') $insOv->execute([':g'=>$grupoId, ':f'=>(int)$fid, ':m'=>$m]);
      }
    }

    header('Location: lista_compras.php?msg='.urlencode('Listas geradas com sucesso!'));
    exit;

  } catch(Throwable $e){
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
.switch{margin-left:8px;font-size:12px}
.btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}
.btn.gray{background:#e9efff;color:#004aad}
.alert{padding:10px;border-radius:8px;margin-bottom:10px}
.alert.err{background:#ffeded;border:1px solid #ffb3b3;color:#8a0c0c}
</style>
<script>
function toggleCat(id){ const el=document.getElementById('items-'+id); if(el){ el.classList.toggle('active'); } }
</script>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
  <h1>Gerar Lista de Compras</h1>

  <div class="form">
    <?php if($err): ?><div class="alert err"><?php echo h($err); ?></div><?php endif; ?>

    <form method="post">
      <div class="block">
        <h2>Eventos</h2>
        <div id="ev-wrap">
          <div class="grid ev-row">
            <div>
              <label class="small">Espaço</label>
              <input class="input" name="eventos[0][espaco]" required>
            </div>
            <div>
              <label class="small">Convidados</label>
              <input class="input" type="number" name="eventos[0][convidados]" min="0" value="0">
            </div>
            <div>
              <label class="small">Horário</label>
              <input class="input" name="eventos[0][horario]" placeholder="19:00">
            </div>
            <div>
              <label class="small">Evento</label>
              <input class="input" name="eventos[0][evento]" placeholder="Aniversário, Casamento...">
            </div>
            <div class="full">
              <label class="small">Data</label>
              <input class="input" type="date" name="eventos[0][data]" required>
            </div>
          </div>
        </div>
        <!-- poderia adicionar botão “+ evento” com JS no futuro -->
      </div>

      <div class="block">
        <h2>Categorias e Itens</h2>
        <?php foreach ($cats as $c): ?>
          <div class="category">
            <label>
              <input type="checkbox" onclick="toggleCat(<?php echo (int)$c['id']; ?>)"> <?php echo h($c['nome']); ?>
            </label>
            <div class="items" id="items-<?php echo (int)$c['id']; ?>">
              <?php foreach (($itensByCat[$c['id']] ?? []) as $it): ?>
                <label style="display:block;margin:6px 0">
                  <input type="checkbox" name="itens[<?php echo (int)$c['id']; ?>][]" value="<?php echo (int)$it['id']; ?>">
                  <?php echo h($it['nome']); ?> <small>(<?php echo h($it['tipo']); ?><?php
                    if ($it['tipo']==='preparo' && $it['ficha_id']) echo ', ficha #'.$it['ficha_id'];
                    if ($it['tipo']==='comprado' && $it['fornecedor_id']) echo ', forn #'.$it['fornecedor_id'];
                  ?>)</small>
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
            <?php echo h($f['nome']); ?>:
            <select name="fornecedor_modo[<?php echo (int)$f['id']; ?>]" class="input" style="max-width:220px; display:inline-block">
              <option value="">Padrão: <?php echo h($f['modo_padrao']); ?></option>
              <option value="consolidado">Consolidado</option>
              <option value="separado">Separado por evento</option>
            </select>
          </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div style="margin-top:12px; display:flex; gap:10px">
        <button class="btn" type="submit">Gerar</button>
        <a class="btn gray" href="lista_compras.php">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
