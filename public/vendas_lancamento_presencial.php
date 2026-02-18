<?php
/**
 * vendas_lancamento_presencial.php
 * Vendas > Lançamento (Presencial)
 *
 * - Somente logado + perm_comercial
 * - Cria/edita pré-contrato com origem = presencial
 * - Não aprova / não cria evento na ME
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/vendas_helper.php';
require_once __DIR__ . '/upload_magalu.php';

if (empty($_SESSION['logado']) || empty($_SESSION['perm_comercial'])) {
    header('Location: index.php?page=login');
    exit;
}

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

$mensagens = [];
$erros = [];

// Garantir schema do módulo (evita fatal quando SQL ainda não foi aplicado no ambiente)
if (!vendas_ensure_schema($pdo, $erros, $mensagens)) {
    includeSidebar('Comercial');
    echo '<div style="padding:2rem;max-width:1100px;margin:0 auto;">';
    foreach ($erros as $e) {
        echo '<div class="alert alert-error">' . htmlspecialchars((string)$e) . '</div>';
    }
    echo '<div class="alert alert-error">Base de Vendas ausente/desatualizada. Execute os SQLs <code>sql/041_modulo_vendas.sql</code> e <code>sql/042_vendas_ajustes.sql</code>.</div>';
    echo '</div>';
    endSidebar();
    exit;
}

$locais_mapeados = vendas_buscar_locais_mapeados();
$tipos_evento = [
    'casamento' => [
        'label' => 'Casamento',
        'nome_label' => 'Nome dos noivos',
        'nome_placeholder' => 'Ex: João e Maria',
        'nome_erro' => 'Nome dos noivos é obrigatório.',
    ],
    '15anos' => [
        'label' => '15 Anos / Debutante',
        'nome_label' => 'Nome da debutante',
        'nome_placeholder' => 'Ex: Maria Silva',
        'nome_erro' => 'Nome da debutante é obrigatório.',
    ],
    'infantil' => [
        'label' => 'Infantil',
        'nome_label' => 'Nome do aniversariante',
        'nome_placeholder' => 'Ex: Maria (5 anos)',
        'nome_erro' => 'Nome do aniversariante é obrigatório.',
    ],
];

// Edição
$editar_id = (int)($_GET['editar'] ?? 0);
$registro = null;
$adicionais_editar = [];
$anexos_editar = [];

if ($editar_id) {
    $st = $pdo->prepare("SELECT * FROM vendas_pre_contratos WHERE id = ? LIMIT 1");
    $st->execute([$editar_id]);
    $registro = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($registro) {
        $st = $pdo->prepare("SELECT * FROM vendas_adicionais WHERE pre_contrato_id = ? ORDER BY id");
        $st->execute([$editar_id]);
        $adicionais_editar = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("SELECT * FROM vendas_anexos WHERE pre_contrato_id = ? ORDER BY criado_em DESC");
        $st->execute([$editar_id]);
        $anexos_editar = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

$tipo_evento_form = 'casamento';
$tipo_evento_get = (string)($_GET['tipo_evento'] ?? $_GET['tipo'] ?? '');
if (isset($tipos_evento[$tipo_evento_get])) {
    $tipo_evento_form = $tipo_evento_get;
}
if (!empty($registro['tipo_evento']) && isset($tipos_evento[(string)$registro['tipo_evento']])) {
    $tipo_evento_form = (string)$registro['tipo_evento'];
}
if (!empty($_POST['tipo_evento']) && isset($tipos_evento[(string)$_POST['tipo_evento']])) {
    $tipo_evento_form = (string)$_POST['tipo_evento'];
}
$tipo_evento_cfg = $tipos_evento[$tipo_evento_form];

function vendas_money($v): float {
    if ($v === null) return 0.0;
    if (is_string($v)) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    }
    return (float)$v;
}

function vendas_money_brl($v): string {
    return number_format((float)$v, 2, ',', '.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'salvar_presencial') {
        $id_edit = (int)($_POST['pre_contrato_id'] ?? 0);

        // Cliente (iguais ao público)
        $nome_completo = trim((string)($_POST['nome_completo'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $telefone = preg_replace('/\D/', '', (string)($_POST['telefone'] ?? ''));
        $cpf = preg_replace('/\D/', '', (string)($_POST['cpf'] ?? ''));
        $rg = trim((string)($_POST['rg'] ?? ''));
        $cep = preg_replace('/\D/', '', (string)($_POST['cep'] ?? ''));
        $endereco = trim((string)($_POST['endereco_completo'] ?? ''));
        $numero = trim((string)($_POST['numero'] ?? ''));
        $complemento = trim((string)($_POST['complemento'] ?? ''));
        $bairro = trim((string)($_POST['bairro'] ?? ''));
        $cidade = trim((string)($_POST['cidade'] ?? ''));
        $estado = strtoupper(trim((string)($_POST['estado'] ?? '')));
        $pais = trim((string)($_POST['pais'] ?? 'Brasil'));
        $instagram = trim((string)($_POST['instagram'] ?? ''));

        // Evento
        $data_evento = (string)($_POST['data_evento'] ?? '');
        $hora_inicio = (string)($_POST['horario_inicio'] ?? '');
        $hora_termino = (string)($_POST['horario_termino'] ?? '');
        $unidade = (string)($_POST['unidade'] ?? '');
        $tipo_evento = (string)($_POST['tipo_evento'] ?? $tipo_evento_form);
        if (!isset($tipos_evento[$tipo_evento])) {
            $tipo_evento = 'casamento';
        }
        $tipo_evento_cfg = $tipos_evento[$tipo_evento];
        $nome_noivos = trim((string)($_POST['nome_noivos'] ?? ''));
        $num_convidados = (int)($_POST['num_convidados'] ?? 0);
        $como_conheceu = (string)($_POST['como_conheceu'] ?? '');
        $como_conheceu_outro = trim((string)($_POST['como_conheceu_outro'] ?? ''));

        // Texto livre (interno)
        $pacote_plano = trim((string)($_POST['pacote_plano'] ?? ''));

        // Campos internos
        $forma_pagamento = trim((string)($_POST['forma_pagamento'] ?? ''));
        $valor_negociado = vendas_money($_POST['valor_negociado'] ?? 0);
        $desconto = vendas_money($_POST['desconto'] ?? 0);
        $observacoes_internas = trim((string)($_POST['observacoes_internas'] ?? ''));
        $status = (string)($_POST['status'] ?? 'aguardando_conferencia');
        if (!in_array($status, ['aguardando_conferencia', 'pronto_aprovacao'], true)) {
            $status = 'aguardando_conferencia';
        }

        // Adicionais
        $adicionais = [];
        if (!empty($_POST['adicionais']) && is_array($_POST['adicionais'])) {
            foreach ($_POST['adicionais'] as $a) {
                if (!is_array($a)) continue;
                $item = trim((string)($a['item'] ?? ''));
                $valor = vendas_money($a['valor'] ?? 0);
                if ($item !== '' && $valor >= 0) {
                    $adicionais[] = ['item' => $item, 'valor' => $valor];
                }
            }
        }

        // Validações (mesmas do público + internas)
        try {
            if ($nome_completo === '' || mb_strlen($nome_completo) < 3) throw new Exception('Nome completo é obrigatório.');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('E-mail válido é obrigatório.');
            if ($telefone === '' || strlen($telefone) < 10) throw new Exception('Telefone/WhatsApp é obrigatório.');
            if ($cpf === '' || strlen($cpf) !== 11 || !vendas_validar_cpf($cpf)) throw new Exception('CPF inválido.');
            if ($rg === '') throw new Exception('RG é obrigatório.');

            if ($cep === '' || strlen($cep) !== 8) throw new Exception('CEP inválido.');
            if ($endereco === '') throw new Exception('Endereço é obrigatório.');
            if ($numero === '') throw new Exception('Número é obrigatório.');
            if ($bairro === '') throw new Exception('Bairro é obrigatório.');
            if ($cidade === '') throw new Exception('Cidade é obrigatória.');
            if ($estado === '' || strlen($estado) !== 2) throw new Exception('Estado (UF) inválido.');

            if ($data_evento === '') throw new Exception('Data do evento é obrigatória.');
            $dt = new DateTime($data_evento);
            $hoje = new DateTime('today');
            if ($dt < $hoje) throw new Exception('Data do evento não pode ser passada.');

            if ($hora_inicio === '' || $hora_termino === '') throw new Exception('Horários são obrigatórios.');
            $inicio_ts = strtotime($hora_inicio);
            $termino_ts = strtotime($hora_termino);
            if ($inicio_ts === false || $termino_ts === false) {
                throw new Exception('Horário de início ou término inválido.');
            }
            if ($termino_ts === $inicio_ts) {
                throw new Exception('Hora término deve ser diferente da hora início.');
            }

            $me_local_id = vendas_validar_local_mapeado($unidade);
            if (!$me_local_id) throw new Exception('Local não mapeado. Ajuste em Logística > Conexão.');

            if ($nome_noivos === '') throw new Exception((string)$tipo_evento_cfg['nome_erro']);
            if ($num_convidados <= 0) throw new Exception('Nº de convidados deve ser maior que zero.');

            if ($como_conheceu === '') throw new Exception('Como conheceu é obrigatório.');
            if ($como_conheceu === 'outro' && $como_conheceu_outro === '') throw new Exception('Informe como conheceu quando selecionar "Outro".');

            if ($pacote_plano === '') throw new Exception('Pacote/Plano escolhido é obrigatório.');

            if ($valor_negociado < 0) throw new Exception('Valor negociado deve ser >= 0.');

            $total_adicionais = array_sum(array_column($adicionais, 'valor'));
            $valor_total = $valor_negociado + $total_adicionais - $desconto;
            if ($desconto < 0) throw new Exception('Desconto deve ser >= 0.');
            if ($valor_total < 0) throw new Exception('Desconto não pode exceder o total.');

            $pdo->beginTransaction();

            if ($id_edit > 0) {
                $st = $pdo->prepare("
                    UPDATE vendas_pre_contratos
                    SET tipo_evento = ?,
                        origem = 'presencial',
                        nome_completo = ?, cpf = ?, rg = ?, telefone = ?, email = ?,
                        cep = ?, endereco_completo = ?, numero = ?, complemento = ?, bairro = ?, cidade = ?, estado = ?, pais = ?, instagram = ?,
                        data_evento = ?, unidade = ?, horario_inicio = ?, horario_termino = ?,
                        nome_noivos = ?, num_convidados = ?, como_conheceu = ?, como_conheceu_outro = ?,
                        pacote_contratado = ?, forma_pagamento = ?, valor_negociado = ?, desconto = ?, valor_total = ?,
                        observacoes_internas = ?, responsavel_comercial_id = ?,
                        status = ?, atualizado_em = NOW(), atualizado_por = ?
                    WHERE id = ?
                ");
                $st->execute([
                    $tipo_evento,
                    $nome_completo, $cpf, $rg, $telefone, $email,
                    $cep, $endereco, $numero, $complemento, $bairro, $cidade, $estado, $pais, $instagram,
                    $data_evento, $unidade, $hora_inicio, $hora_termino,
                    $nome_noivos, $num_convidados, $como_conheceu, ($como_conheceu === 'outro' ? $como_conheceu_outro : null),
                    $pacote_plano, $forma_pagamento, $valor_negociado, $desconto, $valor_total,
                    $observacoes_internas, $usuario_id,
                    $status, $usuario_id,
                    $id_edit
                ]);

                // atualizar adicionais (substitui)
                $pdo->prepare("DELETE FROM vendas_adicionais WHERE pre_contrato_id = ?")->execute([$id_edit]);
                $stAdd = $pdo->prepare("INSERT INTO vendas_adicionais (pre_contrato_id, item, valor) VALUES (?, ?, ?)");
                foreach ($adicionais as $a) {
                    $stAdd->execute([$id_edit, $a['item'], $a['valor']]);
                }

                $pre_id = $id_edit;
            } else {
                $st = $pdo->prepare("
                    INSERT INTO vendas_pre_contratos
                    (tipo_evento, origem, status,
                     nome_completo, cpf, rg, telefone, email,
                     cep, endereco_completo, numero, complemento, bairro, cidade, estado, pais, instagram,
                     data_evento, unidade, horario_inicio, horario_termino,
                     nome_noivos, num_convidados, como_conheceu, como_conheceu_outro,
                     pacote_contratado, forma_pagamento, valor_negociado, desconto, valor_total,
                     observacoes_internas, responsavel_comercial_id, criado_por_ip)
                    VALUES
                    (?, 'presencial', ?,
                     ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?, ?, ?,
                     ?, ?, ?)
                    RETURNING id
                ");
                $st->execute([
                    $tipo_evento,
                    $status,
                    $nome_completo, $cpf, $rg, $telefone, $email,
                    $cep, $endereco, $numero, $complemento, $bairro, $cidade, $estado, $pais, $instagram,
                    $data_evento, $unidade, $hora_inicio, $hora_termino,
                    $nome_noivos, $num_convidados, $como_conheceu, ($como_conheceu === 'outro' ? $como_conheceu_outro : null),
                    $pacote_plano, $forma_pagamento, $valor_negociado, $desconto, $valor_total,
                    $observacoes_internas, $usuario_id, $ip
                ]);
                $pre_id = (int)$st->fetchColumn();

                $stAdd = $pdo->prepare("INSERT INTO vendas_adicionais (pre_contrato_id, item, valor) VALUES (?, ?, ?)");
                foreach ($adicionais as $a) {
                    $stAdd->execute([$pre_id, $a['item'], $a['valor']]);
                }
            }

            // Upload (opcional)
            if (!empty($_FILES['anexo_orcamento']['tmp_name'])) {
                $uploader = new MagaluUpload();
                $result = $uploader->upload($_FILES['anexo_orcamento'], 'vendas/orcamentos');

                if (!empty($result['chave_storage']) || !empty($result['url'])) {
                    $st = $pdo->prepare("
                        INSERT INTO vendas_anexos
                        (pre_contrato_id, nome_original, nome_arquivo, chave_storage, url, mime_type, tamanho_bytes, upload_por)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $st->execute([
                        $pre_id,
                        $_FILES['anexo_orcamento']['name'],
                        $result['nome_original'] ?? $_FILES['anexo_orcamento']['name'],
                        $result['chave_storage'] ?? null,
                        $result['url'] ?? null,
                        $_FILES['anexo_orcamento']['type'] ?? null,
                        $_FILES['anexo_orcamento']['size'] ?? null,
                        $usuario_id
                    ]);
                }
            }

            $st = $pdo->prepare("INSERT INTO vendas_logs (pre_contrato_id, acao, usuario_id, detalhes) VALUES (?, 'lancamento_presencial_salvo', ?, ?)");
            $st->execute([$pre_id, $usuario_id, json_encode(['status' => $status], JSON_UNESCAPED_UNICODE)]);

            $pdo->commit();
            header('Location: index.php?page=vendas_lancamento_presencial&editar=' . $pre_id . '&ok=1');
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erros[] = $e->getMessage();
        }
    }
}

if (!empty($_GET['ok'])) {
    $mensagens[] = 'Lançamento salvo com sucesso!';
}

ob_start();
?>

<style>
.vendas-container{max-width:1480px;margin:0 auto;padding:1.25rem 1.5rem}
.vendas-header{margin-bottom:1rem;background:#f8fafc;border:1px solid #dbe3ef;border-radius:12px;padding:1rem 1.25rem}
.vendas-header h1{font-size:1.75rem;color:#1e3a8a;margin-bottom:.25rem}
.vendas-card{background:#fff;border-radius:14px;border:1px solid #dbe3ef;padding:1.5rem;box-shadow:0 14px 32px rgba(15,23,42,.06);margin-bottom:1rem}
.form-section-title{font-size:1.1rem;font-weight:700;color:#1e3a8a;margin:1.25rem 0 .75rem}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
@media (max-width:768px){.form-row{grid-template-columns:1fr}}
.form-group{margin-bottom:1rem}
.form-group label{display:block;margin-bottom:.35rem;font-weight:600;color:#374151}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:.75rem;border:1px solid #d1d5db;border-radius:8px}
.btn{padding:.6rem 1rem;border-radius:8px;border:none;cursor:pointer;font-weight:700}
.btn-primary{background:#2563eb;color:#fff}
.btn-secondary{background:#6b7280;color:#fff}
.btn-danger{background:#ef4444;color:#fff}
.required{color:#ef4444}
.alert{padding:1rem;border-radius:8px;margin-bottom:1rem}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.adicionais-table{width:100%;border-collapse:collapse;margin-top:.5rem}
.adicionais-table th,.adicionais-table td{border-bottom:1px solid #e5e7eb;padding:.5rem;text-align:left}
.tipo-evento-switch{display:flex;gap:.6rem;flex-wrap:wrap;margin:0 0 1rem}
.tipo-evento-option{position:relative;display:inline-block}
.tipo-evento-option input{position:absolute;opacity:0;pointer-events:none}
.tipo-evento-option span{display:inline-block;padding:.48rem .9rem;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#334155;font-weight:700;cursor:pointer;transition:all .15s ease}
.tipo-evento-option input:checked + span{background:#eff6ff;border-color:#2563eb;color:#1d4ed8}
.valor-base-badge{display:inline-block;padding:.25rem .5rem;border-radius:999px;font-size:.75rem;font-weight:700;background:#eef2ff;color:#3730a3}
</style>

<div class="vendas-container">
  <div class="vendas-header">
    <h1>Lançamento (Presencial)</h1>
    <p>Lance um pré-contrato rapidamente. Esta tela <strong>não</strong> aprova e <strong>não</strong> cria evento na ME.</p>
  </div>

  <?php foreach ($mensagens as $m): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($m); ?></div>
  <?php endforeach; ?>
  <?php foreach ($erros as $e): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
  <?php endforeach; ?>

  <div class="vendas-card">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="salvar_presencial">
      <input type="hidden" name="pre_contrato_id" value="<?php echo (int)($registro['id'] ?? 0); ?>">

      <div class="form-section-title">Tipo de evento</div>
      <div class="tipo-evento-switch">
        <?php foreach ($tipos_evento as $tipo_key => $tipo_cfg): ?>
          <label class="tipo-evento-option">
            <input type="radio" name="tipo_evento" value="<?php echo htmlspecialchars($tipo_key); ?>" <?php echo $tipo_evento_form === $tipo_key ? 'checked' : ''; ?>>
            <span><?php echo htmlspecialchars($tipo_cfg['label']); ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="form-section-title">Cliente</div>
      <div class="form-group">
        <label>Nome <span class="required">*</span></label>
        <input name="nome_completo" required value="<?php echo htmlspecialchars($registro['nome_completo'] ?? ''); ?>">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>E-mail <span class="required">*</span></label>
          <input type="email" name="email" required value="<?php echo htmlspecialchars($registro['email'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Telefone/WhatsApp <span class="required">*</span></label>
          <input name="telefone" required value="<?php echo htmlspecialchars($registro['telefone'] ?? ''); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>CPF <span class="required">*</span></label>
          <input name="cpf" required value="<?php echo htmlspecialchars($registro['cpf'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>RG <span class="required">*</span></label>
          <input name="rg" required value="<?php echo htmlspecialchars($registro['rg'] ?? ''); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>CEP <span class="required">*</span></label>
          <input name="cep" required value="<?php echo htmlspecialchars($registro['cep'] ?? ''); ?>">
          <small class="cep-status" style="color:#64748b;display:block;margin-top:.35rem;"></small>
        </div>
        <div class="form-group">
          <label>Instagram</label>
          <input name="instagram" value="<?php echo htmlspecialchars($registro['instagram'] ?? ''); ?>">
        </div>
      </div>
      <div class="form-group">
        <label>Endereço <span class="required">*</span></label>
        <input name="endereco_completo" required value="<?php echo htmlspecialchars($registro['endereco_completo'] ?? ''); ?>">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Número <span class="required">*</span></label>
          <input name="numero" required value="<?php echo htmlspecialchars($registro['numero'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Complemento</label>
          <input name="complemento" value="<?php echo htmlspecialchars($registro['complemento'] ?? ''); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Bairro <span class="required">*</span></label>
          <input name="bairro" required value="<?php echo htmlspecialchars($registro['bairro'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Cidade <span class="required">*</span></label>
          <input name="cidade" required value="<?php echo htmlspecialchars($registro['cidade'] ?? ''); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Estado (UF) <span class="required">*</span></label>
          <input name="estado" maxlength="2" required value="<?php echo htmlspecialchars($registro['estado'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>País</label>
          <input name="pais" value="<?php echo htmlspecialchars($registro['pais'] ?? 'Brasil'); ?>">
        </div>
      </div>

      <div class="form-section-title" id="titulo_evento_tipo">Evento (<?php echo htmlspecialchars($tipo_evento_cfg['label']); ?>)</div>
      <div class="form-row">
        <div class="form-group">
          <label>Data <span class="required">*</span></label>
          <input type="date" name="data_evento" required value="<?php echo htmlspecialchars($registro['data_evento'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Local do evento (Unidade) <span class="required">*</span></label>
          <select name="unidade" required>
            <option value="">Selecione...</option>
            <?php foreach ($locais_mapeados as $l): ?>
              <option value="<?php echo htmlspecialchars($l['me_local_nome']); ?>" <?php echo (($registro['unidade'] ?? '') === $l['me_local_nome']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($l['me_local_nome']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($locais_mapeados)): ?>
            <small class="required">Nenhum local mapeado. Ajuste em Logística &gt; Conexão.</small>
          <?php endif; ?>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Hora início <span class="required">*</span></label>
          <input type="time" name="horario_inicio" required value="<?php echo htmlspecialchars($registro['horario_inicio'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Hora término <span class="required">*</span></label>
          <input type="time" name="horario_termino" required value="<?php echo htmlspecialchars($registro['horario_termino'] ?? ''); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label><span id="label_nome_evento_tipo"><?php echo htmlspecialchars($tipo_evento_cfg['nome_label']); ?></span> <span class="required">*</span></label>
          <input id="nome_noivos" name="nome_noivos" required placeholder="<?php echo htmlspecialchars($tipo_evento_cfg['nome_placeholder']); ?>" value="<?php echo htmlspecialchars($registro['nome_noivos'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Nº convidados <span class="required">*</span></label>
          <input type="number" min="1" name="num_convidados" required value="<?php echo htmlspecialchars((string)($registro['num_convidados'] ?? '')); ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Como conheceu <span class="required">*</span></label>
          <select name="como_conheceu" id="como_conheceu">
            <option value="">Selecione...</option>
            <?php
              $cc = (string)($registro['como_conheceu'] ?? '');
              $opts = ['instagram'=>'Instagram','facebook'=>'Facebook','google'=>'Google','indicacao'=>'Indicação','outro'=>'Outro'];
              foreach ($opts as $k=>$label):
            ?>
              <option value="<?php echo $k; ?>" <?php echo $cc === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" id="cc_outro_wrap" style="<?php echo ($cc === 'outro') ? '' : 'display:none;'; ?>">
          <label>Outro (qual?) <span class="required">*</span></label>
          <input name="como_conheceu_outro" id="como_conheceu_outro" value="<?php echo htmlspecialchars($registro['como_conheceu_outro'] ?? ''); ?>">
        </div>
      </div>

      <div class="form-section-title">Pacote/Plano (interno)</div>
      <div class="form-group">
        <label>Pacote/Plano escolhido <span class="required">*</span></label>
        <textarea name="pacote_plano" rows="3" required><?php echo htmlspecialchars($registro['pacote_contratado'] ?? ''); ?></textarea>
        <small style="color:#64748b">Não é enviado para a ME.</small>
      </div>

      <div class="form-section-title">Comercial</div>
      <div class="form-row">
        <div class="form-group">
          <label>Forma de pagamento</label>
          <input name="forma_pagamento" value="<?php echo htmlspecialchars($registro['forma_pagamento'] ?? ''); ?>">
        </div>
        <div class="form-group">
          <label>Status</label>
          <select name="status">
            <?php $st = (string)($registro['status'] ?? 'aguardando_conferencia'); ?>
            <option value="aguardando_conferencia" <?php echo $st==='aguardando_conferencia'?'selected':''; ?>>Aguardando conferência</option>
            <option value="pronto_aprovacao" <?php echo $st==='pronto_aprovacao'?'selected':''; ?>>Pronto para aprovação</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label>Itens e valores</label>
        <small style="display:block;color:#64748b;margin:.2rem 0 .6rem;">Preencha item e valor por linha. O total considera todas as linhas e aplica desconto, se houver.</small>
        <button type="button" class="btn btn-secondary" onclick="addAdicional()">+ Adicionar linha</button>
        <table class="adicionais-table" id="tAdicionais">
          <thead><tr><th>Item</th><th>Valor (R$)</th><th></th></tr></thead>
          <tbody>
            <tr>
              <td>Pacote/Plano principal</td>
              <td>
                <input
                  type="text"
                  inputmode="numeric"
                  class="money-input"
                  name="valor_negociado"
                  id="valor_negociado"
                  required
                  value="<?php echo htmlspecialchars(vendas_money_brl($registro['valor_negociado'] ?? 0)); ?>"
                >
              </td>
              <td><span class="valor-base-badge">Base</span></td>
            </tr>
            <?php foreach ($adicionais_editar as $i=>$a): ?>
              <tr>
                <td><input name="adicionais[<?php echo $i; ?>][item]" value="<?php echo htmlspecialchars($a['item']); ?>"></td>
                <td>
                  <input
                    type="text"
                    inputmode="numeric"
                    class="money-input"
                    name="adicionais[<?php echo $i; ?>][valor]"
                    value="<?php echo htmlspecialchars(vendas_money_brl($a['valor'])); ?>"
                  >
                </td>
                <td><button type="button" class="btn btn-danger" onclick="rmRow(this)">Remover</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Desconto aplicado (R$)</label>
          <input type="text" inputmode="numeric" class="money-input" name="desconto" id="desconto" value="<?php echo htmlspecialchars(vendas_money_brl($registro['desconto'] ?? 0)); ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Subtotal das linhas</label>
          <input id="valor_subtotal_display" disabled value="R$ 0,00">
        </div>
        <div class="form-group">
          <label>Desconto</label>
          <input id="desconto_display" disabled value="R$ 0,00">
        </div>
      </div>
      <div class="form-group">
        <label>Total final</label>
        <input id="valor_total_display" disabled value="R$ 0,00" style="font-weight:700">
      </div>

      <div class="form-section-title">Anexos (Magalu)</div>
      <div class="form-group">
        <label>Upload orçamento/proposta</label>
        <input type="file" name="anexo_orcamento" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
        <small style="color:#64748b">Anexo opcional. Você pode salvar sem arquivo e anexar depois, se necessário.</small>
      </div>
      <?php if (!empty($anexos_editar)): ?>
        <div class="form-group">
          <label>Anexos existentes</label>
          <ul>
            <?php foreach ($anexos_editar as $ax): ?>
              <li>
                <a target="_blank" href="<?php echo htmlspecialchars($ax['url'] ?? '#'); ?>">
                  <?php echo htmlspecialchars($ax['nome_original'] ?? 'arquivo'); ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="form-group">
        <label>Observações internas</label>
        <textarea name="observacoes_internas" rows="3"><?php echo htmlspecialchars($registro['observacoes_internas'] ?? ''); ?></textarea>
      </div>

      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">Salvar</button>
        <a class="btn btn-secondary" href="index.php?page=vendas_lancamento_presencial&tipo_evento=<?php echo urlencode($tipo_evento_form); ?>">Novo lançamento</a>
        <?php if (!empty($registro['id'])): ?>
          <a class="btn btn-secondary" href="index.php?page=vendas_pre_contratos&editar=<?php echo (int)$registro['id']; ?>">Abrir na listagem</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<script>
let idxAd = <?php echo (int)count($adicionais_editar); ?>;
function formatMoneyFromDigits(digits){
  if (!digits) return '';
  const cents = digits.slice(-2).padStart(2, '0');
  let ints = digits.slice(0, -2);
  ints = ints.replace(/^0+(?=\d)/, '');
  if (!ints) ints = '0';
  ints = ints.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return `${ints},${cents}`;
}
function parseMoneyValue(value){
  const raw = String(value || '').trim();
  if (!raw) return 0;
  const normalized = raw.replace(/\./g, '').replace(',', '.').replace(/[^\d.-]/g, '');
  const num = parseFloat(normalized);
  return Number.isFinite(num) ? num : 0;
}
function formatMoneyDisplay(value){
  const num = Number.isFinite(value) ? value : 0;
  return 'R$ ' + num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function bindMoneyInput(el){
  if (!el || el.dataset.moneyBound === '1') return;
  el.dataset.moneyBound = '1';
  el.addEventListener('input', function(){
    const digits = (this.value || '').replace(/\D/g, '');
    this.value = formatMoneyFromDigits(digits);
    calcTotal();
  });
  el.addEventListener('blur', function(){
    const digits = (this.value || '').replace(/\D/g, '');
    this.value = digits ? formatMoneyFromDigits(digits) : '0,00';
    calcTotal();
  });
}
function initMoneyInputs(scope){
  (scope || document).querySelectorAll('.money-input').forEach(bindMoneyInput);
}
function addAdicional(){
  const tb = document.querySelector('#tAdicionais tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input name="adicionais[${idxAd}][item]"></td>
    <td><input type="text" inputmode="numeric" class="money-input" name="adicionais[${idxAd}][valor]"></td>
    <td><button type="button" class="btn btn-danger" onclick="rmRow(this)">Remover</button></td>
  `;
  tb.appendChild(tr);
  initMoneyInputs(tr);
  idxAd++;
  calcTotal();
}
function rmRow(btn){
  btn.closest('tr').remove();
  calcTotal();
}
function calcTotal(){
  const v = parseMoneyValue(document.getElementById('valor_negociado')?.value || 0);
  const d = parseMoneyValue(document.getElementById('desconto')?.value || 0);
  let add = 0;
  document.querySelectorAll('#tAdicionais input[name*="[valor]"]').forEach(i => add += parseMoneyValue(i.value));
  const subtotal = v + add;
  const total = subtotal - d;
  document.getElementById('valor_subtotal_display').value = formatMoneyDisplay(subtotal);
  document.getElementById('desconto_display').value = formatMoneyDisplay(d);
  document.getElementById('valor_total_display').value = formatMoneyDisplay(total);
}
const tipoEventoConfig = <?php
  echo json_encode(array_map(static function (array $cfg): array {
      return [
          'label' => (string)$cfg['label'],
          'nome_label' => (string)$cfg['nome_label'],
          'nome_placeholder' => (string)$cfg['nome_placeholder'],
      ];
  }, $tipos_evento), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>;
function atualizarCamposTipoEvento(){
  const selecionado = document.querySelector('input[name="tipo_evento"]:checked')?.value || 'casamento';
  const cfg = tipoEventoConfig[selecionado] || tipoEventoConfig.casamento;
  if (!cfg) return;
  const titulo = document.getElementById('titulo_evento_tipo');
  const label = document.getElementById('label_nome_evento_tipo');
  const input = document.getElementById('nome_noivos');
  if (titulo) titulo.textContent = `Evento (${cfg.label})`;
  if (label) label.textContent = cfg.nome_label;
  if (input) input.placeholder = cfg.nome_placeholder;
}
document.querySelectorAll('input[name="tipo_evento"]').forEach(el => el.addEventListener('change', atualizarCamposTipoEvento));
document.getElementById('como_conheceu')?.addEventListener('change', function(){
  const wrap = document.getElementById('cc_outro_wrap');
  const input = document.getElementById('como_conheceu_outro');
  if (this.value === 'outro'){
    wrap.style.display = '';
    input.required = true;
  } else {
    wrap.style.display = 'none';
    input.required = false;
    input.value = '';
  }
});

// Máscaras para evitar inconsistência de pontuação
document.querySelector('input[name="cpf"]')?.addEventListener('input', function(e){
  let value = (e.target.value || '').replace(/\D/g, '').slice(0, 11);
  value = value.replace(/(\d{3})(\d)/, '$1.$2');
  value = value.replace(/(\d{3})(\d)/, '$1.$2');
  value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
  e.target.value = value;
});
document.querySelector('input[name="telefone"]')?.addEventListener('input', function(e){
  let value = (e.target.value || '').replace(/\D/g, '').slice(0, 11);
  if (value.length <= 10) {
    value = value.replace(/(\d{2})(\d)/, '($1) $2');
    value = value.replace(/(\d{4})(\d)/, '$1-$2');
  } else {
    value = value.replace(/(\d{2})(\d)/, '($1) $2');
    value = value.replace(/(\d{5})(\d)/, '$1-$2');
  }
  e.target.value = value;
});
initMoneyInputs();
calcTotal();
atualizarCamposTipoEvento();

// Busca automática de CEP (ViaCEP via endpoint interno)
let cepTimeout = null;
let lastCepBuscado = '';
async function buscarCepPreencher(cepDigits){
  const status = document.querySelector('.cep-status');
  const endereco = document.querySelector('input[name="endereco_completo"]');
  const bairro = document.querySelector('input[name="bairro"]');
  const cidade = document.querySelector('input[name="cidade"]');
  const estado = document.querySelector('input[name="estado"]');
  const complemento = document.querySelector('input[name="complemento"]');
  const numero = document.querySelector('input[name="numero"]');
  if (!status) return;
  status.textContent = 'Buscando CEP...';
  try {
    const resp = await fetch(`buscar_cep_endpoint.php?cep=${encodeURIComponent(cepDigits)}`);
    const data = await resp.json();
    if (!data?.success || !data?.data){
      status.textContent = data?.message ? String(data.message) : 'CEP não encontrado.';
      return;
    }
    const d = data.data;
    if (endereco && !endereco.value) endereco.value = d.logradouro || '';
    if (bairro && !bairro.value) bairro.value = d.bairro || '';
    if (cidade && !cidade.value) cidade.value = d.cidade || '';
    if (estado && !estado.value) estado.value = (d.estado || '').toUpperCase();
    if (complemento && !complemento.value) complemento.value = d.complemento || '';
    status.textContent = '';
    if (numero) numero.focus();
  } catch(e){
    status.textContent = 'Erro ao buscar CEP. Tente novamente.';
  }
}
function handleCepAuto(){
  const cepEl = document.querySelector('input[name="cep"]');
  if (!cepEl) return;
  // aplica máscara simples 00000-000
  let digits = (cepEl.value || '').replace(/\D/g,'').slice(0,8);
  if (digits.length > 5) {
    cepEl.value = digits.slice(0,5) + '-' + digits.slice(5);
  } else {
    cepEl.value = digits;
  }
  if (digits.length !== 8) return;
  if (digits === lastCepBuscado) return;
  lastCepBuscado = digits;
  buscarCepPreencher(digits);
}
document.querySelector('input[name="cep"]')?.addEventListener('blur', handleCepAuto);
document.querySelector('input[name="cep"]')?.addEventListener('input', function(){
  clearTimeout(cepTimeout);
  cepTimeout = setTimeout(handleCepAuto, 350);
});
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Comercial');
echo $conteudo;
endSidebar();
