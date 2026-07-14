<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generar Hash</title>
</head>
<body style="font-family:Arial;padding:20px">
  <h2>🔐 Generar hash BCRYPT</h2>
  <form method="post">
    <label>Contraseña a hashear:
      <input type="text" name="clave" required>
    </label>
    <button type="submit">Generar</button>
  </form>
  <?php
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $clave = (string)($_POST['clave'] ?? '');
      if ($clave !== '') {
          $hash = password_hash($clave, PASSWORD_BCRYPT);
          echo "<p><b>Hash generado:</b></p><pre style='background:#eee;padding:10px;border-radius:6px;'>$hash</pre>";
      }
  }
  ?>
</body>
</html>
