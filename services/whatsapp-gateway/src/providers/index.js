import { config } from "../config.js";
import { BaileysProvider } from "./baileys-provider.js";
import { MockProvider } from "./mock-provider.js";
import { WhatsAppWebJsProvider } from "./wwebjs-provider.js";

export function createProvider(providerName, context) {
  const normalized = providerName || config.defaultProvider;

  if (normalized === "mock") {
    return new MockProvider(context);
  }

  if (normalized === "whatsapp-web.js") {
    return new WhatsAppWebJsProvider(context);
  }

  if (normalized === "baileys") {
    context.logger?.warn?.(
      { sessionKey: context.sessionKey },
      "Baileys indisponivel para novo pareamento; usando whatsapp-web.js como fallback operacional"
    );
    return new WhatsAppWebJsProvider(context);
  }

  if (normalized === "wppconnect") {
    return new BaileysProvider(context);
  }

  throw new Error(`Provider nao suportado: ${normalized}`);
}
