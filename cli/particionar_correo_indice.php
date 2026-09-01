<?php
/**
 * Parte el índice del buzón en cajones, uno por grupo de cuentas.
 *
 * Uso: php cli/particionar_correo_indice.php [--aplicar] [--cajones=32]
 *
 * Sin --aplicar solo informa qué haría.
 *
 * ── Para qué ──
 *
 * Buscar texto libre no lo resuelve ningún índice: "un pedacito de texto en
 * algún lugar del renglón" obliga a leerlos todos. La consulta pide un solo
 * buzón, pero la base decide leer la tabla entera igual, porque con pocas
 * cuentas leer todo de corrido le sale más barato que ir salteando. Y tiene
 * razón: medido, los dos caminos cuestan lo mismo hoy.
 *
 * El problema es que eso depende de una decisión que toma la base en cada
 * consulta, con sus propias estimaciones. Partiendo la tabla por cuenta, leer
 * solo el buzón propio deja de ser una decisión y pasa a ser la forma en que
 * los datos están guardados: la base ni siquiera abre los cajones de los demás.
 *
 * Medido sobre los tres buzones reales (138.066 correos):
 *
 *   sin partir    lee 133.160 filas    114 ms
 *   partido       lee  56.532 filas     80 ms
 *
 * Con 42 buzones y 32 cajones, cada búsqueda mira una trigésima parte del
 * índice en vez de los casi dos millones de renglones enteros.
 *
 * ── Por qué conviene hacerlo pronto ──
 *
 * Partir la tabla la reescribe entera. Con los 116 MB de hoy tarda unos
 * segundos; con el giga y medio que va a pesar con 17 sociedades es una parada
 * de varios minutos con el módulo cerrado. Es el mismo trabajo, mucho más
 * barato ahora.
 *
 * ── Lo que hay que saber ──
 *
 * La columna por la que se parte tiene que estar en TODAS las claves únicas.
 * La clave única del índice ya empieza por cuenta_id; la PRIMARY KEY no, así
 * que pasa de (id) a (id, cuenta_id). No cambia nada para quien la usa: id
 * sigue siendo único, porque lo sigue generando el AUTO_INCREMENT.
 *
 * El índice FULLTEXT de destinatarios sobrevive: en MariaDB 11.4 convive con
 * las particiones y MATCH sigue funcionando. Se comprobó antes de escribir
 * esto, porque en MySQL no es así.
 *
 * ── Lo que hoy lo impide ──
 *
 * InnoDB no admite claves foráneas en una tabla partida, y `correo_lote_items`
 * apunta al índice con `ON DELETE SET NULL`. Esa foránea hace un trabajo real:
 * las filas del índice se borran todo el tiempo —al reconstruir una carpeta,
 * al vencer la retención— y la base se encarga sola de dejar en NULL las
 * referencias que quedaron colgando. Son 14.459 filas apuntando ahí.
 *
 * Quitarla para poder partir la tabla no es un ajuste de rendimiento: cambia
 * quién garantiza esa limpieza. Sin la foránea, esas referencias quedarían
 * apuntando a un correo que ya no existe, y habría que enseñarle al código a
 * tratar "id que no resuelve" igual que NULL en todos los sitios donde hoy
 * confía en que la base ya lo hizo.
 *
 * Por eso este script comprueba primero y se niega, en vez de quitar la
 * foránea por su cuenta. La decisión es de quien conozca las consecuencias.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo corre por línea de comandos.\n");
}

error_reporting(E_ALL & ~E_DEPRECATED);
@set_time_limit(0);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/app/config/config.php';
require_once $ROOT . '/app/core/Model.php';
require_once $ROOT . '/app/helpers/MailFetcher.php';
require_once $ROOT . '/app/helpers/CorreoSync.php';

$aplicar = in_array('--aplicar', $argv, true);
$cajones = 32;
foreach ($argv as $arg) {
    if (preg_match('/^--cajones=(\d+)$/', $arg, $m)) {
        $cajones = max(2, min(128, (int) $m[1]));
    }
}

$db = new class extends Model {
    public function q($sql, $p = []) { return $this->fetchAll($sql, $p); }
    public function raw($sql) { return $this->query($sql); }
};

function humano($bytes)
{
    return number_format($bytes / 1024 / 1024, 1) . ' MB';
}

echo "\n== Índice del buzón ==\n";
$info = $db->q(
    "SELECT TABLE_ROWS filas, DATA_LENGTH datos, INDEX_LENGTH indices,
            (SELECT COUNT(*) FROM information_schema.PARTITIONS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correo_indice'
                AND PARTITION_NAME IS NOT NULL) cajones
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correo_indice'"
)[0] ?? null;

if ($info === null) {
    exit("No existe la tabla correo_indice en esta base.\n");
}

$filasReales = (int) $db->q("SELECT COUNT(*) c FROM correo_indice")[0]['c'];
echo "  correos:     " . number_format($filasReales) . "\n";
echo "  ocupa:       " . humano((int) $info['datos'] + (int) $info['indices']) . "\n";
echo "  cajones hoy: " . ((int) $info['cajones'] > 0 ? (int) $info['cajones'] : 'ninguno (sin partir)') . "\n";

foreach ($db->q("SELECT cuenta_id, COUNT(*) n FROM correo_indice GROUP BY cuenta_id ORDER BY n DESC") as $f) {
    echo "    buzón {$f['cuenta_id']}: " . number_format((int) $f['n']) . " correos\n";
}

if ((int) $info['cajones'] > 0) {
    echo "\nYa está partido. No hay nada que hacer.\n";
    exit(0);
}

/*
 * El bloqueo real, comprobado antes de tocar nada. InnoDB no admite foráneas
 * en una tabla partida, y quitarlas no es cosa de este script: cambia quién
 * garantiza que no queden referencias colgando.
 */
$foraneas = $db->q(
    "SELECT k.CONSTRAINT_NAME c, k.TABLE_NAME t, k.COLUMN_NAME col, r.DELETE_RULE regla
       FROM information_schema.KEY_COLUMN_USAGE k
       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
         ON r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
      WHERE k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'correo_indice'"
);

if (!empty($foraneas)) {
    echo "\n== No se puede partir todavía ==\n";
    echo "  InnoDB no admite claves foráneas en una tabla partida, y estas apuntan al índice:\n\n";
    foreach ($foraneas as $f) {
        $apuntan = (int) ($db->q(
            "SELECT COUNT(*) c FROM {$f['t']} WHERE {$f['col']} IS NOT NULL"
        )[0]['c'] ?? 0);
        echo "    {$f['t']}.{$f['col']}  ·  ON DELETE {$f['regla']}  ·  "
           . number_format($apuntan) . " filas apuntando\n";
    }
    echo "\n  Esa foránea hace un trabajo real: las filas del índice se borran todo el\n";
    echo "  tiempo —al reconstruir una carpeta, al vencer la retención— y la base deja\n";
    echo "  sola en NULL las referencias que quedan colgando.\n\n";
    echo "  Quitarla no es un ajuste de rendimiento: habría que enseñarle al código a\n";
    echo "  tratar \"id que no resuelve\" igual que NULL en todos los sitios donde hoy\n";
    echo "  confía en que la base ya lo hizo. Es una decisión, no un paso técnico.\n\n";
    echo "  Este script no la toma por su cuenta.\n\n";
    exit(2);
}

echo "\n== Lo que se haría ==\n";
echo "  · PRIMARY KEY pasa de (id) a (id, cuenta_id)\n";
echo "  · la tabla se parte en {$cajones} cajones por HASH(cuenta_id)\n";
echo "  · se reescribe entera: " . humano((int) $info['datos'] + (int) $info['indices']) . " a mover\n";
echo "  · el índice FULLTEXT de destinatarios se conserva\n";

if (!$aplicar) {
    echo "\nInforme solamente. Volvé a correrlo con --aplicar para hacerlo.\n";
    exit(0);
}

// Reescribir la tabla entera exige que no haya ninguna sincronización viva,
// de ningún buzón: para eso está el candado general en exclusiva.
$lock = CorreoSync::adquirirLockMantenimiento();
if ($lock === null) {
    exit("\nHay sincronizaciones en curso. Probá de nuevo cuando terminen.\n");
}

$codigo = 0;
try {
    echo "\n== Aplicando (con la sincronización detenida) ==\n";

    $t = microtime(true);
    echo "  cambiando la PRIMARY KEY...\n";
    $db->raw("ALTER TABLE correo_indice DROP PRIMARY KEY, ADD PRIMARY KEY (id, cuenta_id)");
    printf("    hecho en %.1f s\n", microtime(true) - $t);

    $t = microtime(true);
    echo "  partiendo en {$cajones} cajones...\n";
    $db->raw("ALTER TABLE correo_indice PARTITION BY HASH(cuenta_id) PARTITIONS {$cajones}");
    printf("    hecho en %.1f s\n", microtime(true) - $t);

    $despues = (int) $db->q("SELECT COUNT(*) c FROM correo_indice")[0]['c'];
    echo "\n== Comprobación ==\n";
    echo "  correos antes:   " . number_format($filasReales) . "\n";
    echo "  correos después: " . number_format($despues) . "\n";

    if ($despues !== $filasReales) {
        echo "  ¡NO COINCIDEN! Revisá la tabla antes de seguir usándola.\n";
        $codigo = 1;
    } else {
        echo "  coinciden.\n";
    }

    $cuenta = (int) ($db->q("SELECT cuenta_id FROM correo_indice LIMIT 1")[0]['cuenta_id'] ?? 0);
    $plan = $db->q(
        "EXPLAIN PARTITIONS SELECT COUNT(*) FROM correo_indice WHERE cuenta_id = ? AND asunto LIKE '%factura%'",
        [$cuenta]
    )[0] ?? [];
    echo "  al buscar en el buzón {$cuenta} se abren los cajones: "
       . ($plan['partitions'] ?? '?') . "\n";
} catch (Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "La tabla puede haber quedado a medias; revisala antes de seguir.\n";
    $codigo = 1;
} finally {
    CorreoSync::liberarLock($lock);
}

echo "\n";
exit($codigo);
