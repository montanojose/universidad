<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}

require 'conexion.php';
// ---- Captura y saneo de datos ----
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

$id_usuario          = (int)$_SESSION['id_usuario'];

// ---- Validaciones mínimas ----
$faltan = [];
if ($nombre === '')            $faltan[] = 'nombre';
if ($dni === '')               $faltan[] = 'dni';
if ($email === '')             $faltan[] = 'email';
if ($fecha_nacimiento === '')  $faltan[] = 'fecha_nacimiento';
if ($id_escuela <= 0)          $faltan[] = 'id_escuela';
if ($id_curso <= 0)            $faltan[] = 'id_curso';

if (!empty($faltan)) {
    echo "<script>alert('Faltan campos del formulario: ".implode(', ', $faltan)."'); window.history.back();</script>";
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "<script>alert('Correo inválido.'); window.history.back();</script>";
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_nacimiento)) {
    echo "<script>alert('Formato de fecha inválido (use AAAA-MM-DD).'); window.history.back();</script>";
    exit;
}

$sqlChk = "SELECT 1 FROM cursos WHERE id_curso = ? AND id_escuela = ? LIMIT 1";
$chk = $conn->prepare($sqlChk);
if (!$chk) { 
    echo "<script>alert('Error preparando verificación curso/escuela: ".$conn->error."'); window.history.back();</script>";
    exit;
}
$chk->bind_param('ii', $id_curso, $id_escuela);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    echo "<script>alert('El curso no pertenece a la escuela seleccionada.'); window.history.back();</script>";
    exit;
}
$chk->close();

$sql = "INSERT INTO alumnos
    (nombre, dni, email, fecha_nacimiento,
     id_curso, id_escuela,
     telefono_emergencia, nacionalidad, direccion, materia_dificultosa,
     obra_social, turno, observaciones,
     id_usuario, fecha_registro)
VALUES
    (?,?,?,?, ?,?,
     ?,?,?,?,
     ?,?,?,
     ?, NOW())";

$stmt = $conn->prepare($sql);
if (!$stmt) { 
    echo "<script>alert('Error preparando inserción: ".$conn->error."'); window.history.back();</script>";
    exit;
}

$stmt->bind_param(
    "ssssiisssssssi",
    $nombre,               // s
    $dni,                  // s
    $email,                // s
    $fecha_nacimiento,     // s (DATE aceptará 'YYYY-MM-DD')
    $id_curso,             // i
    $id_escuela,           // i
    $telefono_emergencia,  // s
    $nacionalidad,         // s
    $direccion,            // s
    $materia_dificultosa,  // s
    $obra_social,          // s
    $turno,                // s
    $observaciones,        // s
    $id_usuario            // i
);

try {
    $ok = $stmt->execute();
} catch (mysqli_sql_exception $e) {
    
    $msg = $e->getMessage();
    if (strpos($msg, 'Duplicate') !== false || strpos($msg, 'UNIQUE') !== false) {//ver 
        echo "<script>alert('No se pudo registrar: DNI o email ya existen.'); window.history.back();</script>";
        exit;
    }
    echo "<script>alert('Error al registrar: ".htmlspecialchars($msg)."'); window.history.back();</script>";
    exit;
}

$stmt->close();
$conn->close();

if ($ok) {
    header('Location: ver_alumnos.php?ok=1');
    exit;
} else {
    echo "<script>alert('No se pudo registrar el alumno.'); window.history.back();</script>";
    exit;
}
