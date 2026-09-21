<?php
/**
 * Una empresa de verdad, desechable, para las pruebas que tocan la base.
 * No termina en Test.php a propósito: no es una prueba, lo usan.
 *
 * Varias pruebas se inventaban un `sociedad_id` alto —999777, 990000 + azar—
 * con el comentario "una sociedad que no existe, para no tocar datos de
 * nadie". La intención era buena y el resultado funcionaba, pero funcionaba
 * por el motivo equivocado: porque `sociedad_id` no tenía clave foránea y la
 * base aceptaba cualquier número. Desde migration_claves_sociedad.sql ya no.
 *
 * Aislarse de los datos de nadie sigue siendo lo correcto; lo que cambia es
 * cómo. En vez de un id que no existe, una empresa que sí existe, creada para
 * la prueba y borrada al final. Sale con `activa = 0` para que no se cuele en
 * ningún selector si una prueba se cae a la mitad, y con un nombre que la
 * delata a simple vista si alguna vez queda tirada.
 */
final class SociedadDePrueba
{
    /** Con qué empiezan sus nombres, para reconocerlas y para limpiarlas. */
    const PREFIJO = 'ZZ prueba ';

    /**
     * Crea una y devuelve su id. El sufijo distingue a las de una prueba de
     * las de otra cuando dos corren a la vez.
     */
    public static function crear(PDO $pdo, $sufijo = '')
    {
        $nombre = self::PREFIJO . ($sufijo !== '' ? $sufijo . ' ' : '') . bin2hex(random_bytes(3));
        $pdo->prepare('INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)')
            ->execute([$nombre, '0000' . random_int(10000, 99999)]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * La borra. Silencioso a propósito: se llama desde los `finally` de las
     * pruebas, donde ya puede haber un fallo que informar, y una excepción
     * aquí lo taparía. Si la empresa todavía tiene documentos, la clave
     * foránea impide el borrado y la empresa queda —con su nombre "ZZ prueba",
     * que es justamente la señal de que esa prueba no limpió lo suyo—.
     */
    public static function borrar(PDO $pdo, $id)
    {
        if (!$id) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM sociedades WHERE id = ?')->execute([(int) $id]);
        } catch (Throwable $e) {
            // Ver el comentario de arriba.
        }
    }
}
