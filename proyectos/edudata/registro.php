<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'conexion.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (empty($_POST['correo_escuela']) || empty($_POST['password'])) {
        die("Faltan campos del formulario.");
    }

    $correo = trim($_POST['correo_escuela']);
    $pass   = $_POST['password'];

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        die("Correo no válido.");
    }

    $stmt = $conn->prepare("SELECT 1 FROM usuarios WHERE correo_escuela = ?");
    $stmt->bind_param("s", $correo);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo "<script>alert('⚠️ El usuario ya existe'); window.history.back();</script>";
        exit;
    }
    $stmt->close();

    $hash = password_hash($pass, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO usuarios (correo_escuela, password, fecha_registro) VALUES (?, ?, NOW())");
    $stmt->bind_param("ss", $correo, $hash);

    if ($stmt->execute()) {
        
        $_SESSION['id_usuario'] = $stmt->insert_id;
        $_SESSION['correo_escuela'] = $correo;

        echo "<script>alert('✅ Registro exitoso'); window.location='alumnos.php';</script>";
        exit;
    } else {
        echo "Error al registrar el usuario: " . $stmt->error;
    }

    $stmt->close();
}

$conn->close();
?>
