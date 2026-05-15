import { Boom } from "@hapi/boom";
import baileys from "@whiskeysockets/baileys";
import {
  WAProto,
  Browsers,
  BufferJSON,
  DisconnectReason,
  getContentType,
  initAuthCreds,
  isJidBroadcast,
  isJidGroup,
  jidNormalizedUser,
  makeCacheableSignalKeyStore,
  normalizeMessageContent,
} from "@whiskeysockets/baileys";
import { clearSessionAuthState, getSessionRuntime, saveSessionAuthState } from "../repository.js";

const makeWASocket =
  baileys?.default || baileys?.makeWASocket || baileys;

function normalizePhoneNumber(phoneNumber) {
  return String(phoneNumber || "").replace(/\D+/g, "");
}

function jidToPhone(jid) {
  const normalized = jidNormalizedUser(String(jid || ""));
  return normalized.replace(/@.+$/, "");
}

function buildPhoneJid(phoneE164) {
  const digits = normalizePhoneNumber(phoneE164);
  if (!digits) {
    throw new Error("Numero de telefone invalido para envio.");
  }

  return `${digits}@s.whatsapp.net`;
}

function extractTextFromMessage(message) {
  const normalizedMessage = normalizeMessageContent(message);
  if (!normalizedMessage) {
    return "";
  }

  const contentType = getContentType(normalizedMessage);
  const content = contentType ? normalizedMessage[contentType] : null;
  if (!contentType || !content) {
    return "";
  }

  if (contentType === "conversation") {
    return String(content || "");
  }

  if (typeof content.text === "string") {
    return content.text;
  }

  if (typeof content.caption === "string") {
    return content.caption;
  }

  if (typeof content.selectedDisplayText === "string") {
    return content.selectedDisplayText;
  }

  if (typeof content.title === "string") {
    return content.title;
  }

  return "";
}

function detectMessageType(message) {
  const normalizedMessage = normalizeMessageContent(message);
  if (!normalizedMessage) {
    return "text";
  }

  const contentType = getContentType(normalizedMessage) || "conversation";
  switch (contentType) {
    case "imageMessage":
      return "image";
    case "videoMessage":
      return "video";
    case "audioMessage":
      return "audio";
    case "documentMessage":
      return "file";
    default:
      return "text";
  }
}

class DbBackedAuthState {
  constructor({ sessionKey, provider, logger }) {
    this.sessionKey = sessionKey;
    this.provider = provider;
    this.logger = logger;
    this.data = {
      creds: initAuthCreds(),
      keys: {},
    };
  }

  static async load({ sessionKey, provider, logger }) {
    const instance = new DbBackedAuthState({ sessionKey, provider, logger });
    const runtime = await getSessionRuntime(sessionKey);
    const authState = runtime?.auth_state;

    if (authState && typeof authState === "object") {
      const decoded = JSON.parse(
        JSON.stringify(authState),
        BufferJSON.reviver
      );

      if (decoded?.creds) {
        instance.data.creds = {
          ...initAuthCreds(),
          ...decoded.creds,
        };
      }

      if (decoded?.keys && typeof decoded.keys === "object") {
        instance.data.keys = decoded.keys;
      }
    }

    return instance;
  }

  get state() {
    return {
      creds: this.data.creds,
      keys: {
        get: async (type, ids) => {
          const category = this.data.keys[type] || {};
          const result = {};

          for (const id of ids) {
            let value = category[id];
            if (
              type === "app-state-sync-key" &&
              value &&
              WAProto?.proto?.Message?.AppStateSyncKeyData
            ) {
              value = WAProto.proto.Message.AppStateSyncKeyData.fromObject(value);
            }
            result[id] = value;
          }

          return result;
        },
        set: async (dataset) => {
          for (const category of Object.keys(dataset || {})) {
            this.data.keys[category] = this.data.keys[category] || {};
            for (const id of Object.keys(dataset[category] || {})) {
              const value = dataset[category][id];
              if (value) {
                this.data.keys[category][id] = value;
              } else {
                delete this.data.keys[category][id];
              }
            }
          }

          await this.persist();
        },
      },
    };
  }

  async updateCreds(nextCreds) {
    this.data.creds = {
      ...this.data.creds,
      ...nextCreds,
    };
    await this.persist();
  }

  async clear() {
    this.data = {
      creds: initAuthCreds(),
      keys: {},
    };
    await clearSessionAuthState(this.sessionKey, this.provider);
  }

  async persist() {
    const encoded = JSON.parse(
      JSON.stringify(this.data, BufferJSON.replacer)
    );
    await saveSessionAuthState(this.sessionKey, this.provider, encoded);
  }
}

export class BaileysProvider {
  constructor({ sessionKey, callbacks, logger }) {
    this.sessionKey = sessionKey;
    this.callbacks = callbacks;
    this.logger = logger;
    this.socket = null;
    this.authStore = null;
    this.connected = false;
    this.stopRequested = false;
    this.reconnectTimer = null;
    this.lastConnectOptions = {
      phoneNumber: null,
      mode: "qr",
    };
  }

  async connect({ phoneNumber, mode = "qr" } = {}) {
    this.stopRequested = true;
    clearTimeout(this.reconnectTimer);
    this.reconnectTimer = null;
    this.lastConnectOptions = {
      phoneNumber: phoneNumber || null,
      mode,
    };

    await this.teardownSocket(false);
    this.stopRequested = false;
    await this.callbacks.onStatus("connecting", {
      phoneNumber: phoneNumber || null,
    });

    await this.openSocket();
  }

  async disconnect() {
    this.stopRequested = true;
    clearTimeout(this.reconnectTimer);
    this.reconnectTimer = null;
    await this.teardownSocket(false);
    this.connected = false;
    await this.callbacks.onStatus("disconnected", {
      connectedAt: null,
      phoneNumber: this.lastConnectOptions.phoneNumber || null,
    });
  }

  async sendText({ phoneE164, body }) {
    if (!this.socket || !this.connected) {
      throw new Error("Sessao Baileys nao conectada.");
    }

    const jid = buildPhoneJid(phoneE164);
    const response = await this.socket.sendMessage(jid, { text: body });

    return {
      externalMessageId: response?.key?.id || null,
      acceptedAt: new Date().toISOString(),
      body,
    };
  }

  async openSocket() {
    this.authStore = await DbBackedAuthState.load({
      sessionKey: this.sessionKey,
      provider: "baileys",
      logger: this.logger,
    });

    const socket = makeWASocket({
      auth: {
        creds: this.authStore.state.creds,
        keys: makeCacheableSignalKeyStore(this.authStore.state.keys, this.logger),
      },
      browser: Browsers.macOS("Desktop"),
      printQRInTerminal: false,
      markOnlineOnConnect: false,
      syncFullHistory: false,
      generateHighQualityLinkPreview: false,
    });

    this.socket = socket;
    this.bindEvents(socket);

    if (
      this.lastConnectOptions.mode === "pairing_code" &&
      !this.authStore.state.creds.registered
    ) {
      const digits = normalizePhoneNumber(this.lastConnectOptions.phoneNumber);
      if (!digits) {
        throw new Error("Informe o telefone da linha para usar o modo por codigo.");
      }

      const pairingCode = await socket.requestPairingCode(digits);
      await this.callbacks.onPairingCode(pairingCode);
    }
  }

  bindEvents(socket) {
    socket.ev.on("creds.update", async (creds) => {
      try {
        await this.authStore?.updateCreds(creds);
      } catch (error) {
        this.logger.error({ err: error, sessionKey: this.sessionKey }, "Falha ao persistir credenciais");
      }
    });

    socket.ev.on("connection.update", async (update) => {
      try {
        if (update.qr) {
          await this.callbacks.onQr(update.qr);
        }

        if (update.connection === "open") {
          this.connected = true;
          const connectedPhone = jidToPhone(socket.user?.id || this.lastConnectOptions.phoneNumber);
          await this.callbacks.onConnected({
            provider: "baileys",
            sessionKey: this.sessionKey,
            connectedAt: new Date().toISOString(),
            me: socket.user || null,
          });
          await this.callbacks.onStatus("connected", {
            connectedAt: new Date(),
            phoneNumber: connectedPhone ? `+${connectedPhone}` : this.lastConnectOptions.phoneNumber || null,
          });
          return;
        }

        if (update.connection === "close") {
          this.connected = false;
          const boom = update.lastDisconnect?.error instanceof Boom
            ? update.lastDisconnect.error
            : new Boom(update.lastDisconnect?.error || "Sessao encerrada");
          const statusCode = boom?.output?.statusCode;
          const loggedOut = statusCode === DisconnectReason.loggedOut;

          if (loggedOut) {
            await this.authStore?.clear();
            await this.callbacks.onStatus("disconnected", {
              connectedAt: null,
              phoneNumber: this.lastConnectOptions.phoneNumber || null,
            });
            return;
          }

          await this.callbacks.onStatus("connecting", {
            phoneNumber: this.lastConnectOptions.phoneNumber || null,
          });

          if (!this.stopRequested) {
            this.scheduleReconnect();
          }
        }
      } catch (error) {
        this.logger.error({ err: error, sessionKey: this.sessionKey }, "Falha ao processar evento de conexao");
        await this.callbacks.onStatus("error", {
          phoneNumber: this.lastConnectOptions.phoneNumber || null,
        });
      }
    });

    socket.ev.on("messages.upsert", async (event) => {
      if (!event?.messages?.length) {
        return;
      }

      for (const incomingMessage of event.messages) {
        if (!incomingMessage?.message || incomingMessage.key?.fromMe) {
          continue;
        }

        const remoteJid = String(incomingMessage.key?.remoteJid || "");
        if (!remoteJid || isJidGroup(remoteJid) || isJidBroadcast(remoteJid)) {
          continue;
        }

        const phone = jidToPhone(remoteJid);
        if (!phone) {
          continue;
        }

        const body = extractTextFromMessage(incomingMessage.message);
        await this.callbacks.onInboundMessage({
          sessionKey: this.sessionKey,
          phoneE164: `+${phone}`,
          contactName: incomingMessage.pushName || `+${phone}`,
          body,
          messageType: detectMessageType(incomingMessage.message),
          externalMessageId: incomingMessage.key?.id || null,
          rawPayload: {
            remoteJid,
            key: incomingMessage.key || null,
            messageTimestamp: incomingMessage.messageTimestamp || null,
            pushName: incomingMessage.pushName || null,
          },
        });
      }
    });
  }

  scheduleReconnect() {
    clearTimeout(this.reconnectTimer);
    this.reconnectTimer = setTimeout(async () => {
      if (this.stopRequested) {
        return;
      }

      try {
        this.stopRequested = true;
        await this.teardownSocket(false);
        this.stopRequested = false;
        await this.openSocket();
      } catch (error) {
        this.logger.error({ err: error, sessionKey: this.sessionKey }, "Falha no reconnect do Baileys");
        await this.callbacks.onStatus("error", {
          phoneNumber: this.lastConnectOptions.phoneNumber || null,
        });
        if (!this.stopRequested) {
          this.scheduleReconnect();
        }
      }
    }, 4000);
  }

  async teardownSocket(emitDisconnect = false) {
    if (!this.socket) {
      return;
    }

    const socket = this.socket;
    this.socket = null;

    try {
      socket.end(new Error("Smile Chat session reset"));
    } catch (error) {
      this.logger.warn({ err: error, sessionKey: this.sessionKey }, "Falha ao encerrar socket Baileys");
    }

    if (emitDisconnect) {
      await this.callbacks.onStatus("disconnected", {
        connectedAt: null,
        phoneNumber: this.lastConnectOptions.phoneNumber || null,
      });
    }
  }
}
