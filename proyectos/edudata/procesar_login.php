<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

if (empty($_POST['correo_escuela']) || empty($_POST['password'])) {
    echo "<script>alert('Faltan campos del formulario'); window.history.back();</script>";
    exit;
}

$correo = trim($_POST['correo_escuela']);
$pass   = $_POST['password'];

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo "<script>alert('Correo inválido'); window.history.back();</script>";
    exit;
}

// Buscar usuario por correo_escuela
$sql = "SELECT id_usuario, password FROM usuarios WHERE correo_escuela = ? LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Error de preparación: " . $conn->error);
}

$stmt->bind_param("s", $correo);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 1) {
    $stmt->bind_result($id_usuario, $hash_guardado);
    $stmt->fetch();

    if (password_verify($pass, $hash_guardado)) {
        // Login correcto → sesión
        $_SESSION['id_usuario']     = (int)$id_usuario;
        $_SESSION['correo_escuela'] = $correo;

        session_regenerate_id(true);

        header('Location: alumnos.php'); //ver despues
        
        exit;
    } else {
        echo "<script>alert('Contraseña incorrecta'); window.history.back();</script>";
        exit;
    }
} else {
    echo "<script>alert('Usuario no encontrado'); window.history.back();</script>";
    exit;
}

$stmt->close();
$conn->close();
