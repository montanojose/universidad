<?php
require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN', 'CLIENTE']);

$titulo_pagina = 'Dashboard Cliente';

$rol_actual = $_SESSION['usuario_rol'] ?? '';

function obtenerDniClienteSesionDashboard(): string
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

$dni_cliente = '';
$nombre_cliente = '';

$stats = [
    'enviados' => 0,
    'a_recibir' => 0,
    'disponibles' => 0,
    'retirados' => 0,
];

try {
    if ($rol_actual === 'CLIENTE') {
        $dni_cliente = obtenerDniClienteSesionDashboard();

        if ($dni_cliente !== '') {
            $stmt = $pdo->prepare("SELECT apellido, nombre FROM vista_cliente WHERE dni = :dni LIMIT 1");
            $stmt->execute([':dni' => $dni_cliente]);
            $fila = $stmt->fetch();
            if ($fila) {
                $nombre_cliente = $fila['apellido'] . ', ' . $fila['nombre'];
            }
        }
    }

    if ($dni_cliente !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Envio WHERE dni_remitente = :dni");
        $stmt->execute([':dni' => $dni_cliente]);
        $stats['enviados'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Envio WHERE dni_destinatario = :dni");
        $stmt->execute([':dni' => $dni_cliente]);
        $stats['a_recibir'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM Envio e
            INNER JOIN Disponibilidad_Retiro dr ON e.nro_tracking = dr.nro_tracking
            LEFT JOIN Retiro_Envio re ON e.nro_tracking = re.nro_tracking
            WHERE e.dni_destinatario = :dni
              AND re.nro_tracking IS NULL
        ");
        $stmt->execute([':dni' => $dni_cliente]);
        $stats['disponibles'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM Envio e
            INNER JOIN Retiro_Envio re ON e.nro_tracking = re.nro_tracking
            WHERE e.dni_destinatario = :dni
        ");
        $stmt->execute([':dni' => $dni_cliente]);
        $stats['retirados'] = (int) $stmt->fetchColumn();

    } else {
        $stats['enviados'] = (int) $pdo->query("SELECT COUNT(*) FROM Envio")->fetchColumn();
        $stats['a_recibir'] = (int) $pdo->query("SELECT COUNT(*) FROM Envio")->fetchColumn();
        $stats['disponibles'] = (int) $pdo->query("
            SELECT COUNT(*)
            FROM Disponibilidad_Retiro dr
            LEFT JOIN Retiro_Envio re ON dr.nro_tracking = re.nro_tracking
            WHERE re.nro_tracking IS NULL
        ")->fetchColumn();
        $stats['retirados'] = (int) $pdo->query("SELECT COUNT(*) FROM Retiro_Envio")->fetchColumn();
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
        <h1 class="page-title">Panel del Cliente</h1>
        <p class="page-subtitle">
            Consultá tus envíos, lo que tenés por recibir y el estado del retiro.
        </p>
    </section>

    <?php if ($dni_cliente !== ''): ?>
        <section class="dashboard-card" style="margin-bottom: 24px;">
            <p style="margin: 0;">
                <strong>Cliente actual:</strong>
                <?php echo htmlspecialchars($dni_cliente . ' - ' . $nombre_cliente); ?>
            </p>
        </section>
    <?php endif; ?>

    <section class="dashboard-grid" style="margin-bottom: 24px;">
        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Envíos enviados</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['enviados']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Envíos a recibir</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['a_recibir']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Disponibles retiro</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['disponibles']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Retirados</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['retirados']; ?></p>
        </article>
    </section>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Operaciones del cliente</h3>

        <div class="dashboard-grid">
            <article class="dashboard-card">
                <h4>Crear solicitud</h4>
                <p>Registrar un nuevo envío en el sistema.</p>
                <a href="crear_solicitud_envio.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Mis envíos enviados</h4>
                <p>Seguimiento de los envíos donde sos remitente.</p>
                <a href="mis_envios.php" class="btn-primary">Abrir</a>
            </article>

            <article class="dashboard-card">
                <h4>Mis envíos a recibir</h4>
                <p>Consulta de envíos donde figurás como destinatario.</p>
                <a href="envios_recibir.php" class="btn-primary">Abrir</a>
            </article>
        </div>
    </section>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Consulta rápida por tracking</h3>

        <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">
            <form method="GET" action="detalle_envio.php">
                <div class="form-group">
                    <label for="tracking_detalle">Ver detalle del envío</label>
                    <input type="text" id="tracking_detalle" name="tracking" class="form-control" placeholder="Ej: TRK000001" required>
                </div>
                <button type="submit" class="btn-primary" style="width: auto;">Ver detalle</button>
            </form>

            <form method="GET" action="historial_envio.php">
                <div class="form-group">
                    <label for="tracking_historial">Ver historial del envío</label>
                    <input type="text" id="tracking_historial" name="tracking" class="form-control" placeholder="Ej: TRK000001" required>
                </div>
                <button type="submit" class="btn-primary" style="width: auto;">Ver historial</button>
            </form>
        </div>
    </section>

    <section class="dashboard-card">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Accesos rápidos</h3>

        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="crear_solicitud_envio.php" class="btn-public-secondary">Crear solicitud</a>
            <a href="mis_envios.php" class="btn-public-secondary">Envíos enviados</a>
            <a href="envios_recibir.php" class="btn-public-secondary">Envíos a recibir</a>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
