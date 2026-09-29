<?php
/**
 * El controlador de Correo en sus bordes: lo que llega del navegador (vacío,
 * nulo, mal formado o desmesurado) y los ayudantes con los que arma nombres,
 * números y resultados.
 *
 * Las acciones corren sin exit ni IMAP: json() graba la respuesta, los modelos
 * hablan con la PDO falsa, y todo caso que necesitaría el buzón se detiene en
 * la validación que tiene que cortarlo antes.
 *
 * Uso: php tests/CorreoControladorBordeTest.php
 */
require_once __DIR__ . '/soporte/CorreoSoporte.php';

Prueba::iniciar('Correo: controlador en sus bordes');

/** La cuenta 3, de la empresa 4, ya registrada. */
function cuentaRegistrada(): void
{
    Soporte::$pdo->respuestas['SELECT COUNT(*) FROM correo_cuentas'] = [['c' => 1]];
    Soporte::$pdo->respuestas['FROM correo_cuentas WHERE id = ?'] = [[
        'id' => '3', 'nombre' => 'Facturación', 'host' => 'mail.test.invalid', 'puerto' => '993',
        'usuario' => 'facturas@empresa.example', 'password' => base64_encode('secreta'), 'carpeta' => 'INBOX',
        'dias_atras' => '0', 'indice_retencion_dias' => '0', 'solo_no_leidos' => '0',
    ]];
    Soporte::$pdo->respuestas['SELECT sociedad_id FROM correo_cuenta_sociedades'] = [['sociedad_id' => '4']];
    $_POST['cuenta_id'] = '3';
}

function sonda(): CorreoControllerSonda
{
    return new CorreoControllerSonda();
}

// ── Ayudantes puros ────────────────────────────────────────────────────────

Prueba::caso('parseIds: nulos, vacíos, basura, negativos y repetidos', function () {
    $c = sonda();
    igual([], $c->privado('parseIds', null), 'null');
    igual([], $c->privado('parseIds', ''), 'vacío');
    igual([1, 2], $c->privado('parseIds', ' 1, 2,,abc,-3,2 ,0'), 'solo positivos, sin repetir');
    igual([7], $c->privado('parseIds', ['7', 7, 'siete', null]), 'arreglo');
});

Prueba::caso('idsRecibidos y filtrosIncidencia: JSON roto, anidado y valores fuera de lista', function () {
    $c = sonda();
    $_POST['ids'] = '[1,"2",-3,"x",null]';
    $ids = $c->privado('idsRecibidos');
    verificar($ids === array_map('intval', $ids), 'todo sale entero');
    igual([1, 2], array_values(array_filter($ids, fn($id) => $id > 0)), 'basura y nulos fuera (un negativo pasa, pero no existe)');
    $_POST['ids'] = 'hola';
    igual([], $c->privado('idsRecibidos'), 'JSON inválido');
    unset($_POST['ids']);
    igual([], $c->privado('idsRecibidos'), 'ausente');
    $_POST = ['ver' => ' TODAS ', 'q' => "  cédula\n", 'tipo' => null];
    igual(['q' => 'cédula', 'tipo' => '', 'ver' => 'todas'], $c->privado('filtrosIncidencia'), 'normaliza');
    $_POST = ['ver' => 'borradas'];
    igual('pendientes', $c->privado('filtrosIncidencia')['ver'], 'fuera de lista → pendientes');
});

Prueba::caso('numeroCorto y numeroDesdeClave: ceros, varias corridas, vacío y claves incompletas', function () {
    $c = sonda();
    igual('123', $c->privado('numeroCorto', 'FE-000123-0045'), 'la corrida más larga sin ceros');
    igual('', $c->privado('numeroCorto', '0000'), 'todo ceros es vacío');
    igual('', $c->privado('numeroCorto', null), 'null');
    $clave = '50601092600310112345600100001010000012345112345678';
    igual('12345', $c->privado('numeroDesdeClave', "Factura#{$clave}.pdf"), 'desde la clave de 50');
    igual('', $c->privado('numeroDesdeClave', substr($clave, 1)), '49 dígitos no es clave');
});

Prueba::caso('tokenProveedor: acentos, sufijos, emoji, vacío y longitud', function () {
    $c = sonda();
    igual('DISTRIBUIDORA_LA_N', $c->privado('tokenProveedor', 'Distribuidora La Ñ, S.A.'), 'sin acentos ni sufijo');
    igual('PROVEEDOR', $c->privado('tokenProveedor', 'S.A.'), 'solo sufijo');
    igual('PROVEEDOR', $c->privado('tokenProveedor', ''), 'vacío');
    igual('FRUTAS', $c->privado('tokenProveedor', '🍎 Frutas'), 'emoji fuera');
    $largo = $c->privado('tokenProveedor', str_repeat('PALABRA ', 30));
    verificar(strlen($largo) <= DocumentoArchivo::MAX_TOKEN_PROVEEDOR, 'se recorta');
    verificar(substr($largo, -1) !== '_', 'sin guion bajo colgando');
});

Prueba::caso('nombreArchivoBandeja: fila vacía, nota de crédito con letras y tipo desconocido', function () {
    $c = sonda();
    igual('FE_PROVEEDOR_000000_00000000', $c->privado('nombreArchivoBandeja', []), 'todo por omisión');
    igual('NC_ACME_120726_00000123', $c->privado('nombreArchivoBandeja',
        ['tipo_doc' => 'nc', 'proveedor' => 'Acme S.A.', 'fecha_emision' => '2026-07-12', 'numero_corto' => 'NC-00123']),
        'NC: solo dígitos, ocho');
    igual('FE_ACME_000000_123456789012', $c->privado('nombreArchivoBandeja',
        ['tipo_doc' => 'XX', 'proveedor' => 'Acme', 'fecha_emision' => 'ayer no', 'numero_corto' => '123456789012']),
        'tipo raro → FE; fecha ilegible → 000000; número largo se deja entero');
});

Prueba::caso('tipoIncidencia y PDF de interpretación', function () {
    $c = sonda();
    igual('mensaje_hacienda', $c->privado('tipoIncidencia', 'El XML es un mensaje de Hacienda (MensajeHacienda)'), 'acuse');
    igual('receptor', $c->privado('tipoIncidencia', 'Receptor con otra cédula (3101)'), 'cédula');
    igual('rechazado', $c->privado('tipoIncidencia', 'Rechazada por Hacienda: 12'), 'rechazo');
    igual('nota_debito', $c->privado('tipoIncidencia', 'Nota de débito descartada'), 'ND');
    igual('xml_invalido', $c->privado('tipoIncidencia', null, 'xml_invalido'), 'null → por omisión');
    verificar($c->privado('esPdfInterpretacion', 'INTERPRETACIÓN_506.PDF'), 'mayúsculas y tilde');
    verificar(!$c->privado('esPdfInterpretacion', null), 'null');
});

Prueba::caso('pdfCorrespondeFactura: sin número nunca; por clave; por número exacto', function () {
    $c = sonda();
    verificar(!$c->privado('pdfCorrespondeFactura', 'FE-123.pdf', ['numero_corto' => '']), 'sin número');
    verificar(!$c->privado('pdfCorrespondeFactura', 'FE-123.pdf', []), 'sin clave en la fila');
    verificar($c->privado('pdfCorrespondeFactura', 'FE-123.pdf', ['numero_corto' => '123']), 'exacto');
    verificar($c->privado('pdfCorrespondeFactura',
        'Factura#50601092600310112345600100001010000012345112345678.pdf', ['numero_corto' => '12345']), 'por clave');
});

Prueba::caso('deduplicar resultados: la misma generación repetida se junta', function () {
    $c = sonda();
    igual([], $c->privado('deduplicarCorreosBusqueda', []), 'vacío');
    $base = ['carpeta' => 'INBOX', 'timestamp' => 1756742400, 'remitente' => 'A <a@x>', 'asunto' => 'Factura 12'];
    $r = $c->privado('deduplicarCorreosBusqueda', [
        $base + ['uid' => 10, 'clave' => 'c3:99:10'],
        ['remitente' => ' a <A@X> ', 'asunto' => "factura  12"] + $base + ['uid' => 4, 'clave' => 'c3:77:4'],
    ]);
    igual(1, count($r), 'misma carpeta, segundo, remitente y asunto de dos UIDVALIDITY: un solo correo');
    igual(2, count($c->privado('deduplicarCorreosBusqueda', [
        ['carpeta' => 'INBOX', 'timestamp' => 0, 'uid' => 1], ['carpeta' => 'INBOX', 'timestamp' => 0, 'uid' => 2],
    ])), 'sin fecha se distinguen por UID');
});

Prueba::caso('[L3] deduplicar resultados: dos correos distintos del mismo segundo y asunto se conservan', function () {
    // Los sistemas de facturación mandan ráfagas: "Comprobante electrónico"
    // tres veces a las 10:00:00, un correo por factura.
    $base = ['carpeta' => 'INBOX', 'timestamp' => 1756742400, 'remitente' => 'fe@proveedor', 'asunto' => 'Comprobante electrónico'];
    $r = sonda()->privado('deduplicarCorreosBusqueda', [
        $base + ['uid' => 101, 'clave' => 'c3:77:101', 'adjuntos' => 'FE-0001.xml'],
        $base + ['uid' => 102, 'clave' => 'c3:77:102', 'adjuntos' => 'FE-0002.xml'],
        $base + ['uid' => 103, 'clave' => 'c3:77:103', 'adjuntos' => 'FE-0003.xml'],
    ]);
    igual(3, count($r), 'antes la búsqueda muestra una sola de las tres facturas');
});

Prueba::caso('resumenConfig: null, y un usuario normal se enmascara', function () {
    $c = sonda();
    igual(null, $c->privado('resumenConfig', null), 'null');
    igual('fa••••••@empresa.example', $c->privado('resumenConfig', ['usuario' => 'facturas@empresa.example'])['usuario'], 'enmascarado');
    igual(14, $c->privado('resumenConfig', ['usuario' => ''])['dias_atras'], 'días por omisión');
});

Prueba::caso('[L4] resumenConfig: los usuarios cortos o sin arroba también se enmascaran', function () {
    $c = sonda();
    verificar($c->privado('resumenConfig', ['usuario' => 'ab@empresa.example'])['usuario'] !== 'ab@empresa.example',
        'antes "ab@…" sale completo');
    verificar($c->privado('resumenConfig', ['usuario' => 'contabilidad01'])['usuario'] !== 'contabilidad01',
        'antes un usuario sin arroba sale completo');
});

Prueba::caso('rango y correo de la descarga masiva: ausentes, al revés, formato y mayúsculas', function () {
    $c = sonda();
    lanza(fn() => $c->privado('rangoGeneral'), 'rango');
    $_POST = ['fecha_desde' => '2026-09-10', 'fecha_hasta' => '2026-09-01'];
    lanza(fn() => $c->privado('rangoGeneral'), 'rango');
    $_POST = ['fecha_desde' => '10/09/2026', 'fecha_hasta' => '2026-09-11'];
    lanza(fn() => $c->privado('rangoGeneral'), 'rango');
    $_POST = ['fecha_desde' => ' 2026-09-01 ', 'fecha_hasta' => '2026-09-01'];
    igual(['2026-09-01', '2026-09-01'], $c->privado('rangoGeneral'), 'un solo día vale');

    $_POST = ['correo_busqueda' => ' Proveedor@Ejemplo.CR '];
    igual('proveedor@ejemplo.cr', $c->privado('correoBusquedaGeneral'), 'minúsculas y sin espacios');
    $_POST = ['correo_busqueda' => "a@b.cr\r\nBcc: x@y"];
    lanza(fn() => $c->privado('correoBusquedaGeneral'), 'válido');
});

Prueba::caso('[L2] rango de la descarga masiva: el 30 de febrero no existe', function () {
    $_POST = ['fecha_desde' => '2026-02-30', 'fecha_hasta' => '2026-02-31'];
    lanza(fn() => sonda()->privado('rangoGeneral'), 'rango');
});

// ── Acciones: lo que las corta antes de tocar el buzón ─────────────────────

Prueba::caso('las acciones AJAX rechazan GET con 405', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    foreach (['listar', 'sincronizar', 'contenido', 'adjunto', 'procesar', 'importar', 'descartar',
              'generalProcesar', 'cuentaGuardar'] as $accion) {
        igual(405, sonda()->responder($accion)[0], $accion);
    }
});

Prueba::caso('sin buzón para la empresa: 422 con la explicación, sin conectar', function () {
    Soporte::$pdo->respuestas['SELECT COUNT(*) FROM correo_cuentas'] = [['c' => 1]];
    $_POST['cuenta_id'] = '3';
    [$codigo, $cuerpo] = sonda()->responder('listar');
    igual(422, $codigo, 'código');
    verificar(strpos($cuerpo['message'], 'Ningún buzón') !== false, 'mensaje');
});

Prueba::caso('procesar: items vacíos, JSON sin forma o UID inválidos no llegan al buzón', function () {
    foreach (['', '{"a":1}', '[{"uid":"x"},{"uid":-4},{"carpeta":"INBOX"},7,null]', 'null'] as $items) {
        Soporte::limpiarPeticion();
        cuentaRegistrada();
        $_POST['items'] = $items;
        [$codigo, $cuerpo] = sonda()->responder('procesar');
        igual(422, $codigo, "items {$items}");
        igual('No seleccionaste ningún correo.', $cuerpo['message'], 'mensaje');
    }
});

Prueba::caso('adjunto y contenido: UID y sección inválidos se cortan con 422', function () {
    foreach ([['uid' => '0', 'seccion' => '1'], ['uid' => '5', 'seccion' => ''], ['uid' => '5', 'seccion' => '1.'],
              ['uid' => '5', 'seccion' => '1.2; DROP'], ['uid' => '5', 'seccion' => '../1'], ['uid' => '-5', 'seccion' => '2']] as $p) {
        Soporte::limpiarPeticion();
        cuentaRegistrada();
        $_POST += $p;
        igual(422, sonda()->responder('adjunto')[0], json_encode($p));
    }
    Soporte::limpiarPeticion();
    cuentaRegistrada();
    $_POST['uid'] = 'abc';
    igual(422, sonda()->responder('contenido')[0], 'contenido con UID no numérico');
});

Prueba::caso('descarga masiva: rango inválido y cuenta ajena responden 422 sin crear nada', function () {
    cuentaRegistrada();
    $_POST += ['fecha_desde' => '2026-09-10', 'fecha_hasta' => '2026-09-01'];
    [$codigo, $cuerpo] = sonda()->responder('generalEstimar');
    igual(422, $codigo, 'rango al revés');

    Soporte::limpiarPeticion();
    cuentaRegistrada();
    Soporte::$pdo->respuestas['SELECT sociedad_id FROM correo_cuenta_sociedades'] = [['sociedad_id' => '9']];
    $_POST += ['fecha_desde' => '2026-09-01', 'fecha_hasta' => '2026-09-02'];
    [$codigo, $cuerpo] = sonda()->responder('generalCrear');
    igual(422, $codigo, 'buzón de otra empresa');
    igual(0, Soporte::$pdo->contar('INSERT INTO correo_lotes'), 'sin lote');
});

Prueba::caso('cuentas: datos obligatorios y contraseña al crear', function () {
    $_POST = ['nombre' => '  ', 'host' => 'h', 'usuario' => 'u', 'password' => 'p'];
    igual(422, sonda()->responder('cuentaGuardar')[0], 'nombre en blanco');
    Soporte::limpiarPeticion();
    $_POST = ['nombre' => 'N', 'host' => 'h', 'usuario' => 'u', 'password' => "   "];
    igual(422, sonda()->responder('cuentaGuardar')[0], 'contraseña en blanco al crear');
    igual(0, Soporte::$pdo->contar('INSERT INTO correo_cuentas'), 'nada escrito');
});

Prueba::caso('la carpeta compartida ya no se elige: el explorador y el selector no existen', function () {
    // Aquí se probaban el explorador de carpetas del disco y el selector con
    // la ventana de Windows. Se fueron con la carpeta compartida: los
    // documentos van a SharePoint, que no se elige desde la pantalla.
    foreach (['config', 'carpetas', 'selectorAbrir', 'selectorEstado', 'scriptPicker'] as $accion) {
        verificar(!method_exists(CorreoController::class, $accion), "CorreoController::{$accion}() ya no existe");
    }
});

Prueba::caso('incidencias: descartar sin selección responde 422', function () {
    cuentaRegistrada();
    $_POST['ids'] = '[]';
    igual(422, sonda()->responder('incidenciasDescartar')[0], 'sin ids');
    Soporte::limpiarPeticion();
    $_POST['ids'] = 'nada';
    igual(422, sonda()->responder('incidenciasRestaurar')[0], 'restaurar sin ids');
});

Prueba::caso('[L6] listar: una página más allá de la última se acota a la última', function () {
    cuentaRegistrada();
    Soporte::$pdo->respuestas['SELECT COUNT(*) FROM correo_indice'] = [['c' => 3]];
    $_POST += ['texto' => 'factura', 'ambito' => 'asunto', 'pagina' => '10000'];
    [$codigo, $cuerpo] = sonda()->responder('listar');
    igual(200, $codigo, 'responde');
    $pagina = Soporte::$pdo->de('SELECT uid, clave')[0]['sql'];
    verificar(strpos($pagina, 'OFFSET 0') !== false, 'antes pide OFFSET 4999500 sobre el índice');
});

Prueba::caso('[L1] importar: después de responder no queda código que nunca corre', function () {
    // json() termina la petición con exit. Todo lo que sigue a la primera
    // respuesta de éxito —copiar el par renombrado a la carpeta destino y
    // avisar lo que no llegó— no se ejecuta nunca, y la pantalla sigue
    // prometiendo "archivos renombrados en la carpeta".
    $metodo = new ReflectionMethod(CorreoController::class, 'importar');
    $lineas = array_slice(file($metodo->getFileName()), $metodo->getStartLine() - 1,
        $metodo->getEndLine() - $metodo->getStartLine() + 1);
    $codigo = implode('', $lineas);
    $primera = strpos($codigo, "'ok' => true");
    verificar($primera !== false, 'la acción responde éxito');
    $resto = substr($codigo, $primera);
    igual(1, substr_count($resto, '$this->json(['), 'antes hay una segunda respuesta de éxito inalcanzable');
});

Prueba::terminar();
