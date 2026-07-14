<?php
// =====================================================
// crear_admin.php
// Archivo temporal para crear el usuario administrador
// Sistema: LogiTrack / Sistema Tracking
// =====================================================

require_once __DIR__ . '/config/db.php';

try {

    // -------------------------------------------------
    // Datos del administrador
    // -------------------------------------------------

    $username = 'admin';

    $passwordPlano = 'admin123';

    $passwordHash = password_hash($passwordPlano, PASSWORD_DEFAULT);

    $codRol = 'ADMIN';


    // -------------------------------------------------
    // Asegurar que exista el rol ADMIN
    // -------------------------------------------------

    $sqlRol = "
        INSERT INTO rol (cod_rol, nombre, descripcion)
        VALUES (:cod_rol, :nombre, :descripcion)
        ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            descripcion = VALUES(descripcion)
    ";

    $stmtRol = $pdo->prepare($sqlRol);

    $stmtRol->execute([
        ':cod_rol' => $codRol,
        ':nombre' => 'Administrador',
        ':descripcion' => 'Usuario con acceso total al sistema'
    ]);


    // -------------------------------------------------
    // Crear o actualizar usuario administrador
    // -------------------------------------------------

    $sqlUsuario = "
        INSERT INTO usuario (
            username,
            password_hash,
            cod_rol,
            dni_persona,
            activo,
            created_at
        )
        VALUES (
            :username,
            :password_hash,
            :cod_rol,
            NULL,
            1,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            password_hash = VALUES(password_hash),
            cod_rol = VALUES(cod_rol),
            dni_persona = NULL,
            activo = 1
    ";

    $stmtUsuario = $pdo->prepare($sqlUsuario);

    $stmtUsuario->execute([
        ':username' => $username,
        ':password_hash' => $passwordHash,
        ':cod_rol' => $codRol
    ]);


    // -------------------------------------------------
    // Mensaje final
    // -------------------------------------------------

    echo "<h1>Administrador creado correctamente</h1>";

    echo "<p>Usuario: <strong>admin</strong></p>";

    echo "<p>Contraseña: <strong>admin123</strong></p>";

    echo "<p>Ahora podés ir al login.</p>";

    echo "<a href='auth/login.php'>Ir al login</a>";

} catch (PDOException $e) {

    echo "<h1>Error al crear el administrador</h1>";

    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";

}
