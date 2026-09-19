<?php
/**
 * Dos sociedades no comparten listados.
 *
 * La validación al importar ya impedía que entrara un XML dirigido a otra
 * empresa, pero eso controla la ENTRADA. Las consultas de Facturas, Por pagar
 * y Devoluciones pedían todo lo que hubiera en la tabla, así que con una sola
 * sociedad el filtro era implícito y con dos se habrían mezclado.
 *
 * Se montan dos sociedades de prueba con un documento cada una y se comprueba
 * que ninguna vea lo de la otra, en cada módulo. Todo se borra al terminar.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/Sociedad.php';
require_once __DIR__ . '/../app/models/Factura.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';
require_once __DIR__ . '/../app/models/Pago.php';
require_once __DIR__ . '/../app/models/Notificacion.php';
require_once __DIR__ . '/../app/models/CorreoCuenta.php';

function assertAislamiento($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    echo "SKIP: SociedadAislamiento (sin base de datos disponible)\n";
    exit(0);
}

$marca = '__test_aislamiento__';
$socA = 0;
$socB = 0;
$proveedorId = 0;

$limpiar = function () use ($pdo, $marca, &$socA, &$socB) {
    foreach ([$socA, $socB] as $id) {
        if ($id <= 0) { continue; }
        // El pago ya no tiene líneas propias: sus facturas son filas
        // de facturas_erp marcadas, y esas se sueltan, no se borran.
        $pdo->prepare('UPDATE facturas_erp SET pago_id = NULL WHERE pago_id IN (SELECT id FROM pagos WHERE sociedad_id = ?)')->execute([$id]);
        $pdo->prepare('DELETE FROM facturas_xml WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM facturas_erp WHERE sociedad_id = ?')->execute([$id]);
        // Las incidencias cuelgan de su carga, así que se van primero.
        $pdo->prepare('DELETE FROM facturas_erp_incidencias
                        WHERE carga_id IN (SELECT id FROM facturas_erp_cargas WHERE sociedad_id = ?)')->execute([$id]);
        $pdo->prepare('DELETE FROM facturas_erp_cargas WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM facturas_erp_descartes WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM notificaciones WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM pagos WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM correo_cuenta_sociedades WHERE sociedad_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM sociedades WHERE id = ? AND nombre LIKE ?')->execute([$id, $marca . '%']);
    }
    // El aviso del grupo no cuelga de ninguna empresa: se busca por su marca.
    $pdo->prepare('DELETE FROM notificaciones WHERE firma LIKE ?')->execute([$marca . '%']);
};

$proveedorId = (int) $pdo->query('SELECT MIN(id) FROM proveedores')->fetchColumn();
if ($proveedorId <= 0) {
    echo "SKIP: SociedadAislamiento (sin proveedores registrados)
";
    exit(0);
}

try {
    // Dos empresas con cédulas que no existen en los datos reales.
    $ins = $pdo->prepare('INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)');
    $ins->execute([$marca . ' A', '3199000000001']);
    $socA = (int) $pdo->lastInsertId();
    $ins->execute([$marca . ' B', '3199000000002']);
    $socB = (int) $pdo->lastInsertId();

    // Una factura XML para cada una, con el mismo proveedor y monto: si algo
    // se filtra, no será por diferencias en los datos.
    $insXml = $pdo->prepare(
        "INSERT INTO facturas_xml (sociedad_id, receptor_id, consecutivo_completo, clave, tipo_documento,
                                   numero_factura_asistente, proveedor_id, fecha_emision, total, archivo_xml)
         VALUES (?, ?, ?, ?, 'FE', ?, ?, '2026-08-01', 1000.00, ?)"
    );
    $insXml->execute([$socA, '3199000000001', '00100001010000991001', 'A1', '00991001', $proveedorId, $marca . '_a.xml']);
    $insXml->execute([$socB, '3199000000002', '00100001010000991002', 'B1', '00991002', $proveedorId, $marca . '_b.xml']);

    // Una NC para cada una, para el listado de Notas XML.
    $insNc = $pdo->prepare(
        "INSERT INTO facturas_xml (sociedad_id, receptor_id, consecutivo_completo, clave, tipo_documento,
                                   numero_factura_asistente, proveedor_id, fecha_emision, total, archivo_xml)
         VALUES (?, ?, ?, ?, 'NC', ?, ?, '2026-08-01', 500.00, ?)"
    );
    $insNc->execute([$socA, '3199000000001', '00100001030000991001', 'A2', '00991001', $proveedorId, $marca . '_nca.xml']);
    $insNc->execute([$socB, '3199000000002', '00100001030000991002', 'B2', '00991002', $proveedorId, $marca . '_ncb.xml']);

    // Una factura del ERP para cada una, con EL MISMO documento: es el caso
    // que rompería el cierre semanal si el alcance no funcionara.
    $insErp = $pdo->prepare(
        "INSERT INTO facturas_erp (clave, sociedad_id, proveedor_codigo, proveedor_nombre, sucursal, tipo,
                                   documento, numero_corto, fecha_emision, origen, moneda, monto, saldo)
         VALUES (?, ?, '140099999', ?, 'CEDI', 'F', '00100001010000991001', '00991001', '2026-08-01', 'Local', '¢', 1000.00, 1000.00)"
    );
    $insErp->execute([$marca . '_a', $socA, $marca . ' PROV']);
    $insErp->execute([$marca . '_b', $socB, $marca . ' PROV']);

    $facturas = new Factura();
    $erp = new FacturaErp();
    $pagos = new Pago();

    // ── Facturas ──
    $listaA = $facturas->setSociedad($socA)->buscarConImportacion();
    $listaB = (new Factura())->setSociedad($socB)->buscarConImportacion();
    assertAislamiento(count($listaA) === 1, 'la sociedad A ve exactamente su factura');
    assertAislamiento(count($listaB) === 1, 'la sociedad B ve exactamente su factura');
    assertAislamiento($listaA[0]['archivo_xml'] === $marca . '_a.xml', 'A ve la suya, no la de B');
    assertAislamiento($listaB[0]['archivo_xml'] === $marca . '_b.xml', 'B ve la suya, no la de A');

    assertAislamiento((new Factura())->setSociedad($socA)->contarFacturas() === 1, 'el conteo de A no incluye a B');
    assertAislamiento((int) (new Factura())->setSociedad($socA)->getTotalMonto() === 1000,
        'el total de A no suma las facturas de B');

    // ── Notas XML ──
    assertAislamiento((new Factura())->setSociedad($socA)->countNotasXml() === 1, 'las NC de A no incluyen las de B');
    $ncA = (new Factura())->setSociedad($socA)->getNotasXml();
    assertAislamiento(count($ncA) === 1 && $ncA[0]['archivo_xml'] === $marca . '_nca.xml',
        'el listado de notas de A trae solo la suya');

    // ── Facturas ERP ──
    assertAislamiento((new FacturaErp())->setSociedad($socA)->contar() === 1, 'el ERP de A no cuenta las filas de B');
    $resumenA = (new FacturaErp())->setSociedad($socA)->resumen();
    assertAislamiento((int) $resumenA['saldo'] === 1000, 'el saldo del ERP de A no suma el de B');

    // ── Historial de cargas e incidencias ──
    //
    // La carga lleva su empresa; la incidencia la hereda de su carga. Sin
    // acotar, una empresa recién registrada abría Facturas con el historial y
    // las alertas de otra, que es como se descubrió esto.
    $insCarga = $pdo->prepare(
        "INSERT INTO facturas_erp_cargas (archivo_origen, sociedad_id, filas_leidas, insertadas)
         VALUES (?, ?, 1, 1)"
    );
    $insCarga->execute([$marca . '_a.csv', $socA]);
    $cargaA = (int) $pdo->lastInsertId();
    $insCarga->execute([$marca . '_b.csv', $socB]);
    $cargaB = (int) $pdo->lastInsertId();

    $insInc = $pdo->prepare(
        "INSERT INTO facturas_erp_incidencias (carga_id, tipo, severidad, proveedor_codigo,
                                               proveedor_nombre, documento, detalle, firma)
         VALUES (?, 'saldo_subio', 'alerta', '140099999', ?, '00100001010000991001', 'prueba', ?)"
    );
    $insInc->execute([$cargaA, $marca . ' PROV', $marca . '_firma']);
    $insInc->execute([$cargaB, $marca . ' PROV', $marca . '_firma']);

    $cargasA = (new FacturaErp())->setSociedad($socA)->cargas(50);
    assertAislamiento(count($cargasA) === 1, 'A ve solo su historial de cargas');
    assertAislamiento($cargasA[0]['archivo_origen'] === $marca . '_a.csv',
        'el historial de A trae su archivo, no el de B');

    $ultimaB = (new FacturaErp())->setSociedad($socB)->ultimaCarga();
    assertAislamiento($ultimaB && $ultimaB['archivo_origen'] === $marca . '_b.csv',
        'la última carga de B es la suya, aunque A haya cargado después');

    assertAislamiento((new FacturaErp())->setSociedad($socA)->contarIncidencias([]) === 1,
        'las incidencias de A no cuentan las de B');
    $incA = (new FacturaErp())->setSociedad($socA)->incidencias([], 50);
    assertAislamiento(count($incA) === 1 && (int) $incA[0]['carga_id'] === $cargaA,
        'el listado de incidencias de A trae solo las de su carga');

    // Descartar en A no puede callar la misma firma en B: la firma es
    // proveedor+documento y se repite entre empresas del mismo grupo.
    (new FacturaErp())->setSociedad($socA)->descartarIncidencias(
        array_map('intval', array_column($incA, 'id')), 'prueba'
    );
    assertAislamiento((new FacturaErp())->setSociedad($socB)->contarIncidencias([]) === 1,
        'un descarte hecho en A deja en pie la incidencia de B');
    assertAislamiento((new FacturaErp())->setSociedad($socA)->contarIncidencias([]) === 0,
        'el descarte sí surte efecto en A');

    // ── Matching del pago ──
    // Ambas tienen una factura con el mismo consecutivo: A solo debe ver la
    // suya. El cruce del pago es por consecutivo y el consecutivo se repite
    // entre sociedades, así que sin este filtro A se respaldaría con el
    // comprobante de B.
    $candidatasA = (new Factura())->setSociedad($socA)->getCandidatasParaPago();
    assertAislamiento(count($candidatasA) === 1, 'el matching de A considera solo sus comprobantes');
    assertAislamiento($candidatasA[0]['consecutivo_completo'] === '00100001010000991001',
        'el matching de A toma su propio comprobante');

    // El índice con que se resuelve un archivo de pago tampoco cruza empresas.
    $indiceA = (new FacturaErp())->setSociedad($socA)->getIndicePago();
    assertAislamiento(count($indiceA) === 1, 'el índice de facturas ERP de A no incluye las de B');

    /*
     * La misma clave en las dos empresas.
     *
     * `clave` es proveedor|documento|fecha: identifica una factura dentro de
     * una empresa, y las del grupo le compran a los mismos proveedores. Con la
     * unicidad puesta solo sobre la clave, la segunda de estas dos filas
     * reventaba el INSERT y se llevaba por delante la carga entera.
     */
    $insErp->execute([$marca . '_repetida', $socA, $marca . ' PROV']);
    $pdo->prepare(
        "INSERT INTO facturas_erp (clave, sociedad_id, proveedor_codigo, proveedor_nombre, sucursal, tipo,
                                   documento, numero_corto, fecha_emision, origen, moneda, monto, saldo)
         VALUES (?, ?, '140099999', ?, 'CEDI', 'F', '00100001010000991002', '00991002', '2026-08-01', 'Local', '¢', 1000.00, 1000.00)"
    )->execute([$marca . '_repetida', $socB, $marca . ' PROV']);
    assertAislamiento((new FacturaErp())->setSociedad($socA)->contar() === 2,
        'A ve sus dos filas, y la clave repetida de B no le suma ni le quita');
    assertAislamiento((new FacturaErp())->setSociedad($socB)->contar() === 2,
        'B pudo guardar una factura cuya clave ya existía en A');

    // ── Pagos ──
    $pagoA = (int) (new Pago())->setSociedad($socA)->crear('Pago A ' . $marca, $socA);
    $pagoB = (int) (new Pago())->setSociedad($socB)->crear('Pago B ' . $marca, $socB);
    assertAislamiento($pagoA > 0 && $pagoB > 0, 'se crean pagos para ambas sociedades');
    assertAislamiento(count((new Pago())->setSociedad($socA)->getAll()) === 1, 'A ve solo su pago');
    assertAislamiento((new Pago())->setSociedad($socA)->existePara($pagoB) === false,
        'A no puede seleccionar un pago de B');
    assertAislamiento((new Pago())->setSociedad($socA)->existePara($pagoA) === true,
        'A sí puede seleccionar la suya');

    // ── El archivo del pago ──
    //
    // Ya no hay una ficha aparte para el listado: el archivo se anota sobre el
    // propio pago, así que el aislamiento es el mismo del pago.
    (new Pago())->setSociedad($socA)->registrarArchivo($pagoA, $marca . '.xlsx', $socA);
    (new Pago())->setSociedad($socB)->registrarArchivo($pagoB, $marca . '.xlsx', $socB);
    assertAislamiento(count((new Pago())->setSociedad($socA)->getConDetalle(50)) === 1,
        'A ve solo su pago, con su archivo');
    assertAislamiento((new Pago())->setSociedad($socB)->getConSociedad($pagoA) === null,
        'B no puede abrir un pago de A por id');

    /*
     * ── Abrir por su id algo de la otra empresa ──
     *
     * Es la puerta que no se ve: los listados están acotados, pero a la ficha
     * de un documento, al XML, al PDF y a un pago se llega con un número en la
     * dirección o en el formulario. Escribir el de la otra empresa no puede
     * devolver nada, y en el caso de /documentos/xml y /documentos/pdf eso no
     * era mirar datos ajenos sino descargarse el comprobante.
     */
    $idXmlDeB = (int) $pdo->query(
        "SELECT id FROM facturas_xml WHERE sociedad_id = {$socB} AND tipo_documento = 'FE' LIMIT 1"
    )->fetchColumn();
    assertAislamiento($idXmlDeB > 0, 'la prueba tiene un comprobante de B con el que preguntar');
    assertAislamiento(
        (new Factura())->setSociedad($socA)->getOneWithProvider($idXmlDeB) === null,
        'A no puede abrir —ni descargar— el comprobante de B por su id'
    );
    assertAislamiento(
        (new Factura())->setSociedad($socA)->findById($idXmlDeB) === null,
        'A no puede tomar el comprobante de B para respaldar una factura suya'
    );
    assertAislamiento(
        (new Factura())->setSociedad($socB)->getOneWithProvider($idXmlDeB) !== null,
        'B sí abre el suyo: el filtro acota, no tapa'
    );

    $idErpDeB = (int) $pdo->query(
        "SELECT id FROM facturas_erp WHERE sociedad_id = {$socB} LIMIT 1"
    )->fetchColumn();
    assertAislamiento(
        (new FacturaErp())->setSociedad($socA)->getFacturaPago($idErpDeB) === null,
        'A no puede abrir la factura del ERP de B por su id'
    );

    assertAislamiento((new Pago())->setSociedad($socA)->findById($pagoB) === null,
        'A no puede abrir el pago de B por su id');
    assertAislamiento((new Pago())->setSociedad($socB)->findById($pagoB) !== null,
        'B sí abre el suyo');

    // ── La campana ──
    //
    // Un aviso es de una empresa o del grupo. El del grupo (sociedad_id 0) se
    // ve desde todas y sigue juntando sus repeticiones en un solo renglón, que
    // es lo que se perdería si "del grupo" se guardara como NULL.
    $avisos = new Notificacion();
    $firmaA = $marca . '|solo_de_A';
    $firmaGrupo = $marca . '|del_grupo';
    (new Notificacion())->setSociedad($socA)->avisar([
        'tipo' => 'prueba', 'titulo' => 'Pendiente de A',
        'firma' => $firmaA, 'sociedad_id' => $socA,
    ]);
    $avisos->avisar(['tipo' => 'prueba', 'titulo' => 'Del grupo', 'firma' => $firmaGrupo]);
    $avisos->avisar(['tipo' => 'prueba', 'titulo' => 'Del grupo', 'firma' => $firmaGrupo]);

    $contar = $pdo->prepare('SELECT COUNT(*), MAX(veces) FROM notificaciones WHERE firma = ?');
    $contar->execute([$firmaGrupo]);
    [$filasGrupo, $vecesGrupo] = array_values($contar->fetch(PDO::FETCH_NUM));
    assertAislamiento((int) $filasGrupo === 1 && (int) $vecesGrupo === 2,
        'un aviso del grupo repetido sigue siendo un solo renglón que cuenta las veces');

    $deA = array_column((new Notificacion())->setSociedad($socA)->listar('pendiente', 500), 'firma');
    $deB = array_column((new Notificacion())->setSociedad($socB)->listar('pendiente', 500), 'firma');
    assertAislamiento(in_array($firmaA, $deA, true), 'A ve su propio aviso');
    assertAislamiento(!in_array($firmaA, $deB, true), 'B no ve el aviso de A');
    assertAislamiento(in_array($firmaGrupo, $deA, true) && in_array($firmaGrupo, $deB, true),
        'el aviso del grupo se ve desde las dos');

    // ── Buzones de correo ──
    $cuentaId = (int) $pdo->query('SELECT MIN(id) FROM correo_cuentas')->fetchColumn();
    if ($cuentaId > 0) {
        $pdo->prepare('INSERT IGNORE INTO correo_cuenta_sociedades (cuenta_id, sociedad_id) VALUES (?, ?)')
            ->execute([$cuentaId, $socA]);
        $cuentas = new CorreoCuenta();
        assertAislamiento(count($cuentas->setSociedad($socA)->getVisibles()) === 1,
            'A ve el buzón que tiene asignado');
        assertAislamiento(count((new CorreoCuenta())->setSociedad($socB)->getVisibles()) === 0,
            'B no ve un buzón que no tiene asignado');
        assertAislamiento((new CorreoCuenta())->setSociedad($socA)->perteneceASociedad($cuentaId) === true,
            'el buzón pertenece a A');
        assertAislamiento((new CorreoCuenta())->setSociedad($socB)->perteneceASociedad($cuentaId) === false,
            'el mismo buzón no pertenece a B');

        // Un buzón puede servir a varias: al agregar B, ambas lo ven.
        $cuentas->asignarSociedades($cuentaId, array_merge($cuentas->sociedadesDe($cuentaId), [$socB]));
        assertAislamiento(count((new CorreoCuenta())->setSociedad($socB)->getVisibles()) === 1,
            'un mismo buzón puede servir a dos sociedades');
    }
} catch (Throwable $e) {
    $limpiar();
    fwrite(STDERR, 'FAIL: excepción inesperada: ' . $e->getMessage() . "\n");
    exit(1);
}

$limpiar();
echo "OK: los módulos no mezclan datos entre sociedades\n";
