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
$ini = strpos($archivo, '$acciones = $esModoCorreo ? [');
$fin = strpos($archivo, '</div>', strpos($archivo, 'data-accion="comentar"'));
assertAcciones($ini !== false && $fin !== false,
    'la vista sigue teniendo el bloque de acciones donde se espera');

// Se toma desde el <?php que abre el bloque para que el fragmento sea válido.
$ini = strrpos(substr($archivo, 0, $ini), '<?php');
$bloque = substr($archivo, $ini, $fin + 6 - $ini);

/*
 * Los dos modos, con sus propias pestañas. En Correo son tres —no existe
 * 'lista'—, y un botón hacia una pestaña que esa pantalla no dibuja dejaría
 * renglones aparcados donde nadie puede volver a buscarlos.
 */
$revisadas = 0;
foreach ([Seguimiento::MODO_SISTEMA, Seguimiento::MODO_CORREO] as $modo) {
    $esModoCorreo = $modo === Seguimiento::MODO_CORREO;
    $estados = Seguimiento::estadosDe($modo);
    $pestanas = array_merge(array_keys($estados), ['todo']);

    foreach ($pestanas as $pestana) {
        $filtros = ['vista' => $pestana];
        ob_start();
        // El fragmento trae su propio <?php, así que primero hay que salir
        // del modo código; si no, eval() lo lee como texto suelto.
        eval('?>' . $bloque);
        $html = ob_get_clean();

        preg_match_all('/data-accion="([a-z_]+)"/', $html, $m);
        $ofrecidas = $m[1];

        assertAcciones($ofrecidas !== [], "modo {$modo}: la pestaña '{$pestana}' pinta algún botón");

        // Revisión es la excepción declarada: su diálogo es el único que lleva
        // el motivo y el recordatorio, así que sigue disponible para cambiarlos.
        if ($pestana !== 'todo' && $pestana !== 'revision') {
            assertAcciones(!in_array($pestana, $ofrecidas, true),
                "modo {$modo}: estando en '{$pestana}' no se ofrece mover a '{$pestana}'");
        }

        // Los demás estados sí, o quedarían documentos sin salida.
        foreach (array_keys($estados) as $estado) {
            if ($estado === $pestana && $pestana !== 'revision') {
                continue;
            }
            assertAcciones(in_array($estado, $ofrecidas, true),
                "modo {$modo}: estando en '{$pestana}' se puede mover a '{$estado}'");
        }

        // Y ninguno ajeno al modo: 'lista' en Correo mandaría el renglón a una
        // pestaña que ahí no existe.
        foreach ($ofrecidas as $ofrecida) {
            assertAcciones(
                isset($estados[$ofrecida]) || $ofrecida === Seguimiento::SIN_MARCA
                    || $ofrecida === 'comentar',
                "modo {$modo}: no se ofrece '{$ofrecida}', que no es una pestaña de este modo"
            );
        }

        // Quitar la marca y anotar valen en cualquier pestaña de cualquier modo.
        foreach ([Seguimiento::SIN_MARCA, 'comentar'] as $siempre) {
            assertAcciones(in_array($siempre, $ofrecidas, true),
                "modo {$modo}: '{$siempre}' está disponible en '{$pestana}'");
        }
        $revisadas++;
    }
}

echo 'OK: acciones de seguimiento (' . $revisadas . " pestañas, los dos modos)\n";
