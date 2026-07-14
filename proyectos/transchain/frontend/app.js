// app.js
// Lógica de la demo: navegación entre vistas + llamadas a la API del backend.

const API = "/api";
let currentUser = null;

function getAuthHeaders() {
  return currentUser ? { Authorization: `Bearer ${currentUser.token}` } : {};
}

// ---------------------------------------------------------------------
// Navegación entre tabs/vistas
// ---------------------------------------------------------------------
document.querySelectorAll(".tab-btn").forEach((btn) => {
  btn.addEventListener("click", () => {
    document.querySelectorAll(".tab-btn").forEach((b) => b.classList.remove("active"));
    document.querySelectorAll(".view").forEach((v) => v.classList.remove("active"));
    btn.classList.add("active");
    document.getElementById(`view-${btn.dataset.view}`).classList.add("active");

    if (btn.dataset.view === "dashboard") cargarDashboard();
    if (btn.dataset.view === "registro") cargarDonantes();
    if (btn.dataset.view === "pacientes") {
      cargarPacientes();
      cargarSelectOrganos();
    }
  });
});

// ---------------------------------------------------------------------
// Autenticación
// ---------------------------------------------------------------------
async function loadSession() {
  const token = localStorage.getItem("transchainToken");
  if (!token) {
    updateAuthUI();
    return;
  }

  try {
    const res = await fetch(`${API}/auth/me`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    if (!res.ok) throw new Error("Sesión inválida");
    const data = await res.json();
    currentUser = { token, ...data.user };
  } catch (err) {
    localStorage.removeItem("transchainToken");
    currentUser = null;
  }
  updateAuthUI();
}

function updateAuthUI() {
  const loginForm = document.getElementById("form-login");
  const loginMsg = document.getElementById("login-msg");
  const userInfo = document.getElementById("user-info");
  const userName = document.getElementById("user-name");
  const userRole = document.getElementById("user-role");
  const userHospital = document.getElementById("user-hospital");
  const hospitalInput = document.getElementById("input-hospital");
  const pacienteHospitalInput = document.getElementById("input-paciente-hospital");

  if (currentUser) {
    loginForm.classList.add("hidden");
    userInfo.classList.remove("hidden");
    loginMsg.textContent = "Sesión iniciada correctamente.";
    userName.textContent = currentUser.nombre;
    userRole.textContent = currentUser.role;
    userHospital.textContent = currentUser.hospitalName || "-";

    if (currentUser.role === "hospital") {
      hospitalInput.value = currentUser.hospitalName || "";
      hospitalInput.disabled = true;
      pacienteHospitalInput.value = currentUser.hospitalName || "";
      pacienteHospitalInput.disabled = true;
    } else {
      hospitalInput.disabled = false;
      pacienteHospitalInput.disabled = false;
    }
  } else {
    loginForm.classList.remove("hidden");
    userInfo.classList.add("hidden");
    loginMsg.textContent = "Iniciá sesión para registrar donantes y pacientes desde tu hospital.";
    hospitalInput.disabled = false;
    pacienteHospitalInput.disabled = false;
  }
}

document.getElementById("form-login").addEventListener("submit", async (e) => {
  e.preventDefault();
  const body = {
    email: document.getElementById("input-email").value,
    password: document.getElementById("input-password").value,
  };

  const res = await fetch(`${API}/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });

  const msg = document.getElementById("login-msg");
  if (res.ok) {
    const data = await res.json();
    currentUser = { token: data.token, ...data.user };
    localStorage.setItem("transchainToken", data.token);
    updateAuthUI();
    cargarDashboard();
    cargarDonantes();
    cargarPacientes();
    cargarSelectOrganos();
    msg.textContent = "Bienvenido, sesión iniciada.";
  } else {
    const error = await res.json();
    msg.textContent = error.error || "No se pudo iniciar sesión.";
  }
});

document.getElementById("btn-logout").addEventListener("click", () => {
  localStorage.removeItem("transchainToken");
  currentUser = null;
  updateAuthUI();
});

// ---------------------------------------------------------------------
// DASHBOARD
// ---------------------------------------------------------------------
async function cargarDashboard() {
  const headers = getAuthHeaders();
  const stats = await fetch(`${API}/dashboard`, { headers }).then((r) => r.json());
  document.getElementById("stat-donantes").textContent = stats.totalDonantes;
  document.getElementById("stat-disponibles").textContent = stats.organosDisponibles;
  document.getElementById("stat-pacientes").textContent = stats.pacientesEnEspera;
  document.getElementById("stat-confirmados").textContent = stats.trasplantesConfirmados;

  const asignaciones = await fetch(`${API}/asignaciones`, { headers }).then((r) => r.json());
  const tbody = document.getElementById("tabla-asignaciones");

  if (asignaciones.length === 0) {
    tbody.innerHTML = `<tr><td colspan="6" class="empty-row">Todavía no hay asignaciones confirmadas.</td></tr>`;
    return;
  }

  tbody.innerHTML = asignaciones
    .slice()
    .map(
      (a) => `
      <tr>
        <td>${new Date(a.fecha).toLocaleString()}</td>
        <td>${a.organo}</td>
        <td>${a.receptor}</td>
        <td>${a.hospitalDestino}</td>
        <td class="hash-cell">${a.txHash.slice(0, 14)}...</td>
        <td>${a.estadoBlockchain}</td>
      </tr>`
    )
    .join("");
}

// ---------------------------------------------------------------------
// REGISTRO DE DONANTE / ÓRGANO
// ---------------------------------------------------------------------
document.getElementById("form-donante").addEventListener("submit", async (e) => {
  e.preventDefault();
  const msg = document.getElementById("registro-msg");
  if (!currentUser) {
    msg.textContent = "Necesitás iniciar sesión como hospital o admin para registrar donantes.";
    return;
  }

  const body = {
    organo: document.getElementById("input-organo").value,
    grupoSanguineo: document.getElementById("input-grupo").value,
  };

  if (currentUser.role === "hospital") {
    body.hospitalOrigen = currentUser.hospitalName;
  } else {
    body.hospitalOrigen = document.getElementById("input-hospital").value;
  }

  const res = await fetch(`${API}/donantes`, {
    method: "POST",
    headers: { "Content-Type": "application/json", ...getAuthHeaders() },
    body: JSON.stringify(body),
  });

  if (res.ok) {
    msg.textContent = "Donante/órgano registrado correctamente.";
    document.getElementById("form-donante").reset();
    cargarDonantes();
    cargarSelectOrganos();
  } else {
    const error = await res.json();
    msg.textContent = error.error || "Hubo un error al registrar.";
  }
});

async function cargarDonantes() {
  const headers = getAuthHeaders();
  const donantes = await fetch(`${API}/donantes`, { headers }).then((r) => r.json());
  const tbody = document.getElementById("tabla-donantes");
  tbody.innerHTML = donantes
    .map(
      (d) => `
      <tr>
        <td>${d.id}</td>
        <td>${d.hospitalOrigen}</td>
        <td>${d.organo}</td>
        <td>${d.grupoSanguineo}</td>
        <td><span class="estado-badge ${d.estado === "Disponible" ? "estado-disponible" : "estado-asignado"}">${d.estado}</span></td>
      </tr>`
    )
    .join("");
}

// ---------------------------------------------------------------------
// PACIENTES
// ---------------------------------------------------------------------
document.getElementById("form-paciente").addEventListener("submit", async (e) => {
  e.preventDefault();
  const msg = document.getElementById("paciente-msg");
  if (!currentUser) {
    msg.textContent = "Necesitás iniciar sesión como hospital o admin para agregar pacientes.";
    return;
  }

  const body = {
    nombre: document.getElementById("input-paciente-nombre").value,
    organoNecesitado: document.getElementById("input-paciente-organo").value,
    grupoSanguineo: document.getElementById("input-paciente-grupo").value,
    urgencia: Number(document.getElementById("input-paciente-urgencia").value),
    diasEspera: Number(document.getElementById("input-paciente-dias").value),
    distanciaKm: Number(document.getElementById("input-paciente-distancia").value),
  };

  if (currentUser.role === "hospital") {
    body.hospital = currentUser.hospitalName;
  } else {
    body.hospital = document.getElementById("input-paciente-hospital").value;
  }

  const res = await fetch(`${API}/receptores`, {
    method: "POST",
    headers: { "Content-Type": "application/json", ...getAuthHeaders() },
    body: JSON.stringify(body),
  });

  if (res.ok) {
    msg.textContent = "Paciente agregado correctamente.";
    document.getElementById("form-paciente").reset();
    cargarPacientes();
    cargarSelectOrganos();
  } else {
    const error = await res.json();
    msg.textContent = error.error || "No se pudo agregar el paciente.";
  }
});

async function cargarPacientes() {
  const headers = getAuthHeaders();
  const receptores = await fetch(`${API}/receptores`, { headers }).then((r) => r.json());
  const tbody = document.getElementById("tabla-pacientes");
  tbody.innerHTML = receptores
    .map(
      (p) => `
      <tr>
        <td>${p.id}</td>
        <td>${p.nombre}</td>
        <td>${p.hospital}</td>
        <td>${p.organoNecesitado}</td>
        <td>${p.grupoSanguineo}</td>
        <td>${p.urgencia}</td>
        <td>${p.diasEspera}</td>
        <td>${p.distanciaKm} km</td>
      </tr>`
    )
    .join("");
}

// ---------------------------------------------------------------------
// LISTA DE PACIENTES / MATCHING
// ---------------------------------------------------------------------
async function cargarSelectOrganos() {
  const headers = getAuthHeaders();
  const donantes = await fetch(`${API}/donantes`, { headers }).then((r) => r.json());
  const disponibles = donantes.filter((d) => d.estado === "Disponible");
  const select = document.getElementById("select-organo-disponible");

  if (disponibles.length === 0) {
    select.innerHTML = `<option value="">No hay órganos disponibles</option>`;
    document.getElementById("tabla-matching").innerHTML =
      `<tr><td colspan="9" class="empty-row">No hay órganos disponibles en este momento.</td></tr>`;
    return;
  }

  select.innerHTML = disponibles
    .map((d) => `<option value="${d.id}">#${d.id} · ${d.organo} (${d.grupoSanguineo}) · ${d.hospitalOrigen}</option>`)
    .join("");

  select.onchange = () => cargarMatching(select.value);
  cargarMatching(select.value);
}

async function cargarMatching(donanteId) {
  if (!donanteId) return;
  const headers = getAuthHeaders();
  const response = await fetch(`${API}/matching/${donanteId}`, { headers });
  if (!response.ok) {
    const err = await response.json();
    document.getElementById("tabla-matching").innerHTML = `<tr><td colspan="9" class="empty-row">${err.error || "Error al calcular matching."}</td></tr>`;
    return;
  }

  const data = await response.json();
  const tbody = document.getElementById("tabla-matching");

  if (!data.ranking || data.ranking.length === 0) {
    tbody.innerHTML = `<tr><td colspan="9" class="empty-row">No hay receptores compatibles para este órgano.</td></tr>`;
    return;
  }

  tbody.innerHTML = data.ranking
    .map(
      (r, i) => `
      <tr class="${i === 0 ? "row-best" : ""}">
        <td>${r.ranking}</td>
        <td>${r.nombre}</td>
        <td>${r.hospital}</td>
        <td>${r.grupoSanguineo}</td>
        <td>${r.urgencia}/10</td>
        <td>${r.diasEspera}</td>
        <td>${r.distanciaKm} km</td>
        <td><strong>${r.score}</strong></td>
        <td>
          <button class="secondary" onclick="confirmarAsignacion(${data.donante.id}, ${r.id})">
            Confirmar y enviar a Blockchain
          </button>
        </td>
      </tr>`
    )
    .join("");
}

// ---------------------------------------------------------------------
// CONFIRMAR ASIGNACIÓN (gancho hacia el módulo de Blockchain)
// ---------------------------------------------------------------------
async function confirmarAsignacion(donanteId, receptorId) {
  if (!currentUser) {
    alert("Necesitás iniciar sesión para confirmar asignaciones.");
    return;
  }

  const res = await fetch(`${API}/asignaciones`, {
    method: "POST",
    headers: { "Content-Type": "application/json", ...getAuthHeaders() },
    body: JSON.stringify({ donanteId, receptorId }),
  });

  if (!res.ok) {
    const err = await res.json();
    alert(`No se pudo confirmar: ${err.error}`);
    return;
  }

  const asignacion = await res.json();
  mostrarModal(asignacion);
  cargarSelectOrganos();
  cargarDashboard();
}

function mostrarModal(asignacion) {
  document.getElementById("modal-texto").textContent =
    `${asignacion.organo} asignado a ${asignacion.receptor} (${asignacion.hospitalDestino}). La lista de pacientes ya quedó preparada para anclarse en la red Blockchain.`;
  document.querySelector(".modal-hash").textContent = `tx hash: ${asignacion.txHash}`;
  document.getElementById("modal-confirmacion").classList.remove("hidden");
}

document.getElementById("modal-cerrar").addEventListener("click", () => {
  document.getElementById("modal-confirmacion").classList.add("hidden");
});

// ---------------------------------------------------------------------
// Carga inicial
// ---------------------------------------------------------------------
async function init() {
  await loadSession();
  await carregarInit();
}

init();

async function carregarInit() {
  await cargarDashboard();
  await cargarDonantes();
  await cargarPacientes();
  await cargarSelectOrganos();
}
