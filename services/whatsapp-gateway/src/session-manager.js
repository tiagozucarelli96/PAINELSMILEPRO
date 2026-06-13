import QRCode from "qrcode";
import {
  fetchSessionDiagnostics,
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

  getProviderDiagnostics(sessionKey) {
    const runtime = this.sessions.get(sessionKey);
    if (!runtime?.provider || typeof runtime.provider.getDiagnostics !== "function") {
      return null;
    }

    try {
      return runtime.provider.getDiagnostics();
    } catch (error) {
      this.logger.warn({ err: error, sessionKey }, "Falha ao ler diagnostico do provider");
      return null;
    }
  }

  async reconcileSessionState(inbox) {
    const providerDiagnostics = this.getProviderDiagnostics(inbox.session_key);
    if (!providerDiagnostics?.connected) {
      return {
        ...inbox,
        provider_runtime: providerDiagnostics,
      };
    }

    if (inbox.status !== "connected") {
      const connectedAt = new Date();
      await updateInboxStatus(inbox.session_key, "connected", {
        connectedAt,
        phoneNumber: inbox.phone_number || undefined,
      });
      await saveSessionRuntimeMeta(
        inbox.session_key,
        inbox.provider,
        {
          ...((await getSessionRuntime(inbox.session_key))?.runtime_meta || {}),
          status: "connected",
          qrText: null,
          qrImage: null,
          lastStatusAt: connectedAt.toISOString(),
          reconciledAt: connectedAt.toISOString(),
        }
      );
      await storeConnectionEvent(inbox.session_key, "session.status_reconciled", {
        from: inbox.status,
        to: "connected",
      });
    }

    return {
      ...inbox,
      status: "connected",
      runtime_meta: {
        ...(inbox.runtime_meta || {}),
        status: "connected",
        qrText: null,
        qrImage: null,
      },
      provider_runtime: providerDiagnostics,
    };
  }

  async listSessions() {
    const inboxes = await listInboxes();
    return Promise.all(
      inboxes.map(async (inbox) => {
        const runtime = await getSessionRuntime(inbox.session_key);
        return this.reconcileSessionState({
          ...inbox,
          runtime_meta: runtime?.runtime_meta || inbox.runtime_meta || null,
          credential_updated_at: runtime?.updated_at || inbox.credential_updated_at || null,
        });
      })
    );
  }

  async getSession(sessionKey) {
    const inbox = await getInboxBySessionKey(sessionKey);
    if (!inbox) {
      return null;
    }

    const runtime = await getSessionRuntime(sessionKey);
    return this.reconcileSessionState({
      ...inbox,
      runtime_meta: runtime?.runtime_meta || null,
      credential_updated_at: runtime?.updated_at || null,
    });
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

    const providerResponse = await this.withTimeout(
      runtime.provider.sendText(payload),
      Number.parseInt(process.env.WHATSAPP_SEND_TIMEOUT_MS || "25000", 10),
      "Tempo limite ao enviar mensagem pelo WhatsApp."
    );
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
    this.logger.info(
      {
        sessionKey,
        phoneE164: payload.phoneE164,
        conversationId: persisted.conversationId,
        messageId: persisted.messageId,
        externalMessageId: providerResponse.externalMessageId || null,
      },
      "Mensagem outbound salva no banco"
    );
    return {
      persisted,
      providerResponse,
    };
  }

  async withTimeout(promise, timeoutMs, message) {
    const safeTimeoutMs = Number.isFinite(timeoutMs) && timeoutMs > 0 ? timeoutMs : 25000;
    let timeoutId;
    try {
      return await Promise.race([
        promise,
        new Promise((_, reject) => {
          timeoutId = setTimeout(() => reject(new Error(message)), safeTimeoutMs);
        }),
      ]);
    } finally {
      if (timeoutId) {
        clearTimeout(timeoutId);
      }
    }
  }

  async ingestInbound(payload) {
    try {
      const result = await ingestInboundMessage(payload);
      this.io.emit("message.inbound", {
        sessionKey: payload.sessionKey,
        conversationId: result.conversationId,
        messageId: result.messageId,
        phoneE164: payload.phoneE164,
        body: payload.body,
      });
      this.logger.info(
        {
          sessionKey: payload.sessionKey,
          phoneE164: payload.phoneE164,
          contactName: payload.contactName || null,
          messageType: payload.messageType || "text",
          externalMessageId: payload.externalMessageId || null,
          conversationId: result.conversationId,
          messageId: result.messageId,
        },
        "Mensagem inbound salva no banco"
      );
      return result;
    } catch (error) {
      this.logger.error(
        {
          err: error,
          sessionKey: payload.sessionKey,
          phoneE164: payload.phoneE164,
          contactName: payload.contactName || null,
          messageType: payload.messageType || "text",
          externalMessageId: payload.externalMessageId || null,
          rawPayload: payload.rawPayload || null,
        },
        "Erro ao salvar mensagem inbound no banco"
      );
      throw error;
    }
  }

  async overview() {
    return fetchOverview();
  }

  async getDiagnostics(sessionKey) {
    const session = await this.getSession(sessionKey);
    if (!session) {
      return null;
    }

    const diagnostics = await fetchSessionDiagnostics(sessionKey);
    const runtime = this.sessions.get(sessionKey);
    const providerDiagnostics =
      typeof runtime?.provider?.getDiagnostics === "function"
        ? runtime.provider.getDiagnostics()
        : null;

    return {
      ok: true,
      sessionKey,
      providerActive: session.provider,
      databaseConnected: true,
      socket: {
        enabled: true,
      },
      session,
      providerRuntime: providerDiagnostics,
      diagnostics,
    };
  }

  async restoreConnectedSessions() {
    const inboxes = await listInboxes();
    const candidates = inboxes.filter((inbox) => {
      if (inbox.provider === "mock") {
        return false;
      }

      return inbox.status === "connected";
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
