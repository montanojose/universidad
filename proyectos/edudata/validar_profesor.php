<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require 'conexion.php';

function fail($msg) {
  echo "<div style='font-family:Arial;padding:16px;background:#fee;color:#900;border:1px solid #f88;border-radius:8px;max-width:720px;margin:40px auto'>
          <h3>❌ $msg</h3>
          <p><a href='login_profesor.html'>Volver al login</a></p>
        </div>";
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: login_profesor.html'); 
  exit;
}
$correo = trim($_POST['correo'] ?? '');
$pass   = (string)($_POST['password'] ?? '');

if ($correo === '' || $pass === '') fail('Faltan campos (correo y/o contraseña).');
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) fail('Correo inválido: ' . htmlspecialchars($correo));

$sql = "SELECT id_profesor, id_escuela, password, nombre
        FROM profesores
        WHERE correo = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) fail('Error preparando consulta: ' . $conn->error);

$stmt->bind_param('s', $correo);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows !== 1) {
  $stmt->close();
  fail("Usuario no encontrado: <b>" . htmlspecialchars($correo) . "</b>");
}

$stmt->bind_result($id_profesor, $id_escuela, $hash_guardado, $nombre);
$stmt->fetch();
$stmt->close();

$es_bcrypt = is_string($hash_guardado) && preg_match('/^\$2[aby]\$\d{2}\$/', $hash_guardado);
$tipo = $es_bcrypt ? 'BCRYPT' : 'texto plano';//esttudiar


$ok = $es_bcrypt ? password_verify($pass, $hash_guardado)
                 : hash_equals((string)$hash_guardado, (string)$pass);

if (!$ok) {
  fail('Contraseña incorrecta (tipo guardado: ' . $tipo . '). Sugerencia: regenerá hash con generar_hash.php y actualizá la DB.');
}

// Sesión OK
session_regenerate_id(true);
$_SESSION['rol']         = 'profesor';
$_SESSION['id_profesor'] = (int)$id_profesor;
$_SESSION['id_escuela']  = (int)$id_escuela;
$_SESSION['correo']      = $correo;
$_SESSION['nombre']      = $nombre;

$conn->close();

header('Location: profesor.php');
exit;