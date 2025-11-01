<?php
// comparar_chave_asaas.php - Comparar chave do Railway com chave do painel Asaas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar autenticação
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { 
    $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); 
}

if (!$uid || !is_numeric($uid) || !$estaLogado) {
    header('Location: index.php?page=login');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Comparar Chave Asaas</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: #10b981; }
        .error { color: #dc2626; }
        .warning { color: #f59e0b; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        textarea { width: 100%; height: 80px; font-family: monospace; font-size: 12px; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .btn { background: #3b82f6; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; margin-top: 10px; }
        .btn:hover { background: #2563eb; }
        .comparison { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0; }
        .char-comp { font-family: monospace; font-size: 10px; line-height: 1.5; }
        .match { background: #d1fae5; }
        .diff { background: #fee2e2; }
        .info-box { background: #dbeafe; border: 1px solid #3b82f6; padding: 15px; border-radius: 6px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔍 Comparar Chave Asaas</h1>
        <p>Cole a chave do painel Asaas abaixo para comparar com a chave do Railway.</p>
    </div>

    <?php
    // Carregar chave do Railway
    $env_key = $_ENV['ASAAS_API_KEY'] ?? getenv('ASAAS_API_KEY') ?: null;
    require_once __DIR__ . '/config_env.php';
    $const_key = defined('ASAAS_API_KEY') ? ASAAS_API_KEY : null;
    $chave_railway = $env_key ?? $const_key;

    echo '<div class="box">';
    echo '<h2>1. Chave do Railway (atual)</h2>';
    if ($chave_railway) {
        echo '<p><strong>Fonte:</strong> ' . ($env_key ? 'Variável de Ambiente (ENV)' : 'Constante (config_env.php)') . '</p>';
        echo '<p><strong>Tamanho:</strong> ' . strlen($chave_railway) . ' caracteres</p>';
        echo '<p><strong>Começa com $:</strong> ' . (strpos($chave_railway, '$') === 0 ? '✅ SIM' : '❌ NÃO') . '</p>';
        echo '<textarea readonly id="railway-key" onclick="this.select()">' . htmlspecialchars($chave_railway) . '</textarea>';
        echo '<button class="btn" onclick="copyToClipboard(\'railway-key\')">📋 Copiar Chave do Railway</button>';
    } else {
        echo '<p class="error">❌ Nenhuma chave encontrada!</p>';
    }
    echo '</div>';

    if (isset($_POST['chave_painel']) && !empty($_POST['chave_painel'])) {
        $chave_painel = trim($_POST['chave_painel']);
        
        echo '<div class="box">';
        echo '<h2>2. Comparação</h2>';
        
        echo '<h3>Chave do Painel Asaas:</h3>';
        echo '<p><strong>Tamanho:</strong> ' . strlen($chave_painel) . ' caracteres</p>';
        echo '<p><strong>Começa com $:</strong> ' . (strpos($chave_painel, '$') === 0 ? '✅ SIM' : '❌ NÃO') . '</p>';
        echo '<textarea readonly id="painel-key" onclick="this.select()">' . htmlspecialchars($chave_painel) . '</textarea>';
        echo '<button class="btn" onclick="copyToClipboard(\'painel-key\')">📋 Copiar Chave do Painel</button>';
        
        echo '<h3>Resultado da Comparação:</h3>';
        
        // Comparar
        $sao_iguais = ($chave_railway === $chave_painel);
        $tamanhos_iguais = (strlen($chave_railway) === strlen($chave_painel));
        
        if ($sao_iguais) {
            echo '<div class="info-box">';
            echo '<p class="success"><strong>✅ AS CHAVES SÃO IDÊNTICAS!</strong></p>';
            echo '<p>Se ainda está dando erro 401, o problema pode ser:</p>';
            echo '<ul>';
            echo '<li>A chave foi desabilitada no painel (mesmo que mostre "Habilitada", pode ter um delay)</li>';
            echo '<li>Há um problema temporário na API Asaas</li>';
            echo '<li>A chave foi usada em ambiente errado (sandbox vs produção)</li>';
            echo '</ul>';
            echo '<p><strong>Solução:</strong> Aguarde alguns minutos e tente novamente, ou gere uma nova chave.</p>';
            echo '</div>';
        } else {
            echo '<div style="background: #fee2e2; border: 1px solid #dc2626; padding: 15px; border-radius: 6px;">';
            echo '<p class="error"><strong>❌ AS CHAVES SÃO DIFERENTES!</strong></p>';
            echo '<p><strong>Diferenças encontradas:</strong></p>';
            echo '<ul>';
            
            if (!$tamanhos_iguais) {
                echo '<li>Tamanhos diferentes: Railway tem ' . strlen($chave_railway) . ' chars, Painel tem ' . strlen($chave_painel) . ' chars</li>';
            }
            
            if (strpos($chave_railway, '$') !== strpos($chave_painel, '$')) {
                echo '<li>Uma começa com $ e a outra não</li>';
            }
            
            // Comparar caracter por caracter
            $min_len = min(strlen($chave_railway), strlen($chave_painel));
            $diferencas = [];
            for ($i = 0; $i < $min_len; $i++) {
                if ($chave_railway[$i] !== $chave_painel[$i]) {
                    $diferencas[] = $i;
                    if (count($diferencas) >= 10) break; // Limitar a 10 diferenças
                }
            }
            
            if (!empty($diferencas)) {
                echo '<li>Diferenças na posição: ' . implode(', ', $diferencas) . ' (mostrando até 10)</li>';
            }
            
            echo '</ul>';
            echo '<p><strong>SOLUÇÃO:</strong></p>';
            echo '<ol>';
            echo '<li>Copie a chave EXATA do painel Asaas (incluindo o $ no início)</li>';
            echo '<li>Cole no Railway na variável <code>ASAAS_API_KEY</code></li>';
            echo '<li>Verifique se não há espaços extras no início ou fim</li>';
            echo '<li>Faça um redeploy do serviço no Railway</li>';
            echo '</ol>';
            echo '</div>';
            
            // Mostrar comparação visual
            echo '<h3>Comparação Visual (primeiros 100 caracteres):</h3>';
            echo '<div class="comparison">';
            
            echo '<div>';
            echo '<p><strong>Railway:</strong></p>';
            echo '<div class="char-comp">';
            $railway_preview = substr($chave_railway, 0, 100);
            $painel_preview = substr($chave_painel, 0, 100);
            for ($i = 0; $i < min(strlen($railway_preview), strlen($painel_preview)); $i++) {
                $match = ($railway_preview[$i] === $painel_preview[$i]);
                $char = htmlspecialchars($railway_preview[$i]);
                if ($char === ' ') $char = '·';
                echo '<span class="' . ($match ? 'match' : 'diff') . '">' . $char . '</span>';
            }
            echo '</div>';
            echo '</div>';
            
            echo '<div>';
            echo '<p><strong>Painel:</strong></p>';
            echo '<div class="char-comp">';
            for ($i = 0; $i < min(strlen($railway_preview), strlen($painel_preview)); $i++) {
                $match = ($railway_preview[$i] === $painel_preview[$i]);
                $char = htmlspecialchars($painel_preview[$i]);
                if ($char === ' ') $char = '·';
                echo '<span class="' . ($match ? 'match' : 'diff') . '">' . $char . '</span>';
            }
            echo '</div>';
            echo '</div>';
            
            echo '</div>';
        }
        
        echo '</div>';
    }
    ?>

    <div class="box">
        <h2>3. Colar Chave do Painel Asaas</h2>
        <form method="POST">
            <p><strong>Instruções:</strong></p>
            <ol>
                <li>Acesse o painel Asaas: <a href="https://www.asaas.com" target="_blank">https://www.asaas.com</a></li>
                <li>Vá em <strong>Integrações > Chaves de API</strong></li>
                <li>Clique na chave "PAINEL SMILE NOVO"</li>
                <li>Se a chave não estiver visível, você precisará gerar uma nova</li>
                <li>Cole a chave COMPLETA abaixo (incluindo o $ no início):</li>
            </ol>
            <textarea name="chave_painel" placeholder="Cole a chave do painel Asaas aqui..." required><?php echo isset($_POST['chave_painel']) ? htmlspecialchars($_POST['chave_painel']) : ''; ?></textarea>
            <button type="submit" class="btn">🔍 Comparar Chaves</button>
        </form>
    </div>

    <script>
        function copyToClipboard(id) {
            const textarea = document.getElementById(id);
            textarea.select();
            document.execCommand('copy');
            alert('Chave copiada para a área de transferência!');
        }
    </script>
</body>
</html>

