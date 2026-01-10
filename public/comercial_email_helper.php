<?php
// comercial_email_helper.php — Helper para envio de e-mails SMTP (usa EmailGlobalHelper)
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/email_global_helper.php';

class ComercialEmailHelper {
    private $emailGlobal;
    
    public function __construct() {
        // Usar EmailGlobalHelper que utiliza sistema_email_config
        $this->emailGlobal = new EmailGlobalHelper();
    }
    
    /**
     * Enviar e-mail usando sistema global
     */
    public function sendEmail($to, $subject, $body, $isHtml = true) {
        return $this->emailGlobal->enviarEmail($to, $subject, $body, $isHtml);
    }
    
    public function sendInscricaoConfirmation($inscricao, $degustacao) {
        $subject = "Confirmação de Inscrição - " . $degustacao['nome'];
        
        // Usar template personalizado se disponível
        $body = $degustacao['email_confirmacao_html'] ?: $this->getDefaultConfirmationTemplate($inscricao, $degustacao);
        
        // Substituir variáveis no template
        $body = $this->replaceTemplateVariables($body, $inscricao, $degustacao);
        
        return $this->sendEmail($inscricao['email'], $subject, $body, true);
    }
    
    public function sendListaEsperaNotification($inscricao, $degustacao) {
        $subject = "Lista de Espera - " . $degustacao['nome'];
        
        $body = $this->getListaEsperaTemplate($inscricao, $degustacao);
        $body = $this->replaceTemplateVariables($body, $inscricao, $degustacao);
        
        return $this->sendEmail($inscricao['email'], $subject, $body, true);
    }
    
    private function getDefaultConfirmationTemplate($inscricao, $degustacao) {
        return "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Confirmação de Inscrição</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f8fafc; padding: 30px; border-radius: 0 0 8px 8px; }
                .event-info { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .info-row { display: flex; justify-content: space-between; margin-bottom: 10px; padding: 5px 0; border-bottom: 1px solid #e5e7eb; }
                .info-label { font-weight: 600; color: #1e3a8a; }
                .info-value { color: #374151; }
                .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✅ Inscrição Confirmada!</h1>
                    <p>Obrigado por se inscrever em nossa degustação</p>
                </div>
                
                <div class='content'>
                    <h2>Olá, {NOME}!</h2>
                    <p>Sua inscrição foi confirmada com sucesso. Aqui estão os detalhes:</p>
                    
                    <div class='event-info'>
                        <h3>{DEGUSTACAO_NOME}</h3>
                        <div class='info-row'>
                            <span class='info-label'>📅 Data:</span>
                            <span class='info-value'>{DEGUSTACAO_DATA}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>🕐 Horário:</span>
                            <span class='info-value'>{DEGUSTACAO_HORARIO}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📍 Local:</span>
                            <span class='info-value'>{DEGUSTACAO_LOCAL}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>👥 Pessoas:</span>
                            <span class='info-value'>{QTD_PESSOAS}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>🎉 Tipo de Festa:</span>
                            <span class='info-value'>{TIPO_FESTA}</span>
                        </div>
                    </div>
                    
                    <p>Estamos ansiosos para recebê-lo(a) em nossa degustação!</p>
                    
                    <div class='footer'>
                        <p>GRUPO Smile EVENTOS</p>
                        <p>Para dúvidas, entre em contato conosco</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
    }
    
    private function getListaEsperaTemplate($inscricao, $degustacao) {
        return "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Lista de Espera</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f8fafc; padding: 30px; border-radius: 0 0 8px 8px; }
                .event-info { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; }
                .info-row { display: flex; justify-content: space-between; margin-bottom: 10px; padding: 5px 0; border-bottom: 1px solid #e5e7eb; }
                .info-label { font-weight: 600; color: #f59e0b; }
                .info-value { color: #374151; }
                .footer { text-align: center; margin-top: 30px; color: #6b7280; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏳ Lista de Espera</h1>
                    <p>Você foi adicionado à nossa lista de espera</p>
                </div>
                
                <div class='content'>
                    <h2>Olá, {NOME}!</h2>
                    <p>A degustação <strong>{DEGUSTACAO_NOME}</strong> está lotada, mas você foi adicionado à nossa lista de espera.</p>
                    
                    <div class='event-info'>
                        <h3>{DEGUSTACAO_NOME}</h3>
                        <div class='info-row'>
                            <span class='info-label'>📅 Data:</span>
                            <span class='info-value'>{DEGUSTACAO_DATA}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>🕐 Horário:</span>
                            <span class='info-value'>{DEGUSTACAO_HORARIO}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>📍 Local:</span>
                            <span class='info-value'>{DEGUSTACAO_LOCAL}</span>
                        </div>
                    </div>
                    
                    <p>Se houver desistências, entraremos em contato para confirmar sua participação.</p>
                    
                    <div class='footer'>
                        <p>GRUPO Smile EVENTOS</p>
                        <p>Para dúvidas, entre em contato conosco</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
    }
    
    private function replaceTemplateVariables($body, $inscricao, $degustacao) {
        $replacements = [
            '{NOME}' => $inscricao['nome'],
            '{EMAIL}' => $inscricao['email'],
            '{CELULAR}' => $inscricao['celular'] ?: 'Não informado',
            '{QTD_PESSOAS}' => $inscricao['qtd_pessoas'],
            '{TIPO_FESTA}' => ucfirst($inscricao['tipo_festa']),
            '{DEGUSTACAO_NOME}' => $degustacao['nome'],
            '{DEGUSTACAO_DATA}' => date('d/m/Y', strtotime($degustacao['data'])),
            '{DEGUSTACAO_HORARIO}' => date('H:i', strtotime($degustacao['hora_inicio'])) . ' - ' . date('H:i', strtotime($degustacao['hora_fim'])),
            '{DEGUSTACAO_LOCAL}' => $degustacao['local']
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $body);
    }
    
    public function testEmail($to) {
        $subject = "Teste de E-mail - GRUPO Smile EVENTOS";
        $body = "
        <h2>Teste de E-mail</h2>
        <p>Este é um e-mail de teste para verificar se a configuração SMTP está funcionando corretamente.</p>
        <p>Data/Hora: " . date('d/m/Y H:i:s') . "</p>
        <p>Se você recebeu este e-mail, a configuração está funcionando!</p>
        ";
        
        return $this->sendEmail($to, $subject, $body, true);
    }
    
    public function updateSmtpConfig($config) {
        $sql = "UPDATE comercial_email_config SET 
                smtp_host = :smtp_host, smtp_port = :smtp_port, smtp_username = :smtp_username,
                smtp_password = :smtp_password, from_name = :from_name, from_email = :from_email,
                reply_to = :reply_to, atualizado_em = NOW()
                WHERE ativo = TRUE";
        
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($config);
        
        if ($result) {
            $this->loadSmtpConfig(); // Recarregar configuração
        }
        
        return $result;
    }
}
