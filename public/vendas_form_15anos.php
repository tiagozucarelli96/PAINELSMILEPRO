<?php
/**
 * vendas_form_15anos.php
 * Formulário público para cadastro de contrato - 15 Anos / Debutante
 * Reutiliza o template de casamento com variáveis personalizadas
 */

// Definir variáveis antes de incluir o template
$tipo_evento = '15anos';
$titulo = 'Cadastro do Contrato - 15 Anos';
$label_nome_noivos = 'Nome da Debutante';
$placeholder_nome_noivos = 'Ex: Maria Silva';
$erro_nome_noivos = 'Nome da debutante é obrigatório';
$log_prefix = '15anos';

// Incluir o template base de casamento (ele usará as variáveis definidas acima)
require __DIR__ . '/vendas_form_casamento.php';
