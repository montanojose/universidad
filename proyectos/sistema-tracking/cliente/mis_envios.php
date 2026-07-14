<?php
// =====================================================
// mis_envios.php
// Envíos enviados por el cliente
// - acceso para CLIENTE y ADMIN
// - CLIENTE: ve solo los envíos donde es remitente
// - ADMIN: puede ver todos y filtrar por cliente
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CLIENTE']);

$titulo_pagina = 'Mis Envíos Enviados';

$mensaje = '';
$tipo_mensaje = '';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

$dni_cliente_sesion = '';
$dni_cliente_filtro = trim($_GET['dni_cliente'] ?? '');
$buscar = trim($_GET['buscar'] ?? '');
$filtro_estado = trim($_GET['filtro_estado'] ?? '');
$buscar_es_tracking = $buscar !== '' && preg_match('/^(TRK)?[0-9]+$/i', preg_replace('/[^a-zA-Z0-9]/', '', $buscar));

$cliente_actual = null;
$clientes = [];
$estados_envio = [];
$envios = [];

$resumen = [
    'total' => 0,
    'en_proceso' => 0,
    'disponibles_retiro' => 0,
    'retirados' => 0
];


// -----------------------------------------------------
// FUNCIONES AUXILIARES
// -----------------------------------------------------

function obtenerDniClienteSesionMisEnvios(): string
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

function normalizarTextoEstado(?string $valor): string
{
    return strtoupper(str_replace(' ', '_', (string) $valor));
}


// -----------------------------------------------------
// 1. IDENTIFICAR CLIENTE SI ENTRA COMO CLIENTE
// -----------------------------------------------------

if ($rol_actual === 'CLIENTE') {

    $dni_cliente_sesion = obtenerDniClienteSesionMisEnvios();

    if ($dni_cliente_sesion === '') {

        $mensaje = 'No se pudo identificar el cliente en la sesión.';
        $tipo_mensaje = 'error';

    } else {

        $dni_cliente_filtro = $dni_cliente_sesion;

        try {

            $sqlClienteActual = "
                SELECT
                    dni,
                    nombre,
                    apellido,
                    telefono,
                    email
                FROM vista_cliente
                WHERE dni = :dni
                LIMIT 1
            ";

            $stmtClienteActual = $pdo->prepare($sqlClienteActual);
            $stmtClienteActual->execute([
                ':dni' => $dni_cliente_sesion
            ]);

            $cliente_actual = $stmtClienteActual->fetch();

            if (!$cliente_actual) {
                $mensaje = 'No se encontró el cliente asociado a la sesión actual.';
                $tipo_mensaje = 'error';
            }

        } catch (PDOException $e) {

            $mensaje = 'Ocurrió un error al cargar los datos del cliente actual.';
            $tipo_mensaje = 'error';
        }
    }
}


// -----------------------------------------------------
// 2. CARGAR CATÁLOGOS
// -----------------------------------------------------

try {

    $sqlEstados = "
        SELECT
            cod_estado_envio,
            nombre
        FROM Estado_Envio
        ORDER BY nombre ASC
    ";

    $estados_envio = $pdo->query($sqlEstados)->fetchAll();

    if ($rol_actual === 'ADMIN') {

        $sqlClientes = "
            SELECT
                dni,
                nombre,
                apellido
            FROM vista_cliente
            ORDER BY apellido ASC, nombre ASC
        ";

        $clientes = $pdo->query($sqlClientes)->fetchAll();
    }

} catch (PDOException $e) {

    $mensaje = 'No se pudieron cargar los datos auxiliares.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 3. CARGAR LISTADO DE ENVÍOS
// -----------------------------------------------------

try {

    $sqlEnvios = "
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

            so.nombre AS nombre_origen,
            sd.nombre AS nombre_destino,

            he.cod_estado_envio AS cod_estado_actual,
            ee.nombre AS nombre_estado_actual,
            he.fecha_hora AS fecha_estado_actual,

            COALESCE(pkg.cantidad_paquetes, 0) AS cantidad_paquetes,
            COALESCE(pkg.peso_total_kg, 0) AS peso_total_kg,

            CASE
                WHEN dr.nro_tracking IS NOT NULL THEN 1
                ELSE 0
            END AS disponible_retiro,

            CASE
                WHEN re.nro_tracking IS NOT NULL THEN 1
                ELSE 0
            END AS retirado
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
            SELECT h1.nro_tracking, h1.cod_estado_envio, h1.fecha_hora
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

        LEFT JOIN (
            SELECT
                nro_tracking,
                COUNT(*) AS cantidad_paquetes,
                COALESCE(SUM(peso_kg), 0) AS peso_total_kg
            FROM Paquete
            GROUP BY nro_tracking
        ) pkg
            ON e.nro_tracking = pkg.nro_tracking

        LEFT JOIN Disponibilidad_Retiro dr
            ON e.nro_tracking = dr.nro_tracking

        LEFT JOIN Retiro_Envio re
            ON e.nro_tracking = re.nro_tracking

        WHERE 1 = 1
    ";

    $params = [];

    if ($dni_cliente_filtro !== '' && !($rol_actual === 'ADMIN' && $buscar_es_tracking)) {
        $sqlEnvios .= " AND e.dni_remitente = :dni_remitente ";
        $params[':dni_remitente'] = $dni_cliente_filtro;
    }

    if ($buscar_es_tracking) {
        $sqlEnvios .= " AND e.nro_tracking LIKE :buscar ";
        $params[':buscar'] = '%' . $buscar . '%';
    } elseif ($buscar !== '') {
        $sqlEnvios .= "
            AND (
                e.nro_tracking LIKE :buscar
                OR cd.nombre LIKE :buscar
                OR cd.apellido LIKE :buscar
                OR so.nombre LIKE :buscar
                OR sd.nombre LIKE :buscar
            )
        ";
        $params[':buscar'] = '%' . $buscar . '%';
    }

    if (!$buscar_es_tracking && $filtro_estado !== '') {
        $sqlEnvios .= " AND he.cod_estado_envio = :filtro_estado ";
        $params[':filtro_estado'] = $filtro_estado;
    }

    $sqlEnvios .= "
        ORDER BY
            e.fecha_recepcion DESC,
            e.nro_tracking DESC
    ";

    $stmtEnvios = $pdo->prepare($sqlEnvios);
    $stmtEnvios->execute($params);

    $envios = $stmtEnvios->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al cargar los envíos enviados.';
    $tipo_mensaje = 'error';
}


// -----------------------------------------------------
// 4. RESUMEN
// -----------------------------------------------------

if (!empty($envios)) {

    $resumen['total'] = count($envios);

    foreach ($envios as $envio) {

        $estado = normalizarTextoEstado($envio['cod_estado_actual'] ?? $envio['nombre_estado_actual'] ?? '');

        if ((int) $envio['disponible_retiro'] === 1) {
            $resumen['disponibles_retiro']++;
        }

        if ((int) $envio['retirado'] === 1) {
            $resumen['retirados']++;
        }

        if (
            (int) $envio['retirado'] === 0 &&
            !str_contains($estado, 'RETIRADO') &&
            !str_contains($estado, 'ENTREGADO')
        ) {
            $resumen['en_proceso']++;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Mis Envíos Enviados</h1>
        <p class="page-subtitle">
            Consultá los envíos donde figurás como remitente y seguí el estado de cada tracking.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>


    <?php if ($rol_actual === 'CLIENTE' && $cliente_actual): ?>
        <section class="dashboard-card" style="margin-bottom: 24px;">
            <h3 style="margin-top: 0; margin-bottom: 12px;">Cliente actual</h3>

            <div class="dashboard-grid" style="grid-template-columns: repeat(3, 1fr);">
                <article>
                    <p><strong>DNI:</strong> <?php echo htmlspecialchars($cliente_actual['dni']); ?></p>
                </article>

                <article>
                    <p><strong>Nombre:</strong> <?php echo htmlspecialchars($cliente_actual['apellido'] . ', ' . $cliente_actual['nombre']); ?></p>
                </article>

                <article>
                    <p><strong>Contacto:</strong> <?php echo htmlspecialchars(($cliente_actual['telefono'] ?? '') . ' ' . ($cliente_actual['email'] ?? '')); ?></p>
                </article>
            </div>
        </section>
    <?php endif; ?>


    <section class="dashboard-grid" style="margin-bottom: 24px;">

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Total envíos</h3>
            <p style="font-size: 28px; margin: 0; font-weight: 700;">
                <?php echo htmlspecialchars((string) $resumen['total']); ?>
            </p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">En proceso</h3>
            <p style="font-size: 28px; margin: 0; font-weight: 700;">
                <?php echo htmlspecialchars((string) $resumen['en_proceso']); ?>
            </p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Disponibles para retiro</h3>
            <p style="font-size: 28px; margin: 0; font-weight: 700;">
                <?php echo htmlspecialchars((string) $resumen['disponibles_retiro']); ?>
            </p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Retirados</h3>
            <p style="font-size: 28px; margin: 0; font-weight: 700;">
                <?php echo htmlspecialchars((string) $resumen['retirados']); ?>
            </p>
        </article>

    </section>


    <section class="dashboard-card" style="margin-bottom: 24px;">

        <h3 style="margin-top: 0; margin-bottom: 18px;">Buscar y filtrar envíos</h3>

        <form method="GET" action="mis_envios.php">

            <div class="dashboard-grid" style="grid-template-columns: <?php echo $rol_actual === 'ADMIN' ? '1fr 2fr 1fr 1fr' : '2fr 1fr 1fr'; ?>;">

                <?php if ($rol_actual === 'ADMIN'): ?>
                    <div class="form-group">
                        <label for="dni_cliente">Remitente</label>
                        <select id="dni_cliente" name="dni_cliente" class="form-control">
                            <option value="">Todos los clientes</option>

                            <?php foreach ($clientes as $cliente): ?>
                                <option
                                    value="<?php echo htmlspecialchars($cliente['dni']); ?>"
                                    <?php echo ($dni_cliente_filtro === $cliente['dni']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($cliente['dni'] . ' - ' . $cliente['apellido'] . ', ' . $cliente['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="buscar">Buscar por tracking, destinatario u origen/destino</label>
                    <input
                        type="text"
                        id="buscar"
                        name="buscar"
                        class="form-control"
                        value="<?php echo htmlspecialchars($buscar); ?>"
                        placeholder="Ej: TRK000001, Pérez, Mendoza..."
                    >
                </div>

                <div class="form-group">
                    <label for="filtro_estado">Estado actual</label>
                    <select id="filtro_estado" name="filtro_estado" class="form-control">
                        <option value="">Todos</option>

                        <?php foreach ($estados_envio as $estado): ?>
                            <option
                                value="<?php echo htmlspecialchars($estado['cod_estado_envio']); ?>"
                                <?php echo ($filtro_estado === $estado['cod_estado_envio']) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($estado['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 12px;">
                    <button type="submit" class="btn-primary" style="width: auto;">
                        Buscar
                    </button>

                    <a href="mis_envios.php" class="btn-public-secondary">
                        Limpiar
                    </a>
                </div>

            </div>

        </form>

    </section>


    <section class="dashboard-card">

        <h3 style="margin-top: 0; margin-bottom: 18px;">
            <?php echo $rol_actual === 'CLIENTE' ? 'Mis envíos enviados' : 'Envíos enviados'; ?>
        </h3>

        <div style="overflow-x: auto;">

            <table style="width: 100%; border-collapse: collapse; min-width: 1650px;">

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
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Estado actual</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Fecha estado</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Paquetes</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Peso total</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Disponible retiro</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Retirado</th>
                        <th style="text-align: left; padding: 12px; border-bottom: 1px solid var(--color-border);">Acciones</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($envios)): ?>

                        <tr>
                            <td colspan="<?php echo $rol_actual === 'ADMIN' ? '13' : '12'; ?>" style="padding: 16px; text-align: center; color: var(--color-muted); font-style: italic;">
                                No hay envíos para mostrar con esos criterios.
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

                                <?php if ($rol_actual === 'ADMIN'): ?>
                                    <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                        <?php echo htmlspecialchars($envio['dni_remitente'] . ' - ' . $envio['apellido_remitente'] . ', ' . $envio['nombre_remitente']); ?>
                                    </td>
                                <?php endif; ?>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['dni_destinatario'] . ' - ' . $envio['apellido_destinatario'] . ', ' . $envio['nombre_destinatario']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['nombre_origen']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['nombre_destino']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['nombre_estado_actual'] ?? 'Sin historial'); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars($envio['fecha_estado_actual'] ?? ''); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars((string) $envio['cantidad_paquetes']); ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo htmlspecialchars(number_format((float) $envio['peso_total_kg'], 2, '.', '')); ?> kg
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo ((int) $envio['disponible_retiro'] === 1) ? 'Sí' : 'No'; ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border);">
                                    <?php echo ((int) $envio['retirado'] === 1) ? 'Sí' : 'No'; ?>
                                </td>

                                <td style="padding: 12px; border-bottom: 1px solid var(--color-border); white-space: nowrap;">
                                    <a href="detalle_envio.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary" style="margin-right: 8px;">
                                        Ver detalle
                                    </a>

                                    <a href="historial_envio.php?tracking=<?php echo urlencode($envio['nro_tracking']); ?>" class="btn-public-secondary">
                                        Ver historial
                                    </a>
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
