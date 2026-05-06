<?php
// Script para habilitar todas as permissões para o usuário atual ou específico
// Execute este arquivo uma vez para habilitar todas as permissões

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar se está logado
if (empty($_SESSION['logado']) || empty($_SESSION['id'])) {
    die('Acesso negado. É necessário estar logado para executar este script.');
}

$user_id = $_GET['user_id'] ?? $_SESSION['id'];
$user_id = (int)$user_id;

// Se for um ID diferente do usuário logado, precisa ser admin
if ($user_id != $_SESSION['id']) {
    if (empty($_SESSION['perm_configuracoes']) && empty($_SESSION['is_admin'])) {
        die('Acesso negado. Você só pode habilitar permissões para si mesmo, a menos que seja admin.');
    }
}

// Lista de todas as permissões do sistema
$todas_permissoes = [
    // Módulos da sidebar
    'perm_agenda',
    'perm_comercial',
    'perm_marketing',
    'perm_eventos',
    'perm_eventos_realizar',
    // 'perm_logistico', // REMOVIDO: Módulo desativado
    'perm_configuracoes',
    'perm_cadastros',
    'perm_financeiro',
    'perm_administrativo',
    // Permissões específicas
    'perm_usuarios',
    'perm_pagamentos',
    'perm_tarefas',
    'perm_demandas',
    'perm_portao',
    'perm_notas_fiscais',
    // 'perm_estoque_logistico', // REMOVIDO: Módulo desativado
    'perm_dados_contrato',
    'perm_uso_fiorino'
];

// Buscar dados do usuário
try {
    $stmt = $pdo->prepare("SELECT id, nome, login FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        die('Usuário não encontrado.');
    }
} catch (PDOException $e) {
    die('Erro ao buscar usuário: ' . $e->getMessage());
}

// Verificar quais colunas existem na tabela
try {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'usuarios' AND column_name LIKE 'perm_%'");
    $colunas_existentes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Filtrar apenas as permissões que existem na tabela
    $permissoes_a_atualizar = array_intersect($todas_permissoes, $colunas_existentes);
    
    if (empty($permissoes_a_atualizar)) {
        $mensagem_erro = "Nenhuma coluna de permissão encontrada na tabela. Execute primeiro o script 'apply_permissoes_sidebar_columns.php' para adicionar as colunas.";
    } else {
        // Executar apenas se foi uma requisição POST ou GET com ação
        $executar = isset($_GET['executar']) || isset($_POST['executar']) || !empty($_GET['user_id']);
        // Construir query de UPDATE
        $set_parts = [];
        $params = [':id' => $user_id];
        
        foreach ($permissoes_a_atualizar as $perm) {
            $set_parts[] = "$perm = TRUE";
        }
        
        if (!empty($set_parts)) {
            // Verificar se deve executar (se for requisição GET com executar ou POST)
            $executar = isset($_GET['executar']) || isset($_POST['executar']);
            
            if ($executar) {
                $sql = "UPDATE usuarios SET " . implode(", ", $set_parts) . " WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $mensagem_sucesso = "Todas as permissões foram habilitadas para o usuário: " . htmlspecialchars($usuario['nome']) . " (ID: {$usuario['id']})";
            } else {
                // Mostrar preview
                $mensagem_info = "Pronto para habilitar " . count($permissoes_a_atualizar) . " permissões para: " . htmlspecialchars($usuario['nome']);
            }
        }
    }
} catch (PDOException $e) {
    $mensagem_erro = "Erro ao atualizar permissões: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Habilitar Todas as Permissões</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            padding: 3rem;
            max-width: 600px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        h1 {
            color: #1e3a8a;
            margin-bottom: 1rem;
            font-size: 2rem;
        }
        
        .info-box {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            border-left: 4px solid #3b82f6;
        }
        
        .success {
            background: #d1fae5;
            color: #065f46;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            border-left: 4px solid #059669;
        }
        
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            border-left: 4px solid #dc2626;
        }
        
        .btn {
            display: inline-block;
            background: #1e3a8a;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 1rem;
            margin-right: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }
        
        .btn-secondary {
            background: #64748b;
        }
        
        .btn-secondary:hover {
            background: #475569;
        }
        
        .user-info {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        
        .user-info strong {
            color: #1e293b;
        }
        
        ul {
            margin: 1rem 0;
            padding-left: 1.5rem;
        }
        
        li {
            margin: 0.5rem 0;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>✅ Habilitar Todas as Permissões</h1>
        
        <div class="user-info">
            <strong>Usuário:</strong> <?= htmlspecialchars($usuario['nome']) ?><br>
            <strong>Login:</strong> <?= htmlspecialchars($usuario['login']) ?><br>
            <strong>ID:</strong> <?= $usuario['id'] ?>
        </div>
        
        <?php if (isset($mensagem_sucesso)): ?>
            <div class="success">
                <strong>✅ Sucesso!</strong><br>
                <?= $mensagem_sucesso ?><br><br>
                <strong>Permissões habilitadas:</strong>
                <ul>
                    <?php foreach ($permissoes_a_atualizar as $perm): ?>
                        <li><?= htmlspecialchars($perm) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top: 1rem; font-weight: 600;">⚠️ Faça logout e login novamente para que as permissões tenham efeito!</p>
            </div>
        <?php endif; ?>
        
        <?php if (isset($mensagem_erro)): ?>
            <div class="error">
                <strong>❌ Erro:</strong><br>
                <?= htmlspecialchars($mensagem_erro) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($mensagem_info)): ?>
            <div class="info-box">
                <strong>ℹ️ Preview:</strong><br>
                <?= htmlspecialchars($mensagem_info) ?><br><br>
                <strong>Permissões que serão habilitadas:</strong>
                <ul>
                    <?php foreach ($permissoes_a_atualizar as $perm): ?>
                        <li><?= htmlspecialchars($perm) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
            <form method="GET" style="margin-top: 1.5rem;">
                <input type="hidden" name="page" value="habilitar_todas_permissoes">
                <input type="hidden" name="executar" value="1">
                <?php if ($user_id != $_SESSION['id']): ?>
                    <input type="hidden" name="user_id" value="<?= $user_id ?>">
                <?php endif; ?>
                <button type="submit" class="btn" style="cursor: pointer; border: none;">
                    🚀 Confirmar e Habilitar Todas as Permissões
                </button>
            </form>
        <?php elseif (!isset($mensagem_sucesso) && !isset($mensagem_erro)): ?>
            <div class="info-box">
                <strong>ℹ️ Informação:</strong><br>
                Este script irá habilitar todas as permissões disponíveis para este usuário.
            </div>
            
            <form method="GET" style="margin-top: 1.5rem;">
                <input type="hidden" name="page" value="habilitar_todas_permissoes">
                <?php if ($user_id != $_SESSION['id']): ?>
                    <input type="hidden" name="user_id" value="<?= $user_id ?>">
                <?php endif; ?>
                <button type="submit" class="btn" style="cursor: pointer; border: none;">
                    🚀 Habilitar Todas as Permissões
                </button>
            </form>
        <?php endif; ?>
        
        <div style="margin-top: 2rem;">
            <a href="index.php?page=usuarios" class="btn">👥 Ir para Usuários</a>
            <a href="index.php?page=dashboard" class="btn btn-secondary">🏠 Dashboard</a>
        </div>
    </div>
</body>
</html>
