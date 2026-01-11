<?php
// estoque_contar.php
// Assistente de contagem de estoque

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();

$contagem_id = (int)($_GET['id'] ?? 0);
$msg = '';
$err = '';

// Carregar contagem
$contagem = null;
if ($contagem_id > 0) {
    $stmt = $pdo->prepare("
        SELECT c.*, u.nome as criado_por_nome 
        FROM estoque_contagens c
        LEFT JOIN usuarios u ON u.id = c.criada_por
        WHERE c.id = :id
    ");
    $stmt->execute([':id' => $contagem_id]);
    $contagem = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contagem) {
        header('Location: estoque_contagens.php');
        exit;
    }
}

// Processar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    try {
        if ($acao === 'save_item') {
            $insumo_id = (int)($_POST['insumo_id'] ?? 0);
            $unidade_id_digitada = (int)($_POST['unidade_id_digitada'] ?? 0);
            $qtd_digitada = (float)($_POST['qtd_digitada'] ?? 0);
            $observacao = trim($_POST['observacao'] ?? '');
            
            if ($insumo_id <= 0 || $unidade_id_digitada <= 0 || $qtd_digitada <= 0) {
                throw new Exception('Dados inválidos.');
            }
            
            // Buscar dados do insumo e unidades
            $stmt = $pdo->prepare("
                SELECT i.unidade_padrao, i.fator_correcao,
                       u1.fator_base as fator_insumo,
                       u2.fator_base as fator_digitada
                FROM lc_insumos i
                LEFT JOIN lc_unidades u1 ON u1.simbolo = i.unidade_padrao
                LEFT JOIN lc_unidades u2 ON u2.id = :unidade_id
                WHERE i.id = :insumo_id
            ");
            $stmt->execute([':insumo_id' => $insumo_id, ':unidade_id' => $unidade_id_digitada]);
            $dados = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$dados) {
                throw new Exception('Insumo ou unidade não encontrados.');
            }
            
            // Calcular conversão
            $fator_aplicado = $dados['fator_digitada'] / $dados['fator_insumo'];
            $qtd_contada_base = lc_convert_to_base($qtd_digitada, $dados['fator_digitada'], $dados['fator_insumo']);
            
            // Verificar se já existe item
            $stmt = $pdo->prepare("SELECT id FROM estoque_contagem_itens WHERE contagem_id = :contagem_id AND insumo_id = :insumo_id");
            $stmt->execute([':contagem_id' => $contagem_id, ':insumo_id' => $insumo_id]);
            $item_existente = $stmt->fetch();
            
            if ($item_existente) {
                // Atualizar
                $stmt = $pdo->prepare("
                    UPDATE estoque_contagem_itens 
                    SET unidade_id_digitada = :unidade_id, qtd_digitada = :qtd_digitada,
                        fator_aplicado = :fator_aplicado, qtd_contada_base = :qtd_contada_base,
                        observacao = :observacao
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':unidade_id' => $unidade_id_digitada,
                    ':qtd_digitada' => $qtd_digitada,
                    ':fator_aplicado' => $fator_aplicado,
                    ':qtd_contada_base' => $qtd_contada_base,
                    ':observacao' => $observacao,
                    ':id' => $item_existente['id']
                ]);
            } else {
                // Inserir
                $stmt = $pdo->prepare("
                    INSERT INTO estoque_contagem_itens 
                    (contagem_id, insumo_id, unidade_id_digitada, qtd_digitada, fator_aplicado, qtd_contada_base, observacao)
                    VALUES (:contagem_id, :insumo_id, :unidade_id, :qtd_digitada, :fator_aplicado, :qtd_contada_base, :observacao)
                ");
                $stmt->execute([
                    ':contagem_id' => $contagem_id,
                    ':insumo_id' => $insumo_id,
                    ':unidade_id' => $unidade_id_digitada,
                    ':qtd_digitada' => $qtd_digitada,
                    ':fator_aplicado' => $fator_aplicado,
                    ':qtd_contada_base' => $qtd_contada_base,
                    ':observacao' => $observacao
                ]);
            }
            
            $msg = 'Item salvo com sucesso!';
            
        } elseif ($acao === 'save_header' && $contagem['status'] === 'rascunho') {
            $data_ref = $_POST['data_ref'] ?? '';
            $observacao = trim($_POST['observacao'] ?? '');
            
            if (!$data_ref) {
                throw new Exception('Data de referência é obrigatória.');
            }
            
            $stmt = $pdo->prepare("UPDATE estoque_contagens SET data_ref = :data_ref, observacao = :observacao WHERE id = :id");
            $stmt->execute([':data_ref' => $data_ref, ':observacao' => $observacao, ':id' => $contagem_id]);
            
            $msg = 'Cabeçalho atualizado com sucesso!';
            
        } elseif ($acao === 'close' && lc_can_close_contagem()) {
            $stmt = $pdo->prepare("UPDATE estoque_contagens SET status = 'fechada' WHERE id = :id");
            $stmt->execute([':id' => $contagem_id]);
            
            $msg = 'Contagem fechada com sucesso!';
            header('Location: estoque_contagens.php');
            exit;
        }
        
        // Recarregar dados
        $stmt = $pdo->prepare("
            SELECT c.*, u.nome as criado_por_nome 
            FROM estoque_contagens c
            LEFT JOIN usuarios u ON u.id = c.criada_por
            WHERE c.id = :id
        ");
        $stmt->execute([':id' => $contagem_id]);
        $contagem = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $err = 'Erro: ' . $e->getMessage();
    }
}

// Se não há contagem, criar nova
if (!$contagem) {
    if (!lc_can_create_contagem()) {
        header('Location: estoque_contagens.php?error=permission_denied');
        exit;
    }
    
    $data_ref = date('Y-m-d'); // Segunda-feira da semana atual
    $stmt = $pdo->prepare("
        INSERT INTO estoque_contagens (data_ref, criada_por, status) 
        VALUES (:data_ref, :criada_por, 'rascunho')
    ");
    $stmt->execute([
        ':data_ref' => $data_ref,
        ':criada_por' => $_SESSION['usuario_id'] ?? 1
    ]);
    $contagem_id = $pdo->lastInsertId();
    
    header("Location: estoque_contar.php?id=$contagem_id");
    exit;
}

// Carregar dados
$unidades = lc_load_unidades($pdo);
$insumos_por_categoria = lc_load_insumos_por_categoria($pdo);
$categoria_filtro = $_GET['categoria'] ?? '';

// Carregar itens já contados
$stmt = $pdo->prepare("
    SELECT ci.*, i.nome as insumo_nome, i.unidade_padrao,
           u1.nome as unidade_digitada_nome, u1.simbolo as unidade_digitada_simbolo,
           u2.simbolo as unidade_base_simbolo
    FROM estoque_contagem_itens ci
    JOIN lc_insumos i ON i.id = ci.insumo_id
    LEFT JOIN lc_unidades u1 ON u1.id = ci.unidade_id_digitada
    LEFT JOIN lc_unidades u2 ON u2.simbolo = i.unidade_padrao
    WHERE ci.contagem_id = :contagem_id
    ORDER BY i.nome
");
$stmt->execute([':contagem_id' => $contagem_id]);
$itens_contados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular valor total (apenas para ADM)
$valor_total = 0;
if (lc_can_view_stock_value()) {
    $valor_total = lc_calcular_valor_estoque($pdo, $contagem_id);
}


?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contagem de Estoque - Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #d97706 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .valor-total {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            color: #1e3a8a;
        }
        .filters {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .categoria-section {
            margin-bottom: 30px;
        }
        .categoria-header {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px 8px 0 0;
            font-weight: 600;
            color: #1e3a8a;
        }
        .insumos-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #ddd;
        }
        .insumos-table th, .insumos-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .insumos-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .input-small {
            width: 100px;
        }
        .input-medium {
            width: 150px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        .btn-primary {
            background: #1e3a8a;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn:hover {
            opacity: 0.8;
        }
        .readonly {
            background: #f8f9fa;
            color: #6c757d;
        }
        .alert {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Contagem de Estoque #<?= $contagem['id'] ?></h1>
            <p>Data de referência: <?= h($contagem['data_ref']) ?> | 
               Status: <strong><?= ucfirst($contagem['status']) ?></strong> | 
               Criado por: <?= h($contagem['criado_por_nome'] ?: 'Sistema') ?></p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?= h($msg) ?></div>
        <?php endif; ?>

        <?php if ($err): ?>
            <div class="alert alert-error"><?= h($err) ?></div>
        <?php endif; ?>

        <?php if (lc_can_view_stock_value() && $valor_total > 0): ?>
            <div class="valor-total">
                💰 Valor Total em Estoque: R$ <?= number_format($valor_total, 2, ',', '.') ?>
            </div>
        <?php endif; ?>

        <?php if ($contagem['status'] === 'rascunho' && lc_can_edit_contagem()): ?>
            <form method="POST" class="filters">
                <input type="hidden" name="acao" value="save_header">
                <div style="display: flex; gap: 15px; align-items: end;">
                    <div>
                        <label>Data de referência:</label>
                        <input type="date" name="data_ref" value="<?= h($contagem['data_ref']) ?>" class="input" required>
                    </div>
                    <div style="flex: 1;">
                        <label>Observação:</label>
                        <input type="text" name="observacao" value="<?= h($contagem['observacao']) ?>" class="input" placeholder="Observações gerais da contagem">
                    </div>
                    <button type="submit" class="btn btn-primary">Salvar Cabeçalho</button>
                </div>
            </form>
        <?php endif; ?>

        <div class="filters">
            <form method="GET">
                <input type="hidden" name="id" value="<?= $contagem_id ?>">
                <div style="display: flex; gap: 15px; align-items: end;">
                    <div>
                        <label>Filtrar por categoria:</label>
                        <select name="categoria" class="input">
                            <option value="">Todas as categorias</option>
                            <?php foreach (array_keys($insumos_por_categoria) as $categoria): ?>
                                <option value="<?= h($categoria) ?>" <?= $categoria_filtro === $categoria ? 'selected' : '' ?>>
                                    <?= h($categoria) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary">Filtrar</button>
                </div>
            </form>
            
            <?php if (in_array($perfil, ['ADM', 'OPER'])): ?>
                <div style="margin-top: 15px; padding: 15px; background: #e7f3ff; border-radius: 8px;">
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <button type="button" onclick="abrirScanner()" class="btn btn-primary">
                            📷 Escanear Código
                        </button>
                        <div style="display: flex; gap: 5px; align-items: center;">
                            <input type="text" id="pesquisarEAN" placeholder="Digite ou cole o código EAN" 
                                   style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; width: 250px;">
                            <button type="button" onclick="pesquisarEAN()" class="btn btn-secondary">
                                🔍 Buscar
                            </button>
                        </div>
                        <div id="statusScanner" style="display: none; color: #1e3a8a; font-weight: bold;">
                            📹 Câmera ativa / Clique para pausar
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php foreach ($insumos_por_categoria as $categoria_nome => $insumos): ?>
            <?php if ($categoria_filtro && $categoria_filtro !== $categoria_nome) continue; ?>
            
            <div class="categoria-section">
                <div class="categoria-header">
                    <?= h($categoria_nome) ?> (<?= count($insumos) ?> insumos)
                </div>
                
                <table class="insumos-table">
                    <thead>
                        <tr>
                            <th>Insumo</th>
                            <th>Unidade Base</th>
                            <th>Quantidade</th>
                            <th>Unidade</th>
                            <th>Convertido</th>
                            <th>Observação</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($insumos as $insumo): ?>
                            <?php
                            // Verificar se já foi contado
                            $item_contado = null;
                            foreach ($itens_contados as $item) {
                                if ($item['insumo_id'] == $insumo['id']) {
                                    $item_contado = $item;
                                    break;
                                }
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h($insumo['nome']) ?></strong>
                                    <?php if ($item_contado): ?>
                                        <br><small style="color: #28a745;">✓ Contado</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($insumo['unidade_simbolo']) ?></td>
                                
                                <?php if ($contagem['status'] === 'rascunho' && lc_can_edit_contagem()): ?>
                                    <form method="POST">
                                        <input type="hidden" name="acao" value="save_item">
                                        <input type="hidden" name="insumo_id" value="<?= $insumo['id'] ?>">
                                        
                                        <td>
                                            <input type="number" name="qtd_digitada" 
                                                   value="<?= $item_contado ? h($item_contado['qtd_digitada']) : '' ?>"
                                                   step="0.000001" min="0" class="input input-small" required>
                                        </td>
                                        <td>
                                            <select name="unidade_id_digitada" class="input input-medium" required>
                                                <?php foreach ($unidades as $unidade): ?>
                                                    <option value="<?= $unidade['id'] ?>" 
                                                            <?= $item_contado && $item_contado['unidade_id_digitada'] == $unidade['id'] ? 'selected' : '' ?>>
                                                        <?= h($unidade['nome']) ?> (<?= h($unidade['simbolo']) ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <?php if ($item_contado): ?>
                                                <strong><?= number_format($item_contado['qtd_contada_base'], 6, ',', '.') ?> <?= h($insumo['unidade_simbolo']) ?></strong>
                                            <?php else: ?>
                                                <em>Salve para calcular</em>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <input type="text" name="observacao" 
                                                   value="<?= $item_contado ? h($item_contado['observacao']) : '' ?>"
                                                   class="input" placeholder="Observação">
                                        </td>
                                        <td>
                                            <button type="submit" class="btn btn-success">Salvar</button>
                                        </td>
                                    </form>
                                <?php else: ?>
                                    <td><?= $item_contado ? number_format($item_contado['qtd_digitada'], 6, ',', '.') : '-' ?></td>
                                    <td><?= $item_contado ? h($item_contado['unidade_digitada_simbolo']) : '-' ?></td>
                                    <td>
                                        <?php if ($item_contado): ?>
                                            <strong><?= number_format($item_contado['qtd_contada_base'], 6, ',', '.') ?> <?= h($insumo['unidade_simbolo']) ?></strong>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $item_contado ? h($item_contado['observacao']) : '-' ?></td>
                                    <td>-</td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <div style="margin-top: 30px; text-align: center;">
            <?php if ($contagem['status'] === 'rascunho' && lc_can_close_contagem()): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="acao" value="close">
                    <button type="submit" class="btn btn-success" 
                            onclick="return confirm('Fechar esta contagem? Esta ação não pode ser desfeita.')">
                        Fechar Contagem
                    </button>
                </form>
            <?php endif; ?>
            
            <a href="estoque_contagens.php" class="btn btn-secondary">← Voltar para Lista</a>
        </div>
    </div>

    <!-- Modal do Scanner -->
    <div id="scannerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 20px; max-width: 90%; max-height: 90%;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin: 0;">Scanner de Código de Barras</h3>
                <button onclick="fecharScanner()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
            </div>
            
            <div style="text-align: center; margin-bottom: 15px;">
                <video id="scannerVideo" style="width: 100%; max-width: 400px; border: 2px solid #ddd; border-radius: 4px;"></video>
            </div>
            
            <div style="text-align: center; margin-bottom: 15px;">
                <p style="color: #666; font-size: 14px;">Aponte o código de barras para a câmera<br>Mantenha ~10-20 cm de distância, boa iluminação</p>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button id="btnPausar" onclick="pausarScanner()" class="btn btn-secondary" style="display: none;">
                    ⏸️ Pausar
                </button>
                <button id="btnRetomar" onclick="retomarScanner()" class="btn btn-primary" style="display: none;">
                    ▶️ Retomar
                </button>
                <button onclick="alternarCamera()" class="btn btn-secondary">
                    🔄 Alternar Câmera
                </button>
                <button onclick="fecharScanner()" class="btn btn-secondary">
                    ❌ Fechar
                </button>
            </div>
            
            <div id="scannerResult" style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px; display: none;">
                <p id="scannerResultText"></p>
            </div>
        </div>
    </div>

    <!-- Modal de Associação de EAN -->
    <div id="associarModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1001;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; padding: 20px; max-width: 500px;">
            <h3 style="margin-top: 0;">Código não cadastrado</h3>
            <p>O código <strong id="codigoNaoCadastrado"></strong> não está associado a nenhum insumo.</p>
            <p>Deseja associar este código ao insumo selecionado?</p>
            
            <div style="margin-top: 20px; text-align: center;">
                <button onclick="associarEAN()" class="btn btn-primary">✅ Associar</button>
                <button onclick="fecharAssociarModal()" class="btn btn-secondary">❌ Cancelar</button>
            </div>
        </div>
    </div>

    <!-- Scripts do Scanner -->
    <script src="https://unpkg.com/@zxing/library@latest"></script>
    <script>
        let codeReader = null;
        let isScanning = false;
        let currentStream = null;
        let currentEAN = null;
        let currentInsumoId = null;
        let lastScanTime = 0;

        // Mapear insumos por EAN
        const insumosPorEAN = {};
        <?php foreach ($insumos_por_categoria as $categoria => $insumos): ?>
            <?php foreach ($insumos as $insumo): ?>
                <?php if (!empty($insumo['ean_code'])): ?>
                    insumosPorEAN['<?= h($insumo['ean_code']) ?>'] = {
                        id: <?= $insumo['id'] ?>,
                        nome: '<?= h($insumo['nome']) ?>',
                        unidade: '<?= h($insumo['unidade_simbolo']) ?>'
                    };
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        function abrirScanner() {
            document.getElementById('scannerModal').style.display = 'block';
            document.getElementById('statusScanner').style.display = 'block';
            iniciarScanner();
        }

        function fecharScanner() {
            pararScanner();
            document.getElementById('scannerModal').style.display = 'none';
            document.getElementById('statusScanner').style.display = 'none';
        }

        function pausarScanner() {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            isScanning = false;
            document.getElementById('btnPausar').style.display = 'none';
            document.getElementById('btnRetomar').style.display = 'inline-block';
        }

        function retomarScanner() {
            iniciarScanner();
        }

        function alternarCamera() {
            pararScanner();
            setTimeout(() => {
                iniciarScanner();
            }, 500);
        }

        function pararScanner() {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            isScanning = false;
        }

        async function iniciarScanner() {
            try {
                if (!codeReader) {
                    codeReader = new ZXing.BrowserMultiFormatReader();
                }

                const videoElement = document.getElementById('scannerVideo');
                
                // Tentar câmera traseira primeiro, depois frontal
                const constraints = {
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    }
                };

                currentStream = await navigator.mediaDevices.getUserMedia(constraints);
                videoElement.srcObject = currentStream;
                
                isScanning = true;
                document.getElementById('btnPausar').style.display = 'inline-block';
                document.getElementById('btnRetomar').style.display = 'none';

                // Iniciar decodificação
                codeReader.decodeFromVideoDevice(undefined, videoElement, (result, err) => {
                    if (result) {
                        const now = Date.now();
                        if (now - lastScanTime < 1000) return; // Throttle
                        lastScanTime = now;
                        
                        processarCodigo(result.getText());
                    }
                });

            } catch (error) {
                console.error('Erro ao acessar câmera:', error);
                alert('Erro ao acessar a câmera. Verifique as permissões ou use a busca manual.');
                fecharScanner();
            }
        }

        function processarCodigo(codigo) {
            // Normalizar código
            const ean = codigo.replace(/\s/g, '');
            
            if (insumosPorEAN[ean]) {
                // Código encontrado
                const insumo = insumosPorEAN[ean];
                focarInsumo(insumo.id, insumo.nome, insumo.unidade);
                mostrarResultado(`✅ Item identificado: ${insumo.nome} (${insumo.unidade})`, 'success');
            } else {
                // Código não encontrado - oferecer associação
                currentEAN = ean;
                document.getElementById('codigoNaoCadastrado').textContent = ean;
                document.getElementById('associarModal').style.display = 'block';
                mostrarResultado(`❌ Código não cadastrado: ${ean}`, 'warning');
            }
        }

        function focarInsumo(insumoId, nome, unidade) {
            // Encontrar a linha do insumo na tabela
            const linhas = document.querySelectorAll('tr');
            for (let linha of linhas) {
                const inputInsumo = linha.querySelector('input[name="insumo_id"]');
                if (inputInsumo && inputInsumo.value == insumoId) {
                    // Rolar até a linha
                    linha.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    
                    // Destacar a linha
                    linha.style.backgroundColor = '#e7f3ff';
                    setTimeout(() => {
                        linha.style.backgroundColor = '';
                    }, 3000);
                    
                    // Focar no campo de quantidade
                    const qtdInput = linha.querySelector('input[name="qtd_digitada"]');
                    if (qtdInput) {
                        setTimeout(() => {
                            qtdInput.focus();
                            qtdInput.select();
                        }, 500);
                    }
                    
                    break;
                }
            }
        }

        function mostrarResultado(texto, tipo) {
            const resultDiv = document.getElementById('scannerResult');
            const resultText = document.getElementById('scannerResultText');
            
            resultText.textContent = texto;
            resultDiv.style.display = 'block';
            resultDiv.style.backgroundColor = tipo === 'success' ? '#d4edda' : '#fff3cd';
            resultDiv.style.color = tipo === 'success' ? '#155724' : '#856404';
            
            setTimeout(() => {
                resultDiv.style.display = 'none';
            }, 3000);
        }

        function pesquisarEAN() {
            const ean = document.getElementById('pesquisarEAN').value.replace(/\s/g, '');
            if (ean) {
                processarCodigo(ean);
            }
        }

        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'k') {
                e.preventDefault();
                abrirScanner();
            } else if (e.key === 'Escape') {
                fecharScanner();
                fecharAssociarModal();
            } else if (e.key === 'Enter' && document.activeElement.id === 'pesquisarEAN') {
                pesquisarEAN();
            }
        });

        // Associação de EAN
        function associarEAN() {
            if (currentEAN && currentInsumoId) {
                // Aqui você faria uma requisição AJAX para salvar a associação
                // Por enquanto, vamos apenas mostrar uma mensagem
                alert(`EAN ${currentEAN} será associado ao insumo selecionado.`);
                fecharAssociarModal();
            }
        }

        function fecharAssociarModal() {
            document.getElementById('associarModal').style.display = 'none';
            currentEAN = null;
            currentInsumoId = null;
        }

        // Limpar recursos ao sair da página
        window.addEventListener('beforeunload', function() {
            pararScanner();
        });
    </script>
</body>
</html>
