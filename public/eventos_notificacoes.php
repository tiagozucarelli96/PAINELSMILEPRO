<?php
/**
 * eventos_notificacoes.php
 * Helper para disparo de notificações do módulo Eventos
 * Reutiliza sistema existente de push e e-mail
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/push_helper.php';
require_once __DIR__ . '/core/email_global_helper.php';

/**
 * Notificar quando link de cliente DJ é criado
 */
function eventos_notificar_link_cliente_criado(PDO $pdo, int $meeting_id, string $link_url): void {
    try {
        // Buscar dados da reunião
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   (r.me_event_snapshot->'cliente'->>'email') as email_cliente,
                   (r.me_event_snapshot->'cliente'->>'nome') as nome_cliente,
                   u.nome as criador_nome
            FROM eventos_reunioes r
            LEFT JOIN usuarios u ON u.id = r.created_by
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reuniao) return;
        
        // Enviar e-mail para o cliente (se tiver email)
        if (!empty($reuniao['email_cliente'])) {
            $emailHelper = new EmailGlobalHelper();
            
            $corpo = "
                <h2>Olá, {$reuniao['nome_cliente']}!</h2>
                <p>Você recebeu um link para preencher as informações de DJ do seu evento:</p>
                <p><strong>Evento:</strong> {$reuniao['nome_evento']}</p>
                <br>
                <p><a href='{$link_url}' style='display: inline-block; padding: 12px 24px; background: #1e3a8a; color: white; text-decoration: none; border-radius: 8px;'>Acessar Formulário</a></p>
                <br>
                <p style='color: #64748b; font-size: 14px;'>Este link é pessoal. Após enviar, você não poderá mais alterar as informações.</p>
                <br>
                <p>Atenciosamente,<br>Grupo Smile</p>
            ";
            
            $emailHelper->enviarEmail(
                $reuniao['email_cliente'],
                "Preencha as músicas do seu evento - {$reuniao['nome_evento']}",
                $corpo
            );
        }
        
        // Notificar internamente (push para quem criou)
        if (!empty($reuniao['created_by'])) {
            $pushHelper = new PushHelper();
            $pushHelper->enviarPush(
                $reuniao['created_by'],
                'Link DJ Enviado',
                "Link criado para {$reuniao['nome_evento']}",
                ['url' => "/index.php?page=eventos_reuniao_final&id={$meeting_id}"]
            );
        }
        
    } catch (Exception $e) {
        error_log("Erro ao notificar link cliente: " . $e->getMessage());
    }
}

/**
 * Notificar quando cliente enviou informações de DJ
 */
function eventos_notificar_cliente_enviou_dj(PDO $pdo, int $meeting_id): void {
    try {
        // Buscar dados
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   r.fornecedor_dj_id
            FROM eventos_reunioes r
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reuniao) return;
        
        // Notificar quem criou a reunião
        if (!empty($reuniao['created_by'])) {
            $pushHelper = new PushHelper();
            $pushHelper->enviarPush(
                $reuniao['created_by'],
                '🎵 Cliente enviou músicas!',
                "O cliente preencheu as informações de DJ para {$reuniao['nome_evento']}",
                ['url' => "/index.php?page=eventos_reuniao_final&id={$meeting_id}"]
            );
        }
        
        // Notificar DJ vinculado (se houver)
        // Nota: DJ não tem user_id no sistema interno, então não envia push
        // Podemos enviar e-mail se o fornecedor tiver email cadastrado
        if (!empty($reuniao['fornecedor_dj_id'])) {
            $stmt = $pdo->prepare("SELECT email FROM eventos_fornecedores WHERE id = :id AND email IS NOT NULL");
            $stmt->execute([':id' => $reuniao['fornecedor_dj_id']]);
            $dj_email = $stmt->fetchColumn();
            
            if ($dj_email) {
                $emailHelper = new EmailGlobalHelper();
                $emailHelper->enviarEmail(
                    $dj_email,
                    "Novas informações de DJ - {$reuniao['nome_evento']}",
                    "
                        <h2>Nova atualização!</h2>
                        <p>O cliente enviou as informações de músicas para o evento:</p>
                        <p><strong>{$reuniao['nome_evento']}</strong></p>
                        <p>Acesse o Portal DJ para visualizar os detalhes.</p>
                    "
                );
            }
        }
        
    } catch (Exception $e) {
        error_log("Erro ao notificar envio DJ: " . $e->getMessage());
    }
}

/**
 * Notificar quando conteúdo é atualizado (para fornecedores vinculados)
 */
function eventos_notificar_conteudo_atualizado(PDO $pdo, int $meeting_id, string $section): void {
    try {
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   r.fornecedor_dj_id,
                   r.fornecedor_decoracao_id
            FROM eventos_reunioes r
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reuniao) return;
        
        $fornecedor_id = null;
        $tipo = '';
        
        if ($section === 'dj_protocolo' && !empty($reuniao['fornecedor_dj_id'])) {
            $fornecedor_id = $reuniao['fornecedor_dj_id'];
            $tipo = 'DJ';
        } elseif ($section === 'decoracao' && !empty($reuniao['fornecedor_decoracao_id'])) {
            $fornecedor_id = $reuniao['fornecedor_decoracao_id'];
            $tipo = 'Decoração';
        }
        
        if ($fornecedor_id) {
            $stmt = $pdo->prepare("SELECT email FROM eventos_fornecedores WHERE id = :id AND email IS NOT NULL");
            $stmt->execute([':id' => $fornecedor_id]);
            $email = $stmt->fetchColumn();
            
            if ($email) {
                $emailHelper = new EmailGlobalHelper();
                $emailHelper->enviarEmail(
                    $email,
                    "Atualização de {$tipo} - {$reuniao['nome_evento']}",
                    "
                        <h2>Conteúdo atualizado!</h2>
                        <p>Houve uma atualização na seção de {$tipo} do evento:</p>
                        <p><strong>{$reuniao['nome_evento']}</strong></p>
                        <p>Acesse o Portal para visualizar os detalhes.</p>
                    "
                );
            }
        }
        
    } catch (Exception $e) {
        error_log("Erro ao notificar atualização: " . $e->getMessage());
    }
}

/**
 * Notificar quando versão é restaurada (log interno)
 */
function eventos_notificar_versao_restaurada(PDO $pdo, int $meeting_id, string $section, int $version_number, int $user_id): void {
    try {
        // Apenas log - não envia notificação
        error_log("[EVENTOS] Versão #{$version_number} restaurada na seção {$section} da reunião {$meeting_id} por user {$user_id}");
        
    } catch (Exception $e) {
        error_log("Erro ao logar restauração: " . $e->getMessage());
    }
}
