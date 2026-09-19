<?php
/**
 * Un candado que ven todas las PC, no solo esta.
 *
 * El organizador se cuidaba de correr dos veces a la vez con flock() sobre un
 * archivo de storage/locks/. Eso alcanza mientras cada PC ordena su propia
 * copia del archivo. Contra una sola base y una sola biblioteca de SharePoint
 * no alcanza: dos PC con la tarea programada se moverían los documentos la
 * una a la otra, y cada una anotaría en la base una ruta distinta.
 *
 * GET_LOCK de MariaDB es de la conexión: si el proceso muere —un corte de luz,
 * un tiempo agotado—, la base lo suelta sola al cerrarse la conexión. No hay
 * candados huérfanos que alguien tenga que borrar a mano.
 *
 * El nombre lleva la base delante: dos instalaciones en el mismo servidor
 * (la de certificación y la real) no se bloquean entre sí.
 */
class BloqueoEnBase extends Model
{
    /**
     * @return bool|null true si se tomó, false si lo tiene otro, null si no
     *                   hay base a la que preguntar (quien llama sigue con su
     *                   candado de la máquina, que es lo que había antes).
     */
    public static function tomar($nombre)
    {
        try {
            $r = (new self())->fetchColumn('SELECT GET_LOCK(LEFT(CONCAT(DATABASE(), ?), 64), 0)',
                ['.' . (string) $nombre]);
        } catch (Throwable $e) {
            return null;
        }
        return $r === null || $r === false ? null : (int) $r === 1;
    }

    public static function soltar($nombre)
    {
        try {
            (new self())->fetchColumn('SELECT RELEASE_LOCK(LEFT(CONCAT(DATABASE(), ?), 64))', ['.' . (string) $nombre]);
        } catch (Throwable $e) {
            // Al cerrarse la conexión se suelta igual.
        }
    }
}
