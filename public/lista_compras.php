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
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

// ========= Incluir lc_calc.php =========
require_once __DIR__ . '/lc_calc.php';

// ========= Util =========
// dow_pt() já está definida em core/helpers.php - não redeclarar
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
        header('Location: index.php?page=lista_compras&ok=rascunho_atualizado'); exit;
    } else {
        $st = $pdo->prepare("INSERT INTO lc_rascunhos (criado_por, criado_por_nome, payload) VALUES (:u,:n,:p)");
        $st->execute([':u'=>$uid, ':n'=>$usuarioNome, ':p'=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        header('Location: index.php?page=lista_compras&ok=rascunho_salvo'); exit;
    }
}
if (($_GET['acao'] ?? '') === 'excluir_rascunho' && !empty($_GET['id'])) {
    $st = $pdo->prepare("DELETE FROM lc_rascunhos WHERE id=:i AND criado_por=:u");
    $st->execute([':i'=>(int)$_GET['id'], ':u'=>$uid]);
    header('Location: index.php?page=lista_compras&ok=rascunho_excluido'); exit;
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

// ========= POST (Gerar Lista Final do Rascunho) =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'gerar_lista_final') {
    try {
        $eventosRascunho = json_decode($_POST['eventos_rascunho'] ?? '[]', true);
        
        if (empty($eventosRascunho)) {
            throw new Exception('Nenhum evento no rascunho para gerar lista.');
        }
        
        // Consolidar itens de todos os eventos
        $itensConsolidados = [];
        $eventosIds = [];
        
        foreach ($eventosRascunho as $evento) {
            // Garantir que o ID seja um inteiro válido
            $eventoIdRaw = $evento['id'] ?? '';
            $eventoId = (int)$eventoIdRaw;
            
            // Verificar se o valor original era "on" ou inválido
            if ($eventoIdRaw === 'on' || $eventoIdRaw === '' || $eventoId <= 0) {
                throw new Exception('ID de evento inválido: "' . $eventoIdRaw . '". Selecione um evento válido da ME.');
            }
            
            $eventosIds[] = $eventoId;
            
            foreach ($evento['itens'] as $item) {
                $key = $item['tipo'] . '_' . $item['id'];
                
                if (!isset($itensConsolidados[$key])) {
                    $itensConsolidados[$key] = [
                        'tipo' => $item['tipo'],
                        'id' => $item['id'],
                        'nome' => $item['nome'],
                        'quantidade_total' => 0,
                        'eventos' => []
                    ];
                }
                
                $itensConsolidados[$key]['quantidade_total'] += $item['quantidade'];
                $itensConsolidados[$key]['eventos'][] = [
                    'evento_id' => $eventoId,
                    'evento_nome' => $evento['nome'],
                    'quantidade' => $item['quantidade']
                ];
            }
        }
        
        // Criar lista principal
        $stmt = $pdo->prepare("
            INSERT INTO lc_listas (grupo_id, tipo, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, tipo_lista, criado_em, resumo_eventos, espaco_resumo)
            VALUES (1, 'compras', NOW(), 'Múltiplos Eventos', :eventos_resumo, :criado_por, :criado_por_nome, 'compras', NOW(), :resumo_eventos, 'Múltiplos Eventos')
            RETURNING id
        ");
        
        $eventosResumo = implode(', ', array_column($eventosRascunho, 'nome'));
        $resumoEventos = json_encode($eventosRascunho, JSON_UNESCAPED_UNICODE);
        
        $stmt->execute([
            ':eventos_resumo' => $eventosResumo,
            ':criado_por' => $uid,
            ':criado_por_nome' => $usuarioNome,
            ':resumo_eventos' => $resumoEventos
        ]);
        
        $listaId = $stmt->fetchColumn();
        
        // Criar eventos da lista
        foreach ($eventosRascunho as $evento) {
            $stmt = $pdo->prepare("
                INSERT INTO lc_listas_eventos (lista_id, grupo_id, espaco, convidados, horario, evento, data, dia_semana)
                VALUES (:lista_id, 1, 'Múltiplos', :convidados, '', :evento, :data, '')
            ");
            $stmt->execute([
                ':lista_id' => $listaId,
                ':convidados' => $evento['convidados'],
                ':evento' => $evento['nome'],
                ':data' => $evento['data']
            ]);
        }
        
        // Criar itens consolidados
        foreach ($itensConsolidados as $item) {
            if ($item['tipo'] === 'insumo') {
                // Buscar dados do insumo
                $stmt = $pdo->prepare("
                    SELECT i.*, u.simbolo, c.nome as categoria_nome
                    FROM lc_insumos i
                    LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
                    LEFT JOIN lc_categorias c ON c.id = i.categoria_id
                    WHERE i.id = ?
                ");
                $stmt->execute([$item['id']]);
                $insumo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($insumo) {
                    $stmt = $pdo->prepare("
                        INSERT INTO lc_compras_consolidadas (lista_id, grupo_id, insumo_id, nome_insumo, unidade, quantidade, custo_unitario, categoria)
                        VALUES (:lista_id, 1, :insumo_id, :nome_insumo, :unidade, :quantidade, :custo_unitario, :categoria)
                    ");
                    $stmt->execute([
                        ':lista_id' => $listaId,
                        ':insumo_id' => $insumo['id'],
                        ':nome_insumo' => $insumo['nome'],
                        ':unidade' => $insumo['unidade_padrao'] ?: $insumo['unidade'],
                        ':quantidade' => $item['quantidade_total'],
                        ':custo_unitario' => $insumo['custo_unit'] ?: 0,
                        ':categoria' => $insumo['categoria_nome'] ?: 'Sem Categoria'
                    ]);
                }
            }
        }
        
        // Limpar rascunho da sessão
        unset($_SESSION['rascunho_lista']);
        
        header('Location: index.php?page=lc_index&sucesso=' . urlencode('Lista de compras gerada com sucesso! ID: ' . $listaId));
        exit;
        
    } catch (Exception $e) {
        $err = 'Erro ao gerar lista: ' . $e->getMessage();
    }
}

// ========= POST (Finalizar = gerar listas) =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['acao'] ?? '') !== 'salvar_rascunho' && ($_POST['acao'] ?? '') !== 'gerar_lista_final')) {
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
        header('Location: index.php?page=lc_index&msg='.urlencode('Listas geradas com sucesso!')); exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e->getMessage();
    }
}
// Iniciar output buffering
ob_start();
?>
<style>
.form{background:#fff;border:1px solid #dfe7f4;border-radius:12px;padding:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid .full{grid-column:1/-1}
.block{border:1px dashed #dbe6ff;border-radius:10px;padding:12px;margin-top:12px}
h2{margin:10px 0}
label.small{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
.input{
  width: 100%;
  padding: 12px 16px;
  border: 2px solid #e1e5e9;
  border-radius: 12px;
  font-size: 14px;
  transition: all 0.3s ease;
  background: #fff;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.input:focus{
  outline: none;
  border-color: #1e3a8a;
  box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
  transform: translateY(-1px);
}
.input:read-only{
  background: #f8f9fa;
  color: #6c757d;
  cursor: not-allowed;
}
.category{margin-bottom:10px}
.items{display:none;margin-top:8px;padding-left:12px}
.items.active{display:block}
  .btn{
  background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
  color: #fff;
  border: none;
  border-radius: 12px;
  padding: 12px 20px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 4px 14px rgba(30, 58, 138, 0.3);
  position: relative;
  overflow: hidden;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.btn:hover{
  background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(30, 58, 138, 0.4);
}
.btn:active{
  transform: translateY(0);
}
.btn.gray{
  background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
  box-shadow: 0 4px 14px rgba(107, 114, 128, 0.3);
}
.btn.gray:hover{
  background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
  box-shadow: 0 8px 25px rgba(107, 114, 128, 0.4);
}
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

// Sistema de Rascunho de Múltiplos Eventos
let rascunhoEventos = [];

function adicionarEvento() {
  const eventoIdRaw = document.getElementById('evento_id_me')?.value;
  const eventoNome = document.getElementById('evento_nome')?.value;
  const eventoData = document.getElementById('evento_data')?.value;
  const eventoConvidados = document.getElementById('evento_convidados')?.value;
  
  // Validar e converter ID do evento para inteiro
  const eventoId = parseInt(eventoIdRaw) || 0;
  if (eventoId <= 0) {
    alert('ID de evento inválido. Selecione um evento válido da ME.');
    return;
  }
  
  // Debug: mostrar valores dos campos
  console.log('Debug - Valores dos campos:', {
    eventoId: eventoId,
    eventoNome: eventoNome,
    eventoData: eventoData,
    eventoConvidados: eventoConvidados
  });
  
  if (!eventoId || !eventoNome) {
    alert(`Selecione um evento da ME antes de adicionar ao rascunho.\n\nDebug:\n- ID: ${eventoId || 'VAZIO'}\n- Nome: ${eventoNome || 'VAZIO'}`);
    return;
  }
  
  // Verificar se evento já foi adicionado
  if (rascunhoEventos.find(e => e.id === eventoId)) {
    alert('Este evento já foi adicionado ao rascunho.');
    return;
  }
  
  // Coletar itens selecionados
  const itensSelecionados = [];
  const checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');
  
  console.log('Debug - Checkboxes encontrados:', checkboxes.length);
  
  checkboxes.forEach((checkbox, index) => {
    console.log(`Checkbox ${index}:`, {
      value: checkbox.value,
      dataset: checkbox.dataset,
      quantidade: checkbox.dataset.quantidade || 1
    });
    
    const quantidade = parseFloat(checkbox.dataset.quantidade || 1);
    if (quantidade > 0) {
      itensSelecionados.push({
        id: checkbox.value,
        nome: checkbox.dataset.nome || 'Item',
        quantidade: quantidade,
        tipo: checkbox.dataset.tipo || 'insumo'
      });
    }
  });
  
  console.log('Debug - Itens selecionados:', itensSelecionados);
  
  if (itensSelecionados.length === 0) {
    alert('Selecione pelo menos um item para adicionar ao rascunho.');
    return;
  }
  
  // Adicionar evento ao rascunho
  rascunhoEventos.push({
    id: eventoId,
    nome: eventoNome,
    data: eventoData,
    convidados: eventoConvidados,
    itens: itensSelecionados
  });
  
  // Atualizar interface
  atualizarRascunho();
  
  // Limpar seleção atual
  checkboxes.forEach(cb => cb.checked = false);
  
  alert(`Evento "${eventoNome}" adicionado ao rascunho!`);
}

function atualizarRascunho() {
  const container = document.getElementById('rascunhoContainer');
  const eventosDiv = document.getElementById('eventosRascunho');
  
  if (rascunhoEventos.length === 0) {
    container.style.display = 'none';
    return;
  }
  
  container.style.display = 'block';
  
  let html = '';
  rascunhoEventos.forEach((evento, index) => {
    html += `
      <div class="evento-rascunho-card">
        <div class="evento-info">
          <h4 class="evento-nome">${evento.nome}</h4>
          <p class="evento-detalhes">
            📅 ${evento.data} | 👥 ${evento.convidados} convidados | 📦 ${evento.itens.length} itens
          </p>
        </div>
        <div class="evento-actions">
          <button type="button" class="btn btn--secondary btn-sm" onclick="removerEvento(${index})">
            ❌ Remover
          </button>
        </div>
      </div>
    `;
  });
  
  eventosDiv.innerHTML = html;
}

function removerEvento(index) {
  if (confirm('Tem certeza que deseja remover este evento do rascunho?')) {
    rascunhoEventos.splice(index, 1);
    atualizarRascunho();
  }
}

function limparRascunho() {
  if (confirm('Tem certeza que deseja limpar todo o rascunho?')) {
    rascunhoEventos = [];
    atualizarRascunho();
  }
}

function gerarListaFinal() {
  if (rascunhoEventos.length === 0) {
    alert('Adicione pelo menos um evento ao rascunho antes de gerar a lista.');
    return;
  }
  
  if (!confirm(`Gerar lista final com ${rascunhoEventos.length} evento(s)?`)) {
    return;
  }
  
  // Criar formulário para enviar dados
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '';
  
  // Adicionar campo de ação
  const acaoInput = document.createElement('input');
  acaoInput.type = 'hidden';
  acaoInput.name = 'acao';
  acaoInput.value = 'gerar_lista_final';
  form.appendChild(acaoInput);
  
  // Adicionar dados dos eventos
  const eventosInput = document.createElement('input');
  eventosInput.type = 'hidden';
  eventosInput.name = 'eventos_rascunho';
  eventosInput.value = JSON.stringify(rascunhoEventos);
  form.appendChild(eventosInput);
  
  // Adicionar ao DOM e enviar
  document.body.appendChild(form);
  form.submit();
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
    <style>
  /* ====== Estilos Modernos para Lista de Compras ====== */
  
  /* Reset e base */
  *, *::before, *::after { 
    box-sizing: border-box; 
    margin: 0;
    padding: 0;
  }

  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    min-height: 100vh;
    color: #374151;
  }

  /* Container principal */
  .lc-main-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    margin-top: 20px;
    margin-bottom: 20px;
  }

  /* Título principal */
  .lc-title {
    font-size: 2.5rem;
    font-weight: 800;
    color: #1e3a8a;
    text-align: center;
    margin-bottom: 30px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  }

  /* Botões de ação */
  .lc-action-buttons {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
  }

  .btn {
    padding: 12px 24px;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    cursor: pointer;
    font-size: 16px;
  }

  .btn-primary {
    background: #1e3a8a;
    color: white;
    box-shadow: 0 4px 15px rgba(30, 58, 138, 0.3);
  }

  .btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
  }

  .btn-secondary {
    background: #6b7280;
    color: white;
    box-shadow: 0 4px 15px rgba(107, 114, 128, 0.3);
  }
  
  .btn-danger {
    background: #dc2626;
    color: white;
  }
  
  .btn-danger:hover {
    background: #b91c1c;
    transform: translateY(-2px);
  }

  .btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(107, 114, 128, 0.4);
  }

  /* Formulário */
  .form {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 24px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
    margin-bottom: 20px;
  }

  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
  }

  .block {
    border: 2px dashed #dbeafe;
    border-radius: 12px;
    padding: 20px;
    margin-top: 20px;
    background: #f8fafc;
  }

  h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: #1e3a8a;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  label.small {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #374151;
  }

  .input {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 16px;
    transition: all 0.3s ease;
    background: white;
  }

  .input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
  }

  .btn-sm {
    padding: 8px 16px;
    font-size: 14px;
    border-radius: 8px;
  }

  /* Responsivo */
  @media (max-width: 768px) {
    .lc-main-container {
      margin: 10px;
      padding: 16px;
    }
    
    .lc-title {
      font-size: 2rem;
    }
    
    .grid {
      grid-template-columns: 1fr;
    }
    
    .lc-action-buttons {
      flex-direction: column;
      align-items: center;
    }
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
    border-radius: 16px;
    border: 1px solid #e9ecef;
    padding: 32px;
    margin-bottom: 32px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.08);
    position: relative;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
  }

  .lc-section:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 40px rgba(0,0,0,0.12);
  }

  .lc-section h2 {
    margin: 0 0 24px 0;
    color: #2c3e50;
    font-size: 24px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
  }

  .lc-section h2::before {
    content: '';
    width: 6px;
    height: 32px;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    border-radius: 3px;
    box-shadow: 0 2px 8px rgba(30, 58, 138, 0.3);
  }

  .lc-section h2::after {
    content: '';
    position: absolute;
    bottom: -8px;
    left: 0;
    width: 60px;
    height: 3px;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    border-radius: 2px;
    opacity: 0.6;
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
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 24px;
    margin-top: 20px;
  }

  /* Responsividade */
  @media (max-width: 768px) {
    .lc-main-container {
      padding: 16px;
    }
    
    .lc-section {
      padding: 20px;
      margin-bottom: 20px;
    }
    
    .lc-categories-grid {
      grid-template-columns: 1fr;
      gap: 16px;
    }
    
    .grid {
      grid-template-columns: 1fr;
    }
    
    .btn {
      padding: 10px 16px;
      font-size: 13px;
    }
  }

  .lc-category-card {
    border: 1px solid #e9ecef;
    border-radius: 16px;
    padding: 20px;
    background: #fff;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
  }

  .lc-category-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
  }

  .lc-category-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
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
    margin-right: 12px;
    transform: scale(1.3);
    accent-color: #1e3a8a;
    cursor: pointer;
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
    padding: 12px;
    border-radius: 10px;
    transition: all 0.3s ease;
    border: 1px solid transparent;
    background: #f8f9fa;
    margin-bottom: 8px;
  }

  .lc-item-checkbox:hover {
    background: #e9ecef;
    border-color: #1e3a8a;
    transform: translateX(4px);
  }

  .lc-item-checkbox input[type="checkbox"] {
    margin-right: 12px;
    margin-top: 2px;
    transform: scale(1.2);
    accent-color: #1e3a8a;
    cursor: pointer;
  }

  .lc-item-checkbox:has(input:checked) {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    color: white;
    border-color: #1e3a8a;
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

  /* Rascunho de Eventos */
  .lc-rascunho-container {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    border: 2px solid #3b82f6;
    border-radius: 12px;
    padding: 1.5rem;
    margin: 1rem 0;
  }

  .lc-rascunho-container h3 {
    color: #1e40af;
    margin: 0 0 1rem 0;
    font-size: 1.25rem;
    font-weight: 700;
  }

  .eventos-rascunho {
    margin-bottom: 1rem;
  }

  .evento-rascunho-card {
    background: white;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 0.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .evento-info {
    flex: 1;
  }

  .evento-nome {
    font-weight: 600;
    color: #1f2937;
    margin: 0;
  }

  .evento-detalhes {
    font-size: 0.875rem;
    color: #6b7280;
    margin: 0.25rem 0 0 0;
  }

  .evento-actions {
    display: flex;
    gap: 0.5rem;
  }

  .rascunho-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
  }

  .btn--danger:hover {
    background: #c82333;
  }

  .btn--success {
    background: #28a745;
    color: white;
  }

  .btn--success:hover {
    background: #218838;
  }

  .btn--lg {
    padding: 12px 24px;
    font-size: 16px;
    font-weight: 600;
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

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
  <div>
    <h1 class="page-title">Gerar Lista de Compras</h1>
    <p class="page-subtitle">Crie listas de compras e encomendas para seus eventos</p>
  </div>
  <div style="display: flex; gap: 10px;">
    <a href="index.php?page=config_fornecedores" class="btn btn-primary" style="display: flex; align-items: center; gap: 5px;">
      <span>🏢</span> Cadastrar Fornecedor
    </a>
  </div>
</div>

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
                <a class="btn btn-secondary" href="index.php?page=lista_compras&acao=editar_rascunho&id=<?= (int)$r['id'] ?>">Editar</a>
                <a class="btn btn-danger" href="index.php?page=lista_compras&acao=excluir_rascunho&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir este rascunho?')">Excluir</a>
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

      <!-- 4. RASCUNHO DE EVENTOS -->
      <div class="lc-rascunho-container" id="rascunhoContainer" style="display: none;">
        <h3>📝 Rascunho da Lista</h3>
        <div id="eventosRascunho" class="eventos-rascunho">
          <!-- Eventos adicionados aparecerão aqui -->
        </div>
        <div class="rascunho-actions">
          <button type="button" class="btn btn--secondary" onclick="limparRascunho()">🗑️ Limpar Rascunho</button>
          <button type="button" class="btn btn--success btn--lg" onclick="gerarListaFinal()">
            <span>📋</span> Gerar Lista Final
          </button>
        </div>
      </div>

      <!-- 5. AÇÕES -->
      <div class="lc-actions-container">
        <button class="btn btn--secondary" type="button" onclick="adicionarEvento()">➕ Adicionar Evento ao Rascunho</button>
        <button class="btn btn--secondary" type="button" onclick="salvarRascunho()">Salvar rascunho</button>
        <a class="btn btn-secondary" href="index.php?page=dashboard">Cancelar</a>
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
  // Aguardar DOM estar pronto
  function initMEBusca() {
    const $ = s => document.querySelector(s);
    const modal = $('#modalME'), btnOpen = $('#btnBuscarME'), btnClose = $('#me_close'), btnExec = $('#me_exec'), box = $('#me_results');

    console.log('Elementos encontrados:', {
      modal: !!modal,
      btnOpen: !!btnOpen,
      btnClose: !!btnClose,
      btnExec: !!btnExec,
      box: !!box
    });

    if (!modal || !btnOpen || !btnClose || !btnExec || !box) {
      console.error('Elementos do modal ME não encontrados!', {
        modal: !!modal,
        btnOpen: !!btnOpen,
        btnClose: !!btnClose,
        btnExec: !!btnExec,
        box: !!box
      });
      return;
    }

    // Teste de conectividade com me_proxy.php
    fetch('./me_proxy.php')
      .then(r => r.json())
      .then(data => console.log('Teste de conectividade me_proxy.php:', data))
      .catch(e => console.error('Erro ao testar me_proxy.php:', e));

    const openModal  = () => { 
      console.log('Abrindo modal ME');
      if (modal) modal.style.display = 'flex'; 
    };
    const closeModal = () => { 
      console.log('Fechando modal ME');
      if (modal) modal.style.display = 'none'; 
    };

    // Registrar evento de abertura
    btnOpen.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      console.log('Clique detectado no botão Buscar na ME');
      openModal();
    });
    console.log('Evento de abertura do modal registrado');
  
    // Registrar evento de fechamento
    btnClose.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      closeModal();
    });
    console.log('Evento de fechamento do modal registrado');

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
    btnExec.addEventListener('click', async function(e) {
      e.preventDefault();
      e.stopPropagation();
      console.log('Botão Buscar clicado');
      
      const start = $('#me_start')?.value || '';
      const end   = $('#me_end')?.value   || '';
      const q     = $('#me_q')?.value     || '';

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
        <div style="display:grid;grid-template-columns:1fr 140px 90px 120px 110px 96px 120px;gap:8px;padding:10px 12px;background:#f6f9ff;border-bottom:1px solid #eaeaea;font-weight:600;">
          <div>Cliente</div><div>Evento</div><div>Convid.</div><div>Data</div><div>Hora</div><div>Local</div><div>Ação</div>
        </div>`;

      const rows = lista.map(raw => {
        const ev = mapEventoToForm(raw);
        const cliente = raw.nomeCliente ?? '';
        const evento = raw.nomeevento ?? '';
        const local = raw.localevento ?? '';
        const dataBR  = ev.data ? ev.data.split('-').reverse().join('/') : '';
        const payload = encodeURIComponent(JSON.stringify({ev}));
        return `
          <div style="display:grid;grid-template-columns:1fr 140px 90px 120px 110px 96px 120px;gap:8px;padding:10px 12px;border-bottom:1px solid #f0f0f0;">
            <div><strong>${cliente || '(sem cliente)'}</strong></div>
            <div>${evento || ''}</div>
            <div>${ev.convidados || ''}</div>
            <div>${dataBR || ''}</div>
            <div>${ev.hora || ''}</div>
            <div>${local || ''}</div>
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
          const hid = document.getElementById('evento_id_me'); 
          if (hid) {
            const eventId = parseInt(ev.id) || 0;
            hid.value = eventId > 0 ? eventId : '';
          }
          closeModal();
          document.getElementById('evento_espaco')?.scrollIntoView({behavior:'smooth', block:'center'});
        });
      });

    } catch (e) {
      console.error('Erro ao buscar na ME:', e);
      if (box) {
        box.innerHTML = `<div style="padding:12px;color:#b00">Falha ao buscar na ME (${e.message}).</div>`;
      }
    }
    });
    console.log('Evento de busca registrado');
  }

  // Inicializar quando DOM estiver pronto - usar múltiplas estratégias
  function iniciarMEBusca() {
    // Tentar múltiplas vezes para garantir que os elementos estejam disponíveis
    setTimeout(() => {
      initMEBusca();
    }, 100);
    
    setTimeout(() => {
      initMEBusca();
    }, 500);
    
    // Também tentar imediatamente
    initMEBusca();
  }
  
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarMEBusca);
  } else {
    // DOM já está pronto
    iniciarMEBusca();
  }
  
  // Também tentar após um pequeno delay adicional (para garantir que tudo está renderizado)
  window.addEventListener('load', () => {
    setTimeout(initMEBusca, 200);
  });

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

<?php
$conteudo = ob_get_clean();
includeSidebar('Logístico');
echo $conteudo;
endSidebar();
?>
