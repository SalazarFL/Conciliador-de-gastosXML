<?php
/**
 * Cómo llega esta computadora a la base de datos.
 *
 * La base es UNA sola, en el servidor; la aplicación corre en la máquina de
 * cada persona y todas escriben ahí. Los datos de conexión cambian de una
 * computadora a otra y la contraseña no puede vivir en el repositorio —es
 * público—, así que se leen de `local.php`, que no se versiona.
 *
 * Para preparar una computadora: copiar `local.ejemplo.php` como `local.php`
 * y llenarlo. `php cli/diagnostico.php` dice si quedó bien.
 */

$archivo = __DIR__ . DIRECTORY_SEPARATOR . 'local.php';

/**
 * Antes, sin configuración, esto devolvía silenciosamente localhost/root.
 * Fallar es mejor: arrancar contra el MySQL de la propia máquina significa
 * trabajar horas sobre una base vacía que nadie más ve, y darse cuenta
 * demasiado tarde.
 */
if (!is_file($archivo)) {
    throw new RuntimeException(
        "Falta app/config/local.php: esta computadora no sabe a qué base conectarse.\n"
        . "Copia app/config/local.ejemplo.php con el nombre local.php y escribe los "
        . "datos del servidor."
    );
}

$local  = require $archivo;
$config = is_array($local) && isset($local['database']) && is_array($local['database'])
    ? $local['database']
    : null;

if ($config === null) {
    throw new RuntimeException(
        "app/config/local.php no tiene la sección 'database'. "
        . "Compáralo con app/config/local.ejemplo.php."
    );
}

$config += [
    'driver'    => 'mysql',
    'host'      => '',
    'port'      => '3306',
    'database'  => 'bd_xmlconcilia',
    'username'  => '',
    'password'  => '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
    'strict'    => false,
    'engine'    => 'InnoDB',
];

foreach (['host', 'database', 'username'] as $obligatorio) {
    if (trim((string) $config[$obligatorio]) === '') {
        throw new RuntimeException(
            "Falta '{$obligatorio}' en la sección 'database' de app/config/local.php."
        );
    }
}

/**
 * Armado aquí y no en cada llamada: eran diecisiete copias de la misma cadena
 * repartidas por modelos, comandos y pruebas, y ninguna incluía el puerto. Con
 * la base en la misma máquina daba igual; contra un servidor, no.
 */
$config['dsn'] = "mysql:host={$config['host']};port={$config['port']}"
    . ";dbname={$config['database']};charset={$config['charset']}";

return $config;
