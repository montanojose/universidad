// server.js
// API simple para la demo de TransplantChain (front + back).
// Sirve también el frontend estático, así con un solo "npm start" queda todo arriba.

const asyncHandler = (fn) => (req, res, next) => {
  Promise.resolve(fn(req, res, next)).catch((err) => {
    res.status(500).json({ error: err.message });
  });
};

require("dotenv").config();
const express = require("express");
const path = require("path");
const bcrypt = require("bcryptjs");
const jwt = require("jsonwebtoken");
const db = require("./data");
const hedera = require("./hedera");

const app = express();
const PORT = process.env.PORT || 3000;
const JWT_SECRET = process.env.JWT_SECRET || "transplantchain_demo_secret";
const JWT_EXPIRES_IN = "8h";

function attachUser(req, res, next) {
  const authHeader = req.headers.authorization || "";
  if (!authHeader.startsWith("Bearer ")) {
    return next();
  }

  const token = authHeader.slice(7).trim();
  try {
    req.user = jwt.verify(token, JWT_SECRET);
  } catch (err) {
    return res.status(401).json({ error: "Token inválido" });
  }
  next();
}

function authenticateToken(req, res, next) {
  if (!req.user) {
    return res.status(401).json({ error: "Autenticación requerida" });
  }
  next();
}

function authorizeRole(...allowedRoles) {
  return (req, res, next) => {
    if (!req.user || !allowedRoles.includes(req.user.role)) {
      return res.status(403).json({ error: "No autorizado" });
    }
    next();
  };
}

app.use(express.json());
app.use(express.static(path.join(__dirname, "..", "frontend")));
app.use("/api", attachUser);

app.post(
  "/api/auth/login",
  asyncHandler(async (req, res) => {
    const { email, password } = req.body;
    if (!email || !password) {
      return res.status(400).json({ error: "Email y contraseña son obligatorios" });
    }

    const user = await db.getUserByEmail(email);
    if (!user || !bcrypt.compareSync(password, user.passwordHash)) {
      return res.status(401).json({ error: "Email o contraseña incorrectos" });
    }

    const tokenPayload = {
      id: user.id,
      role: user.role,
      hospitalId: user.hospitalId,
      hospitalName: user.hospitalName,
    };

    const token = jwt.sign(tokenPayload, JWT_SECRET, { expiresIn: JWT_EXPIRES_IN });
    res.json({
      user: {
        id: user.id,
        nombre: user.nombre,
        email: user.email,
        role: user.role,
        hospitalId: user.hospitalId,
        hospitalName: user.hospitalName,
      },
      token,
    });
  })
);

app.get(
  "/api/auth/me",
  authenticateToken,
  asyncHandler(async (req, res) => {
    const user = await db.getUserById(req.user.id);
    if (!user) {
      return res.status(404).json({ error: "Usuario no encontrado" });
    }
    res.json({ user });
  })
);

app.get(
  "/api/hospitales",
  asyncHandler(async (req, res) => {
    const hospitales = await db.getHospitales();
    res.json(hospitales);
  })
);

app.get(
  "/api/donantes",
  asyncHandler(async (req, res) => {
    const hospitalId = req.user?.role === "hospital" ? req.user.hospitalId : null;
    const donantes = await db.getDonantes(hospitalId);
    res.json(donantes);
  })
);

app.post(
  "/api/donantes",
  authenticateToken,
  asyncHandler(async (req, res) => {
    const { hospitalOrigen, organo, grupoSanguineo, hospitalId: bodyHospitalId } = req.body;
    const userHospitalId = req.user.role === "hospital" ? req.user.hospitalId : bodyHospitalId;
    const origen = req.user.role === "hospital" ? req.user.hospitalName : hospitalOrigen;

    if (!origen || !organo || !grupoSanguineo) {
      return res.status(400).json({ error: "Faltan campos obligatorios" });
    }

    const nuevo = await db.registrarDonante({
      hospitalOrigen: origen,
      organo,
      grupoSanguineo,
      hospitalId: userHospitalId,
    });
    res.status(201).json(nuevo);
  })
);

app.get(
  "/api/receptores",
  asyncHandler(async (req, res) => {
    const hospitalId = req.user?.role === "hospital" ? req.user.hospitalId : null;
    const receptores = await db.getReceptores(hospitalId);
    res.json(receptores);
  })
);

app.post(
  "/api/receptores",
  authenticateToken,
  asyncHandler(async (req, res) => {
    const {
      nombre,
      organoNecesitado,
      grupoSanguineo,
      urgencia,
      diasEspera,
      distanciaKm,
      hospital,
      hospitalId: bodyHospitalId,
    } = req.body;

    if (!nombre || !organoNecesitado || !grupoSanguineo || urgencia == null || diasEspera == null || distanciaKm == null) {
      return res.status(400).json({ error: "Faltan campos obligatorios" });
    }

    const receptorHospitalId = req.user.role === "hospital" ? req.user.hospitalId : bodyHospitalId;
    const receptorHospitalName = req.user.role === "hospital" ? req.user.hospitalName : hospital;

    if (!receptorHospitalName) {
      return res.status(400).json({ error: "Hospital del paciente es obligatorio" });
    }

    const nuevo = await db.registrarPaciente({
      nombre,
      organoNecesitado,
      grupoSanguineo,
      urgencia: Number(urgencia),
      diasEspera: Number(diasEspera),
      distanciaKm: Number(distanciaKm),
      hospital: receptorHospitalName,
      hospitalId: receptorHospitalId,
    });
    res.status(201).json(nuevo);
  })
);

app.get(
  "/api/pacientes",
  asyncHandler(async (req, res) => {
    const hospitalId = req.user?.role === "hospital" ? req.user.hospitalId : null;
    const receptores = await db.getReceptores(hospitalId);
    res.json(receptores);
  })
);

app.post(
  "/api/pacientes",
  authenticateToken,
  asyncHandler(async (req, res) => {
    const {
      nombre,
      organoNecesitado,
      grupoSanguineo,
      urgencia,
      diasEspera,
      distanciaKm,
      hospital,
      hospitalId: bodyHospitalId,
    } = req.body;

    if (!nombre || !organoNecesitado || !grupoSanguineo || urgencia == null || diasEspera == null || distanciaKm == null) {
      return res.status(400).json({ error: "Faltan campos obligatorios" });
    }

    const receptorHospitalId = req.user.role === "hospital" ? req.user.hospitalId : bodyHospitalId;
    const receptorHospitalName = req.user.role === "hospital" ? req.user.hospitalName : hospital;

    if (!receptorHospitalName) {
      return res.status(400).json({ error: "Hospital del paciente es obligatorio" });
    }

    const nuevo = await db.registrarPaciente({
      nombre,
      organoNecesitado,
      grupoSanguineo,
      urgencia: Number(urgencia),
      diasEspera: Number(diasEspera),
      distanciaKm: Number(distanciaKm),
      hospital: receptorHospitalName,
      hospitalId: receptorHospitalId,
    });
    res.status(201).json(nuevo);
  })
);

app.get(
  "/api/matching/:donanteId",
  asyncHandler(async (req, res) => {
    const donante = await db.getDonanteById(req.params.donanteId);
    if (!donante) {
      return res.status(404).json({ error: "Donante no encontrado" });
    }

    if (req.user?.role === "hospital" && donante.hospitalId && donante.hospitalId !== req.user.hospitalId) {
      return res.status(403).json({ error: "No autorizado para acceder a este donante" });
    }

    const ranking = await db.calcularMatching(donante);
    res.json({ donante, ranking });
  })
);

app.get(
  "/api/asignaciones",
  asyncHandler(async (req, res) => {
    const hospitalId = req.user?.role === "hospital" ? req.user.hospitalId : null;
    const asignaciones = await db.getAsignaciones(hospitalId);
    res.json(asignaciones);
  })
);

app.post(
  "/api/asignaciones",
  authenticateToken,
  asyncHandler(async (req, res) => {
    const { donanteId, receptorId } = req.body;
    const donante = await db.getDonanteById(donanteId);
    if (!donante) {
      return res.status(404).json({ error: "Donante no encontrado" });
    }
    if (req.user.role === "hospital" && donante.hospitalId && donante.hospitalId !== req.user.hospitalId) {
      return res.status(403).json({ error: "No autorizado para asignar este donante" });
    }

    try {
      const asignacion = await db.confirmarAsignacion({ donanteId, receptorId });
      res.status(201).json(asignacion);
    } catch (err) {
      res.status(400).json({ error: err.message });
    }
  })
);

app.get(
  "/api/dashboard",
  asyncHandler(async (req, res) => {
    const hospitalId = req.user?.role === "hospital" ? req.user.hospitalId : null;
    const dashboardData = await db.getDashboard(hospitalId);
    res.json(dashboardData);
  })
);

app.get(
  "/api/hedera/status",
  asyncHandler(async (req, res) => {
    const configured = hedera.isConfigured();
    res.json({
      configured,
      network: process.env.HEDERA_NETWORK || "testnet",
      topicId: process.env.HEDERA_TOPIC_ID || null,
      accountId: process.env.HEDERA_ACCOUNT_ID ? `${process.env.HEDERA_ACCOUNT_ID.slice(0, 8)}...` : null,
      message: configured
        ? "Hedera está configurado."
        : "Hedera no está configurado. Define HEDERA_ACCOUNT_ID, HEDERA_PRIVATE_KEY y HEDERA_TOPIC_ID en backend/.env.",
    });
  })
);

async function start() {
  await db.initializeDefaults();
  app.listen(PORT, () => {
    console.log(`TransplantChain demo corriendo en http://localhost:${PORT}`);
  });
}

start().catch((err) => {
  console.error("Error iniciando el servidor:", err);
  process.exit(1);
});
