const DEFAULT_API_BASE = "https://smile-client-app-api-production.up.railway.app/api";
const APP_KEY = "smile-client-app-session";

const state = {
  apiBase: (window.localStorage.getItem("smile-client-api-base") || DEFAULT_API_BASE).replace(/\/$/, ""),
  locations: [],
  loadingLocations: true,
  authenticating: false,
  bootingSession: true,
  session: loadSession(),
  event: null,
  route: {
    name: "dashboard",
    moduleKey: "",
  },
  iframeLoading: false,
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
    if (state.route.name === "module") {
      shell.append(renderModuleScreen());
    } else {
      shell.append(renderDashboard());
    }
  } else {
    shell.append(renderLogin());
  }

  app.append(shell);
}

function renderLogin() {
  const wrap = document.createElement("section");
  wrap.className = "hero hero-login";
  wrap.innerHTML = `
    <img class="hero-logo" src="/meu-evento-smile.png" alt="Meu Evento Smile" />
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
  return fragment;
}

function renderDashboard() {
  const event = state.event.event || state.event;
  const modules = getVisibleModules(event);

  const wrap = document.createElement("div");
  wrap.className = "stack";

  wrap.innerHTML = `
    <section class="hero">
      <img class="hero-logo hero-logo-dashboard" src="/meu-evento-smile.png" alt="Meu Evento Smile" />
      <div class="hero-banner">
        <strong>${escapeHtml(event.name || "Seu evento")}</strong>
        <p>${escapeHtml(event.client_name || "Cliente")} • ${formatDate(event.date)} • ${escapeHtml(event.location || "Local não informado")}</p>
      </div>
    </section>

    <section class="surface">
      <div class="section-kicker">Portal</div>
      <h2 class="section-title">Áreas liberadas</h2>
      <div class="portal-grid">
        ${modules.length > 0 ? modules.map(renderModuleTile).join("") : emptyPortalState("Nenhuma área está liberada para este portal no momento.")}
      </div>
    </section>

    <section class="card footer-actions">
      <button class="button" type="button" id="logout-event">Sair</button>
    </section>
  `;

  wrap.querySelectorAll("[data-module-key]").forEach((button) => {
    button.addEventListener("click", () => {
      openModule(String(button.dataset.moduleKey || ""));
    });
  });
  wrap.querySelector("#logout-event").addEventListener("click", logout);
  return wrap;
}

function renderModuleScreen() {
  const event = state.event.event || state.event;
  const module = getModuleMap(event)[state.route.moduleKey] || null;
  if (!module) {
    state.route = { name: "dashboard", moduleKey: "" };
    return renderDashboard();
  }

  const wrap = document.createElement("div");
  wrap.className = "stack";
  wrap.innerHTML = `
    <section class="surface module-shell">
      <div class="module-shell-header">
        <button class="ghost-button" type="button" id="module-back">Voltar</button>
        <div class="module-shell-copy">
          <div class="section-kicker">Portal do cliente</div>
          <h2 class="section-title">${escapeHtml(module.title)}</h2>
          <p class="section-copy">${escapeHtml(event.name || "Seu evento")}</p>
        </div>
      </div>
      <div class="module-frame-wrap">
        ${state.iframeLoading ? '<div class="module-loading">Carregando conteúdo...</div>' : ""}
        <iframe
          class="module-frame"
          id="module-frame"
          title="${escapeHtml(module.title)}"
          src="${escapeHtml(module.url)}"
          loading="eager"
          referrerpolicy="no-referrer-when-downgrade"
        ></iframe>
      </div>
      <div class="module-actions">
        <a class="ghost-link" href="${escapeHtml(module.url)}" target="_blank" rel="noreferrer">Abrir em tela completa</a>
      </div>
    </section>
  `;

  wrap.querySelector("#module-back").addEventListener("click", () => {
    state.route = { name: "dashboard", moduleKey: "" };
    state.iframeLoading = false;
    render();
  });
  const frame = wrap.querySelector("#module-frame");
  if (frame) {
    frame.addEventListener("load", () => {
      if (state.iframeLoading) {
        state.iframeLoading = false;
        render();
      }
    });
  }

  return wrap;
}

function emptyPortalState(message) {
  return `<div class="portal-empty">${escapeHtml(message)}</div>`;
}

function getModuleMap(event) {
  const cards = event.cards || {};
  const urls = event.module_urls || {};
  const permissions = event.permissions || {};

  return {
    reuniao_final: {
      key: "reuniao_final",
      title: "Reunião Final",
      description: permissions.reuniao_editavel
        ? "Acompanhamento e preenchimento da reunião final."
        : "Acompanhamento da reunião final liberada pela equipe.",
      url: urls.reuniao_final || "",
      enabled: Boolean(cards.reuniao_final && urls.reuniao_final),
    },
    convidados: {
      key: "convidados",
      title: "Convidados",
      description: permissions.convidados_editavel
        ? "Lista de convidados liberada para edição."
        : "Lista de convidados disponível para consulta.",
      url: urls.convidados || "",
      enabled: Boolean(cards.convidados && urls.convidados),
    },
    arquivos: {
      key: "arquivos",
      title: "Arquivos",
      description: permissions.arquivos_editavel
        ? "Envio e acompanhamento de arquivos do evento."
        : "Consulta de arquivos liberados para o evento.",
      url: urls.arquivos || "",
      enabled: Boolean(cards.arquivos && urls.arquivos),
    },
    dj: {
      key: "dj",
      title: "DJ",
      description: "Formulários e alinhamentos de DJ e protocolo.",
      url: urls.dj || "",
      enabled: Boolean(cards.dj && urls.dj),
    },
    formulario: {
      key: "formulario",
      title: "Formulários",
      description: "Formulários adicionais liberados para este evento.",
      url: urls.formulario || "",
      enabled: Boolean(cards.formulario && urls.formulario),
    },
  };
}

function getVisibleModules(event) {
  return Object.values(getModuleMap(event)).filter((item) => item.enabled);
}

function renderModuleTile(module) {
  return `
    <button class="portal-tile portal-tile-action" type="button" data-module-key="${escapeHtml(module.key)}">
      <strong>${escapeHtml(module.title)}</strong>
      <div class="muted">${escapeHtml(module.description)}</div>
      <span class="tile-link">Abrir</span>
    </button>
  `;
}

function openModule(moduleKey) {
  const event = state.event?.event || state.event;
  if (!event) {
    return;
  }

  const module = getModuleMap(event)[moduleKey] || null;
  if (!module || !module.enabled || !module.url) {
    state.error = "Esta área não está disponível no momento.";
    render();
    return;
  }

  state.error = "";
  state.success = "";
  state.route = {
    name: "module",
    moduleKey,
  };
  state.iframeLoading = true;
  render();
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
    state.success = "";
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
    state.success = "";
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
    state.route = { name: "dashboard", moduleKey: "" };
    state.success = "";
    render();
  }
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
  state.route = { name: "dashboard", moduleKey: "" };
  state.iframeLoading = false;
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
