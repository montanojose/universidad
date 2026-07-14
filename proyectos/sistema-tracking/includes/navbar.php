<?php
// =====================================================
// navbar.php
// Barra superior reutilizable para pantallas internas
// Sistema: LogiTrack / Sistema Tracking
// =====================================================


// -----------------------------------------------------
// Datos de sesión para mostrar en la barra superior
// -----------------------------------------------------

$usuario_actual = $_SESSION['username'] ?? 'Usuario';

$rol_actual = $_SESSION['usuario_rol'] ?? 'Sin rol';


// -----------------------------------------------------
// Nombre visible del rol
// -----------------------------------------------------

$nombre_rol = match ($rol_actual) {

    'ADMIN' => 'Administrador',

    'EMPLEADO_SUCURSAL' => 'Empleado Sucursal',

    'CHOFER' => 'Chofer',

    'CLIENTE' => 'Cliente',

    default => 'Sin rol'

};

?>

<header class="app-navbar">

    <div class="navbar-left">

        <button 
            type="button" 
            class="menu-toggle" 
            id="menuToggle"
            aria-label="Abrir menú"
        >
            ☰
        </button>

        <a href="../index.php" class="navbar-brand">

            <span class="navbar-logo">
                📦
            </span>

            <span class="navbar-title">
                LogiTrack
            </span>

        </a>

    </div>


    <div class="navbar-right">

        <div class="navbar-user">

            <span class="navbar-username">
                <?php echo htmlspecialchars($usuario_actual); ?>
            </span>

            <span class="navbar-role">
                <?php echo htmlspecialchars($nombre_rol); ?>
            </span>

        </div>

        <a href="../auth/logout.php" class="navbar-logout">
            Cerrar sesión
        </a>

    </div>

</header>