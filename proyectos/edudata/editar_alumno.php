<?php
// editar_alumno.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
require 'conexion.php';

$id_usuario = (int)$_SESSION['id_usuario'];

// Aceptar tanto ?id= como ?id_alumno= (compatibilidad)
$id_alumno = 0;
if (isset($_GET['id_alumno'])) {
    $id_alumno = (int)$_GET['id_alumno'];
} elseif (isset($_GET['id'])) {
    $id_alumno = (int)$_GET['id'];
}

if ($id_alumno <= 0) {
    header('Location: ver_alumnos.php');
    exit;
}

// 1) Verificar que el alumno existe y pertenece al usuario
$sql = "SELECT * FROM alumnos WHERE id_alumno = ? AND id_usuario = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $id_alumno, $id_usuario);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows === 0) {
    $stmt->close();
    die("No tenés permiso para editar este alumno.");
}
$alumno = $res->fetch_assoc();
$stmt->close();

// 2) Si viene POST, procesar actualización
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre              = trim($_POST['nombre'] ?? '');
    $dni                 = trim($_POST['dni'] ?? '');
    $email               = trim($_POST['email'] ?? '');
    $fecha_nacimiento    = trim($_POST['fecha_nacimiento'] ?? '');

    $id_escuela          = (int)($_POST['id_escuela'] ?? 0);
    $id_curso            = (int)($_POST['id_curso'] ?? 0);

    $telefono_emergencia = trim($_POST['telefono_emergencia'] ?? '');
    $nacionalidad        = trim($_POST['nacionalidad'] ?? '');
    $direccion           = trim($_POST['direccion'] ?? '');
    $materia_dificultosa = trim($_POST['materia_dificultosa'] ?? '');
    $obra_social         = trim($_POST['obra_social'] ?? '');
    $turno               = trim($_POST['turno'] ?? '');
    $observaciones       = trim($_POST['observaciones'] ?? '');

    // Validaciones mínimas
    if ($nombre==='' || $dni==='' || $email==='' || $fecha_nacimiento==='' || !$id_escuela || !$id_curso) {
        die('Faltan campos obligatorios.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die('Correo inválido.');
    }

    // Coherencia curso ↔ escuela
    $chk = $conn->prepare("SELECT 1 FROM cursos WHERE id_curso = ? AND id_escuela = ? LIMIT 1");
    $chk->bind_param('ii', $id_curso, $id_escuela);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        $chk->close();
        die('El curso no pertenece a la escuela seleccionada.');
    }
    $chk->close();

    // UPDATE
    $upd = $conn->prepare("UPDATE alumnos SET
        nombre=?, dni=?, email=?, fecha_nacimiento=?,
        id_curso=?, id_escuela=?,
        telefono_emergencia=?, nacionalidad=?, direccion=?, materia_dificultosa=?,
        obra_social=?, turno=?, observaciones=?
        WHERE id_alumno=? AND id_usuario=?");

    $upd->bind_param(
        "ssssiisssssssii",
        $nombre, $dni, $email, $fecha_nacimiento,
        $id_curso, $id_escuela,
        $telefono_emergencia, $nacionalidad, $direccion, $materia_dificultosa,
        $obra_social, $turno, $observaciones,
        $id_alumno, $id_usuario
    );

    if ($upd->execute()) {
        $upd->close();
        header("Location: ver_alumnos.php?edit=ok");
        exit;
    } else {
        echo "Error al actualizar: " . $upd->error;
        $upd->close();
    }
}

// 3) Cargar ESCUELAS y CURSOS desde la BD para el formulario
$escuelas = [];
$resEsc = $conn->query("SELECT id_escuela, nombre FROM escuelas ORDER BY nombre");
while ($e = $resEsc->fetch_assoc()) {
    $escuelas[] = $e;
}
$resEsc->close();

// escuela seleccionada por defecto = la que tiene el alumno
$id_escuela_sel = (int)$alumno['id_escuela'];

// cursos de esa escuela
$cursos = [];
if ($id_escuela_sel > 0) {
    $stmtC = $conn->prepare("SELECT id_curso, nombre FROM cursos WHERE id_escuela = ? ORDER BY nombre");
    $stmtC->bind_param('i', $id_escuela_sel);
    $stmtC->execute();
    $resC = $stmtC->get_result();
    while ($c = $resC->fetch_assoc()) {
        $cursos[] = $c;
    }
    $stmtC->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Editar alumno</title>
  <link rel="stylesheet" href="alumnos.css" />
</head>
<body>

  <header class="header-alumnos">
    <h1 class="titulo-principal">Editar Alumno</h1>
    <a href="ver_alumnos.php" class="btn-logout">← Volver</a>
  </header>

  <section class="alumnos-section">
    <form method="POST" class="formulario">

      <label>Nombre completo
        <input type="text" name="nombre" value="<?= htmlspecialchars($alumno['nombre']) ?>" required />
      </label>

      <label>DNI
        <input type="text" name="dni" value="<?= htmlspecialchars($alumno['dni']) ?>" required />
      </label>

      <label>Correo electrónico
        <input type="email" name="email" value="<?= htmlspecialchars($alumno['email']) ?>" required />
      </label>

      <label>Fecha de nacimiento
        <input type="date" name="fecha_nacimiento" value="<?= htmlspecialchars($alumno['fecha_nacimiento']) ?>" required />
      </label>

      <!-- Escuela desde BD -->
      <label>Escuela
        <select name="id_escuela" required>
          <option value="" disabled>Selecciona una escuela</option>
          <?php foreach ($escuelas as $e): ?>
            <option value="<?= (int)$e['id_escuela'] ?>"
              <?= $id_escuela_sel === (int)$e['id_escuela'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($e['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <!-- Cursos de la escuela del alumno -->
      <label>Curso / División
        <select name="id_curso" required>
          <option value="" disabled>Selecciona un curso</option>
          <?php foreach ($cursos as $c): ?>
            <option value="<?= (int)$c['id_curso'] ?>"
              <?= (int)$alumno['id_curso'] === (int)$c['id_curso'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Teléfono de emergencia
        <input type="tel" name="telefono_emergencia" value="<?= htmlspecialchars($alumno['telefono_emergencia']) ?>" />
      </label>

      <label>Nacionalidad
        <input type="text" name="nacionalidad" value="<?= htmlspecialchars($alumno['nacionalidad']) ?>" />
      </label>

      <label>Dirección
        <input type="text" name="direccion" value="<?= htmlspecialchars($alumno['direccion']) ?>" />
      </label>

      <label>Materia más dificultosa
        <input type="text" name="materia_dificultosa" value="<?= htmlspecialchars($alumno['materia_dificultosa']) ?>" />
      </label>

      <label>Obra social
        <input type="text" name="obra_social" value="<?= htmlspecialchars($alumno['obra_social']) ?>" />
      </label>

      <label>Turno
        <select name="turno">
          <option value=""       <?= $alumno['turno']===''         ? 'selected':''; ?>>Selecciona…</option>
          <option value="Mañana" <?= $alumno['turno']==='Mañana'  ? 'selected':''; ?>>Mañana</option>
          <option value="Tarde"  <?= $alumno['turno']==='Tarde'   ? 'selected':''; ?>>Tarde</option>
          <option value="Noche"  <?= $alumno['turno']==='Noche'   ? 'selected':''; ?>>Noche</option>
        </select>
      </label>

      <label>Observaciones
        <textarea name="observaciones" rows="3"><?= htmlspecialchars($alumno['observaciones']) ?></textarea>
      </label>

      <div class="acciones">
        <button type="submit">Guardar cambios</button>
        <a href="ver_alumnos.php">Cancelar</a>
      </div>
    </form>
  </section>

</body>
</html>
