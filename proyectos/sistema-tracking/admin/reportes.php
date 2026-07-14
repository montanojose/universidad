<?php
// =====================================================
// reportes.php
// Reportes generales del sistema
// - indicadores generales
// - envíos por último estado
// - viajes por estado
// - vehículos por estado
// - empleados por estado
// - incidentes por tipo
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Reportes Generales';

$mensaje = '';
$tipo_mensaje = '';

$resumen = [
    'sucursales' => 0,
    'clientes' => 0,
    'vehiculos' => 0,
    'choferes' => 0,
    'empleados' => 0,
    'usuarios' => 0,
    'envios' => 0,
    'paquetes' => 0,
    'viajes' => 0,
    'incidentes' => 0
];

$envios_por_estado = [];
$viajes_por_estado = [];
$vehiculos_por_estado = [];
$empleados_por_estado = [];
$incidentes_por_tipo = [];

try {

    $resumen['sucursales'] = (int) $pdo->query("SELECT COUNT(*) FROM Sucursal")->fetchColumn();
    $resumen['clientes'] = (int) $pdo->query("SELECT COUNT(*) FROM Cliente")->fetchColumn();
    $resumen['vehiculos'] = (int) $pdo->query("SELECT COUNT(*) FROM Vehiculo")->fetchColumn();
    $resumen['choferes'] = (int) $pdo->query("SELECT COUNT(*) FROM Chofer")->fetchColumn();
    $resumen['empleados'] = (int) $pdo->query("SELECT COUNT(*) FROM Empleado_Sucursal")->fetchColumn();
    $resumen['usuarios'] = (int) $pdo->query("SELECT COUNT(*) FROM Usuario")->fetchColumn();
    $resumen['envios'] = (int) $pdo->query("SELECT COUNT(*) FROM Envio")->fetchColumn();
    $resumen['paquetes'] = (int) $pdo->query("SELECT COUNT(*) FROM Paquete")->fetchColumn();
    $resumen['viajes'] = (int) $pdo->query("SELECT COUNT(*) FROM Viaje")->fetchColumn();
    $resumen['incidentes'] = (int) $pdo->query("SELECT COUNT(*) FROM Incidente")->fetchColumn();


    $envios_por_estado = $pdo->query("
        SELECT
            COALESCE(ee.nombre, 'Sin historial') AS estado,
            COUNT(*) AS cantidad
        FROM Envio e
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
        GROUP BY COALESCE(ee.nombre, 'Sin historial')
        ORDER BY cantidad DESC, estado ASC
    ")->fetchAll();


    $viajes_por_estado = $pdo->query("
        SELECT
            ev.nombre AS estado,
            COUNT(*) AS cantidad
        FROM Viaje v
        INNER JOIN Estado_Viaje ev
            ON v.cod_estado_viaje = ev.cod_estado_viaje
        GROUP BY ev.nombre
        ORDER BY cantidad DESC, ev.nombre ASC
    ")->fetchAll();


    $vehiculos_por_estado = $pdo->query("
        SELECT
            ev.nombre AS estado,
            COUNT(*) AS cantidad
        FROM Vehiculo v
        INNER JOIN Estado_Vehiculo ev
            ON v.cod_estado_vehiculo = ev.cod_estado_vehiculo
        GROUP BY ev.nombre
        ORDER BY cantidad DESC, ev.nombre ASC
    ")->fetchAll();


    $empleados_por_estado = $pdo->query("
        SELECT
            estado,
            COUNT(*) AS cantidad
        FROM vista_empleado_sucursal
        GROUP BY estado
        ORDER BY cantidad DESC, estado ASC
    ")->fetchAll();


    $incidentes_por_tipo = $pdo->query("
        SELECT
            ti.nombre AS tipo,
            COUNT(*) AS cantidad
        FROM Incidente i
        INNER JOIN Tipo_Incidente ti
            ON i.cod_tipo_incidente = ti.cod_tipo_incidente
        GROUP BY ti.nombre
        ORDER BY cantidad DESC, ti.nombre ASC
    ")->fetchAll();

} catch (PDOException $e) {

    $mensaje = 'Ocurrió un error al generar los reportes.';
    $tipo_mensaje = 'error';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Reportes Generales</h1>
        <p class="page-subtitle">
            En esta pantalla se visualizan indicadores globales y resúmenes operativos del sistema.
        </p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="alert <?php echo $tipo_mensaje === 'success' ? 'alert-success' : ($tipo_mensaje === 'warning' ? 'alert-warning' : 'alert-error'); ?>">
            <?php echo htmlspecialchars($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="dashboard-grid" style="margin-bottom: 24px;">

        <article class="dashboard-card">
            <h3>Sucursales</h3>
            <p>Total registradas: <strong><?php echo htmlspecialchars((string) $resumen['sucursales']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Clientes</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['clientes']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Vehículos</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['vehiculos']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Choferes</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['choferes']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Empleados</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['empleados']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Usuarios</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['usuarios']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Envíos</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['envios']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Paquetes</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['paquetes']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Viajes</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['viajes']); ?></strong></p>
        </article>

        <article class="dashboard-card">
            <h3>Incidentes</h3>
            <p>Total registrados: <strong><?php echo htmlspecialchars((string) $resumen['incidentes']); ?></strong></p>
        </article>

    </section>


    <section class="dashboard-grid" style="grid-template-columns: repeat(2, 1fr);">

        <section class="dashboard-card">
            <h3 style="margin-top: 0; margin-bottom: 18px;">Envíos por último estado</h3>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($envios_por_estado)): ?>
                        <tr>
                            <td colspan="2" style="padding: 12px; color: var(--color-muted);">Sin datos.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($envios_por_estado as $fila): ?>
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['estado']); ?></td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['cantidad']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>


        <section class="dashboard-card">
            <h3 style="margin-top: 0; margin-bottom: 18px;">Viajes por estado</h3>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($viajes_por_estado)): ?>
                        <tr>
                            <td colspan="2" style="padding: 12px; color: var(--color-muted);">Sin datos.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($viajes_por_estado as $fila): ?>
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['estado']); ?></td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['cantidad']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>


        <section class="dashboard-card">
            <h3 style="margin-top: 0; margin-bottom: 18px;">Vehículos por estado</h3>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vehiculos_por_estado)): ?>
                        <tr>
                            <td colspan="2" style="padding: 12px; color: var(--color-muted);">Sin datos.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vehiculos_por_estado as $fila): ?>
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['estado']); ?></td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['cantidad']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>


        <section class="dashboard-card">
            <h3 style="margin-top: 0; margin-bottom: 18px;">Empleados por estado</h3>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Estado</th>
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($empleados_por_estado)): ?>
                        <tr>
                            <td colspan="2" style="padding: 12px; color: var(--color-muted);">Sin datos.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($empleados_por_estado as $fila): ?>
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['estado']); ?></td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['cantidad']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>


        <section class="dashboard-card" style="grid-column: span 2;">
            <h3 style="margin-top: 0; margin-bottom: 18px;">Incidentes por tipo</h3>

            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background-color: var(--color-surface-soft);">
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Tipo de incidente</th>
                        <th style="text-align: left; padding: 10px; border-bottom: 1px solid var(--color-border);">Cantidad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($incidentes_por_tipo)): ?>
                        <tr>
                            <td colspan="2" style="padding: 12px; color: var(--color-muted);">Sin datos.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($incidentes_por_tipo as $fila): ?>
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['tipo']); ?></td>
                                <td style="padding: 10px; border-bottom: 1px solid var(--color-border);"><?php echo htmlspecialchars($fila['cantidad']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>

    </section>

</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>