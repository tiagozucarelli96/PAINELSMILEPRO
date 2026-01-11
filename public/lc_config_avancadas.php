<?php
// public/lc_config_avancadas.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_config_helper.php';



// mapa de chaves e defaults
$FIELDS = [
  // 1) Arredondamento e precisão
  'precisao_quantidade'        => '3',   // 2|3|4
  'precisao_valor'             => '2',   // 2|3
  'arred_custo_convidado'      => '1',   // 1|0
  'arred_embalagem_auto'       => '1',   // 1|0
  'arred_sempre_pra_cima'      => '1',   // 1|0

  // 2) Exibição e relatórios
  'mostrar_custo_previa'       => '1',   // 1|0
  'mostrar_custo_pdf'          => '1',   // 1|0
  'pdf_detalhe_custos'         => 'simples', // simples|completo

  // 3) Regras operacionais
  'incluir_fixos_auto'         => '1',   // 1|0
  'fixos_sem_convidados'       => '1',   // 1|0
  'multiplicar_por_eventos'    => '1',   // 1|0

  // 4) Permissões
  'perm_pdf_todos'             => '1',   // 1|0
  'perm_excluir_listas'        => '0',   // 1|0
  'perm_editar_insumos_fichas' => '1',   // 1|0

  // 5) Preferências visuais
  'tema'                        => 'azul-claro', // azul-claro|azul-escuro
  'fonte_tamanho'               => 'media',      // pequena|media|grande
];

$msg=''; $err='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    foreach ($FIELDS as $k=>$def) {
      $val = isset($_POST[$k]) ? (string)$_POST[$k] : $def;
      lc_set_config($pdo, $k, $val);
    }
    $msg = 'Preferências salvas com sucesso.';
  } catch (Exception $e){
    $err = $e->getMessage();
  }
}

$cfg = lc_get_configs($pdo, array_keys($FIELDS), $FIELDS);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Configurações Avançadas | Painel Smile PRO</title>
  <link rel="stylesheet" href="estilo.css">
  <style>
    fieldset { border:1px solid #e0e6ef; border-radius:10px; margin:12px 0; padding:12px }
    legend { color:#0a4; font-weight:600; padding:0 6px }
    label { display:block; margin:6px 0 }
    .row { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px }
    .msg{background:#e7fff0;border:1px solid #bfe9cc;padding:8px;border-radius:6px;margin:10px 0}
    .err{background:#ffecec;border:1px solid #ffb3b3;padding:8px;border-radius:6px;margin:10px 0}
  </style>
</head>
<body>
  <h1>Configurações Avançadas</h1>
  <?php if ($msg): ?><div class="msg"><?=h($msg)?></div><?php endif; ?>
  <?php if ($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>

  <form method="post">
    <!-- 1) Arredondamento e precisão -->
    <fieldset>
      <legend>Arredondamento e precisão</legend>
      <div class="row">
        <label>Casas decimais para quantidades
          <select name="precisao_quantidade">
            <?php foreach (['2','3','4'] as $v): ?>
              <option value="<?=$v?>" <?= ($cfg['precisao_quantidade']===$v?'selected':'') ?>><?=$v?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Casas decimais para valores
          <select name="precisao_valor">
            <?php foreach (['2','3'] as $v): ?>
              <option value="<?=$v?>" <?= ($cfg['precisao_valor']===$v?'selected':'') ?>><?=$v?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>Arredondar custo por convidado?
          <select name="arred_custo_convidado">
            <option value="1" <?= $cfg['arred_custo_convidado']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['arred_custo_convidado']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>

        <label>Arredondar embalagens automaticamente?
          <select name="arred_embalagem_auto">
            <option value="1" <?= $cfg['arred_embalagem_auto']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['arred_embalagem_auto']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>

        <label>Arredondar sempre pra cima (quando arredondar)?
          <select name="arred_sempre_pra_cima">
            <option value="1" <?= $cfg['arred_sempre_pra_cima']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['arred_sempre_pra_cima']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>
      </div>
    </fieldset>

    <!-- 2) Exibição e relatórios -->
    <fieldset>
      <legend>Exibição e relatórios</legend>
      <div class="row">
        <label>Mostrar custo por convidado na prévia?
          <select name="mostrar_custo_previa">
            <option value="1" <?= $cfg['mostrar_custo_previa']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['mostrar_custo_previa']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>

        <label>Mostrar custo por convidado no PDF?
          <select name="mostrar_custo_pdf">
            <option value="1" <?= $cfg['mostrar_custo_pdf']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['mostrar_custo_pdf']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>

        <label>Nível de detalhe dos custos no PDF
          <select name="pdf_detalhe_custos">
            <?php foreach (['simples'=>'Simples','completo'=>'Completo'] as $k=>$v): ?>
              <option value="<?=$k?>" <?= ($cfg['pdf_detalhe_custos']===$k?'selected':'') ?>><?=$v?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </fieldset>

    <!-- 3) Regras operacionais -->
    <fieldset>
      <legend>Regras operacionais</legend>
      <div class="row">
        <label>Incluir itens fixos automaticamente?
          <select name="incluir_fixos_auto">
            <option value="1" <?= $cfg['incluir_fixos_auto']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['incluir_fixos_auto']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>

        <label>Incluir fixos mesmo sem convidados?
          <select name="fixos_sem_convidados">
            <option value="1" <?= $cfg['fixos_sem_convidados']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['fixos_sem_convidados']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>

        <label>Multiplicar insumos por número de eventos?
          <select name="multiplicar_por_eventos">
            <option value="1" <?= $cfg['multiplicar_por_eventos']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['multiplicar_por_eventos']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>
      </div>
    </fieldset>

    <!-- 4) Permissões -->
    <fieldset>
      <legend>Permissões</legend>
      <div class="row">
        <label>Permitir PDF para todos?
          <select name="perm_pdf_todos">
            <option value="1" <?= $cfg['perm_pdf_todos']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['perm_pdf_todos']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>

        <label>Permitir excluir listas?
          <select name="perm_excluir_listas">
            <option value="1" <?= $cfg['perm_excluir_listas']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['perm_excluir_listas']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>

        <label>Permitir editar insumos e fichas?
          <select name="perm_editar_insumos_fichas">
            <option value="1" <?= $cfg['perm_editar_insumos_fichas']==='1'?'selected':'' ?>>Sim</option>
            <option value="0" <?= $cfg['perm_editar_insumos_fichas']!=='1'?'selected':'' ?>>Não</option>
          </select>
        </label>
      </div>
    </fieldset>

    <!-- 5) Preferências visuais -->
    <fieldset>
      <legend>Preferências visuais</legend>
      <div class="row">
        <label>Tema
          <select name="tema">
            <option value="azul-claro"  <?= $cfg['tema']==='azul-claro'?'selected':'' ?>>Azul claro</option>
            <option value="azul-escuro" <?= $cfg['tema']==='azul-escuro'?'selected':'' ?>>Azul escuro</option>
          </select>
        </label>

        <label>Tamanho da fonte
          <select name="fonte_tamanho">
            <option value="pequena" <?= $cfg['fonte_tamanho']==='pequena'?'selected':'' ?>>Pequena</option>
            <option value="media"   <?= $cfg['fonte_tamanho']==='media'?'selected':'' ?>>Média</option>
            <option value="grande"  <?= $cfg['fonte_tamanho']==='grande'?'selected':'' ?>>Grande</option>
          </select>
        </label>
      </div>
    </fieldset>

    <p><button type="submit">💾 Salvar preferências</button></p>
  </form>
</body>
</html>
