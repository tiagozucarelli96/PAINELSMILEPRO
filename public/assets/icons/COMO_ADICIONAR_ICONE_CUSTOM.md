# 📸 Como Adicionar seu Ícone Customizado de Notificações

## Opção 1: Substituir o SVG atual

1. **Envie sua imagem** para mim (ou coloque diretamente na pasta):
   - Formato recomendado: PNG ou SVG
   - Tamanho ideal: 24x24px ou 48x48px
   - Cores: pode ser colorida, será convertida para branco automaticamente

2. **Substitua o arquivo**:
   - Localização: `public/assets/icons/bell.svg` (ou `bell.png`)
   - Nome do arquivo: pode manter `bell.svg` ou usar outro nome (ex: `bell-custom.png`)

3. **Se usar nome diferente**, atualize em `public/sidebar_unified.php` linha 217:
   ```php
   <img src="assets/icons/SEU_ARQUIVO_AQUI.svg" alt="Notificações" ...>
   ```

## Opção 2: Enviar para mim via chat

**Envie a imagem e me diga:**
- Nome desejado para o arquivo
- Se quer substituir o atual ou criar um novo
- Qualquer ajuste de tamanho/cor necessário

**Eu faço:**
- Salvar na pasta correta
- Atualizar o código para usar sua imagem
- Ajustar tamanho/filtros CSS se necessário

## Especificações Técnicas

- **Localização atual**: `public/assets/icons/bell.svg`
- **Tamanho exibido**: 24x24px
- **Cor**: Branco (filtro CSS aplicado automaticamente)
- **Fundo**: Círculo azul (#3b82f6)
- **Formato aceito**: SVG, PNG, JPG, WEBP

## Estilo Atual

- Fundo circular azul
- Ícone branco no centro
- Contador vermelho no canto superior direito (se houver notificações)

---

**Nota**: Se preferir que eu crie o arquivo, basta me enviar a imagem e as instruções!

