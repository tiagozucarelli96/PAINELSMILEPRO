import crypto from "node:crypto";

export class MockProvider {
  constructor({ sessionKey, callbacks, logger }) {
    this.sessionKey = sessionKey;
    this.callbacks = callbacks;
    this.logger = logger;
    this.connectTimer = null;
    this.connected = false;
  }

  async connect({ phoneNumber, mode = "qr" } = {}) {
    await this.callbacks.onStatus("connecting", {
      phoneNumber: phoneNumber || null,
    });

    if (mode === "pairing_code") {
      const pairingCode = Math.random().toString().slice(2, 8);
      await this.callbacks.onPairingCode(pairingCode);
    } else {
      const qrText = `smilechat:${this.sessionKey}:${crypto.randomUUID()}`;
      await this.callbacks.onQr(qrText);
    }

    this.connectTimer = setTimeout(async () => {
      this.connected = true;
      await this.callbacks.onConnected({
        provider: "mock",
        sessionKey: this.sessionKey,
        connectedAt: new Date().toISOString(),
      });
      await this.callbacks.onStatus("connected", {
        connectedAt: new Date(),
        phoneNumber: phoneNumber || "+5500000000000",
      });
    }, 1800);
  }

  async disconnect() {
    if (this.connectTimer) {
      clearTimeout(this.connectTimer);
      this.connectTimer = null;
    }
    this.connected = false;
    await this.callbacks.onStatus("disconnected", {
      connectedAt: null,
    });
  }

  async sendText({ phoneE164, body }) {
    if (!this.connected) {
      throw new Error("Sessao mock nao conectada.");
    }

    const externalMessageId = `mock-${crypto.randomUUID()}`;
    this.logger.info(
      { sessionKey: this.sessionKey, phoneE164, externalMessageId },
      "Mensagem outbound simulada"
    );

    return {
      externalMessageId,
      acceptedAt: new Date().toISOString(),
      body,
    };
  }
}
