<?php
require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Empleados';

$mensaje = '';
$tipo_mensaje = '';

$empleados = [];
$sucursales = [];

$modo_edicion = false;

$legajo_empleado = '';
$dni = '';
$nombre = '';
$apellido = '';
$telefono = '';
$email = '';
$fecha_ingreso = '';
$estado = 'ACTIVO';
$cod_sucursal = '';

$buscar = trim($_GET['buscar'] ?? '');
$filtro_sucursal = trim($_GET['filtro_sucursal'] ?? '');
$filtro_estado = trim($_GET['filtro_estado'] ?? '');

function generarLegajoEmpleado(PDO $pdo): string
{
    $sql = "SELECT legajo_empleado FROM Empleado_Sucursal";
    $stmt = $pdo->query($sql);
    $legajos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maximo = 0;

    foreach ($legajos as $legajo_actual) {
        if (preg_match('/(\d+)$/', (string)$legajo_actual, $coincidencias)) {
            $numero = (int)$coincidencias[1];
            if ($numero > $maximo) {
                $maximo = $numero;
            }
        }
    }

    $siguiente = $maximo + 1;
    return 'EMP' . str_pad((string)$siguiente, 3, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    $legajo_eliminar = trim($_POST['legajo_empleado'] ?? '');

    if ($legajo_eliminar === '') {
        $mensaje = 'No se recibió el empleado a eliminar.';
        $tipo_mensaje = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            $sqlEliminarUsuario = "
                DELETE u
                FROM Usuario u
                INNER JOIN vista_empleado_sucursal e
                    ON u.dni_persona = e.dni
                WHERE e.legajo_empleado = :legajo_empleado
            ";
            $stmtEliminarUsuario = $pdo->prepare($sqlEliminarUsuario);
            $stmtEliminarUsuario->execute([
                ':legajo_empleado' => $legajo_eliminar
            ]);

            $sqlEliminar = "DELETE FROM Empleado_Sucursal WHERE legajo_empleado = :legajo_empleado";
            $stmtEliminar = $pdo->prepare($sqlEliminar);
            $stmtEliminar->execute([
                ':legajo_empleado' => $legajo_eliminar
            ]);

            if ($stmtEliminar->rowCount() > 0) {
                $pdo->commit();
                $mensaje = 'Empleado eliminado correctamente.';
                $tipo_mensaje = 'success';
            } else {
                $pdo->rollBack();
                $mensaje = 'No se encontró el empleado seleccionado.';
                $tipo_mensaje = 'warning';
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = 'No se puede eliminar el empleado porque está relacionado con otros registros.';
            $tipo_mensaje = 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    $modo_formulario = trim($_POST['modo_formulario'] ?? 'alta');
    $modo_edicion = ($modo_formulario === 'edicion');

    $legajo_empleado = trim($_POST['legajo_empleado'] ?? '');
    $dni = trim($_POST['dni'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $apellido = trim($_POST['apellido'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $fecha_ingreso = trim($_POST['fecha_ingreso'] ?? '');
    $estado = trim($_POST['estado'] ?? 'ACTIVO');
    $cod_sucursal = trim($_POST['cod_sucursal'] ?? '');

    if (
        $legajo_empleado === '' ||
        $dni === '' ||
        $nombre === '' ||
        $apellido === '' ||
        $fecha_ingreso === '' ||
        $estado === '' ||
        $cod_sucursal === ''
    ) {
        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';
    } elseif (!$modo_edicion && $email === '') {
        $mensaje = 'Ingresa un email para crear el usuario del empleado.';
        $tipo_mensaje = 'error';
    } elseif (!in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
        $mensaje = 'El estado seleccionado no es válido.';
        $tipo_mensaje = 'error';
    } else {
        try {
            if ($modo_edicion) {
                $stmtDniActual = $pdo->prepare("SELECT dni FROM Empleado_Sucursal WHERE legajo_empleado = :legajo_empleado LIMIT 1");
                $stmtDniActual->execute([':legajo_empleado' => $legajo_empleado]);
                $dni_actual = $stmtDniActual->fetchColumn();

                if (!$dni_actual) {
                    throw new Exception('No se encontrÃ³ el empleado seleccionado.');
                }

                $sqlActualizarPersona = "
                    UPDATE Persona
                    SET
                        dni = :dni,
                        nombre = :nombre,
                        apellido = :apellido,
                        telefono = :telefono,
                        email = :email
                    WHERE dni = :dni_actual
                ";

                $stmtActualizarPersona = $pdo->prepare($sqlActualizarPersona);
                $stmtActualizarPersona->execute([
                    ':dni' => $dni,
                    ':nombre' => $nombre,
                    ':apellido' => $apellido,
                    ':telefono' => ($telefono !== '' ? $telefono : null),
                    ':email' => ($email !== '' ? $email : null),
                    ':dni_actual' => $dni_actual
                ]);

                $sqlActualizar = "
                    UPDATE Empleado_Sucursal
                    SET
                        fecha_ingreso = :fecha_ingreso,
                        estado = :estado,
                        cod_sucursal = :cod_sucursal
                    WHERE legajo_empleado = :legajo_empleado
                ";

                $stmtActualizar = $pdo->prepare($sqlActualizar);
                $stmtActualizar->execute([
                    ':fecha_ingreso' => $fecha_ingreso,
                    ':estado' => $estado,
                    ':cod_sucursal' => $cod_sucursal,
                    ':legajo_empleado' => $legajo_empleado
                ]);

                $mensaje = 'Empleado actualizado correctamente.';
                $tipo_mensaje = 'success';

                $modo_edicion = false;
                $legajo_empleado = '';
                $dni = '';
                $nombre = '';
                $apellido = '';
                $telefono = '';
                $email = '';
                $fecha_ingreso = '';
                $estado = 'ACTIVO';
                $cod_sucursal = '';
            } else {
                $pdo->beginTransaction();

                $stmtExisteUsuario = $pdo->prepare("SELECT username FROM Usuario WHERE username = :username LIMIT 1");
                $stmtExisteUsuario->execute([
                    ':username' => $email
                ]);

                if ($stmtExisteUsuario->fetch()) {
                    throw new Exception('Ya existe un usuario con ese email.');
                }

                $stmtExisteEmpleadoUsuario = $pdo->prepare("SELECT username FROM Usuario WHERE dni_persona = :dni LIMIT 1");
                $stmtExisteEmpleadoUsuario->execute([
                    ':dni' => $dni
                ]);

                if ($stmtExisteEmpleadoUsuario->fetch()) {
                    throw new Exception('Ese empleado ya tiene usuario creado.');
                }

                $sqlInsertar = "
                    INSERT INTO Persona (
                        dni,
                        nombre,
                        apellido,
                        telefono,
                        email
                    )
                    VALUES (
                        :dni,
                        :nombre,
                        :apellido,
                        :telefono,
                        :email
                    )
                    ON DUPLICATE KEY UPDATE
                        nombre = VALUES(nombre),
                        apellido = VALUES(apellido),
                        telefono = VALUES(telefono),
                        email = VALUES(email)
                ";

                $stmtInsertar = $pdo->prepare($sqlInsertar);
                $stmtInsertar->execute([
                    ':dni' => $dni,
                    ':nombre' => $nombre,
                    ':apellido' => $apellido,
                    ':telefono' => ($telefono !== '' ? $telefono : null),
                    ':email' => ($email !== '' ? $email : null)
                ]);

                $sqlInsertar = "
                    INSERT INTO Empleado_Sucursal (
                        legajo_empleado,
                        dni,
                        fecha_ingreso,
                        estado,
                        cod_sucursal
                    )
                    VALUES (
                        :legajo_empleado,
                        :dni,
                        :fecha_ingreso,
                        :estado,
                        :cod_sucursal
                    )
                ";

                $stmtInsertar = $pdo->prepare($sqlInsertar);
                $stmtInsertar->execute([
                    ':legajo_empleado' => $legajo_empleado,
                    ':dni' => $dni,
                    ':fecha_ingreso' => $fecha_ingreso,
                    ':estado' => $estado,
                    ':cod_sucursal' => $cod_sucursal
                ]);

                $sqlInsertarUsuario = "
                    INSERT INTO Usuario (
                        username,
                        password_hash,
                        cod_rol,
                        dni_persona,
                        activo
                    ) VALUES (
                        :username,
                        :password_hash,
                        'EMPLEADO_SUCURSAL',
                        :dni_persona,
                        1
                    )
                ";

                $stmtInsertarUsuario = $pdo->prepare($sqlInsertarUsuario);
                $stmtInsertarUsuario->execute([
                    ':username' => $email,
                    ':password_hash' => password_hash($dni, PASSWORD_DEFAULT),
                    ':dni_persona' => $dni
                ]);

                $pdo->commit();

                $mensaje = 'Empleado registrado correctamente. Usuario: ' . $email . ' | Contrasena inicial: ' . $dni;
                $tipo_mensaje = 'success';

                $legajo_empleado = '';
                $dni = '';
                $nombre = '';
                $apellido = '';
                $telefono = '';
                $email = '';
                $fecha_ingreso = '';
                $estado = 'ACTIVO';
                $cod_sucursal = '';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = ($e instanceof PDOException)
                ? 'Ocurrio un error al guardar el empleado. Verifica que el DNI o el email no esten repetidos.'
                : $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar'])) {
    $legajo_editar = trim($_GET['editar']);

    if ($legajo_editar !== '') {
        try {
            $sqlEditar = "
                SELECT
                    legajo_empleado,
                    dni,
                    nombre,
                    apellido,
                    telefono,
                    email,
                    fecha_ingreso,
                    estado,
                    cod_sucursal
                FROM vista_empleado_sucursal
                WHERE legajo_empleado = :legajo_empleado
                LIMIT 1
            ";

            $stmtEditar = $pdo->prepare($sqlEditar);
            $stmtEditar->execute([
                ':legajo_empleado' => $legajo_editar
            ]);

            $filaEditar = $stmtEditar->fetch(PDO::FETCH_ASSOC);

            if ($filaEditar) {
                $modo_edicion = true;
                $legajo_empleado = $filaEditar['legajo_empleado'];
                $dni = $filaEditar['dni'];
                $nombre = $filaEditar['nombre'];
                $apellido = $filaEditar['apellido'];
                $telefono = $filaEditar['telefono'] ?? '';
                $email = $filaEditar['email'] ?? '';
                $fecha_ingreso = $filaEditar['fecha_ingreso'];
                $estado = $filaEditar['estado'];
                $cod_sucursal = $filaEditar['cod_sucursal'];
            } else {
                $mensaje = 'No se encontró el empleado seleccionado.';
                $tipo_mensaje = 'warning';
            }
        } catch (PDOException $e) {
            $mensaje = 'No se pudo cargar el empleado para edición.';
            $tipo_mensaje = 'error';
        }
    }
}

try {
    $sqlSucursales = "
        SELECT cod_sucursal, nombre
        FROM Sucursal
        ORDER BY nombre ASC
    ";
    $stmtSucursales = $pdo->query($sqlSucursales);
    $sucursales = $stmtSucursales->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = 'No se pudieron cargar las sucursales.';
    $tipo_mensaje = 'error';
}

if (!$modo_edicion && $legajo_empleado === '') {
    try {
        $legajo_empleado = generarLegajoEmpleado($pdo);
    } catch (PDOException $e) {
        $legajo_empleado = 'EMP001';
    }
}

if (!$modo_edicion && $fecha_ingreso === '') {
    $fecha_ingreso = date('Y-m-d');
}

try {
    $sqlListado = "
        SELECT
            e.legajo_empleado,
            e.dni,
            e.nombre,
            e.apellido,
            e.telefono,
            e.email,
            e.fecha_ingreso,
            e.estado,
            e.cod_sucursal,
            s.nombre AS nombre_sucursal,
            u.username AS username_usuario
        FROM vista_empleado_sucursal e
        INNER JOIN Sucursal s
            ON e.cod_sucursal = s.cod_sucursal
        LEFT JOIN Usuario u
            ON u.dni_persona = e.dni
        WHERE 1 = 1
    ";

    $params = [];

    if ($buscar !== '') {
        $sqlListado .= "
            AND (
                e.legajo_empleado LIKE :buscar
                OR e.nombre LIKE :buscar
                OR e.apellido LIKE :buscar
                OR e.dni LIKE :buscar
                OR e.email LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if ($filtro_sucursal !== '') {
        $sqlListado .= " AND e.cod_sucursal = :filtro_sucursal ";
        $params[':filtro_sucursal'] = $filtro_sucursal;
    }

    if ($filtro_estado !== '') {
        $sqlListado .= " AND e.estado = :filtro_estado ";
        $params[':filtro_estado'] = $filtro_estado;
    }

    $sqlListado .= " ORDER BY e.apellido ASC, e.nombre ASC ";

    $stmtListado = $pdo->prepare($sqlListado);
    $stmtListado->execute($params);
    $empleados = $stmtListado->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensaje = 'Ocurrió un error al consultar los empleados.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">
    <section class="page-header">
        <h1 class="page-title">Gestión de Empleados</h1>
        <p class="page-subtitle">
            En esta pantalla podés registrar, editar, consultar y eliminar empleados de sucursal del sistema.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $modo_edicion ? 'Editar empleado' : 'Registrar nuevo empleado'; ?>
        </h3>

        <form method="POST" action="empleados.php">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="modo_formulario" value="<?php echo $modo_edicion ? 'edicion' : 'alta'; ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">
                <div>
                    <div class="form-group">
                        <label for="legajo_empleado">Legajo</label>
                        <input
                            type="text"
                            id="legajo_empleado"
                            name="legajo_empleado"
                            class="form-control"
                            value="<?php echo htmlspecialchars($legajo_empleado); ?>"
                            readonly
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="dni">DNI</label>
                        <input
                            type="text"
                            id="dni"
                            name="dni"
                            class="form-control"
                            value="<?php echo htmlspecialchars($dni); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="nombre">Nombre</label>
                        <input
                            type="text"
                            id="nombre"
                            name="nombre"
                            class="form-control"
                            value="<?php echo htmlspecialchars($nombre); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="apellido">Apellido</label>
                        <input
                            type="text"
                            id="apellido"
                            name="apellido"
                            class="form-control"
                            value="<?php echo htmlspecialchars($apellido); ?>"
                            required
                        >
                    </div>
                </div>

                <div>
                    <div class="form-group">
                        <label for="telefono">Teléfono</label>
                        <input
                            type="text"
                            id="telefono"
                            name="telefono"
                            class="form-control"
                            value="<?php echo htmlspecialchars($telefono); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            value="<?php echo htmlspecialchars($email); ?>"
                            <?php echo !$modo_edicion ? 'required' : ''; ?>
                        >
                    </div>

                    <div class="form-group">
                        <label for="fecha_ingreso">Fecha de ingreso</label>
                        <input
                            type="date"
                            id="fecha_ingreso"
                            name="fecha_ingreso"
                            class="form-control"
                            value="<?php echo htmlspecialchars($fecha_ingreso); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="estado">Estado</label>
                        <select id="estado" name="estado" class="form-control" required>
                            <option value="ACTIVO" <?php echo $estado === 'ACTIVO' ? 'selected' : ''; ?>>ACTIVO</option>
                            <option value="INACTIVO" <?php echo $estado === 'INACTIVO' ? 'selected' : ''; ?>>INACTIVO</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cod_sucursal">Sucursal</label>
                        <select id="cod_sucursal" name="cod_sucursal" class="form-control" required>
                            <option value="">Seleccione una sucursal</option>
                            <?php foreach ($sucursales as $sucursal): ?>
                                <option
                                    value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                    <?php echo ($cod_sucursal === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($sucursal['cod_sucursal'] . ' - ' . $sucursal['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar empleado'; ?>
                        </button>

                        <?php if ($modo_edicion): ?>
                            <a href="empleados.php" class="btn-public-secondary">Cancelar edición</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </section>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar empleados</h3>

        <form method="GET" action="empleados.php">
            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr;">
                <div class="form-group">
                    <label for="buscar">Buscar por legajo, nombre, apellido, DNI o email</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: EMP001, Gómez, 30111222, correo@mail.com"
                    >
                </div>

                <div class="form-group">
                    <label for="filtro_sucursal">Filtrar por sucursal</label>
                    <select id="filtro_sucursal" name="filtro_sucursal" class="form-control">
                        <option value="">Todas las sucursales</option>
                        <?php foreach ($sucursales as $sucursal): ?>
                            <option
                                value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                <?php echo ($filtro_sucursal === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($sucursal['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="filtro_estado">Filtrar por estado</label>
                    <select id="filtro_estado" name="filtro_estado" class="form-control">
                        <option value="">Todos</option>
                        <option value="ACTIVO" <?php echo $filtro_estado === 'ACTIVO' ? 'selected' : ''; ?>>ACTIVO</option>
                        <option value="INACTIVO" <?php echo $filtro_estado === 'INACTIVO' ? 'selected' : ''; ?>>INACTIVO</option>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">Buscar</button>
                    <a href="empleados.php" class="btn-public-secondary">Limpiar</a>
                </div>
            </div>
        </form>
    </section>

    <section class="dashboard-card">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de empleados</h3>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; min-width: 1300px;">
                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Legajo</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">DNI</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Nombre</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Apellido</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Teléfono</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Email</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha ingreso</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Sucursal</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Usuario</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($empleados)): ?>
                        <tr>
                            <td colspan="11" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay empleados registrados con esos criterios.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($empleados as $empleado): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['legajo_empleado']); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['dni']); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['nombre']); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['apellido']); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['telefono'] ?? ''); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['email'] ?? ''); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['fecha_ingreso']); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['estado']); ?></td>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($empleado['nombre_sucursal']); ?></td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php if (!empty($empleado['username_usuario'])): ?>
                                        <?php echo htmlspecialchars($empleado['username_usuario']); ?>
                                    <?php else: ?>
                                        <span style="color: #a36a00;">Sin usuario</span>
                                    <?php endif; ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <?php if (empty($empleado['username_usuario'])): ?>
                                        <a
                                            href="usuarios.php?rol=EMPLEADO_SUCURSAL&legajo_empleado=<?php echo urlencode($empleado['legajo_empleado']); ?>"
                                            class="btn-public-secondary"
                                            style="margin-right: 8px;"
                                        >
                                            Crear usuario
                                        </a>
                                    <?php endif; ?>

                                    <a
                                        href="empleados.php?editar=<?php echo urlencode($empleado['legajo_empleado']); ?>&buscar=<?php echo urlencode($buscar); ?>&filtro_sucursal=<?php echo urlencode($filtro_sucursal); ?>&filtro_estado=<?php echo urlencode($filtro_estado); ?>"
                                        class="btn-public-secondary"
                                        style="margin-right: 8px;"
                                    >
                                        Editar
                                    </a>

                                    <form method="POST" action="empleados.php" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar este empleado?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="legajo_empleado" value="<?php echo htmlspecialchars($empleado['legajo_empleado']); ?>">
                                        <button type="submit" class="btn-public-secondary" style="border-color: #f0b6b6; color: #a32626;">
                                            Eliminar
                                        </button>
                                    </form>
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
