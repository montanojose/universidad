<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (isset($_SESSION['usuario_rol'])) {
    header('Location: login.php');
    exit;
}

$mensaje = '';
$tipo_mensaje = '';

$username = '';
$dni = '';
$nombre = '';
$apellido = '';
$telefono = '';
$email = '';
$direccion = '';
$provincia = '';
$nombre_localidad = '';
$localidad_seleccionada = '';
$localidades = [];

try {
    $stmtLocalidades = $pdo->query("
        SELECT provincia, nombre_localidad
        FROM Localidad
        ORDER BY provincia ASC, nombre_localidad ASC
    ");
    $localidades = $stmtLocalidades->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = 'No se pudieron cargar las localidades.';
    $tipo_mensaje = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password_confirm = trim($_POST['password_confirm'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $localidad_seleccionada = trim($_POST['localidad_seleccionada'] ?? '');

    if ($localidad_seleccionada !== '') {
        $partes = explode('||', $localidad_seleccionada);
        if (count($partes) === 2) {
            $provincia = $partes[0];
            $nombre_localidad = $partes[1];
        }
    }

    if (
        $username === '' ||
        $password === '' ||
        $password_confirm === '' ||
        $dni === '' ||
        $nombre === '' ||
        $apellido === '' ||
        $email === '' ||
        $direccion === '' ||
        $provincia === '' ||
        $nombre_localidad === ''
    ) {
        $mensaje = 'Completa todos los campos obligatorios.';
        $tipo_mensaje = 'error';
    } elseif ($password !== $password_confirm) {
        $mensaje = 'Las contrasenas no coinciden.';
        $tipo_mensaje = 'error';
    } elseif (strlen($password) < 6) {
        $mensaje = 'La contrasena debe tener al menos 6 caracteres.';
        $tipo_mensaje = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtUsuario = $pdo->prepare("SELECT username FROM Usuario WHERE username = :username LIMIT 1");
            $stmtUsuario->execute([':username' => $username]);
            if ($stmtUsuario->fetch()) {
                throw new Exception('Ese nombre de usuario ya existe.');
            }

            $stmtCliente = $pdo->prepare("SELECT dni FROM Cliente WHERE dni = :dni LIMIT 1");
            $stmtCliente->execute([':dni' => $dni]);
            if ($stmtCliente->fetch()) {
                throw new Exception('Ya existe un cliente registrado con ese DNI.');
            }

            $stmtInsertarPersona = $pdo->prepare("
                INSERT INTO Persona (
                    dni,
                    nombre,
                    apellido,
                    telefono,
                    email
                ) VALUES (
                    :dni,
                    :nombre,
                    :apellido,
                    :telefono,
                    :email
                )
            ");
            $stmtInsertarPersona->execute([
                ':dni' => $dni,
                ':nombre' => $nombre,
                ':apellido' => $apellido,
                ':telefono' => ($telefono !== '' ? $telefono : null),
                ':email' => $email
            ]);

            $stmtInsertarCliente = $pdo->prepare("
                INSERT INTO Cliente (
                    dni,
                    direccion,
                    provincia,
                    nombre_localidad
                ) VALUES (
                    :dni,
                    :direccion,
                    :provincia,
                    :nombre_localidad
                )
            ");
            $stmtInsertarCliente->execute([
                ':dni' => $dni,
                ':direccion' => $direccion,
                ':provincia' => $provincia,
                ':nombre_localidad' => $nombre_localidad
            ]);

            $stmtInsertarUsuario = $pdo->prepare("
                INSERT INTO Usuario (
                    username,
                    password_hash,
                    cod_rol,
                    dni_persona,
                    activo
                ) VALUES (
                    :username,
                    :password_hash,
                    'CLIENTE',
                    :dni_persona,
                    1
                )
            ");
            $stmtInsertarUsuario->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':dni_persona' => $dni
            ]);

            $pdo->commit();

            header('Location: login.php?registro=ok');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = ($e instanceof PDOException)
                ? 'Ocurrio un error al registrar el cliente. Verifica que los datos no esten repetidos.'
                : $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

$ruta_css = '../assets/css/main.css';
$ruta_css_fisica = __DIR__ . '/../assets/css/main.css';
$version_css = file_exists($ruta_css_fisica) ? filemtime($ruta_css_fisica) : time();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro de cliente - LogiTrack</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php echo $ruta_css . '?v=' . $version_css; ?>">
</head>
<body class="login-body">
    <main class="login-container">
        <section class="login-card" style="max-width: 860px;">
            <div class="login-brand">
                <div class="login-logo">LT</div>
                <div>
                    <h1>Crear cuenta</h1>
                    <p>Registro de cliente para acceder al seguimiento y solicitudes.</p>
                </div>
            </div>

            <?php if ($mensaje !== ''): ?>
                <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars($mensaje); ?>
                </div>
            <?php endif; ?>

            <form action="registro_cliente.php" method="POST" class="login-form">
                <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <div>
                        <div class="form-group">
                            <label for="username">Usuario</label>
                            <input type="text" id="username" name="username" class="form-control" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="password">Contrasena</label>
                            <input type="password" id="password" name="password" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="password_confirm">Confirmar contrasena</label>
                            <input type="password" id="password_confirm" name="password_confirm" class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="dni">DNI</label>
                            <input type="text" id="dni" name="dni" class="form-control" value="<?php echo htmlspecialchars($dni); ?>" required>
                        </div>
                    </div>

                    <div>
                        <div class="form-group">
                            <label for="nombre">Nombre</label>
                            <input type="text" id="nombre" name="nombre" class="form-control" value="<?php echo htmlspecialchars($nombre); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="apellido">Apellido</label>
                            <input type="text" id="apellido" name="apellido" class="form-control" value="<?php echo htmlspecialchars($apellido); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="telefono">Telefono</label>
                            <input type="text" id="telefono" name="telefono" class="form-control" value="<?php echo htmlspecialchars($telefono); ?>">
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="direccion">Direccion</label>
                    <input type="text" id="direccion" name="direccion" class="form-control" value="<?php echo htmlspecialchars($direccion); ?>" required>
                </div>

                <div class="form-group">
                    <label for="localidad_seleccionada">Localidad</label>
                    <select id="localidad_seleccionada" name="localidad_seleccionada" class="form-control" required>
                        <option value="">Seleccione una localidad</option>
                        <?php foreach ($localidades as $localidad): ?>
                            <?php $valor = $localidad['provincia'] . '||' . $localidad['nombre_localidad']; ?>
                            <option value="<?php echo htmlspecialchars($valor); ?>" <?php echo $localidad_seleccionada === $valor ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($localidad['provincia'] . ' - ' . $localidad['nombre_localidad']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: flex; flex-direction: column; align-items: center; gap: 12px; margin-top: 6px;">
                    <button type="submit" class="btn-primary" style="width: min(100%, 280px);">
                        Registrarme
                    </button>

                    <a href="login.php" class="btn-public-secondary" style="width: min(100%, 280px); text-align: center;">
                        Ya tengo cuenta
                    </a>

                    <a href="../index.php" class="btn-public-secondary" style="width: min(100%, 280px); text-align: center;">
                        Volver al inicio
                    </a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
