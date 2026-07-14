<?php
// =====================================================
// viajes.php
// CRUD funcional de viajes
// - alta
// - edición
// - eliminación física
// - buscador y filtros
// - clave compuesta: patente + fecha_salida
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Viajes';

$mensaje = '';
$tipo_mensaje = '';

$viajes = [];
$vehiculos = [];
$choferes = [];
$sucursales = [];
$estados_viaje = [];

$modo_edicion = false;

$patente_original = '';
$fecha_salida_original = '';

$patente = '';
$fecha_salida = '';
$fecha_llegada_estimada = '';
$fecha_llegada_real = '';
$legajo_chofer = '';
$cod_sucursal_origen = '';
$cod_sucursal_destino = '';
$cod_estado_viaje = '';

$buscar = trim($_GET['buscar'] ?? '');
$filtro_origen = trim($_GET['filtro_origen'] ?? '');
$filtro_destino = trim($_GET['filtro_destino'] ?? '');
$filtro_estado = trim($_GET['filtro_estado'] ?? '');


// -----------------------------------------------------
// FUNCIÓN: normalizar fecha datetime-local
// -----------------------------------------------------

function normalizarFechaDatetimeLocalViaje(string $valor): string
{
    $valor = trim($valor);

    if ($valor === '') {
        return '';
    }

    $valor = str_replace('T', ' ', $valor);

    if (strlen($valor) === 16) {
        $valor .= ':00';
    }

    return $valor;
}


// -----------------------------------------------------
// FUNCIÓN: formatear fecha para input datetime-local
// -----------------------------------------------------

function fechaViajeParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}


// -----------------------------------------------------
// 1. ELIMINAR VIAJE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    $patente_eliminar = trim($_POST['patente'] ?? '');
    $fecha_salida_eliminar = trim($_POST['fecha_salida'] ?? '');

    if ($patente_eliminar === '' || $fecha_salida_eliminar === '') {

        $mensaje = 'No se recibieron correctamente los datos del viaje a eliminar.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEliminar = "
                DELETE FROM Viaje
                WHERE patente = :patente
                  AND fecha_salida = :fecha_salida
            ";

            $stmtEliminar = $pdo->prepare($sqlEliminar);

            $stmtEliminar->execute([
                ':patente' => $patente_eliminar,
                ':fecha_salida' => $fecha_salida_eliminar
            ]);

            if ($stmtEliminar->rowCount() > 0) {
                $mensaje = 'Viaje eliminado correctamente.';
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'No se encontró el viaje seleccionado.';
                $tipo_mensaje = 'warning';
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo eliminar el viaje.';
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

    $patente_original = trim($_POST['patente_original'] ?? '');
    $fecha_salida_original = trim($_POST['fecha_salida_original'] ?? '');

    $patente = trim($_POST['patente'] ?? '');
    $fecha_salida = normalizarFechaDatetimeLocalViaje($_POST['fecha_salida'] ?? '');
    $fecha_llegada_estimada = normalizarFechaDatetimeLocalViaje($_POST['fecha_llegada_estimada'] ?? '');
    $fecha_llegada_real = normalizarFechaDatetimeLocalViaje($_POST['fecha_llegada_real'] ?? '');
    $legajo_chofer = trim($_POST['legajo_chofer'] ?? '');
    $cod_sucursal_origen = trim($_POST['cod_sucursal_origen'] ?? '');
    $cod_sucursal_destino = trim($_POST['cod_sucursal_destino'] ?? '');
    $cod_estado_viaje = trim($_POST['cod_estado_viaje'] ?? '');

    if (
        $patente === '' ||
        $fecha_salida === '' ||
        $fecha_llegada_estimada === '' ||
        $legajo_chofer === '' ||
        $cod_sucursal_origen === '' ||
        $cod_sucursal_destino === '' ||
        $cod_estado_viaje === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } elseif ($cod_sucursal_origen === $cod_sucursal_destino) {

        $mensaje = 'La sucursal de origen y destino no pueden ser la misma.';
        $tipo_mensaje = 'error';

    } elseif (strtotime($fecha_llegada_estimada) < strtotime($fecha_salida)) {

        $mensaje = 'La fecha estimada de llegada no puede ser anterior a la fecha de salida.';
        $tipo_mensaje = 'error';

    } elseif ($fecha_llegada_real !== '' && strtotime($fecha_llegada_real) < strtotime($fecha_salida)) {

        $mensaje = 'La fecha real de llegada no puede ser anterior a la fecha de salida.';
        $tipo_mensaje = 'error';

    } else {

        try {

            if ($modo_edicion) {

                $sqlActualizar = "
                    UPDATE Viaje
                    SET
                        patente = :patente_nueva,
                        fecha_salida = :fecha_salida_nueva,
                        fecha_llegada_estimada = :fecha_llegada_estimada,
                        fecha_llegada_real = :fecha_llegada_real,
                        legajo_chofer = :legajo_chofer,
                        cod_sucursal_origen = :cod_sucursal_origen,
                        cod_sucursal_destino = :cod_sucursal_destino,
                        cod_estado_viaje = :cod_estado_viaje
                    WHERE patente = :patente_original
                      AND fecha_salida = :fecha_salida_original
                ";

                $stmtActualizar = $pdo->prepare($sqlActualizar);

                $stmtActualizar->execute([
                    ':patente_nueva' => $patente,
                    ':fecha_salida_nueva' => $fecha_salida,
                    ':fecha_llegada_estimada' => $fecha_llegada_estimada,
                    ':fecha_llegada_real' => ($fecha_llegada_real !== '' ? $fecha_llegada_real : null),
                    ':legajo_chofer' => $legajo_chofer,
                    ':cod_sucursal_origen' => $cod_sucursal_origen,
                    ':cod_sucursal_destino' => $cod_sucursal_destino,
                    ':cod_estado_viaje' => $cod_estado_viaje,
                    ':patente_original' => $patente_original,
                    ':fecha_salida_original' => $fecha_salida_original
                ]);

                $mensaje = 'Viaje actualizado correctamente.';
                $tipo_mensaje = 'success';

                $modo_edicion = false;
                $patente_original = '';
                $fecha_salida_original = '';
                $patente = '';
                $fecha_salida = '';
                $fecha_llegada_estimada = '';
                $fecha_llegada_real = '';
                $legajo_chofer = '';
                $cod_sucursal_origen = '';
                $cod_sucursal_destino = '';
                $cod_estado_viaje = '';

            } else {

                $sqlInsertar = "
                    INSERT INTO Viaje (
                        patente,
                        fecha_salida,
                        fecha_llegada_estimada,
                        fecha_llegada_real,
                        legajo_chofer,
                        cod_sucursal_origen,
                        cod_sucursal_destino,
                        cod_estado_viaje
                    )
                    VALUES (
                        :patente,
                        :fecha_salida,
                        :fecha_llegada_estimada,
                        :fecha_llegada_real,
                        :legajo_chofer,
                        :cod_sucursal_origen,
                        :cod_sucursal_destino,
                        :cod_estado_viaje
                    )
                ";

                $stmtInsertar = $pdo->prepare($sqlInsertar);

                $stmtInsertar->execute([
                    ':patente' => $patente,
                    ':fecha_salida' => $fecha_salida,
                    ':fecha_llegada_estimada' => $fecha_llegada_estimada,
                    ':fecha_llegada_real' => ($fecha_llegada_real !== '' ? $fecha_llegada_real : null),
                    ':legajo_chofer' => $legajo_chofer,
                    ':cod_sucursal_origen' => $cod_sucursal_origen,
                    ':cod_sucursal_destino' => $cod_sucursal_destino,
                    ':cod_estado_viaje' => $cod_estado_viaje
                ]);

                $mensaje = 'Viaje registrado correctamente.';
                $tipo_mensaje = 'success';

                $patente = '';
                $fecha_salida = '';
                $fecha_llegada_estimada = '';
                $fecha_llegada_real = '';
                $legajo_chofer = '';
                $cod_sucursal_origen = '';
                $cod_sucursal_destino = '';
                $cod_estado_viaje = '';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al guardar el viaje. Verificá que no exista ya un viaje con la misma patente y fecha de salida.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR VIAJE PARA EDICIÓN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar_patente']) && isset($_GET['editar_fecha'])) {

    $patente_editar = trim($_GET['editar_patente']);
    $fecha_editar = trim($_GET['editar_fecha']);

    if ($patente_editar !== '' && $fecha_editar !== '') {

        try {

            $sqlEditar = "
                SELECT
                    patente,
                    fecha_salida,
                    fecha_llegada_estimada,
                    fecha_llegada_real,
                    legajo_chofer,
                    cod_sucursal_origen,
                    cod_sucursal_destino,
                    cod_estado_viaje
                FROM Viaje
                WHERE patente = :patente
                  AND fecha_salida = :fecha_salida
                LIMIT 1
            ";

            $stmtEditar = $pdo->prepare($sqlEditar);

            $stmtEditar->execute([
                ':patente' => $patente_editar,
                ':fecha_salida' => $fecha_editar
            ]);

            $filaEditar = $stmtEditar->fetch();

            if ($filaEditar) {
                $modo_edicion = true;
                $patente_original = $filaEditar['patente'];
                $fecha_salida_original = $filaEditar['fecha_salida'];

                $patente = $filaEditar['patente'];
                $fecha_salida = $filaEditar['fecha_salida'];
                $fecha_llegada_estimada = $filaEditar['fecha_llegada_estimada'];
                $fecha_llegada_real = $filaEditar['fecha_llegada_real'] ?? '';
                $legajo_chofer = $filaEditar['legajo_chofer'];
                $cod_sucursal_origen = $filaEditar['cod_sucursal_origen'];
                $cod_sucursal_destino = $filaEditar['cod_sucursal_destino'];
                $cod_estado_viaje = $filaEditar['cod_estado_viaje'];
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo cargar el viaje para edición.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR DATOS AUXILIARES
// -----------------------------------------------------

try {

    $vehiculos = $pdo->query("
        SELECT patente, marca, modelo
        FROM Vehiculo
        ORDER BY patente ASC
    ")->fetchAll();

    $choferes = $pdo->query("
        SELECT legajo, nombre, apellido
        FROM vista_chofer
        ORDER BY apellido ASC, nombre ASC
    ")->fetchAll();

    $sucursales = $pdo->query("
        SELECT cod_sucursal, nombre
        FROM Sucursal
        ORDER BY nombre ASC
    ")->fetchAll();

    $estados_viaje = $pdo->query("
        SELECT cod_estado_viaje, nombre
        FROM Estado_Viaje
        ORDER BY nombre ASC
    ")->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los datos auxiliares del formulario.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 5. LISTADO DE VIAJES
// -----------------------------------------------------

try {

    $sqlListado = "
        SELECT
            v.patente,
            v.fecha_salida,
            v.fecha_llegada_estimada,
            v.fecha_llegada_real,
            v.legajo_chofer,
            v.cod_sucursal_origen,
            v.cod_sucursal_destino,
            v.cod_estado_viaje,
            ch.nombre AS nombre_chofer,
            ch.apellido AS apellido_chofer,
            so.nombre AS nombre_sucursal_origen,
            sd.nombre AS nombre_sucursal_destino,
            ev.nombre AS nombre_estado_viaje
        FROM Viaje v
        INNER JOIN vista_chofer ch
            ON v.legajo_chofer = ch.legajo
        INNER JOIN Sucursal so
            ON v.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sd
            ON v.cod_sucursal_destino = sd.cod_sucursal
        INNER JOIN Estado_Viaje ev
            ON v.cod_estado_viaje = ev.cod_estado_viaje
        WHERE 1 = 1
    ";

    $params = [];

    if ($buscar !== '') {
        $sqlListado .= "
            AND (
                v.patente LIKE :buscar
                OR v.legajo_chofer LIKE :buscar
                OR ch.nombre LIKE :buscar
                OR ch.apellido LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if ($filtro_origen !== '') {
        $sqlListado .= " AND v.cod_sucursal_origen = :filtro_origen ";
        $params[':filtro_origen'] = $filtro_origen;
    }

    if ($filtro_destino !== '') {
        $sqlListado .= " AND v.cod_sucursal_destino = :filtro_destino ";
        $params[':filtro_destino'] = $filtro_destino;
    }

    if ($filtro_estado !== '') {
        $sqlListado .= " AND v.cod_estado_viaje = :filtro_estado ";
        $params[':filtro_estado'] = $filtro_estado;
    }

    $sqlListado .= " ORDER BY v.fecha_salida DESC, v.patente ASC ";

    $stmtListado = $pdo->prepare($sqlListado);
    $stmtListado->execute($params);

    $viajes = $stmtListado->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al consultar los viajes.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">

        <h1 class="page-title">Gestión de Viajes</h1>

        <p class="page-subtitle">
            En esta pantalla podés registrar, editar, consultar y eliminar viajes del sistema.
        </p>

    </section>


    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $modo_edicion ? 'Editar viaje' : 'Registrar nuevo viaje'; ?>
        </h3>

        <form method="POST" action="viajes.php">

            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="modo_formulario" value="<?php echo $modo_edicion ? 'edicion' : 'alta'; ?>">
            <input type="hidden" name="patente_original" value="<?php echo htmlspecialchars($patente_original); ?>">
            <input type="hidden" name="fecha_salida_original" value="<?php echo htmlspecialchars($fecha_salida_original); ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                <div>

                    <div class="form-group">
                        <label for="patente">Vehículo</label>
                        <select id="patente" name="patente" class="form-control" required>
                            <option value="">Seleccione un vehículo</option>

                            <?php foreach ($vehiculos as $vehiculo): ?>
                                <option
                                    value="<?php echo htmlspecialchars($vehiculo['patente']); ?>"
                                    <?php echo ($patente === $vehiculo['patente']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($vehiculo['patente'] . ' - ' . $vehiculo['marca'] . ' ' . $vehiculo['modelo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="fecha_salida">Fecha de salida</label>
                        <input
                            type="datetime-local"
                            id="fecha_salida"
                            name="fecha_salida"
                            class="form-control"
                            value="<?php echo htmlspecialchars(fechaViajeParaInput($fecha_salida)); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="fecha_llegada_estimada">Fecha estimada de llegada</label>
                        <input
                            type="datetime-local"
                            id="fecha_llegada_estimada"
                            name="fecha_llegada_estimada"
                            class="form-control"
                            value="<?php echo htmlspecialchars(fechaViajeParaInput($fecha_llegada_estimada)); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="fecha_llegada_real">Fecha real de llegada</label>
                        <input
                            type="datetime-local"
                            id="fecha_llegada_real"
                            name="fecha_llegada_real"
                            class="form-control"
                            value="<?php echo htmlspecialchars(fechaViajeParaInput($fecha_llegada_real)); ?>"
                        >
                    </div>

                </div>

                <div>

                    <div class="form-group">
                        <label for="legajo_chofer">Chofer</label>
                        <select id="legajo_chofer" name="legajo_chofer" class="form-control" required>
                            <option value="">Seleccione un chofer</option>

                            <?php foreach ($choferes as $chofer): ?>
                                <option
                                    value="<?php echo htmlspecialchars($chofer['legajo']); ?>"
                                    <?php echo ($legajo_chofer === $chofer['legajo']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($chofer['legajo'] . ' - ' . $chofer['apellido'] . ', ' . $chofer['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cod_sucursal_origen">Sucursal de origen</label>
                        <select id="cod_sucursal_origen" name="cod_sucursal_origen" class="form-control" required>
                            <option value="">Seleccione una sucursal</option>

                            <?php foreach ($sucursales as $sucursal): ?>
                                <option
                                    value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                    <?php echo ($cod_sucursal_origen === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($sucursal['cod_sucursal'] . ' - ' . $sucursal['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cod_sucursal_destino">Sucursal de destino</label>
                        <select id="cod_sucursal_destino" name="cod_sucursal_destino" class="form-control" required>
                            <option value="">Seleccione una sucursal</option>

                            <?php foreach ($sucursales as $sucursal): ?>
                                <option
                                    value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                    <?php echo ($cod_sucursal_destino === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($sucursal['cod_sucursal'] . ' - ' . $sucursal['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="cod_estado_viaje">Estado del viaje</label>
                        <select id="cod_estado_viaje" name="cod_estado_viaje" class="form-control" required>
                            <option value="">Seleccione un estado</option>

                            <?php foreach ($estados_viaje as $estado_item): ?>
                                <option
                                    value="<?php echo htmlspecialchars($estado_item['cod_estado_viaje']); ?>"
                                    <?php echo ($cod_estado_viaje === $estado_item['cod_estado_viaje']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($estado_item['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar viaje'; ?>
                        </button>

                        <?php if ($modo_edicion): ?>
                            <a href="viajes.php" class="btn-public-secondary">
                                Cancelar edición
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar viajes</h3>

        <form method="GET" action="viajes.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr 1fr;">

                <div class="form-group">
                    <label for="buscar">Buscar por patente, legajo o chofer</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: AA123BB, CHO001, Pérez..."
                    >
                </div>

                <div class="form-group">
                    <label for="filtro_origen">Filtrar por origen</label>
                    <select id="filtro_origen" name="filtro_origen" class="form-control">
                        <option value="">Todos</option>

                        <?php foreach ($sucursales as $sucursal): ?>
                            <option
                                value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                <?php echo ($filtro_origen === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($sucursal['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="filtro_destino">Filtrar por destino</label>
                    <select id="filtro_destino" name="filtro_destino" class="form-control">
                        <option value="">Todos</option>

                        <?php foreach ($sucursales as $sucursal): ?>
                            <option
                                value="<?php echo htmlspecialchars($sucursal['cod_sucursal']); ?>"
                                <?php echo ($filtro_destino === $sucursal['cod_sucursal']) ? 'selected' : ''; ?>
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

                        <?php foreach ($estados_viaje as $estado_item): ?>
                            <option
                                value="<?php echo htmlspecialchars($estado_item['cod_estado_viaje']); ?>"
                                <?php echo ($filtro_estado === $estado_item['cod_estado_viaje']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($estado_item['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="viajes.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de viajes</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1450px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Patente</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha salida</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Llegada estimada</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Llegada real</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Chofer</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($viajes)): ?>

                        <tr>
                            <td colspan="9" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay viajes registrados con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($viajes as $viaje): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['patente']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['fecha_salida']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['fecha_llegada_estimada']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['fecha_llegada_real'] ?? ''); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['legajo_chofer'] . ' - ' . $viaje['apellido_chofer'] . ', ' . $viaje['nombre_chofer']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['nombre_sucursal_origen']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['nombre_sucursal_destino']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($viaje['nombre_estado_viaje']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">

                                    <a href="viajes.php?editar_patente=<?php echo urlencode($viaje['patente']); ?>&editar_fecha=<?php echo urlencode($viaje['fecha_salida']); ?>&buscar=<?php echo urlencode($buscar); ?>&filtro_origen=<?php echo urlencode($filtro_origen); ?>&filtro_destino=<?php echo urlencode($filtro_destino); ?>&filtro_estado=<?php echo urlencode($filtro_estado); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                        Editar
                                    </a>

                                    <form method="POST" action="viajes.php" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar este viaje?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="patente" value="<?php echo htmlspecialchars($viaje['patente']); ?>">
                                        <input type="hidden" name="fecha_salida" value="<?php echo htmlspecialchars($viaje['fecha_salida']); ?>">
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