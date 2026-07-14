<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}

require 'conexion.php';

$escuelas = $conn->query("SELECT id_escuela, nombre FROM escuelas ORDER BY nombre");

$id_escuela_sel = isset($_GET['id_escuela']) ? (int)$_GET['id_escuela'] : 0;
$cursos = null;
if ($id_escuela_sel > 0) {
    $stmt = $conn->prepare("SELECT id_curso, nombre FROM cursos WHERE id_escuela = ? ORDER BY nombre");
    $stmt->bind_param('i', $id_escuela_sel);
    $stmt->execute();
    $cursos = $stmt->get_result();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>EduData · Registrar Alumno</title>
  <link rel="stylesheet" href="alumnos.css" />
</head>
<body>

  <header class="header-alumnos">
    <h1 class="titulo-principal">Sección de Alumnos</h1>
    <a href="logout_profesor.php" class="btn-logout" onclick="return confirm('¿Cerrar sesión?')">🚪 Cerrar sesión</a>
  </header>

  <p class="introduccion">
    Primero elegí la <strong>Escuela</strong> y tocá <em>Filtrar cursos</em>. Después completá los datos del alumno y seleccioná el <strong>Curso</strong> (queda guardado el <em>ID</em> real).
  </p>

  <h2 class="subtitulo-izquierda">Ingresar un alumno</h2>

  <section class="alumnos-section">

    <!-- Paso A: elegir escuela y filtrar cursos (GET) -->
    <form method="get" action="alumnos.php" class="formulario" style="margin-bottom: 16px;">
      <label>Escuela
        <select name="id_escuela" required>
          <option value="" disabled <?= $id_escuela_sel===0?'selected':''; ?>>Selecciona una escuela</option>
          <?php while ($e = $escuelas->fetch_assoc()): ?>
            <option value="<?= (int)$e['id_escuela'] ?>" <?= $id_escuela_sel===(int)$e['id_escuela']?'selected':''; ?>>
              <?= htmlspecialchars($e['nombre']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </label>
      <button type="submit">Filtrar cursos</button>
      <a href="alumnos.php" class="btn-borde" style="margin-left:8px;">Limpiar</a>
    </form>

    <!-- Paso B: formulario principal (POST) -->
    <form action="procesar_formulario.php" method="POST" class="formulario">

      <!-- Guardamos la escuela seleccionada -->
      <input type="hidden" name="id_escuela" value="<?= (int)$id_escuela_sel ?>">

      <label>Nombre completo
        <input type="text" name="nombre" placeholder="Ej: Juan Pérez" required />
      </label>

      <label>DNI
        <input type="text" name="dni" placeholder="Ej: 40123456" required />
      </label>

      <label>Correo electrónico
        <input type="email" name="email" placeholder="Ej: alumno@escuela.com" required />
      </label>

      <label>Fecha de nacimiento
        <input type="date" name="fecha_nacimiento" required />
      </label>

      <!-- Cursos dependientes de la escuela -->
      <label>Curso / División
        <select name="id_curso" <?= $id_escuela_sel===0 ? 'disabled' : '' ?> required>
          <option value="" disabled selected>Selecciona un curso</option>
          <?php if ($cursos): ?>
            <?php while ($c = $cursos->fetch_assoc()): ?>
              <option value="<?= (int)$c['id_curso'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
            <?php endwhile; ?>
          <?php endif; ?>
        </select>
      </label>

      <label>Teléfono de emergencia
        <input type="tel" name="telefono_emergencia" placeholder="Ej: +54 9 261 555-1234" />
      </label>

      <label>Nacionalidad
        <input type="text" name="nacionalidad" placeholder="Ej: Argentina" />
      </label>

      <label>Dirección
        <input type="text" name="direccion" placeholder="Calle, número, localidad" />
      </label>

      <label>Materia más dificultosa
        <input type="text" name="materia_dificultosa" placeholder="Ej: Matemáticas" />
      </label>

      <label>Obra social
        <input type="text" name="obra_social" placeholder="Ej: OSDE" />
      </label>

      <label>Turno
        <select name="turno">
          <option value="">Selecciona…</option>
          <option value="Mañana">Mañana</option>
          <option value="Tarde">Tarde</option>
          <option value="Noche">Noche</option>
        </select>
      </label>

      <label>Observaciones
        <textarea name="observaciones" rows="3" placeholder="Información adicional (opcional)"></textarea>
      </label>

      <button type="submit" <?= $id_escuela_sel===0 ? 'disabled' : '' ?>>Registrar Alumno</button>

      <div class="ver-base">
        <a href="ver_alumnos.php" class="btn-ver">📋 Ver base de alumnos</a>
      </div>
    </form>
  </section>

</body>
</html>
