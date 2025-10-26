<?php
// config_demandas_permissions.php — Configurar permissões do sistema de demandas
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

function configDemandasPermissions() {
    echo "<h1>🔐 Configuração de Permissões - Sistema de Demandas</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success-bg { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error-bg { background: #fef2f2; border: 1px solid #fecaca; }
        .warning-bg { background: #fffbeb; border: 1px solid #fed7aa; }
        .permission-item { margin: 10px 0; padding: 10px; background: #f9fafb; border-left: 4px solid #3b82f6; }
        .permission-result { margin-left: 20px; }
    </style>";
    
    try {
        $pdo = $GLOBALS['pdo'];
        
        echo "<div class='section info'>";
        echo "<h2>🔐 Configurando Permissões do Sistema de Demandas</h2>";
        echo "<p>Este script configura as permissões padrão para diferentes perfis de usuário.</p>";
        echo "</div>";
        
        // Verificar se as colunas de permissão existem
        echo "<div class='permission-item'>";
        echo "<h3>🔍 Verificando Estrutura de Permissões</h3>";
        echo "<div class='permission-result'>";
        
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'usuarios' AND column_name LIKE 'perm_demandas%'");
        $existing_permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($existing_permissions) > 0) {
            echo "<p class='success'>✅ Permissões de demandas encontradas:</p>";
            foreach ($existing_permissions as $permission) {
                echo "<p>• {$permission}</p>";
            }
        } else {
            echo "<p class='error'>❌ Permissões de demandas não encontradas. Execute o SQL primeiro.</p>";
            return;
        }
        echo "</div></div>";
        
        // Configurar permissões por perfil
        $permissions_config = [
            'ADM' => [
                'perm_demandas' => true,
                'perm_demandas_criar_quadros' => true,
                'perm_demandas_ver_produtividade' => true
            ],
            'GERENTE' => [
                'perm_demandas' => true,
                'perm_demandas_criar_quadros' => true,
                'perm_demandas_ver_produtividade' => true
            ],
            'OPER' => [
                'perm_demandas' => true,
                'perm_demandas_criar_quadros' => false,
                'perm_demandas_ver_produtividade' => false
            ],
            'CONSULTA' => [
                'perm_demandas' => true,
                'perm_demandas_criar_quadros' => false,
                'perm_demandas_ver_produtividade' => false
            ]
        ];
        
        echo "<div class='permission-item'>";
        echo "<h3>👥 Configurando Permissões por Perfil</h3>";
        echo "<div class='permission-result'>";
        
        foreach ($permissions_config as $perfil => $permissoes) {
            try {
                $sql = "UPDATE usuarios SET ";
                $params = [];
                $set_parts = [];
                
                foreach ($permissoes as $permission => $value) {
                    $set_parts[] = "{$permission} = ?";
                    $params[] = $value ? 1 : 0;
                }
                
                $sql .= implode(', ', $set_parts) . " WHERE perfil = ?";
                $params[] = $perfil;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $affected = $stmt->rowCount();
                
                echo "<p class='success'>✅ Perfil {$perfil}: {$affected} usuários configurados</p>";
                echo "<p>• Acesso: " . ($permissoes['perm_demandas'] ? 'Sim' : 'Não') . "</p>";
                echo "<p>• Criar quadros: " . ($permissoes['perm_demandas_criar_quadros'] ? 'Sim' : 'Não') . "</p>";
                echo "<p>• Ver produtividade: " . ($permissoes['perm_demandas_ver_produtividade'] ? 'Sim' : 'Não') . "</p>";
                
            } catch (PDOException $e) {
                echo "<p class='error'>❌ Erro ao configurar perfil {$perfil}: " . $e->getMessage() . "</p>";
            }
        }
        echo "</div></div>";
        
        // Verificar usuários sem perfil
        echo "<div class='permission-item'>";
        echo "<h3>⚠️ Verificando Usuários sem Perfil</h3>";
        echo "<div class='permission-result'>";
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE perfil IS NULL OR perfil = ''");
            $sem_perfil = $stmt->fetchColumn();
            
            if ($sem_perfil > 0) {
                echo "<p class='warning'>⚠️ {$sem_perfil} usuários sem perfil definido</p>";
                echo "<p>Configurando como OPER (padrão)...</p>";
                
                $stmt = $pdo->prepare("
                    UPDATE usuarios 
                    SET perfil = 'OPER',
                        perm_demandas = TRUE,
                        perm_demandas_criar_quadros = FALSE,
                        perm_demandas_ver_produtividade = FALSE
                    WHERE perfil IS NULL OR perfil = ''
                ");
                $stmt->execute();
                $updated = $stmt->rowCount();
                
                echo "<p class='success'>✅ {$updated} usuários configurados como OPER</p>";
            } else {
                echo "<p class='success'>✅ Todos os usuários têm perfil definido</p>";
            }
        } catch (PDOException $e) {
            echo "<p class='error'>❌ Erro ao verificar usuários sem perfil: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Verificar permissões finais
        echo "<div class='permission-item'>";
        echo "<h3>📊 Resumo das Permissões Configuradas</h3>";
        echo "<div class='permission-result'>";
        
        try {
            $stmt = $pdo->query("
                SELECT 
                    perfil,
                    COUNT(*) as total_usuarios,
                    SUM(CASE WHEN perm_demandas = TRUE THEN 1 ELSE 0 END) as com_acesso,
                    SUM(CASE WHEN perm_demandas_criar_quadros = TRUE THEN 1 ELSE 0 END) as pode_criar,
                    SUM(CASE WHEN perm_demandas_ver_produtividade = TRUE THEN 1 ELSE 0 END) as pode_ver_prod
                FROM usuarios 
                GROUP BY perfil
                ORDER BY perfil
            ");
            $resumo = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
            echo "<tr style='background: #f3f4f6;'>";
            echo "<th style='padding: 10px;'>Perfil</th>";
            echo "<th style='padding: 10px;'>Total</th>";
            echo "<th style='padding: 10px;'>Com Acesso</th>";
            echo "<th style='padding: 10px;'>Pode Criar</th>";
            echo "<th style='padding: 10px;'>Ver Produtividade</th>";
            echo "</tr>";
            
            foreach ($resumo as $row) {
                echo "<tr>";
                echo "<td style='padding: 10px;'><strong>" . htmlspecialchars($row['perfil']) . "</strong></td>";
                echo "<td style='padding: 10px;'>" . $row['total_usuarios'] . "</td>";
                echo "<td style='padding: 10px;'>" . $row['com_acesso'] . "</td>";
                echo "<td style='padding: 10px;'>" . $row['pode_criar'] . "</td>";
                echo "<td style='padding: 10px;'>" . $row['pode_ver_prod'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            
        } catch (PDOException $e) {
            echo "<p class='error'>❌ Erro ao gerar resumo: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Configurar preferências de notificação
        echo "<div class='permission-item'>";
        echo "<h3>🔔 Configurando Preferências de Notificação</h3>";
        echo "<div class='permission-result'>";
        
        try {
            $stmt = $pdo->query("SELECT id FROM usuarios");
            $usuarios = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $preferencias_inseridas = 0;
            foreach ($usuarios as $usuario_id) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO demandas_preferencias_notificacao (
                            usuario_id, notificacao_painel, notificacao_email, 
                            notificacao_whatsapp, alerta_vencimento
                        ) VALUES (?, TRUE, TRUE, FALSE, 24)
                        ON CONFLICT (usuario_id) DO NOTHING
                    ");
                    $stmt->execute([$usuario_id]);
                    $preferencias_inseridas++;
                } catch (PDOException $e) {
                    // Usuário já tem preferências
                }
            }
            
            echo "<p class='success'>✅ Preferências de notificação configuradas para {$preferencias_inseridas} usuários</p>";
            echo "<p>• Notificação no painel: Ativada</p>";
            echo "<p>• Notificação por e-mail: Ativada</p>";
            echo "<p>• Notificação por WhatsApp: Desativada</p>";
            echo "<p>• Alerta de vencimento: 24 horas</p>";
            
        } catch (PDOException $e) {
            echo "<p class='error'>❌ Erro ao configurar preferências: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        echo "<div class='section success-bg'>";
        echo "<h2>🎉 Permissões Configuradas com Sucesso!</h2>";
        echo "<p>O sistema de demandas está pronto para uso com as seguintes configurações:</p>";
        echo "<ul>";
        echo "<li><strong>ADM:</strong> Acesso total, pode criar quadros, ver produtividade</li>";
        echo "<li><strong>GERENTE:</strong> Acesso total, pode criar quadros, ver produtividade</li>";
        echo "<li><strong>OPER:</strong> Acesso básico, não pode criar quadros</li>";
        echo "<li><strong>CONSULTA:</strong> Acesso somente leitura</li>";
        echo "</ul>";
        echo "<p><strong>Próximos passos:</strong></p>";
        echo "<ol>";
        echo "<li>Testar acesso com diferentes usuários</li>";
        echo "<li>Configurar notificações por e-mail</li>";
        echo "<li>Configurar integração com WhatsApp</li>";
        echo "<li>Configurar leitura de e-mails via IMAP</li>";
        echo "<li>Configurar automações agendadas</li>";
        echo "</ol>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='section error-bg'>";
        echo "<h2>❌ Erro Geral</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
}

// Executar configuração
configDemandasPermissions();
?>
