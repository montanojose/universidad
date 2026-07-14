// data.js
// Lógica de "IA" simplificada (compatibilidad y priorización).
// Conectado a MySQL para persistencia de datos y ahora con soporte de usuarios/hospitales.

const bcrypt = require("bcryptjs");
const crypto = require("crypto");
const mysql = require("mysql2/promise");
const hedera = require("./hedera");

const dbConfig = {
  host: process.env.DB_HOST || "localhost",
  port: process.env.DB_PORT ? Number(process.env.DB_PORT) : 3306,
  user: process.env.DB_USER || "root",
  password: process.env.DB_PASSWORD || "",
  database: process.env.DB_NAME || "transplantchain",
};

const pool = mysql.createPool(dbConfig);

const COMPATIBILIDAD = {
  "O-": ["O-", "O+", "A-", "A+", "B-", "B+", "AB-", "AB+"],
  "O+": ["O+", "A+", "B+", "AB+"],
  "A-": ["A-", "A+", "AB-", "AB+"],
  "A+": ["A+", "AB+"],
  "B-": ["B-", "B+", "AB-", "AB+"],
  "B+": ["B+", "AB+"],
  "AB-": ["AB-", "AB+"],
  "AB+": ["AB+"],
};

async function getHospitales() {
  const [rows] = await pool.query("SELECT * FROM hospitales ORDER BY nombre");
  return rows;
}

async function getDonantes(hospitalId = null) {
  if (hospitalId) {
    const [rows] = await pool.query("SELECT * FROM donantes WHERE hospitalId = ? ORDER BY id DESC", [hospitalId]);
    return rows;
  }
  const [rows] = await pool.query("SELECT * FROM donantes ORDER BY id DESC");
  return rows;
}

async function getDonanteById(id) {
  const [rows] = await pool.query("SELECT * FROM donantes WHERE id = ?", [id]);
  return rows[0];
}

async function getReceptores(hospitalId = null) {
  if (hospitalId) {
    const [rows] = await pool.query("SELECT * FROM receptores WHERE hospitalId = ? ORDER BY id", [hospitalId]);
    return rows;
  }
  const [rows] = await pool.query("SELECT * FROM receptores ORDER BY id");
  return rows;
}

async function getAsignaciones(hospitalId = null) {
  if (hospitalId) {
    const [rows] = await pool.query(
      `SELECT a.* FROM asignaciones a
       JOIN donantes d ON a.donanteId = d.id
       WHERE d.hospitalId = ?
       ORDER BY a.id DESC`,
      [hospitalId]
    );
    return rows;
  }
  const [rows] = await pool.query("SELECT * FROM asignaciones ORDER BY id DESC");
  return rows;
}

async function calcularMatching(donante) {
  const compatibles = COMPATIBILIDAD[donante.grupoSanguineo] || [];
  const placeholders = compatibles.map(() => "?").join(",");

  const query = `
    SELECT * FROM receptores
    WHERE organoNecesitado = ? AND grupoSanguineo IN (${placeholders})
  `;
  const params = [donante.organo, ...compatibles];

  const [receptores] = await pool.query(query, params);

  return receptores
    .map((r) => {
      const score = r.urgencia * 6 + r.diasEspera * 0.08 - r.distanciaKm * 0.3;
      return { ...r, score: Math.round(score * 10) / 10 };
    })
    .sort((a, b) => b.score - a.score)
    .map((r, index) => ({ ranking: index + 1, ...r }));
}

async function registrarDonante({ hospitalOrigen, organo, grupoSanguineo, hospitalId = null }) {
  const query = `
    INSERT INTO donantes (hospitalOrigen, hospitalId, organo, grupoSanguineo, horaExtraccion, estado)
    VALUES (?, ?, ?, ?, ?, ?)
  `;
  const params = [hospitalOrigen, hospitalId, organo, grupoSanguineo, new Date(), "Disponible"];
  const [result] = await pool.query(query, params);

  const [newDonante] = await pool.query("SELECT * FROM donantes WHERE id = ?", [result.insertId]);
  return newDonante[0];
}

async function registrarPaciente({
  nombre,
  organoNecesitado,
  grupoSanguineo,
  urgencia,
  diasEspera,
  distanciaKm,
  hospital,
  hospitalId = null,
}) {
  const query = `
    INSERT INTO receptores
      (nombre, organoNecesitado, grupoSanguineo, urgencia, diasEspera, distanciaKm, hospital, hospitalId)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `;
  const params = [
    nombre,
    organoNecesitado,
    grupoSanguineo,
    urgencia,
    diasEspera,
    distanciaKm,
    hospital,
    hospitalId,
  ];

  const [result] = await pool.query(query, params);
  const [newPaciente] = await pool.query("SELECT * FROM receptores WHERE id = ?", [result.insertId]);
  return newPaciente[0];
}

async function confirmarAsignacion({ donanteId, receptorId }) {
  const [donantes] = await pool.query("SELECT * FROM donantes WHERE id = ?", [donanteId]);
  const donante = donantes[0];

  const [receptores] = await pool.query("SELECT * FROM receptores WHERE id = ?", [receptorId]);
  const receptor = receptores[0];

  if (!donante || !receptor) {
    throw new Error("Donante o receptor no encontrado");
  }
  if (donante.estado === "Asignado") {
    throw new Error("Este órgano ya fue asignado");
  }

  await pool.query("UPDATE donantes SET estado = 'Asignado' WHERE id = ?", [donanteId]);

  const score = receptor.urgencia * 6 + receptor.diasEspera * 0.08 - receptor.distanciaKm * 0.3;

  let txHash = crypto.randomBytes(32).toString("hex");
  let estadoBlockchain = "Simulado (pendiente módulo Hedera)";

  if (hedera.isConfigured()) {
    const payload = {
      tipo: "asignacion",
      donanteId,
      receptorId,
      organo: donante.organo,
      hospitalOrigen: donante.hospitalOrigen,
      receptor: receptor.nombre,
      hospitalDestino: receptor.hospital,
      score: Math.round(score * 10) / 10,
      fecha: new Date().toISOString(),
    };

    try {
      txHash = await hedera.submitAssignmentMessage(payload);
      estadoBlockchain = "Registrado en Hedera";
    } catch (err) {
      estadoBlockchain = `Error Hedera: ${err.message}`;
    }
  }

  const asignacionData = {
    donanteId,
    receptorId,
    fecha: new Date(),
    organo: donante.organo,
    hospitalOrigen: donante.hospitalOrigen,
    receptor: receptor.nombre,
    hospitalDestino: receptor.hospital,
    score: Math.round(score * 10) / 10,
    txHash,
    estadoBlockchain,
  };

  const query = "INSERT INTO asignaciones SET ?";
  const [result] = await pool.query(query, asignacionData);
  const [newAsignacion] = await pool.query("SELECT * FROM asignaciones WHERE id = ?", [result.insertId]);
  return newAsignacion[0];
}

async function getDashboard(hospitalId = null) {
  if (hospitalId) {
    const [[{ totalDonantes }]] = await pool.query(
      "SELECT COUNT(*) as totalDonantes FROM donantes WHERE hospitalId = ?",
      [hospitalId]
    );
    const [[{ organosDisponibles }]] = await pool.query(
      "SELECT COUNT(*) as organosDisponibles FROM donantes WHERE estado = 'Disponible' AND hospitalId = ?",
      [hospitalId]
    );
    const [[{ pacientesEnEspera }]] = await pool.query(
      "SELECT COUNT(*) as pacientesEnEspera FROM receptores WHERE hospitalId = ?",
      [hospitalId]
    );
    const [[{ trasplantesConfirmados }]] = await pool.query(
      `SELECT COUNT(*) as trasplantesConfirmados
       FROM asignaciones a
       JOIN donantes d ON a.donanteId = d.id
       WHERE d.hospitalId = ?`,
      [hospitalId]
    );

    return {
      totalDonantes,
      organosDisponibles,
      pacientesEnEspera,
      trasplantesConfirmados,
    };
  }

  const [[{ totalDonantes }]] = await pool.query("SELECT COUNT(*) as totalDonantes FROM donantes");
  const [[{ organosDisponibles }]] = await pool.query("SELECT COUNT(*) as organosDisponibles FROM donantes WHERE estado = 'Disponible'");
  const [[{ pacientesEnEspera }]] = await pool.query("SELECT COUNT(*) as pacientesEnEspera FROM receptores");
  const [[{ trasplantesConfirmados }]] = await pool.query("SELECT COUNT(*) as trasplantesConfirmados FROM asignaciones");

  return {
    totalDonantes,
    organosDisponibles,
    pacientesEnEspera,
    trasplantesConfirmados,
  };
}

async function getUserByEmail(email) {
  const [rows] = await pool.query(
    `SELECT u.*, h.nombre AS hospitalName
     FROM usuarios u
     LEFT JOIN hospitales h ON u.hospitalId = h.id
     WHERE u.email = ?`,
    [email]
  );
  return rows[0];
}

async function getUserById(id) {
  const [rows] = await pool.query(
    `SELECT u.*, h.nombre AS hospitalName
     FROM usuarios u
     LEFT JOIN hospitales h ON u.hospitalId = h.id
     WHERE u.id = ?`,
    [id]
  );
  return rows[0];
}

async function createUser({ nombre, email, passwordHash, role = "hospital", hospitalId = null }) {
  const [existing] = await pool.query("SELECT id FROM usuarios WHERE email = ?", [email]);
  if (existing.length > 0) {
    return null;
  }

  const [result] = await pool.query(
    "INSERT INTO usuarios (nombre, email, passwordHash, role, hospitalId) VALUES (?, ?, ?, ?, ?)",
    [nombre, email, passwordHash, role, hospitalId]
  );

  return getUserById(result.insertId);
}

async function ensureHospital(nombre, direccion, telefono) {
  const [rows] = await pool.query("SELECT id FROM hospitales WHERE nombre = ?", [nombre]);
  if (rows.length > 0) {
    return rows[0].id;
  }
  const [result] = await pool.query(
    "INSERT INTO hospitales (nombre, direccion, telefono) VALUES (?, ?, ?)",
    [nombre, direccion, telefono]
  );
  return result.insertId;
}

async function ensureUser({ nombre, email, password, role = "hospital", hospitalId = null }) {
  const existing = await getUserByEmail(email);
  if (existing) {
    return existing;
  }

  const passwordHash = bcrypt.hashSync(password, 10);
  return createUser({ nombre, email, passwordHash, role, hospitalId });
}

async function initializeDefaults() {
  const italianoId = await ensureHospital("Hospital Italiano", "Calle Falsa 123", "011-1111");
  const clinicasId = await ensureHospital("Hospital de Clínicas", "Av. Siempre Viva 742", "011-2222");
  const australId = await ensureHospital("Hospital Austral", "Paseo Austral 456", "011-3333");

  await ensureUser({ nombre: "Administrador", email: "admin@transplantchain.local", password: "admin123", role: "admin" });
  await ensureUser({ nombre: "Hospital Italiano", email: "italiano@transplantchain.local", password: "hospital123", role: "hospital", hospitalId: italianoId });
  await ensureUser({ nombre: "Hospital de Clínicas", email: "clinicas@transplantchain.local", password: "hospital123", role: "hospital", hospitalId: clinicasId });
  await ensureUser({ nombre: "Hospital Austral", email: "austral@transplantchain.local", password: "hospital123", role: "hospital", hospitalId: australId });
}

module.exports = {
  getHospitales,
  getDonantes,
  getDonanteById,
  getReceptores,
  getAsignaciones,
  calcularMatching,
  registrarDonante,
  registrarPaciente,
  confirmarAsignacion,
  getDashboard,
  getUserByEmail,
  getUserById,
  ensureHospital,
  ensureUser,
  initializeDefaults,
};
