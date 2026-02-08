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
        // Buscar dados do evento/reunião
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   (r.me_event_snapshot->>'data') as data_evento,
                   (r.me_event_snapshot->>'hora_inicio') as hora_inicio,
                   (r.me_event_snapshot->>'hora_fim') as hora_fim,
                   (r.me_event_snapshot->>'local') as local_evento,
                   (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome,
                   (r.me_event_snapshot->'cliente'->>'email') as cliente_email,
                   (r.me_event_snapshot->'cliente'->>'telefone') as cliente_telefone,
                   r.fornecedor_dj_id
            FROM eventos_reunioes r
            WHERE r.id = :id
        ");
        $stmt->execute([':id' => $meeting_id]);
        $reuniao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reuniao) {
            return;
        }

        $e = function($value): string {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };

        $nome_evento = (string)($reuniao['nome_evento'] ?? 'Evento');
        $data_evento = (string)($reuniao['data_evento'] ?? '');
        $data_ts = $data_evento !== '' ? strtotime($data_evento) : false;
        $data_fmt = $data_ts ? date('d/m/Y', $data_ts) : '-';
        $hora_inicio = trim((string)($reuniao['hora_inicio'] ?? ''));
        $hora_fim = trim((string)($reuniao['hora_fim'] ?? ''));
        $hora_fmt = $hora_inicio !== '' ? $hora_inicio : '-';
        if ($hora_inicio !== '' && $hora_fim !== '') {
            $hora_fmt = $hora_inicio . ' - ' . $hora_fim;
        }
        $local_evento = trim((string)($reuniao['local_evento'] ?? ''));
        $cliente_nome = trim((string)($reuniao['cliente_nome'] ?? ''));
        
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
        
        // Notificar DJ por e-mail (usa fornecedor vinculado; se não houver, envia para todos os DJs ativos)
        $emails = [];

        if (!empty($reuniao['fornecedor_dj_id'])) {
            $stmt = $pdo->prepare("SELECT email FROM eventos_fornecedores WHERE id = :id AND email IS NOT NULL");
            $stmt->execute([':id' => $reuniao['fornecedor_dj_id']]);
            $dj_email = trim((string)($stmt->fetchColumn() ?: ''));
            if ($dj_email !== '') {
                $emails[] = $dj_email;
            }
        }

        if (empty($emails)) {
            $stmt = $pdo->query("
                SELECT email
                FROM eventos_fornecedores
                WHERE tipo = 'dj'
                  AND ativo = TRUE
                  AND email IS NOT NULL
                  AND email <> ''
            ");
            $emails = array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }

        $emails = array_values(array_unique(array_filter($emails, fn($x) => is_string($x) && trim($x) !== '')));
        if (!empty($emails)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

            $base_url = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
            if ($base_url === '' && $host !== '') {
                $base_url = $scheme . '://' . $host;
            }
            $base_url = rtrim($base_url, '/');
            $portal_url = $base_url !== ''
                ? $base_url . "/index.php?page=portal_dj&evento=" . (int)$meeting_id
                : "index.php?page=portal_dj&evento=" . (int)$meeting_id;

            $assunto = "FORMULARIO PREENCHIDO - SMILE EVENTOS";
            $nome_evento_e = $e($nome_evento);
            $data_fmt_e = $e($data_fmt);
            $hora_fmt_e = $e($hora_fmt);
            $local_e = $e($local_evento !== '' ? $local_evento : '-');
            $cliente_e = $e($cliente_nome !== '' ? $cliente_nome : '-');
            $portal_url_e = $e($portal_url);
            $corpo = "
                <h2>Formulário preenchido</h2>
                <p>O cliente enviou/atualizou o formulário de DJ do evento abaixo:</p>
                <p><strong>Evento:</strong> {$nome_evento_e}</p>
                <p><strong>Data:</strong> {$data_fmt_e}</p>
                <p><strong>Horário:</strong> {$hora_fmt_e}</p>
                <p><strong>Local:</strong> {$local_e}</p>
                <p><strong>Cliente:</strong> {$cliente_e}</p>
                <br>
                <p><a href='{$portal_url_e}' style='display:inline-block;padding:12px 18px;background:#1e3a8a;color:#fff;text-decoration:none;border-radius:8px;'>Abrir no Portal DJ</a></p>
                <p style='color:#64748b;font-size:14px;margin-top:10px;'>Se o link solicitar login, entre com seu usuário do Portal DJ.</p>
                <br>
                <p>Atenciosamente,<br>Grupo Smile</p>
            ";

            $emailHelper = new EmailGlobalHelper();
            foreach ($emails as $to) {
                $emailHelper->enviarEmail($to, $assunto, $corpo);
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
