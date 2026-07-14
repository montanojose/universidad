<?php
require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Usuarios';

$mensaje = '';
$tipo_mensaje = '';

$username = trim($_POST['username'] ?? $_GET['username'] ?? '');
$password = '';
$password_confirm = '';

$cod_rol = trim($_POST['cod_rol'] ?? $_GET['rol'] ?? '');
$legajo_chofer = trim($_POST['legajo_chofer'] ?? $_GET['legajo_chofer'] ?? '');
$legajo_empleado = trim($_POST['legajo_empleado'] ?? $_GET['legajo_empleado'] ?? '');
$dni_cliente = trim($_POST['dni_cliente'] ?? $_GET['dni_cliente'] ?? '');

$usuarios = [];
$choferes_disponibles = [];
$empleados_disponibles = [];
$clientes_disponibles = [];

$roles_validos = ['ADMIN', 'CHOFER', 'CLIENTE', 'EMPLEADO_SUCURSAL'];

function sugerirUsername(string $rol, string $referencia = ''): string
{
    $referencia = preg_replace('/[^a-zA-Z0-9]/', '', $referencia);

    switch ($rol) {
        case 'CHOFER':
            return 'chofer_' . strtolower($referencia);
        case 'CLIENTE':
            return 'cliente_' . strtolower($referencia);
        case 'EMPLEADO_SUCURSAL':
            return 'empleado_' . strtolower($referencia);
        case 'ADMIN':
            return 'admin_' . date('His');
        default:
            return '';
    }
}

try {
    $stmt = $pdo->query("
        SELECT c.legajo, c.dni, c.nombre, c.apellido
        FROM vista_chofer c
        LEFT JOIN Usuario u ON u.dni_persona = c.dni
        WHERE u.dni_persona IS NULL
        ORDER BY c.apellido, c.nombre
    ");
    $choferes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT e.legajo_empleado, e.dni, e.nombre, e.apellido
        FROM vista_empleado_sucursal e
        LEFT JOIN Usuario u ON u.dni_persona = e.dni
        WHERE u.dni_persona IS NULL
        ORDER BY e.apellido, e.nombre
    ");
    $empleados_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT c.dni, c.nombre, c.apellido
        FROM vista_cliente c
        LEFT JOIN Usuario u ON u.dni_persona = c.dni
        WHERE u.dni_persona IS NULL
        ORDER BY c.apellido, c.nombre
    ");
    $clientes_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensaje = 'No se pudieron cargar los datos auxiliares.';
    $tipo_mensaje = 'error';
}

if ($username === '' && in_array($cod_rol, $roles_validos, true)) {
    if ($cod_rol === 'CHOFER' && $legajo_chofer !== '') {
        $username = sugerirUsername($cod_rol, $legajo_chofer);
    } elseif ($cod_rol === 'EMPLEADO_SUCURSAL' && $legajo_empleado !== '') {
        $username = sugerirUsername($cod_rol, $legajo_empleado);
    } elseif ($cod_rol === 'CLIENTE' && $dni_cliente !== '') {
        $username = sugerirUsername($cod_rol, $dni_cliente);
    } elseif ($cod_rol === 'ADMIN') {
        $username = sugerirUsername($cod_rol);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_usuario') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $password_confirm = trim($_POST['password_confirm'] ?? '');
    $cod_rol = trim($_POST['cod_rol'] ?? '');
    $legajo_chofer = trim($_POST['legajo_chofer'] ?? '');
    $legajo_empleado = trim($_POST['legajo_empleado'] ?? '');
    $dni_cliente = trim($_POST['dni_cliente'] ?? '');
    $dni_persona = null;

    if ($username === '' || $password === '' || $password_confirm === '' || $cod_rol === '') {
        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';
    } elseif (!in_array($cod_rol, $roles_validos, true)) {
        $mensaje = 'El rol seleccionado no es válido.';
        $tipo_mensaje = 'error';
    } elseif ($password !== $password_confirm) {
        $mensaje = 'Las contraseñas no coinciden.';
        $tipo_mensaje = 'error';
    } elseif (strlen($password) < 6) {
        $mensaje = 'La contraseña debe tener al menos 6 caracteres.';
        $tipo_mensaje = 'error';
    } else {
        try {
            if ($cod_rol === 'CHOFER') {
                if ($legajo_chofer === '') {
                    throw new Exception('Debés seleccionar un chofer.');
                }
                $stmtPersona = $pdo->prepare("SELECT dni FROM Chofer WHERE legajo = :legajo LIMIT 1");
                $stmtPersona->execute([':legajo' => $legajo_chofer]);
                $dni_persona = $stmtPersona->fetchColumn();
                if (!$dni_persona) {
                    throw new Exception('No se encontró el chofer seleccionado.');
                }
            } elseif ($cod_rol === 'EMPLEADO_SUCURSAL') {
                if ($legajo_empleado === '') {
                    throw new Exception('Debés seleccionar un empleado.');
                }
                $stmtPersona = $pdo->prepare("SELECT dni FROM Empleado_Sucursal WHERE legajo_empleado = :legajo LIMIT 1");
                $stmtPersona->execute([':legajo' => $legajo_empleado]);
                $dni_persona = $stmtPersona->fetchColumn();
                if (!$dni_persona) {
                    throw new Exception('No se encontró el empleado seleccionado.');
                }
            } elseif ($cod_rol === 'CLIENTE') {
                if ($dni_cliente === '') {
                    throw new Exception('Debés seleccionar un cliente.');
                }
                $dni_persona = $dni_cliente;
            } elseif ($cod_rol === 'ADMIN') {
                $dni_persona = null;
            }

            $stmt = $pdo->prepare("SELECT username FROM Usuario WHERE username = :username LIMIT 1");
            $stmt->execute([':username' => $username]);
            if ($stmt->fetch()) {
                throw new Exception('Ese nombre de usuario ya existe.');
            }

            if ($cod_rol === 'CHOFER') {
                $stmt = $pdo->prepare("SELECT username FROM Usuario WHERE dni_persona = :dni_persona LIMIT 1");
                $stmt->execute([':dni_persona' => $dni_persona]);
                if ($stmt->fetch()) {
                    throw new Exception('Ese chofer ya tiene usuario creado.');
                }
            }

            if ($cod_rol === 'EMPLEADO_SUCURSAL') {
                $stmt = $pdo->prepare("SELECT username FROM Usuario WHERE dni_persona = :dni_persona LIMIT 1");
                $stmt->execute([':dni_persona' => $dni_persona]);
                if ($stmt->fetch()) {
                    throw new Exception('Ese empleado ya tiene usuario creado.');
                }
            }

            if ($cod_rol === 'CLIENTE') {
                $stmt = $pdo->prepare("SELECT username FROM Usuario WHERE dni_persona = :dni_persona LIMIT 1");
                $stmt->execute([':dni_persona' => $dni_persona]);
                if ($stmt->fetch()) {
                    throw new Exception('Ese cliente ya tiene usuario creado.');
                }
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "
                INSERT INTO Usuario (
                    username,
                    password_hash,
                    cod_rol,
                    dni_persona,
                    activo
                ) VALUES (
                    :username,
                    :password_hash,
                    :cod_rol,
                    :dni_persona,
                    1
                )
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => $password_hash,
                ':cod_rol' => $cod_rol,
                ':dni_persona' => $dni_persona
            ]);

            $mensaje = 'Usuario creado correctamente.';
            $tipo_mensaje = 'success';

            $username = '';
            $password = '';
            $password_confirm = '';
            $cod_rol = '';
            $legajo_chofer = '';
            $legajo_empleado = '';
            $dni_cliente = '';

        } catch (Exception $e) {
            $mensaje = $e->getMessage();
            $tipo_mensaje = 'error';
        } catch (PDOException $e) {
            $mensaje = 'Ocurrió un error al crear el usuario.';
            $tipo_mensaje = 'error';
        }
    }
}

try {
    $sqlUsuarios = "
        SELECT
            u.username,
            u.cod_rol,
            u.activo,
            u.created_at,
            u.dni_persona,
            ch.legajo AS legajo_chofer,
            emp.legajo_empleado,
            cli.dni AS dni_cliente,
            CONCAT(ch.apellido, ', ', ch.nombre) AS nombre_chofer,
            CONCAT(emp.apellido, ', ', emp.nombre) AS nombre_empleado,
            CONCAT(cli.apellido, ', ', cli.nombre) AS nombre_cliente
        FROM Usuario u
        LEFT JOIN vista_chofer ch ON u.dni_persona = ch.dni
        LEFT JOIN vista_empleado_sucursal emp ON u.dni_persona = emp.dni
        LEFT JOIN vista_cliente cli ON u.dni_persona = cli.dni
        ORDER BY u.created_at DESC, u.username ASC
    ";
    $stmt = $pdo->query($sqlUsuarios);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = 'No se pudieron cargar los usuarios.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">
    <section class="page-header">
        <h1 class="page-title">Gestión de Usuarios</h1>
        <p class="page-subtitle">
            Desde aquí el administrador crea los accesos al sistema para empleados, choferes, clientes y administradores.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Crear nuevo usuario</h3>

        <form method="POST" action="usuarios.php">
            <input type="hidden" name="accion" value="crear_usuario">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">
                <div>
                    <div class="form-group">
                        <label for="cod_rol">Rol</label>
                        <select name="cod_rol" id="cod_rol" class="form-control" required>
                            <option value="">Seleccione un rol</option>
                            <option value="ADMIN" <?php echo $cod_rol === 'ADMIN' ? 'selected' : ''; ?>>Administrador</option>
                            <option value="CHOFER" <?php echo $cod_rol === 'CHOFER' ? 'selected' : ''; ?>>Chofer</option>
                            <option value="EMPLEADO_SUCURSAL" <?php echo $cod_rol === 'EMPLEADO_SUCURSAL' ? 'selected' : ''; ?>>Empleado Sucursal</option>
                            <option value="CLIENTE" <?php echo $cod_rol === 'CLIENTE' ? 'selected' : ''; ?>>Cliente</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="username">Usuario</label>
                        <input
                            type="text"
                            name="username"
                            id="username"
                            class="form-control"
                            value="<?php echo htmlspecialchars($username); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="password">Contraseña</label>
                        <input
                            type="password"
                            name="password"
                            id="password"
                            class="form-control"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="password_confirm">Confirmar contraseña</label>
                        <input
                            type="password"
                            name="password_confirm"
                            id="password_confirm"
                            class="form-control"
                            required
                        >
                    </div>
                </div>

                <div>
                    <div class="form-group">
                        <label for="legajo_empleado">Empleado</label>
                        <select name="legajo_empleado" id="legajo_empleado" class="form-control">
                            <option value="">No aplica</option>
                            <?php foreach ($empleados_disponibles as $emp): ?>
                                <option value="<?php echo htmlspecialchars($emp['legajo_empleado']); ?>" <?php echo $legajo_empleado === $emp['legajo_empleado'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['legajo_empleado'] . ' - ' . $emp['apellido'] . ', ' . $emp['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="legajo_chofer">Chofer</label>
                        <select name="legajo_chofer" id="legajo_chofer" class="form-control">
                            <option value="">No aplica</option>
                            <?php foreach ($choferes_disponibles as $chofer): ?>
                                <option value="<?php echo htmlspecialchars($chofer['legajo']); ?>" <?php echo $legajo_chofer === $chofer['legajo'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($chofer['legajo'] . ' - ' . $chofer['apellido'] . ', ' . $chofer['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="dni_cliente">Cliente</label>
                        <select name="dni_cliente" id="dni_cliente" class="form-control">
                            <option value="">No aplica</option>
                            <?php foreach ($clientes_disponibles as $cli): ?>
                                <option value="<?php echo htmlspecialchars($cli['dni']); ?>" <?php echo $dni_cliente === $cli['dni'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cli['dni'] . ' - ' . $cli['apellido'] . ', ' . $cli['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-top: 30px;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Crear usuario
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </section>

    <section class="dashboard-card">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de usuarios</h3>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 1100px;">
                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px;">Usuario</th>
                        <th style="text-align: left; padding: 12px;">Rol</th>
                        <th style="text-align: left; padding: 12px;">Relacionado con</th>
                        <th style="text-align: left; padding: 12px;">Referencia</th>
                        <th style="text-align: left; padding: 12px;">Activo</th>
                        <th style="text-align: left; padding: 12px;">Creado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr>
                            <td colspan="6" style="padding: 16px; text-align: center;">
                                No hay usuarios registrados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($usuario['username']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($usuario['cod_rol']); ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php
                                    if ($usuario['cod_rol'] === 'CHOFER') {
                                        echo htmlspecialchars($usuario['nombre_chofer'] ?? 'Chofer');
                                    } elseif ($usuario['cod_rol'] === 'EMPLEADO_SUCURSAL') {
                                        echo htmlspecialchars($usuario['nombre_empleado'] ?? 'Empleado');
                                    } elseif ($usuario['cod_rol'] === 'CLIENTE') {
                                        echo htmlspecialchars($usuario['nombre_cliente'] ?? 'Cliente');
                                    } else {
                                        echo 'Administrador';
                                    }
                                    ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php
                                    echo htmlspecialchars(
                                        $usuario['legajo_chofer']
                                        ?? $usuario['legajo_empleado']
                                        ?? $usuario['dni_cliente']
                                        ?? '-'
                                    );
                                    ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo ((int)$usuario['activo'] === 1) ? 'Sí' : 'No'; ?>
                                </td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($usuario['created_at']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
