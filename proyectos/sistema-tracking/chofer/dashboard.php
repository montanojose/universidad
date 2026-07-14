<?php
require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CHOFER']);

$titulo_pagina = 'Dashboard Chofer';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

function obtenerLegajoChoferSesionDashboard(): string
{
    if (!empty($_SESSION['legajo_chofer'])) {
        return (string) $_SESSION['legajo_chofer'];
    }
    if (!empty($_SESSION['usuario_legajo_chofer'])) {
        return (string) $_SESSION['usuario_legajo_chofer'];
    }
    if (!empty($_SESSION['chofer_legajo'])) {
        return (string) $_SESSION['chofer_legajo'];
    }
    return '';
}

$legajo_chofer = '';
$nombre_chofer = '';

$stats = [
    'mis_viajes' => 0,
    'pendientes_inicio' => 0,
    'en_curso' => 0,
    'incidentes' => 0,
];

try {
    if ($rol_actual === 'CHOFER') {
        $legajo_chofer = obtenerLegajoChoferSesionDashboard();

        if ($legajo_chofer !== '') {
            $stmt = $pdo->prepare("SELECT apellido, nombre FROM vista_chofer WHERE legajo = :legajo LIMIT 1");
            $stmt->execute([':legajo' => $legajo_chofer]);
            $fila = $stmt->fetch();
            if ($fila) {
                $nombre_chofer = $fila['apellido'] . ', ' . $fila['nombre'];
            }
        }
    }

    if ($legajo_chofer !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Viaje WHERE legajo_chofer = :legajo");
        $stmt->execute([':legajo' => $legajo_chofer]);
        $stats['mis_viajes'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM Viaje
            WHERE legajo_chofer = :legajo
              AND UPPER(cod_estado_viaje) IN ('PROGRAMADO','PLANIFICADO','ASIGNADO','CREADO')
        ");
        $stmt->execute([':legajo' => $legajo_chofer]);
        $stats['pendientes_inicio'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM Viaje
            WHERE legajo_chofer = :legajo
              AND UPPER(cod_estado_viaje) IN ('EN_CURSO','EN_TRANSITO','INICIADO','EN_PROGRESO')
        ");
        $stmt->execute([':legajo' => $legajo_chofer]);
        $stats['en_curso'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM Incidente i
            INNER JOIN Viaje v ON i.patente = v.patente AND i.fecha_salida = v.fecha_salida
            WHERE v.legajo_chofer = :legajo
        ");
        $stmt->execute([':legajo' => $legajo_chofer]);
        $stats['incidentes'] = (int) $stmt->fetchColumn();

    } else {
        $stats['mis_viajes'] = (int) $pdo->query("SELECT COUNT(*) FROM Viaje")->fetchColumn();
        $stats['pendientes_inicio'] = (int) $pdo->query("
            SELECT COUNT(*) FROM Viaje
            WHERE UPPER(cod_estado_viaje) IN ('PROGRAMADO','PLANIFICADO','ASIGNADO','CREADO')
        ")->fetchColumn();
        $stats['en_curso'] = (int) $pdo->query("
            SELECT COUNT(*) FROM Viaje
            WHERE UPPER(cod_estado_viaje) IN ('EN_CURSO','EN_TRANSITO','INICIADO','EN_PROGRESO')
        ")->fetchColumn();
        $stats['incidentes'] = (int) $pdo->query("SELECT COUNT(*) FROM Incidente")->fetchColumn();
    }
} catch (PDOException $e) {
    // no cortar dashboard
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Panel de Chofer</h1>
        <p class="page-subtitle">
            Consultá tus viajes asignados y registrá las acciones del traslado.
        </p>
    </section>

    <?php if ($legajo_chofer !== ''): ?>
        <section class="dashboard-card" style="margin-bottom: 24px;">
            <p style="margin: 0;">
                <strong>Chofer actual:</strong>
                <?php echo htmlspecialchars($legajo_chofer . ' - ' . $nombre_chofer); ?>
            </p>
        </section>
    <?php endif; ?>

    <section class="dashboard-grid" style="margin-bottom: 24px;">
        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Mis viajes</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['mis_viajes']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Pendientes inicio</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['pendientes_inicio']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">En curso</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['en_curso']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Incidentes</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['incidentes']; ?></p>
        </article>
    </section>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Operaciones del chofer</h3>

        <div class="dashboard-grid">
            <article class="dashboard-card">
                <h4>Mis viajes</h4>
                <p>Lista completa de viajes asignados al chofer.</p>
                <a href="mis_viajes.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Iniciar viaje</h4>
                <p>Confirmar salida operativa del transporte.</p>
                <a href="iniciar_viaje.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Finalizar viaje</h4>
                <p>Registrar llegada y cierre del traslado.</p>
                <a href="finalizar_viaje.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Registrar incidente</h4>
                <p>Dejar constancia de problemas ocurridos en el viaje.</p>
                <a href="registrar_incidente.php" class="btn-primary">Abrir</a>
            </article>
        </div>
    </section>

    <section class="dashboard-card">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Accesos rápidos</h3>

        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="mis_viajes.php" class="btn-public-secondary">Mis viajes</a>
            <a href="iniciar_viaje.php" class="btn-public-secondary">Iniciar viaje</a>
            <a href="finalizar_viaje.php" class="btn-public-secondary">Finalizar viaje</a>
            <a href="registrar_incidente.php" class="btn-public-secondary">Registrar incidente</a>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
