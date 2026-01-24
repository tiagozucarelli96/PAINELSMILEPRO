<?php
// me_config.php - Configurações da API da ME Eventos

/**
 * IMPORTANTE (SEGURANÇA):
 * - NÃO manter token/chaves hardcoded no repositório.
 * - Configure via variáveis de ambiente no Railway/servidor.
 *
 * Variáveis suportadas (ordem de prioridade):
 * - ME_BASE_URL
 * - ME_API_TOKEN (preferencial) ou ME_API_KEY
 */

define('ME_BASE_URL', getenv('ME_BASE_URL') ?: 'https://app2.meeventos.com.br/lisbonbuffet');
define('ME_API_KEY', getenv('ME_API_TOKEN') ?: (getenv('ME_API_KEY') ?: ''));

