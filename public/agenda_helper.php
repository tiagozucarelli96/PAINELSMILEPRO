<?php
// agenda_helper.php — Helper principal do sistema de agenda interna
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/email_helper.php';

class AgendaHelper {
    private $pdo;
    private $emailHelper;
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        $this->emailHelper = new EmailHelper();
    }
    
    /**
     * Verificar permissões de agenda
     */
    public function canAccessAgenda($usuario_id) {
        $stmt = $this->pdo->prepare("SELECT perm_agenda_ver FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn() ?: false;
    }
    
    public function canCreateEvents($usuario_id) {
        $stmt = $this->pdo->prepare("SELECT perm_agenda_meus FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn() ?: false;
    }
    
    public function canManageOthersEvents($usuario_id) {
        $stmt = $this->pdo->prepare("SELECT perm_gerir_eventos_outros FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn() ?: false;
    }
    
    public function canForceConflict($usuario_id) {
        $stmt = $this->pdo->prepare("SELECT perm_forcar_conflito FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn() ?: false;
    }
    
    public function canViewReports($usuario_id) {
        $stmt = $this->pdo->prepare("SELECT perm_agenda_relatorios FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn() ?: false;
    }
    
    /**
     * Obter espaços disponíveis
     */
    public function obterEspacos() {
        $stmt = $this->pdo->query("SELECT * FROM agenda_espacos WHERE ativo = TRUE ORDER BY nome");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter usuários com cores
     */
    public function obterUsuariosComCores() {
        $stmt = $this->pdo->query("
            SELECT id, nome, cor_agenda, agenda_lembrete_padrao_min 
            FROM usuarios 
            WHERE ativo = TRUE 
            ORDER BY nome
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verificar conflitos
     */
    public function verificarConflitos($responsavel_id, $espaco_id, $inicio, $fim, $evento_id = null) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM verificar_conflito_agenda(?, ?, ?, ?, ?)
        ");
        $stmt->execute([$responsavel_id, $espaco_id, $inicio, $fim, $evento_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Criar evento
     */
    public function criarEvento($dados) {
        try {
            // Verificar conflitos se não forçar
            if (!$dados['forcar_conflito']) {
                $conflito = $this->verificarConflitos(
                    $dados['responsavel_usuario_id'],
                    $dados['espaco_id'],
                    $dados['inicio'],
                    $dados['fim']
                );
                
                if ($conflito['conflito_responsavel'] || $conflito['conflito_espaco']) {
                    return [
                        'success' => false,
                        'error' => 'Conflito detectado',
                        'conflito' => $conflito
                    ];
                }
            }
            
            // Obter cor do responsável
            $stmt = $this->pdo->prepare("SELECT cor_agenda FROM usuarios WHERE id = ?");
            $stmt->execute([$dados['responsavel_usuario_id']]);
            $cor_responsavel = $stmt->fetchColumn();
            
            // Criar evento
            $stmt = $this->pdo->prepare("
                INSERT INTO agenda_eventos (
                    tipo, titulo, descricao, inicio, fim, responsavel_usuario_id, 
                    criado_por_usuario_id, espaco_id, lembrete_minutos, 
                    participantes, cor_evento
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $dados['tipo'],
                $dados['titulo'],
                $dados['descricao'],
                $dados['inicio'],
                $dados['fim'],
                $dados['responsavel_usuario_id'],
                $dados['criado_por_usuario_id'],
                $dados['espaco_id'],
                $dados['lembrete_minutos'],
                json_encode($dados['participantes'] ?? []),
                $cor_responsavel
            ]);
            
            $evento_id = $this->pdo->lastInsertId();
            
            // Enviar notificação de criação
            $this->enviarNotificacaoEvento($evento_id, 'criacao');
            
            return [
                'success' => true,
                'evento_id' => $evento_id
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Atualizar evento
     */
    public function atualizarEvento($evento_id, $dados) {
        try {
            // Verificar conflitos se não forçar
            if (!$dados['forcar_conflito']) {
                $conflito = $this->verificarConflitos(
                    $dados['responsavel_usuario_id'],
                    $dados['espaco_id'],
                    $dados['inicio'],
                    $dados['fim'],
                    $evento_id
                );
                
                if ($conflito['conflito_responsavel'] || $conflito['conflito_espaco']) {
                    return [
                        'success' => false,
                        'error' => 'Conflito detectado',
                        'conflito' => $conflito
                    ];
                }
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE agenda_eventos SET 
                    tipo = ?, titulo = ?, descricao = ?, inicio = ?, fim = ?, 
                    responsavel_usuario_id = ?, espaco_id = ?, lembrete_minutos = ?, 
                    status = ?, compareceu = ?, fechou_contrato = ?, fechou_ref = ?,
                    participantes = ?
                WHERE id = ?
            ");
            
            $stmt->execute([
                $dados['tipo'],
                $dados['titulo'],
                $dados['descricao'],
                $dados['inicio'],
                $dados['fim'],
                $dados['responsavel_usuario_id'],
                $dados['espaco_id'],
                $dados['lembrete_minutos'],
                $dados['status'],
                $dados['compareceu'] ?? false,
                $dados['fechou_contrato'] ?? false,
                $dados['fechou_ref'],
                json_encode($dados['participantes'] ?? []),
                $evento_id
            ]);
            
            // Enviar notificação de alteração
            $this->enviarNotificacaoEvento($evento_id, 'alteracao');
            
            return [
                'success' => true
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Excluir evento
     */
    public function excluirEvento($evento_id) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM agenda_eventos WHERE id = ?");
            $stmt->execute([$evento_id]);
            
            return [
                'success' => true
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obter eventos para calendário
     */
    public function obterEventosCalendario($usuario_id, $inicio, $fim, $filtros = []) {
        $where_conditions = ["ae.inicio >= ?", "ae.fim <= ?"];
        $params = [$inicio, $fim];
        
        // Filtro por responsável
        if (isset($filtros['responsavel_id']) && $filtros['responsavel_id']) {
            $where_conditions[] = "ae.responsavel_usuario_id = ?";
            $params[] = $filtros['responsavel_id'];
        }
        
        // Filtro por espaço
        if (isset($filtros['espaco_id']) && $filtros['espaco_id']) {
            $where_conditions[] = "ae.espaco_id = ?";
            $params[] = $filtros['espaco_id'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $stmt = $this->pdo->prepare("
            SELECT 
                ae.id,
                ae.tipo,
                ae.titulo,
                ae.descricao,
                ae.inicio,
                ae.fim,
                ae.status,
                ae.compareceu,
                ae.fechou_contrato,
                u.nome as responsavel_nome,
                u.cor_agenda,
                esp.nome as espaco_nome,
                criador.nome as criado_por_nome
            FROM agenda_eventos ae
            JOIN usuarios u ON ae.responsavel_usuario_id = u.id
            LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
            JOIN usuarios criador ON ae.criado_por_usuario_id = criador.id
            WHERE {$where_clause}
            AND ae.status != 'cancelado'
            ORDER BY ae.inicio ASC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter agenda do dia
     */
    public function obterAgendaDia($usuario_id, $horas = 24) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM obter_proximos_eventos(?, ?)
        ");
        $stmt->execute([$usuario_id, $horas]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Enviar notificação de evento
     */
    private function enviarNotificacaoEvento($evento_id, $tipo) {
        try {
            // Buscar dados do evento
            $stmt = $this->pdo->prepare("
                SELECT ae.*, u.nome as responsavel_nome, u.email as responsavel_email,
                       esp.nome as espaco_nome, criador.nome as criado_por_nome
                FROM agenda_eventos ae
                JOIN usuarios u ON ae.responsavel_usuario_id = u.id
                LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
                JOIN usuarios criador ON ae.criado_por_usuario_id = criador.id
                WHERE ae.id = ?
            ");
            $stmt->execute([$evento_id]);
            $evento = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$evento) return;
            
            // Preparar dados do e-mail
            $assunto = "Agenda: {$evento['titulo']} - " . date('d/m/Y H:i', strtotime($evento['inicio']));
            
            $mensagem = "
            <h3>📅 {$evento['titulo']}</h3>
            <p><strong>Data/Hora:</strong> " . date('d/m/Y H:i', strtotime($evento['inicio'])) . " - " . date('H:i', strtotime($evento['fim'])) . "</p>
            <p><strong>Duração:</strong> " . $this->calcularDuracao($evento['inicio'], $evento['fim']) . "</p>
            ";
            
            if ($evento['espaco_nome']) {
                $mensagem .= "<p><strong>Espaço:</strong> {$evento['espaco_nome']}</p>";
            }
            
            if ($evento['descricao']) {
                $mensagem .= "<p><strong>Observações:</strong> {$evento['descricao']}</p>";
            }
            
            $mensagem .= "<p><strong>Agendado por:</strong> {$evento['criado_por_nome']}</p>";
            
            if ($tipo === 'criacao') {
                $mensagem .= "<p>✅ Evento criado com sucesso!</p>";
            } else {
                $mensagem .= "<p>📝 Evento atualizado!</p>";
            }
            
            // Enviar e-mail
            $this->emailHelper->enviarNotificacao(
                $evento['responsavel_email'],
                $evento['responsavel_nome'],
                $assunto,
                $mensagem
            );
            
        } catch (Exception $e) {
            error_log("Erro ao enviar notificação de evento: " . $e->getMessage());
        }
    }
    
    /**
     * Calcular duração entre dois timestamps
     */
    private function calcularDuracao($inicio, $fim) {
        $diff = strtotime($fim) - strtotime($inicio);
        $horas = floor($diff / 3600);
        $minutos = floor(($diff % 3600) / 60);
        
        if ($horas > 0) {
            return "{$horas}h {$minutos}min";
        } else {
            return "{$minutos}min";
        }
    }
    
    /**
     * Obter relatório de conversão
     */
    public function obterRelatorioConversao($data_inicio, $data_fim, $espaco_id = null, $responsavel_id = null) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM calcular_conversao_visitas(?, ?, ?, ?)
        ");
        $stmt->execute([$data_inicio, $data_fim, $espaco_id, $responsavel_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Gerar token ICS
     */
    public function gerarTokenICS($usuario_id) {
        $stmt = $this->pdo->prepare("SELECT gerar_token_ics(?)");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Obter eventos para ICS
     */
    public function obterEventosICS($usuario_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                ae.id,
                ae.titulo,
                ae.descricao,
                ae.inicio,
                ae.fim,
                ae.tipo,
                esp.nome as espaco_nome
            FROM agenda_eventos ae
            LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
            WHERE ae.responsavel_usuario_id = ?
            AND ae.status != 'cancelado'
            ORDER BY ae.inicio ASC
        ");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Sugerir próximo horário livre
     */
    public function sugerirProximoHorario($responsavel_id, $espaco_id, $duracao_minutos = 60) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COALESCE(MAX(fim), NOW()) + INTERVAL '1 hour' as proximo_horario
            FROM agenda_eventos 
            WHERE responsavel_usuario_id = ?
            AND status != 'cancelado'
            AND fim >= NOW()
        ");
        $stmt->execute([$responsavel_id]);
        $proximo = $stmt->fetchColumn();
        
        return [
            'inicio' => $proximo,
            'fim' => date('Y-m-d H:i:s', strtotime($proximo . " + {$duracao_minutos} minutes"))
        ];
    }
}
?>
