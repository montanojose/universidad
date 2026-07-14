<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'profesor' || empty($_SESSION['id_profesor'])) {
    header('Location: login_profesor.html');
    exit;
}

require 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profesor.php');
    exit;
}

$id_profesor       = (int)$_SESSION['id_profesor'];
$id_curso_materia  = (int)($_POST['id_curso_materia'] ?? 0);
$fecha             = $_POST['fecha'] ?? '';
$estados           = $_POST['estado'] ?? [];   // array [id_alumno => 'P'/'A']

if ($id_curso_materia <= 0 || $fecha === '' || empty($estados)) {
    echo "<script>alert('Faltan datos para guardar la asistencia.'); window.history.back();</script>";
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    echo "<script>alert('Fecha inválida.'); window.history.back();</script>";
    exit;
}

foreach ($estados as $id_alumno => $estado) {
    $id_alumno = (int)$id_alumno;
    $estado    = ($estado === 'A') ? 'A' : 'P';  // solo P o A

    $del = $conn->prepare("DELETE FROM asistencias WHERE id_alumno = ? AND id_curso_materia = ? AND fecha = ?");
    if ($del) {
        $del->bind_param('iis', $id_alumno, $id_curso_materia, $fecha);
        $del->execute();
        $del->close();
    }

    $ins = $conn->prepare("INSERT INTO asistencias (id_alumno, id_curso_materia, fecha, estado) VALUES (?,?,?,?)");
    if (!$ins) {
        echo "Error preparando inserción: " . $conn->error;
        exit;
    }
    $ins->bind_param('iiss', $id_alumno, $id_curso_materia, $fecha, $estado);
    $ins->execute();
    $ins->close();
}

header('Location: profesor.php?ok=1&id_curso_materia='.$id_curso_materia.'&fecha='.$fecha);
exit;
