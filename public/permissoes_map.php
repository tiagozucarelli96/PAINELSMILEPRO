<?php
// public/permissoes_map.php — Mapeamento de páginas para permissões
// Define qual permissão é necessária para cada página/modulo

return [
    // Dashboard - sempre permitido (todos logados podem acessar)
    'dashboard' => null,
    
    // Scripts de setup - sempre permitido para usuários logados
    'apply_permissoes_sidebar_columns' => null,
    'habilitar_todas_permissoes' => null,
    
    // Módulos principais da sidebar
    'agenda' => 'perm_agenda',
    'demandas' => 'perm_demandas',
    'comercial' => 'perm_comercial',
    // 'logistico' => 'perm_logistico', // REMOVIDO: Módulo desativado
    // 'lc_index' => 'perm_logistico', // REMOVIDO: Módulo desativado
    'configuracoes' => 'perm_configuracoes',
    'cadastros' => 'perm_cadastros',
    'financeiro' => 'perm_financeiro',
    'administrativo' => 'perm_administrativo',
    'contabilidade' => 'perm_administrativo',
    'rh' => 'perm_administrativo',
    'banco_smile' => 'perm_banco_smile',
    'banco_smile_main' => 'perm_banco_smile',
    'banco_smile_admin' => 'perm_banco_smile_admin',
    
    // Páginas específicas dentro dos módulos
    
    // Agenda
    'agenda_config' => 'perm_agenda',
    'agenda_relatorios' => 'perm_agenda',
    
    // Comercial
    'comercial_degustacoes' => 'perm_comercial',
    'comercial_inscritos' => 'perm_comercial',
    'comercial_degust_inscritos' => 'perm_comercial',
    'comercial_degust_inscricoes' => 'perm_comercial',
    'comercial_degustacao_editar' => 'perm_comercial',
    'comercial_clientes' => 'perm_comercial',
    'comercial_pagamento' => 'perm_comercial',
    'comercial_inscritos_cadastrados' => 'perm_comercial',
    'comercial_lista_espera' => 'perm_comercial',
    'comercial_realizar_degustacao' => 'perm_comercial',
    
    // Logístico - REMOVIDO: Módulo desativado
    // 'lista_compras' => 'perm_logistico',
    // 'lista' => 'perm_logistico',
    // 'lc_ver' => 'perm_logistico',
    // 'lc_pdf' => 'perm_logistico',
    // 'estoque' => 'perm_logistico',
    // 'estoque_logistico' => 'perm_logistico',
    // 'estoque_kardex' => 'perm_logistico',
    // 'kardex' => 'perm_logistico',
    // 'estoque_contagens' => 'perm_logistico',
    // 'contagens' => 'perm_logistico',
    // 'estoque_alertas' => 'perm_logistico',
    // 'alertas' => 'perm_logistico',
    // 'ver' => 'perm_logistico',
    
    // Configurações
    'config_usuarios' => 'perm_configuracoes',
    'usuarios' => 'perm_configuracoes',
    'usuario_novo' => 'perm_configuracoes',
    'usuario_editar' => 'perm_configuracoes',
    // REMOVIDO: Módulo desativado
    // 'config_insumos' => 'perm_configuracoes',
    // 'config_categorias' => 'perm_configuracoes',
    // 'config_fichas' => 'perm_configuracoes',
    // 'config_itens' => 'perm_configuracoes',
    // 'config_itens_fixos' => 'perm_configuracoes',
    'config_sistema' => 'perm_configuracoes',
    
    // Cadastros (módulos removidos)
    
    // Financeiro (módulo removido)
    
    // Administrativo
    'administrativo_auditoria' => 'perm_administrativo',
    'administrativo_stats' => 'perm_administrativo',
    'administrativo_historico' => 'perm_administrativo',
    'verificacao_completa_erros' => 'perm_administrativo',
    'sistema_unificado' => 'perm_administrativo',
    'historico' => 'perm_administrativo',
    'notas_fiscais' => 'perm_notas_fiscais',
    
    // RH (transferido para Administrativo)
    'rh_dashboard' => 'perm_administrativo',
    'rh_colaboradores' => 'perm_administrativo',
    'rh_colaborador_ver' => 'perm_administrativo',
    'rh_holerite_upload' => 'perm_administrativo',
    
    // Outros (usando permissões existentes)
    'portao' => 'perm_portao',
    'dados_contrato' => 'perm_dados_contrato',
    'uso_fiorino' => 'perm_uso_fiorino',
];
