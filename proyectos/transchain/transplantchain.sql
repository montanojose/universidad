-- schema.sql
-- Crea la base de datos "transplantchain" y la deja poblada con los mismos
-- datos de ejemplo que antes vivían hardcodeados en memoria (backend/data.js),
-- para que la demo arranque con información visible.
--
-- Cómo importarlo (XAMPP / MySQL local, usuario root sin contraseña):
--   mysql -u root < schema.sql
-- o desde phpMyAdmin: Importar > seleccionar este archivo.

CREATE DATABASE IF NOT EXISTS transplantchain
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE transplantchain;

-- ----------------------------------------------------------------------
-- Donantes / órganos disponibles
-- ----------------------------------------------------------------------
DROP TABLE IF EXISTS asignaciones;
DROP TABLE IF EXISTS donantes;
DROP TABLE IF EXISTS receptores;

CREATE TABLE donantes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hospitalOrigen VARCHAR(150) NOT NULL,
  organo VARCHAR(50) NOT NULL,
  grupoSanguineo VARCHAR(5) NOT NULL,
  horaExtraccion DATETIME NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Disponible'
);

-- ----------------------------------------------------------------------
-- Receptores / pacientes en lista de espera
-- ----------------------------------------------------------------------
CREATE TABLE receptores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  organoNecesitado VARCHAR(50) NOT NULL,
  grupoSanguineo VARCHAR(5) NOT NULL,
  urgencia INT NOT NULL,
  diasEspera INT NOT NULL,
  distanciaKm INT NOT NULL,
  hospital VARCHAR(150) NOT NULL
);

-- ----------------------------------------------------------------------
-- Asignaciones confirmadas (simula el registro inmutable de blockchain)
-- ----------------------------------------------------------------------
CREATE TABLE asignaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  donanteId INT NOT NULL,
  receptorId INT NOT NULL,
  fecha DATETIME NOT NULL,
  organo VARCHAR(50) NOT NULL,
  hospitalOrigen VARCHAR(150) NOT NULL,
  receptor VARCHAR(150) NOT NULL,
  hospitalDestino VARCHAR(150) NOT NULL,
  score DECIMAL(6,2) NOT NULL,
  txHash VARCHAR(64) NOT NULL,
  estadoBlockchain VARCHAR(100) NOT NULL,
  FOREIGN KEY (donanteId) REFERENCES donantes(id),
  FOREIGN KEY (receptorId) REFERENCES receptores(id)
);

-- ----------------------------------------------------------------------
-- Datos semilla (los mismos que antes estaban en memoria)
-- ----------------------------------------------------------------------
INSERT INTO donantes (hospitalOrigen, organo, grupoSanguineo, horaExtraccion, estado) VALUES
  ('Hospital Italiano', 'Riñón', 'O+', NOW(), 'Disponible'),
  ('Hospital de Clínicas', 'Hígado', 'A-', NOW(), 'Disponible');

INSERT INTO receptores (nombre, organoNecesitado, grupoSanguineo, urgencia, diasEspera, distanciaKm, hospital) VALUES
  ('Paciente A. Gómez', 'Riñón', 'O+', 9, 210, 12, 'Hospital Italiano'),
  ('Paciente B. Fernández', 'Riñón', 'A+', 6, 95, 40, 'Hospital Austral'),
  ('Paciente C. Rodríguez', 'Riñón', 'O-', 8, 300, 8, 'Hospital Italiano'),
  ('Paciente D. Silva', 'Hígado', 'O-', 10, 150, 25, 'Hospital de Clínicas'),
  ('Paciente E. Torres', 'Hígado', 'A-', 5, 60, 5, 'Hospital de Clínicas'),
  ('Paciente F. Acosta', 'Corazón', 'B+', 7, 180, 60, 'Hospital Austral');
