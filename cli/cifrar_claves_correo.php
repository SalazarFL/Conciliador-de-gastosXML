<?php
/**
 * Vuelve a guardar cifradas las contraseñas de buzón que quedaron en base64.
 *
 * Las cuentas creadas antes de app/helpers/Secreto.php guardan la contraseña
 * en base64, que se lee sin clave. Se siguen usando (Secreto::descifrar las
 * entiende), pero mientras sigan así cualquiera con la tabla, un respaldo o un
 * app.log viejo las tiene. Esto las pasa al formato cifrado de una vez.
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

    $plano = Secreto::descifrar($guardada);
    if ($plano === '') {
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
