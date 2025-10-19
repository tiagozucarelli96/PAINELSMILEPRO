-- Script para corrigir o mapeamento de colunas
-- Execute este código no TablePlus

-- 1. Inserir dados de teste com os campos corretos
INSERT INTO lc_listas (grupo_id, tipo, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, tipo_lista)
VALUES (1, 'compras', 'Sala Principal', 'Evento de teste', 1, 'Usuário Teste', 'compras');

-- 2. Inserir mais alguns dados de exemplo
INSERT INTO lc_listas (grupo_id, tipo, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, tipo_lista)
VALUES 
(1, 'compras', 'Sala Principal', 'Aniversário João - 50 convidados', 1, 'Usuário Teste', 'compras'),
(1, 'encomendas', 'Sala Secundária', 'Casamento Maria - 100 convidados', 1, 'Usuário Teste', 'encomendas'),
(1, 'compras', 'Múltiplos', 'Evento Corporativo + Festa', 1, 'Usuário Teste', 'compras');

-- 3. Verificar os dados inseridos
SELECT * FROM lc_listas ORDER BY data_gerada DESC;
