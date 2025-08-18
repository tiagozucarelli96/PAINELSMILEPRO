<?php
// lista_compras_gerar.php — Gerar Lista de Compras (gera Compras + Encomendas)
session_start();
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão mínima: usuário logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function uuidv4(){
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data),4));
}
function weekday_pt($dateYmd){
    // 0 domingo ... 6 sábado
    $w = (int)date('w', strtotime($dateYmd));
    $names = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
    return $names[$w] ?? '';
}
function ensure_col_ficha_on_itens(PDO $pdo){
    // adiciona coluna ficha_id em lc_itens se não existir
    $q = $pdo->query("SHOW COLUMNS FROM lc_itens LIKE 'ficha_id'");
    if (!$q->fetch()){
        $pdo->exec("ALTER TABLE lc_itens ADD COLUMN ficha_id INT UNSIGNED NULL AFTER fornecedor_id");
        $pdo->exec("ALTER TABLE lc_itens ADD CONSTRAINT fk_itens_ficha FOREIGN KEY (ficha_id) REFERENCES lc_fichas(id)");
        $pdo->exec("CREATE INDEX idx_itens_ficha ON lc_itens(ficha_id)");
    }
}
function fetch_assoc(PDO $pdo, string $sql, array $params=[]){
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function fetch_all(PDO $pdo, string $sql, array $params=[]){
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Dicionários
$ESPACOS = ['garden'=>'Espaço Garden','cristal'=>'Espaço Cristal','lisbon'=>'Lisbon Buffet','diverkids'=>'Diverkids'];
$isAdmin = !empty($_SESSION['perm_usuarios']); // para link de Config

// Dados base para formulário
$cats = fetch_all($pdo, "SELECT id, nome FROM lc_categorias WHERE ativo=1 AND mostrar_no_gerar=1 ORDER BY ordem ASC, nome ASC");
$mapCat = [];
foreach ($cats as $c){ $mapCat[(int)$c['id']] = $c['nome']; }

$itensPorCat = [];
if ($cats){
    $in = implode(',', array_fill(0, count($cats), '?'));
    $rows = fetch_all($pdo, "SELECT i.*, c.nome AS categoria_nome, f.nome AS fornecedor_nome
                              FROM lc_itens i
                              JOIN lc_categorias c ON c.id=i.categoria_id
                              LEFT JOIN lc_fornecedores f ON f.id=i.fornecedor_id
                              WHERE i.ativo=1 AND i.categoria_id IN ($in)
                              ORDER BY c.ordem ASC, i.ordem ASC, i.nome ASC", array_column($cats,'id'));
    foreach ($rows as $r){
        $cid = (int)$r['categoria_id'];
        $itensPorCat[$cid][] = $r;
    }
}
$fornecedores = fetch_all($pdo, "SELECT id, nome, modo_padrao FROM lc_fornecedores WHERE ativo=1 ORDER BY nome ASC");
$mapForn = [];
foreach ($fornecedores as $f){ $mapForn[(int)$f['id']] = $f; }

$fichas = fetch_all($pdo, "SELECT id, nome, rendimento_qtd, rendimento_unid FROM lc_fichas WHERE ativo=1 ORDER BY nome ASC");
$mapFichaNome = []; $mapFichaId = [];
foreach ($fichas as $f){ $mapFichaNome[$f['nome']] = $f; $mapFichaId[(int)$f['id']] = $f; }

// Carrega payload de edição (se existir)
$action = $_GET['action'] ?? '';
$grupo_id = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;
$editPayload = null;
if ($action === 'edit' && $grupo_id > 0){
    // garante tabela de payload
    $pdo->exec("CREATE TABLE IF NOT EXISTS lc_geracoes_input (
        grupo_id INT UNSIGNED PRIMARY KEY,
        payload_json MEDIUMTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_ginp_grupo FOREIGN KEY (grupo_id) REFERENCES lc_geracoes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $row = fetch_assoc($pdo, "SELECT payload_json FROM lc_geracoes_input WHERE grupo_id=?", [$grupo_id]);
    if ($row){
        $editPayload = json_decode($row['payload_json'], true);
    }
}

// POST: salvar geração
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['post_action'] ?? '') === 'save') {
    try{
        $pdo->beginTransaction();

        // Normaliza eventos
        $events = $_POST['events'] ?? [];
        $normEvents = [];
        foreach ($events as $e){
            $espaco = $e['espaco'] ?? '';
            $conv   = (int)($e['convidados'] ?? 0);
            $hora   = trim($e['hora'] ?? '');
            $texto  = trim($e['evento_texto'] ?? '');
            $data   = trim($e['data'] ?? '');
            if (!isset($ESPACOS[$espaco]) || $conv<=0 || $hora==='' || $texto==='' || $data==='') {
                throw new Exception('Preencha todos os campos do evento (espaço, convidados, horário, evento e data).');
            }
            $dia = weekday_pt($data);
            $normEvents[] = [
                'espaco_key'=>$espaco,
                'espaco'=>$ESPACOS[$espaco],
                'convidados'=>$conv,
                'hora'=>$hora,
                'evento_texto'=>$texto,
                'data'=>$data,
                'dia_semana'=>$dia,
            ];
        }
        if (!$normEvents) throw new Exception('Informe pelo menos 1 evento.');

        // Seleção de itens
        $sel = $_POST['sel'] ?? []; // sel[cat_id][] = item_id
        $itensSelecionados = [];
        foreach ($sel as $cid=>$arr){
            if (!is_array($arr)) continue;
            foreach ($arr as $iid){
                $iid = (int)$iid;
                if ($iid>0) $itensSelecionados[$iid] = true;
            }
        }
        if (!$itensSelecionados) throw new Exception('Selecione ao menos 1 item em alguma categoria.');

        // Fornecedor escolhido para itens comprados sem fornecedor padrão
        $fornChosen = $_POST['forn'] ?? []; // forn[item_id] = fornecedor_id
        // Ficha escolhida para itens preparo sem vínculo
        $fichaChosen = $_POST['ficha'] ?? []; // ficha[item_id] = ficha_id

        // Garante coluna ficha_id para vincular
        ensure_col_ficha_on_itens($pdo);

        // Carrega itens completos
        $inIds = implode(',', array_fill(0, count($itensSelecionados), '?'));
        $itens = fetch_all($pdo, "SELECT i.*, f.nome AS fornecedor_nome, f.modo_padrao AS fornecedor_modo,
                                         fi.id AS ficha_id_join, fi.nome AS ficha_nome, fi.rendimento_qtd, fi.rendimento_unid
                                   FROM lc_itens i
                                   LEFT JOIN lc_fornecedores f ON f.id=i.fornecedor_id
                                   LEFT JOIN lc_fichas fi ON fi.id=i.ficha_id
                                   WHERE i.id IN ($inIds)", array_keys($itensSelecionados));
        $byId = [];
        foreach ($itens as $r){ $byId[(int)$r['id']] = $r; }

        // Valida fornecedores e resolve fichas
        foreach (array_keys($itensSelecionados) as $iid){
            if (!isset($byId[$iid])) throw new Exception('Item inválido selecionado.');
            $it = $byId[$iid];

            if ($it['tipo']==='comprado'){
                $fornId = (int)($it['fornecedor_id'] ?? 0);
                if ($fornId<=0){
                    $chosen = isset($fornChosen[$iid]) ? (int)$fornChosen[$iid] : 0;
                    if ($chosen<=0) throw new Exception('Selecione o fornecedor para o item comprado: '.$it['nome']);
                    // seta fornecedor padrão neste item para próximas vezes
                    $st = $pdo->prepare("UPDATE lc_itens SET fornecedor_id=:f WHERE id=:id");
                    $st->execute([':f'=>$chosen, ':id'=>$iid]);
                    $it['fornecedor_id'] = $chosen;
                    $byId[$iid]['fornecedor_id'] = $chosen;
                    $frow = fetch_assoc($pdo, "SELECT id, nome, modo_padrao FROM lc_fornecedores WHERE id=?", [$chosen]);
                    $byId[$iid]['fornecedor_nome'] = $frow['nome'] ?? null;
                    $byId[$iid]['fornecedor_modo'] = $frow['modo_padrao'] ?? 'consolidado';
                }
            }

            if ($it['tipo']==='preparo'){
                $fid = (int)($it['ficha_id'] ?? 0);
                if ($fid<=0){
                    $chosen = isset($fichaChosen[$iid]) ? (int)$fichaChosen[$iid] : 0;
                    if ($chosen<=0){
                        // fallback por nome igual
                        $auto = $mapFichaNome[$it['nome']] ?? null;
                        if ($auto) $chosen = (int)$auto['id'];
                    }
                    if ($chosen<=0) throw new Exception('Associe uma ficha técnica ao item de preparo: '.$it['nome']);
                    // vincula para próximas vezes
                    $st = $pdo->prepare("UPDATE lc_itens SET ficha_id=:f WHERE id=:id");
                    $st->execute([':f'=>$chosen, ':id'=>$iid]);
                    $byId[$iid]['ficha_id_join'] = $chosen;
                    $ff = $mapFichaId[$chosen] ?? fetch_assoc($pdo,"SELECT id,nome,rendimento_qtd,rendimento_unid FROM lc_fichas WHERE id=?",[$chosen]);
                    $byId[$iid]['ficha_nome'] = $ff['nome'] ?? null;
                    $byId[$iid]['rendimento_qtd'] = $ff['rendimento_qtd'] ?? null;
                    $byId[$iid]['rendimento_unid'] = $ff['rendimento_unid'] ?? null;
                }
            }
        }

        // Cria grupo (geração)
        $grupoToken = uuidv4();
        $st = $pdo->prepare("INSERT INTO lc_geracoes (grupo_token, criado_por, criado_por_nome) VALUES (:t,:u,:n)");
        $st->execute([
            ':t'=>$grupoToken,
            ':u'=>$_SESSION['id'] ?? null,
            ':n'=>$_SESSION['nome'] ?? null
        ]);
        $grupoId = (int)$pdo->lastInsertId();

        // Salva input para futura edição
        $pdo->exec("CREATE TABLE IF NOT EXISTS lc_geracoes_input (
            grupo_id INT UNSIGNED PRIMARY KEY,
            payload_json MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_ginp_grupo FOREIGN KEY (grupo_id) REFERENCES lc_geracoes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $payload = [
            'events'=>$normEvents,
            'sel'=>array_map('array_values', $sel),
            'forn'=>$fornChosen,
            'ficha'=>$fichaChosen,
        ];
        $st = $pdo->prepare("INSERT INTO lc_geracoes_input (grupo_id, payload_json) VALUES (?,?)");
        $st->execute([$grupoId, json_encode($payload, JSON_UNESCAPED_UNICODE)]);

        // Insere eventos (snapshot)
        $eventIds = [];
        foreach ($normEvents as $e){
            $st = $pdo->prepare("INSERT INTO lc_lista_eventos
                (grupo_id, espaco, convidados, horario, evento_texto, data_evento, dia_semana, aplicar_fixos)
                VALUES (:g,:esp,:conv,:hor,:txt,:dt,:dia,1)");
            $st->execute([
                ':g'=>$grupoId, ':esp'=>$e['espaco'], ':conv'=>$e['convidados'], ':hor'=>$e['hora'],
                ':txt'=>$e['evento_texto'], ':dt'=>$e['data'], ':dia'=>$e['dia_semana']
            ]);
            $eventIds[] = (int)$pdo->lastInsertId();
        }

        // Motor de cálculo
        // Consolidação de insumos: [insumo_id][unid] => ['q_bruta'=>, 'por_evento'=>[eventId=>q], ...]
        $compras = [];
        // Encomendas: por fornecedor e opcionalmente por evento
        $prefGlobal = fetch_assoc($pdo,"SELECT modo_padrao FROM lc_pref_encomendas WHERE id=1") ?? ['modo_padrao'=>'consolidado'];

        // Recursão de fichas
        $cacheFicha = []; // ficha_id => ['r_qtd','r_un','comp'=>[]]
        function getFicha(PDO $pdo, $ficha_id, &$cacheFicha){
            if (isset($cacheFicha[$ficha_id])) return $cacheFicha[$ficha_id];
            $fi = fetch_assoc($pdo, "SELECT id, rendimento_qtd, rendimento_unid FROM lc_fichas WHERE id=?", [$ficha_id]);
            $comp = fetch_all($pdo, "SELECT * FROM lc_ficha_componentes WHERE ficha_id=? ORDER BY ordem ASC, id ASC", [$ficha_id]);
            $fi['comp'] = $comp;
            return $cacheFicha[$ficha_id] = $fi;
        }
        function addCompra(&$compras, $insumo_id, $un, $qtd, $eventId){
            if (!isset($compras[$insumo_id])) $compras[$insumo_id] = [];
            if (!isset($compras[$insumo_id][$un])) $compras[$insumo_id][$un] = ['q_bruta'=>0,'por_evento'=>[]];
            $compras[$insumo_id][$un]['q_bruta'] += $qtd;
            $compras[$insumo_id][$un]['por_evento'][$eventId] = ($compras[$insumo_id][$un]['por_evento'][$eventId] ?? 0) + $qtd;
        }
        function explodeFicha(PDO $pdo, $ficha_id, $escala, $eventId, &$compras, &$encomendas, $mapForn){
            $fi = getFicha($pdo, $ficha_id, $GLOBALS['cacheFicha']);
            $r_qtd = (float)($fi['rendimento_qtd'] ?? 1);
            if ($r_qtd <= 0) $r_qtd = 1;
            $factor = $escala / $r_qtd;

            foreach ($fi['comp'] as $c){
                $q = (float)$c['quantidade'] * $factor;
                if ($c['componente_tipo']==='insumo'){
                    $insumo_id = (int)$c['insumo_id'];
                    $un = $c['unidade'];
                    addCompra($compras, $insumo_id, $un, $q, $eventId);
                } elseif ($c['componente_tipo']==='item_comprado'){
                    $item_id = (int)$c['item_id'];
                    $it = fetch_assoc($pdo, "SELECT id, nome, unidade_compra, fornecedor_id FROM lc_itens WHERE id=?", [$item_id]);
                    if ($it && (int)$it['fornecedor_id']>0){
                        $fornId = (int)$it['fornecedor_id'];
                        $modo = $mapForn[$fornId]['modo_padrao'] ?? ($GLOBALS['prefGlobal']['modo_padrao'] ?? 'consolidado');
                        $un = $it['unidade_compra'];
                        $encomendas[] = [
                            'fornecedor_id'=>$fornId,
                            'evento_id'=>($modo==='separado_evento') ? $eventId : null,
                            'item_id'=>$item_id,
                            'quantidade'=>$q,
                            'unidade'=>$un,
                            'observacao'=>null,
                            'modo_fornecedor'=>$modo
                        ];
                    } else {
                        throw new Exception('Componente "item comprado" sem fornecedor definido na ficha.');
                    }
                } elseif ($c['componente_tipo']==='sub_ficha'){
                    $sfid = (int)$c['sub_ficha_id'];
                    $q_sub = (float)$c['quantidade'] * $factor;
                    // interpreta quantidade como "qtd de rendimentos" da sub-ficha
                    $sfi = getFicha($pdo, $sfid, $GLOBALS['cacheFicha']);
                    $r_sub = (float)($sfi['rendimento_qtd'] ?? 1);
                    if ($r_sub <= 0) $r_sub = 1;
                    $escala_sub = $q_sub; // já em unidades de "lote/un" da sub
                    $escala_conv = $escala_sub; // assumimos compatível
                    explodeFicha($pdo, $sfid, $escala_conv, $eventId, $compras, $encomendas, $mapForn);
                }
            }
        }

        $encomendas = [];

        // Itera eventos e itens
        foreach ($normEvents as $idx=>$ev){
            $eventId = $eventIds[$idx];
            foreach (array_keys($itensSelecionados) as $iid){
                $it = $byId[$iid];
                // demanda do item no evento
                if ($it['regra_consumo']==='por_pessoa'){
                    $demanda = (float)$it['fator_por_pessoa'] * (int)$ev['convidados'];
                } else {
                    $pessoas = max(1, (int)$it['pessoas_por_lote']);
                    $lotes   = (int)ceil(((int)$ev['convidados']) / $pessoas);
                    $demanda = (float)$it['qtd_por_lote'] * $lotes;
                }

                if ($it['tipo']==='comprado'){
                    $fornId = (int)$it['fornecedor_id'];
                    $modo = $mapForn[$fornId]['modo_padrao'] ?? ($prefGlobal['modo_padrao'] ?? 'consolidado');
                    $encomendas[] = [
                        'fornecedor_id'=>$fornId,
                        'evento_id'=>($modo==='separado_evento') ? $eventId : null,
                        'item_id'=>$iid,
                        'quantidade'=>$demanda,
                        'unidade'=>$it['unidade_compra'],
                        'observacao'=>null,
                        'modo_fornecedor'=>$modo
                    ];
                } else { // preparo
                    $fid = (int)($it['ficha_id_join'] ?? $it['ficha_id'] ?? 0);
                    if ($fid<=0) throw new Exception('Item de preparo sem ficha vinculada: '.$it['nome']);
                    // A demanda do item deve estar na mesma unidade do rendimento da ficha.
                    // Assumimos que você definiu coerente (ex.: rendimento em "un" e demanda em "un").
                    explodeFicha($pdo, $fid, (float)$demanda, $eventId, $compras, $encomendas, $mapForn);
                }
            }

            // Aplica Itens Fixos do espaço
            $aplica = strtolower($ev['espaco_key']);
            $fx = fetch_all($pdo, "SELECT * FROM lc_itens_fixos WHERE ativo=1 AND (aplica_em='todos' OR aplica_em=?) ORDER BY ordem ASC, id ASC", [$aplica]);
            foreach ($fx as $f){
                addCompra($compras, (int)$f['insumo_id'], $f['unidade'], (float)$f['quantidade'], $eventId);
            }
        }

        // Aplica arredondamentos e grava Compras
        foreach ($compras as $insumo_id=>$byUn){
            foreach ($byUn as $un=>$row){
                $qbruta = (float)$row['q_bruta'];
                $arr = fetch_assoc($pdo, "SELECT metodo, passo, minimo FROM lc_arredondamentos WHERE insumo_id=?", [$insumo_id]);
                $qfinal = $qbruta;
                $foiArr = 0;
                if ($arr){
                    $passo = (float)$arr['passo']; if ($passo<=0) $passo = 1;
                    $met   = $arr['metodo'];
                    if ($met==='ceil')   $qfinal = ceil($qbruta / $passo) * $passo;
                    elseif ($met==='floor') $qfinal = floor($qbruta / $passo) * $passo;
                    else $qfinal = round($qbruta / $passo) * $passo;
                    if ($arr['minimo'] !== null && $qfinal < (float)$arr['minimo']) $qfinal = (float)$arr['minimo'];
                    if (abs($qfinal - $qbruta) > 1e-9) $foiArr = 1;
                }
                $st = $pdo->prepare("INSERT INTO lc_compras_consolidadas
                    (grupo_id, insumo_id, unidade, qtd_bruta, qtd_final, foi_arredondado, origem_json)
                    VALUES (:g,:i,:u,:qb,:qf,:fa,:orig)");
                $st->execute([
                    ':g'=>$grupoId, ':i'=>$insumo_id, ':u'=>$un,
                    ':qb'=>$qbruta, ':qf'=>$qfinal, ':fa'=>$foiArr,
                    ':orig'=>json_encode($row['por_evento'], JSON_UNESCAPED_UNICODE)
                ]);
            }
        }

        // Grava Encomendas
        foreach ($encomendas as $en){
            $st = $pdo->prepare("INSERT INTO lc_encomendas_itens
                (grupo_id, fornecedor_id, evento_id, item_id, quantidade, unidade, observacao, modo_fornecedor)
                VALUES (:g,:f,:e,:i,:q,:u,:o,:m)");
            $st->execute([
                ':g'=>$grupoId,
                ':f'=>$en['fornecedor_id'],
                ':e'=>$en['evento_id'],
                ':i'=>$en['item_id'],
                ':q'=>$en['quantidade'],
                ':u'=>$en['unidade'],
                ':o'=>$en['observacao'],
                ':m'=>$en['modo_fornecedor']
            ]);
        }

        // Resumo p/ dashboard
        $espacos = array_unique(array_map(fn($e)=>$e['espaco'], $normEvents));
        $espaco_consolidado = count($espacos)===1 ? $espacos[0] : 'Múltiplos';
        $datas = array_map(fn($e)=>date('d/m', strtotime($e['data'])), $normEvents);
        $resumo = count($normEvents).' evento'.(count($normEvents)>1?'s':'').' ('.implode(', ', array_slice($datas,0,3)).(count($datas)>3?', …':'').')';

        // Cria duas linhas em lc_listas
        $snapUserId = $_SESSION['id'] ?? null;
        $snapUserNm = $_SESSION['nome'] ?? null;
        $st = $pdo->prepare("INSERT INTO lc_listas (grupo_id, tipo, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome)
                             VALUES (:g,'compras',NOW(),:e,:r,:u,:n), (:g,'encomendas',NOW(),:e,:r,:u,:n)");
        $st->execute([':g'=>$grupoId, ':e'=>$espaco_consolidado, ':r'=>$resumo, ':u'=>$snapUserId, ':n'=>$snapUserNm]);

        $pdo->commit();
        header("Location: lista_compras.php?msg=".urlencode('Lista gerada com sucesso.'));
        exit;

    } catch(Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

// Form defaults (novo ou edição)
$formEvents = $editPayload['events'] ?? [[
    'espaco_key'=>'garden','espaco'=>$ESPACOS['garden'],'convidados'=>'',
    'hora'=>'','evento_texto'=>'','data'=>'','dia_semana'=>''
]];
$formSel    = $editPayload['sel']   ?? []; // [cat_id]=>[item_id,...]
$formForn   = $editPayload['forn']  ?? []; // [item_id]=>forn_id
$formFicha  = $editPayload['ficha'] ?? []; // [item_id]=>ficha_id

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Gerar Lista de Compras</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
.content-narrow{ max-width:1200px; margin:0 auto; }
.topbar{display:flex; gap:10px; align-items:center; margin-bottom:16px; flex-wrap:wrap}
.btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer}
.btn.link{background:#e9efff;color:#004aad}
.btn.warn{background:#b00020}
.card{margin-bottom:18px}
h1,h2,h3{margin-top:0}
.grid{display:grid; gap:10px}
.grid-2{grid-template-columns:1fr 1fr}
.grid-3{grid-template-columns:1fr 1fr 1fr}
.grid-4{grid-template-columns:1fr 1fr 1fr 1fr}
fieldset{border:1px solid #e6eefc;padding:14px;border-radius:10px}
legend{padding:0 8px;color:#004aad;font-weight:700}
.table{width:100%;border-collapse:collapse}
.table th,.table td{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top}
.badge{font-size:12px;padding:3px 8px;border-radius:999px;background:#e9efff;color:#004aad}
.small{font-size:12px;color:#666}
.cat-box{border:1px solid #eef2ff; border-radius:10px; padding:10px; margin-bottom:10px; background:#fafcff}
.cat-head{display:flex; align-items:center; justify-content:space-between}
.cat-items{margin-top:8px; display:none}
.cat-items.on{display:block}
.item-row{display:flex; gap:8px; align-items:center; margin-bottom:6px}
input[type="text"],input[type="number"],input[type="time"],input[type="date"],select,textarea{width:100%;padding:9px;border:1px solid #ccc;border-radius:8px;font-size:14px}
.event-row{display:grid; grid-template-columns:170px 130px 130px 1fr 160px 120px; gap:8px; align-items:end}
@media(max-width:1000px){ .event-row{grid-template-columns:1fr 1fr 1fr 1fr 1fr 1fr} }
.evlist .event-row{border:1px solid #eee; border-radius:10px; padding:10px; margin-bottom:10px; background:#fff}
.ev-tools{display:flex; gap:8px}
.note{font-size:13px;color:#555}
.warn{color:#7f0000}
</style>
<script>
function addEventRow(pref){
  const evs = document.getElementById('evlist');
  const idx = evs.querySelectorAll('.event-row').length;
  const tmpl = document.getElementById('tmpl-event').content.cloneNode(true);
  tmpl.querySelectorAll('[data-name]').forEach(el=>{
    const nm = el.getAttribute('data-name').replace(/__i__/g, idx);
    el.setAttribute('name', nm);
  });
  evs.appendChild(tmpl);
}
function toggleCat(id){
  const box = document.getElementById('cat-items-'+id);
  if (!box) return;
  box.classList.toggle('on');
}
function onDateChange(sel){
  const row = sel.closest('.event-row');
  const out = row.querySelector('.weekday');
  if (!out) return;
  const dt = sel.value;
  if (!dt){ out.textContent=''; return; }
  // preview simples do dia da semana (cliente). O valor real é salvo no servidor.
  const d = new Date(dt+'T00:00:00');
  const days = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
  out.textContent = days[d.getDay()] || '';
}
</script>
</head>
<body>
<div class="sidebar">
    <img src="logo-smile.png" alt="Logo" />
    <nav>
        <a href="index.php?page=dashboard">🏠 Painel</a>
        <a href="lista_compras.php">🛒 Dashboard</a>
        <?php if ($isAdmin): ?><a href="config_categorias.php">⚙️ Configurações</a><?php endif; ?>
    </nav>
</div>

<div class="main-content">
<div class="content-narrow">
    <h1>Gerar Lista de Compras</h1>

    <?php if ($err): ?>
        <div class="card" style="border-left:4px solid #b00020"><p class="warn"><?=h($err)?></p></div>
    <?php elseif (isset($_GET['msg'])): ?>
        <div class="card" style="border-left:4px solid #2e7d32"><p><?=h($_GET['msg'])?></p></div>
    <?php endif; ?>

    <form method="post" action="lista_compras_gerar.php">
        <input type="hidden" name="post_action" value="save">

        <!-- Eventos -->
        <div class="card">
            <fieldset>
                <legend>Eventos</legend>
                <div id="evlist" class="evlist">
                    <?php foreach ($formEvents as $i=>$e): ?>
                    <div class="event-row">
                        <div>
                            <label>Espaço</label>
                            <select name="events[<?= $i ?>][espaco]" data-name="events[__i__][espaco]">
                                <?php foreach ($ESPACOS as $k=>$nm): ?>
                                    <option value="<?= h($k) ?>" <?= ($e['espaco_key']??'')===$k?'selected':'' ?>><?= h($nm) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Convidados</label>
                            <input type="number" min="1" name="events[<?= $i ?>][convidados]" data-name="events[__i__][convidados]" value="<?= h($e['convidados'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Horário</label>
                            <input type="time" name="events[<?= $i ?>][hora]" data-name="events[__i__][hora]" value="<?= h($e['hora'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Evento</label>
                            <input type="text" name="events[<?= $i ?>][evento_texto]" data-name="events[__i__][evento_texto]" placeholder="ex.: Aniversário João" value="<?= h($e['evento_texto'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Data</label>
                            <input type="date" name="events[<?= $i ?>][data]" data-name="events[__i__][data]" value="<?= h($e['data'] ?? '') ?>" onchange="onDateChange(this)">
                        </div>
                        <div>
                            <label>Dia</label>
                            <div class="badge weekday"><?= h($e['dia_semana'] ?? '') ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:10px">
                    <button class="btn link" type="button" onclick="addEventRow()">+ Adicionar evento</button>
                </div>
            </fieldset>
        </div>

        <!-- Categorias e Itens -->
        <div class="card">
            <fieldset>
                <legend>Seleção de categorias e itens</legend>

                <?php if (!$cats): ?>
                    <p class="small">Nenhuma categoria ativa configurada. Cadastre em <b>Configurações → Categorias</b>.</p>
                <?php else: foreach ($cats as $c): $cid=(int)$c['id']; ?>
                    <div class="cat-box">
                        <div class="cat-head">
                            <div>
                                <input type="checkbox" id="cat-<?= $cid ?>" onclick="toggleCat(<?= $cid ?>)">
                                <label for="cat-<?= $cid ?>"><b><?= h($c['nome']) ?></b></label>
                                <span class="small">marque para ver os itens</span>
                            </div>
                            <div class="small">Itens ativos desta categoria</div>
                        </div>
                        <div id="cat-items-<?= $cid ?>" class="cat-items">
                            <?php if (empty($itensPorCat[$cid])): ?>
                                <div class="small">Nenhum item ativo nesta categoria.</div>
                            <?php else: foreach ($itensPorCat[$cid] as $it): $iid=(int)$it['id']; ?>
                                <div class="item-row">
                                    <input type="checkbox" name="sel[<?= $cid ?>][]" value="<?= $iid ?>" <?= in_array($iid, $formSel[$cid] ?? [])?'checked':'' ?>>
                                    <span><?= h($it['nome']) ?></span>
                                    <span class="badge"><?= $it['tipo']==='comprado'?'Comprado':'Preparo' ?></span>
                                    <span class="small">un: <?= h($it['unidade_compra']) ?> • regra: <?= $it['regra_consumo']==='por_pessoa'?'por pessoa':'por lote' ?></span>

                                    <?php if ($it['tipo']==='comprado' && (int)$it['fornecedor_id']<=0): ?>
                                        <span class="small" style="margin-left:8px">Fornecedor:</span>
                                        <select name="forn[<?= $iid ?>]" style="max-width:260px">
                                            <option value="">Selecione</option>
                                            <?php foreach ($fornecedores as $f): ?>
                                                <option value="<?= (int)$f['id'] ?>" <?= (isset($formForn[$iid]) && (int)$formForn[$iid]===(int)$f['id'])?'selected':'' ?>><?= h($f['nome']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>

                                    <?php if ($it['tipo']==='preparo' && (int)($it['ficha_id'] ?? 0)===0): ?>
                                        <span class="small" style="margin-left:8px">Ficha técnica:</span>
                                        <select name="ficha[<?= $iid ?>]" style="max-width:260px">
                                            <option value="">Selecione</option>
                                            <?php foreach ($fichas as $f): ?>
                                                <option value="<?= (int)$f['id'] ?>" <?= (isset($formFicha[$iid]) && (int)$formFicha[$iid]===(int)$f['id'])?'selected':'' ?>><?= h($f['nome']) ?> (rend. <?= h($f['rendimento_qtd']).' '.h($f['rendimento_unid']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>

                <p class="note">Itens <b>Comprados</b> irão para <b>Encomendas</b> (agrupado por Fornecedor → Evento conforme o modo do fornecedor). Itens <b>Preparo</b> explodem a <b>Ficha técnica</b> e viram <b>Compras</b> de insumos.</p>
            </fieldset>
        </div>

        <div class="card" style="display:flex; gap:8px">
            <button class="btn" type="submit">Salvar e gerar</button>
            <a class="btn link" href="lista_compras.php">Cancelar</a>
        </div>
    </form>

    <template id="tmpl-event">
        <div class="event-row">
            <div>
                <label>Espaço</label>
                <select data-name="events[__i__][espaco]">
                    <?php foreach ($ESPACOS as $k=>$nm): ?>
                        <option value="<?= h($k) ?>"><?= h($nm) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Convidados</label>
                <input type="number" min="1" data-name="events[__i__][convidados]">
            </div>
            <div>
                <label>Horário</label>
                <input type="time" data-name="events[__i__][hora]">
            </div>
            <div>
                <label>Evento</label>
                <input type="text" placeholder="ex.: Casamento Ana & Pedro" data-name="events[__i__][evento_texto]">
            </div>
            <div>
                <label>Data</label>
                <input type="date" data-name="events[__i__][data]" onchange="onDateChange(this)">
            </div>
            <div>
                <label>Dia</label>
                <div class="badge weekday"></div>
            </div>
        </div>
    </template>

    <p class="small">Observação: a edição de listas reutiliza o “snapshot” salvo desta tela. Se não existir snapshot antigo, gere uma nova lista.</p>
</div>
</div>
</body>
</html>
