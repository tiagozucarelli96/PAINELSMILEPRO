<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método inválido'); }

$eventos = $_POST['eventos'] ?? [];         // [ ['id'=>1,'convidados'=>120,'data'=>'2025-08-30 19:00'], ... ]
$itensSel = $_POST['itens'] ?? [];          // [id_item, id_item, ...]
$overrides = $_POST['overrides'] ?? [];     // ['fornecedor_12'=>'separado', 'fornecedor_7'=>'consolidado']

if (!$eventos || !$itensSel) { exit('Selecione ao menos 1 evento e 1 item.'); }

/** Helpers **/
function fetchRow(PDO $pdo, string $sql, array $p=[]){ $st=$pdo->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC); }
function fetchAll(PDO $pdo, string $sql, array $p=[]){ $st=$pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }

$pdo->beginTransaction();
try {
  // 1) grupo_id
  $grupoId = (int)fetchRow($pdo, "SELECT COALESCE(MAX(grupo_id),0)+1 AS gid FROM lc_listas")['gid'];

  // 2) carregar itens selecionados
  $in = implode(',', array_map('intval',$itensSel));
  $itens = fetchAll($pdo, "
    SELECT i.id, i.nome, i.tipo, i.ficha_id, i.insumo_id
    FROM lc_itens i
    WHERE i.id IN ($in) AND i.ativo = TRUE
  ");

  // 3) função recursiva para explodir ficha em insumos
  $memoFicha = [];
  $explodeFicha = function(int $fichaId) use (&$explodeFicha, &$memoFicha, $pdo){
    if (isset($memoFicha[$fichaId])) return $memoFicha[$fichaId];
    $comp = fetchAll($pdo, "
      SELECT fc.quantidade, COALESCE(fc.unidade,'') AS und, fc.insumo_id, fc.sub_ficha_id
      FROM lc_ficha_componentes fc WHERE fc.ficha_id = :f", [':f'=>$fichaId]
    );
    $lista = [];
    foreach ($comp as $c) {
      if ($c['insumo_id']) {
        $lista[] = ['insumo_id'=>(int)$c['insumo_id'], 'quantidade'=>(float)$c['quantidade'], 'unidade'=>$c['und']];
      } elseif ($c['sub_ficha_id']) {
        $sub = $explodeFicha((int)$c['sub_ficha_id']);
        // quantidade multiplica a sub-ficha inteira
        foreach ($sub as $s) {
          $lista[] = ['insumo_id'=>$s['insumo_id'], 'quantidade'=>$s['quantidade']*(float)$c['quantidade'], 'unidade'=>$s['unidade']];
        }
      }
    }
    return $memoFicha[$fichaId] = $lista;
  };

  // 4) acumuladores
  $compras = [];       // key: insumo_id → ['qtd'=>x, 'und'=>'kg']
  $encomendas = [];    // key: fornecedor_id → (consolidado|separado) → eventoId/0 → [insumo_id => ['qtd'=>x,'und'=>...]]
  $fixos = [];         // idem compras (serão 1x por evento)

  foreach ($eventos as $ev) {
    $eventoId = (int)($ev['id'] ?? 0);
    $convidados = max(1, (int)($ev['convidados'] ?? 0));

    foreach ($itens as $it) {
      if ($it['tipo'] === 'preparo') {
        $fx = fetchRow($pdo, "SELECT id,rendimento_base_pessoas FROM lc_fichas WHERE id=:id", [':id'=>$it['ficha_id']]);
        if (!$fx) continue;
        $fator = max(0.0001, $convidados / max(1,(int)$fx['rendimento_base_pessoas']));
        $lista = $explodeFicha((int)$fx['id']);
        foreach ($lista as $l) {
          // classificar por aquisição do insumo
          $ins = fetchRow($pdo, "SELECT aquisicao, fornecedor_id FROM lc_insumos WHERE id=:id", [':id'=>$l['insumo_id']]);
          $qtd = $l['quantidade'] * $fator;
          if (($ins['aquisicao'] ?? 'mercado') === 'mercado') {
            $k = (int)$l['insumo_id'];
            if (!isset($compras[$k])) $compras[$k] = ['qtd'=>0,'und'=>$l['unidade']];
            $compras[$k]['qtd'] += $qtd;
          } else {
            $fid = (int)($ins['fornecedor_id'] ?? 0);
            $modo = $overrides["fornecedor_$fid"] ?? null;
            // Buscar padrão se não houver override
            if (!$modo) {
              $pad = fetchRow($pdo, "SELECT modo_padrao FROM fornecedores WHERE id=:i", [':i'=>$fid]);
              $modo = $pad['modo_padrao'] ?? 'consolidado';
            }
            $bucket = ($modo === 'separado') ? $eventoId : 0;
            $encomendas[$fid][$bucket][(int)$l['insumo_id']]['und'] = $l['unidade'];
            $encomendas[$fid][$bucket][(int)$l['insumo_id']]['qtd'] = ($encomendas[$fid][$bucket][(int)$l['insumo_id']]['qtd'] ?? 0) + $qtd;
          }
        }

      } elseif ($it['tipo'] === 'comprado') {
        // vai direto pra encomenda
        $ins = fetchRow($pdo, "SELECT fornecedor_id FROM lc_insumos WHERE id=:id", [':id'=>$it['insumo_id']]);
        $fid = (int)($ins['fornecedor_id'] ?? 0);
        $modo = $overrides["fornecedor_$fid"] ?? (fetchRow($pdo,"SELECT modo_padrao FROM fornecedores WHERE id=:i",[":i"=>$fid])['modo_padrao'] ?? 'consolidado');
        $bucket = ($modo === 'separado') ? $eventoId : 0;
        $encomendas[$fid][$bucket][(int)$it['insumo_id']]['und'] = 'un';
        $encomendas[$fid][$bucket][(int)$it['insumo_id']]['qtd'] = ($encomendas[$fid][$bucket][(int)$it['insumo_id']]['qtd'] ?? 0) + 1;

      } elseif ($it['tipo'] === 'fixo') {
        // 1x por evento
        $fixos[$eventoId][] = $it['id'];
      }
    }
  }

  // 5) persistir cabeçalho da lista (um registro por grupo)
  $pdo->prepare("INSERT INTO lc_listas (grupo_id, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome)
                 VALUES (:g, NOW(), :esp, :evs, :uid, :nome)")
      ->execute([
        ':g'=>$grupoId,
        ':esp'=> '—', // preencher se quiser consolidar nomes dos espaços
        ':evs'=> '—', // resumo textual dos eventos
        ':uid'=> (int)($_SESSION['user_id'] ?? 0),
        ':nome'=> (string)($_SESSION['user_nome'] ?? '')
      ]);

  // 6) persistir COMPRAS (insumos de mercado + fixos)
  $insumoInfo = $pdo->prepare("SELECT nome, COALESCE(unidade,'') AS und FROM lc_insumos WHERE id=:i");
  $insumoInfo->execute([':i'=>0]); // no-op pra preparar

  foreach ($compras as $insumoId => $info) {
    $insumoInfo->execute([':i'=>$insumoId]); $ii = $insumoInfo->fetch(PDO::FETCH_ASSOC) ?: ['und'=>$info['und']];
    $pdo->prepare("INSERT INTO lc_compras_consolidadas (grupo_id, insumo_id, quantidade, unidade)
                   VALUES (:g,:i,:q,:u)")
        ->execute([':g'=>$grupoId, ':i'=>$insumoId, ':q'=>$info['qtd'], ':u'=>$ii['und'] ?? $info['und']]);
  }
  // fixos (se quiser gravar também como compras utilitárias)
  foreach ($fixos as $eventoId => $ids) {
    foreach ($ids as $itemId) {
      $pdo->prepare("INSERT INTO lc_compras_consolidadas (grupo_id, insumo_id, quantidade, unidade)
                     VALUES (:g,:i,1,'un')")
          ->execute([':g'=>$grupoId, ':i'=>$itemId]);
    }
  }

  // 7) persistir ENCOMENDAS
  foreach ($encomendas as $fornecedorId => $buckets) {
    foreach ($buckets as $bucket => $map) {
      foreach ($map as $insumoId => $val) {
        $pdo->prepare("INSERT INTO lc_encomendas_itens (grupo_id, fornecedor_id, evento_id, insumo_id, quantidade, unidade)
                       VALUES (:g,:f,:e,:i,:q,:u)")
            ->execute([
              ':g'=>$grupoId, ':f'=>$fornecedorId, ':e'=>($bucket ?: null),
              ':i'=>$insumoId, ':q'=>$val['qtd'], ':u'=>$val['und'] ?? 'un'
            ]);
      }
    }
  }

  // 8) persistir overrides (se veio algo)
  foreach ($overrides as $k=>$modo) {
    if (strpos($k,'fornecedor_')===0) {
      $fid = (int)substr($k, 11);
      $pdo->prepare("INSERT INTO lc_encomendas_overrides (grupo_id, fornecedor_id, modo)
                     VALUES (:g,:f,:m)")
          ->execute([':g'=>$grupoId, ':f'=>$fid, ':m'=>$modo]);
    }
  }

  $pdo->commit();
  header('Location: ver.php?g='.$grupoId.'&tab=compras');
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  echo 'Erro ao gerar lista: '.$e->getMessage();
}
