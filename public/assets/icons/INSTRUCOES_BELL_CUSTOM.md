# 🔔 Instruções para Adicionar o Sino Customizado

## Passo a Passo

1. **Salve sua imagem PNG** com o nome `bell_custom.png` na pasta:
   ```
   public/assets/icons/bell_custom.png
   ```

2. **Características da imagem:**
   - Formato: PNG com fundo transparente
   - Tamanho recomendado: 40x40px (ou maior, será redimensionado)
   - Sino amarelo com bolinha vermelha no topo direito
   - A bolinha vermelha deve estar na parte superior direita do sino

3. **O código já está configurado para:**
   - Usar `bell_custom.png`
   - Exibir o sino com tamanho 40x40px
   - Posicionar o número dentro da bolinha vermelha
   - Número em branco, negrito (font-weight: 700)
   - Limitar a 99+ se houver mais de 99 notificações

## Localização do Arquivo

Após salvar, o caminho completo será:
```
/Users/tiagozucarelli/Desktop/PAINELSMILEPRO/public/assets/icons/bell_custom.png
```

## Se a Imagem Não Aparecer

- Verifique se o arquivo foi salvo corretamente
- Verifique o nome do arquivo (deve ser exatamente `bell_custom.png`)
- Limpe o cache do navegador (Ctrl+F5 ou Cmd+Shift+R)
- Verifique o console do navegador para erros

## Ajustes Opcionais

Se precisar ajustar o tamanho ou posição, edite em `public/sidebar_unified.php` linha ~217:
- `width: 40px; height: 40px;` → ajusta tamanho do sino
- `top: 2px; right: 2px;` → ajusta posição do número na bolinha

