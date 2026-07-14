<?php
require_once __DIR__ . '/../includes/verificar_rol.php';
require_once __DIR__ . '/../config/db.php';

verificarRol(['ADMIN']);

$titulo_pagina = 'Dashboard Administrador';

$stats = [
    'sucursales' => 0,
    'empleados' => 0,
    'choferes' => 0,
    'clientes' => 0,
    'vehiculos' => 0,
    'viajes' => 0,
    'envios' => 0,
    'usuarios' => 0,
];

try {
    $stats['sucursales'] = (int) $pdo->query("SELECT COUNT(*) FROM Sucursal")->fetchColumn();
    $stats['empleados'] = (int) $pdo->query("SELECT COUNT(*) FROM Empleado_Sucursal")->fetchColumn();
    $stats['choferes'] = (int) $pdo->query("SELECT COUNT(*) FROM Chofer")->fetchColumn();
    $stats['clientes'] = (int) $pdo->query("SELECT COUNT(*) FROM Cliente")->fetchColumn();
    $stats['vehiculos'] = (int) $pdo->query("SELECT COUNT(*) FROM Vehiculo")->fetchColumn();
    $stats['viajes'] = (int) $pdo->query("SELECT COUNT(*) FROM Viaje")->fetchColumn();
    $stats['envios'] = (int) $pdo->query("SELECT COUNT(*) FROM Envio")->fetchColumn();
    $stats['usuarios'] = (int) $pdo->query("SELECT COUNT(*) FROM Usuario")->fetchColumn();
} catch (PDOException $e) {
    // si falla una métrica, no cortamos el dashboard
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
require_once __DIR__ . '/../includes/menu_lateral.php';
?>

<main class="app-content">

    <section class="page-header">
        <h1 class="page-title">Panel de Administración</h1>
        <p class="page-subtitle">
            Gestioná entidades principales del sistema y accedé a los paneles operativos.
        </p>
    </section>

    <section class="dashboard-grid" style="margin-bottom: 24px;">
        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Sucursales</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['sucursales']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Empleados</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['empleados']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Choferes</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['choferes']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Clientes</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['clientes']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Vehículos</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['vehiculos']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Viajes</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['viajes']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Envíos</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['envios']; ?></p>
        </article>

        <article class="dashboard-card">
            <h3 style="margin-top: 0;">Usuarios</h3>
            <p style="font-size: 28px; font-weight: 700; margin: 0;"><?php echo $stats['usuarios']; ?></p>
        </article>
    </section>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Gestión principal</h3>

        <div class="dashboard-grid">
            <article class="dashboard-card">
                <h4>Sucursales</h4>
                <p>Alta, edición y baja lógica de sucursales.</p>
                <a href="sucursales.php" class="btn-primary">Ir a sucursales</a>
            </article>

            <article class="dashboard-card">
                <h4>Empleados</h4>
                <p>Gestión de personal de sucursal.</p>
                <a href="empleados.php" class="btn-primary">Ir a empleados</a>
            </article>

            <article class="dashboard-card">
                <h4>Choferes</h4>
                <p>Alta y administración de choferes.</p>
                <a href="choferes.php" class="btn-primary">Ir a choferes</a>
            </article>

            <article class="dashboard-card">
                <h4>Clientes</h4>
                <p>Gestión de remitentes y destinatarios.</p>
                <a href="clientes.php" class="btn-primary">Ir a clientes</a>
            </article>

            <article class="dashboard-card">
                <h4>Vehículos</h4>
                <p>Control de flota y estado de unidades.</p>
                <a href="vehiculos.php" class="btn-primary">Ir a vehículos</a>
            </article>

            <article class="dashboard-card">
                <h4>Viajes</h4>
                <p>Consulta y administración de viajes.</p>
                <a href="viajes.php" class="btn-primary">Ir a viajes</a>
            </article>

            <article class="dashboard-card">
                <h4>Usuarios</h4>
                <p>Alta y control de accesos del sistema.</p>
                <a href="usuarios.php" class="btn-primary">Ir a usuarios</a>
            </article>
        </div>
    </section>

    <section class="dashboard-card" style="margin-bottom: 24px;">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Previsualización de paneles operativos</h3>

        <div class="dashboard-grid">
            <article class="dashboard-card">
                <h4>Panel Empleado</h4>
                <p>Recepción, estados, viajes, retiro y devoluciones.</p>
                <a href="../empleado/dashboard.php" class="btn-public-secondary">Ver panel empleado</a>
            </article>

            <article class="dashboard-card">
                <h4>Panel Chofer</h4>
                <p>Viajes asignados, inicio, finalización e incidentes.</p>
                <a href="../chofer/dashboard.php" class="btn-public-secondary">Ver panel chofer</a>
            </article>

            <article class="dashboard-card">
                <h4>Panel Cliente</h4>
                <p>Consulta de envíos enviados y a recibir.</p>
                <a href="../cliente/dashboard.php" class="btn-public-secondary">Ver panel cliente</a>
            </article>
        </div>
    </section>

    <section class="dashboard-card">
        <h3 style="margin-top: 0; margin-bottom: 18px;">Accesos rápidos operativos</h3>

        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="../empleado/recepcionar_envio.php" class="btn-public-secondary">Recepcionar envío</a>
            <a href="../empleado/asignar_viaje.php" class="btn-public-secondary">Asignar viaje</a>
            <a href="../empleado/disponible_retiro.php" class="btn-public-secondary">Disponible retiro</a>
            <a href="../empleado/registrar_retiro.php" class="btn-public-secondary">Registrar retiro</a>
            <a href="../empleado/devoluciones.php" class="btn-public-secondary">Devoluciones</a>
            <a href="../chofer/mis_viajes.php" class="btn-public-secondary">Mis viajes</a>
            <a href="../cliente/mis_envios.php" class="btn-public-secondary">Mis envíos</a>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>