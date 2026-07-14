<?php
// =====================================================
// crear_solicitud_envio.php
// Solicitud de envío por cliente o admin
// - CLIENTE: remitente fijo según sesión
// - ADMIN: puede elegir remitente
// - genera tracking automático
// - guarda la solicitud
// - registra historial inicial como SOLICITUD_CREADA
// - redirige a carga de paquetes
// Nota: por no tocar la BD, se usa fecha_recepcion como
// fecha de solicitud en esta etapa del flujo.
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CLIENTE']);

$titulo_pagina = 'Crear Solicitud de Envío';

$mensaje = '';
$tipo_mensaje = '';

$clientes = [];
$sucursales = [];
$ultimas_solicitudes = [];

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$nro_tracking = '';
$fecha_solicitud = date('Y-m-d H:i:s');
$dni_remitente = '';
$dni_destinatario = '';
$cod_sucursal_origen = '';
$cod_sucursal_destino = '';

$cliente_actual = null;


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerDniClienteSesion(): string
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

function generarTrackingCliente(PDO $pdo): string
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

function normalizarFechaDatetimeLocalCliente(string $valor): string
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

function fechaClienteParaInput(?string $fecha): string
{
    if (!$fecha) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($fecha));
}

function obtenerEstadoInicialSolicitud(PDO $pdo): ?string
{
    $posibles_estados = [
        'SOLICITUD_CREADA',
        'RECIBIDO_EN_SUCURSAL',
        'RECIBIDO'
    ];

    foreach ($posibles_estados as $estado) {
        $sql = "
            SELECT cod_estado_envio
            FROM Estado_Envio
            WHERE cod_estado_envio = :estado
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':estado' => $estado
        ]);

        $fila = $stmt->fetch();

        if ($fila) {
            return $fila['cod_estado_envio'];
        }
    }

    return null;
}


// -----------------------------------------------------
// 1. IDENTIFICAR CLIENTE DE SESIÓN SI CORRESPONDE
// -----------------------------------------------------

if ($rol_actual === 'CLIENTE') {

    $dni_cliente_sesion = obtenerDniClienteSesion();

    if ($dni_cliente_sesion === '') {
        $mensaje = 'No se pudo identificar el cliente en la sesión.';
        $tipo_mensaje = 'error';
    } else {
        try {
            $sqlCliente = "
                SELECT dni, nombre, apellido
                FROM vista_cliente
                WHERE dni = :dni
                LIMIT 1
            ";

            $stmtCliente = $pdo->prepare($sqlCliente);
            $stmtCliente->execute([
                ':dni' => $dni_cliente_sesion
            ]);

            $cliente_actual = $stmtCliente->fetch();

            if ($cliente_actual) {
                $dni_remitente = $cliente_actual['dni'];
            } else {
                $mensaje = 'No se encontró el cliente asociado a la sesión actual.';
                $tipo_mensaje = 'error';
            }

        } catch (PDOException $e) {
            $mensaje = 'Ocurrió un error al obtener los datos del cliente actual.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 2. GUARDAR SOLICITUD
// -----------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {

    $nro_tracking = trim($_POST['nro_tracking'] ?? '');
    $fecha_solicitud = normalizarFechaDatetimeLocalCliente($_POST['fecha_solicitud'] ?? '');
    $dni_destinatario = trim($_POST['dni_destinatario'] ?? '');
    $cod_sucursal_origen = trim($_POST['cod_sucursal_origen'] ?? '');
    $cod_sucursal_destino = trim($_POST['cod_sucursal_destino'] ?? '');

    if ($rol_actual === 'CLIENTE') {
        $dni_remitente = obtenerDniClienteSesion();
    } else {
        $dni_remitente = trim($_POST['dni_remitente'] ?? '');
    }

    if (
        $nro_tracking === '' ||
        $fecha_solicitud === '' ||
        $dni_remitente === '' ||
        $dni_destinatario === '' ||
        $cod_sucursal_origen === '' ||
        $cod_sucursal_destino === ''
    ) {
        $mensaje = 'Completá todos los campos obligatorios.';
        $tipo_mensaje = 'error';

    } elseif ($dni_remitente === $dni_destinatario) {
        $mensaje = 'El remitente y el destinatario no pueden ser la misma persona.';
        $tipo_mensaje = 'error';

    } elseif ($cod_sucursal_origen === $cod_sucursal_destino) {
        $mensaje = 'La sucursal de origen y destino no pueden ser la misma.';
        $tipo_mensaje = 'error';

    } else {

        try {

            $pdo->beginTransaction();

            $sqlInsertarEnvio = "
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

            $stmtInsertarEnvio = $pdo->prepare($sqlInsertarEnvio);

            $stmtInsertarEnvio->execute([
                ':nro_tracking' => $nro_tracking,
                ':fecha_recepcion' => $fecha_solicitud,
                ':dni_remitente' => $dni_remitente,
                ':dni_destinatario' => $dni_destinatario,
                ':cod_sucursal_origen' => $cod_sucursal_origen,
                ':cod_sucursal_destino' => $cod_sucursal_destino
            ]);

            $estado_inicial = obtenerEstadoInicialSolicitud($pdo);

            if ($estado_inicial !== null) {

                $sqlInsertarHistorial = "
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

                $stmtInsertarHistorial = $pdo->prepare($sqlInsertarHistorial);

                $stmtInsertarHistorial->execute([
                    ':nro_tracking' => $nro_tracking,
                    ':cod_estado_envio' => $estado_inicial,
                    ':fecha_hora' => $fecha_solicitud,
                    ':cod_sucursal_actual' => $cod_sucursal_origen,
                    ':observaciones' => 'Solicitud creada por cliente'
                ]);
                $stmtInsertarHistorial->closeCursor();
            }

            $pdo->commit();

            header('Location: paquetes_cargar.php?tracking=' . urlencode($nro_tracking) . '&creado=1');
            exit;

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $mensaje = 'Ocurrió un error al registrar la solicitud de envío.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 3. GENERAR TRACKING
// -----------------------------------------------------

if ($nro_tracking === '') {
    try {
        $nro_tracking = generarTrackingCliente($pdo);
    } catch (PDOException $e) {
        $nro_tracking = 'TRK000001';
    }
}


// -----------------------------------------------------
// 4. CARGAR DATOS AUXILIARES
// -----------------------------------------------------

try {

    $sucursales = $pdo->query("
        SELECT cod_sucursal, nombre
        FROM Sucursal
        ORDER BY nombre ASC
    ")->fetchAll();

    if ($rol_actual === 'ADMIN') {
        $clientes = $pdo->query("
            SELECT dni, nombre, apellido
            FROM vista_cliente
            ORDER BY apellido ASC, nombre ASC
        ")->fetchAll();
    } else {
        $sqlDestinatarios = "
            SELECT dni, nombre, apellido
            FROM vista_cliente
            WHERE dni <> :dni_cliente
            ORDER BY apellido ASC, nombre ASC
        ";

        $stmtDestinatarios = $pdo->prepare($sqlDestinatarios);
        $stmtDestinatarios->execute([
            ':dni_cliente' => $dni_remitente
        ]);

        $clientes = $stmtDestinatarios->fetchAll();
    }

} catch (PDOException $e) {
    $mensaje = 'No se pudieron cargar los datos auxiliares.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 5. CARGAR ÚLTIMAS SOLICITUDES
// -----------------------------------------------------

try {

    if ($rol_actual === 'CLIENTE' && $dni_remitente !== '') {

        $sqlUltimas = "
            SELECT
                e.nro_tracking,
                e.fecha_recepcion,
                e.dni_destinatario,
                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,
                so.nombre AS nombre_origen,
                sd.nombre AS nombre_destino
            FROM Envio e
            INNER JOIN vista_cliente cd
                ON e.dni_destinatario = cd.dni
            INNER JOIN Sucursal so
                ON e.cod_sucursal_origen = so.cod_sucursal
            INNER JOIN Sucursal sd
                ON e.cod_sucursal_destino = sd.cod_sucursal
            WHERE e.dni_remitente = :dni_remitente
            ORDER BY e.fecha_recepcion DESC, e.nro_tracking DESC
            LIMIT 10
        ";

        $stmtUltimas = $pdo->prepare($sqlUltimas);
        $stmtUltimas->execute([
            ':dni_remitente' => $dni_remitente
        ]);

        $ultimas_solicitudes = $stmtUltimas->fetchAll();

    } else {

        $sqlUltimas = "
            SELECT
                e.nro_tracking,
                e.fecha_recepcion,
                e.dni_remitente,
                e.dni_destinatario,
                cr.nombre AS nombre_remitente,
                cr.apellido AS apellido_remitente,
                cd.nombre AS nombre_destinatario,
                cd.apellido AS apellido_destinatario,
                so.nombre AS nombre_origen,
                sd.nombre AS nombre_destino
            FROM Envio e
            INNER JOIN vista_cliente cr
                ON e.dni_remitente = cr.dni
            INNER JOIN vista_cliente cd
                ON e.dni_destinatario = cd.dni
            INNER JOIN Sucursal so
                ON e.cod_sucursal_origen = so.cod_sucursal
            INNER JOIN Sucursal sd
                ON e.cod_sucursal_destino = sd.cod_sucursal
            ORDER BY e.fecha_recepcion DESC, e.nro_tracking DESC
            LIMIT 10
        ";

        $ultimas_solicitudes = $pdo->query($sqlUltimas)->fetchAll();
    }

} catch (PDOException $e) {
    $mensaje = 'No se pudieron cargar las últimas solicitudes.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Crear Solicitud de Envío</h1>
        <p class="page-subtitle">
            Primero se genera la solicitud y luego se cargan los paquetes que componen el envío.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Nueva solicitud</h3>

        <form method="POST" action="crear_solicitud_envio.php">

            <input type="hidden" name="accion" value="guardar">

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
                        <label for="fecha_solicitud">Fecha de solicitud</label>
                        <input
                            type="datetime-local"
                            id="fecha_solicitud"
                            name="fecha_solicitud"
                            class="form-control"
                            value="<?php echo htmlspecialchars(fechaClienteParaInput($fecha_solicitud)); ?>"
                            required
                        >
                    </div>

                    <?php if ($rol_actual === 'ADMIN'): ?>

                        <div class="form-group">
                            <label for="dni_remitente">Remitente</label>
                            <select id="dni_remitente" name="dni_remitente" class="form-control" required>
                                <option value="">Seleccione un cliente</option>

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

                    <?php else: ?>

                        <div class="form-group">
                            <label for="remitente_mostrar">Remitente</label>
                            <input
                                type="text"
                                id="remitente_mostrar"
                                class="form-control"
                                value="<?php echo $cliente_actual ? htmlspecialchars($cliente_actual['dni'] . ' - ' . $cliente_actual['apellido'] . ', ' . $cliente_actual['nombre']) : ''; ?>"
                                readonly
                            >
                        </div>

                    <?php endif; ?>

                    <div class="form-group">
                        <label for="dni_destinatario">Destinatario</label>
                        <select id="dni_destinatario" name="dni_destinatario" class="form-control" required>
                            <option value="">Seleccione un cliente</option>

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

                </div>

                <div>

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

                    <p class="field-note">
                        Al guardar la solicitud, el sistema te redirige automáticamente a la carga de paquetes.
                    </p>

                    <div style="display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap;">
                        <button type="submit" class="btn-primary" style="width: auto;">
                            Crear solicitud y continuar
                        </button>
                    </div>

                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $rol_actual === 'CLIENTE' ? 'Mis últimas solicitudes' : 'Últimas solicitudes registradas'; ?>
        </h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1200px;">

                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Tracking</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha</th>
                        <?php if ($rol_actual === 'ADMIN'): ?>
                            <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Remitente</th>
                        <?php endif; ?>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destinatario</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Origen</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Destino</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($ultimas_solicitudes)): ?>

                        <tr>
                            <td colspan="<?php echo $rol_actual === 'ADMIN' ? '6' : '5'; ?>" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay solicitudes registradas todavía.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($ultimas_solicitudes as $solicitud): ?>
                            <tr>
                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($solicitud['nro_tracking']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($solicitud['fecha_recepcion']); ?>
                                </td>

                                <?php if ($rol_actual === 'ADMIN'): ?>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($solicitud['dni_remitente'] . ' - ' . $solicitud['apellido_remitente'] . ', ' . $solicitud['nombre_remitente']); ?>
                                    </td>
                                <?php endif; ?>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($solicitud['dni_destinatario'] . ' - ' . $solicitud['apellido_destinatario'] . ', ' . $solicitud['nombre_destinatario']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($solicitud['nombre_origen']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($solicitud['nombre_destino']); ?>
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
