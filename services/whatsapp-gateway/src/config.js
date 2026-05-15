import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import dotenv from "dotenv";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const serviceRoot = path.resolve(__dirname, "..");
const repoRoot = path.resolve(serviceRoot, "..", "..");

const envCandidates = [
  path.join(repoRoot, ".env"),
  path.join(serviceRoot, ".env"),
];

for (const envFile of envCandidates) {
  if (fs.existsSync(envFile)) {
    dotenv.config({ path: envFile, override: false });
  }
}

export const config = {
  env: process.env.APP_ENV || process.env.NODE_ENV || "development",
  serviceRoot,
  repoRoot,
  host: process.env.WHATSAPP_GATEWAY_HOST || "0.0.0.0",
  port: Number.parseInt(process.env.PORT || process.env.WHATSAPP_GATEWAY_PORT || "8787", 10),
  databaseUrl: process.env.DATABASE_URL || "",
  dbSchema: process.env.DB_SCHEMA || "public",
  defaultProvider: process.env.WHATSAPP_GATEWAY_DEFAULT_PROVIDER || "baileys",
  socketCorsOrigin: process.env.WHATSAPP_GATEWAY_SOCKET_CORS_ORIGIN || "*",
};

if (!config.databaseUrl) {
  throw new Error("DATABASE_URL nao definido para o whatsapp-gateway.");
}
