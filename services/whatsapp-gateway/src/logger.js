import pino from "pino";
import { config } from "./config.js";

export const logger = pino({
  name: "smile-whatsapp-gateway",
  level: config.env === "production" ? "info" : "debug",
});
