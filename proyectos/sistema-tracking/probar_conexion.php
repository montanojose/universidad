<?php
// =====================================================
// probar_conexion.php
// Archivo temporal para verificar la conexión a la BD
// =====================================================

require_once __DIR__ . '/config/db.php';

try {

    $sql = "SELECT DATABASE() AS base_actual";

    $stmt = $pdo->query($sql);

    $resultado = $stmt->fetch();

    echo "<h1>Conexión exitosa</h1>";

    echo "<p>Base de datos conectada: <strong>" . htmlspecialchars($resultado['base_actual']) . "</strong></p>";

} catch (PDOException $e) {

    echo "<h1>Error al consultar la base de datos</h1>";

    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";

}