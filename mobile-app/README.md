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
- adicionar `Capacitor`
- gerar `android/` e `ios/`
