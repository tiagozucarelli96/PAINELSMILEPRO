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

function isBroadcastLikeChatId(chatId) {
  return (
    typeof chatId === "string" &&
    (chatId.endsWith("@g.us") || chatId.endsWith("@broadcast") || chatId.endsWith("@newsletter"))
  );
}

function isLidChatId(chatId) {
  return typeof chatId === "string" && chatId.endsWith("@lid");
}

function isSupportedDirectChatId(chatId) {
  if (typeof chatId !== "string" || chatId === "") {
    return false;
  }

  if (chatId === "0@c.us" || isBroadcastLikeChatId(chatId)) {
    return false;
  }

  if (isLidChatId(chatId)) {
    return true;
  }

  if (!chatId.endsWith("@c.us")) {
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

function ignoreReasonForMessage(message) {
  const from = String(message?.from || "");
  const type = String(message?.type || "");

  if (!from) {
    return "missing_from";
  }

  if (from === "0@c.us") {
    return "system_zero_chat";
  }

  if (!isSupportedDirectChatId(from)) {
    return "unsupported_chat_id";
  }

  if (isBroadcastLikeChatId(from)) {
    return "group_or_broadcast";
  }

  if (type === "notification_template" || type === "e2e_notification" || type === "gp2") {
    return `ignored_type_${type}`;
  }

  if (message?.isStatus) {
    return "status_message";
  }

  if (extractMessagePreview(message) === "") {
    return "empty_preview";
  }

  return null;
}

function shouldIgnoreMessage(message) {
  return ignoreReasonForMessage(message) !== null;
}

function summarizeMessage(message) {
  return {
    messageId: message?.id?._serialized || null,
    from: message?.from || null,
    to: message?.to || null,
    chatId: message?._getChatId?.() || null,
    type: message?.type || null,
    fromMe: Boolean(message?.fromMe),
    hasMedia: Boolean(message?.hasMedia),
    timestamp: message?.timestamp || null,
    preview: extractMessagePreview(message),
  };
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
    this.identityCache = new Map();
    this.lastInboundEvent = null;
    this.lastIgnoredEvent = null;
    this.lastSavedInbound = null;
    this.lastSaveError = null;
    this.lastHistorySyncError = null;
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

  async resolveChatIdentity(client, chatId, contact = null, fallbackName = null) {
    const cached = this.identityCache.get(chatId);
    if (cached) {
      return cached;
    }

    let resolvedChatId = String(chatId || "");
    let digits = resolvedChatId.endsWith("@c.us") ? extractDigitsFromChatId(resolvedChatId) : "";
    let lidResolution = null;

    if ((!digits || /^0+$/.test(digits)) && isLidChatId(resolvedChatId)) {
      try {
        const [resolution] = await client.getContactLidAndPhone([resolvedChatId]);
        lidResolution = resolution || null;
        if (typeof lidResolution?.pn === "string" && lidResolution.pn.endsWith("@c.us")) {
          resolvedChatId = lidResolution.pn;
          digits = extractDigitsFromChatId(lidResolution.pn);
        }
      } catch (error) {
        this.logger.info(
          { err: error, sessionKey: this.sessionKey, chatId: resolvedChatId },
          "Falha ao resolver numero de chat @lid"
        );
      }
    }

    if ((!digits || /^0+$/.test(digits)) && contact?.number) {
      digits = normalizePhoneNumber(contact.number);
    }

    if (!digits || /^0+$/.test(digits) || digits.length < 8 || digits.length > 15) {
      return null;
    }

    const identity = {
      chatId: resolvedChatId,
      originalChatId: String(chatId || ""),
      phoneDigits: digits,
      phoneE164: `+${digits}`,
      contactName:
        contact?.name ||
        contact?.pushname ||
        contact?.verifiedName ||
        fallbackName ||
        `+${digits}`,
      lidResolution,
    };

    this.identityCache.set(chatId, identity);
    if (resolvedChatId !== chatId) {
      this.identityCache.set(resolvedChatId, identity);
    }

    return identity;
  }

  logInboundEvent(stage, payload, level = "info") {
    const logMethod = typeof this.logger[level] === "function" ? this.logger[level].bind(this.logger) : this.logger.info.bind(this.logger);
    logMethod(
      {
        sessionKey: this.sessionKey,
        stage,
        ...payload,
      },
      "Fluxo inbound WhatsApp"
    );
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
      try {
        const enabled = await client.setBackgroundSync(true);
        this.logger.info(
          { sessionKey: this.sessionKey, enabled },
          "Configuracao de background sync aplicada ao WhatsApp Web"
        );
      } catch (error) {
        this.logger.info(
          { err: error, sessionKey: this.sessionKey },
          "Falha ao aplicar configuracao de background sync"
        );
      }
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
      this.lastInboundEvent = {
        event: "message",
        receivedAt: new Date().toISOString(),
        message: summarizeMessage(message),
      };
      await this.handleInboundMessage(client, message);
    });

    client.on("message_create", async (message) => {
      this.lastInboundEvent = {
        event: "message_create",
        receivedAt: new Date().toISOString(),
        message: summarizeMessage(message),
      };
      await this.handleInboundMessage(client, message);
    });

    client.on("message_ciphertext", async (message) => {
      const chatId = String(message?._getChatId?.() || message?.from || "");
      const messageId = message?.id?._serialized || null;
      this.lastInboundEvent = {
        event: "message_ciphertext",
        receivedAt: new Date().toISOString(),
        message: summarizeMessage(message),
      };
      this.logInboundEvent("provider_event_message_ciphertext", {
        event: "message_ciphertext",
        message: summarizeMessage(message),
      });
      if (!isSupportedDirectChatId(chatId) || !messageId) {
        this.lastIgnoredEvent = {
          event: "message_ciphertext",
          ignoredAt: new Date().toISOString(),
          reason: !messageId ? "missing_message_id" : "unsupported_chat_id",
          message: summarizeMessage(message),
        };
        return;
      }

      this.scheduleSingleMessageSync(client, messageId, 5000, 0);
      this.scheduleSingleChatSync(client, chatId, 8000);
    });

    client.on("message_ciphertext_failed", async (message) => {
      const chatId = String(message?._getChatId?.() || message?.from || "");
      const messageId = message?.id?._serialized || null;
      this.lastInboundEvent = {
        event: "message_ciphertext_failed",
        receivedAt: new Date().toISOString(),
        message: summarizeMessage(message),
      };
      this.logInboundEvent("provider_event_message_ciphertext_failed", {
        event: "message_ciphertext_failed",
        message: summarizeMessage(message),
      });
      if (!isSupportedDirectChatId(chatId) || !messageId) {
        this.lastIgnoredEvent = {
          event: "message_ciphertext_failed",
          ignoredAt: new Date().toISOString(),
          reason: !messageId ? "missing_message_id" : "unsupported_chat_id",
          message: summarizeMessage(message),
        };
        return;
      }

      this.scheduleSingleMessageSync(client, messageId, 12000, 0);
      this.scheduleSingleChatSync(client, chatId, 15000);
    });

    client.on("unread_count", async (chat) => {
      const chatId = String(chat?.id?._serialized || "");
      this.logInboundEvent("provider_event_unread_count", {
        event: "unread_count",
        chatId,
        unreadCount: Number(chat?.unreadCount || 0),
      });
      if (!isSupportedDirectChatId(chatId)) {
        this.lastIgnoredEvent = {
          event: "unread_count",
          ignoredAt: new Date().toISOString(),
          reason: "unsupported_chat_id",
          chatId,
        };
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

      if (!lastMessage) {
        continue;
      }

      const contact = await chat.getContact().catch(() => null);
      const serialized = String(chat.id?._serialized || "");
      const ignoreReason = ignoreReasonForMessage(lastMessage);
      if (ignoreReason) {
        this.lastIgnoredEvent = {
          event: "chat_snapshot",
          ignoredAt: new Date().toISOString(),
          reason: ignoreReason,
          message: summarizeMessage(lastMessage),
        };
        continue;
      }

      const identity = await this.resolveChatIdentity(
        client,
        serialized,
        contact,
        contact?.name || contact?.pushname || chat.name || null
      );
      if (!identity) {
        this.lastIgnoredEvent = {
          event: "chat_snapshot",
          ignoredAt: new Date().toISOString(),
          reason: "unresolved_phone_identity",
          message: summarizeMessage(lastMessage),
          chatId: serialized,
        };
        continue;
      }

      await syncChatSnapshot({
        sessionKey: this.sessionKey,
        contactName: identity.contactName,
        phoneE164: identity.phoneE164,
        body: extractMessagePreview(lastMessage),
        messageType: detectMessageType(lastMessage),
        externalMessageId: lastMessage.id?._serialized || null,
        rawPayload: {
          from: lastMessage.from,
          to: lastMessage.to,
          type: lastMessage.type,
          source: "chat_snapshot",
          chatId: serialized,
          resolvedChatId: identity.chatId,
        },
        lastMessageAt: lastMessage.timestamp ? new Date(lastMessage.timestamp * 1000).toISOString() : null,
        unreadCount: Number(chat.unreadCount || 0),
        direction: lastMessage.fromMe ? "outbound" : "inbound",
      });
    }
  }

  async syncSingleChat(client, chatId) {
    try {
      await client.syncHistory(chatId);
    } catch (error) {
      if (!isProtocolTimeoutError(error)) {
        this.logger.info(
          { err: error, sessionKey: this.sessionKey, chatId },
          "Falha ao solicitar syncHistory de chat especifico"
        );
      }
    }

    const chat = await client.getChatById(chatId);
    if (!chat || chat.isGroup) {
      return;
    }

    const serialized = String(chat.id?._serialized || chatId || "");
    if (!isSupportedDirectChatId(serialized)) {
      return;
    }

    const contact = await chat.getContact().catch(() => null);
    const identity = await this.resolveChatIdentity(
      client,
      serialized,
      contact,
      contact?.name || contact?.pushname || chat.name || null
    );
    if (!identity) {
      return;
    }

    const fetched = await chat.fetchMessages({ limit: 5 });
    const messages = [...fetched].sort((left, right) => (left.timestamp || 0) - (right.timestamp || 0));

    for (const message of messages) {
      const ignoreReason = message.fromMe ? "from_me" : ignoreReasonForMessage(message);
      if (ignoreReason) {
        this.lastIgnoredEvent = {
          event: "chat_resync",
          ignoredAt: new Date().toISOString(),
          reason: ignoreReason,
          message: summarizeMessage(message),
        };
        continue;
      }

      const externalMessageId = message.id?._serialized || null;
      if (externalMessageId && this.wasInboundProcessed(externalMessageId)) {
        continue;
      }

      if (externalMessageId) {
        this.markInboundProcessed(externalMessageId);
      }

      try {
        await this.callbacks.onInboundMessage({
          sessionKey: this.sessionKey,
          phoneE164: identity.phoneE164,
          contactName: identity.contactName,
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
            resolvedChatId: identity.chatId,
          },
        });
        this.lastSavedInbound = {
          event: "chat_resync",
          savedAt: new Date().toISOString(),
          externalMessageId,
          phoneE164: identity.phoneE164,
          preview: extractMessagePreview(message),
        };
        this.lastSaveError = null;
      } catch (error) {
        this.lastSaveError = {
          event: "chat_resync",
          failedAt: new Date().toISOString(),
          externalMessageId,
          phoneE164: identity.phoneE164,
          message: error.message,
        };
        throw error;
      }
    }
  }

  async syncSingleMessage(client, messageId) {
    const message = await client.getMessageById(messageId);
    const ignoreReason = !message ? "message_not_found" : message.fromMe ? "from_me" : ignoreReasonForMessage(message);
    if (!message || ignoreReason) {
      if (ignoreReason) {
        this.lastIgnoredEvent = {
          event: "message_resync",
          ignoredAt: new Date().toISOString(),
          reason: ignoreReason,
          message: message ? summarizeMessage(message) : { messageId },
        };
      }
      return false;
    }

    const chatId = String(message?._getChatId?.() || message.from || "");
    if (!isSupportedDirectChatId(chatId)) {
      return false;
    }

    const contact = typeof message.getContact === "function" ? await message.getContact().catch(() => null) : null;
    const identity = await this.resolveChatIdentity(
      client,
      chatId,
      contact,
      message._data?.notifyName || message._data?.pushname || null
    );
    if (!identity) {
      return false;
    }

    const externalMessageId = message.id?._serialized || null;
    if (externalMessageId && this.wasInboundProcessed(externalMessageId)) {
      return true;
    }

    if (externalMessageId) {
      this.markInboundProcessed(externalMessageId);
    }

    try {
      await this.callbacks.onInboundMessage({
        sessionKey: this.sessionKey,
        phoneE164: identity.phoneE164,
        contactName: identity.contactName,
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
          resolvedChatId: identity.chatId,
        },
      });

      this.lastSavedInbound = {
        event: "message_resync",
        savedAt: new Date().toISOString(),
        externalMessageId,
        phoneE164: identity.phoneE164,
        preview: extractMessagePreview(message),
      };
      this.lastSaveError = null;
    } catch (error) {
      this.lastSaveError = {
        event: "message_resync",
        failedAt: new Date().toISOString(),
        externalMessageId,
        phoneE164: identity.phoneE164,
        message: error.message,
      };
      throw error;
    }

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
          this.lastHistorySyncError = null;
          this.logger.info({ sessionKey: this.sessionKey }, "Sincronizacao inicial de chats concluida");
        })
        .catch((error) => {
          if (isProtocolTimeoutError(error)) {
            this.lastHistorySyncError = {
              failedAt: new Date().toISOString(),
              attempt: this.historySyncAttempts,
              message: error.message,
              name: error.name,
            };
            this.logger.info(
              { err: error, sessionKey: this.sessionKey, attempt: this.historySyncAttempts },
              "Sincronizacao inicial ainda nao terminou no WhatsApp Web; nova tentativa sera feita em background"
            );
            this.scheduleRecentChatSyncAfter(client, 60000);
            return;
          }

          this.lastHistorySyncError = {
            failedAt: new Date().toISOString(),
            attempt: this.historySyncAttempts,
            message: error.message,
            name: error.name,
          };
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
    if (client !== this.client) {
      return;
    }

    if (message.fromMe) {
      this.lastIgnoredEvent = {
        event: "message",
        ignoredAt: new Date().toISOString(),
        reason: "from_me",
        message: summarizeMessage(message),
      };
      this.logInboundEvent("message_ignored", {
        reason: "from_me",
        message: summarizeMessage(message),
      });
      return;
    }

    this.logInboundEvent("provider_event_message", {
      event: "message",
      message: summarizeMessage(message),
    });

    const ignoreReason = ignoreReasonForMessage(message);
    if (ignoreReason) {
      this.lastIgnoredEvent = {
        event: "message",
        ignoredAt: new Date().toISOString(),
        reason: ignoreReason,
        message: summarizeMessage(message),
      };
      this.logInboundEvent("message_ignored", {
        reason: ignoreReason,
        message: summarizeMessage(message),
      });
      return;
    }

    const externalMessageId = message.id?._serialized || null;
    if (externalMessageId && this.wasInboundProcessed(externalMessageId)) {
      return;
    }

    const contact = typeof message.getContact === "function" ? await message.getContact().catch(() => null) : null;
    const chatId = String(message?._getChatId?.() || message.from || "");
    const identity = await this.resolveChatIdentity(
      client,
      chatId,
      contact,
      message._data?.notifyName || message._data?.pushname || null
    );
    if (!identity) {
      this.lastIgnoredEvent = {
        event: "message",
        ignoredAt: new Date().toISOString(),
        reason: "unresolved_phone_identity",
        message: summarizeMessage(message),
      };
      this.logInboundEvent("message_ignored", {
        reason: "unresolved_phone_identity",
        message: summarizeMessage(message),
      });
      return;
    }

    if (externalMessageId) {
      this.markInboundProcessed(externalMessageId);
    }

    this.logInboundEvent("message_forwarded_to_persistence", {
      phoneE164: identity.phoneE164,
      contactName: identity.contactName,
      externalMessageId,
      message: summarizeMessage(message),
    });

    try {
      await this.callbacks.onInboundMessage({
        sessionKey: this.sessionKey,
        phoneE164: identity.phoneE164,
        contactName: identity.contactName,
        body: extractMessagePreview(message),
        messageType: detectMessageType(message),
        externalMessageId,
        rawPayload: {
          from: message.from,
          type: message.type,
          timestamp: message.timestamp,
          source: "message_event",
          chatId,
          resolvedChatId: identity.chatId,
        },
      });

      this.lastSavedInbound = {
        event: "message",
        savedAt: new Date().toISOString(),
        externalMessageId,
        phoneE164: identity.phoneE164,
        preview: extractMessagePreview(message),
      };
      this.lastSaveError = null;
    } catch (error) {
      this.lastSaveError = {
        event: "message",
        failedAt: new Date().toISOString(),
        externalMessageId,
        phoneE164: identity.phoneE164,
        message: error.message,
      };
      throw error;
    }
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

  getDiagnostics() {
    return {
      provider: "whatsapp-web.js",
      connected: this.connected,
      stopRequested: this.stopRequested,
      initializing: Boolean(this.initializing),
      historySyncAttempts: this.historySyncAttempts,
      historySyncCompleted: this.historySyncCompleted,
      queuedChatSyncs: this.chatSyncTimers.size,
      queuedMessageSyncs: this.messageSyncTimers.size,
      processedInboundCacheSize: this.processedInboundIds.size,
      lastInboundEvent: this.lastInboundEvent,
      lastIgnoredEvent: this.lastIgnoredEvent,
      lastSavedInbound: this.lastSavedInbound,
      lastSaveError: this.lastSaveError,
      lastHistorySyncError: this.lastHistorySyncError,
    };
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
    this.identityCache.clear();
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
