<?php
/**
 * La barra de acciones no ofrece la pestaña en la que ya estás.
 *
 * Mover un documento al estado que ya tiene no hace nada, y el botón invita a
 * hacerlo. Se comprueba renderizando el bloque de verdad de la vista, no
 * buscando el `if`: lo que importa es qué botones salen.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Notificacion.php';
require_once __DIR__ . '/../app/models/Seguimiento.php';

function assertAcciones($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$archivo = file_get_contents(__DIR__ . '/../app/views/seguimiento/index.php');
$ini = strpos($archivo, '$acciones = [');
$fin = strpos($archivo, '</div>', strpos($archivo, 'data-accion="comentar"'));
assertAcciones($ini !== false && $fin !== false,
    'la vista sigue teniendo el bloque de acciones donde se espera');

// Se toma desde el <?php que abre el bloque para que el fragmento sea válido.
$ini = strrpos(substr($archivo, 0, $ini), '<?php');
$bloque = substr($archivo, $ini, $fin + 6 - $ini);

$pestanas = array_merge(array_keys(Seguimiento::ESTADOS), ['todo']);
foreach ($pestanas as $pestana) {
    $filtros = ['vista' => $pestana];
    ob_start();
    // El fragmento trae su propio <?php, así que primero hay que salir
    // del modo código; si no, eval() lo lee como texto suelto.
    eval('?>' . $bloque);
    $html = ob_get_clean();

    preg_match_all('/data-accion="([a-z_]+)"/', $html, $m);
    $ofrecidas = $m[1];

    assertAcciones($ofrecidas !== [], "la pestaña '{$pestana}' pinta algún botón");

    // Revisión es la excepción declarada: su diálogo es el único que lleva el
    // motivo y el recordatorio, así que sigue disponible para cambiarlos.
    if ($pestana !== 'todo' && $pestana !== 'revision') {
        assertAcciones(!in_array($pestana, $ofrecidas, true),
            "estando en '{$pestana}' no se ofrece mover a '{$pestana}'");
    }

    // Los otros tres estados sí, o quedarían documentos sin salida.
    foreach (array_keys(Seguimiento::ESTADOS) as $estado) {
        if ($estado === $pestana && $pestana !== 'revision') {
            continue;
        }
        assertAcciones(in_array($estado, $ofrecidas, true),
            "estando en '{$pestana}' se puede mover a '{$estado}'");
    }

    // Quitar la marca y anotar valen en cualquier pestaña.
    foreach ([Seguimiento::SIN_MARCA, 'comentar'] as $siempre) {
        assertAcciones(in_array($siempre, $ofrecidas, true),
            "'{$siempre}' está disponible en '{$pestana}'");
    }
}

echo 'OK: acciones de seguimiento (' . count($pestanas) . " pestañas)\n";
