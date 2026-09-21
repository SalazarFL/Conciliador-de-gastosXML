<?php
/**
 * "Marcar como resuelto" tiene que apagar el recordatorio, no solo el recado.
 *
 * El fallo que fija: un recordatorio de Seguimiento no es un hecho suelto, es
 * una alarma. El aviso que llega a la campana es el recado que manda esa
 * alarma, y la alarma vive en el renglón (`recordar_en` / `recordar_cada`).
 * Cerrar el aviso a secas mataba al mensajero: al día siguiente el barrido
 * volvía a sonar y avisarVarios() REABRE lo que estaba resuelto —a propósito,
 * ver Notificacion::avisar—, así que el botón parecía no hacer nada. En esta
 * instalación el mismo pendiente llegó a contar siete repeticiones del mismo
 * recado, con su `resuelta_en` de ocho días antes todavía escrito.
 *
 * Lo que se comprueba, en orden de lo que dolería que se rompiera:
 *
 *  1. Que apagar deja el renglón EN REVISIÓN. Si eso se rompe, el botón de la
 *     campana estaría cerrando trabajo pendiente sin que nadie lo decida, que
 *     es bastante peor que un recordatorio insistente.
 *  2. Que la alarma queda apagada de verdad, medido con la condición del
 *     barrido.
 *  3. Que queda dicho en la historia del documento por qué dejó de sonar.
 *  4. Que apagar dos veces no escribe dos veces.
 *
 * Usa un renglón propio, inventado, y lo borra al terminar: no toca ninguna
 * gestión de verdad. Se salta sola si no hay base.
 *
 * Uso: php tests/RecordatorioApagadoTest.php
 */
require_once __DIR__ . '/../app/core/Esquema.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/soporte/SociedadDePrueba.php';
require_once __DIR__ . '/soporte/DocumentosDePrueba.php';
require_once __DIR__ . '/../app/core/AlcanceSociedad.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

$fallos = 0;

function assertRecordatorio($condicion, $mensaje)
{
    global $fallos;
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        $fallos++;
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'], $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: RecordatorioApagado (sin base de datos disponible)\n";
    exit(0);
}

// Los modelos leen $_SESSION; en CLI no existe hasta que se declara.
$_SESSION = [];

/*
 * Dos renglones que no son de nadie: un listado propio con sus dos líneas,
 * creado para esta prueba y borrado al final. Antes iba un id inventado
 * (2147480000) con la idea correcta —no pisar una gestión real— pero por el
 * mecanismo equivocado: funcionaba porque `seguimiento.referencia_id` no tenía
 * a qué amarrarse. Desde migration_seguimiento_referencias.sql sí la tiene, así
 * que la referencia existe de verdad y sigue sin ser de nadie.
 */
$ORIGEN = 'nota_credito';
$sociedadPrueba = SociedadDePrueba::crear($pdo, 'recordatorio');
$docs = DocumentosDePrueba::listadoConLineas($pdo, $sociedadPrueba, 2);
$REF  = $docs['lineas'][0];
$REF2 = $docs['lineas'][1];

$limpiar = function () use ($pdo, $ORIGEN, $REF, $REF2) {
    try {
        $pdo->prepare('DELETE b FROM seguimiento_bitacora b
                         JOIN seguimiento s ON s.id = b.seguimiento_id
                        WHERE s.origen = ? AND s.referencia_id IN (?, ?)')
            ->execute([$ORIGEN, $REF, $REF2]);
        $pdo->prepare('DELETE FROM seguimiento WHERE origen = ? AND referencia_id IN (?, ?)')
            ->execute([$ORIGEN, $REF, $REF2]);
    } catch (Throwable $e) {
        // Si la base se cayó a mitad, no hay nada que limpiar.
    }
};
register_shutdown_function($limpiar);
$limpiar(); // por si una corrida anterior murió a medias

/** El renglón, como lo deja Seguimiento al mandarlo a revisión con alarma. */
function armarAlarma(PDO $pdo, $origen, $ref)
{
    $pdo->prepare(
        "INSERT INTO seguimiento
            (origen, referencia_id, estado, responsable, recordar_en, recordar_cada,
             avisado_en, motivo, actualizado_por)
         VALUES (?, ?, 'revision', 'Prueba', DATE_SUB(NOW(), INTERVAL 3 DAY), 1,
                 DATE_SUB(NOW(), INTERVAL 2 DAY), 'Renglon de prueba', 'Prueba')"
    )->execute([$origen, $ref]);
    return (int) $pdo->lastInsertId();
}

function renglonSeguimiento(PDO $pdo, $id)
{
    $consulta = $pdo->prepare('SELECT * FROM seguimiento WHERE id = ?');
    $consulta->execute([$id]);
    return $consulta->fetch() ?: [];
}

function lineasDeHistoria(PDO $pdo, $id)
{
    $consulta = $pdo->prepare('SELECT COUNT(*) n FROM seguimiento_bitacora WHERE seguimiento_id = ?');
    $consulta->execute([$id]);
    return (int) $consulta->fetch()['n'];
}

/**
 * ¿Lo barrería el reloj ahora mismo?
 *
 * La condición es copia de Seguimiento::barrerRecordatorios(), a sabiendas:
 * aquí no se prueba esa consulta, se usa para decir en qué estado queda el
 * renglón —"ya no está vencido"— con las mismas palabras que el barrido. Correr
 * el barrido de verdad tocaría los recordatorios reales de la instalación.
 */
function estaVencido(PDO $pdo, $id)
{
    $consulta = $pdo->prepare(
        "SELECT COUNT(*) n FROM seguimiento s
          WHERE s.id = ?
            AND s.estado = 'revision'
            AND s.recordar_en IS NOT NULL
            AND s.recordar_en <= NOW()
            AND (s.avisado_en IS NULL
                 OR (s.recordar_cada IS NOT NULL
                     AND DATE_ADD(s.avisado_en, INTERVAL s.recordar_cada DAY) <= NOW()))"
    );
    $consulta->execute([$id]);
    return (int) $consulta->fetch()['n'] > 0;
}

$usuario = ['id' => 1, 'nombre' => 'Prueba'];
$modelo  = new Seguimiento();

// ── Antes: la alarma suena ────────────────────────────────────────────────
$id = armarAlarma($pdo, $ORIGEN, $REF);
$historiaAntes = lineasDeHistoria($pdo, $id);

assertRecordatorio(estaVencido($pdo, $id),
    'el renglón de prueba arranca vencido (si no, la prueba no probaría nada)');

// ── Apagar ────────────────────────────────────────────────────────────────
$apagados = $modelo->apagarRecordatorio($ORIGEN, $REF, $usuario);
$fila = renglonSeguimiento($pdo, $id);

assertRecordatorio($apagados === 1, 'apagar una alarma armada contesta que apagó una');

// 1. Lo que más dolería: que esto cerrara trabajo pendiente.
assertRecordatorio(($fila['estado'] ?? '') === 'revision',
    'el renglón SIGUE en revisión: apagar el recordatorio no es dar el trabajo por hecho');
assertRecordatorio(($fila['responsable'] ?? '') === 'Prueba',
    'sigue a cargo de quien lo tenía');
assertRecordatorio(($fila['motivo'] ?? '') === 'Renglon de prueba',
    'el problema descrito sigue ahí');

// 2. La alarma, apagada de verdad.
assertRecordatorio($fila['recordar_en'] === null, 'se apagó el momento del recordatorio');
assertRecordatorio($fila['recordar_cada'] === null,
    'y también la frecuencia: el momento sin la frecuencia deja media alarma');
assertRecordatorio(!estaVencido($pdo, $id),
    'el barrido ya no lo ve vencido, así que el aviso no puede volver a nacer');

// 3. Que quede dicho por qué dejó de sonar.
assertRecordatorio(lineasDeHistoria($pdo, $id) === $historiaAntes + 1,
    'apagar deja una línea en la historia del documento');

$consulta = $pdo->prepare('SELECT usuario_nombre, comentario, estado_anterior, estado_nuevo
                             FROM seguimiento_bitacora WHERE seguimiento_id = ? ORDER BY id DESC LIMIT 1');
$consulta->execute([$id]);
$linea = $consulta->fetch() ?: [];

assertRecordatorio(($linea['usuario_nombre'] ?? '') === 'Prueba',
    'la historia dice quién lo apagó');
assertRecordatorio(strpos((string) ($linea['comentario'] ?? ''), 'Recordatorio apagado') === 0,
    'y dice qué pasó, para que nadie se pregunte dentro de un mes dónde quedó su recordatorio');
assertRecordatorio(($linea['estado_anterior'] ?? null) === null && ($linea['estado_nuevo'] ?? null) === null,
    'sin cambio de estado en la historia: no lo hubo');

// 4. Apagar lo ya apagado no escribe.
$otraVez = $modelo->apagarRecordatorio($ORIGEN, $REF, $usuario);
assertRecordatorio($otraVez === 0, 'apagar lo que ya estaba apagado contesta que no apagó nada');
assertRecordatorio(lineasDeHistoria($pdo, $id) === $historiaAntes + 1,
    'y no deja una segunda línea en la historia por no haber hecho nada');

// ── Un renglón que no existe no revienta, ni se inventa ───────────────────
$inventado = $modelo->apagarRecordatorio($ORIGEN, $REF + 1, $usuario);
assertRecordatorio($inventado === 0, 'apagar un renglón que no existe contesta 0');

$consulta = $pdo->prepare('SELECT COUNT(*) n FROM seguimiento WHERE origen = ? AND referencia_id = ?');
$consulta->execute([$ORIGEN, $REF + 1]);
assertRecordatorio((int) $consulta->fetch()['n'] === 0,
    'y no crea el renglón que no estaba: apagar no es dar de alta una gestión');

$limpiar();

if ($fallos > 0) {
    fwrite(STDERR, "RecordatorioApagado: {$fallos} fallas\n");
    exit(1);
}

// Borrar el listado se lleva sus líneas por cascada, y eso se lleva los
// seguimientos que las apuntaban: no hay que borrarlos aparte.
DocumentosDePrueba::borrarListado($pdo, $docs['listado_id']);
SociedadDePrueba::borrar($pdo, $sociedadPrueba);

echo "OK: apagar un recordatorio lo calla sin cerrar el trabajo\n";
exit(0);
