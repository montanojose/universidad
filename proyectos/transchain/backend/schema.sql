-- schema.sql
-- Crea la base de datos "transplantchain" y la deja poblada con datos de ejemplo.
-- Usa tablas normalizadas para hospitales, usuarios y pacientes.

CREATE DATABASE IF NOT EXISTS transplantchain
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE transplantchain;

DROP TABLE IF EXISTS asignaciones;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS hospitales;
DROP TABLE IF EXISTS donantes;
DROP TABLE IF EXISTS receptores;

CREATE TABLE hospitales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL UNIQUE,
  direccion VARCHAR(255),
  telefono VARCHAR(50),
  creadoEn DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  passwordHash VARCHAR(255) NOT NULL,
  role ENUM('admin','hospital') NOT NULL DEFAULT 'hospital',
  hospitalId INT NULL,
  creadoEn DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hospitalId) REFERENCES hospitales(id)
);

CREATE TABLE donantes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hospitalOrigen VARCHAR(150) NOT NULL,
  hospitalId INT NULL,
  organo VARCHAR(50) NOT NULL,
  grupoSanguineo VARCHAR(5) NOT NULL,
  horaExtraccion DATETIME NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'Disponible',
  FOREIGN KEY (hospitalId) REFERENCES hospitales(id)
);

CREATE TABLE receptores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  organoNecesitado VARCHAR(50) NOT NULL,
  grupoSanguineo VARCHAR(5) NOT NULL,
  urgencia INT NOT NULL,
  diasEspera INT NOT NULL,
  distanciaKm INT NOT NULL,
  hospital VARCHAR(150) NOT NULL,
  hospitalId INT NULL,
  FOREIGN KEY (hospitalId) REFERENCES hospitales(id)
);

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
  txHash VARCHAR(128) NOT NULL,
  estadoBlockchain VARCHAR(100) NOT NULL,
  FOREIGN KEY (donanteId) REFERENCES donantes(id),
  FOREIGN KEY (receptorId) REFERENCES receptores(id)
);

INSERT INTO hospitales (nombre, direccion, telefono) VALUES
  ('Hospital Italiano', 'Calle Falsa 123', '011-1111'),
  ('Hospital de Clínicas', 'Av. Siempre Viva 742', '011-2222'),
  ('Hospital Austral', 'Paseo Austral 456', '011-3333');

INSERT INTO donantes (hospitalOrigen, hospitalId, organo, grupoSanguineo, horaExtraccion, estado) VALUES
  ('Hospital Italiano', 1, 'Riñón', 'O+', NOW(), 'Disponible'),
  ('Hospital de Clínicas', 2, 'Hígado', 'A-', NOW(), 'Disponible');

INSERT INTO receptores (nombre, organoNecesitado, grupoSanguineo, urgencia, diasEspera, distanciaKm, hospital, hospitalId) VALUES
  ('Paciente A. Gómez', 'Riñón', 'O+', 9, 210, 12, 'Hospital Italiano', 1),
  ('Paciente B. Fernández', 'Riñón', 'A+', 6, 95, 40, 'Hospital Austral', 3),
  ('Paciente C. Rodríguez', 'Riñón', 'O-', 8, 300, 8, 'Hospital Italiano', 1),
  ('Paciente D. Silva', 'Hígado', 'O-', 10, 150, 25, 'Hospital de Clínicas', 2),
  ('Paciente E. Torres', 'Hígado', 'A-', 5, 60, 5, 'Hospital de Clínicas', 2),
  ('Paciente F. Acosta', 'Corazón', 'B+', 7, 180, 60, 'Hospital Austral', 3);
