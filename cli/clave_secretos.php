<?php
/**
 * Crea la clave con la que se cifran las contraseñas de los buzones.
 *
 * Se corre UNA vez, al instalar, y deja app/config/local.clave.php. Ese valor
 * hay que copiarlo a **todas** las computadoras: la base de datos es una sola
 * y cualquiera de ellas tiene que poder abrir los mismos buzones. No se guarda
 * en la base a propósito — ese es justamente el punto de cifrar.
 *
 * Vive en su propio archivo y no en local.php porque `cambiar-base.ps1` pisa
 * local.php con el perfil que se elija, y se llevaría la clave por delante.
 *
 * Uso:  php cli/clave_secretos.php
 *
 * Si ya hay clave, la muestra y no genera otra: cambiarla deja ilegibles las
 * contraseñas guardadas (habría que volver a escribirlas desde el ⚙ de Correo).
 */

require_once __DIR__ . '/../app/helpers/Secreto.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$archivo = __DIR__ . '/../app/config/local.clave.php';

if (Secreto::hayClave()) {
    echo "Esta computadora ya tiene clave de cifrado configurada.\n";
    if (is_file($archivo)) {
        echo "Está en: " . realpath($archivo) . "\n";
        echo "Cópiala tal cual a las demás computadoras (mismo archivo, mismo valor).\n";
    } else {
        echo "Viene de 'clave_secretos' en app/config/local.php.\n";
    }
    echo "No se genera otra: cambiarla dejaría ilegibles las contraseñas ya guardadas.\n";
    exit(0);
}

$clave = Secreto::generarClave();
$contenido = <<<PHP
<?php
/**
 * Clave con la que se cifran las contraseñas de los buzones de Correo.
 *
 * NO se versiona (.gitignore la cubre por el patrón local.*.php) y NO vive en
 * la base: quien lea la tabla o un respaldo no debe poder usar las
 * contraseñas. Tiene que ser LA MISMA en todas las computadoras.
 *
 * Si se pierde, hay que volver a escribir la contraseña de cada buzón desde
 * el ⚙ del módulo Correo.
 */

return '{$clave}';

PHP;

if (file_put_contents($archivo, $contenido) === false) {
    fwrite(STDERR, "No se pudo escribir {$archivo}. Créalo a mano con este contenido:\n\n{$contenido}\n");
    exit(1);
}

echo "Clave creada en: " . realpath($archivo) . "\n\n";
echo "Copia ese archivo a app/config/ de las demás computadoras ANTES de guardar\n";
echo "o cambiar la contraseña de un buzón desde cualquiera de ellas.\n\n";
echo "Si ya había buzones guardados en el formato viejo (base64), pásalos al\n";
echo "nuevo con:  php cli/cifrar_claves_correo.php\n";
