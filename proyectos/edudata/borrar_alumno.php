
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.html');
    exit;
}
$id_usuario = (int)$_SESSION['id_usuario'];

require 'conexion.php';

$id_alumno = 0;
if (isset($_GET['id_alumno'])) {
    $id_alumno = (int)$_GET['id_alumno'];
} elseif (isset($_GET['id'])) {
    $id_alumno = (int)$_GET['id'];
}

if ($id_alumno <= 0) {
    header('Location: ver_alumnos.php?msg=alumno_invalido');
    exit;
}

$chk = $conn->prepare("SELECT 1 FROM alumnos WHERE id_alumno = ? AND id_usuario = ? LIMIT 1");
if (!$chk) {
    die("Error preparando verificación: " . $conn->error);
}
$chk->bind_param('ii', $id_alumno, $id_usuario);
$chk->execute();
$chk->store_result();

if ($chk->num_rows === 0) {
    $chk->close();
    header('Location: ver_alumnos.php?msg=no_autorizado');
    exit;
}
$chk->close();

$delAsis = $conn->prepare("DELETE FROM asistencias WHERE id_alumno = ?");
if ($delAsis) {
    $delAsis->bind_param('i', $id_alumno);
    $delAsis->execute();
    $delAsis->close();
}

$del = $conn->prepare("DELETE FROM alumnos WHERE id_alumno = ? AND id_usuario = ?");
if (!$del) {
    die("Error preparando borrado: " . $conn->error);
}
$del->bind_param('ii', $id_alumno, $id_usuario);
$del->execute();
$del->close();

header('Location: ver_alumnos.php?msg=alumno_borrado');
exit;
