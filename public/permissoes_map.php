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
    // Logística
    'logistica' => 'perm_logistico',
    'logistica_operacional' => 'perm_logistico',
    'logistica_divergencias' => 'perm_logistico_divergencias',
    'logistica_financeiro' => 'perm_logistico_financeiro',
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
    'usuarios' => 'perm_configuracoes',
    // 'config_usuarios' => 'perm_configuracoes', // REMOVIDO: tela antiga de usuários
    // 'usuario_novo' => 'perm_configuracoes', // REMOVIDO: tela antiga de usuários
    // 'usuario_editar' => 'perm_configuracoes', // REMOVIDO: tela antiga de usuários
    'config_logistica' => 'perm_configuracoes',
    'logistica_catalogo' => 'perm_logistico',
    'logistica_conexao' => 'perm_configuracoes',
    'logistica_unidades_medida' => 'perm_configuracoes',
    'logistica_tipologias' => 'perm_logistico',
    'logistica_insumos' => 'perm_logistico',
    'logistica_receitas' => 'perm_logistico',
    'logistica_gerar_lista' => 'perm_logistico',
    'logistica_listas' => 'perm_logistico',
    'logistica_lista_pdf' => 'perm_logistico',
    'logistica_estoque' => 'perm_logistico',
    'logistica_contagem' => 'perm_logistico',
    'logistica_entrada' => 'perm_logistico',
    'logistica_transferencias' => 'perm_logistico',
    'logistica_transferencia_ver' => 'perm_logistico',
    'logistica_transferencia_receber' => 'perm_logistico',
    'logistica_saldo' => 'perm_logistico',
    'logistica_upload' => 'perm_logistico',
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
