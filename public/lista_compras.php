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

?>


<?php
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
    <style>
  /* ====== Estilos para Lista de Compras ====== */
  
  /* Garante cálculo correto de largura/padding */
  *, *::before, *::after { box-sizing: border-box; }

  /* Container principal */
  .lc-main-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
  }

  /* Seção de cabeçalho */
  .lc-header-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 0;
    background: transparent;
    border: none;
  }

  .lc-header-section h2 {
    margin: 0;
    color: var(--primary-blue);
    font-size: 20px;
    font-weight: 700;
  }

  /* Campos do evento */
  .lc-event-fields {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
    padding: 20px;
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
  }

  .field-group {
    display: flex;
    flex-direction: column;
  }

  .field-group label {
    font-weight: 600;
    color: #333;
    margin-bottom: 8px;
    font-size: 14px;
  }

  .field-group input {
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    background: #f8f9fa;
  }

  /* Seções */
  .lc-section {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e9ecef;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    position: relative;
  }

  .lc-section h2 {
    margin: 0 0 20px 0;
    color: var(--primary-blue);
    font-size: 20px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .lc-section h2::before {
    content: '';
    width: 4px;
    height: 24px;
    background: var(--primary-blue);
    border-radius: 2px;
  }

  /* Estado vazio */
  .lc-empty-state {
    text-align: center;
    color: #666;
    font-style: italic;
    padding: 20px;
  }

  /* Tabela de rascunhos */
  .lc-table-container {
    overflow-x: auto;
  }

  .lc-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 16px;
  }

  .lc-table th,
  .lc-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
  }

  .lc-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
  }

  .lc-actions {
    white-space: nowrap;
  }

  .lc-actions .btn {
    margin-right: 8px;
  }

  /* Grid de categorias */
  .lc-categories-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 16px;
  }

  .lc-category-card {
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 16px;
    background: #f8f9fa;
  }

  .lc-category-header {
    margin-bottom: 12px;
  }

  .lc-category-checkbox {
    display: flex;
    align-items: center;
    cursor: pointer;
    font-weight: 600;
    color: #333;
  }

  .lc-category-checkbox input[type="checkbox"] {
    margin-right: 8px;
    transform: scale(1.2);
  }

  .lc-category-name {
    font-size: 16px;
  }

  .lc-items-container {
    display: none;
    margin-top: 12px;
  }

  .lc-items-container.active {
    display: block;
  }

  .lc-item {
    margin-bottom: 8px;
  }

  .lc-item-checkbox {
    display: flex;
    align-items: flex-start;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: background-color 0.2s;
  }

  .lc-item-checkbox:hover {
    background: #e9ecef;
  }

  .lc-item-checkbox input[type="checkbox"] {
    margin-right: 8px;
    margin-top: 2px;
  }

  .lc-item-name {
    font-weight: 500;
    color: #333;
    margin-right: 8px;
  }

  .lc-item-meta {
    color: #666;
    font-size: 12px;
  }

  .lc-empty-items {
    text-align: center;
    color: #666;
    font-style: italic;
    padding: 16px;
  }

  /* Fornecedores */
  .lc-suppliers-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 16px;
    margin-top: 16px;
  }

  .lc-supplier-item {
    padding: 16px;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    background: #f8f9fa;
  }

  .lc-supplier-label {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .lc-supplier-name {
    font-weight: 600;
    color: #333;
    min-width: 120px;
  }

  .lc-supplier-select {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    background: #fff;
  }

  /* Botões de ação */
  .lc-actions-container {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 32px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 12px;
    border: 1px solid #e9ecef;
  }

  /* Variantes de botões */
  .btn--primary {
    background: var(--primary-blue);
    color: white;
  }

  .btn--secondary {
    background: #6c757d;
    color: white;
  }

  .btn--danger {
    background: #dc3545;
    color: white;
  }

  .btn--secondary:hover {
    background: #5a6268;
  }

  .btn--danger:hover {
    background: #c82333;
  }

  /* Modal da ME */
  #modalME {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }

  #modalME .modal-content {
    max-width: 980px;
    width: 100%;
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    max-height: 80vh;
    overflow-y: auto;
  }

  .modal-filters {
    display: flex;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
    margin-bottom: 16px;
  }

  .modal-filters > div {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .modal-filters label {
    font-weight: 600;
    color: #333;
    font-size: 14px;
  }

  .modal-filters input {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
  }

  .modal-filters .search-field {
    flex: 1;
    min-width: 240px;
  }

  #me_results {
    margin-top: 16px;
    max-height: 400px;
    overflow: auto;
    border: 1px solid #eee;
    border-radius: 8px;
    background: #fff;
  }

  /* Responsivo */
  @media (max-width: 768px) {
    .lc-main-container {
      padding: 16px;
    }

    .lc-header-section {
      flex-direction: column;
      gap: 16px;
      text-align: center;
    }

    .lc-event-fields {
      grid-template-columns: 1fr;
    }

    .lc-categories-grid {
      grid-template-columns: 1fr;
    }

    .lc-suppliers-container {
      grid-template-columns: 1fr;
    }

    .lc-actions-container {
      flex-direction: column;
    }

    .modal-filters {
      flex-direction: column;
    }

    .modal-filters .search-field {
      min-width: auto;
    }
  }
</style>
</script>
</head>
<body class="panel">
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
  <h1>Gerar Lista de Compras</h1>

  <div class="lc-main-container">
    <?php if (!empty($err)): ?><div class="alert err"><?=h($err)?></div><?php endif; ?>
    <?php if (isset($_GET['ok'])): ?>
      <div class="alert success">
        <?php if ($_GET['ok']==='rascunho_salvo')  echo 'Rascunho salvo.'; ?>
        <?php if ($_GET['ok']==='rascunho_atualizado') echo 'Rascunho atualizado.'; ?>
        <?php if ($_GET['ok']==='rascunho_excluido')  echo 'Rascunho excluído.'; ?>
      </div>
    <?php endif; ?>

    <!-- RASCUNHOS (se houver) -->
    <?php if ($RAS): ?>
    <div class="lc-section">
      <h2>📝 Rascunhos Salvos</h2>
      <div class="lc-table-container">
        <table class="lc-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Atualizado</th>
              <th>Eventos</th>
              <th>Itens</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($RAS as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['quando']) ?></td>
              <td><?= (int)$r['qtd_ev'] ?></td>
              <td><?= (int)$r['qtd_it'] ?></td>
              <td class="lc-actions">
                <a class="btn btn--secondary" href="lista_compras.php?acao=editar_rascunho&id=<?= (int)$r['id'] ?>">Editar</a>
                <a class="btn btn--danger" href="lista_compras.php?acao=excluir_rascunho&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir este rascunho?')">Excluir</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php
    // Pré-carrega eventos/itens do rascunho se houver
    $evDraft = $RAS_PAYLOAD['payload']['eventos'] ?? [];
    if (!$evDraft) {
      $evDraft = [[ 'espaco'=>'', 'convidados'=>0, 'horario'=>'', 'evento'=>'', 'data'=>'' ]];
    }
    $draftIds = $RAS_PAYLOAD['payload']['itens_ids'] ?? [];
    $draftSet = array_flip(array_map('intval',$draftIds));
    ?>

    <!-- FORMULÁRIO PRINCIPAL -->
    <form id="formLC" method="post">
      <input type="hidden" id="evento_id_me" name="evento_id_me" required>
      <?php if ($RAS_PAYLOAD): ?>
        <input type="hidden" name="rascunho_id" value="<?= (int)$RAS_PAYLOAD['id'] ?>">
      <?php endif; ?>
      <input type="hidden" name="acao" id="acao" value="">

      <!-- 1. DADOS DO EVENTO -->
      <div class="lc-section">
        <div class="lc-header-section">
          <h2>1. Dados do Evento</h2>
          <button type="button" id="btnBuscarME" class="btn">Buscar na ME</button>
        </div>
        <div class="lc-event-fields">
          <div class="field-group">
            <label>Espaço</label>
            <input id="evento_espaco" name="evento_espaco" class="input" required readonly>
          </div>
          <div class="field-group">
            <label>Convidados</label>
            <input id="evento_convidados" name="evento_convidados" type="number" min="0" class="input" required readonly>
          </div>
          <div class="field-group">
            <label>Horário</label>
            <input id="evento_hora" name="evento_hora" type="time" class="input" required readonly>
          </div>
          <div class="field-group">
            <label>Evento</label>
            <input id="evento_nome" name="evento_nome" class="input" required readonly>
          </div>
          <div class="field-group">
            <label>Data</label>
            <input id="evento_data" name="evento_data" type="date" class="input" required readonly>
          </div>
        </div>
      </div>

      <!-- 2. CATEGORIAS E ITENS -->
      <div class="lc-section">
        <h2>2. Categorias e Itens</h2>
        <div class="lc-categories-grid">
          <?php foreach ($cats as $c): ?>
            <div class="lc-category-card">
              <div class="lc-category-header">
                <label class="lc-category-checkbox">
                  <input type="checkbox" onclick="toggleCat(<?= (int)$c['id'] ?>)">
                  <span class="lc-category-name"><?= h($c['nome']) ?></span>
                </label>
              </div>
              <div class="lc-items-container" id="items-<?= (int)$c['id'] ?>">
                <?php foreach (($itensByCat[$c['id']] ?? []) as $it): ?>
                  <?php $checked = isset($draftSet[(int)$it['id']]) ? 'checked' : ''; ?>
                  <div class="lc-item">
                    <label class="lc-item-checkbox">
                      <input type="checkbox" <?=$checked?> name="itens[<?= (int)$c['id'] ?>][]" value="<?= (int)$it['id'] ?>">
                      <span class="lc-item-name"><?= h($it['nome']) ?></span>
                      <small class="lc-item-meta">(
                        <?= h($it['tipo']) ?>
                        <?php
                          if ($it['tipo']==='preparo'  && $it['ficha_id'])      echo ', ficha #'.(int)$it['ficha_id'];
                          if ($it['tipo']==='comprado' && $it['fornecedor_id']) echo ', forn #'.(int)$it['fornecedor_id'];
                        ?>
                      )</small>
                    </label>
                  </div>
                <?php endforeach; ?>
                <?php if (empty($itensByCat[$c['id']])): ?>
                  <div class="lc-empty-items"><em>Nenhum item nesta categoria.</em></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- 3. FORNECEDORES -->
      <?php if ($forns): ?>
      <div class="lc-section">
        <h2>3. Encomendas — Modo por Fornecedor</h2>
        <div class="lc-suppliers-container">
          <?php foreach ($forns as $f): ?>
            <div class="lc-supplier-item">
              <label class="lc-supplier-label">
                <span class="lc-supplier-name"><?= h($f['nome']) ?>:</span>
                <select name="fornecedor_modo[<?= (int)$f['id'] ?>]" class="lc-supplier-select">
                  <option value="">Padrão: <?= h($f['modo_padrao']) ?></option>
                  <option value="consolidado">Consolidado</option>
                  <option value="separado">Separado por evento</option>
                </select>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- 4. AÇÕES -->
      <div class="lc-actions-container">
        <button class="btn btn--secondary" type="button" onclick="salvarRascunho()">Salvar rascunho</button>
        <button class="btn btn--primary" type="submit" onclick="document.getElementById('acao').value=''">Gerar (finalizar)</button>
        <a class="btn btn--secondary" href="dashboard.php">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<!-- === BLOCO: Modal de Busca na ME === -->
<div id="modalME">
  <div class="modal-content">
    <div class="modal-filters">
      <div>
        <label>Início</label>
        <input type="date" id="me_start" value="<?php echo date('Y-m-d'); ?>">
      </div>
      <div>
        <label>Fim</label>
        <input type="date" id="me_end" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
      </div>
      <div class="search-field">
        <label>Buscar</label>
        <input type="text" id="me_q" placeholder="cliente, observação, evento…">
      </div>
      <div>
        <label>&nbsp;</label>
        <div style="display: flex; gap: 8px;">
          <button type="button" id="me_exec" class="btn">Buscar</button>
          <button type="button" id="me_close" class="btn btn--secondary">Fechar</button>
        </div>
      </div>
    </div>
    <div id="me_results">
      <div style="padding:12px;color:#666">Use os filtros e clique em Buscar.</div>
    </div>
  </div>
</div>

<script>
(function(){
  const $ = s => document.querySelector(s);
  const modal = $('#modalME'), btnOpen = $('#btnBuscarME'), btnClose = $('#me_close'), btnExec = $('#me_exec'), box = $('#me_results');

  console.log('Elementos encontrados:', {
    modal: !!modal,
    btnOpen: !!btnOpen,
    btnClose: !!btnClose,
    btnExec: !!btnExec,
    box: !!box
  });

  // Teste de conectividade com me_proxy.php
  fetch('./me_proxy.php')
    .then(r => r.json())
    .then(data => console.log('Teste de conectividade me_proxy.php:', data))
    .catch(e => console.error('Erro ao testar me_proxy.php:', e));

  const openModal  = () => { 
    console.log('Abrindo modal ME');
    modal.style.display = 'flex'; 
  };
  const closeModal = () => { 
    console.log('Fechando modal ME');
    modal.style.display = 'none'; 
  };

  if (btnOpen) {
    btnOpen.addEventListener('click', openModal);
    console.log('Evento de abertura do modal registrado');
    
    // Teste adicional - clique direto
    btnOpen.addEventListener('click', function(e) {
      console.log('Clique detectado no botão Buscar na ME');
    });
  } else {
    console.error('Botão de abrir modal não encontrado!');
  }
  
  if (btnClose) {
    btnClose.addEventListener('click', closeModal);
    console.log('Evento de fechamento do modal registrado');
  } else {
    console.error('Botão de fechar modal não encontrado!');
  }

  // MAPEAMENTO conforme documentação da ME Eventos
  function mapEventoToForm(raw){
    const pad = s => (s||'').toString();
    return {
      espaco:     pad(raw.tipoEvento),                 // Espaço (tipoEvento)
      convidados: parseInt(raw.convidados||'0',10)||'',// Convidados
      hora:       pad(raw.horaevento || '').slice(0,5), // Horário (HH:MM)
      nome:       pad(raw.nomeevento||''),             // Evento (nomeevento)
      data:       pad(raw.dataevento||''),             // Data (YYYY-MM-DD)
      id:         pad(raw.id||'')
    };
  }

  // Buscar no proxy
  if (btnExec) {
    btnExec.addEventListener('click', async ()=>{
      console.log('Botão Buscar clicado');
    const start = $('#me_start').value || '';
    const end   = $('#me_end').value   || '';
    const q     = $('#me_q').value     || '';

    console.log('Parâmetros:', { start, end, q });

    const params = new URLSearchParams();
    if (start) params.set('start', start);
    if (end)   params.set('end', end);
    if (q)     params.set('q', q);  // Corrigido: era 'search', deve ser 'q'

    const url = './me_proxy.php?' + params.toString();  // Mudado para caminho relativo
    console.log('URL da requisição:', url);

    box.innerHTML = '<div style="padding:12px">Buscando…</div>';
    try {
      console.log('Fazendo requisição para:', url);
      const r = await fetch(url, { 
        headers: { 'Accept': 'application/json' },
        method: 'GET'
      });
      
      console.log('Status da resposta:', r.status);
      
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const j = await r.json();
      
      console.log('Resposta da API:', j);

      // Verifica se houve erro na API
      if (j.ok === false) {
        throw new Error(j.error || 'Erro na API da ME Eventos');
      }

      const lista = Array.isArray(j?.data) ? j.data : (Array.isArray(j) ? j : []);
      console.log('Lista de eventos:', lista);
      
      if (!lista.length) { 
        box.innerHTML = '<div style="padding:12px">Nenhum evento encontrado.</div>'; 
        return; 
      }

      const header = `
        <div style="display:grid;grid-template-columns:1fr 140px 90px 120px 110px 96px;gap:8px;padding:10px 12px;background:#f6f9ff;border-bottom:1px solid #eaeaea;font-weight:600;">
          <div>Cliente</div><div>Evento</div><div>Convid.</div><div>Data</div><div>Hora</div><div>Ação</div>
        </div>`;

      const rows = lista.map(raw => {
        const ev = mapEventoToForm(raw);
        const cliente = raw.nomeCliente ?? '';
        const evento = raw.nomeevento ?? '';
        const dataBR  = ev.data ? ev.data.split('-').reverse().join('/') : '';
        const payload = encodeURIComponent(JSON.stringify({ev}));
        return `
          <div style="display:grid;grid-template-columns:1fr 140px 90px 120px 110px 96px;gap:8px;padding:10px 12px;border-bottom:1px solid #f0f0f0;">
            <div><strong>${cliente || '(sem cliente)'}</strong></div>
            <div>${evento || ''}</div>
            <div>${ev.convidados || ''}</div>
            <div>${dataBR || ''}</div>
            <div>${ev.hora || ''}</div>
            <div><button type="button" class="btn me-usar" data-payload="${payload}">Usar</button></div>
          </div>`;
      }).join('');

      box.innerHTML = header + rows;

      // Seleciona evento -> preenche e trava
      box.querySelectorAll('.me-usar').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const {ev} = JSON.parse(decodeURIComponent(btn.getAttribute('data-payload')));
          const set = (id,v)=>{ const el=document.getElementById(id); if(el){ el.value=v??''; el.readOnly=true; } };
          set('evento_espaco', ev.espaco);
          set('evento_convidados', ev.convidados);
          set('evento_hora', ev.hora);
          set('evento_nome', ev.nome);
          set('evento_data', ev.data);
          const hid = document.getElementById('evento_id_me'); if (hid) hid.value = ev.id || '';
          closeModal();
          document.getElementById('evento_espaco')?.scrollIntoView({behavior:'smooth', block:'center'});
        });
      });

    } catch (e) {
      box.innerHTML = `<div style="padding:12px;color:#b00">Falha ao buscar na ME (${e.message}).</div>`;
    }
    });
    console.log('Evento de busca registrado');
  } else {
    console.error('Botão de executar busca não encontrado!');
  }

  // Impede submit sem evento da ME
  (function(){
    const form = document.querySelector('form');
    if (!form) return;
    form.addEventListener('submit', (ev)=>{
      const hid = document.getElementById('evento_id_me');
      if (!hid || !hid.value) {
        ev.preventDefault();
        alert('Selecione um evento pela ME antes de gerar a lista.');
      }
    });
  })();
})();
</script>
</body>
</html>
