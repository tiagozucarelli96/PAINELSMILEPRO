import fs from "node:fs/promises";
import path from "node:path";
import { Pool } from "pg";
import { config } from "./config.js";
import { logger } from "./logger.js";

const searchPath = config.dbSchema
  .split(",")
  .map((item) => item.trim())
  .filter(Boolean)
  .join(", ");

export const pool = new Pool({
  connectionString: config.databaseUrl,
  ssl: false,
});

pool.on("connect", async (client) => {
  if (searchPath) {
    await client.query(`SET search_path TO ${searchPath}`);
  }
});

pool.on("error", (error) => {
  logger.error({ err: error }, "Erro inesperado no pool Postgres");
});

export async function query(text, params = []) {
  return pool.query(text, params);
}

export async function withTransaction(callback) {
  const client = await pool.connect();
  try {
    if (searchPath) {
      await client.query(`SET search_path TO ${searchPath}`);
    }
    await client.query("BEGIN");
    const result = await callback(client);
    await client.query("COMMIT");
    return result;
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
}

export async function ensureSchema() {
  const migrationFiles = await Promise.all([
    resolveMigrationPath("060_atendimento_whatsapp_base.sql"),
    resolveMigrationPath("061_atendimento_whatsapp_gateway_runtime.sql"),
  ]);

  for (const filePath of migrationFiles) {
    const sql = await fs.readFile(filePath, "utf8");
    if (!sql.trim()) {
      continue;
    }
    await pool.query(sql);
  }
}

export async function pingDatabase() {
  const result = await pool.query("SELECT NOW() AS now");
  return result.rows[0]?.now ?? null;
}

async function resolveMigrationPath(filename) {
  const candidates = [
    path.join(config.serviceRoot, "migrations", filename),
    path.join(config.repoRoot, "sql", filename),
  ];

  for (const candidate of candidates) {
    try {
      await fs.access(candidate);
      return candidate;
    } catch {
      continue;
    }
  }

  throw new Error(
    `Migration file nao encontrado: ${filename}. Caminhos verificados: ${candidates.join(", ")}`
  );
}
