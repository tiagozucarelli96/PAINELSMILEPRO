import { config } from "../config.js";
import { BaileysProvider } from "./baileys-provider.js";
import { MockProvider } from "./mock-provider.js";

export function createProvider(providerName, context) {
  const normalized = providerName || config.defaultProvider;

  if (normalized === "mock") {
    return new MockProvider(context);
  }

  if (normalized === "baileys" || normalized === "whatsapp-web.js" || normalized === "wppconnect") {
    return new BaileysProvider(context);
  }

  throw new Error(`Provider nao suportado: ${normalized}`);
}
