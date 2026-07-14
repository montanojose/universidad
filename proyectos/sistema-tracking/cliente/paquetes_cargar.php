<?php
// =====================================================
// paquetes_cargar.php
// Gestión de paquetes por cliente o admin
// - CLIENTE: solo puede operar sobre sus envíos
// - ADMIN: puede operar sobre cualquier envío
// - alta, edición y eliminación
// - nro_paquete automático por tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CLIENTE']);

$titulo_pagina = 'Cargar Paquetes';

$mensaje = '';
$tipo_mensaje = '';

$envios_disponibles = [];
$tipos_contenido = [];
$categorias_paquete = [];
$paquetes = [];

$modo_edicion = false;

$rol_actual = $_SESSION['usuario_rol'] ?? '';
$dni_cliente_sesion = '';

$nro_tracking = trim($_GET['tracking'] ?? '');
$mostrar_etiquetas = (($_GET['etiquetas'] ?? '') === '1');
$nro_paquete = '';
$peso_kg = '';
$largo_cm = '';
$ancho_cm = '';
$alto_cm = '';
$fragil = '0';
$descripcion = '';
$cod_tipo_contenido = '';
$cod_categoria_paquete = '';

$solicitud_actual = null;


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerDniClienteSesionPaquetes(): string
{
    if (!empty($_SESSION['dni_cliente'])) {
        return (string) $_SESSION['dni_cliente'];
    }

    if (!empty($_SESSION['usuario_dni_cliente'])) {
        return (string) $_SESSION['usuario_dni_cliente'];
    }

    if (!empty($_SESSION['cliente_dni'])) {
        return (string) $_SESSION['cliente_dni'];
    }

    return '';
}

function obtenerProximoNumeroPaqueteCliente(PDO $pdo, string $nro_tracking): int
{
    $sql = "
        SELECT COALESCE(MAX(nro_paquete), 0) AS max_nro
        FROM Paquete
        WHERE nro_tracking = :nro_tracking
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nro_tracking' => $nro_tracking
    ]);

    $fila = $stmt->fetch();

    return ((int) ($fila['max_nro'] ?? 0)) + 1;
}

function clientePuedeAccederTracking(PDO $pdo, string $tracking, string $dni_cliente): bool
{
    $sql = "
        SELECT COUNT(*)
        FROM Envio
        WHERE nro_tracking = :nro_tracking
          AND dni_remitente = :dni_remitente
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':nro_tracking' => $tracking,
        ':dni_remitente' => $dni_cliente
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function generarCodigoEtiquetaPaquete(string $tracking, string $nro_paquete): string
{
    return strtoupper($tracking . '-P' . str_pad($nro_paquete, 3, '0', STR_PAD_LEFT));
}


// -----------------------------------------------------
// 1. IDENTIFICAR CLIENTE DE SESIÓN SI CORRESPONDE
// -----------------------------------------------------

if ($rol_actual === 'CLIENTE') {
    $dni_cliente_sesion = obtenerDniClienteSesionPaquetes();

    if ($dni_cliente_sesion === '') {
        $mensaje = 'No se pudo identificar el cliente en la sesión.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 2. MENSAJE AL VOLVER DE CREAR SOLICITUD
// -----------------------------------------------------

if (isset($_GET['creado']) && $_GET['creado'] === '1') {
    $mensaje = 'Solicitud creada correctamente. Ahora cargá los paquetes del envío.';
    $tipo_mensaje = 'success';
}


// -----------------------------------------------------
// 3. ELIMINAR PAQUETE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {

    $tracking_eliminar = trim($_POST['nro_tracking'] ?? '');
    $paquete_eliminar = trim($_POST['nro_paquete'] ?? '');

    if ($tracking_eliminar === '' || $paquete_eliminar === '') {

        $mensaje = 'No se recibieron correctamente los datos del paquete a eliminar.';
        $tipo_mensaje = 'error';

    } else {

        try {

            if ($rol_actual === 'CLIENTE' && !clientePuedeAccederTracking($pdo, $tracking_eliminar, $dni_cliente_sesion)) {
                $mensaje = 'No tenés permiso para modificar ese envío.';
                $tipo_mensaje = 'error';
            } else {

                $sqlEliminar = "
                    DELETE FROM Paquete
                    WHERE nro_tracking = :nro_tracking
                      AND nro_paquete = :nro_paquete
                ";

                $stmtEliminar = $pdo->prepare($sqlEliminar);

                $stmtEliminar->execute([
                    ':nro_tracking' => $tracking_eliminar,
                    ':nro_paquete' => $paquete_eliminar
                ]);

                if ($stmtEliminar->rowCount() > 0) {
                    $mensaje = 'Paquete eliminado correctamente.';
                    $tipo_mensaje = 'success';
                } else {
                    $mensaje = 'No se encontró el paquete seleccionado.';
                    $tipo_mensaje = 'warning';
                }

                $nro_tracking = $tracking_eliminar;
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al eliminar el paquete.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 4. GUARDAR O EDITAR PAQUETE
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {

    $modo_formulario = trim($_POST['modo_formulario'] ?? 'alta');
    $modo_edicion = ($modo_formulario === 'edicion');

    $nro_tracking = trim($_POST['nro_tracking'] ?? '');
    $nro_paquete = trim($_POST['nro_paquete'] ?? '');
    $peso_kg = trim($_POST['peso_kg'] ?? '');
    $largo_cm = trim($_POST['largo_cm'] ?? '');
    $ancho_cm = trim($_POST['ancho_cm'] ?? '');
    $alto_cm = trim($_POST['alto_cm'] ?? '');
    $fragil = trim($_POST['fragil'] ?? '0');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $cod_tipo_contenido = trim($_POST['cod_tipo_contenido'] ?? '');
    $cod_categoria_paquete = trim($_POST['cod_categoria_paquete'] ?? '');

    if (
        $nro_tracking === '' ||
        $nro_paquete === '' ||
        $peso_kg === '' ||
        $largo_cm === '' ||
        $ancho_cm === '' ||
        $alto_cm === '' ||
        $cod_tipo_contenido === '' ||
        $cod_categoria_paquete === ''
    ) {

        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } elseif (
        (float) $peso_kg <= 0 ||
        (float) $largo_cm <= 0 ||
        (float) $ancho_cm <= 0 ||
        (float) $alto_cm <= 0
    ) {

        $mensaje = 'Peso y dimensiones deben ser mayores que cero.';
        $tipo_mensaje = 'error';

    } else {

        try {

            if ($rol_actual === 'CLIENTE' && !clientePuedeAccederTracking($pdo, $nro_tracking, $dni_cliente_sesion)) {
                $mensaje = 'No tenés permiso para modificar ese envío.';
                $tipo_mensaje = 'error';
            } else {

                if ($modo_edicion) {

                    $sqlActualizar = "
                        UPDATE Paquete
                        SET
                            peso_kg = :peso_kg,
                            largo_cm = :largo_cm,
                            ancho_cm = :ancho_cm,
                            alto_cm = :alto_cm,
                            fragil = :fragil,
                            descripcion = :descripcion,
                            cod_tipo_contenido = :cod_tipo_contenido,
                            cod_categoria_paquete = :cod_categoria_paquete
                        WHERE nro_tracking = :nro_tracking
                          AND nro_paquete = :nro_paquete
                    ";

                    $stmtActualizar = $pdo->prepare($sqlActualizar);

                    $stmtActualizar->execute([
                        ':peso_kg' => $peso_kg,
                        ':largo_cm' => $largo_cm,
                        ':ancho_cm' => $ancho_cm,
                        ':alto_cm' => $alto_cm,
                        ':fragil' => ($fragil === '1' ? 1 : 0),
                        ':descripcion' => ($descripcion !== '' ? $descripcion : null),
                        ':cod_tipo_contenido' => $cod_tipo_contenido,
                        ':cod_categoria_paquete' => $cod_categoria_paquete,
                        ':nro_tracking' => $nro_tracking,
                        ':nro_paquete' => $nro_paquete
                    ]);

                    $mensaje = 'Paquete actualizado correctamente.';
                    $tipo_mensaje = 'success';

                } else {

                    $sqlInsertar = "
                        INSERT INTO Paquete (
                            nro_tracking,
                            nro_paquete,
                            peso_kg,
                            largo_cm,
                            ancho_cm,
                            alto_cm,
                            fragil,
                            descripcion,
                            cod_tipo_contenido,
                            cod_categoria_paquete
                        )
                        VALUES (
                            :nro_tracking,
                            :nro_paquete,
                            :peso_kg,
                            :largo_cm,
                            :ancho_cm,
                            :alto_cm,
                            :fragil,
                            :descripcion,
                            :cod_tipo_contenido,
                            :cod_categoria_paquete
                        )
                    ";

                    $stmtInsertar = $pdo->prepare($sqlInsertar);

                    $stmtInsertar->execute([
                        ':nro_tracking' => $nro_tracking,
                        ':nro_paquete' => $nro_paquete,
                        ':peso_kg' => $peso_kg,
                        ':largo_cm' => $largo_cm,
                        ':ancho_cm' => $ancho_cm,
                        ':alto_cm' => $alto_cm,
                        ':fragil' => ($fragil === '1' ? 1 : 0),
                        ':descripcion' => ($descripcion !== '' ? $descripcion : null),
                        ':cod_tipo_contenido' => $cod_tipo_contenido,
                        ':cod_categoria_paquete' => $cod_categoria_paquete
                    ]);

                    $mensaje = 'Paquete registrado correctamente.';
                    $tipo_mensaje = 'success';
                }

                $modo_edicion = false;
                $nro_paquete = '';
                $peso_kg = '';
                $largo_cm = '';
                $ancho_cm = '';
                $alto_cm = '';
                $fragil = '0';
                $descripcion = '';
                $cod_tipo_contenido = '';
                $cod_categoria_paquete = '';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al guardar el paquete.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 5. CARGAR PAQUETE PARA EDICIÓN
// -----------------------------------------------------

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['tracking']) &&
    isset($_GET['paquete'])
) {

    $tracking_editar = trim($_GET['tracking']);
    $paquete_editar = trim($_GET['paquete']);

    if ($tracking_editar !== '' && $paquete_editar !== '') {

        try {

            if ($rol_actual === 'CLIENTE' && !clientePuedeAccederTracking($pdo, $tracking_editar, $dni_cliente_sesion)) {
                $mensaje = 'No tenés permiso para acceder a ese envío.';
                $tipo_mensaje = 'error';
            } else {

                $sqlEditar = "
                    SELECT
                        nro_tracking,
                        nro_paquete,
                        peso_kg,
                        largo_cm,
                        ancho_cm,
                        alto_cm,
                        fragil,
                        descripcion,
                        cod_tipo_contenido,
                        cod_categoria_paquete
                    FROM Paquete
                    WHERE nro_tracking = :nro_tracking
                      AND nro_paquete = :nro_paquete
                    LIMIT 1
                ";

                $stmtEditar = $pdo->prepare($sqlEditar);

                $stmtEditar->execute([
                    ':nro_tracking' => $tracking_editar,
                    ':nro_paquete' => $paquete_editar
                ]);

                $filaEditar = $stmtEditar->fetch();

                if ($filaEditar) {
                    $modo_edicion = true;
                    $nro_tracking = $filaEditar['nro_tracking'];
                    $nro_paquete = $filaEditar['nro_paquete'];
                    $peso_kg = $filaEditar['peso_kg'];
                    $largo_cm = $filaEditar['largo_cm'];
                    $ancho_cm = $filaEditar['ancho_cm'];
                    $alto_cm = $filaEditar['alto_cm'];
                    $fragil = (string) $filaEditar['fragil'];
                    $descripcion = $filaEditar['descripcion'] ?? '';
                    $cod_tipo_contenido = $filaEditar['cod_tipo_contenido'];
                    $cod_categoria_paquete = $filaEditar['cod_categoria_paquete'];
                }
            }

        } catch (PDOException $e) {

            $mensaje = 'No se pudo cargar el paquete para edición.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 6. CARGAR ENVÍOS DISPONIBLES
// -----------------------------------------------------

try {

    if ($rol_actual === 'CLIENTE' && $dni_cliente_sesion !== '') {

        $sqlEnvios = "
            SELECT
                e.nro_tracking,
                e.fecha_recepcion,
                so.nombre AS nombre_origen,
                sd.nombre AS nombre_destino,
                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario
            FROM Envio e
            INNER JOIN Sucursal so
                ON e.cod_sucursal_origen = so.cod_sucursal
            INNER JOIN Sucursal sd
                ON e.cod_sucursal_destino = sd.cod_sucursal
            INNER JOIN vista_cliente cd
                ON e.dni_destinatario = cd.dni
            WHERE e.dni_remitente = :dni_remitente
            ORDER BY e.fecha_recepcion DESC, e.nro_tracking DESC
        ";

        $stmtEnvios = $pdo->prepare($sqlEnvios);
        $stmtEnvios->execute([
            ':dni_remitente' => $dni_cliente_sesion
        ]);

        $envios_disponibles = $stmtEnvios->fetchAll();

    } else {

        $sqlEnvios = "
            SELECT
                e.nro_tracking,
                e.fecha_recepcion,
                so.nombre AS nombre_origen,
                sd.nombre AS nombre_destino,
                cr.nombre AS nombre_remitente,
                cr.apellido AS apellido_remitente
            FROM Envio e
            INNER JOIN Sucursal so
                ON e.cod_sucursal_origen = so.cod_sucursal
            INNER JOIN Sucursal sd
                ON e.cod_sucursal_destino = sd.cod_sucursal
            INNER JOIN vista_cliente cr
                ON e.dni_remitente = cr.dni
            ORDER BY e.fecha_recepcion DESC, e.nro_tracking DESC
        ";

        $envios_disponibles = $pdo->query($sqlEnvios)->fetchAll();
    }

    $tipos_contenido = $pdo->query("
        SELECT cod_tipo_contenido, nombre
        FROM Tipo_Contenido
        ORDER BY nombre ASC
    ")->fetchAll();

    $categorias_paquete = $pdo->query("
        SELECT cod_categoria_paquete, nombre
        FROM Categoria_Paquete
        ORDER BY nombre ASC
    ")->fetchAll();

} catch (PDOException $e) {
    $mensaje = 'No se pudieron cargar los datos auxiliares.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 7. VALIDAR TRACKING SELECCIONADO Y CARGAR SOLICITUD
// -----------------------------------------------------

if ($nro_tracking !== '') {

    try {

        if ($rol_actual === 'CLIENTE' && !clientePuedeAccederTracking($pdo, $nro_tracking, $dni_cliente_sesion)) {
            $mensaje = 'No tenés permiso para acceder a ese envío.';
            $tipo_mensaje = 'error';
            $nro_tracking = '';
        } else {

            $sqlSolicitud = "
                SELECT
                    e.nro_tracking,
                    e.fecha_recepcion,
                    so.nombre AS nombre_origen,
                    sd.nombre AS nombre_destino
                FROM Envio e
                INNER JOIN Sucursal so
                    ON e.cod_sucursal_origen = so.cod_sucursal
                INNER JOIN Sucursal sd
                    ON e.cod_sucursal_destino = sd.cod_sucursal
                WHERE e.nro_tracking = :nro_tracking
                LIMIT 1
            ";

            $stmtSolicitud = $pdo->prepare($sqlSolicitud);
            $stmtSolicitud->execute([
                ':nro_tracking' => $nro_tracking
            ]);

            $solicitud_actual = $stmtSolicitud->fetch();

            if (!$solicitud_actual) {
                $mensaje = 'No se encontró el envío seleccionado.';
                $tipo_mensaje = 'error';
                $nro_tracking = '';
            }
        }

    } catch (PDOException $e) {
        $mensaje = 'No se pudo cargar la solicitud seleccionada.';
        $tipo_mensaje = 'error';
    }
}


// -----------------------------------------------------
// 8. GENERAR NRO PAQUETE SI CORRESPONDE
// -----------------------------------------------------

if (!$modo_edicion && $nro_tracking !== '' && $nro_paquete === '') {
    try {
        $nro_paquete = (string) obtenerProximoNumeroPaqueteCliente($pdo, $nro_tracking);
    } catch (PDOException $e) {
        $nro_paquete = '1';
    }
}


// -----------------------------------------------------
// 9. CARGAR PAQUETES DEL TRACKING
// -----------------------------------------------------

if ($nro_tracking !== '') {

    try {

        $sqlPaquetes = "
            SELECT
                p.nro_tracking,
                p.nro_paquete,
                p.peso_kg,
                p.largo_cm,
                p.ancho_cm,
                p.alto_cm,
                p.fragil,
                p.descripcion,
                tc.nombre AS tipo_contenido,
                cp.nombre AS categoria_paquete
            FROM Paquete p
            INNER JOIN Tipo_Contenido tc
                ON p.cod_tipo_contenido = tc.cod_tipo_contenido
            INNER JOIN Categoria_Paquete cp
                ON p.cod_categoria_paquete = cp.cod_categoria_paquete
            WHERE p.nro_tracking = :nro_tracking
            ORDER BY p.nro_paquete ASC
        ";

        $stmtPaquetes = $pdo->prepare($sqlPaquetes);
        $stmtPaquetes->execute([
            ':nro_tracking' => $nro_tracking
        ]);

        $paquetes = $stmtPaquetes->fetchAll();

    } catch (PDOException $e) {
        $mensaje = 'No se pudieron cargar los paquetes del envío seleccionado.';
        $tipo_mensaje = 'error';
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<style>
    .package-label-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 18px;
    }

    .package-label-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(280px, 1fr));
        gap: 16px;
    }

    .package-label {
        border: 2px dashed var(--color-text);
        border-radius: 10px;
        padding: 16px;
        background: #fff;
        color: #111;
        min-height: 240px;
        page-break-inside: avoid;
    }

    .package-label-header {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        align-items: flex-start;
        border-bottom: 1px solid #222;
        padding-bottom: 10px;
        margin-bottom: 12px;
    }

    .package-label-title {
        margin: 0;
        font-size: 18px;
        color: #111;
    }

    .package-label-code {
        font-size: 13px;
        font-weight: 800;
        text-align: right;
        word-break: break-word;
    }

    .package-label-barcode {
        margin: 12px 0;
        height: 58px;
        border: 1px solid #222;
        background:
            repeating-linear-gradient(
                90deg,
                #111 0 2px,
                #fff 2px 5px,
                #111 5px 8px,
                #fff 8px 12px,
                #111 12px 13px,
                #fff 13px 18px
            );
    }

    .package-label-data {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px 14px;
        font-size: 13px;
        line-height: 1.35;
    }

    .package-label-data strong {
        display: block;
        font-size: 11px;
        text-transform: uppercase;
        color: #444;
    }

    .package-label-warning {
        margin-top: 12px;
        padding: 8px 10px;
        border: 1px solid #222;
        font-size: 13px;
        font-weight: 800;
        text-align: center;
    }

    @media print {
        .app-navbar,
        .app-sidebar,
        .sidebar,
        .app-footer,
        .page-header,
        .package-label-actions,
        .no-print {
            display: none !important;
        }

        .app-content {
            margin: 0 !important;
            padding: 0 !important;
        }

        .dashboard-card {
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }

        .package-label-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10mm;
        }

        .package-label {
            min-height: 72mm;
        }
    }
</style>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Cargar Paquetes</h1>
        <p class="page-subtitle">
            Después de crear la solicitud, cargá todos los paquetes que componen el envío.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Seleccionar solicitud</h3>

        <form method="GET" action="paquetes_cargar.php">

            <div class="dashboard-grid" style="grid-template-columns: 2fr 1fr;">

                <div class="form-group">
                    <label for="tracking">
                        <?php echo $rol_actual === 'CLIENTE' ? 'Mis solicitudes' : 'Solicitudes del sistema'; ?>
                    </label>

                    <select id="tracking" name="tracking" class="form-control" required>
                        <option value="">Seleccione un envío</option>

                        <?php foreach ($envios_disponibles as $envio): ?>
                            <option
                                value="<?php echo htmlspecialchars($envio['nro_tracking']); ?>"
                                <?php echo ($nro_tracking === $envio['nro_tracking']) ? 'selected' : ''; ?>
                            >
                                <?php
                                    if ($rol_actual === 'CLIENTE') {
                                        echo htmlspecialchars(
                                            $envio['nro_tracking'] . ' | ' .
                                            $envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario'] . ' | ' .
                                            $envio['nombre_origen'] . ' → ' . $envio['nombre_destino']
                                        );
                                    } else {
                                        echo htmlspecialchars(
                                            $envio['nro_tracking'] . ' | ' .
                                            $envio['apellido_remitente'] . ', ' . $envio['nombre_remitente'] . ' | ' .
                                            $envio['nombre_origen'] . ' → ' . $envio['nombre_destino']
                                        );
                                    }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Cargar solicitud
                    </button>

                    <a href="paquetes_cargar.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <?php if ($nro_tracking !== '' && $solicitud_actual): ?>

        <section class="dashboard-card" style="margin-bottom: 24px;">

            <h3 style="margin-top: 0; margin-bottom: 10px;">
                Solicitud seleccionada: <?php echo htmlspecialchars($solicitud_actual['nro_tracking']); ?>
            </h3>

            <p class="field-note" style="margin-bottom: 18px;">
                Origen: <?php echo htmlspecialchars($solicitud_actual['nombre_origen']); ?>
                |
                Destino: <?php echo htmlspecialchars($solicitud_actual['nombre_destino']); ?>
                |
                Fecha: <?php echo htmlspecialchars($solicitud_actual['fecha_recepcion']); ?>
            </p>

            <h3 style="margin-top: 0; margin-bottom: 18px;">
                <?php echo $modo_edicion ? 'Editar paquete' : 'Registrar paquete'; ?>
            </h3>

            <form method="POST" action="paquetes_cargar.php">

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
                            <label for="nro_paquete">Número de paquete</label>
                            <input
                                type="text"
                                id="nro_paquete"
                                name="nro_paquete"
                                class="form-control"
                                value="<?php echo htmlspecialchars($nro_paquete); ?>"
                                readonly
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="peso_kg">Peso (kg)</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                id="peso_kg"
                                name="peso_kg"
                                class="form-control"
                                value="<?php echo htmlspecialchars($peso_kg); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="largo_cm">Largo (cm)</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                id="largo_cm"
                                name="largo_cm"
                                class="form-control"
                                value="<?php echo htmlspecialchars($largo_cm); ?>"
                                required
                            >
                        </div>

                    </div>

                    <div>

                        <div class="form-group">
                            <label for="ancho_cm">Ancho (cm)</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                id="ancho_cm"
                                name="ancho_cm"
                                class="form-control"
                                value="<?php echo htmlspecialchars($ancho_cm); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="alto_cm">Alto (cm)</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                id="alto_cm"
                                name="alto_cm"
                                class="form-control"
                                value="<?php echo htmlspecialchars($alto_cm); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="cod_tipo_contenido">Tipo de contenido</label>
                            <select id="cod_tipo_contenido" name="cod_tipo_contenido" class="form-control" required>
                                <option value="">Seleccione un tipo</option>

                                <?php foreach ($tipos_contenido as $tipo): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($tipo['cod_tipo_contenido']); ?>"
                                        <?php echo ($cod_tipo_contenido === $tipo['cod_tipo_contenido']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($tipo['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="cod_categoria_paquete">Categoría del paquete</label>
                            <select id="cod_categoria_paquete" name="cod_categoria_paquete" class="form-control" required>
                                <option value="">Seleccione una categoría</option>

                                <?php foreach ($categorias_paquete as $categoria): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($categoria['cod_categoria_paquete']); ?>"
                                        <?php echo ($cod_categoria_paquete === $categoria['cod_categoria_paquete']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($categoria['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>

                </div>

                <div class="dashboard-grid" style="grid-template-columns: 1fr 2fr; margin-top: 10px;">

                    <div class="form-group">
                        <label for="fragil">¿Es frágil?</label>
                        <select id="fragil" name="fragil" class="form-control" required>
                            <option value="0" <?php echo $fragil === '0' ? 'selected' : ''; ?>>No</option>
                            <option value="1" <?php echo $fragil === '1' ? 'selected' : ''; ?>>Sí</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="descripcion">Descripción</label>
                        <textarea
                            id="descripcion"
                            name="descripcion"
                            class="form-control"
                            rows="4"
                        ><?php echo htmlspecialchars($descripcion); ?></textarea>
                    </div>

                </div>

                <div style="display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        <?php echo $modo_edicion ? 'Guardar cambios' : 'Registrar paquete'; ?>
                    </button>

                    <?php if ($modo_edicion): ?>
                        <a href="paquetes_cargar.php?tracking=<?php echo urlencode($nro_tracking); ?>" class="btn-public-secondary">
                            Cancelar edición
                        </a>
                    <?php endif; ?>
                </div>

            </form>

        </section>


        <section class="dashboard-card">

            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 18px; flex-wrap: wrap;">
                <h3 style="margin: 0;">Paquetes del envío seleccionado</h3>

                <?php if (!empty($paquetes)): ?>
                    <a href="paquetes_cargar.php?tracking=<?php echo urlencode($nro_tracking); ?>&etiquetas=1" class="btn-primary" style="width: auto;">
                        Terminar de cargar
                    </a>
                <?php endif; ?>
            </div>

            <div style="overflow-x: auto;">

                <table style="width: 100%; border-collapse: collapse; min-width: 1350px;">

                    <thead>
                        <tr style="background-color: var(--color-surface-soft);">
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">N° paquete</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Largo</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Ancho</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Alto</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Frágil</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tipo contenido</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Categoría</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Descripción</th>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($paquetes)): ?>

                            <tr>
                                <td colspan="10" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                    Todavía no hay paquetes cargados para este envío.
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($paquetes as $paquete): ?>
                                <tr>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['nro_paquete']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['peso_kg']); ?> kg
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['largo_cm']); ?> cm
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['ancho_cm']); ?> cm
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['alto_cm']); ?> cm
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo ((int) $paquete['fragil'] === 1) ? 'Sí' : 'No'; ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['tipo_contenido']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($paquete['categoria_paquete']); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border); max-width: 220px;">
                                        <?php echo htmlspecialchars($paquete['descripcion'] ?? ''); ?>
                                    </td>

                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">

                                        <a href="paquetes_cargar.php?tracking=<?php echo urlencode($paquete['nro_tracking']); ?>&paquete=<?php echo urlencode($paquete['nro_paquete']); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                            Editar
                                        </a>

                                        <form method="POST" action="paquetes_cargar.php?tracking=<?php echo urlencode($paquete['nro_tracking']); ?>" style="display: inline;" onsubmit="return confirm('¿Seguro que querés eliminar este paquete?');">
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="nro_tracking" value="<?php echo htmlspecialchars($paquete['nro_tracking']); ?>">
                                            <input type="hidden" name="nro_paquete" value="<?php echo htmlspecialchars($paquete['nro_paquete']); ?>">
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

        <?php if ($mostrar_etiquetas && !empty($paquetes)): ?>
            <section class="dashboard-card" style="margin-top: 24px;">
                <div class="package-label-actions">
                    <button type="button" class="btn-primary" style="width: auto;" onclick="window.print();">
                        Imprimir etiquetas
                    </button>

                    <a href="paquetes_cargar.php?tracking=<?php echo urlencode($nro_tracking); ?>" class="btn-public-secondary">
                        Seguir cargando paquetes
                    </a>
                </div>

                <h3 style="margin-top: 0; margin-bottom: 18px;">Etiquetas para pegar en los paquetes</h3>

                <div class="package-label-grid">
                    <?php foreach ($paquetes as $paquete): ?>
                        <?php
                            $codigo_etiqueta = generarCodigoEtiquetaPaquete(
                                (string) $paquete['nro_tracking'],
                                (string) $paquete['nro_paquete']
                            );
                        ?>

                        <article class="package-label">
                            <div class="package-label-header">
                                <div>
                                    <h4 class="package-label-title">LogiTrack</h4>
                                    <strong>Paquete <?php echo htmlspecialchars($paquete['nro_paquete']); ?></strong>
                                </div>

                                <div class="package-label-code">
                                    <?php echo htmlspecialchars($codigo_etiqueta); ?>
                                </div>
                            </div>

                            <div class="package-label-barcode" aria-hidden="true"></div>

                            <div class="package-label-data">
                                <div>
                                    <strong>Tracking</strong>
                                    <?php echo htmlspecialchars($paquete['nro_tracking']); ?>
                                </div>

                                <div>
                                    <strong>Origen</strong>
                                    <?php echo htmlspecialchars($solicitud_actual['nombre_origen']); ?>
                                </div>

                                <div>
                                    <strong>Destino</strong>
                                    <?php echo htmlspecialchars($solicitud_actual['nombre_destino']); ?>
                                </div>

                                <div>
                                    <strong>Contenido</strong>
                                    <?php echo htmlspecialchars($paquete['tipo_contenido']); ?>
                                </div>

                                <div>
                                    <strong>Categoria</strong>
                                    <?php echo htmlspecialchars($paquete['categoria_paquete']); ?>
                                </div>

                                <div>
                                    <strong>Peso</strong>
                                    <?php echo htmlspecialchars($paquete['peso_kg']); ?> kg
                                </div>

                                <div>
                                    <strong>Medidas</strong>
                                    <?php echo htmlspecialchars($paquete['largo_cm']); ?> x
                                    <?php echo htmlspecialchars($paquete['ancho_cm']); ?> x
                                    <?php echo htmlspecialchars($paquete['alto_cm']); ?> cm
                                </div>

                                <div>
                                    <strong>Codigo</strong>
                                    <?php echo htmlspecialchars($codigo_etiqueta); ?>
                                </div>
                            </div>

                            <?php if ((int) $paquete['fragil'] === 1): ?>
                                <div class="package-label-warning">
                                    FRAGIL
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    <?php endif; ?>

</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
