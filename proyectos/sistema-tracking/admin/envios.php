<?php
// =====================================================
// envios.php
// CRUD funcional de envíos
// - tracking automático TRK000001, TRK000002...
// - alta
// - edición
// - eliminación física
// - buscador y filtros
// - muestra último estado si existe historial
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Gestión de Envíos';

$mensaje = '';
$tipo_mensaje = '';

$envios = [];
$clientes = [];
$sucursales = [];
$estados_envio = [];

$modo_edicion = false;

$nro_tracking = '';
$fecha_recepcion = date('Y-m-d H:i:s');
$dni_remitente = '';
$dni_destinatario = '';
$cod_sucursal_origen = '';
$cod_sucursal_destino = '';

$buscar = trim($_GET['buscar'] ?? '');
$filtro_origen = trim($_GET['filtro_origen'] ?? '');
$filtro_destino = trim($_GET['filtro_destino'] ?? '');
$filtro_estado = trim($_GET['filtro_estado'] ?? '');
$buscar_es_tracking = $buscar !== '' && preg_match('/^(TRK)?[0-9]+$/i', preg_replace('/[^a-zA-Z0-9]/', '', $buscar));


// -----------------------------------------------------
// FUNCIÓN: generar tracking
// Formato: TRK000001, TRK000002...
// Compatible con valores anteriores que tengan números
// -----------------------------------------------------

function generarTrackingEnvio(PDO $pdo): string
{
    $sql = "SELECT nro_tracking FROM Envio";

    $stmt = $pdo->query($sql);

    $trackings = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maximo = 0;

    foreach ($trackings as $tracking_actual) {

        if (preg_match('/(\d+)$/', (string) $tracking_actual, $coincidencias)) {

            $numero = (int) $coincidencias[1];

            if ($numero > $maximo) {
                $maximo = $numero;
            }
        }
    }

    $siguiente = $maximo + 1;

    return 'TRK' . str_pad((string) $siguiente, 6, '0', STR_PAD_LEFT);
}


// -----------------------------------------------------
// FUNCIÓN: normalizar fecha datetime-local
// -----------------------------------------------------

function normalizarFechaDatetimeLocal(string $valor): string
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

function fechaParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}


// -----------------------------------------------------
// FUNCIÓN: registrar historial inicial si existe estado
// -----------------------------------------------------

function registrarHistorialInicialEnvio(PDO $pdo, string $nro_tracking, string $fecha_hora, string $cod_sucursal_origen): void
{
    $posibles_estados = [
        'SOLICITUD_CREADA',
        'RECIBIDO_EN_SUCURSAL',
        'RECIBIDO'
    ];

    $estado_encontrado = null;

    foreach ($posibles_estados as $estado) {

        $sqlEstado = "
            SELECT cod_estado_envio
            FROM Estado_Envio
            WHERE cod_estado_envio = :estado
            LIMIT 1
        ";

        $stmtEstado = $pdo->prepare($sqlEstado);

        $stmtEstado->execute([
            ':estado' => $estado
        ]);

        $filaEstado = $stmtEstado->fetch();

        if ($filaEstado) {
            $estado_encontrado = $filaEstado['cod_estado_envio'];
            break;
        }
    }

    if ($estado_encontrado !== null) {

        $sqlHistorial = "
            CALL sp_registrar_movimiento_envio(
                :nro_tracking,
                :cod_estado_envio,
                :fecha_hora,
                :cod_sucursal_actual,
                NULL,
                NULL,
                :observaciones
            )
        ";

        $stmtHistorial = $pdo->prepare($sqlHistorial);

        $stmtHistorial->execute([
            ':nro_tracking' => $nro_tracking,
            ':cod_estado_envio' => $estado_encontrado,
            ':fecha_hora' => $fecha_hora,
            ':cod_sucursal_actual' => $cod_sucursal_origen,
            ':observaciones' => 'Registro inicial del envío'
        ]);
        $stmtHistorial->closeCursor();
    }
}


// -----------------------------------------------------
// 1. ELIMINAR ENVÍO
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    $tracking_eliminar = trim($_POST['nro_tracking'] ?? '');

    if ($tracking_eliminar === '') {

        $mensaje = 'No se recibió el envío a eliminar.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $sqlEliminar = "
                DELETE FROM Envio
                WHERE nro_tracking = :nro_tracking
            ";

            $stmtEliminar = $pdo->prepare($sqlEliminar);

            $stmtEliminar->execute([
                ':nro_tracking' => $tracking_eliminar
            ]);

            if ($stmtEliminar->rowCount() > 0) {
                $mensaje = 'Envío eliminado correctamente.';
                $tipo_mensaje = 'success';
            } else {
                $mensaje = 'No se encontró el envío seleccionado.';
                $tipo_mensaje = 'warning';
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo eliminar el envío.';
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

    $nro_tracking = trim($_POST['nro_tracking'] ?? '');
    $fecha_recepcion_input = trim($_POST['fecha_recepcion'] ?? '');
    $fecha_recepcion = normalizarFechaDatetimeLocal($fecha_recepcion_input);
    $dni_remitente = trim($_POST['dni_remitente'] ?? '');
    $dni_destinatario = trim($_POST['dni_destinatario'] ?? '');
    $cod_sucursal_origen = trim($_POST['cod_sucursal_origen'] ?? '');
    $cod_sucursal_destino = trim($_POST['cod_sucursal_destino'] ?? '');

    if (
        $nro_tracking === '' ||
        $fecha_recepcion === '' ||
        $dni_remitente === '' ||
        $dni_destinatario === '' ||
        $cod_sucursal_origen === '' ||
        $cod_sucursal_destino === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } elseif ($cod_sucursal_origen === $cod_sucursal_destino) {

        $mensaje = 'La sucursal de origen y destino no pueden ser la misma.';
        $tipo_mensaje = 'error';

    } else {

        try {

            if ($modo_edicion) {

                $sqlActualizar = "
                    UPDATE Envio
                    SET
                        fecha_recepcion = :fecha_recepcion,
                        dni_remitente = :dni_remitente,
                        dni_destinatario = :dni_destinatario,
                        cod_sucursal_origen = :cod_sucursal_origen,
                        cod_sucursal_destino = :cod_sucursal_destino
                    WHERE nro_tracking = :nro_tracking
                ";

                $stmtActualizar = $pdo->prepare($sqlActualizar);

                $stmtActualizar->execute([
                    ':fecha_recepcion' => $fecha_recepcion,
                    ':dni_remitente' => $dni_remitente,
                    ':dni_destinatario' => $dni_destinatario,
                    ':cod_sucursal_origen' => $cod_sucursal_origen,
                    ':cod_sucursal_destino' => $cod_sucursal_destino,
                    ':nro_tracking' => $nro_tracking
                ]);

                $mensaje = 'Envío actualizado correctamente.';
                $tipo_mensaje = 'success';

                $modo_edicion = false;
                $nro_tracking = '';
                $fecha_recepcion = date('Y-m-d H:i:s');
                $dni_remitente = '';
                $dni_destinatario = '';
                $cod_sucursal_origen = '';
                $cod_sucursal_destino = '';

            } else {

                $sqlInsertar = "
                    INSERT INTO Envio (
                        nro_tracking,
                        fecha_recepcion,
                        dni_remitente,
                        dni_destinatario,
                        cod_sucursal_origen,
                        cod_sucursal_destino
                    )
                    VALUES (
                        :nro_tracking,
                        :fecha_recepcion,
                        :dni_remitente,
                        :dni_destinatario,
                        :cod_sucursal_origen,
                        :cod_sucursal_destino
                    )
                ";

                $stmtInsertar = $pdo->prepare($sqlInsertar);

                $stmtInsertar->execute([
                    ':nro_tracking' => $nro_tracking,
                    ':fecha_recepcion' => $fecha_recepcion,
                    ':dni_remitente' => $dni_remitente,
                    ':dni_destinatario' => $dni_destinatario,
                    ':cod_sucursal_origen' => $cod_sucursal_origen,
                    ':cod_sucursal_destino' => $cod_sucursal_destino
                ]);

                try {
                    registrarHistorialInicialEnvio($pdo, $nro_tracking, $fecha_recepcion, $cod_sucursal_origen);
                } catch (PDOException $e) {
                    // Si falla el historial inicial, el envío igual queda creado.
                }

                $mensaje = 'Envío registrado correctamente.';
                $tipo_mensaje = 'success';

                $nro_tracking = '';
                $fecha_recepcion = date('Y-m-d H:i:s');
                $dni_remitente = '';
                $dni_destinatario = '';
                $cod_sucursal_origen = '';
                $cod_sucursal_destino = '';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al guardar el envío.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. CARGAR ENVÍO PARA EDICIÓN
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['editar'])) {

    $tracking_editar = trim($_GET['editar']);

    if ($tracking_editar !== '') {

        try {

            $sqlEditar = "
                SELECT
                    nro_tracking,
                    fecha_recepcion,
                    dni_remitente,
                    dni_destinatario,
                    cod_sucursal_origen,
                    cod_sucursal_destino
                FROM Envio
                WHERE nro_tracking = :nro_tracking
                LIMIT 1
            ";

            $stmtEditar = $pdo->prepare($sqlEditar);

            $stmtEditar->execute([
                ':nro_tracking' => $tracking_editar
            ]);

            $filaEditar = $stmtEditar->fetch();

            if ($filaEditar) {
                $modo_edicion = true;
                $nro_tracking = $filaEditar['nro_tracking'];
                $fecha_recepcion = $filaEditar['fecha_recepcion'];
                $dni_remitente = $filaEditar['dni_remitente'];
                $dni_destinatario = $filaEditar['dni_destinatario'];
                $cod_sucursal_origen = $filaEditar['cod_sucursal_origen'];
                $cod_sucursal_destino = $filaEditar['cod_sucursal_destino'];
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo cargar el envío para edición.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. CARGAR CLIENTES, SUCURSALES Y ESTADOS
// -----------------------------------------------------

try {

    $clientes = $pdo->query("
        SELECT dni, nombre, apellido
        FROM vista_cliente
        ORDER BY apellido ASC, nombre ASC
    ")->fetchAll();

    $sucursales = $pdo->query("
        SELECT cod_sucursal, nombre
        FROM Sucursal
        ORDER BY nombre ASC
    ")->fetchAll();

    $estados_envio = $pdo->query("
        SELECT cod_estado_envio, nombre
        FROM Estado_Envio
        ORDER BY nombre ASC
    ")->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los datos auxiliares del formulario.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 5. GENERAR TRACKING AUTOMÁTICO PARA ALTA
// -----------------------------------------------------

if (!$modo_edicion && $nro_tracking === '') {

    try {
        $nro_tracking = generarTrackingEnvio($pdo);
    } catch (PDOException $e) {
        $nro_tracking = 'TRK000001';
    }
}


// -----------------------------------------------------
// 6. LISTADO DE ENVÍOS CON ÚLTIMO ESTADO
// -----------------------------------------------------

try {

    $sqlListado = "
        SELECT
            e.nro_tracking,
            e.fecha_recepcion,
            e.dni_remitente,
            e.dni_destinatario,
            e.cod_sucursal_origen,
            e.cod_sucursal_destino,
            cr.nombre AS nombre_remitente,
            cr.apellido AS apellido_remitente,
            cd.nombre AS nombre_destinatario,
            cd.apellido AS apellido_destinatario,
            so.nombre AS nombre_sucursal_origen,
            sd.nombre AS nombre_sucursal_destino,
            he.cod_estado_envio,
            ee.nombre AS nombre_estado
        FROM Envio e
        INNER JOIN vista_cliente cr
            ON e.dni_remitente = cr.dni
        INNER JOIN vista_cliente cd
            ON e.dni_destinatario = cd.dni
        INNER JOIN Sucursal so
            ON e.cod_sucursal_origen = so.cod_sucursal
        INNER JOIN Sucursal sd
            ON e.cod_sucursal_destino = sd.cod_sucursal
        LEFT JOIN (
            SELECT h1.nro_tracking, h1.cod_estado_envio
            FROM Historial_Estado h1
            INNER JOIN (
                SELECT nro_tracking, MAX(nro_movimiento) AS max_mov
                FROM Historial_Estado
                GROUP BY nro_tracking
            ) hm
                ON h1.nro_tracking = hm.nro_tracking
               AND h1.nro_movimiento = hm.max_mov
        ) he
            ON e.nro_tracking = he.nro_tracking
        LEFT JOIN Estado_Envio ee
            ON he.cod_estado_envio = ee.cod_estado_envio
        WHERE 1 = 1
    ";

    $params = [];

    if ($buscar_es_tracking) {
        $sqlListado .= " AND e.nro_tracking LIKE :buscar ";
        $params[':buscar'] = '%' . $buscar . '%';
    } elseif ($buscar !== '') {
        $sqlListado .= "
            AND (
                e.nro_tracking LIKE :buscar
                OR e.dni_remitente LIKE :buscar
                OR e.dni_destinatario LIKE :buscar
                OR cr.nombre LIKE :buscar
                OR cr.apellido LIKE :buscar
                OR cd.nombre LIKE :buscar
                OR cd.apellido LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if (!$buscar_es_tracking && $filtro_origen !== '') {
        $sqlListado .= " AND e.cod_sucursal_origen = :filtro_origen ";
        $params[':filtro_origen'] = $filtro_origen;
    }

    if (!$buscar_es_tracking && $filtro_destino !== '') {
        $sqlListado .= " AND e.cod_sucursal_destino = :filtro_destino ";
        $params[':filtro_destino'] = $filtro_destino;
    }

    if (!$buscar_es_tracking && $filtro_estado !== '') {
        $sqlListado .= " AND he.cod_estado_envio = :filtro_estado ";
        $params[':filtro_estado'] = $filtro_estado;
    }

    $sqlListado .= " ORDER BY e.fecha_recepcion DESC, e.nro_tracking DESC ";

    $stmtListado = $pdo->prepare($sqlListado);
    $stmtListado->execute($params);

    $envios = $stmtListado->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al consultar los envíos.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">

        <h1 class="page-title">Gestión de Envíos</h1>

        <p class="page-subtitle">
            En esta pantalla podés registrar, editar, consultar y eliminar envíos del sistema.
        </p>

    </section>


    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $modo_edicion ? 'Editar envío' : 'Registrar nuevo envío'; ?>
        </h3>

        <form method="POST" action="envios.php">

            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="modo_formulario" value="<?php echo $modo_edicion ? 'edicion' : 'alta'; ?>">

            <div class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

                <div>

                    <div class="form-group">
                        <label for="nro_tracking">Tracking</label>
                        <input
                            type="text"
                            id="nro_tracking"
                            name="nro_tracking"
                            class="form-control"
                            value="<?php echo htmlspecialchars($nro_tracking); ?>"
                            readonly
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="fecha_recepcion">Fecha de recepción</label>
                        <input
                            type="datetime-local"
                            id="fecha_recepcion"
                            name="fecha_recepcion"
                            class="form-control"
                            value="<?php echo htmlspecialchars(fechaParaInput($fecha_recepcion)); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="dni_remitente">Remitente</label>
                        <select id="dni_remitente" name="dni_remitente" class="form-control" required>
                            <option value="">Seleccione un remitente</option>

                            <?php foreach ($clientes as $cliente): ?>
                                <option
                                    value="<?php echo htmlspecialchars($cliente['dni']); ?>"
                                    <?php echo ($dni_remitente === $cliente['dni']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($cliente['dni'] . ' - ' . $cliente['apellido'] . ', ' . $cliente['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>

                <div>

                    <div class="form-group">
                        <label for="dni_destinatario">Destinatario</label>
                        <select id="dni_destinatario" name="dni_destinatario" class="form-control" required>
                            <option value="">Seleccione un destinatario</option>

                            <?php foreach ($clientes as $cliente): ?>
                                <option
                                    value="<?php echo htmlspecialchars($cliente['dni']); ?>"
                                    <?php echo ($dni_destinatario === $cliente['dni']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($cliente['dni'] . ' - ' . $cliente['apellido'] . ', ' . $cliente['nombre']); ?>
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

                    <div style="display: flex; gap: 12px; margin-top: 30px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar envío'; ?>
                        </button>

                        <?php if ($modo_edicion): ?>
                            <a href="envios.php" class="btn-public-secondary">
                                Cancelar edición
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar envíos</h3>

        <form method="GET" action="envios.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr 1fr 1fr 1fr;">

                <div class="form-group">
                    <label for="buscar">Buscar por tracking, DNI o nombre</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: TRK000001, 30111222, Pérez..."
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

                        <?php foreach ($estados_envio as $estado_item): ?>
                            <option
                                value="<?php echo htmlspecialchars($estado_item['cod_estado_envio']); ?>"
                                <?php echo ($filtro_estado === $estado_item['cod_estado_envio']) ? 'selected' : ''; ?>
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

                    <a href="envios.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Listado de envíos</h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1350px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha recepción</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Remitente</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($envios)): ?>

                        <tr>
                            <td colspan="8" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay envíos registrados con esos criterios.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($envios as $envio): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['nro_tracking']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['fecha_recepcion']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['dni_remitente'] . ' - ' . $envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['dni_destinatario'] . ' - ' . $envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['nombre_sucursal_origen']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['nombre_sucursal_destino']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['nombre_estado'] ?? 'Sin historial'); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">

                                    <a href="envios.php?editar=<?php echo urlencode($envio['nro_tracking']); ?>&buscar=<?php echo urlencode($buscar); ?>&filtro_origen=<?php echo urlencode($filtro_origen); ?>&filtro_destino=<?php echo urlencode($filtro_destino); ?>&filtro_estado=<?php echo urlencode($filtro_estado); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                        Editar
                                    </a>

                                    <form method="POST" action="envios.php" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar este envío? Esto puede borrar también registros relacionados.');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="nro_tracking" value="<?php echo htmlspecialchars($envio['nro_tracking']); ?>">
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
