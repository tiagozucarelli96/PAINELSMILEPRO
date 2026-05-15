import fs from "node:fs";
import path from "node:path";
import wwebjs from "whatsapp-web.js";
import { config } from "../config.js";

const { Client, LocalAuth } = wwebjs;

function normalizePhoneNumber(phoneNumber) {
  return String(phoneNumber || "").replace(/\D+/g, "");
}

function buildChatId(phoneE164) {
  const digits = normalizePhoneNumber(phoneE164);
  if (!digits) {
    throw new Error("Numero de telefone invalido para envio.");
  }

  return `${digits}@c.us`;
}

function detectMessageType(message) {
  if (message?.type === "image") {
    return "image";
  }
  if (message?.type === "video") {
    return "video";
  }
  if (message?.type === "audio" || message?.type === "ptt") {
    return "audio";
  }
  if (message?.type === "document") {
    return "file";
  }
  return "text";
}

export class WhatsAppWebJsProvider {
  constructor({ sessionKey, callbacks, logger }) {
    this.sessionKey = sessionKey;
    this.callbacks = callbacks;
    this.logger = logger;
    this.client = null;
    this.connected = false;
    this.initializing = null;
    this.stopRequested = false;
  }

  async connect({ phoneNumber } = {}) {
    this.stopRequested = false;

    if (this.initializing) {
      return this.initializing;
    }

    if (this.client) {
      return;
    }

    await this.callbacks.onStatus("connecting", {
      phoneNumber: phoneNumber || null,
    });

    this.initializing = this.openClient(phoneNumber || null).finally(() => {
      this.initializing = null;
    });

    return this.initializing;
  }

  async disconnect() {
    this.stopRequested = true;
    await this.teardownClient();
    this.connected = false;
    await this.callbacks.onStatus("disconnected", {
      connectedAt: null,
    });
  }

  async sendText({ phoneE164, body }) {
    if (!this.client || !this.connected) {
      throw new Error("Sessao WhatsApp Web nao conectada.");
    }

    const chatId = buildChatId(phoneE164);
    const message = await this.client.sendMessage(chatId, body);

    return {
      externalMessageId: message?.id?._serialized || null,
      acceptedAt: new Date().toISOString(),
      body,
    };
  }

  async openClient(phoneNumber) {
    const cachePath = path.join(config.wwebjsCacheDir, this.sessionKey);
    fs.mkdirSync(config.wwebjsAuthDir, { recursive: true });
    fs.mkdirSync(cachePath, { recursive: true });

    const client = new Client({
      authStrategy: new LocalAuth({
        clientId: this.sessionKey,
        dataPath: config.wwebjsAuthDir,
      }),
      puppeteer: {
        headless: true,
        executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || undefined,
        args: [
          "--no-sandbox",
          "--disable-setuid-sandbox",
          "--disable-dev-shm-usage",
          "--disable-accelerated-2d-canvas",
          "--disable-gpu",
          "--no-first-run",
          "--no-zygote",
          "--single-process",
        ],
      },
      webVersionCache: {
        type: "local",
        path: path.join(cachePath, "web-version-cache.html"),
      },
    });

    this.client = client;
    this.bindEvents(client, phoneNumber);
    await client.initialize();
  }

  bindEvents(client, phoneNumber) {
    client.on("qr", async (qrText) => {
      if (client !== this.client) {
        return;
      }

      await this.callbacks.onQr(qrText);
    });

    client.on("authenticated", async () => {
      if (client !== this.client) {
        return;
      }

      this.logger.info({ sessionKey: this.sessionKey }, "Sessao whatsapp-web.js autenticada");
    });

    client.on("ready", async () => {
      if (client !== this.client) {
        return;
      }

      this.connected = true;
      const wid = client.info?.wid?._serialized || "";
      const digits = wid.replace(/@.+$/, "");
      await this.callbacks.onConnected({
        provider: "whatsapp-web.js",
        sessionKey: this.sessionKey,
        connectedAt: new Date().toISOString(),
        me: client.info || null,
      });
      await this.callbacks.onStatus("connected", {
        connectedAt: new Date(),
        phoneNumber: digits ? `+${digits}` : phoneNumber,
      });
    });

    client.on("message", async (message) => {
      if (client !== this.client || message.fromMe) {
        return;
      }

      if (message.from?.endsWith("@g.us") || message.from?.endsWith("@broadcast")) {
        return;
      }

      const digits = String(message.from || "").replace(/@.+$/, "");
      if (!digits) {
        return;
      }

      await this.callbacks.onInboundMessage({
        sessionKey: this.sessionKey,
        phoneE164: `+${digits}`,
        contactName: message._data?.notifyName || message._data?.pushname || `+${digits}`,
        body: message.body || "",
        messageType: detectMessageType(message),
        externalMessageId: message.id?._serialized || null,
        rawPayload: {
          from: message.from,
          type: message.type,
          timestamp: message.timestamp,
        },
      });
    });

    client.on("auth_failure", async (message) => {
      if (client !== this.client) {
        return;
      }

      this.logger.error({ sessionKey: this.sessionKey, message }, "Falha de autenticacao whatsapp-web.js");
      this.connected = false;
      await this.callbacks.onStatus("error", {
        phoneNumber: phoneNumber || null,
      });
    });

    client.on("disconnected", async (reason) => {
      if (client !== this.client) {
        return;
      }

      this.logger.warn({ sessionKey: this.sessionKey, reason }, "Sessao whatsapp-web.js desconectada");
      this.connected = false;
      await this.teardownClient(false);

      if (!this.stopRequested) {
        await this.callbacks.onStatus("connecting", {
          phoneNumber: phoneNumber || null,
        });
        setTimeout(() => {
          if (!this.stopRequested) {
            this.connect({ phoneNumber }).catch((error) => {
              this.logger.error({ err: error, sessionKey: this.sessionKey }, "Falha ao reconectar whatsapp-web.js");
            });
          }
        }, 4000);
      }
    });

    client.on("change_state", async (state) => {
      if (client !== this.client) {
        return;
      }

      this.logger.info({ sessionKey: this.sessionKey, state }, "Estado whatsapp-web.js");
    });

    client.on("loading_screen", async (_percent, _message) => {
      if (client !== this.client) {
        return;
      }

      // Sem acao; apenas evita ruído operacional.
    });

    client.pupPage?.on?.("console", (_msg) => {});
  }

  async teardownClient(emitStatus = true) {
    const client = this.client;
    this.client = null;

    if (!client) {
      return;
    }

    try {
      await client.destroy();
    } catch (error) {
      this.logger.warn({ err: error, sessionKey: this.sessionKey }, "Falha ao destruir client whatsapp-web.js");
    }

    if (emitStatus) {
      await this.callbacks.onStatus("disconnected", {
        connectedAt: null,
      });
    }
  }
}
