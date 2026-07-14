<?php
require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'EMPLEADO_SUCURSAL', 'CHOFER', 'CLIENTE']);

$titulo_pagina = 'Mi cuenta';
$mensaje = '';
$tipo_mensaje = '';

$rol = $_SESSION['usuario_rol'] ?? '';
$username = $_SESSION['username'] ?? '';
$datos = [];
$localidades = [];

function cargarDatosCuenta(PDO $pdo, string $rol, string $username): array
{
    $sql = "
        SELECT
            u.username,
            u.cod_rol,
            u.dni_persona,
            ch.legajo AS legajo_chofer,
            c.dni AS dni_cliente,
            e.legajo_empleado,
            c.dni AS cliente_dni,
            c.nombre AS cliente_nombre,
            c.apellido AS cliente_apellido,
            c.telefono AS cliente_telefono,
            c.email AS cliente_email,
            c.direccion AS cliente_direccion,
            c.provincia AS cliente_provincia,
            c.nombre_localidad AS cliente_localidad,
            ch.legajo AS chofer_legajo,
            ch.dni AS chofer_dni,
            ch.nombre AS chofer_nombre,
            ch.apellido AS chofer_apellido,
            ch.telefono AS chofer_telefono,
            ch.email AS chofer_email,
            e.legajo_empleado AS empleado_legajo,
            e.dni AS empleado_dni,
            e.nombre AS empleado_nombre,
            e.apellido AS empleado_apellido,
            e.telefono AS empleado_telefono,
            e.email AS empleado_email
        FROM Usuario u
        LEFT JOIN vista_cliente c ON u.dni_persona = c.dni
        LEFT JOIN vista_chofer ch ON u.dni_persona = ch.dni
        LEFT JOIN vista_empleado_sucursal e ON u.dni_persona = e.dni
        WHERE u.username = :username
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':username' => $username]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

try {
    $datos = cargarDatosCuenta($pdo, $rol, $username);

    if ($rol === 'CLIENTE') {
        $stmtLocalidades = $pdo->query("
            SELECT provincia, nombre_localidad
            FROM Localidad
            ORDER BY provincia ASC, nombre_localidad ASC
        ");
        $localidades = $stmtLocalidades->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $mensaje = 'No se pudieron cargar tus datos.';
    $tipo_mensaje = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_datos') {
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $localidad_seleccionada = trim($_POST['localidad_seleccionada'] ?? '');
    $provincia = '';
    $nombre_localidad = '';

    if ($localidad_seleccionada !== '') {
        $partes = explode('||', $localidad_seleccionada);
        if (count($partes) === 2) {
            $provincia = $partes[0];
            $nombre_localidad = $partes[1];
        }
    }

    if ($rol === 'ADMIN') {
        $mensaje = 'El administrador solo puede cambiar su contrasena desde esta pantalla.';
        $tipo_mensaje = 'warning';
    } elseif ($nombre === '' || $apellido === '' || $dni === '') {
        $mensaje = 'Completa DNI, nombre y apellido.';
        $tipo_mensaje = 'error';
    } elseif ($rol === 'CLIENTE' && ($email === '' || $direccion === '' || $provincia === '' || $nombre_localidad === '')) {
        $mensaje = 'Completa email, direccion y localidad.';
        $tipo_mensaje = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            if ($rol === 'CLIENTE') {
                $dni_original = $datos['dni_cliente'] ?? '';
                $stmt = $pdo->prepare("
                    UPDATE Persona
                    SET
                        dni = :dni,
                        nombre = :nombre,
                        apellido = :apellido,
                        telefono = :telefono,
                        email = :email
                    WHERE dni = :dni_original
                ");
                $stmt->execute([
                    ':dni' => $dni,
                    ':nombre' => $nombre,
                    ':apellido' => $apellido,
                    ':telefono' => ($telefono !== '' ? $telefono : null),
                    ':email' => $email,
                    ':dni_original' => $dni_original
                ]);

                $stmt = $pdo->prepare("
                    UPDATE Cliente
                    SET
                        direccion = :direccion,
                        provincia = :provincia,
                        nombre_localidad = :nombre_localidad
                    WHERE dni = :dni
                ");
                $stmt->execute([
                    ':dni' => $dni,
                    ':direccion' => $direccion,
                    ':provincia' => $provincia,
                    ':nombre_localidad' => $nombre_localidad,
                ]);

                $stmtUsuarioCliente = $pdo->prepare("
                    UPDATE Usuario
                    SET dni_persona = :dni
                    WHERE username = :username
                ");
                $stmtUsuarioCliente->execute([
                    ':dni' => $dni,
                    ':username' => $username
                ]);

                $_SESSION['dni_cliente'] = $dni;
                $_SESSION['usuario_dni_cliente'] = $dni;
                $_SESSION['dni_persona'] = $dni;
            } elseif ($rol === 'CHOFER') {
                $email_anterior = $datos['chofer_email'] ?? '';
                $stmt = $pdo->prepare("
                    UPDATE Persona
                    SET
                        dni = :dni,
                        nombre = :nombre,
                        apellido = :apellido,
                        telefono = :telefono,
                        email = :email
                    WHERE dni = :dni_actual
                ");
                $stmt->execute([
                    ':dni' => $dni,
                    ':nombre' => $nombre,
                    ':apellido' => $apellido,
                    ':telefono' => ($telefono !== '' ? $telefono : null),
                    ':email' => ($email !== '' ? $email : null),
                    ':dni_actual' => $datos['chofer_dni'] ?? ''
                ]);

                $_SESSION['dni_persona'] = $dni;

                if ($email !== '' && $username === $email_anterior && $email !== $username) {
                    $stmtExisteUsuario = $pdo->prepare("SELECT username FROM Usuario WHERE username = :username LIMIT 1");
                    $stmtExisteUsuario->execute([':username' => $email]);
                    if ($stmtExisteUsuario->fetch()) {
                        throw new Exception('Ya existe un usuario con ese email.');
                    }

                    $stmtUsuario = $pdo->prepare("UPDATE Usuario SET username = :nuevo WHERE username = :actual");
                    $stmtUsuario->execute([
                        ':nuevo' => $email,
                        ':actual' => $username
                    ]);

                    $_SESSION['username'] = $email;
                    $username = $email;
                }
            } elseif ($rol === 'EMPLEADO_SUCURSAL') {
                $email_anterior = $datos['empleado_email'] ?? '';
                $stmt = $pdo->prepare("
                    UPDATE Persona
                    SET
                        dni = :dni,
                        nombre = :nombre,
                        apellido = :apellido,
                        telefono = :telefono,
                        email = :email
                    WHERE dni = :dni_actual
                ");
                $stmt->execute([
                    ':dni' => $dni,
                    ':nombre' => $nombre,
                    ':apellido' => $apellido,
                    ':telefono' => ($telefono !== '' ? $telefono : null),
                    ':email' => ($email !== '' ? $email : null),
                    ':dni_actual' => $datos['empleado_dni'] ?? ''
                ]);

                $_SESSION['dni_persona'] = $dni;

                if ($email !== '' && $username === $email_anterior && $email !== $username) {
                    $stmtExisteUsuario = $pdo->prepare("SELECT username FROM Usuario WHERE username = :username LIMIT 1");
                    $stmtExisteUsuario->execute([':username' => $email]);
                    if ($stmtExisteUsuario->fetch()) {
                        throw new Exception('Ya existe un usuario con ese email.');
                    }

                    $stmtUsuario = $pdo->prepare("UPDATE Usuario SET username = :nuevo WHERE username = :actual");
                    $stmtUsuario->execute([
                        ':nuevo' => $email,
                        ':actual' => $username
                    ]);

                    $_SESSION['username'] = $email;
                    $username = $email;
                }
            }

            $pdo->commit();

            $mensaje = 'Datos actualizados correctamente.';
            $tipo_mensaje = 'success';
            $datos = cargarDatosCuenta($pdo, $rol, $username);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = ($e instanceof PDOException)
                ? 'No se pudieron actualizar los datos. Verifica que el DNI o email no esten repetidos.'
                : $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_password') {
    $password_actual = trim($_POST['password_actual'] ?? '');
    $password_nueva = trim($_POST['password_nueva'] ?? '');
    $password_confirm = trim($_POST['password_confirm'] ?? '');

    if ($password_actual === '' || $password_nueva === '' || $password_confirm === '') {
        $mensaje = 'Completa todos los campos de contrasena.';
        $tipo_mensaje = 'error';
    } elseif ($password_nueva !== $password_confirm) {
        $mensaje = 'La nueva contrasena y su confirmacion no coinciden.';
        $tipo_mensaje = 'error';
    } elseif (strlen($password_nueva) < 6) {
        $mensaje = 'La nueva contrasena debe tener al menos 6 caracteres.';
        $tipo_mensaje = 'error';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT password_hash FROM Usuario WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario || !password_verify($password_actual, $usuario['password_hash'])) {
                throw new Exception('La contrasena actual no es correcta.');
            }

            $stmtActualizar = $pdo->prepare("
                UPDATE Usuario
                SET password_hash = :password_hash
                WHERE username = :username
            ");
            $stmtActualizar->execute([
                ':password_hash' => password_hash($password_nueva, PASSWORD_DEFAULT),
                ':username' => $username
            ]);

            $mensaje = 'Contrasena actualizada correctamente.';
            $tipo_mensaje = 'success';
        } catch (Exception $e) {
            $mensaje = ($e instanceof PDOException)
                ? 'No se pudo actualizar la contrasena.'
                : $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

$dni_valor = '';
$nombre_valor = '';
$apellido_valor = '';
$telefono_valor = '';
$email_valor = '';
$direccion_valor = '';
$provincia_valor = '';
$localidad_valor = '';
$referencia = '';

if ($rol === 'CLIENTE') {
    $dni_valor = $datos['cliente_dni'] ?? '';
    $nombre_valor = $datos['cliente_nombre'] ?? '';
    $apellido_valor = $datos['cliente_apellido'] ?? '';
    $telefono_valor = $datos['cliente_telefono'] ?? '';
    $email_valor = $datos['cliente_email'] ?? '';
    $direccion_valor = $datos['cliente_direccion'] ?? '';
    $provincia_valor = $datos['cliente_provincia'] ?? '';
    $localidad_valor = $datos['cliente_localidad'] ?? '';
    $referencia = $dni_valor;
} elseif ($rol === 'CHOFER') {
    $dni_valor = $datos['chofer_dni'] ?? '';
    $nombre_valor = $datos['chofer_nombre'] ?? '';
    $apellido_valor = $datos['chofer_apellido'] ?? '';
    $telefono_valor = $datos['chofer_telefono'] ?? '';
    $email_valor = $datos['chofer_email'] ?? '';
    $referencia = $datos['chofer_legajo'] ?? '';
} elseif ($rol === 'EMPLEADO_SUCURSAL') {
    $dni_valor = $datos['empleado_dni'] ?? '';
    $nombre_valor = $datos['empleado_nombre'] ?? '';
    $apellido_valor = $datos['empleado_apellido'] ?? '';
    $telefono_valor = $datos['empleado_telefono'] ?? '';
    $email_valor = $datos['empleado_email'] ?? '';
    $referencia = $datos['empleado_legajo'] ?? '';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">
    <section class="page-header">
        <h1 class="page-title">Mi cuenta</h1>
        <p class="page-subtitle">Actualiza tus datos personales y tu contrasena de acceso.</p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Datos de acceso</h3>
        <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">
            <div class="form-group">
                <label>Usuario</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($username); ?>" readonly>
            </div>
            <div class="form-group">
                <label>Referencia</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($referencia !== '' ? $referencia : 'Administrador'); ?>" readonly>
            </div>
        </div>
    </section>

    <?php if ($rol !== 'ADMIN'): ?>
        <section class="dashboard-card" style="margin-bottom: 24px;">
            <h3 style="margin-top: 0; margin-bottom: 18px;">Datos personales</h3>

            <form method="POST" action="perfil.php">
                <input type="hidden" name="accion" value="guardar_datos">

                <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">
                    <div>
                        <div class="form-group">
                            <label for="dni">DNI</label>
                            <input type="text" id="dni" name="dni" class="form-control" value="<?php echo htmlspecialchars($dni_valor); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="nombre">Nombre</label>
                            <input type="text" id="nombre" name="nombre" class="form-control" value="<?php echo htmlspecialchars($nombre_valor); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="apellido">Apellido</label>
                            <input type="text" id="apellido" name="apellido" class="form-control" value="<?php echo htmlspecialchars($apellido_valor); ?>" required>
                        </div>
                    </div>

                    <div>
                        <div class="form-group">
                            <label for="telefono">Telefono</label>
                            <input type="text" id="telefono" name="telefono" class="form-control" value="<?php echo htmlspecialchars($telefono_valor); ?>">
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" class="form-control" value="<?php echo htmlspecialchars($email_valor); ?>" <?php echo $rol === 'CLIENTE' ? 'required' : ''; ?>>
                        </div>

                        <?php if ($rol === 'CLIENTE'): ?>
                            <div class="form-group">
                                <label for="direccion">Direccion</label>
                                <input type="text" id="direccion" name="direccion" class="form-control" value="<?php echo htmlspecialchars($direccion_valor); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="localidad_seleccionada">Localidad</label>
                                <select id="localidad_seleccionada" name="localidad_seleccionada" class="form-control" required>
                                    <option value="">Seleccione una localidad</option>
                                    <?php foreach ($localidades as $localidad): ?>
                                        <?php
                                            $valor = $localidad['provincia'] . '||' . $localidad['nombre_localidad'];
                                            $seleccionado = ($provincia_valor === $localidad['provincia'] && $localidad_valor === $localidad['nombre_localidad']);
                                        ?>
                                        <option value="<?php echo htmlspecialchars($valor); ?>" <?php echo $seleccionado ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($localidad['provincia'] . ' - ' . $localidad['nombre_localidad']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="btn-primary" style="width: auto;">Guardar datos</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="dashboard-card">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Cambiar contrasena</h3>

        <form method="POST" action="perfil.php">
            <input type="hidden" name="accion" value="cambiar_password">

            <div class="dashboard-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="form-group">
                    <label for="password_actual">Contrasena actual</label>
                    <input type="password" id="password_actual" name="password_actual" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="password_nueva">Nueva contrasena</label>
                    <input type="password" id="password_nueva" name="password_nueva" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="password_confirm">Confirmar nueva contrasena</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" required>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="width: auto;">Actualizar contrasena</button>
        </form>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
