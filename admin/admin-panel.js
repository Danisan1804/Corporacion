const loginScreen = document.querySelector("#login-screen");
const loginForm = document.querySelector("#login-form");
const loginError = document.querySelector("#login-error");
const requestsList = document.querySelector("#requests-list");
const participantsTableBody = document.querySelector(
  "#participants-table-body",
);
let csrfToken = "";

async function requestApi(route, options = {}) {
  const method = options.method || "GET";
  const headers = { "Content-Type": "application/json", ...(options.headers || {}) };
  if (method !== "GET" && route !== "login" && csrfToken) {
    headers["X-CSRF-Token"] = csrfToken;
  }
  const response = await fetch(`api.php?route=${route}`, {
    credentials: "same-origin",
    headers,
    ...options,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok)
    throw new Error(data.error || "No se pudo completar la solicitud.");
  return data;
}

function escapeHtml(value) {
  return String(value ?? "").replace(
    /[&<>'"]/g,
    (character) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        "'": "&#39;",
        '"': "&quot;",
      })[character],
  );
}

async function showAdmin() {
  csrfToken = (await requestApi("csrf")).token;
  loginScreen.classList.add("hidden");
  loadAdminData();
}

function setText(selector, value) {
  const element = document.querySelector(selector);
  if (element) element.textContent = value;
}

function setWidth(selector, value) {
  const element = document.querySelector(selector);
  if (element) element.style.width = `${Math.max(0, Math.min(100, value))}%`;
}

function statusLabel(status) {
  return status === "Pendiente" ? "En revisión" : status;
}

function showCredentials(data, title = "Credenciales del participante") {
  const modal = document.querySelector("#credentials-modal");
  if (!modal) return;
  const titleElement = document.querySelector("#credentials-title");
  const emailInput = document.querySelector("#credentials-email");
  const passwordInput = document.querySelector("#credentials-password");
  if (titleElement) titleElement.textContent = title;
  if (emailInput) emailInput.value = data.email || "";
  if (passwordInput) passwordInput.value = data.temporary_password || "";
  modal.classList.remove("hidden");
}

function renderParticipants(participants) {
  if (!participantsTableBody) return;

  if (!participants.length) {
    participantsTableBody.innerHTML =
      '<tr><td colspan="6" class="muted">No hay participantes registrados.</td></tr>';
    return;
  }

  participantsTableBody.innerHTML = participants
    .map(
      (participant) => `<tr>
        <td><strong>${escapeHtml(participant.name)}</strong><br><small>${escapeHtml(participant.email)}</small></td>
        <td>${escapeHtml(participant.module_name || `Módulo ${participant.stage}`)}</td>
        <td>${Number(participant.attendance || 0)}%</td>
        <td>${Number(participant.evidence_count || 0)}</td>
        <td>${escapeHtml(statusLabel(participant.status))}</td>
        <td><button class="secondary-btn" data-reset-password="${participant.id}" type="button">Regenerar acceso</button>${Number(participant.stage) < 5 ? `<button class="primary-btn" data-advance-stage="${participant.id}" data-stage="${participant.stage}" type="button" style="margin-top:6px">Avanzar módulo</button>` : ""}</td>
      </tr>`,
    )
    .join("");
}

function renderDashboard(data) {
  const participants = data.participants || [];
  const total = Number(data.total || 0);
  const active = Number(data.active || 0);
  const graduated = Number(data.graduated || 0);
  const pending = Number(data.pending || 0);
  const lowAttendance = Number.isFinite(Number(data.low_attendance))
    ? Number(data.low_attendance)
    : participants.filter(
        (participant) =>
          Number(participant.attendance || 0) < 80 && Number(participant.evidence_count || 0) === 0 &&
          participant.status !== "Pendiente",
      ).length;
  const retention = total ? Math.round((active / total) * 100) : 0;
  const modules = data.module_summary || [];
  const programProgress = modules.length
    ? Math.round(modules.reduce((sum, module) => sum + Number(module.progress || 0), 0) / modules.length)
    : 0;
  const moduleTwo = modules.find((module) => module.stage === 2)
    ? Math.round(modules.reduce((sum, module) => sum + Number(module.progress || 0), 0) / modules.length)
    : 0;

  setText("#kpi-total", total);
  setText("#kpi-active", active);
  setText("#kpi-retention", `${retention}%`);
  setText("#kpi-graduated", data.meetings_done || 0);
  setText("#funnel-total", total);
  setText("#funnel-pending", pending);
  setText("#funnel-active", active);
  setText("#funnel-graduated", graduated);
  modules.forEach((module) => {
    setText(`#progress-stage-${module.stage}-label`, `${module.progress || 0}%`);
    setWidth(`#progress-stage-${module.stage}`, Number(module.progress || 0));
    const status = document.querySelector(`[data-module-status="${module.stage}"]`);
    if (status) {
      status.textContent = module.status.toLowerCase();
      status.className = `pill ${module.status === "Completado" ? "green" : "gold"}`;
    }
  });
  setText("#progress-total-label", `${programProgress}%`);
  setWidth("#progress-stage-2", total ? (moduleTwo / total) * 100 : 0);
  setWidth("#progress-total", programProgress);
  setText(
    "#alert-low-attendance",
    `${lowAttendance} participantes con asistencia < 80%`,
  );
  setText("#alert-pending", `${pending} postulaciones pendientes`);
  setText("#alert-graduated", `${graduated} participantes graduados`);
}

async function loadAdminData() {
  try {
    const [dashboard, report] = await Promise.all([
      requestApi("dashboard"),
      requestApi("report"),
    ]);
    renderDashboard({ ...dashboard, participants: report.participants || [] });
    renderParticipants(report.participants || []);
  } catch (error) {
    const message = `<tr><td colspan="6" class="login-error">${escapeHtml(error.message)}</td></tr>`;
    if (participantsTableBody) participantsTableBody.innerHTML = message;
    setText("#alert-pending", `Error al cargar datos: ${error.message}`);
  }
}

async function loadRequests() {
  requestsList.innerHTML = '<p class="muted">Cargando solicitudes...</p>';
  try {
    const data = await requestApi("applications");
    const pending = data.applications.filter(
      (participant) => participant.status === "Pendiente",
    );
    if (!pending.length) {
      requestsList.innerHTML =
        '<p class="muted">No hay solicitudes pendientes.</p>';
      return;
    }
    requestsList.innerHTML = `<div id="approval-credentials" class="card" style="display:none;margin-bottom:16px;border:1px solid #29a36a;background:#edfdf4;color:#123b27"></div>` + pending
      .map(
        (
          participant,
        ) => `<article class="request-card" id="request-${participant.id}">
      <h3>${escapeHtml(participant.name)}</h3>
      <p class="muted">Solicitud recibida: ${new Date(participant.created_at).toLocaleString("es-CO")}</p>
      <div class="request-data">
        <div><strong>Correo</strong>${escapeHtml(participant.email)}</div>
        <div><strong>Teléfono</strong>${escapeHtml(participant.phone)}</div>
        <div><strong>Documento</strong>${escapeHtml(participant.document)}</div>
        <div><strong>Estado</strong>Pendiente</div>
      </div>
      <div class="request-motivation"><strong>Interés y motivación</strong><br>${escapeHtml(participant.motivation)}</div>
      <div class="request-actions">
        <button class="primary-btn" data-decision="approve" data-id="${participant.id}">Aprobar ingreso</button>
        <button class="danger-btn" data-decision="reject" data-id="${participant.id}">Rechazar</button>
      </div>
    </article>`,
      )
      .join("");
  } catch (error) {
    requestsList.innerHTML = `<p class="login-error">${escapeHtml(error.message)}</p>`;
  }
}

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  loginError.textContent = "Validando acceso...";
  try {
    await requestApi("login", {
      method: "POST",
      body: JSON.stringify(Object.fromEntries(new FormData(loginForm))),
    });
    loginError.textContent = "";
    showAdmin();
  } catch (error) {
    loginError.textContent = error.message;
  }
});

document
  .querySelector('[data-view="requests"]')
  .addEventListener("click", loadRequests);
participantsTableBody.addEventListener("click", async (event) => {
  const button = event.target.closest("[data-reset-password]");
  if (!button) return;
  if (!window.confirm("¿Regenerar la contraseña temporal de este participante?")) return;
  try {
    const result = await requestApi(`participants/${button.dataset.resetPassword}/reset-password`, { method: "POST" });
    showCredentials(result, "Acceso regenerado");
  } catch (error) { window.alert(error.message); }
});
participantsTableBody.addEventListener("click", async (event) => {
  const button = event.target.closest("[data-advance-stage]");
  if (!button) return;
  if (!window.confirm("¿Avanzar a este participante al siguiente módulo?")) return;
  try {
    await requestApi(`participants/${button.dataset.advanceStage}`, {
      method: "POST",
      body: JSON.stringify({ stage: Number(button.dataset.stage) + 1, status: "Activo" }),
    });
    await loadAdminData();
    window.alert("Participante avanzado correctamente.");
  } catch (error) { window.alert(error.message); }
});
requestsList.addEventListener("click", async (event) => {
  const button = event.target.closest("[data-decision]");
  if (!button) return;
  const decision = button.dataset.decision;
  let reason = "";
  if (decision === "reject") {
    reason = window.prompt("Escribe el motivo del rechazo:", "") || "";
    if (reason.trim().length < 5) {
      window.alert("El motivo debe tener mínimo 5 caracteres.");
      return;
    }
  }
  try {
    const result = await requestApi(`applications/${button.dataset.id}/${decision}`, {
      method: "POST",
      body: JSON.stringify({ reason }),
    });
    if (decision === "approve" && result.temporary_password) {
      const credentials = document.querySelector("#approval-credentials");
      if (credentials) {
        credentials.style.display = "block";
        credentials.innerHTML = `<strong>Participante aprobado</strong><br><br>Correo: <b>${escapeHtml(result.email)}</b><br>Contraseña temporal: <b>${escapeHtml(result.temporary_password)}</b><br><small>Guárdala o entrégala de forma segura. Por seguridad no se volverá a mostrar automáticamente.</small>`;
      }
      showCredentials(result, "Participante aprobado");
    }
    await loadAdminData();
  } catch (error) {
    window.alert(error.message);
  }
});

document.querySelector("#credentials-close")?.addEventListener("click", () => {
  document.querySelector("#credentials-modal")?.classList.add("hidden");
});

document.querySelectorAll("[data-copy-credential]").forEach((button) => {
  button.addEventListener("click", async () => {
    const input = document.querySelector(`#${button.dataset.copyCredential}`);
    if (!input) return;
    try {
      await navigator.clipboard.writeText(input.value);
    } catch (_) {
      input.select();
      document.execCommand("copy");
    }
    const original = button.textContent;
    button.textContent = "Copiado";
    setTimeout(() => { button.textContent = original || "Copiar"; }, 1400);
  });
});

async function loadMeetings() {
  const list = document.querySelector("#meetings-list");
  if (!list) return;
  list.innerHTML = '<p class="muted">Cargando encuentros...</p>';
  try {
    const data = await requestApi("meetings");
    if (!data.meetings.length) {
      list.innerHTML = '<p class="muted">Todavía no hay encuentros programados.</p>';
      return;
    }
    list.innerHTML = data.meetings.map((meeting) => `<article class="request-card">
      <h3>${escapeHtml(meeting.title)} · Módulo ${meeting.stage}</h3>
      <p class="muted">${escapeHtml(meeting.meeting_date)} · ${escapeHtml(meeting.start_time)}${meeting.end_time ? ` - ${escapeHtml(meeting.end_time)}` : ""} · ${escapeHtml(meeting.location || "Lugar por confirmar")}</p>
      <p>${escapeHtml(meeting.notes || "")}</p>
      <div class="request-data"><strong>Asistencia marcada: ${meeting.marked_count}/${meeting.participants.length}</strong></div>
      <div style="display:grid;gap:8px;margin-top:12px">${meeting.participants.map((p) => `<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" data-meeting="${meeting.id}" data-participant="${p.id}" ${Number(p.present) ? "checked" : ""}> ${escapeHtml(p.name)} <small>${escapeHtml(p.email)}</small>${p.photo_url ? ` <a href="${escapeHtml(p.photo_url)}" target="_blank" rel="noopener">Ver foto</a>` : ""}</label>`).join("")}</div>
    </article>`).join("");
  } catch (error) { list.innerHTML = `<p class="login-error">${escapeHtml(error.message)}</p>`; }
}

const meetingForm = document.querySelector("#meeting-form");
if (meetingForm) meetingForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  try {
    await requestApi("meetings", { method: "POST", body: JSON.stringify(Object.fromEntries(new FormData(meetingForm))) });
    meetingForm.reset();
    await loadMeetings();
    window.alert("Encuentro programado correctamente.");
  } catch (error) { window.alert(error.message); }
});
document.addEventListener("change", async (event) => {
  const check = event.target.closest("input[data-meeting][data-participant]");
  if (!check) return;
  try {
    await requestApi(`meetings/${check.dataset.meeting}/attendance`, { method: "POST", body: JSON.stringify({ participant_id: check.dataset.participant, present: check.checked }) });
    await loadAdminData();
  } catch (error) { check.checked = !check.checked; window.alert(error.message); }
});

requestApi("me")
  .then((data) => {
    if (data.authenticated) showAdmin();
  })
  .catch(() => {});

document.querySelectorAll(".nav-button").forEach((button) => {
  button.addEventListener("click", () => {
    if (button.dataset.view === "requests") loadRequests();
    if (button.dataset.view === "formation") loadMeetings();
    if (
      button.dataset.view === "dashboard" ||
      button.dataset.view === "participants"
    ) {
      loadAdminData();
    }
  });
});
