export class BaileysProvider {
  constructor({ sessionKey, callbacks, logger }) {
    this.sessionKey = sessionKey;
    this.callbacks = callbacks;
    this.logger = logger;
  }

  async connect() {
    this.logger.warn(
      { sessionKey: this.sessionKey },
      "Provider Baileys ainda nao foi ativado nesta fase"
    );
    await this.callbacks.onStatus("error", {});
    throw new Error(
      "Provider Baileys reservado para a proxima entrega. O gateway segue funcional com provider mock nesta fase."
    );
  }

  async disconnect() {
    await this.callbacks.onStatus("disconnected", {});
  }

  async sendText() {
    throw new Error("Envio por Baileys ainda nao implementado nesta fase.");
  }
}
