<?php
/**
 * Vuelve a guardar cifradas las contraseñas de buzón que quedaron en base64.
 *
 * Las cuentas creadas antes de app/helpers/Secreto.php guardan la contraseña
 * en base64, que se lee sin clave: mientras una fila siga así, cualquiera con
 * la tabla, un respaldo o un app.log viejo la tiene en claro. Esto las pasa al
 * formato cifrado de una vez.
 *
 * ── Este script es el ÚNICO que entiende el formato viejo ─────────────────
 * Secreto::descifrar() ya no lee base64: una contraseña sin el prefijo
 * `enc:v1:` le sale vacía, y la cuenta queda sin credenciales hasta que
 * alguien la escriba de nuevo. Eso cerró el agujero, pero deja a las
 * instalaciones viejas sin forma de migrar, así que la decodificación vive
 * aquí —en la herramienta cuyo trabajo es quitarla— y en ningún otro sitio.
 * Al terminar esto, nadie en el sistema sabe leer base64 otra vez.
 *
 * Hace falta sobre todo al restaurar un respaldo anterior al cifrado. El
 * diagnóstico avisa cuando toca correrlo.
 *
 * Uso:  php cli/cifrar_claves_correo.php [--ver]
 *         --ver   solo dice qué haría, sin escribir nada.
 *
 * Requiere `clave_secretos` en app/config/local.php (php cli/clave_secretos.php).
 * Es idempotente: lo ya cifrado no se toca.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/Secreto.php';

$soloVer = in_array('--ver', $argv, true);

if (!Secreto::hayClave()) {
    fwrite(STDERR, "Falta clave_secretos en app/config/local.php. Genérala con: php cli/clave_secretos.php\n");
    exit(1);
}

final class ClavesCorreo extends Model
{
    protected $table = 'correo_cuentas';

    public function pendientes()
    {
        return $this->fetchAll('SELECT id, nombre, usuario, password FROM correo_cuentas ORDER BY id') ?: [];
    }

    public function guardar($id, $cifrada)
    {
        return $this->execute('UPDATE correo_cuentas SET password = ? WHERE id = ?', [$cifrada, (int) $id]);
    }
}

$modelo = new ClavesCorreo();
$cambiadas = 0;
$yaEstaban = 0;

foreach ($modelo->pendientes() as $cuenta) {
    $guardada = (string) $cuenta['password'];
    if (Secreto::estaCifrado($guardada)) {
        $yaEstaban++;
        continue;
    }

    // El base64 de antes, en estricto. Secreto ya no lo lee a propósito (ver
    // la cabecera): quien sabe deshacerlo es este script y nadie más.
    $plano = base64_decode($guardada, true);
    if ($plano === false || $plano === '') {
        printf("Cuenta %d (%s): la contraseña guardada está vacía o ilegible; se deja como está.\n",
            (int) $cuenta['id'], (string) $cuenta['nombre']);
        continue;
    }

    printf("Cuenta %d (%s, %s): %s\n", (int) $cuenta['id'], (string) $cuenta['nombre'],
        (string) $cuenta['usuario'], $soloVer ? 'se cifraría' : 'cifrada');
    if (!$soloVer) {
        $modelo->guardar((int) $cuenta['id'], Secreto::cifrar($plano));
    }
    $cambiadas++;
}

printf("\n%d cuenta(s) %s; %d ya estaban cifradas.\n",
    $cambiadas, $soloVer ? 'por cifrar' : 'cifradas', $yaEstaban);
