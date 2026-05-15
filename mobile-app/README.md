# Mobile App

Base mobile-first para o app do cliente.

## Requisitos

- Node 18+

## Rodar localmente

```bash
cd mobile-app
npm install
npm run dev
```

Por padrão, a interface consome:

`https://smile-client-app-api-production.up.railway.app/api`

## Fluxo atual

- login com `CPF + data do evento + local`
- persistência do token da sessão
- carregamento do resumo do evento
- cards das áreas do portal com base nas permissões vindas da API

## Próximos passos

- integrar as telas reais de `Reunião Final`, `Convidados` e `Arquivos`
- gerar `android/` e `ios/` com `npx cap add android` e `npx cap add ios`
- sincronizar assets com `npm run cap:sync`
- abrir o projeto nativo com `npm run cap:open:ios` ou `npm run cap:open:android`

## Capacitor

Configuração atual:

- app id: `com.smileeventos.cliente`
- nome do app: `Smile Eventos`
- web dir: `dist`

Status da base nativa:

- `android/` gerado e sincronizado
- `ios/` gerado e com assets copiados
- para concluir a preparação iOS nesta máquina ainda falta `Xcode` completo e `CocoaPods`
