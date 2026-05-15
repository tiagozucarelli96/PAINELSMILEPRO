import QRCode from "qrcode";
import {
  fetchOverview,
  getInboxBySessionKey,
  getSessionRuntime,
  ingestInboundMessage,
  ingestOutboundMessage,
  listInboxes,
  saveSessionRuntimeMeta,
  storeConnectionEvent,
  updateInboxStatus,
} from "./repository.js";
import { createProvider } from "./providers/index.js";

export class SessionManager {
  constructor({ io, logger }) {
    this.io = io;
    this.logger = logger;
    this.sessions = new Map();
  }

  async listSessions() {
    const inboxes = await listInboxes();
    return Promise.all(
      inboxes.map(async (inbox) => {
        const runtime = await getSessionRuntime(inbox.session_key);
        return {
          ...inbox,
          runtime_meta: runtime?.runtime_meta || inbox.runtime_meta || null,
          credential_updated_at: runtime?.updated_at || inbox.credential_updated_at || null,
        };
      })
    );
  }

  async getSession(sessionKey) {
    const inbox = await getInboxBySessionKey(sessionKey);
    if (!inbox) {
      return null;
    }

    const runtime = await getSessionRuntime(sessionKey);
    return {
      ...inbox,
      runtime_meta: runtime?.runtime_meta || null,
      credential_updated_at: runtime?.updated_at || null,
    };
  }

  async connect(sessionKey, options = {}) {
    const inbox = await getInboxBySessionKey(sessionKey);
    if (!inbox) {
      throw new Error(`Inbox nao encontrada para session_key ${sessionKey}.`);
    }

    let runtime = this.sessions.get(sessionKey);
    if (!runtime) {
      runtime = this.createSessionRuntime(inbox);
      this.sessions.set(sessionKey, runtime);
    }

    await runtime.provider.connect({
      phoneNumber: options.phoneNumber || inbox.phone_number || null,
      mode: options.mode || inbox.connection_mode || "qr",
    });

    return this.getSession(sessionKey);
  }

  async disconnect(sessionKey) {
    const runtime = this.sessions.get(sessionKey);
    if (!runtime) {
      await updateInboxStatus(sessionKey, "disconnected", {
        connectedAt: null,
      });
      await storeConnectionEvent(sessionKey, "session.disconnected", {
        source: "manual",
      });
      this.io.emit("session.status", {
        sessionKey,
        status: "disconnected",
      });
      return this.getSession(sessionKey);
    }

    await runtime.provider.disconnect();
    return this.getSession(sessionKey);
  }

  async sendText(sessionKey, payload) {
    const inbox = await getInboxBySessionKey(sessionKey);
    if (!inbox) {
      throw new Error(`Inbox nao encontrada para session_key ${sessionKey}.`);
    }

    let runtime = this.sessions.get(sessionKey);
    if (!runtime) {
      runtime = this.createSessionRuntime(inbox);
      this.sessions.set(sessionKey, runtime);
    }

    const providerResponse = await runtime.provider.sendText(payload);
    const persisted = await ingestOutboundMessage({
      sessionKey,
      contactName: payload.contactName || payload.phoneE164,
      phoneE164: payload.phoneE164,
      body: payload.body,
      messageType: "text",
      externalMessageId: providerResponse.externalMessageId,
      authorUserId: payload.authorUserId || null,
      rawPayload: {
        request: payload,
        providerResponse,
      },
    });

    const eventPayload = {
      sessionKey,
      conversationId: persisted.conversationId,
      messageId: persisted.messageId,
      phoneE164: payload.phoneE164,
      body: payload.body,
      externalMessageId: providerResponse.externalMessageId,
    };

    this.io.emit("message.outbound", eventPayload);
    return {
      persisted,
      providerResponse,
    };
  }

  async ingestInbound(payload) {
    const result = await ingestInboundMessage(payload);
    this.io.emit("message.inbound", {
      sessionKey: payload.sessionKey,
      conversationId: result.conversationId,
      messageId: result.messageId,
      phoneE164: payload.phoneE164,
      body: payload.body,
    });
    return result;
  }

  async overview() {
    return fetchOverview();
  }

  async restoreConnectedSessions() {
    const inboxes = await listInboxes();
    const candidates = inboxes.filter((inbox) => {
      if (inbox.provider === "mock") {
        return false;
      }

      return inbox.status === "connected" || inbox.status === "connecting";
    });

    for (const inbox of candidates) {
      try {
        await this.connect(inbox.session_key, {
          phoneNumber: inbox.phone_number || null,
          mode: inbox.connection_mode || "qr",
        });
        this.logger.info({ sessionKey: inbox.session_key }, "Sessao restaurada automaticamente apos restart");
      } catch (error) {
        this.logger.warn(
          { err: error, sessionKey: inbox.session_key },
          "Falha ao restaurar sessao automaticamente apos restart"
        );
      }
    }
  }

  createSessionRuntime(inbox) {
    const callbacks = {
      onStatus: async (status, extra = {}) => {
        await updateInboxStatus(inbox.session_key, status, {
          connectedAt: extra.connectedAt ?? null,
          phoneNumber: extra.phoneNumber ?? undefined,
        });
        await saveSessionRuntimeMeta(
          inbox.session_key,
          inbox.provider,
          {
            ...((await getSessionRuntime(inbox.session_key))?.runtime_meta || {}),
            status,
            lastStatusAt: new Date().toISOString(),
          }
        );
        await storeConnectionEvent(inbox.session_key, "session.status", {
          status,
          ...extra,
        });
        this.io.emit("session.status", {
          sessionKey: inbox.session_key,
          status,
          ...extra,
        });
      },
      onQr: async (qrText) => {
        const qrImage = await QRCode.toDataURL(qrText);
        await updateInboxStatus(inbox.session_key, "connecting", {
          lastQrAt: new Date(),
        });
        await saveSessionRuntimeMeta(inbox.session_key, inbox.provider, {
          qrText,
          qrImage,
          status: "connecting",
          lastQrAt: new Date().toISOString(),
        });
        await storeConnectionEvent(inbox.session_key, "session.qr", {
          qrText,
        });
        this.io.emit("session.qr", {
          sessionKey: inbox.session_key,
          qrText,
          qrImage,
        });
      },
      onPairingCode: async (pairingCode) => {
        await saveSessionRuntimeMeta(inbox.session_key, inbox.provider, {
          pairingCode,
          status: "connecting",
          generatedAt: new Date().toISOString(),
        });
        await storeConnectionEvent(inbox.session_key, "session.pairing_code", {
          pairingCode,
        });
        this.io.emit("session.pairing_code", {
          sessionKey: inbox.session_key,
          pairingCode,
        });
      },
      onConnected: async (payload) => {
        const runtime = (await getSessionRuntime(inbox.session_key))?.runtime_meta || {};
        await saveSessionRuntimeMeta(inbox.session_key, inbox.provider, {
          ...runtime,
          status: "connected",
          qrText: null,
          qrImage: null,
          connectedMeta: payload,
        });
        await storeConnectionEvent(inbox.session_key, "session.connected", payload);
        this.io.emit("session.connected", {
          sessionKey: inbox.session_key,
          ...payload,
        });
      },
      onInboundMessage: async (payload) => {
        await this.ingestInbound(payload);
      },
    };

    const provider = createProvider(inbox.provider, {
      sessionKey: inbox.session_key,
      callbacks,
      logger: this.logger,
    });

    return {
      inbox,
      provider,
    };
  }
}
