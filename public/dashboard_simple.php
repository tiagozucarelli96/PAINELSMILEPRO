<?php
// dashboard_simple.php — Dashboard simplificado para teste
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Simular sessão se necessário
if (!isset($_SESSION['logado'])) {
    $_SESSION['logado'] = 1;
    $_SESSION['nome'] = 'Tiago';
    $_SESSION['perfil'] = 'ADM';
    $_SESSION['user_id'] = 1;
}

echo "<!DOCTYPE html>";
echo "<html lang='pt-BR'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
echo "<title>Dashboard Principal - Smile EVENTOS</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f8fafc; }";
echo ".container { max-width: 1200px; margin: 0 auto; }";
echo ".header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }";
echo ".cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }";
echo ".card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }";
echo ".card h3 { margin: 0 0 10px 0; color: #1e293b; }";
echo ".card .value { font-size: 24px; font-weight: bold; color: #3b82f6; }";
echo ".card .label { color: #64748b; font-size: 14px; }";
echo "</style>";
echo "</head>";
echo "<body>";

echo "<div class='container'>";
echo "<div class='header'>";
echo "<h1>🎉 Dashboard Principal - Smile EVENTOS</h1>";
echo "<p>Bem-vindo, " . ($_SESSION['nome'] ?? 'Usuário') . "!</p>";
echo "</div>";

echo "<div class='cards'>";
echo "<div class='card'>";
echo "<h3>📊 Leads do Mês</h3>";
echo "<div class='value'>15</div>";
echo "<div class='label'>Outubro 2025</div>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>🤝 Leads em Negociação</h3>";
echo "<div class='value'>8</div>";
echo "<div class='label'>Todo Período</div>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>✅ Contratos Fechados</h3>";
echo "<div class='value'>12</div>";
echo "<div class='label'>Outubro 2025</div>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>💰 Vendas Realizadas</h3>";
echo "<div class='value'>19</div>";
echo "<div class='label'>Outubro 2025</div>";
echo "</div>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>🎯 Meta Mensal</h3>";
echo "<div class='value'>80%</div>";
echo "<div class='label'>Meta: 15 contratos</div>";
echo "</div>";

echo "<div class='card'>";
echo "<h3>📧 E-mails Hoje</h3>";
echo "<div class='value'>3</div>";
echo "<div class='label'>Novas mensagens</div>";
echo "</div>";
echo "</div>";

echo "<div style='position: fixed; bottom: 20px; right: 20px;'>";
echo "<button style='background: #10b981; color: white; border: none; padding: 15px 20px; border-radius: 25px; font-weight: bold; cursor: pointer;'>";
echo "💸 Solicitar Pagamento";
echo "</button>";
echo "</div>";

echo "</div>";
echo "</body>";
echo "</html>";
?>
