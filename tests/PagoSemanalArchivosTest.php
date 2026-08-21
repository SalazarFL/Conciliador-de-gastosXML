<?php
/**
 * Cambiar quién respalda el pago tiene que mover los archivos.
 *
 * La carpeta del pago semanal existe para entregar respaldos: se crea al
 * cargar el pago y se llena con una copia del par XML/PDF de cada factura
 * respaldada. El acomodo lo hace el organizador, y el organizador hay que
 * llamarlo — no se entera solo.
 *
 * Cuatro puertas cambian la composición del pago, y tres de ellas lo llamaban.
 * La que faltaba era `subir()`, la más grande: cargar el pago creaba la carpeta
 * y la dejaba vacía hasta que corriera la tarea programada, que ordena cada
 * quince minutos y solo si está activa en esa computadora. Quien cargaba el
 * pago abría la carpeta recién creada y no encontraba nada.
 *
 * Se comprueba sobre el código y no ejecutando el controlador porque lo que
 * falló no fue una cuenta ni una condición: fue una llamada que no estaba. El
 * organizador ya tiene sus propias pruebas —OrganizadorDocumentosTest cubre la
 * copia al pago, incluido que un par sin PDF no se entrega—; lo que acá se
 * vigila es que alguien la mande a correr.
 */

function assertArchivosPago($condicion, $mensaje)
{
    if (!$condicion) {
        fwrite(STDERR, "FAIL: {$mensaje}\n");
        exit(1);
    }
}

$fuente = file_get_contents(__DIR__ . '/../app/controllers/PorPagarController.php');
assertArchivosPago(is_string($fuente) && $fuente !== '', 'se puede leer el controlador del pago semanal');

/** El cuerpo de cada método, cortado en la siguiente declaración. */
$cuerpos = [];
if (preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $fuente, $m, PREG_OFFSET_CAPTURE)) {
    foreach ($m[1] as $i => $encontrado) {
        $inicio = $encontrado[1];
        $fin = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($fuente);
        $cuerpos[$encontrado[0]] = substr($fuente, $inicio, $fin - $inicio);
    }
}
assertArchivosPago(isset($cuerpos['subir']), 'el controlador sigue teniendo subir()');
assertArchivosPago(isset($cuerpos['reubicarArchivos']), 'sigue existiendo el ayudante que llama al organizador');

// La regla: quien vuelve a cruzar el pago cambia qué comprobante respalda qué
// factura, y eso decide qué archivos van a la carpeta de entrega. Re-cruzar
// sin reubicar deja la carpeta diciendo lo de antes.
foreach ($cuerpos as $nombre => $cuerpo) {
    if ($nombre === 'ejecutarMatching' || strpos($cuerpo, 'ejecutarMatching(') === false) {
        continue;
    }
    assertArchivosPago(
        strpos($cuerpo, 'reubicarArchivos(') !== false,
        "{$nombre}() vuelve a cruzar el pago pero no manda a acomodar los archivos: "
        . 'la carpeta del pago queda como estaba'
    );
}

// Las tres que ya lo hacían, nombradas una por una: si alguien las cambia de
// nombre o les quita la llamada, el bucle de arriba se queda callado.
foreach (['subir', 'actualizarListado', 'verificar', 'quitarFactura', 'borrarSemana', 'forzar'] as $puerta) {
    assertArchivosPago(isset($cuerpos[$puerta]), "sigue existiendo {$puerta}()");
    assertArchivosPago(
        strpos($cuerpos[$puerta], 'reubicarArchivos(') !== false,
        "{$puerta}() cambia la composición del pago y debe acomodar los archivos"
    );
}

// Cargar el pago acomoda TODOS los comprobantes del pago, no un pedazo: es la
// primera vez que esa carpeta se llena y no hay un "antes" contra el que
// calcular un delta.
assertArchivosPago(
    strpos($cuerpos['subir'], 'idsXmlDePago(') !== false,
    'subir() acomoda los comprobantes del pago completo'
);

// Y lo dice. Una copia que no se anuncia es indistinguible de una que no pasó,
// que es exactamente cómo se vivió el error.
assertArchivosPago(
    strpos($cuerpos['subir'], "copias_pago") !== false,
    'subir() cuenta en pantalla cuántos comprobantes llegaron a la carpeta'
);
assertArchivosPago(
    strpos($cuerpos['reubicarArchivos'], "errores_copia_pago") !== false,
    'una copia que falla no se queda callada'
);

echo "OK PagoSemanalArchivosTest\n";
