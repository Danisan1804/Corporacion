const API = "../api.php";
const MODULES = {
  1: "Liderazgo personal y comunitario",
  2: "Pensamiento emprendedor",
  3: "Diseño y validación de proyectos",
  4: "Marketing y transformación digital",
  5: "Gestión, sostenibilidad y cierre",
};

const $ = (selector) => document.querySelector(selector);
let participant = null;
let csrfToken = "";

async function api(route, options = {}) {
  const isFormData = options.body instanceof FormData;
  const method = options.method || "GET";
  const response = await fetch(`${API}?route=${route}`, {
    credentials: "same-origin",
    headers: {
      ...(isFormData ? {} : { "Content-Type": "application/json" }),
      ...(method !== "GET" && route !== "participant-login" && csrfToken
        ? { "X-CSRF-Token": csrfToken }
        : {}),
    },
    ...options,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.error || "No se pudo completar la solicitud.");
  return data;
}

function showPortal(data) {
  participant = data.participant;
  $("#login-screen").classList.add("hidden");
  $("#portal").classList.remove("hidden");
  $("#participant-name").textContent = participant.name;
  $("#current-module").textContent = `Módulo ${participant.stage}: ${participant.module_name}`;
  $("#participant-status").textContent = participant.status;
  $("#overall-attendance").textContent = `${Number(participant.attendance || 0)}%`;
  const currentEvidence = data.evidences.some((item) => Number(item.stage) === Number(participant.stage));
  $("#evidence-status").textContent = currentEvidence ? "Registrada" : "Pendiente";
  $("#attendance").value = (data.attendance.find((item) => Number(item.stage) === Number(participant.stage)) || {}).attendance || "";
  $("#evidence-list").innerHTML = data.evidences.length
    ? data.evidences.map((item) => `<article class="evidence-item"><strong>Módulo ${item.stage}: ${escapeHtml(item.title)}</strong><small>${escapeHtml(item.description)}${item.link ? ` · <a href="${escapeHtml(item.link)}" target="_blank" rel="noopener">Abrir enlace</a>` : ""}</small></article>`).join("")
    : '<p class="muted">Todavía no has registrado evidencias.</p>';
  renderMeetings(data.meetings || []);
  renderModules(data.modules || []);
}

function renderMeetings(meetings) {
  const list = $("#meetings-list");
  if (!meetings.length) {
    list.innerHTML = '<p class="muted">Todavía no hay encuentros programados para tu módulo.</p>';
    return;
  }
  list.innerHTML = meetings.map((meeting) => {
    const buttonText = meeting.window_status === "Abierta" ? "Registrar asistencia" : meeting.window_status;
    const disabled = meeting.window_status !== "Abierta";
    return `<article class="meeting-item">
      <h3>${escapeHtml(meeting.title)}</h3>
      <div class="meeting-meta">${escapeHtml(meeting.meeting_date)} · ${escapeHtml(meeting.start_time)}${meeting.end_time ? ` - ${escapeHtml(meeting.end_time)}` : ""} · ${escapeHtml(meeting.location || "Lugar por confirmar")}</div>
      <form data-meeting-form="${meeting.id}">
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" ${disabled ? "disabled" : "required"}>
        <button class="button ${disabled ? "secondary" : "primary"}" type="submit" ${disabled ? "disabled" : ""}>${escapeHtml(buttonText)}</button>
      </form>
    </article>`;
  }).join("");
}

function renderModules(modules) {
  const list = $("#module-list");
  list.innerHTML = modules.map((module) => `<article class="module-item">
    <div><h3>Módulo ${module.stage}: ${escapeHtml(module.name)}</h3><small>${module.progress}% de encuentros cerrados${module.attendance === null ? "" : ` · Asistencia: ${module.attendance}%`}</small></div>
    <span class="module-status ${module.status === "Completado" ? "completed" : module.status === "En curso" ? "current" : ""}">${escapeHtml(module.status)}</span>
  </article>`).join("");
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>'"]/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;" })[character]);
}

$("#login-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  $("#login-error").textContent = "";
  try {
    await api("participant-login", { method: "POST", body: JSON.stringify(Object.fromEntries(new FormData(event.currentTarget))) });
    csrfToken = (await api("csrf")).token;
    showPortal(await api("participant-me"));
  } catch (error) { $("#login-error").textContent = error.message; }
});

$("#evidence-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  $("#evidence-error").textContent = "";
  try {
    await api("participant/evidence", { method: "POST", body: JSON.stringify({ stage: participant.stage, ...Object.fromEntries(new FormData(event.currentTarget)) }) });
    event.currentTarget.reset();
    showPortal(await api("participant-me"));
  } catch (error) { $("#evidence-error").textContent = error.message; }
});

$("#meetings-list").addEventListener("submit", async (event) => {
  const form = event.target.closest("[data-meeting-form]");
  if (!form) return;
  event.preventDefault();
  $("#attendance-error").textContent = "";
  const photo = form.querySelector("input[type=\"file\"]");
  if (!photo.files.length) {
    $("#attendance-error").textContent = "Selecciona una foto antes de continuar.";
    return;
  }
  const body = new FormData();
  body.append("photo", photo.files[0]);
  try {
    await api(`participant/meetings/${form.dataset.meeting}/attendance`, { method: "POST", body });
    showPortal(await api("participant-me"));
  } catch (error) { $("#attendance-error").textContent = error.message; }
});

$("#logout-button").addEventListener("click", async () => {
  await api("participant-logout", { method: "POST" });
  window.location.reload();
});

api("participant-me").then(showPortal).catch(() => {});
