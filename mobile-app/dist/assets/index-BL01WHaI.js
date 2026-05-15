(function(){const t=document.createElement("link").relList;if(t&&t.supports&&t.supports("modulepreload"))return;for(const a of document.querySelectorAll('link[rel="modulepreload"]'))n(a);new MutationObserver(a=>{for(const i of a)if(i.type==="childList")for(const l of i.addedNodes)l.tagName==="LINK"&&l.rel==="modulepreload"&&n(l)}).observe(document,{childList:!0,subtree:!0});function s(a){const i={};return a.integrity&&(i.integrity=a.integrity),a.referrerPolicy&&(i.referrerPolicy=a.referrerPolicy),a.crossOrigin==="use-credentials"?i.credentials="include":a.crossOrigin==="anonymous"?i.credentials="omit":i.credentials="same-origin",i}function n(a){if(a.ep)return;a.ep=!0;const i=s(a);fetch(a.href,i)}})();const $="https://smile-client-app-api-production.up.railway.app/api",v="smile-client-app-session",o={apiBase:(window.localStorage.getItem("smile-client-api-base")||$).replace(/\/$/,""),locations:[],loadingLocations:!0,authenticating:!1,bootingSession:!0,session:D(),event:null,error:"",success:""},h=document.querySelector("#app");_().catch(e=>{console.error(e),o.error="Não foi possível iniciar o app.",o.bootingSession=!1,r()});async function _(){r(),await Promise.all([w(),A()]),r()}async function w(){o.loadingLocations=!0,r();try{const e=await fetch(`${o.apiBase}/v1/client/locations`),t=await e.json();if(!e.ok||!t.ok)throw new Error(t.error||"Falha ao carregar os locais.");o.locations=Array.isArray(t.items)?t.items:[]}catch(e){console.error(e),o.error="Não foi possível carregar os locais do evento."}finally{o.loadingLocations=!1,r()}}async function A(){var e;if(o.bootingSession=!0,r(),!((e=o.session)!=null&&e.token)){o.bootingSession=!1;return}try{const t=await p("/v1/auth/me",{headers:m()});o.event=t.event,o.success=""}catch(t){console.error(t),f()}finally{o.bootingSession=!1,r()}}function r(){var t;h.innerHTML="";const e=document.createElement("div");e.className="shell",(t=o.session)!=null&&t.token&&o.event?e.append(E()):e.append(L()),h.append(e)}function L(){const e=document.createElement("section");e.innerHTML=`
    <div class="hero">
      <div class="eyebrow">Smile Eventos</div>
      <h1>Portal do Cliente</h1>
      <p>Acesse o seu evento com CPF, data e local. Esta base já está pronta para virar app iOS e Android.</p>
    </div>
  `;const t=document.createElement("div");t.className="surface";const s=q();s&&t.append(s);const n=document.createElement("h2");n.className="section-title",n.textContent="Entrar no evento",t.append(n);const a=document.createElement("form");a.className="stack",a.innerHTML=`
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
      <select id="event_location_id" name="event_location_id" required ${o.loadingLocations?"disabled":""}>
        <option value="">${o.loadingLocations?"Carregando locais...":"Selecione o local"}</option>
        ${o.locations.map(u=>`<option value="${u.id}">${c(u.name)}</option>`).join("")}
      </select>
    </div>
    <button class="button" type="submit" ${o.authenticating?"disabled":""}>
      ${o.authenticating?"Validando acesso...":"Entrar no portal"}
    </button>
  `,a.addEventListener("submit",C),t.append(a);const i=document.createElement("div");i.className="card",i.innerHTML=`
    <div class="header-row">
      <div>
        <div class="eyebrow">API</div>
        <h2 class="section-title" style="margin-top:8px;">Conexão do aplicativo</h2>
      </div>
    </div>
    <div class="field" style="margin-top:10px;">
      <label for="api_base">URL da API</label>
      <input id="api_base" name="api_base" value="${O(o.apiBase)}" />
    </div>
  `,i.querySelector("#api_base").addEventListener("change",P);const l=document.createDocumentFragment();return l.append(e,t,i),l}function E(){var i,l,u,g;const e=o.event.event||o.event,t=e.cards||{},s=e.permissions||{},n=e.summary||{},a=document.createElement("div");return a.className="stack",a.innerHTML=`
    <section class="hero">
      <div class="header-row">
        <div>
          <div class="eyebrow">Evento do Cliente</div>
          <div class="event-title">${c(e.name||"Seu Evento")}</div>
          <p>${c(e.client_name||"Cliente")} • ${b(e.date)} • ${c(e.location||"Local não informado")}</p>
        </div>
        <div class="badge">Sessão ativa</div>
      </div>
    </section>

    <section class="surface">
      <h2 class="section-title">Resumo do evento</h2>
      <div class="meta-grid">
        <div class="meta-item">
          <span>Cliente</span>
          <strong>${c(e.client_name||"-")}</strong>
        </div>
        <div class="meta-item">
          <span>Data</span>
          <strong>${b(e.date)}</strong>
        </div>
        <div class="meta-item">
          <span>Início</span>
          <strong>${c(e.time_start||"-")}</strong>
        </div>
        <div class="meta-item">
          <span>Local</span>
          <strong>${c(e.location||"-")}</strong>
        </div>
      </div>
    </section>

    <section class="surface">
      <h2 class="section-title">Indicadores rápidos</h2>
      <div class="stats-grid">
        <div class="stat-item">
          <span>Convidados</span>
          <strong>${((i=n.convidados)==null?void 0:i.total)??0}</strong>
        </div>
        <div class="stat-item">
          <span>Check-in</span>
          <strong>${((l=n.convidados)==null?void 0:l.checkin)??0}</strong>
        </div>
        <div class="stat-item">
          <span>Arquivos</span>
          <strong>${((u=n.arquivos)==null?void 0:u.arquivos_total)??0}</strong>
        </div>
        <div class="stat-item">
          <span>Pendências</span>
          <strong>${((g=n.arquivos)==null?void 0:g.campos_pendentes)??0}</strong>
        </div>
      </div>
    </section>

    <section class="surface">
      <h2 class="section-title">Áreas do portal</h2>
      <div class="cards-grid">
        ${d("Resumo do Evento",!0,"Visão geral do evento e dados principais.")}
        ${d("Reunião Final",t.reuniao_final,s.reuniao_editavel?"Liberada para edição do cliente.":"Disponível para consulta quando publicada.")}
        ${d("Convidados",t.convidados,s.convidados_editavel?"Cliente pode editar a lista.":"Lista publicada somente para visualização.")}
        ${d("Arquivos",t.arquivos,s.arquivos_editavel?"Cliente pode enviar e acompanhar arquivos.":"Arquivos liberados apenas para consulta.")}
        ${d("DJ",t.dj,"Área de protocolo e informações do DJ.")}
        ${d("Formulários",t.formulario,"Formulários adicionais do evento.")}
      </div>
    </section>

    <section class="card footer-actions">
      <button class="button-secondary" type="button" id="refresh-event">Atualizar dados</button>
      <button class="button" type="button" id="logout-event">Sair</button>
    </section>
  `,a.querySelector("#refresh-event").addEventListener("click",S),a.querySelector("#logout-event").addEventListener("click",N),a}function d(e,t,s){return`
    <div class="portal-card ${t?"":"disabled"}">
      <span>${t?"Disponível":"Oculto no momento"}</span>
      <strong>${c(e)}</strong>
      <div class="muted">${c(s)}</div>
    </div>
  `}function q(){return o.error?y(o.error,"alert-error"):o.success?y(o.success,"alert-success"):null}function y(e,t){const s=document.createElement("div");return s.className=`alert ${t}`,s.textContent=e,s}async function C(e){e.preventDefault(),o.error="",o.success="",o.authenticating=!0,r();const t=new FormData(e.currentTarget),s={cpf:String(t.get("cpf")||""),event_date:String(t.get("event_date")||""),event_location_id:Number(t.get("event_location_id")||0),platform:F(),app_version:"0.1.0",device_name:navigator.userAgent};try{const n=await p("/v1/auth/login",{method:"POST",body:JSON.stringify(s),headers:{"Content-Type":"application/json"}});o.session={token:n.token,expires_at:n.expires_at},I(),o.success="Acesso autorizado.",await S()}catch(n){console.error(n),o.error=n.message||"Falha no login."}finally{o.authenticating=!1,r()}}async function S(){var e;if((e=o.session)!=null&&e.token){o.error="",r();try{const t=await p("/v1/client/event/summary",{headers:m()});o.event=t.event,o.success="Dados do evento atualizados."}catch(t){console.error(t),o.error=t.message||"Não foi possível carregar o evento.",/Sessão inválida|expirada/i.test(o.error)&&f()}finally{r()}}}async function N(){var e;try{(e=o.session)!=null&&e.token&&await p("/v1/auth/logout",{method:"POST",headers:m()})}catch(t){console.error(t)}finally{f(),o.success="Sessão encerrada.",r()}}function P(e){const t=String(e.target.value||"").trim().replace(/\/$/,"");t&&(o.apiBase=t,window.localStorage.setItem("smile-client-api-base",o.apiBase),o.success="API atualizada.",o.error="",w())}function m(){var e;return{Authorization:`Bearer ${((e=o.session)==null?void 0:e.token)||""}`}}async function p(e,t={}){const s=await fetch(`${o.apiBase}${e}`,t),n=await s.json().catch(()=>({}));if(!s.ok||!n.ok)throw new Error(n.error||"Falha na comunicação com a API.");return n}function D(){try{const e=window.localStorage.getItem(v);return e?JSON.parse(e):null}catch(e){return console.error(e),null}}function I(){window.localStorage.setItem(v,JSON.stringify(o.session))}function f(){o.session=null,o.event=null,window.localStorage.removeItem(v)}function b(e){if(!e)return"-";const t=new Date(`${e}T00:00:00`);return Number.isNaN(t.getTime())?e:new Intl.DateTimeFormat("pt-BR",{day:"2-digit",month:"2-digit",year:"numeric"}).format(t)}function F(){const e=navigator.userAgent.toLowerCase();return e.includes("iphone")||e.includes("ipad")?"ios":e.includes("android")?"android":"web"}function c(e){return String(e).replaceAll("&","&amp;").replaceAll("<","&lt;").replaceAll(">","&gt;").replaceAll('"',"&quot;").replaceAll("'","&#39;")}function O(e){return c(e)}
