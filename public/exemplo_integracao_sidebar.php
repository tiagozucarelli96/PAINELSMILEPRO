<?php
// exemplo_integracao_sidebar.php — Exemplo de como integrar a sidebar

// Incluir sistema de integração
require_once __DIR__ . '/sidebar_integration.php';

// Iniciar sidebar
includeSidebar();

// Definir título da página
setPageTitle('Exemplo de Integração');

// Adicionar breadcrumb
addBreadcrumb([
    ['title' => 'Dashboard', 'url' => 'index.php?page=dashboard'],
    ['title' => 'Exemplo', 'url' => ''],
    ['title' => 'Integração Sidebar']
]);

// Conteúdo da página
?>
<div class="page-container">
    <div class="page-header">
        <h1 class="page-title">🎯 Exemplo de Integração da Sidebar</h1>
        <p class="page-subtitle">Demonstração de como usar a sidebar moderna em suas páginas</p>
    </div>
    
    <div class="card">
        <h2 style="margin-bottom: 20px; color: #1e3a8a;">Como Integrar a Sidebar</h2>
        
        <h3>1. Incluir o sistema de integração</h3>
        <pre style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0;">
require_once __DIR__ . '/sidebar_integration.php';
includeSidebar();
        </pre>
        
        <h3>2. Adicionar conteúdo da página</h3>
        <pre style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0;">
// Seu conteúdo HTML aqui
echo '&lt;div class="page-container"&gt;';
echo '&lt;h1&gt;Minha Página&lt;/h1&gt;';
echo '&lt;/div&gt;';
        </pre>
        
        <h3>3. Finalizar a sidebar</h3>
        <pre style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 10px 0;">
endSidebar();
        </pre>
    </div>
    
    <div class="card">
        <h2 style="margin-bottom: 20px; color: #1e3a8a;">Funcionalidades Disponíveis</h2>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            <div>
                <h4>🎨 Estilização</h4>
                <ul>
                    <li>Classes CSS pré-definidas</li>
                    <li>Design responsivo</li>
                    <li>Animações suaves</li>
                    <li>Tema consistente</li>
                </ul>
            </div>
            
            <div>
                <h4>🔧 Utilitários</h4>
                <ul>
                    <li>setPageTitle() - Definir título</li>
                    <li>addBreadcrumb() - Adicionar navegação</li>
                    <li>addAlert() - Mostrar alertas</li>
                    <li>addPageCSS() - CSS adicional</li>
                </ul>
            </div>
            
            <div>
                <h4>📱 Responsivo</h4>
                <ul>
                    <li>Sidebar colapsável</li>
                    <li>Botão voltar inteligente</li>
                    <li>Overlay para mobile</li>
                    <li>Toggle automático</li>
                </ul>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2 style="margin-bottom: 20px; color: #1e3a8a;">Exemplo de Alertas</h2>
        
        <?php
        // Exemplo de alertas
        addAlert('Este é um alerta de sucesso!', 'success');
        addAlert('Este é um alerta de erro!', 'error');
        addAlert('Este é um alerta de aviso!', 'warning');
        addAlert('Este é um alerta informativo!', 'info');
        ?>
    </div>
    
    <div class="card">
        <h2 style="margin-bottom: 20px; color: #1e3a8a;">Exemplo de Tabela</h2>
        
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>João Silva</td>
                    <td>joao@email.com</td>
                    <td><span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Ativo</span></td>
                    <td>
                        <button class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">Editar</button>
                        <button class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">Excluir</button>
                    </td>
                </tr>
                <tr>
                    <td>2</td>
                    <td>Maria Santos</td>
                    <td>maria@email.com</td>
                    <td><span style="background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Inativo</span></td>
                    <td>
                        <button class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">Editar</button>
                        <button class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;">Excluir</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="card">
        <h2 style="margin-bottom: 20px; color: #1e3a8a;">Exemplo de Formulário</h2>
        
        <form>
            <div class="form-group">
                <label class="form-label" for="nome">Nome</label>
                <input type="text" id="nome" class="form-input" placeholder="Digite seu nome">
            </div>
            
            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input type="email" id="email" class="form-input" placeholder="Digite seu email">
            </div>
            
            <div class="form-group">
                <label class="form-label" for="categoria">Categoria</label>
                <select id="categoria" class="form-select">
                    <option value="">Selecione uma categoria</option>
                    <option value="1">Categoria 1</option>
                    <option value="2">Categoria 2</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="observacoes">Observações</label>
                <textarea id="observacoes" class="form-textarea" rows="4" placeholder="Digite suas observações"></textarea>
            </div>
            
            <div style="display: flex; gap: 15px;">
                <button type="submit" class="btn btn-primary">Salvar</button>
                <button type="button" class="btn btn-secondary">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<?php
// Finalizar sidebar
endSidebar();
?>
