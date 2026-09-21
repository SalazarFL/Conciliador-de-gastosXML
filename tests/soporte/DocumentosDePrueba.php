<?php
/**
 * Documentos desechables para las pruebas que tocan la base.
 * No termina en Test.php a propósito: no es una prueba, lo usan.
 *
 * Igual que con las empresas: varias pruebas se inventaban ids altos —un
 * 2147480000, un 900000000— con el comentario de que "la referencia no existe
 * a propósito, así no se pisa nada real". La intención era la correcta y el
 * resultado funcionaba, pero funcionaba porque no había claves foráneas.
 *
 * Aislarse sigue siendo lo correcto; lo que cambia es cómo. Un documento de
 * verdad, creado para la prueba y borrado al final, no pisa nada de nadie y
 * además ejercita las claves en vez de esquivarlas.
 *
 * @see SociedadDePrueba, que es de donde cuelga todo esto.
 */
final class DocumentosDePrueba
{
    /**
     * Un listado de notas de crédito con `$cuantas` líneas dentro.
     *
     * Devuelve ['listado_id' => int, 'lineas' => [int, ...]].
     *
     * Borrar el listado se lleva las líneas por cascada, así que para limpiar
     * basta con `borrarListado()` —o con borrar la empresa, que se lo lleva
     * todo por delante si no quedan documentos que lo impidan—.
     */
    public static function listadoConLineas(PDO $pdo, $sociedadId, $cuantas = 1, $marca = '')
    {
        $marca = $marca !== '' ? $marca : 'ZZ doc ' . bin2hex(random_bytes(3));

        $pdo->prepare(
            'INSERT INTO notas_credito_listados
               (sociedad_id, nombre, archivo_origen, archivo_ruta, archivo_hash)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            (int) $sociedadId,
            $marca,
            $marca . '.pdf',
            'pruebas/' . $marca,
            hash('sha256', $marca),
        ]);
        $listadoId = (int) $pdo->lastInsertId();

        $insertar = $pdo->prepare(
            'INSERT INTO notas_credito_lineas
               (listado_id, fila_origen, proveedor_nombre, documento, fecha, moneda, monto)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        );
        $lineas = [];
        for ($i = 1; $i <= (int) $cuantas; $i++) {
            $insertar->execute([$listadoId, $i, $marca, $marca . '-' . $i, '2026-09-01', 'CRC']);
            $lineas[] = (int) $pdo->lastInsertId();
        }

        return ['listado_id' => $listadoId, 'lineas' => $lineas];
    }

    /**
     * Lo borra. Silencioso a propósito: se llama desde los `finally` de las
     * pruebas, donde ya puede haber un fallo que informar.
     */
    public static function borrarListado(PDO $pdo, $listadoId)
    {
        if (!$listadoId) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM notas_credito_listados WHERE id = ?')
                ->execute([(int) $listadoId]);
        } catch (Throwable $e) {
            // Ver el comentario de arriba.
        }
    }

    /** Un buzón desechable. Su índice, carpetas y capturas se van con él. */
    public static function cuentaCorreo(PDO $pdo, $marca = '')
    {
        $marca = $marca !== '' ? $marca : 'ZZ buzon ' . bin2hex(random_bytes(3));
        $pdo->prepare(
            'INSERT INTO correo_cuentas (nombre, host, usuario, password) VALUES (?, ?, ?, ?)'
        )->execute([$marca, 'imap.pruebas.invalid', $marca, '']);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Borra el buzón. La bandeja es RESTRICT —guarda documentos sin importar—
     * así que se vacía primero; lo demás se va por cascada.
     */
    public static function borrarCuentaCorreo(PDO $pdo, $cuentaId)
    {
        if (!$cuentaId) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM correo_bandeja WHERE cuenta_id = ?')->execute([(int) $cuentaId]);
            $pdo->prepare('DELETE FROM correo_cuentas WHERE id = ?')->execute([(int) $cuentaId]);
        } catch (Throwable $e) {
            // Ver el comentario de arriba.
        }
    }
}
