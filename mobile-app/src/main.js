const DEFAULT_API_BASE = "https://smile-client-app-api-production.up.railway.app/api";
const APP_KEY = "smile-client-app-session";
const DEBUG_SETTINGS_ENABLED =
  new URLSearchParams(window.location.search).get("debug") === "1" ||
  window.localStorage.getItem("smile-client-app-debug") === "1" ||
  /(^localhost$)|(^127\.0\.0\.1$)|(^0\.0\.0\.0$)/.test(window.location.hostname);

const state = {
  apiBase: (window.localStorage.getItem("smile-client-api-base") || DEFAULT_API_BASE).replace(/\/$/, ""),
  locations: [],
  loadingLocations: true,
  authenticating: false,
  bootingSession: true,
  session: loadSession(),
  event: null,
  error: "",
  success: "",
};

const app = document.querySelector("#app");

init().catch((error) => {
  console.error(error);
  state.error = "Não foi possível iniciar o app.";
  state.bootingSession = false;
  render();
});

async function init() {
  render();
  await Promise.all([fetchLocations(), restoreSession()]);
  render();
}

async function fetchLocations() {
  state.loadingLocations = true;
  render();

  try {
    const response = await fetch(`${state.apiBase}/v1/client/locations`);
    const data = await response.json();
    if (!response.ok || !data.ok) {
      throw new Error(data.error || "Falha ao carregar os locais.");
    }
    state.locations = Array.isArray(data.items) ? data.items : [];
  } catch (error) {
    console.error(error);
    state.error = "Não foi possível carregar os locais do evento.";
  } finally {
    state.loadingLocations = false;
    render();
  }
}

async function restoreSession() {
  state.bootingSession = true;
  render();

  if (!state.session?.token) {
    state.bootingSession = false;
    return;
  }

  try {
    const data = await apiRequest("/v1/auth/me", {
      headers: withAuthHeaders(),
    });
    state.event = data.event;
    state.success = "";
  } catch (error) {
    console.error(error);
    clearSession();
  } finally {
    state.bootingSession = false;
    render();
  }
}

function render() {
  app.innerHTML = "";

  const shell = document.createElement("div");
  shell.className = "shell";

  if (state.session?.token && state.event) {
    shell.append(renderDashboard());
  } else {
    shell.append(renderLogin());
  }

  app.append(shell);
}

function renderLogin() {
  const wrap = document.createElement("section");
  wrap.className = "hero hero-login";
  wrap.innerHTML = `
    <div class="brand-lockup">
      <div class="brand-mark">GS</div>
      <div>
        <div class="eyebrow">Grupo Smile</div>
        <div class="brand-name">Smile Eventos</div>
      </div>
    </div>
    <h1>Portal do Cliente</h1>
    <p>Acesse as informações do seu evento com segurança.</p>
  `;

  const formCard = document.createElement("div");
  formCard.className = "surface";

  const alert = renderAlerts();
  if (alert) {
    formCard.append(alert);
  }

  const title = document.createElement("h2");
  title.className = "section-title";
  title.textContent = "Entrar no portal";
  formCard.append(title);

  const subtitle = document.createElement("p");
  subtitle.className = "section-copy";
  subtitle.textContent = "Informe seus dados para visualizar o evento liberado para o seu atendimento.";
  formCard.append(subtitle);

  const form = document.createElement("form");
  form.className = "stack";
  form.innerHTML = `
    <div class="field">
      <label for="cpf">CPF</label>
      <input id="cpf" name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required />
    </div>
    <div class="field">
      <label for="event_date">Data do evento</label>
      <input id="event_date" name="event_date" type="date" required />
    </div>
    <div class="field">
      <label for="event_location_id">Local do evento</label>
      <select id="event_location_id" name="event_location_id" required ${state.loadingLocations ? "disabled" : ""}>
        <option value="">${state.loadingLocations ? "Carregando locais..." : "Selecione o local"}</option>
        ${state.locations.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join("")}
      </select>
    </div>
    <button class="button" type="submit" ${state.authenticating ? "disabled" : ""}>
      ${state.authenticating ? "Validando acesso..." : "Entrar no portal"}
    </button>
  `;

  form.addEventListener("submit", onLoginSubmit);
  formCard.append(form);

  const fragment = document.createDocumentFragment();
  fragment.append(wrap, formCard);
  if (DEBUG_SETTINGS_ENABLED) {
    fragment.append(renderDebugSettings());
  }
  return fragment;
}

function renderDashboard() {
  const event = state.event.event || state.event;
  const cards = event.cards || {};
  const permissions = event.permissions || {};
  const summary = event.summary || {};

  const wrap = document.createElement("div");
  wrap.className = "stack";

  const portalItems = [
    portalCard("Resumo do Evento", true, "RE", "Dados principais do evento e visão geral do atendimento."),
    portalCard(
      "Reunião Final",
      cards.reuniao_final,
      "RF",
      permissions.reuniao_editavel ? "Liberado para acompanhamento e edição do cliente." : "Liberado para consulta quando a equipe publicar."
    ),
    portalCard(
      "Convidados",
      cards.convidados,
      "CV",
      permissions.convidados_editavel ? "Lista liberada para edição do cliente." : "Lista disponível apenas para consulta."
    ),
    portalCard(
      "Arquivos",
      cards.arquivos,
      "AR",
      permissions.arquivos_editavel ? "Envio e acompanhamento de arquivos liberados." : "Área de arquivos ainda não liberada."
    ),
    portalCard("DJ", cards.dj, "DJ", cards.dj ? "Informações de protocolo e alinhamento do DJ." : "Ainda não liberado."),
    portalCard("Formulários", cards.formulario, "FM", cards.formulario ? "Formulários adicionais já disponíveis para o evento." : "Ainda não liberado.")
  ].join("");

  wrap.innerHTML = `
    <section class="hero">
      <div class="header-row header-row-top">
        <div class="hero-copy">
          <div class="eyebrow">Grupo Smile</div>
          <div class="event-title">${escapeHtml(event.name || "Seu evento")}</div>
          <p>${escapeHtml(event.client_name || "Cliente")} • ${formatDate(event.date)} • ${escapeHtml(event.location || "Local não informado")}</p>
        </div>
        <div class="badge">Acesso liberado</div>
      </div>
      <div class="hero-banner">
        <div class="hero-banner-label">Portal do Cliente</div>
        <strong>Informações centralizadas para acompanhar seu evento com a equipe Smile.</strong>
      </div>
    </section>

    <section class="surface">
      <div class="section-header">
        <div>
          <div class="section-kicker">Resumo</div>
          <h2 class="section-title">Dados do evento</h2>
        </div>
      </div>
      <div class="meta-grid">
        <div class="meta-item">
          <span>Cliente</span>
          <strong>${escapeHtml(event.client_name || "-")}</strong>
        </div>
        <div class="meta-item">
          <span>Data</span>
          <strong>${formatDate(event.date)}</strong>
        </div>
        <div class="meta-item">
          <span>Início</span>
          <strong>${escapeHtml(event.time_start || "-")}</strong>
        </div>
        <div class="meta-item">
          <span>Local</span>
          <strong>${escapeHtml(event.location || "-")}</strong>
        </div>
      </div>
    </section>

    <section class="surface">
      <div class="section-header">
        <div>
          <div class="section-kicker">Visão rápida</div>
          <h2 class="section-title">Indicadores</h2>
        </div>
      </div>
      <div class="stats-grid">
        <div class="stat-item">
          <span>Convidados</span>
          <strong>${summary.convidados?.total ?? 0}</strong>
        </div>
        <div class="stat-item">
          <span>Check-in</span>
          <strong>${summary.convidados?.checkin ?? 0}</strong>
        </div>
        <div class="stat-item">
          <span>Arquivos</span>
          <strong>${summary.arquivos?.arquivos_total ?? 0}</strong>
        </div>
        <div class="stat-item">
          <span>Pendências</span>
          <strong>${summary.arquivos?.campos_pendentes ?? 0}</strong>
        </div>
      </div>
    </section>

    <section class="surface">
      <div class="section-header">
        <div>
          <div class="section-kicker">Acompanhamento</div>
          <h2 class="section-title">Áreas do portal</h2>
        </div>
      </div>
      <div class="cards-grid">
        ${portalItems}
      </div>
    </section>

    <section class="card footer-actions">
      <button class="button-secondary" type="button" id="refresh-event">Atualizar informações</button>
      <button class="button" type="button" id="logout-event">Sair</button>
    </section>
  `;

  wrap.querySelector("#refresh-event").addEventListener("click", refreshEventSummary);
  wrap.querySelector("#logout-event").addEventListener("click", logout);
  return wrap;
}

function portalCard(title, enabled, monogram, description) {
  return `
    <div class="portal-card ${enabled ? "" : "disabled"}">
      <div class="portal-card-top">
        <div class="portal-icon">${escapeHtml(monogram)}</div>
        <span class="portal-status">${enabled ? "Liberado" : "Ainda não liberado"}</span>
      </div>
      <strong>${escapeHtml(title)}</strong>
      <div class="muted">${escapeHtml(description)}</div>
    </div>
  `;
}

function renderDebugSettings() {
  const settings = document.createElement("div");
  settings.className = "card debug-card";
  settings.innerHTML = `
    <div class="section-kicker">Debug</div>
    <h2 class="section-title">Configuração interna</h2>
    <p class="section-copy">Área visível apenas em modo de desenvolvimento.</p>
    <div class="field">
      <label for="api_base">URL da API</label>
      <input id="api_base" name="api_base" value="${escapeAttribute(state.apiBase)}" />
    </div>
  `;
  settings.querySelector("#api_base").addEventListener("change", onApiBaseChange);
  return settings;
}

function renderAlerts() {
  if (state.error) {
    return createAlert(state.error, "alert-error");
  }
  if (state.success) {
    return createAlert(state.success, "alert-success");
  }
  return null;
}

function createAlert(message, className) {
  const div = document.createElement("div");
  div.className = `alert ${className}`;
  div.textContent = message;
  return div;
}

async function onLoginSubmit(event) {
  event.preventDefault();
  state.error = "";
  state.success = "";
  state.authenticating = true;
  render();

  const form = new FormData(event.currentTarget);
  const payload = {
    cpf: String(form.get("cpf") || ""),
    event_date: String(form.get("event_date") || ""),
    event_location_id: Number(form.get("event_location_id") || 0),
    platform: detectPlatform(),
    app_version: "0.1.0",
    device_name: detectDeviceName(),
  };

  try {
    const data = await apiRequest("/v1/auth/login", {
      method: "POST",
      body: JSON.stringify(payload),
      headers: {
        "Content-Type": "application/json",
      },
    });

    state.session = {
      token: data.token,
      expires_at: data.expires_at,
    };
    persistSession();
    state.success = "Acesso liberado.";
    await refreshEventSummary();
  } catch (error) {
    console.error(error);
    state.error = error.message || "Falha no login.";
  } finally {
    state.authenticating = false;
    render();
  }
}

async function refreshEventSummary() {
  if (!state.session?.token) {
    return;
  }

  state.error = "";
  render();

  try {
    const data = await apiRequest("/v1/client/event/summary", {
      headers: withAuthHeaders(),
    });
    state.event = data.event;
    state.success = "Informações atualizadas.";
  } catch (error) {
    console.error(error);
    state.error = error.message || "Não foi possível carregar o evento.";
    if (/Sessão inválida|expirada/i.test(state.error)) {
      clearSession();
    }
  } finally {
    render();
  }
}

async function logout() {
  try {
    if (state.session?.token) {
      await apiRequest("/v1/auth/logout", {
        method: "POST",
        headers: withAuthHeaders(),
      });
    }
  } catch (error) {
    console.error(error);
  } finally {
    clearSession();
    state.success = "Acesso encerrado.";
    render();
  }
}

function onApiBaseChange(event) {
  const value = String(event.target.value || "").trim().replace(/\/$/, "");
  if (!value) {
    return;
  }
  state.apiBase = value;
  window.localStorage.setItem("smile-client-api-base", state.apiBase);
  state.success = "API atualizada.";
  state.error = "";
  fetchLocations();
}

function withAuthHeaders() {
  return {
    Authorization: `Bearer ${state.session?.token || ""}`,
  };
}

async function apiRequest(path, options = {}) {
  const response = await fetch(`${state.apiBase}${path}`, options);
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.ok) {
    throw new Error(data.error || "Falha na comunicação com a API.");
  }
  return data;
}

function loadSession() {
  try {
    const raw = window.localStorage.getItem(APP_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch (error) {
    console.error(error);
    return null;
  }
}

function persistSession() {
  window.localStorage.setItem(APP_KEY, JSON.stringify(state.session));
}

function clearSession() {
  state.session = null;
  state.event = null;
  window.localStorage.removeItem(APP_KEY);
}

function formatDate(value) {
  if (!value) {
    return "-";
  }
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return new Intl.DateTimeFormat("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(date);
}

function detectPlatform() {
  const ua = navigator.userAgent.toLowerCase();
  if (ua.includes("iphone") || ua.includes("ipad")) {
    return "ios";
  }
  if (ua.includes("android")) {
    return "android";
  }
  return "web";
}

function detectDeviceName() {
  const platform = detectPlatform();
  if (platform === "android") {
    return "Android App";
  }
  if (platform === "ios") {
    return "iPhone App";
  }
  return "Web App";
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function escapeAttribute(value) {
  return escapeHtml(value);
}
