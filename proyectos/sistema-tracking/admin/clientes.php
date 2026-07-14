<?php
// =====================================================
// clientes.php
// CRUD funcional de clientes
// - alta
// - edición
// - eliminación física
// - buscador y filtro por localidad
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Clientes';

$mensaje = '';
$tipo_mensaje = '';

$clientes = [];
$localidades = [];

$modo_edicion = false;

$dni_original = '';
$dni = '';
$nombre = '';
$apellido = '';
$telefono = '';
$email = '';
$direccion = '';
$provincia = '';
$nombre_localidad = '';

$buscar = trim($_GET['buscar'] ?? '');
$filtro_localidad = trim($_GET['filtro_localidad'] ?? '');


// -----------------------------------------------------
// 1. ELIMINAR CLIENTE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    $dni_eliminar = trim($_POST['dni'] ?? '');

    if ($dni_eliminar === '') {

        $mensaje = 'No se recibió el cliente a eliminar.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEliminar = "
                DELETE FROM Cliente
                WHERE dni = :dni
            ";

            $stmtEliminar = $pdo->prepare($sqlEliminar);

            $stmtEliminar->execute([
                ':dni' => $dni_eliminar
            ]);

            if ($stmtEliminar->rowCount() > 0) {
                $mensaje = 'Cliente eliminado correctamente.';
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'No se encontró el cliente seleccionado.';
                $tipo_mensaje = 'warning';
            }

        } catch (PDOException $e) {

            $mensaje = 'No se puede eliminar el cliente porque está siendo utilizado por otros registros del sistema.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 2. ALTA O EDICIÓN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {

    $modo_formulario = trim($_POST['modo_formulario'] ?? 'alta');
    $modo_edicion = ($modo_formulario === 'edicion');

    $dni_original = trim($_POST['dni_original'] ?? '');
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
        $dni === '' ||
        $nombre === '' ||
        $apellido === '' ||
        $direccion === '' ||
        $provincia === '' ||
        $nombre_localidad === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } else {

        try {

            if ($modo_edicion) {

                $sqlActualizarPersona = "
                    UPDATE Persona
                    SET
                        dni = :dni,
                        nombre = :nombre,
                        apellido = :apellido,
                        telefono = :telefono,
                        email = :email
                    WHERE dni = :dni_original
                ";

                $stmtActualizarPersona = $pdo->prepare($sqlActualizarPersona);

                $stmtActualizarPersona->execute([
                    ':dni' => $dni,
                    ':nombre' => $nombre,
                    ':apellido' => $apellido,
                    ':telefono' => ($telefono !== '' ? $telefono : null),
                    ':email' => ($email !== '' ? $email : null),
                    ':dni_original' => $dni_original
                ]);

                $sqlActualizar = "
                    UPDATE Cliente
                    SET
                        direccion = :direccion,
                        provincia = :provincia,
                        nombre_localidad = :nombre_localidad
                    WHERE dni = :dni
                ";

                $stmtActualizar = $pdo->prepare($sqlActualizar);

                $stmtActualizar->execute([
                    ':direccion' => $direccion,
                    ':provincia' => $provincia,
                    ':nombre_localidad' => $nombre_localidad,
                    ':dni' => $dni
                ]);

                $mensaje = 'Cliente actualizado correctamente.';
                $tipo_mensaje = 'success';

                $modo_edicion = false;
                $dni_original = '';
                $dni = '';
                $nombre = '';
                $apellido = '';
                $telefono = '';
                $email = '';
                $direccion = '';
                $provincia = '';
                $nombre_localidad = '';

            } else {

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
                    INSERT INTO Cliente (
                        dni,
                        direccion,
                        provincia,
                        nombre_localidad
                    )
                    VALUES (
                        :dni,
                        :direccion,
                        :provincia,
                        :nombre_localidad
                    )
                ";

                $stmtInsertar = $pdo->prepare($sqlInsertar);

                $stmtInsertar->execute([
                    ':dni' => $dni,
                    ':direccion' => $direccion,
                    ':provincia' => $provincia,
                    ':nombre_localidad' => $nombre_localidad
                ]);

                $mensaje = 'Cliente registrado correctamente.';
                $tipo_mensaje = 'success';

                $dni_original = '';
                $dni = '';
                $nombre = '';
                $apellido = '';
                $telefono = '';
                $email = '';
                $direccion = '';
                $provincia = '';
                $nombre_localidad = '';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al guardar el cliente. Verificá que el DNI no esté repetido.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR CLIENTE PARA EDICIÓN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar'])) {

    $dni_editar = trim($_GET['editar']);

    if ($dni_editar !== '') {

        try {

            $sqlEditar = "
                SELECT
                    dni,
                    nombre,
                    apellido,
                    telefono,
                    email,
                    direccion,
                    provincia,
                    nombre_localidad
                FROM vista_cliente
                WHERE dni = :dni
                LIMIT 1
            ";

            $stmtEditar = $pdo->prepare($sqlEditar);

            $stmtEditar->execute([
                ':dni' => $dni_editar
            ]);

            $filaEditar = $stmtEditar->fetch();

            if ($filaEditar) {
                $modo_edicion = true;
                $dni_original = $filaEditar['dni'];
                $dni = $filaEditar['dni'];
                $nombre = $filaEditar['nombre'];
                $apellido = $filaEditar['apellido'];
                $telefono = $filaEditar['telefono'] ?? '';
                $email = $filaEditar['email'] ?? '';
                $direccion = $filaEditar['direccion'];
                $provincia = $filaEditar['provincia'];
                $nombre_localidad = $filaEditar['nombre_localidad'];
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo cargar el cliente para edición.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR LOCALIDADES
// -----------------------------------------------------

try {

    $sqlLocalidades = "
        SELECT
            provincia,
            nombre_localidad
        FROM Localidad
        ORDER BY provincia ASC, nombre_localidad ASC
    ";

    $stmtLocalidades = $pdo->query($sqlLocalidades);
    $localidades = $stmtLocalidades->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar las localidades.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 5. LISTADO CON BÚSQUEDA Y FILTRO
// -----------------------------------------------------

try {

    $sqlListado = "
        SELECT
            dni,
            nombre,
            apellido,
            telefono,
            email,
            direccion,
            provincia,
            nombre_localidad
        FROM vista_cliente
        WHERE 1 = 1
    ";

    $params = [];

    if ($buscar !== '') {
        $sqlListado .= "
            AND (
                dni LIKE :buscar
                OR nombre LIKE :buscar
                OR apellido LIKE :buscar
                OR email LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if ($filtro_localidad !== '') {

        $partesFiltro = explode('||', $filtro_localidad);

        if (count($partesFiltro) === 2) {
            $sqlListado .= "
                AND provincia = :provincia_filtro
                AND nombre_localidad = :localidad_filtro
            ";
            $params[':provincia_filtro'] = $partesFiltro[0];
            $params[':localidad_filtro'] = $partesFiltro[1];
        }
    }

    $sqlListado .= " ORDER BY apellido ASC, nombre ASC ";

    $stmtListado = $pdo->prepare($sqlListado);
    $stmtListado->execute($params);

    $clientes = $stmtListado->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al consultar los clientes.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">

        <h1 class="page-title">Gestión de Clientes</h1>

        <p class="page-subtitle">
            En esta pantalla podés registrar, editar, consultar y eliminar clientes del sistema.
        </p>

    </section>


    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $modo_edicion ? 'Editar cliente' : 'Registrar nuevo cliente'; ?>
        </h3>

        <form method="POST" action="clientes.php">

            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="modo_formulario" value="<?php echo $modo_edicion ? 'edicion' : 'alta'; ?>">
            <input type="hidden" name="dni_original" value="<?php echo htmlspecialchars($dni_original); ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                <div>

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

                </div>

                <div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            value="<?php echo htmlspecialchars($email); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="direccion">Dirección</label>
                        <input
                            type="text"
                            id="direccion"
                            name="direccion"
                            class="form-control"
                            value="<?php echo htmlspecialchars($direccion); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="localidad_seleccionada">Localidad</label>
                        <select id="localidad_seleccionada" name="localidad_seleccionada" class="form-control" required>
                            <option value="">Seleccione una localidad</option>

                            <?php foreach ($localidades as $localidad): ?>
                                <?php
                                    $valor_localidad = $localidad['provincia'] . '||' . $localidad['nombre_localidad'];
                                    $selected_localidad = ($provincia === $localidad['provincia'] && $nombre_localidad === $localidad['nombre_localidad']) ? 'selected' : '';
                                ?>
                                <option value="<?php echo htmlspecialchars($valor_localidad); ?>" <?php echo $selected_localidad; ?>>
                                    <?php echo htmlspecialchars($localidad['provincia'] . ' - ' . $localidad['nombre_localidad']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar cliente'; ?>
                        </button>

                        <?php if ($modo_edicion): ?>
                            <a href="clientes.php" class="btn-public-secondary">
                                Cancelar edición
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar clientes</h3>

        <form method="GET" action="clientes.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr 1fr;">

                <div class="form-group">
                    <label for="buscar">Buscar por DNI, nombre, apellido o email</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: 30111222, Juan, Pérez..."
                    >
                </div>

                <div class="form-group">
                    <label for="filtro_localidad">Filtrar por localidad</label>
                    <select id="filtro_localidad" name="filtro_localidad" class="form-control">
                        <option value="">Todas las localidades</option>

                        <?php foreach ($localidades as $localidad): ?>
                            <?php
                                $valor_filtro = $localidad['provincia'] . '||' . $localidad['nombre_localidad'];
                            ?>
                            <option
                                value="<?php echo htmlspecialchars($valor_filtro); ?>"
                                <?php echo ($filtro_localidad === $valor_filtro) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($localidad['provincia'] . ' - ' . $localidad['nombre_localidad']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="clientes.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de clientes</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1200px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">DNI</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Nombre</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Apellido</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Teléfono</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Email</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Dirección</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Provincia</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Localidad</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($clientes)): ?>

                        <tr>
                            <td colspan="9" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay clientes registrados con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($clientes as $cliente): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($cliente['dni']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($cliente['nombre']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($cliente['apellido']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($cliente['telefono'] ?? ''); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($cliente['email'] ?? ''); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($cliente['direccion']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($cliente['provincia']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($cliente['nombre_localidad']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">

                                    <a href="clientes.php?editar=<?php echo urlencode($cliente['dni']); ?>&buscar=<?php echo urlencode($buscar); ?>&filtro_localidad=<?php echo urlencode($filtro_localidad); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                        Editar
                                    </a>

                                    <form method="POST" action="clientes.php" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar este cliente?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="dni" value="<?php echo htmlspecialchars($cliente['dni']); ?>">
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

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
