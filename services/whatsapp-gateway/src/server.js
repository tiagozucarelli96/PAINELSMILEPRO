import cors from "cors";
import express from "express";
import http from "node:http";
import { Server as SocketServer } from "socket.io";
import { config } from "./config.js";
import { ensureSchema, pingDatabase, pool } from "./database.js";
import { logger } from "./logger.js";
import { SessionManager } from "./session-manager.js";

const app = express();
const server = http.createServer(app);
const io = new SocketServer(server, {
  cors: {
    origin: config.socketCorsOrigin,
    credentials: false,
  },
});

const sessionManager = new SessionManager({ io, logger });

app.use(cors({ origin: config.socketCorsOrigin }));
app.use(express.json({ limit: "1mb" }));

app.get("/health", async (_request, response) => {
  try {
    const databaseNow = await pingDatabase();
    const overview = await sessionManager.overview();
    response.json({
      ok: true,
      service: "smile-whatsapp-gateway",
      env: config.env,
      databaseNow,
      overview,
    });
  } catch (error) {
    logger.error({ err: error }, "Health check falhou");
    response.status(500).json({
      ok: false,
      error: error.message,
    });
  }
});

app.get("/api/sessions", async (_request, response) => {
  try {
    response.json({
      items: await sessionManager.listSessions(),
    });
  } catch (error) {
    logger.error({ err: error }, "Falha ao listar sessoes");
    response.status(500).json({ error: error.message });
  }
});

app.get("/api/sessions/:sessionKey", async (request, response) => {
  try {
    const session = await sessionManager.getSession(request.params.sessionKey);
    if (!session) {
      response.status(404).json({ error: "Sessao nao encontrada." });
      return;
    }
    response.json(session);
  } catch (error) {
    logger.error({ err: error }, "Falha ao obter sessao");
    response.status(500).json({ error: error.message });
  }
});

app.post("/api/sessions/:sessionKey/connect", async (request, response) => {
  try {
    const session = await sessionManager.connect(request.params.sessionKey, {
      phoneNumber: request.body?.phoneNumber || null,
      mode: request.body?.mode || null,
    });
    response.json({
      ok: true,
      session,
    });
  } catch (error) {
    logger.error({ err: error, sessionKey: request.params.sessionKey }, "Falha ao conectar sessao");
    response.status(500).json({ error: error.message });
  }
});

app.post("/api/sessions/:sessionKey/disconnect", async (request, response) => {
  try {
    const session = await sessionManager.disconnect(request.params.sessionKey);
    response.json({
      ok: true,
      session,
    });
  } catch (error) {
    logger.error({ err: error, sessionKey: request.params.sessionKey }, "Falha ao desconectar sessao");
    response.status(500).json({ error: error.message });
  }
});

app.post("/api/sessions/:sessionKey/messages/send", async (request, response) => {
  try {
    const phoneE164 = String(request.body?.phoneE164 || "").trim();
    const body = String(request.body?.body || "").trim();
    if (!phoneE164 || !body) {
      response.status(422).json({
        error: "phoneE164 e body sao obrigatorios.",
      });
      return;
    }

    const result = await sessionManager.sendText(request.params.sessionKey, {
      phoneE164,
      body,
      contactName: request.body?.contactName || null,
      authorUserId: request.body?.authorUserId || null,
    });
    response.json({
      ok: true,
      result,
    });
  } catch (error) {
    logger.error({ err: error, sessionKey: request.params.sessionKey }, "Falha ao enviar mensagem");
    response.status(500).json({ error: error.message });
  }
});

app.post("/api/events/messages/inbound", async (request, response) => {
  try {
    const sessionKey = String(request.body?.sessionKey || "").trim();
    const phoneE164 = String(request.body?.phoneE164 || "").trim();
    if (!sessionKey || !phoneE164) {
      response.status(422).json({
        error: "sessionKey e phoneE164 sao obrigatorios.",
      });
      return;
    }

    const result = await sessionManager.ingestInbound({
      sessionKey,
      phoneE164,
      contactName: request.body?.contactName || phoneE164,
      body: request.body?.body || "",
      messageType: request.body?.messageType || "text",
      externalMessageId: request.body?.externalMessageId || null,
      rawPayload: request.body || {},
    });

    response.json({
      ok: true,
      result,
    });
  } catch (error) {
    logger.error({ err: error }, "Falha ao ingerir mensagem inbound");
    response.status(500).json({ error: error.message });
  }
});

io.on("connection", (socket) => {
  logger.info({ socketId: socket.id }, "Cliente Socket.IO conectado");
  socket.emit("gateway.ready", {
    service: "smile-whatsapp-gateway",
    connectedAt: new Date().toISOString(),
  });

  socket.on("disconnect", () => {
    logger.info({ socketId: socket.id }, "Cliente Socket.IO desconectado");
  });
});

async function bootstrap() {
  await ensureSchema();
  server.listen(config.port, config.host, () => {
    logger.info(
      {
        host: config.host,
        port: config.port,
        defaultProvider: config.defaultProvider,
      },
      "Smile WhatsApp Gateway iniciado"
    );
  });
}

bootstrap().catch((error) => {
  logger.error({ err: error }, "Falha fatal ao iniciar o gateway");
  process.exitCode = 1;
  pool.end().catch(() => {});
});

process.on("SIGINT", async () => {
  logger.info("Encerrando gateway");
  await pool.end();
  process.exit(0);
});

process.on("SIGTERM", async () => {
  logger.info("Encerrando gateway");
  await pool.end();
  process.exit(0);
});
