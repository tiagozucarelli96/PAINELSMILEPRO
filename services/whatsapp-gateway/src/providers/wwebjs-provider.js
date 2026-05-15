import fs from "node:fs";
import path from "node:path";
import wwebjs from "whatsapp-web.js";
import { config } from "../config.js";
import { purgeSessionNoiseConversations, syncChatSnapshot } from "../repository.js";

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

function isSupportedDirectChatId(chatId) {
  if (typeof chatId !== "string" || !chatId.endsWith("@c.us")) {
    return false;
  }

  const digits = extractDigitsFromChatId(chatId);
  if (!digits || /^0+$/.test(digits)) {
    return false;
  }

  return digits.length >= 8 && digits.length <= 15;
}

function extractDigitsFromChatId(chatId) {
  return String(chatId || "").replace(/@.+$/, "").replace(/\D+/g, "");
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

function extractMessagePreview(message) {
  const body = String(message?.body || "").trim();
  if (body !== "") {
    return body;
  }

  switch (detectMessageType(message)) {
    case "image":
      return "[imagem]";
    case "video":
      return "[video]";
    case "audio":
      return "[audio]";
    case "file":
      return "[arquivo]";
    default:
      return "";
  }
}

function shouldIgnoreMessage(message) {
  const from = String(message?.from || "");
  const type = String(message?.type || "");

  if (!from || !isSupportedDirectChatId(from)) {
    return true;
  }

  if (from.endsWith("@g.us") || from.endsWith("@broadcast") || from.endsWith("@newsletter")) {
    return true;
  }

  if (type === "notification_template" || type === "e2e_notification" || type === "gp2") {
    return true;
  }

  if (message?.isStatus) {
    return true;
  }

  return extractMessagePreview(message) === "";
}

function isProtocolTimeoutError(error) {
  const message = String(error?.message || "");
  return error?.name === "ProtocolError" && message.includes("Runtime.callFunctionOn timed out");
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
    this.syncTimer = null;
    this.chatSyncTimers = new Map();
    this.messageSyncTimers = new Map();
    this.processedInboundIds = new Map();
    this.historySyncAttempts = 0;
    this.historySyncCompleted = false;
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
        protocolTimeout: Number.parseInt(process.env.PUPPETEER_PROTOCOL_TIMEOUT || "300000", 10),
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
      try {
        await purgeSessionNoiseConversations(this.sessionKey);
      } catch (error) {
        this.logger.warn({ err: error, sessionKey: this.sessionKey }, "Falha ao limpar conversas de ruido da sessao");
      }

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

      this.historySyncAttempts = 0;
      this.historySyncCompleted = false;
      this.scheduleRecentChatSync(client);
    });

    client.on("message", async (message) => {
      await this.handleInboundMessage(client, message);
    });

    client.on("message_create", async (message) => {
      await this.handleInboundMessage(client, message);
    });

    client.on("message_ciphertext", async (message) => {
      const chatId = String(message?._getChatId?.() || message?.from || "");
      const messageId = message?.id?._serialized || null;
      if (!isSupportedDirectChatId(chatId) || !messageId) {
        return;
      }

      this.scheduleSingleMessageSync(client, messageId, 5000, 0);
      this.scheduleSingleChatSync(client, chatId, 8000);
    });

    client.on("message_ciphertext_failed", async (message) => {
      const chatId = String(message?._getChatId?.() || message?.from || "");
      const messageId = message?.id?._serialized || null;
      if (!isSupportedDirectChatId(chatId) || !messageId) {
        return;
      }

      this.scheduleSingleMessageSync(client, messageId, 12000, 0);
      this.scheduleSingleChatSync(client, chatId, 15000);
    });

    client.on("unread_count", async (chat) => {
      const chatId = String(chat?.id?._serialized || "");
      if (!isSupportedDirectChatId(chatId)) {
        return;
      }

      this.scheduleSingleChatSync(client, chatId, 4000);
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

  async syncRecentChats(client) {
    const chats = await client.getChats();
    const directChats = chats
      .filter((chat) => !chat.isGroup && isSupportedDirectChatId(chat.id?._serialized || ""))
      .sort((left, right) => (right.timestamp || 0) - (left.timestamp || 0))
      .slice(0, 40);

    for (const chat of directChats) {
      let lastMessage = chat.lastMessage;
      if (!lastMessage) {
        const fetched = await chat.fetchMessages({ limit: 1 });
        lastMessage = fetched.at(-1);
      }

      if (!lastMessage || shouldIgnoreMessage(lastMessage)) {
        continue;
      }

      const contact = await chat.getContact().catch(() => null);
      const serialized = String(chat.id?._serialized || "");
      const digits = extractDigitsFromChatId(serialized);
      if (!digits) {
        continue;
      }

      const displayName =
        contact?.name ||
        contact?.pushname ||
        chat.name ||
        `+${digits}`;

      await syncChatSnapshot({
        sessionKey: this.sessionKey,
        contactName: displayName,
        phoneE164: `+${digits}`,
        body: extractMessagePreview(lastMessage),
        messageType: detectMessageType(lastMessage),
        externalMessageId: lastMessage.id?._serialized || null,
        rawPayload: {
          from: lastMessage.from,
          to: lastMessage.to,
          type: lastMessage.type,
          source: "chat_snapshot",
          chatId: serialized,
        },
        lastMessageAt: lastMessage.timestamp ? new Date(lastMessage.timestamp * 1000).toISOString() : null,
        unreadCount: Number(chat.unreadCount || 0),
        direction: lastMessage.fromMe ? "outbound" : "inbound",
      });
    }
  }

  async syncSingleChat(client, chatId) {
    const chat = await client.getChatById(chatId);
    if (!chat || chat.isGroup) {
      return;
    }

    const serialized = String(chat.id?._serialized || chatId || "");
    if (!isSupportedDirectChatId(serialized)) {
      return;
    }

    const digits = extractDigitsFromChatId(serialized);
    if (!digits) {
      return;
    }

    const contact = await chat.getContact().catch(() => null);
    const fetched = await chat.fetchMessages({ limit: 5 });
    const messages = [...fetched].sort((left, right) => (left.timestamp || 0) - (right.timestamp || 0));

    for (const message of messages) {
      if (message.fromMe || shouldIgnoreMessage(message)) {
        continue;
      }

      const externalMessageId = message.id?._serialized || null;
      if (externalMessageId && this.wasInboundProcessed(externalMessageId)) {
        continue;
      }

      if (externalMessageId) {
        this.markInboundProcessed(externalMessageId);
      }

      await this.callbacks.onInboundMessage({
        sessionKey: this.sessionKey,
        phoneE164: `+${digits}`,
        contactName: contact?.name || contact?.pushname || `+${digits}`,
        body: extractMessagePreview(message),
        messageType: detectMessageType(message),
        externalMessageId,
        rawPayload: {
          from: message.from,
          to: message.to,
          type: message.type,
          timestamp: message.timestamp,
          source: "chat_resync",
          chatId: serialized,
        },
      });
    }
  }

  async syncSingleMessage(client, messageId) {
    const message = await client.getMessageById(messageId);
    if (!message || message.fromMe || shouldIgnoreMessage(message)) {
      return false;
    }

    const chatId = String(message?._getChatId?.() || message.from || "");
    if (!isSupportedDirectChatId(chatId)) {
      return false;
    }

    const digits = extractDigitsFromChatId(chatId);
    if (!digits || /^0+$/.test(digits)) {
      return false;
    }

    const externalMessageId = message.id?._serialized || null;
    if (externalMessageId && this.wasInboundProcessed(externalMessageId)) {
      return true;
    }

    if (externalMessageId) {
      this.markInboundProcessed(externalMessageId);
    }

    await this.callbacks.onInboundMessage({
      sessionKey: this.sessionKey,
      phoneE164: `+${digits}`,
      contactName: message._data?.notifyName || message._data?.pushname || `+${digits}`,
      body: extractMessagePreview(message),
      messageType: detectMessageType(message),
      externalMessageId,
      rawPayload: {
        from: message.from,
        to: message.to,
        type: message.type,
        timestamp: message.timestamp,
        source: "message_resync",
        chatId,
      },
    });

    return true;
  }

  scheduleRecentChatSync(client) {
    this.scheduleRecentChatSyncAfter(client, 15000);
  }

  scheduleRecentChatSyncAfter(client, delayMs) {
    if (this.syncTimer) {
      clearTimeout(this.syncTimer);
      this.syncTimer = null;
    }

    this.syncTimer = setTimeout(() => {
      this.syncTimer = null;

      if (client !== this.client || !this.connected || this.stopRequested || this.historySyncCompleted) {
        return;
      }

      this.historySyncAttempts += 1;

      this.syncRecentChats(client)
        .then(() => {
          this.historySyncCompleted = true;
          this.logger.info({ sessionKey: this.sessionKey }, "Sincronizacao inicial de chats concluida");
        })
        .catch((error) => {
          if (isProtocolTimeoutError(error)) {
            this.logger.info(
              { err: error, sessionKey: this.sessionKey, attempt: this.historySyncAttempts },
              "Sincronizacao inicial ainda nao terminou no WhatsApp Web; nova tentativa sera feita em background"
            );
            this.scheduleRecentChatSyncAfter(client, 60000);
            return;
          }

          this.logger.warn(
            { err: error, sessionKey: this.sessionKey, attempt: this.historySyncAttempts },
            "Falha na sincronizacao inicial de chats"
          );
          if (this.historySyncAttempts < 10) {
            this.scheduleRecentChatSyncAfter(client, 90000);
          }
        });
    }, delayMs);
  }

  scheduleSingleChatSync(client, chatId, delayMs) {
    const existingTimer = this.chatSyncTimers.get(chatId);
    if (existingTimer) {
      clearTimeout(existingTimer);
    }

    const timer = setTimeout(() => {
      this.chatSyncTimers.delete(chatId);

      if (client !== this.client || !this.connected || this.stopRequested) {
        return;
      }

      this.syncSingleChat(client, chatId).catch((error) => {
        this.logger.info(
          { err: error, sessionKey: this.sessionKey, chatId },
          "Falha ao sincronizar chat especifico em background"
        );
      });
    }, delayMs);

    this.chatSyncTimers.set(chatId, timer);
  }

  scheduleSingleMessageSync(client, messageId, delayMs, attempt) {
    const existingTimer = this.messageSyncTimers.get(messageId);
    if (existingTimer) {
      clearTimeout(existingTimer);
    }

    const timer = setTimeout(() => {
      this.messageSyncTimers.delete(messageId);

      if (client !== this.client || !this.connected || this.stopRequested) {
        return;
      }

      this.syncSingleMessage(client, messageId)
        .then((persisted) => {
          if (!persisted && attempt < 6) {
            this.scheduleSingleMessageSync(client, messageId, 10000, attempt + 1);
          }
        })
        .catch((error) => {
          this.logger.info(
            { err: error, sessionKey: this.sessionKey, messageId, attempt },
            "Falha ao sincronizar mensagem especifica em background"
          );

          if (attempt < 6) {
            this.scheduleSingleMessageSync(client, messageId, 12000, attempt + 1);
          }
        });
    }, delayMs);

    this.messageSyncTimers.set(messageId, timer);
  }

  async handleInboundMessage(client, message) {
    if (client !== this.client || message.fromMe) {
      return;
    }

    if (shouldIgnoreMessage(message)) {
      return;
    }

    const externalMessageId = message.id?._serialized || null;
    if (externalMessageId && this.wasInboundProcessed(externalMessageId)) {
      return;
    }

    const digits = extractDigitsFromChatId(message.from);
    if (!digits || /^0+$/.test(digits)) {
      return;
    }

    if (externalMessageId) {
      this.markInboundProcessed(externalMessageId);
    }

    await this.callbacks.onInboundMessage({
      sessionKey: this.sessionKey,
      phoneE164: `+${digits}`,
      contactName: message._data?.notifyName || message._data?.pushname || `+${digits}`,
      body: extractMessagePreview(message),
      messageType: detectMessageType(message),
      externalMessageId,
      rawPayload: {
        from: message.from,
        type: message.type,
        timestamp: message.timestamp,
      },
    });
  }

  wasInboundProcessed(externalMessageId) {
    return this.processedInboundIds.has(externalMessageId);
  }

  markInboundProcessed(externalMessageId) {
    this.processedInboundIds.set(externalMessageId, Date.now());

    if (this.processedInboundIds.size <= 500) {
      return;
    }

    const threshold = Date.now() - 1000 * 60 * 30;
    for (const [messageId, processedAt] of this.processedInboundIds.entries()) {
      if (processedAt < threshold) {
        this.processedInboundIds.delete(messageId);
      }
    }
  }

  async teardownClient(emitStatus = true) {
    const client = this.client;
    this.client = null;

    if (this.syncTimer) {
      clearTimeout(this.syncTimer);
      this.syncTimer = null;
    }

    for (const timer of this.chatSyncTimers.values()) {
      clearTimeout(timer);
    }
    this.chatSyncTimers.clear();

    for (const timer of this.messageSyncTimers.values()) {
      clearTimeout(timer);
    }
    this.messageSyncTimers.clear();

    this.processedInboundIds.clear();
    this.historySyncAttempts = 0;
    this.historySyncCompleted = false;

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
