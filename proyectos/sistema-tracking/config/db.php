<?php
// =====================================================
// db.php
// Archivo central de conexión a la base de datos
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

// -----------------------------------------------------
// Configuración de la base de datos
// -----------------------------------------------------

define('DB_HOST', 'localhost');
define('DB_NAME', 'sistema_tracking');
define('DB_USER', 'root');
define('DB_PASS', '');

// Si tu MySQL usa el puerto 3306, dejalo así.
// Si estás usando XAMPP con puerto 3307, cambiá este valor.
define('DB_PORT', '3306');


// -----------------------------------------------------
// Conexión PDO
// -----------------------------------------------------

try {

    $dsn = "mysql:host=" . DB_HOST .
           ";port=" . DB_PORT .
           ";dbname=" . DB_NAME .
           ";charset=utf8mb4";

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    // En desarrollo mostramos el error para poder corregir.
    // Más adelante, si lo subís a producción, conviene ocultarlo.
    die("Error de conexión a la base de datos: " . $e->getMessage());

}