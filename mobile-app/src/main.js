const DEFAULT_API_BASE = "https://smile-client-app-api-production.up.railway.app/api";
const APP_KEY = "smile-client-app-session";

const MODULE_META = {
  reuniao_final: {
    key: "reuniao_final",
    title: "Reunião Final",
    badge: "RF",
    endpoint: "/v1/client/modules/reuniao-final",
  },
  convidados: {
    key: "convidados",
    title: "Convidados",
    badge: "CV",
    endpoint: "/v1/client/modules/convidados",
  },
  arquivos: {
    key: "arquivos",
    title: "Arquivos",
    badge: "AR",
    endpoint: "/v1/client/modules/arquivos",
  },
  dj: {
    key: "dj",
    title: "DJ e Protocolo",
    badge: "DJ",
    endpoint: "/v1/client/modules/dj",
  },
  formulario: {
    key: "formulario",
    title: "Formulários",
    badge: "FM",
    endpoint: "/v1/client/modules/formulario",
  },
};

const state = {
  apiBase: DEFAULT_API_BASE.replace(/\/$/, ""),
  locations: [],
  loadingLocations: true,
  authenticating: false,
  bootingSession: true,
  session: loadSession(),
  event: null,
  screen: {
    name: "dashboard",
    key: "",
  },
  moduleLoading: false,
  moduleError: "",
  moduleCache: {},
  editingGuestId: 0,
  guestFormBusy: false,
  fileUploadBusyFieldId: 0,
  fileDeleteBusyId: 0,
  formBusyLinkId: 0,
  formUploadBusyKey: "",
  formDeleteBusyId: 0,
  error: "",
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
    state.error = "";
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
  shell.className = "app-shell";

  if (!state.session?.token || !state.event) {
    shell.append(renderLogin());
  } else if (state.screen.name === "dashboard") {
    shell.append(renderDashboard());
  } else {
    shell.append(renderModuleScreen());
  }

  app.append(shell);
}

function renderLogin() {
  const wrap = document.createElement("div");
  wrap.className = "stack";

  const hero = document.createElement("section");
  hero.className = "brand-hero";
  hero.innerHTML = `
    <img class="brand-hero-logo" src="/meu-evento-smile.png" alt="Meu Evento Smile" />
    <p class="brand-hero-copy">Seu evento em uma experiência pensada para celular, com acesso direto ao que realmente importa.</p>
  `;

  const card = document.createElement("section");
  card.className = "panel";
  const alert = renderAlert(state.error, "error");
  if (alert) {
    card.append(alert);
  }
  card.innerHTML += `
    <div class="panel-header">
      <div>
        <div class="eyebrow">Acesso</div>
        <h1 class="panel-title">Entrar no portal</h1>
        <p class="panel-copy">Informe seus dados para localizar o evento liberado para o seu atendimento.</p>
      </div>
    </div>
  `;

  const form = document.createElement("form");
  form.className = "stack";
  form.innerHTML = `
    <label class="field">
      <span>CPF</span>
      <input id="cpf" name="cpf" inputmode="numeric" autocomplete="off" placeholder="000.000.000-00" required />
    </label>
    <label class="field">
      <span>Data do evento</span>
      <input id="event_date" name="event_date" type="date" required />
    </label>
    <label class="field">
      <span>Local do evento</span>
      <select id="event_location_id" name="event_location_id" required ${state.loadingLocations ? "disabled" : ""}>
        <option value="">${state.loadingLocations ? "Carregando locais..." : "Selecione o local"}</option>
        ${state.locations.map((item) => `<option value="${item.id}">${escapeHtml(item.name)}</option>`).join("")}
      </select>
    </label>
    <button class="primary-button" type="submit" ${state.authenticating ? "disabled" : ""}>
      ${state.authenticating ? "Validando acesso..." : "Entrar no portal"}
    </button>
  `;
  form.addEventListener("submit", onLoginSubmit);
  card.append(form);

  wrap.append(hero, card);
  return wrap;
}

function renderDashboard() {
  const event = state.event?.event || state.event;
  const modules = getVisibleModules(event);
  const wrap = document.createElement("div");
  wrap.className = "stack";
  wrap.innerHTML = `
    <section class="hero-card">
      <div class="hero-topline">
        <img class="hero-logo" src="/meu-evento-smile.png" alt="Meu Evento Smile" />
      </div>
      <div class="hero-event-name">${escapeHtml(event.name || "Seu evento")}</div>
      <div class="hero-event-meta">${escapeHtml(event.client_name || "Cliente")}</div>
      <div class="hero-summary">
        ${clientSummaryRows(event).map((item) => `
          <div class="summary-item">
            <span class="summary-label">${escapeHtml(item.label)}</span>
            <strong>${escapeHtml(item.value)}</strong>
          </div>
        `).join("")}
      </div>
    </section>

    <section class="panel">
      <div class="panel-header">
        <div>
          <div class="eyebrow">Portal</div>
          <h2 class="panel-title">Áreas liberadas</h2>
          <p class="panel-copy">Tudo o que o cliente visualiza no app respeita exatamente a liberação do portal.</p>
        </div>
      </div>
      <div class="module-grid">
        ${modules.map(renderDashboardModuleCard).join("")}
      </div>
    </section>

    <section class="panel action-panel">
      <button class="secondary-button" type="button" id="logout-button">Sair</button>
    </section>
  `;

  wrap.querySelectorAll("[data-open-module]").forEach((button) => {
    button.addEventListener("click", () => openModule(String(button.dataset.openModule || "")));
  });
  wrap.querySelector("#logout-button")?.addEventListener("click", logout);
  return wrap;
}

function renderDashboardModuleCard(module) {
  const summaryText = moduleSummaryText(module);
  return `
    <button class="module-card" type="button" data-open-module="${escapeHtml(module.key)}">
      <span class="module-badge">${escapeHtml(module.badge)}</span>
      <strong>${escapeHtml(module.title)}</strong>
      <span>${escapeHtml(module.description)}</span>
      ${summaryText ? `<small>${escapeHtml(summaryText)}</small>` : ""}
    </button>
  `;
}

function renderModuleScreen() {
  const meta = MODULE_META[state.screen.key];
  if (!meta) {
    state.screen = { name: "dashboard", key: "" };
    return renderDashboard();
  }

  const module = state.moduleCache[state.screen.key] || null;
  const wrap = document.createElement("div");
  wrap.className = "stack";

  const header = document.createElement("section");
  header.className = "module-hero";
  header.innerHTML = `
    <button class="back-button" type="button" id="back-button">Voltar</button>
    <div class="module-hero-copy">
      <div class="eyebrow">Meu Evento Smile</div>
      <h1 class="panel-title">${escapeHtml(meta.title)}</h1>
      <p class="panel-copy">${escapeHtml(state.event?.name || "")}</p>
    </div>
  `;
  wrap.append(header);

  const feedback = renderAlert(state.moduleError || state.error, "error");
  if (feedback) {
    wrap.append(feedback);
  }

  if (state.moduleLoading || !module) {
    const loading = document.createElement("section");
    loading.className = "panel";
    loading.innerHTML = `<div class="loading-shell">Carregando módulo...</div>`;
    wrap.append(loading);
  } else if (state.screen.key === "reuniao_final") {
    wrap.append(renderMeetingModule(module));
  } else if (state.screen.key === "convidados") {
    wrap.append(renderGuestsModule(module));
  } else if (state.screen.key === "arquivos") {
    wrap.append(renderFilesModule(module));
  } else {
    wrap.append(renderFormsModule(module));
  }

  wrap.querySelector("#back-button")?.addEventListener("click", goBack);
  return wrap;
}

function renderMeetingModule(module) {
  const wrap = document.createElement("div");
  wrap.className = "stack";
  wrap.innerHTML = `
    <section class="panel">
      <div class="panel-header">
        <div>
          <div class="eyebrow">Evento</div>
          <h2 class="panel-title">${escapeHtml(module.event?.name || "Evento")}</h2>
        </div>
      </div>
      <div class="detail-grid">
        ${(module.summary || []).map((item) => `
          <div class="detail-card">
            <span>${escapeHtml(item.label)}</span>
            <strong>${escapeHtml(item.value)}</strong>
          </div>
        `).join("")}
      </div>
    </section>
    ${(module.sections || []).map(renderMeetingSection).join("")}
  `;
  return wrap;
}

function renderMeetingSection(section) {
  const filledFields = filterDisplayFields(section.schema || [], section.values || {});
  return `
    <section class="panel">
      <div class="panel-header">
        <div>
          <div class="eyebrow">Seção</div>
          <h2 class="panel-title">${escapeHtml(section.title || "Seção")}</h2>
          ${section.text_preview ? `<p class="panel-copy">${escapeHtml(section.text_preview)}</p>` : ""}
        </div>
      </div>
      ${filledFields.length > 0 ? `
        <div class="form-readonly-list">
          ${filledFields.map((field) => `
            <div class="readonly-row">
              <span>${escapeHtml(field.label)}</span>
              <strong>${escapeHtml(field.value)}</strong>
            </div>
          `).join("")}
        </div>
      ` : `<div class="empty-state">Nenhuma informação preenchida nesta seção ainda.</div>`}
    </section>
  `;
}

function renderGuestsModule(module) {
  const editingGuest = findEditingGuest(module.items || []);
  const wrap = document.createElement("div");
  wrap.className = "stack";
  wrap.innerHTML = `
    <section class="panel">
      <div class="panel-header">
        <div>
          <div class="eyebrow">Convidados</div>
          <h2 class="panel-title">Lista do evento</h2>
          <p class="panel-copy">${module.editable ? "O cliente pode incluir, ajustar e remover convidados por aqui." : "Lista disponível apenas para consulta."}</p>
        </div>
      </div>
      <div class="metric-grid">
        <div class="metric-card"><span>Total</span><strong>${escapeHtml(String(module.summary?.total || 0))}</strong></div>
        <div class="metric-card"><span>Check-ins</span><strong>${escapeHtml(String(module.summary?.checkin || 0))}</strong></div>
        <div class="metric-card"><span>Pendentes</span><strong>${escapeHtml(String(module.summary?.pendentes || 0))}</strong></div>
      </div>
    </section>
  `;

  if (module.editable) {
    const editor = document.createElement("section");
    editor.className = "panel";
    editor.innerHTML = `
      <div class="panel-header">
        <div>
          <div class="eyebrow">${editingGuest ? "Editar" : "Adicionar"}</div>
          <h2 class="panel-title">${editingGuest ? "Atualizar convidado" : "Novo convidado"}</h2>
        </div>
      </div>
      <form class="stack" id="guest-form">
        <label class="field">
          <span>Nome</span>
          <input name="name" value="${escapeHtml(editingGuest?.name || "")}" placeholder="Nome do convidado" required />
        </label>
        <label class="field">
          <span>Faixa etária</span>
          <select name="age_group">
            <option value="">Selecione</option>
            ${(module.config?.opcoes_faixa || []).map((option) => `
              <option value="${escapeHtml(option)}" ${option === (editingGuest?.age_group || "") ? "selected" : ""}>${escapeHtml(option)}</option>
            `).join("")}
          </select>
        </label>
        ${module.config?.usa_mesa ? `
          <label class="field">
            <span>Mesa</span>
            <input name="table_number" value="${escapeHtml(editingGuest?.table_number || "")}" placeholder="Número da mesa" />
          </label>
        ` : ""}
        <div class="inline-actions">
          <button class="primary-button" type="submit" ${state.guestFormBusy ? "disabled" : ""}>
            ${state.guestFormBusy ? "Salvando..." : (editingGuest ? "Salvar ajustes" : "Adicionar convidado")}
          </button>
          ${editingGuest ? `<button class="secondary-button" type="button" id="guest-cancel">Cancelar</button>` : ""}
        </div>
      </form>
    `;
    wrap.append(editor);

    queueMicrotask(() => {
      wrap.querySelector("#guest-form")?.addEventListener("submit", onGuestSave);
      wrap.querySelector("#guest-cancel")?.addEventListener("click", () => {
        state.editingGuestId = 0;
        render();
      });
    });
  }

  const list = document.createElement("section");
  list.className = "panel";
  list.innerHTML = `
    <div class="panel-header">
      <div>
        <div class="eyebrow">Lista</div>
        <h2 class="panel-title">${(module.items || []).length} convidados</h2>
      </div>
    </div>
    <div class="list-stack">
      ${(module.items || []).length > 0 ? module.items.map((item) => renderGuestRow(item, module.editable)).join("") : '<div class="empty-state">Nenhum convidado cadastrado ainda.</div>'}
    </div>
  `;
  wrap.append(list);

  queueMicrotask(() => {
    wrap.querySelectorAll("[data-guest-edit]").forEach((button) => {
      button.addEventListener("click", () => {
        state.editingGuestId = Number(button.dataset.guestEdit || 0);
        render();
      });
    });
    wrap.querySelectorAll("[data-guest-delete]").forEach((button) => {
      button.addEventListener("click", () => onGuestDelete(Number(button.dataset.guestDelete || 0)));
    });
  });

  return wrap;
}

function renderGuestRow(item, editable) {
  return `
    <article class="list-card">
      <div class="list-card-main">
        <strong>${escapeHtml(item.name || "Convidado")}</strong>
        <span>${escapeHtml(item.age_group || "Faixa não informada")}${item.table_number ? ` • Mesa ${escapeHtml(item.table_number)}` : ""}</span>
      </div>
      ${editable ? `
        <div class="list-card-actions">
          <button class="mini-button" type="button" data-guest-edit="${escapeHtml(String(item.id))}">Editar</button>
          <button class="mini-button danger" type="button" data-guest-delete="${escapeHtml(String(item.id))}">Excluir</button>
        </div>
      ` : ""}
    </article>
  `;
}

function renderFilesModule(module) {
  const wrap = document.createElement("div");
  wrap.className = "stack";
  wrap.innerHTML = `
    <section class="panel">
      <div class="panel-header">
        <div>
          <div class="eyebrow">Arquivos</div>
          <h2 class="panel-title">Central de documentos</h2>
          <p class="panel-copy">${module.editable ? "Envie os arquivos solicitados e acompanhe os materiais já liberados." : "Arquivos liberados para consulta neste evento."}</p>
        </div>
      </div>
      <div class="metric-grid">
        <div class="metric-card"><span>Campos</span><strong>${escapeHtml(String(module.summary?.campos_total || 0))}</strong></div>
        <div class="metric-card"><span>Obrigatórios</span><strong>${escapeHtml(String(module.summary?.campos_obrigatorios || 0))}</strong></div>
        <div class="metric-card"><span>Pendentes</span><strong>${escapeHtml(String(module.summary?.campos_pendentes || 0))}</strong></div>
      </div>
    </section>
    ${(module.fields || []).map((field) => renderFileFieldCard(field, module.editable)).join("")}
  `;

  queueMicrotask(() => {
    wrap.querySelectorAll("[data-upload-field]").forEach((form) => {
      form.addEventListener("submit", onFileUpload);
    });
    wrap.querySelectorAll("[data-file-delete]").forEach((button) => {
      button.addEventListener("click", () => onFileDelete(Number(button.dataset.fileDelete || 0)));
    });
  });

  return wrap;
}

function renderFileFieldCard(field, editable) {
  return `
    <section class="panel">
      <div class="panel-header">
        <div>
          <div class="eyebrow">${field.required ? "Obrigatório" : "Opcional"}</div>
          <h2 class="panel-title">${escapeHtml(field.title || "Arquivo solicitado")}</h2>
          ${field.description ? `<p class="panel-copy">${escapeHtml(field.description)}</p>` : ""}
        </div>
      </div>
      ${(field.files || []).length > 0 ? `
        <div class="list-stack">
          ${field.files.map((file) => `
            <article class="list-card">
              <div class="list-card-main">
                <strong>${escapeHtml(file.name)}</strong>
                <span>${escapeHtml(file.description || formatFileSize(file.size_bytes))}</span>
              </div>
              <div class="list-card-actions">
                ${file.public_url ? `<a class="mini-link" href="${escapeHtml(file.public_url)}" target="_blank" rel="noreferrer">Abrir</a>` : ""}
                ${editable && file.uploaded_by_client ? `<button class="mini-button danger" type="button" data-file-delete="${escapeHtml(String(file.id))}" ${state.fileDeleteBusyId === file.id ? "disabled" : ""}>Excluir</button>` : ""}
              </div>
            </article>
          `).join("")}
        </div>
      ` : '<div class="empty-state">Nenhum arquivo enviado para este item ainda.</div>'}
      ${editable ? `
        <form class="stack compact-top" data-upload-field="${escapeHtml(String(field.id))}">
          <input type="hidden" name="campo_id" value="${escapeHtml(String(field.id))}" />
          <label class="field">
            <span>Descrição</span>
            <input name="descricao" placeholder="Observação opcional" />
          </label>
          <label class="field file-field">
            <span>Arquivo</span>
            <input name="arquivo" type="file" required />
          </label>
          <button class="primary-button" type="submit" ${state.fileUploadBusyFieldId === field.id ? "disabled" : ""}>
            ${state.fileUploadBusyFieldId === field.id ? "Enviando..." : "Enviar arquivo"}
          </button>
        </form>
      ` : ""}
    </section>
  `;
}

function renderFormsModule(module) {
  const wrap = document.createElement("div");
  wrap.className = "stack";
  wrap.innerHTML = `
    <section class="panel">
      <div class="panel-header">
        <div>
          <div class="eyebrow">${escapeHtml(module.title || "Formulários")}</div>
          <h2 class="panel-title">${escapeHtml(module.event?.name || "Evento")}</h2>
          <p class="panel-copy">Preenchimento nativo do app, sem abrir páginas externas do portal.</p>
        </div>
      </div>
    </section>
    ${(module.forms || []).length > 0 ? module.forms.map((form) => renderNativeFormCard(module.key, form)).join("") : '<section class="panel"><div class="empty-state">Nenhum formulário liberado neste módulo.</div></section>'}
  `;

  queueMicrotask(() => {
    wrap.querySelectorAll("[data-form-save]").forEach((button) => {
      button.addEventListener("click", () => onPortalFormSave(button));
    });
    wrap.querySelectorAll("[data-form-file-upload]").forEach((form) => {
      form.addEventListener("submit", onPortalFormFileUpload);
    });
    wrap.querySelectorAll("[data-form-file-delete]").forEach((button) => {
      button.addEventListener("click", () => onPortalFormFileDelete(button));
    });
  });

  return wrap;
}

function renderNativeFormCard(moduleKey, form) {
  const readonly = !form.editable;
  const filledFields = filterDisplayFields(form.schema || [], form.values || {});
  const filesByField = form.files_by_field || {};
  return `
    <section class="panel">
      <div class="panel-header">
        <div>
          <div class="eyebrow">Etapa ${escapeHtml(String(form.slot_index || 1))}</div>
          <h2 class="panel-title">${escapeHtml(form.title || "Formulário")}</h2>
          ${form.preview ? `<p class="panel-copy">${escapeHtml(form.preview)}</p>` : ""}
        </div>
      </div>
      ${readonly ? `
        ${filledFields.length > 0 ? `
          <div class="form-readonly-list">
            ${filledFields.map((field) => `
              <div class="readonly-row">
                <span>${escapeHtml(field.label)}</span>
                <strong>${escapeHtml(field.value)}</strong>
              </div>
            `).join("")}
          </div>
        ` : '<div class="empty-state">Nenhuma resposta enviada ainda.</div>'}
      ` : `
        <form class="stack" data-form-shell="${escapeHtml(String(form.id))}">
          ${(form.schema || []).map((field) => renderFormField(field, form.values || {}, filesByField, form, moduleKey)).join("")}
          <div class="inline-actions">
            <button class="secondary-button" type="button" data-form-save="${escapeHtml(String(form.id))}" data-module-key="${escapeHtml(moduleKey)}" data-submit-mode="draft" ${state.formBusyLinkId === form.id ? "disabled" : ""}>
              ${state.formBusyLinkId === form.id ? "Salvando..." : "Salvar rascunho"}
            </button>
            <button class="primary-button" type="button" data-form-save="${escapeHtml(String(form.id))}" data-module-key="${escapeHtml(moduleKey)}" data-submit-mode="submit" ${state.formBusyLinkId === form.id ? "disabled" : ""}>
              ${state.formBusyLinkId === form.id ? "Enviando..." : "Enviar respostas"}
            </button>
          </div>
        </form>
      `}
    </section>
  `;
}

function renderFormField(field, values, filesByField = {}, form = null, moduleKey = "") {
  const type = String(field.type || "text").toLowerCase();
  const fieldId = String(field.id || "");
  if (type === "divider") {
    return `<div class="form-divider"></div>`;
  }
  if (type === "section") {
    return `<div class="form-section-title">${escapeHtml(field.label || "")}</div>`;
  }
  if (type === "note") {
    return `<div class="note-block">${field.content_html || ""}</div>`;
  }
  if (type === "file") {
    const files = Array.isArray(filesByField[fieldId]) ? filesByField[fieldId] : [];
    const busyKey = `${moduleKey}:${form?.id || 0}:${fieldId}`;
    return `
      <div class="field field-upload-card">
        <span>${escapeHtml(field.label || "Arquivo")}${field.required ? " *" : ""}</span>
        ${field.description ? `<div class="field-hint">${escapeHtml(field.description)}</div>` : ""}
        ${files.length > 0 ? `
          <div class="list-stack compact-list">
            ${files.map((file) => `
              <article class="list-card file-inline-card">
                <div class="list-card-main">
                  <strong>${escapeHtml(file.name)}</strong>
                  <span>${escapeHtml(file.description || formatFileSize(file.size_bytes))}</span>
                </div>
                <div class="list-card-actions">
                  ${file.public_url ? `<a class="mini-link" href="${escapeHtml(file.public_url)}" target="_blank" rel="noreferrer">Abrir</a>` : ""}
                  ${file.uploaded_by_client ? `<button class="mini-button danger" type="button" data-form-file-delete="${escapeHtml(String(file.id))}" data-form-link-id="${escapeHtml(String(form?.id || 0))}" data-module-key="${escapeHtml(moduleKey)}" ${state.formDeleteBusyId === file.id ? "disabled" : ""}>Excluir</button>` : ""}
                </div>
              </article>
            `).join("")}
          </div>
        ` : `<div class="empty-state tight-empty">Nenhum arquivo enviado neste item.</div>`}
        <form class="stack compact-top" data-form-file-upload="${escapeHtml(fieldId)}" data-form-link-id="${escapeHtml(String(form?.id || 0))}" data-module-key="${escapeHtml(moduleKey)}">
          <input type="hidden" name="field_id" value="${escapeHtml(fieldId)}" />
          <label class="field file-field">
            <span>Selecionar arquivo</span>
            <input name="arquivo" type="file" ${field.required && files.length === 0 ? "required" : ""} />
          </label>
          <label class="field">
            <span>Descrição</span>
            <input name="description" placeholder="Observação opcional" />
          </label>
          <button class="secondary-button" type="submit" ${state.formUploadBusyKey === busyKey ? "disabled" : ""}>
            ${state.formUploadBusyKey === busyKey ? "Enviando..." : "Adicionar arquivo"}
          </button>
        </form>
      </div>
    `;
  }

  const currentValue = escapeHtml(fieldValue(field, values));
  if (type === "textarea") {
    return `
      <label class="field">
        <span>${escapeHtml(field.label || "Campo")}${field.required ? " *" : ""}</span>
        <textarea name="${escapeHtml(fieldId)}" rows="4" ${field.required ? "required" : ""}>${currentValue}</textarea>
      </label>
    `;
  }
  if (type === "select") {
    return `
      <label class="field">
        <span>${escapeHtml(field.label || "Campo")}${field.required ? " *" : ""}</span>
        <select name="${escapeHtml(fieldId)}" ${field.required ? "required" : ""}>
          <option value="">Selecione</option>
          ${(field.options || []).map((option) => `
            <option value="${escapeHtml(option)}" ${option === fieldValue(field, values) ? "selected" : ""}>${escapeHtml(option)}</option>
          `).join("")}
        </select>
      </label>
    `;
  }
  if (type === "yesno") {
    const value = fieldValue(field, values);
    return `
      <label class="field">
        <span>${escapeHtml(field.label || "Campo")}${field.required ? " *" : ""}</span>
        <select name="${escapeHtml(fieldId)}" ${field.required ? "required" : ""}>
          <option value="">Selecione</option>
          <option value="Sim" ${value === "Sim" ? "selected" : ""}>Sim</option>
          <option value="Não" ${value === "Não" ? "selected" : ""}>Não</option>
        </select>
      </label>
    `;
  }
  return `
    <label class="field">
      <span>${escapeHtml(field.label || "Campo")}${field.required ? " *" : ""}</span>
      <input name="${escapeHtml(fieldId)}" value="${currentValue}" ${field.required ? "required" : ""} />
    </label>
  `;
}

async function openModule(moduleKey) {
  if (!MODULE_META[moduleKey]) {
    return;
  }
  state.error = "";
  state.moduleError = "";
  state.screen = { name: "module", key: moduleKey };
  if (!state.moduleCache[moduleKey]) {
    await fetchModule(moduleKey);
  } else {
    render();
  }
}

async function fetchModule(moduleKey, force = false) {
  if (!MODULE_META[moduleKey]) {
    return;
  }
  if (!force && state.moduleCache[moduleKey]) {
    render();
    return;
  }
  state.moduleLoading = true;
  state.moduleError = "";
  render();
  try {
    const data = await apiRequest(MODULE_META[moduleKey].endpoint, {
      headers: withAuthHeaders(),
    });
    state.moduleCache[moduleKey] = data.module;
  } catch (error) {
    console.error(error);
    state.moduleError = error.message || "Não foi possível carregar esta área.";
  } finally {
    state.moduleLoading = false;
    render();
  }
}

function goBack() {
  state.screen = { name: "dashboard", key: "" };
  state.moduleError = "";
  state.editingGuestId = 0;
  render();
}

async function onLoginSubmit(event) {
  event.preventDefault();
  state.error = "";
  state.authenticating = true;
  render();

  const form = new FormData(event.currentTarget);
  const payload = {
    cpf: String(form.get("cpf") || ""),
    event_date: String(form.get("event_date") || ""),
    event_location_id: Number(form.get("event_location_id") || 0),
    platform: detectPlatform(),
    app_version: "1.0.0",
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
    state.session = { token: data.token, expires_at: data.expires_at };
    persistSession();
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
  const data = await apiRequest("/v1/client/event/summary", {
    headers: withAuthHeaders(),
  });
  state.event = data.event;
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
    render();
  }
}

async function onGuestSave(event) {
  event.preventDefault();
  const form = new FormData(event.currentTarget);
  state.guestFormBusy = true;
  state.moduleError = "";
  render();
  try {
    const data = await apiRequest("/v1/client/modules/convidados/save", {
      method: "POST",
      headers: {
        ...withAuthHeaders(),
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        id: state.editingGuestId || 0,
        name: String(form.get("name") || ""),
        age_group: String(form.get("age_group") || ""),
        table_number: String(form.get("table_number") || ""),
      }),
    });
    state.moduleCache.convidados = data.module;
    state.editingGuestId = 0;
    await refreshEventSummary();
  } catch (error) {
    console.error(error);
    state.moduleError = error.message || "Não foi possível salvar o convidado.";
  } finally {
    state.guestFormBusy = false;
    render();
  }
}

async function onGuestDelete(guestId) {
  if (!guestId) {
    return;
  }
  state.moduleError = "";
  render();
  try {
    const data = await apiRequest("/v1/client/modules/convidados/delete", {
      method: "POST",
      headers: {
        ...withAuthHeaders(),
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ id: guestId }),
    });
    state.moduleCache.convidados = data.module;
    if (state.editingGuestId === guestId) {
      state.editingGuestId = 0;
    }
    await refreshEventSummary();
  } catch (error) {
    console.error(error);
    state.moduleError = error.message || "Não foi possível excluir o convidado.";
  } finally {
    render();
  }
}

async function onFileUpload(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const fieldId = Number(form.dataset.uploadField || 0);
  const payload = new FormData(form);
  state.fileUploadBusyFieldId = fieldId;
  state.moduleError = "";
  render();
  try {
    const data = await apiRequest("/v1/client/modules/arquivos/upload", {
      method: "POST",
      headers: withAuthHeaders(),
      body: payload,
    });
    state.moduleCache.arquivos = data.module;
    await refreshEventSummary();
  } catch (error) {
    console.error(error);
    state.moduleError = error.message || "Não foi possível enviar o arquivo.";
  } finally {
    state.fileUploadBusyFieldId = 0;
    render();
  }
}

async function onFileDelete(fileId) {
  if (!fileId) {
    return;
  }
  state.fileDeleteBusyId = fileId;
  state.moduleError = "";
  render();
  try {
    const data = await apiRequest("/v1/client/modules/arquivos/delete", {
      method: "POST",
      headers: {
        ...withAuthHeaders(),
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ id: fileId }),
    });
    state.moduleCache.arquivos = data.module;
    await refreshEventSummary();
  } catch (error) {
    console.error(error);
    state.moduleError = error.message || "Não foi possível excluir o arquivo.";
  } finally {
    state.fileDeleteBusyId = 0;
    render();
  }
}

async function onPortalFormSave(button) {
  const linkId = Number(button.dataset.formSave || 0);
  const moduleKey = String(button.dataset.moduleKey || "");
  const submit = String(button.dataset.submitMode || "") === "submit";
  const form = button.closest("[data-form-shell]");
  if (!linkId || !form || !moduleKey) {
    return;
  }

  const module = state.moduleCache[moduleKey];
  const currentForm = (module?.forms || []).find((item) => Number(item.id) === linkId);
  if (!currentForm) {
    return;
  }

  state.formBusyLinkId = linkId;
  state.moduleError = "";
  render();
  try {
    const values = collectPortalFormValues(form, currentForm.schema || []);
    const data = await apiRequest("/v1/client/modules/form-response/save", {
      method: "POST",
      headers: {
        ...withAuthHeaders(),
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        module: moduleKey,
        link_id: linkId,
        submit,
        values,
      }),
    });
    state.moduleCache[moduleKey] = data.module;
  } catch (error) {
    console.error(error);
    state.moduleError = error.message || "Não foi possível salvar o formulário.";
  } finally {
    state.formBusyLinkId = 0;
    render();
  }
}

async function onPortalFormFileUpload(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const fieldId = String(form.dataset.formFileUpload || "");
  const linkId = Number(form.dataset.formLinkId || 0);
  const moduleKey = String(form.dataset.moduleKey || "");
  if (!fieldId || !linkId || !moduleKey) {
    return;
  }

  const busyKey = `${moduleKey}:${linkId}:${fieldId}`;
  const payload = new FormData(form);
  payload.append("module", moduleKey);
  payload.append("link_id", String(linkId));

  state.formUploadBusyKey = busyKey;
  state.moduleError = "";
  render();
  try {
    const data = await apiRequest("/v1/client/modules/form-response/upload", {
      method: "POST",
      headers: withAuthHeaders(),
      body: payload,
    });
    state.moduleCache[moduleKey] = data.module;
  } catch (error) {
    console.error(error);
    state.moduleError = error.message || "Não foi possível enviar o arquivo do formulário.";
  } finally {
    state.formUploadBusyKey = "";
    render();
  }
}

async function onPortalFormFileDelete(button) {
  const fileId = Number(button.dataset.formFileDelete || 0);
  const linkId = Number(button.dataset.formLinkId || 0);
  const moduleKey = String(button.dataset.moduleKey || "");
  if (!fileId || !linkId || !moduleKey) {
    return;
  }

  state.formDeleteBusyId = fileId;
  state.moduleError = "";
  render();
  try {
    const data = await apiRequest("/v1/client/modules/form-response/delete-file", {
      method: "POST",
      headers: {
        ...withAuthHeaders(),
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        module: moduleKey,
        link_id: linkId,
        file_id: fileId,
      }),
    });
    state.moduleCache[moduleKey] = data.module;
  } catch (error) {
    console.error(error);
    state.moduleError = error.message || "Não foi possível excluir o arquivo do formulário.";
  } finally {
    state.formDeleteBusyId = 0;
    render();
  }
}

function collectPortalFormValues(form, schema) {
  const values = {};
  for (const field of schema) {
    if (!field || !field.id) {
      continue;
    }
    const type = String(field.type || "text").toLowerCase();
    if (["divider", "section", "note", "file"].includes(type)) {
      continue;
    }
    const input = form.querySelector(`[name="${cssEscape(String(field.id))}"]`);
    if (!input) {
      continue;
    }
    values[String(field.id)] = String(input.value || "").trim();
  }
  return values;
}

function getVisibleModules(event) {
  const cards = event.cards || {};
  const permissions = event.permissions || {};
  const summary = event.summary || {};
  return Object.values(MODULE_META).filter((meta) => cards[meta.key]).map((meta) => ({
    ...meta,
    description: moduleDescription(meta.key, permissions),
    summary: summary,
  }));
}

function moduleDescription(moduleKey, permissions) {
  if (moduleKey === "reuniao_final") {
    return permissions.reuniao_editavel ? "Acompanhamento completo da reunião final." : "Consulta liberada para a reunião final.";
  }
  if (moduleKey === "convidados") {
    return permissions.convidados_editavel ? "Lista liberada para edição do cliente." : "Lista disponível para consulta.";
  }
  if (moduleKey === "arquivos") {
    return permissions.arquivos_editavel ? "Envie arquivos e acompanhe os materiais do evento." : "Arquivos liberados para consulta.";
  }
  if (moduleKey === "dj") {
    return "Alinhamento de trilhas, protocolo e momentos do evento.";
  }
  return "Formulários adicionais liberados para este portal.";
}

function moduleSummaryText(module) {
  if (module.key === "convidados") {
    const total = Number(module.summary?.convidados?.total || 0);
    return total > 0 ? `${total} convidados` : "Lista pronta para receber nomes";
  }
  if (module.key === "arquivos") {
    const pending = Number(module.summary?.arquivos?.campos_pendentes || 0);
    return pending > 0 ? `${pending} item(ns) pendente(s)` : "Tudo organizado";
  }
  return "";
}

function clientSummaryRows(event) {
  const rows = [];
  if (event.date) {
    rows.push({ label: "Data", value: formatDate(event.date) });
  }
  if (event.time_start || event.time_end) {
    rows.push({
      label: "Horário",
      value: [event.time_start, event.time_end].filter(Boolean).join(event.time_end ? " às " : ""),
    });
  }
  if (event.location) {
    rows.push({ label: "Local", value: event.location });
  }
  return rows;
}

function findEditingGuest(items) {
  return (items || []).find((item) => Number(item.id) === Number(state.editingGuestId)) || null;
}

function filterDisplayFields(schema, values) {
  return (schema || [])
    .filter((field) => {
      const type = String(field.type || "text").toLowerCase();
      return ["text", "textarea", "yesno", "select"].includes(type);
    })
    .map((field) => ({
      label: String(field.label || "Campo"),
      value: fieldValue(field, values),
    }))
    .filter((field) => field.value !== "");
}

function fieldValue(field, values) {
  const fieldId = String(field.id || "");
  if (fieldId && Object.prototype.hasOwnProperty.call(values || {}, fieldId)) {
    return String(values[fieldId] || "").trim();
  }
  return String(field.default_value || "").trim();
}

function renderAlert(message, variant) {
  if (!message) {
    return null;
  }
  const div = document.createElement("div");
  div.className = `alert ${variant === "error" ? "alert-error" : "alert-success"}`;
  div.textContent = message;
  return div;
}

async function apiRequest(path, options = {}) {
  const fetchOptions = { ...options };
  fetchOptions.headers = { ...(options.headers || {}) };
  const response = await fetch(`${state.apiBase}${path}`, fetchOptions);
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.ok) {
    throw new Error(data.error || "Falha na comunicação com a API.");
  }
  return data;
}

function withAuthHeaders() {
  return {
    Authorization: `Bearer ${state.session?.token || ""}`,
  };
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
  state.screen = { name: "dashboard", key: "" };
  state.moduleCache = {};
  state.moduleError = "";
  state.editingGuestId = 0;
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

function formatFileSize(bytes) {
  const size = Number(bytes || 0);
  if (size <= 0) {
    return "Arquivo pronto";
  }
  if (size < 1024 * 1024) {
    return `${Math.round(size / 1024)} KB`;
  }
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
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
  if (platform === "android") return "Android App";
  if (platform === "ios") return "iPhone App";
  return "Web App";
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function cssEscape(value) {
  if (window.CSS && typeof window.CSS.escape === "function") {
    return window.CSS.escape(value);
  }
  return String(value).replace(/["\\]/g, "\\$&");
}
