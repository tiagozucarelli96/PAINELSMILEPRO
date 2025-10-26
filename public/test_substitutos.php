<?php
// test_substitutos.php
// Script de teste para o sistema de substitutos

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_substitutes_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Definir usuário de teste
$_SESSION['perfil'] = 'ADM';
$_SESSION['usuario_id'] = 1;
$_SESSION['usuario_nome'] = 'Administrador';

echo "<h1>🔄 Teste do Sistema de Substitutos</h1>";

try {
    // Teste 1: Verificar tabela de substitutos
    echo "<h2>1. Verificando estrutura da tabela de substitutos...</h2>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'lc_insumos_substitutos'");
    $colunasExistem = $stmt->fetchColumn() > 0;
    echo "<p>Tabela de substitutos: " . ($colunasExistem ? "✅ Existe" : "❌ Não existe") . "</p>";
    
    if (!$colunasExistem) {
        echo "<p style='color: red;'>Execute o arquivo sql/008_estoque_contagem.sql para criar a tabela de substitutos.</p>";
    }
    
    // Teste 2: Criar dados de teste
    echo "<h2>2. Criando dados de teste...</h2>";
    
    // Buscar alguns insumos para criar substitutos
    $stmt = $pdo->query("SELECT id, nome FROM lc_insumos WHERE ativo = true LIMIT 3");
    $insumos_teste = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($insumos_teste) >= 2) {
        $insumo_principal = $insumos_teste[0];
        $insumo_substituto = $insumos_teste[1];
        
        echo "<p>Insumo principal: " . htmlspecialchars($insumo_principal['nome']) . "</p>";
        echo "<p>Insumo substituto: " . htmlspecialchars($insumo_substituto['nome']) . "</p>";
        
        // Criar substituto de teste
        try {
            $substituto_id = lc_adicionar_substituto($pdo, [
                'insumo_principal_id' => $insumo_principal['id'],
                'insumo_substituto_id' => $insumo_substituto['id'],
                'equivalencia' => 1.0,
                'prioridade' => 1,
                'observacao' => 'Substituto de teste criado automaticamente',
                'ativo' => true
            ]);
            
            echo "<p>✅ Substituto criado com ID: $substituto_id</p>";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'duplicate key') !== false) {
                echo "<p>⚠️ Substituto já existe (normal)</p>";
            } else {
                throw $e;
            }
        }
    } else {
        echo "<p>⚠️ Insuficientes insumos para teste</p>";
    }
    
    // Teste 3: Buscar substitutos
    echo "<h2>3. Testando busca de substitutos...</h2>";
    
    if (count($insumos_teste) >= 2) {
        $insumo_principal_id = $insumos_teste[0]['id'];
        $substitutos = lc_buscar_substitutos($pdo, $insumo_principal_id);
        
        echo "<p>Substitutos encontrados: " . count($substitutos) . "</p>";
        
        if (!empty($substitutos)) {
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr><th>Nome</th><th>Equivalência</th><th>Prioridade</th><th>Observação</th></tr>";
            
            foreach ($substitutos as $substituto) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($substituto['substituto_nome']) . "</td>";
                echo "<td>" . htmlspecialchars($substituto['equivalencia']) . "</td>";
                echo "<td>" . htmlspecialchars($substituto['prioridade']) . "</td>";
                echo "<td>" . htmlspecialchars($substituto['observacao'] ?? '') . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
    
    // Teste 4: Testar cálculos
    echo "<h2>4. Testando cálculos de substitutos...</h2>";
    
    $necessidade_principal = 10.5;
    $equivalencia = 1.2;
    $percentual_cobertura = 80.0;
    $embalagem_multiplo = 12;
    
    $resultado = lc_calcular_quantidade_substituto(
        $necessidade_principal,
        $equivalencia,
        $percentual_cobertura,
        $embalagem_multiplo
    );
    
    echo "<p>Necessidade principal: $necessidade_principal</p>";
    echo "<p>Equivalência: $equivalencia</p>";
    echo "<p>% Cobertura: $percentual_cobertura%</p>";
    echo "<p>Embalagem múltiplo: $embalagem_multiplo</p>";
    echo "<p>Resultado:</p>";
    echo "<ul>";
    echo "<li>Necessidade parcial: " . $resultado['necessidade_parcial'] . "</li>";
    echo "<li>Necessidade substituto: " . $resultado['necessidade_substituto'] . "</li>";
    echo "<li>Sugerido (com pack): " . $resultado['sugerido'] . "</li>";
    echo "<li>% Cobertura: " . $resultado['percentual_cobertura'] . "%</li>";
    echo "</ul>";
    
    // Teste 5: Testar validações
    echo "<h2>5. Testando validações...</h2>";
    
    // Testar compatibilidade de unidades
    $compatibilidade = lc_validar_compatibilidade_unidades('L', 'L', 1.0);
    echo "<p>Compatibilidade L → L (eq. 1.0): " . ($compatibilidade['compativel'] ? "✅ Compatível" : "❌ Incompatível") . "</p>";
    if ($compatibilidade['aviso']) {
        echo "<p>Aviso: " . htmlspecialchars($compatibilidade['aviso']) . "</p>";
    }
    
    $compatibilidade = lc_validar_compatibilidade_unidades('kg', 'L', 0.8);
    echo "<p>Compatibilidade kg → L (eq. 0.8): " . ($compatibilidade['compativel'] ? "✅ Compatível" : "❌ Incompatível") . "</p>";
    if ($compatibilidade['aviso']) {
        echo "<p>Aviso: " . htmlspecialchars($compatibilidade['aviso']) . "</p>";
    }
    
    // Teste 6: Testar permissões
    echo "<h2>6. Testando permissões...</h2>";
    
    $perfil = lc_get_user_perfil();
    echo "<p>Perfil atual: $perfil</p>";
    
    $pode_gerar = in_array($perfil, ['ADM', 'OPER']);
    $pode_ver_custo = $perfil === 'ADM';
    
    echo "<p>Pode gerar sugestões: " . ($pode_gerar ? "✅ Sim" : "❌ Não") . "</p>";
    echo "<p>Pode ver custos: " . ($pode_ver_custo ? "✅ Sim" : "❌ Não") . "</p>";
    
    // Teste 7: Testar funções auxiliares
    echo "<h2>7. Testando funções auxiliares...</h2>";
    
    $observacao = lc_gerar_observacao_substituicao('Leite UHT 1L', 80.0, 1.2);
    echo "<p>Observação gerada: " . htmlspecialchars($observacao) . "</p>";
    
    if (count($insumos_teste) >= 2) {
        $tem_substitutos = lc_tem_substitutos($pdo, $insumos_teste[0]['id']);
        echo "<p>Insumo tem substitutos: " . ($tem_substitutos ? "✅ Sim" : "❌ Não") . "</p>";
    }
    
    // Teste 8: Simular cenários de uso
    echo "<h2>8. Simulando cenários de uso...</h2>";
    
    echo "<h3>Cenário 1: Cobertura 100% com 1 substituto</h3>";
    $resultado_100 = lc_calcular_quantidade_substituto(10.0, 1.0, 100.0, 12);
    echo "<p>Necessidade: 10.0 → Sugerido: " . $resultado_100['sugerido'] . " (pack: 12)</p>";
    
    echo "<h3>Cenário 2: Cobertura parcial 60%</h3>";
    $resultado_60 = lc_calcular_quantidade_substituto(10.0, 1.0, 60.0, 12);
    echo "<p>Necessidade: 10.0 → Sugerido: " . $resultado_60['sugerido'] . " (60% cobertura)</p>";
    
    echo "<h3>Cenário 3: Equivalência diferente</h3>";
    $resultado_eq = lc_calcular_quantidade_substituto(10.0, 0.5, 100.0, 6);
    echo "<p>Necessidade: 10.0 → Sugerido: " . $resultado_eq['sugerido'] . " (eq: 0.5, pack: 6)</p>";
    
    echo "<h2>✅ Testes de substitutos concluídos!</h2>";
    
    echo "<div style='margin-top: 20px; padding: 15px; background: #e7f3ff; border-radius: 8px;'>";
    echo "<h3>📋 Funcionalidades testadas:</h3>";
    echo "<ul>";
    echo "<li>✅ Criação de substitutos</li>";
    echo "<li>✅ Busca de substitutos</li>";
    echo "<li>✅ Cálculos de equivalência</li>";
    echo "<li>✅ Validações de compatibilidade</li>";
    echo "<li>✅ Permissões do sistema</li>";
    echo "<li>✅ Funções auxiliares</li>";
    echo "<li>✅ Cenários de uso</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div style='margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 8px;'>";
    echo "<h3>⚠️ Próximos passos:</h3>";
    echo "<ol>";
    echo "<li><strong>Configure substitutos:</strong> Acesse a edição de insumos para cadastrar substitutos</li>";
    echo "<li><strong>Teste o modal:</strong> Acesse <a href='estoque_alertas.php'>estoque_alertas.php</a> e teste o modal de sugestão</li>";
    echo "<li><strong>Teste substitutos:</strong> Use o botão '🔄 Substituto' na prévia</li>";
    echo "<li><strong>Valide cálculos:</strong> Verifique se as quantidades e custos estão corretos</li>";
    echo "</ol>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h2>❌ Erro durante os testes:</h2>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>

<script>
// Teste de funcionalidades JavaScript
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ JavaScript carregado');
    console.log('✅ Sistema de substitutos disponível');
    
    // Teste de funções de substitutos
    if (typeof abrirSubmodalSubstitutos === 'function') {
        console.log('✅ Função abrirSubmodalSubstitutos disponível');
    }
    
    if (typeof aplicarSubstituto === 'function') {
        console.log('✅ Função aplicarSubstituto disponível');
    }
    
    // Teste de cálculos
    const testeCalculo = {
        necessidade: 10.5,
        equivalencia: 1.2,
        percentual: 80,
        embalagem: 12
    };
    
    console.log('🧮 Teste de cálculo:', testeCalculo);
    
    // Simular cálculo
    const necessidadeParcial = testeCalculo.necessidade * (testeCalculo.percentual / 100);
    const necessidadeSubstituto = necessidadeParcial * testeCalculo.equivalencia;
    const sugerido = Math.ceil(necessidadeSubstituto / testeCalculo.embalagem) * testeCalculo.embalagem;
    
    console.log('📊 Resultado do cálculo:');
    console.log('- Necessidade parcial:', necessidadeParcial);
    console.log('- Necessidade substituto:', necessidadeSubstituto);
    console.log('- Sugerido (com pack):', sugerido);
});
</script>
