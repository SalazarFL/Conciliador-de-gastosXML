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
 * Lo cifrado se reconoce por el prefijo `enc:v1:`. Lo que no lo lleva se lee
 * como base64: así las cuentas que ya existían siguen funcionando sin migrar
 * nada, y se vuelven a guardar cifradas la próxima vez que se edite su
 * contraseña (o de una vez con cli/cifrar_claves_correo.php).
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
     * Devuelve el texto original. Lo que no lleva el prefijo se lee como el
     * base64 de antes, en estricto: si tampoco es base64 válido devuelve
     * vacío, y entonces la cuenta sale como "sin credenciales completas" en
     * vez de intentar conectar con un texto a medio decodificar.
     */
    public static function descifrar($guardado)
    {
        $guardado = (string) $guardado;
        if (!self::estaCifrado($guardado)) {
            $plano = base64_decode($guardado, true);
            return $plano === false ? '' : $plano;
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
