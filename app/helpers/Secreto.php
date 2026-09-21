<?php
/**
 * Lo que la base guarda pero nadie debería poder leer de ella.
 *
 * Hoy es solo la contraseña de cada buzón. Estaba en base64, que no es cifrado
 * sino una forma de escribir: quien leyera la tabla, un respaldo o el app.log
 * —donde Model::logError vuelca los parámetros de toda consulta fallida— la
 * tenía en claro.
 *
 * La clave NO vive en la base: va en `clave_secretos` de app/config/local.php,
 * que no se versiona. Tiene que ser LA MISMA en todas las computadoras, porque
 * la base es una sola y cualquiera de ellas abre los mismos buzones. Quien
 * instala genera una con `php cli/clave_secretos.php` y la copia a cada
 * local.php.
 *
 * Lo cifrado se reconoce por el prefijo `enc:v1:`. Lo que NO lo lleva no se
 * lee: devuelve vacío, y la cuenta sale como "sin credenciales completas"
 * hasta que alguien teclee la contraseña otra vez.
 *
 * ── Por qué ya no se lee el base64 de antes ───────────────────────────────
 * Durante un tiempo esto leía como base64 cualquier valor sin el prefijo, para
 * que las cuentas anteriores al cifrado siguieran funcionando sin migrar nada.
 * Esa rampa tenía un precio: base64 no es cifrado, es una forma de escribir, y
 * mientras una fila siguiera así cualquiera con la tabla, un respaldo o un
 * app.log viejo tenía la contraseña en claro. Peor: el camino de lectura de
 * todos los días aceptaba para siempre datos sin cifrar, así que el agujero no
 * se cerraba nunca solo y nada avisaba de que seguía abierto.
 *
 * Se cortó cuando no quedaba ninguna fila en ese formato, así que no rompió
 * nada. Lo que sabe leer el base64 viejo es ahora UNA sola cosa
 * —cli/cifrar_claves_correo.php, cuyo trabajo es justamente quitarlo— y el
 * diagnóstico avisa si alguna vez vuelve a aparecer una contraseña sin cifrar.
 */

class Secreto
{
    private const PREFIJO = 'enc:v1:';
    private const CIFRADO = 'aes-256-gcm';
    private const BYTES_IV = 12;
    private const BYTES_TAG = 16;

    /** null = sin resolver todavía; '' = no hay clave configurada. */
    private static $clave = null;

    /** Fija la clave a mano (las pruebas, y quien la reciba de otro lado). */
    public static function usarClave($clave)
    {
        self::$clave = (string) $clave;
    }

    public static function hayClave()
    {
        return self::claveResuelta() !== '';
    }

    /** Una clave nueva para pegar en local.php. */
    public static function generarClave()
    {
        return bin2hex(random_bytes(32));
    }

    public static function estaCifrado($guardado)
    {
        return strpos((string) $guardado, self::PREFIJO) === 0;
    }

    public static function cifrar($texto)
    {
        $clave = self::claveResuelta();
        if ($clave === '') {
            throw new RuntimeException(
                'Falta la clave de cifrado: agrega "clave_secretos" en app/config/local.php '
                . '(genérala con: php cli/clave_secretos.php) y usa el mismo valor en todas las computadoras.'
            );
        }

        $iv = random_bytes(self::BYTES_IV);
        $tag = '';
        $cifrado = openssl_encrypt((string) $texto, self::CIFRADO, self::derivar($clave), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cifrado === false) {
            throw new RuntimeException('No se pudo cifrar el dato.');
        }

        return self::PREFIJO . base64_encode($iv . $tag . $cifrado);
    }

    /**
     * Devuelve el texto original, o vacío si lo guardado no está cifrado por
     * esta clase.
     *
     * Vacío no es un fallo silencioso: aguas abajo significa "sin credenciales
     * completas", que es lo que hace CorreoCuenta::configParaFetcher(), así que
     * la cuenta se queda sin conectar y alguien vuelve a escribir la
     * contraseña —en vez de que el sistema intente entrar con un texto a medio
     * decodificar, o de que siga aceptando para siempre una contraseña que
     * nunca se cifró—. Ver la cabecera de esta clase.
     */
    public static function descifrar($guardado)
    {
        $guardado = (string) $guardado;
        if (!self::estaCifrado($guardado)) {
            return '';
        }

        $crudo = base64_decode(substr($guardado, strlen(self::PREFIJO)), true);
        if ($crudo === false || strlen($crudo) <= self::BYTES_IV + self::BYTES_TAG) {
            return '';
        }
        $clave = self::claveResuelta();
        if ($clave === '') {
            throw new RuntimeException(
                'Esta computadora no tiene la clave de cifrado ("clave_secretos" en app/config/local.php), '
                . 'así que no puede usar las contraseñas guardadas. Cópiala de una computadora que ya la tenga.'
            );
        }

        $iv = substr($crudo, 0, self::BYTES_IV);
        $tag = substr($crudo, self::BYTES_IV, self::BYTES_TAG);
        $cuerpo = substr($crudo, self::BYTES_IV + self::BYTES_TAG);
        $plano = openssl_decrypt($cuerpo, self::CIFRADO, self::derivar($clave), OPENSSL_RAW_DATA, $iv, $tag);

        return $plano === false ? '' : $plano;
    }

    /** La clave de 32 bytes que usa AES, derivada de lo que diga local.php. */
    private static function derivar($clave)
    {
        return hash('sha256', $clave, true);
    }

    private static function claveResuelta()
    {
        if (self::$clave === null) {
            self::$clave = '';
            try {
                $config = require dirname(__DIR__) . '/config/config.php';
                self::$clave = trim((string) ($config['clave_secretos'] ?? ''));
            } catch (Throwable $e) {
                // Sin config legible no hay clave; cifrar lo dirá con todas
                // las letras y descifrar seguirá leyendo lo antiguo.
            }
        }
        return self::$clave;
    }
}
