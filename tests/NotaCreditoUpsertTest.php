<?php
/**
 * Verifica el maestro acumulativo de notas de credito:
 *   nota nueva                         -> se inserta
 *   misma identidad y mismo saldo      -> la linea no se toca
 *   misma identidad y saldo distinto   -> solo cambia el saldo y su auditoria
 *   nota ausente en una foto posterior -> se conserva
 *
 * La identidad es sociedad + proveedor + sucursal + documento + moneda +
 * monto. La prueba crea sociedades, proveedor y XML sinteticos, y elimina
 * todo lo que crea aunque falle una asercion.
 */
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/NotaCredito.php';
require_once __DIR__ . '/../app/helpers/NotasCreditoVerificador.php';

function assertNotaCreditoUpsert($condicion, $mensaje)
{
    if (!$condicion) {
        throw new RuntimeException('FAIL: ' . $mensaje);
    }
}

function assertSaldoNotaCredito($esperado, $actual, $mensaje)
{
    assertNotaCreditoUpsert(
        abs((float) $esperado - (float) $actual) < 0.005,
        $mensaje . ' (esperado ' . $esperado . ', recibido ' . $actual . ')'
    );
}

$config = require __DIR__ . '/../app/config/database.php';
try {
    $pdo = new PDO(
        $config['dsn'],
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    echo "SKIP: NotaCreditoUpsert (sin base de datos disponible)\n";
    exit(0);
}

$token = bin2hex(random_bytes(6));
$marca = '__test_nc_upsert_' . $token . '__';
$rfcProveedor = 'NC' . strtoupper(substr($token, 0, 11));
$proveedorId = 0;
$xmlId = 0;
$xmlIds = [];
$sociedades = [];

/**
 * Borra explicitamente las tablas hijas porque algunas instalaciones antiguas
 * no tienen todas las llaves foraneas de la migracion formal.
 */
$limpiar = function () use (
    $pdo,
    &$sociedades,
    &$proveedorId,
    &$xmlIds,
    $rfcProveedor
) {
    foreach (array_keys($sociedades) as $sociedadId) {
        if ((int) $sociedadId <= 0) {
            continue;
        }

        $params = [(int) $sociedadId];
        $pdo->prepare(
            'DELETE FROM notas_credito_historial
              WHERE listado_id IN (
                    SELECT id FROM notas_credito_listados WHERE sociedad_id = ?
              )'
        )->execute($params);
        $pdo->prepare(
            'DELETE FROM notas_credito_verificaciones
              WHERE listado_id IN (
                    SELECT id FROM notas_credito_listados WHERE sociedad_id = ?
              )'
        )->execute($params);
        $pdo->prepare(
            'DELETE FROM notas_credito_lineas
              WHERE listado_id IN (
                    SELECT id FROM notas_credito_listados WHERE sociedad_id = ?
              )'
        )->execute($params);
        $pdo->prepare('DELETE FROM notas_credito_cargas WHERE sociedad_id = ?')
            ->execute($params);
        $pdo->prepare('DELETE FROM notas_credito_listados WHERE sociedad_id = ?')
            ->execute($params);
    }

    foreach (array_unique(array_map('intval', $xmlIds)) as $id) {
        if ($id > 0) {
            $pdo->prepare('DELETE FROM facturas_xml WHERE id = ?')->execute([$id]);
        }
    }

    foreach ($sociedades as $sociedadId => $cedula) {
        $pdo->prepare('DELETE FROM sociedades WHERE id = ? AND cedula = ?')
            ->execute([(int) $sociedadId, (string) $cedula]);
    }

    if ($proveedorId > 0) {
        $pdo->prepare('DELETE FROM proveedores WHERE id = ? AND rfc = ?')
            ->execute([$proveedorId, $rfcProveedor]);
    }
};

try {
    // Tres sociedades inactivas y un proveedor que no pueden confundirse con
    // informacion de produccion.
    $cedulaA = '3199' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    do {
        $cedulaB = '3199' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    } while ($cedulaB === $cedulaA);
    do {
        $cedulaLegacy = '3199' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    } while (in_array($cedulaLegacy, [$cedulaA, $cedulaB], true));

    $insertarSociedad = $pdo->prepare(
        'INSERT INTO sociedades (nombre, cedula, activa) VALUES (?, ?, 0)'
    );
    $insertarSociedad->execute([$marca . ' SOCIEDAD A', $cedulaA]);
    $sociedadA = (int) $pdo->lastInsertId();
    $sociedades[$sociedadA] = $cedulaA;

    $insertarSociedad->execute([$marca . ' SOCIEDAD B', $cedulaB]);
    $sociedadB = (int) $pdo->lastInsertId();
    $sociedades[$sociedadB] = $cedulaB;

    $insertarSociedad->execute([$marca . ' SOCIEDAD LEGACY', $cedulaLegacy]);
    $sociedadLegacy = (int) $pdo->lastInsertId();
    $sociedades[$sociedadLegacy] = $cedulaLegacy;

    $pdo->prepare(
        'INSERT INTO proveedores
            (rfc, razon_social, razon_social_normalizada, alias, activo)
         VALUES (?, ?, ?, ?, 0)'
    )->execute([
        $rfcProveedor,
        $marca . ' PROVEEDOR XML',
        $marca . ' PROVEEDOR XML',
        $marca,
    ]);
    $proveedorId = (int) $pdo->lastInsertId();

    $numeroAleatorio = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    $consecutivoFactura = '001000010100' . $numeroAleatorio;
    $consecutivoNc = '001000010300' . $numeroAleatorio;
    $claveNc = str_repeat('5', 21) . $consecutivoNc . str_repeat('7', 9);

    $pdo->prepare(
        "INSERT INTO facturas_xml
            (sociedad_id, receptor_id, consecutivo_completo, clave, tipo_documento,
             numero_factura_asistente, proveedor_id, fecha_emision, total, moneda,
             archivo_xml)
         VALUES (?, ?, ?, ?, 'NC', ?, ?, '2026-08-01', 100.00, 'CRC', ?)"
    )->execute([
        $sociedadA,
        $cedulaA,
        $consecutivoNc,
        $claveNc,
        $numeroAleatorio,
        $proveedorId,
        $marca . '.xml',
    ]);
    $xmlId = (int) $pdo->lastInsertId();
    $xmlIds[] = $xmlId;

    $proveedorA = '990' . substr($numeroAleatorio, 0, 6);
    $proveedorB = '991' . substr($numeroAleatorio, 0, 6);
    $documentoBase = 'NC- 17-1-' . $consecutivoFactura . '-684';
    $documentoAusente = 'NC- 8' . substr($numeroAleatorio, 0, 6);
    $documentoIdentidad = 'NC- 9' . substr($numeroAleatorio, 0, 6);

    $linea = function (
        $fila,
        $documento,
        $proveedorCodigo,
        $proveedorNombre,
        $sucursal,
        $moneda,
        $monto,
        $saldo
    ) use ($marca) {
        return [
            'fila_origen' => (int) $fila,
            'proveedor_codigo' => (string) $proveedorCodigo,
            'proveedor_nombre' => (string) $proveedorNombre,
            'sucursal' => (string) $sucursal,
            'documento' => (string) $documento,
            'fecha' => '2026-08-01',
            'nc_proveedor' => null,
            'fecha_nc_proveedor' => null,
            'entrada_asociada' => null,
            'moneda' => (string) $moneda,
            'monto' => (float) $monto,
            'saldo' => (float) $saldo,
            'monto_conversion' => 0.0,
            'datos_origen' => json_encode(['prueba' => $marca, 'fila' => (int) $fila]),
        ];
    };

    $meta = function ($sociedadId, $etapa, $filas) use ($marca) {
        return [
            'sociedad_id' => (int) $sociedadId,
            'nombre' => $marca . ' ACUMULADO ' . (int) $sociedadId,
            'empresa_reporte' => $marca,
            'periodo_desde' => '2026-08-01',
            'periodo_hasta' => '2026-08-31',
            'archivo_origen' => $marca . '_' . $etapa . '.csv',
            'archivo_ruta' => '',
            'archivo_hash' => hash('sha256', $marca . '|' . $sociedadId . '|' . $etapa),
            'filas_leidas' => (int) $filas,
            'filas_invalidas' => 0,
        ];
    };

    $base = $linea(
        1,
        $documentoBase,
        $proveedorA,
        $marca . ' PROVEEDOR A',
        'CEDI',
        'CRC',
        100.00,
        100.00
    );
    $ausente = $linea(
        2,
        $documentoAusente,
        $proveedorA,
        $marca . ' PROVEEDOR A',
        'CEDI',
        'CRC',
        75.00,
        75.00
    );
    $loteInicial = [$base, $ausente];

    // claveCarga no incluye el saldo, pero si cada componente de la identidad.
    $claveBase = NotaCredito::claveCarga($base);
    $soloSaldo = $base;
    $soloSaldo['saldo'] = 1.00;
    assertNotaCreditoUpsert(
        NotaCredito::claveCarga($soloSaldo) === $claveBase,
        'claveCarga mantiene la identidad cuando solo cambia el saldo'
    );

    $variacionesClave = [];
    foreach (['proveedor_codigo', 'sucursal', 'monto', 'moneda'] as $campo) {
        $variacion = $base;
        if ($campo === 'proveedor_codigo') {
            $variacion[$campo] = $proveedorB;
        } elseif ($campo === 'sucursal') {
            $variacion[$campo] = 'AUTOMERCADO';
        } elseif ($campo === 'monto') {
            $variacion[$campo] = 101.00;
        } else {
            $variacion[$campo] = 'USD';
        }
        $variacionesClave[] = NotaCredito::claveCarga($variacion);
        assertNotaCreditoUpsert(
            NotaCredito::claveCarga($variacion) !== $claveBase,
            'claveCarga distingue el mismo numero cuando cambia ' . $campo
        );
    }
    assertNotaCreditoUpsert(
        count(array_unique(array_merge([$claveBase], $variacionesClave))) === 5,
        'las cinco identidades calculadas son distintas entre si'
    );

    $modelo = new NotaCredito();

    // 1) Primera foto: las dos notas son nuevas.
    $metaInicial = $meta($sociedadA, 'inicial', count($loteInicial));
    $r1 = $modelo->importarConsolidado($loteInicial, $metaInicial, null);
    assertNotaCreditoUpsert($r1['insertadas'] === 2, 'la primera carga inserta sus dos notas');
    assertNotaCreditoUpsert($r1['actualizadas'] === 0, 'la primera carga no actualiza saldos');
    assertNotaCreditoUpsert($r1['sin_cambio'] === 0, 'la primera carga no tiene filas previas');
    assertNotaCreditoUpsert($r1['total'] === 2, 'el acumulado inicia con dos notas');

    $contarCargas = $pdo->prepare(
        'SELECT COUNT(*) FROM notas_credito_cargas WHERE sociedad_id = ?'
    );
    $contarCargas->execute([$sociedadA]);
    $cargasAntesDeRevisarEsquema = (int) $contarCargas->fetchColumn();
    Esquema::olvidar();
    $modelo = new NotaCredito();
    $contarCargas->execute([$sociedadA]);
    assertNotaCreditoUpsert(
        (int) $contarCargas->fetchColumn() === $cargasAntesDeRevisarEsquema,
        'revisar el esquema no inventa una carga legacy para el listado canonico'
    );

    $seleccionarLineas = $pdo->prepare(
        'SELECT * FROM notas_credito_lineas WHERE listado_id = ? ORDER BY id'
    );
    $seleccionarLineas->execute([$r1['listado_id']]);
    $antesRecarga = $seleccionarLineas->fetchAll();
    assertNotaCreditoUpsert(count($antesRecarga) === 2, 'se guardaron exactamente dos lineas');

    // 2) La misma foto genera auditoria de carga, pero no toca ninguna linea.
    $r2 = $modelo->importarConsolidado($loteInicial, $metaInicial, null);
    assertNotaCreditoUpsert($r2['listado_id'] === $r1['listado_id'], 'la recarga usa el mismo acumulado');
    assertNotaCreditoUpsert($r2['insertadas'] === 0, 'la recarga identica no inserta');
    assertNotaCreditoUpsert($r2['actualizadas'] === 0, 'la recarga identica no actualiza');
    assertNotaCreditoUpsert($r2['sin_cambio'] === 2, 'la recarga reconoce ambas lineas sin cambio');
    assertNotaCreditoUpsert($r2['carga_id'] !== $r1['carga_id'], 'cada foto conserva su propia carga');

    $seleccionarLineas->execute([$r1['listado_id']]);
    assertNotaCreditoUpsert(
        $antesRecarga === $seleccionarLineas->fetchAll(),
        'una recarga identica no altera ningun campo de las lineas'
    );

    $buscarDocumento = $pdo->prepare(
        'SELECT * FROM notas_credito_lineas WHERE listado_id = ? AND documento = ? LIMIT 1'
    );
    $buscarDocumento->execute([$r1['listado_id'], $documentoBase]);
    $filaBase = $buscarDocumento->fetch();
    assertNotaCreditoUpsert((bool) $filaBase, 'se encuentra la nota que cambiara de saldo');

    // Simula una conciliacion manual ya realizada antes de recibir otra foto.
    $pdo->prepare(
        "UPDATE notas_credito_lineas
            SET factura_xml_id = ?, estado = 'con_diferencia', diferencia = 7.25,
                metodo_match = 'manual', score_proveedor = 87.5,
                match_manual = 1, bloqueo_automatico = 1,
                motivo_match = 'Vinculo manual de prueba'
          WHERE id = ?"
    )->execute([$xmlId, $filaBase['id']]);

    $columnasMatch = [
        'factura_xml_id',
        'estado',
        'diferencia',
        'metodo_match',
        'score_proveedor',
        'match_manual',
        'bloqueo_automatico',
        'motivo_match',
    ];
    $seleccionarMatch = $pdo->prepare(
        'SELECT ' . implode(', ', $columnasMatch) . '
           FROM notas_credito_lineas WHERE id = ?'
    );
    $seleccionarMatch->execute([$filaBase['id']]);
    $matchAntes = $seleccionarMatch->fetch();

    $buscarDocumento->execute([$r1['listado_id'], $documentoAusente]);
    $ausenteAntes = $buscarDocumento->fetch();

    // 3) Solo viene la nota que cambio. La otra no debe interpretarse como
    // eliminada, y los campos de conciliacion de la modificada sobreviven.
    $baseActualizada = $base;
    $baseActualizada['saldo'] = 40.00;
    $r3 = $modelo->importarConsolidado(
        [$baseActualizada],
        $meta($sociedadA, 'cambio_saldo', 1),
        null
    );
    assertNotaCreditoUpsert($r3['insertadas'] === 0, 'el cambio de saldo no crea otra nota');
    assertNotaCreditoUpsert($r3['actualizadas'] === 1, 'solo se actualiza el saldo distinto');
    assertNotaCreditoUpsert($r3['sin_cambio'] === 0, 'la unica linea recibida cambio');
    assertNotaCreditoUpsert($r3['total'] === 2, 'la nota ausente se conserva en el total');

    $buscarDocumento->execute([$r1['listado_id'], $documentoBase]);
    $filaCambiada = $buscarDocumento->fetch();
    assertNotaCreditoUpsert(
        (int) $filaCambiada['id'] === (int) $filaBase['id'],
        'el saldo se actualiza sobre la misma fila'
    );
    assertSaldoNotaCredito(40.00, $filaCambiada['saldo'], 'se guarda el saldo nuevo');
    assertSaldoNotaCredito(100.00, $filaCambiada['saldo_anterior'], 'se guarda saldo_anterior');
    assertNotaCreditoUpsert(
        (int) $filaCambiada['carga_cambio_id'] === (int) $r3['carga_id'],
        'la linea apunta a la carga que cambio el saldo'
    );
    assertNotaCreditoUpsert(
        $filaCambiada['saldo_cambiado_en'] !== null,
        'se registra la fecha del cambio de saldo'
    );

    $seleccionarMatch->execute([$filaBase['id']]);
    assertNotaCreditoUpsert(
        $matchAntes === $seleccionarMatch->fetch(),
        'el cambio de saldo preserva vinculo, estado, metodo, manual y bloqueo'
    );

    $buscarDocumento->execute([$r1['listado_id'], $documentoAusente]);
    assertNotaCreditoUpsert(
        $ausenteAntes === $buscarDocumento->fetch(),
        'una nota ausente en la foto nueva no se borra ni se modifica'
    );

    // 4) Cinco filas comparten numero; una es el documento nuevo y las otras
    // cambian exactamente proveedor, sucursal, monto o moneda.
    $identidadBase = $linea(
        10,
        $documentoIdentidad,
        $proveedorA,
        $marca . ' PROVEEDOR A',
        'CEDI',
        'CRC',
        25.00,
        25.00
    );
    $identidadProveedor = $identidadBase;
    $identidadProveedor['fila_origen'] = 11;
    $identidadProveedor['proveedor_codigo'] = $proveedorB;
    $identidadProveedor['proveedor_nombre'] = $marca . ' PROVEEDOR B';

    $identidadSucursal = $identidadBase;
    $identidadSucursal['fila_origen'] = 12;
    $identidadSucursal['sucursal'] = 'AUTOMERCADO';

    $identidadMonto = $identidadBase;
    $identidadMonto['fila_origen'] = 13;
    $identidadMonto['monto'] = 26.00;
    $identidadMonto['saldo'] = 26.00;

    $identidadMoneda = $identidadBase;
    $identidadMoneda['fila_origen'] = 14;
    $identidadMoneda['moneda'] = 'USD';

    $mismoNumero = [
        $identidadBase,
        $identidadProveedor,
        $identidadSucursal,
        $identidadMonto,
        $identidadMoneda,
    ];
    assertNotaCreditoUpsert(
        count(array_unique(array_map([NotaCredito::class, 'claveCarga'], $mismoNumero))) === 5,
        'claveCarga distingue las cinco variantes del mismo numero'
    );

    $loteConNuevas = array_merge([$baseActualizada], $mismoNumero);
    $r4 = $modelo->importarConsolidado(
        $loteConNuevas,
        $meta($sociedadA, 'documentos_nuevos', count($loteConNuevas)),
        null
    );
    NotasCreditoVerificador::verificarListado(
        $r4['listado_id'],
        $modelo,
        'carga_incremental',
        $r4['ids_verificar']
    );
    assertNotaCreditoUpsert($r4['insertadas'] === 5, 'se agregan el documento nuevo y sus cuatro identidades distintas');
    assertNotaCreditoUpsert($r4['actualizadas'] === 0, 'la carga de documentos nuevos no vuelve a tocar el saldo');
    assertNotaCreditoUpsert($r4['sin_cambio'] === 1, 'la nota previa se reconoce sin cambio');
    assertNotaCreditoUpsert($r4['total'] === 7, 'el acumulado conserva dos notas y agrega cinco');
    $seleccionarMatch->execute([$filaBase['id']]);
    assertNotaCreditoUpsert(
        $matchAntes === $seleccionarMatch->fetch(),
        'la verificacion incremental tampoco redistribuye el vinculo existente'
    );

    $contarNumero = $pdo->prepare(
        'SELECT COUNT(*) FROM notas_credito_lineas
          WHERE listado_id = ? AND documento = ?'
    );
    $contarNumero->execute([$r1['listado_id'], $documentoIdentidad]);
    assertNotaCreditoUpsert(
        (int) $contarNumero->fetchColumn() === 5,
        'el mismo numero queda en cinco filas cuando cambia su identidad'
    );

    $buscarDocumento->execute([$r1['listado_id'], $documentoAusente]);
    assertNotaCreditoUpsert(
        (int) ($buscarDocumento->fetch()['id'] ?? 0) === (int) $ausenteAntes['id'],
        'la nota que sigue ausente permanece despues de mas cargas'
    );

    // 5) La clave calculada puede repetirse entre sociedades: el alcance de la
    // consulta es el que impide que una empresa actualice a la otra.
    $mismaEnB = $baseActualizada;
    $mismaEnB['saldo'] = 777.00;
    assertNotaCreditoUpsert(
        NotaCredito::claveCarga($mismaEnB) === NotaCredito::claveCarga($baseActualizada),
        'la sociedad se aplica como alcance, no dentro de claveCarga'
    );
    $rB = $modelo->importarConsolidado(
        [$mismaEnB],
        $meta($sociedadB, 'aislamiento', 1),
        null
    );
    assertNotaCreditoUpsert($rB['insertadas'] === 1, 'la misma identidad es nueva en otra sociedad');
    assertNotaCreditoUpsert($rB['total'] === 1, 'la sociedad B tiene su propio acumulado');
    assertNotaCreditoUpsert(
        $rB['listado_id'] !== $r1['listado_id'],
        'cada sociedad conserva un listado canonico separado'
    );

    $saldosPorSociedad = $pdo->prepare(
        'SELECT li.sociedad_id, nl.saldo
           FROM notas_credito_lineas nl
           JOIN notas_credito_listados li ON li.id = nl.listado_id
          WHERE li.sociedad_id IN (?, ?)
            AND nl.documento = ?
            AND nl.proveedor_codigo = ?
            AND nl.sucursal = ?
            AND nl.moneda = ?
            AND nl.monto = ?
          ORDER BY li.sociedad_id'
    );
    $saldosPorSociedad->execute([
        $sociedadA,
        $sociedadB,
        $documentoBase,
        $proveedorA,
        'CEDI',
        'CRC',
        100.00,
    ]);
    $aisladas = [];
    foreach ($saldosPorSociedad->fetchAll() as $fila) {
        $aisladas[(int) $fila['sociedad_id']] = (float) $fila['saldo'];
    }
    assertNotaCreditoUpsert(count($aisladas) === 2, 'la identidad existe una vez en cada sociedad');
    assertSaldoNotaCredito(40.00, $aisladas[$sociedadA] ?? null, 'la sociedad A conserva su saldo');
    assertSaldoNotaCredito(777.00, $aisladas[$sociedadB] ?? null, 'la sociedad B conserva su saldo');

    // 6) Datos heredados: dos fotos de la misma sociedad ya contenian la
    // misma identidad. La fila del listado mas reciente es la canonica y su
    // ID debe sobrevivir. Si esta vacia, hereda la decision manual antigua;
    // si ambos lados tienen XML distintos, prevalece la decision canonica;
    // y un bloqueo canonico sin XML conserva la desvinculacion manual.
    $numerosXmlLegacy = [];
    while (count($numerosXmlLegacy) < 4) {
        $numero = str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        if ($numero !== $numeroAleatorio && !in_array($numero, $numerosXmlLegacy, true)) {
            $numerosXmlLegacy[] = $numero;
        }
    }

    $insertarXmlLegacy = $pdo->prepare(
        "INSERT INTO facturas_xml
            (sociedad_id, receptor_id, consecutivo_completo, clave, tipo_documento,
             numero_factura_asistente, proveedor_id, fecha_emision, total, moneda,
             archivo_xml)
         VALUES (?, ?, ?, ?, 'NC', ?, ?, '2026-07-01', ?, 'CRC', ?)"
    );
    $xmlLegacy = [];
    foreach ($numerosXmlLegacy as $indice => $numero) {
        $consecutivo = '001000010300' . $numero;
        $clave = str_repeat((string) (6 + $indice), 21)
            . $consecutivo
            . str_pad((string) ($indice + 1), 9, '0', STR_PAD_LEFT);
        $insertarXmlLegacy->execute([
            $sociedadLegacy,
            $cedulaLegacy,
            $consecutivo,
            $clave,
            $numero,
            $proveedorId,
            55.00 + ($indice * 10),
            $marca . '_legacy_' . ($indice + 1) . '.xml',
        ]);
        $xmlLegacy[] = (int) $pdo->lastInsertId();
        $xmlIds[] = (int) $pdo->lastInsertId();
    }

    $insertarListadoLegacy = $pdo->prepare(
        'INSERT INTO notas_credito_listados
            (sociedad_id, nombre, empresa_reporte, periodo_desde, periodo_hasta,
             archivo_origen, archivo_ruta, archivo_hash, total_lineas, fecha_subida)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 4, ?)'
    );
    $insertarListadoLegacy->execute([
        $sociedadLegacy,
        $marca . ' FOTO HISTORICA',
        $marca,
        '2026-06-01',
        '2026-06-30',
        $marca . '_historica.csv',
        '',
        hash('sha256', $marca . '|listado-historico'),
        '2026-07-01 08:00:00',
    ]);
    $listadoHistorico = (int) $pdo->lastInsertId();

    $insertarListadoLegacy->execute([
        $sociedadLegacy,
        $marca . ' FOTO CANONICA',
        $marca,
        '2026-07-01',
        '2026-07-31',
        $marca . '_canonica.csv',
        '',
        hash('sha256', $marca . '|listado-canonico'),
        '2026-08-01 08:00:00',
    ]);
    $listadoCanonico = (int) $pdo->lastInsertId();

    $documentoTransferencia = 'NC- 17-1-001000010100'
        . $numerosXmlLegacy[0] . '-681';
    $documentoConflicto = 'NC- 17-1-001000010100'
        . $numerosXmlLegacy[1] . '-682';
    $documentoBloqueado = 'NC- 17-1-001000010100'
        . $numerosXmlLegacy[3] . '-683';
    $documentoBloqueoHistorico = 'NC- 17-1-001000010100'
        . $numerosXmlLegacy[2] . '-684';
    $legacyTransferencia = $linea(
        101,
        $documentoTransferencia,
        $proveedorA,
        $marca . ' PROVEEDOR A',
        'LEGACY',
        'CRC',
        55.00,
        55.00
    );
    $legacyConflicto = $linea(
        102,
        $documentoConflicto,
        $proveedorA,
        $marca . ' PROVEEDOR A',
        'LEGACY',
        'CRC',
        65.00,
        65.00
    );
    $legacyBloqueado = $linea(
        103,
        $documentoBloqueado,
        $proveedorA,
        $marca . ' PROVEEDOR A',
        'LEGACY',
        'CRC',
        85.00,
        85.00
    );
    $legacyBloqueoHistorico = $linea(
        104,
        $documentoBloqueoHistorico,
        $proveedorA,
        $marca . ' PROVEEDOR A',
        'LEGACY',
        'CRC',
        75.00,
        75.00
    );
    $canonicaTransferencia = $legacyTransferencia;
    $canonicaTransferencia['fila_origen'] = 201;
    $canonicaConflicto = $legacyConflicto;
    $canonicaConflicto['fila_origen'] = 202;
    $canonicaBloqueada = $legacyBloqueado;
    $canonicaBloqueada['fila_origen'] = 203;
    $canonicaBloqueoHistorico = $legacyBloqueoHistorico;
    $canonicaBloqueoHistorico['fila_origen'] = 204;

    $idHistoricoTransferencia = $modelo->crearLinea(
        $listadoHistorico,
        $legacyTransferencia
    );
    $idHistoricoConflicto = $modelo->crearLinea($listadoHistorico, $legacyConflicto);
    $idHistoricoBloqueado = $modelo->crearLinea($listadoHistorico, $legacyBloqueado);
    $idHistoricoBloqueo = $modelo->crearLinea(
        $listadoHistorico,
        $legacyBloqueoHistorico
    );
    $idCanonicoTransferencia = $modelo->crearLinea(
        $listadoCanonico,
        $canonicaTransferencia
    );
    $idCanonicoConflicto = $modelo->crearLinea($listadoCanonico, $canonicaConflicto);
    $idCanonicoBloqueado = $modelo->crearLinea($listadoCanonico, $canonicaBloqueada);
    $idCanonicoBloqueo = $modelo->crearLinea(
        $listadoCanonico,
        $canonicaBloqueoHistorico
    );

    $actualizarDecision = $pdo->prepare(
        'UPDATE notas_credito_lineas
            SET factura_xml_id = ?, estado = ?, diferencia = ?, metodo_match = ?,
                score_proveedor = ?, match_manual = ?, bloqueo_automatico = ?,
                motivo_match = ?
          WHERE id = ?'
    );
    $actualizarDecision->execute([
        $xmlLegacy[0],
        'con_diferencia',
        2.50,
        'manual',
        93.5,
        1,
        1,
        'Decision manual historica',
        $idHistoricoTransferencia,
    ]);
    $actualizarDecision->execute([
        $xmlLegacy[2],
        'con_diferencia',
        1.25,
        'manual',
        88.0,
        1,
        1,
        'XML historico que no debe desplazar al canonico',
        $idHistoricoConflicto,
    ]);
    $actualizarDecision->execute([
        $xmlLegacy[1],
        'coincide',
        0.00,
        'manual',
        100.0,
        1,
        1,
        'Decision manual canonica',
        $idCanonicoConflicto,
    ]);
    $actualizarDecision->execute([
        $xmlLegacy[3],
        'coincide',
        0.00,
        'manual',
        100.0,
        1,
        0,
        'Vinculo historico descartado despues',
        $idHistoricoBloqueado,
    ]);
    $actualizarDecision->execute([
        null,
        'sin_respaldo',
        null,
        'ninguno',
        null,
        0,
        1,
        'Desvinculada manualmente.',
        $idCanonicoBloqueado,
    ]);
    $actualizarDecision->execute([
        null,
        'sin_respaldo',
        null,
        'ninguno',
        null,
        0,
        1,
        'Desvinculada manualmente en la foto historica.',
        $idHistoricoBloqueo,
    ]);

    $seleccionarMatch->execute([$idHistoricoTransferencia]);
    $decisionHistorica = $seleccionarMatch->fetch();
    $seleccionarMatch->execute([$idCanonicoConflicto]);
    $decisionCanonica = $seleccionarMatch->fetch();
    $seleccionarMatch->execute([$idCanonicoBloqueado]);
    $decisionBloqueada = $seleccionarMatch->fetch();
    $seleccionarMatch->execute([$idHistoricoBloqueo]);
    $bloqueoHistorico = $seleccionarMatch->fetch();

    $loteLegacy = [
        $canonicaTransferencia,
        $canonicaConflicto,
        $canonicaBloqueada,
        $canonicaBloqueoHistorico,
    ];
    $previewLegacy = $modelo->previsualizarImportacion($sociedadLegacy, $loteLegacy);
    $rLegacy = $modelo->importarConsolidado(
        $loteLegacy,
        $meta($sociedadLegacy, 'consolidacion_legacy', count($loteLegacy)),
        null
    );
    assertNotaCreditoUpsert(
        $rLegacy['listado_id'] === $listadoCanonico,
        'la importacion legacy conserva el listado mas reciente como canonico'
    );
    assertNotaCreditoUpsert($rLegacy['insertadas'] === 0, 'las identidades legacy no se duplican');
    assertNotaCreditoUpsert($rLegacy['total'] === 4, 'el listado canonico conserva una fila por identidad');
    assertNotaCreditoUpsert(
        (int) $previewLegacy['recuperables'] === (int) $rLegacy['recuperadas'],
        'la previsualizacion y la importacion cuentan las mismas recuperaciones legacy'
    );

    $seleccionarMatch->execute([$idCanonicoTransferencia]);
    assertNotaCreditoUpsert(
        $decisionHistorica === $seleccionarMatch->fetch(),
        'la fila canonica vacia hereda vinculo y decision manual de la historica'
    );
    $seleccionarMatch->execute([$idCanonicoConflicto]);
    assertNotaCreditoUpsert(
        $decisionCanonica === $seleccionarMatch->fetch(),
        'dos XML distintos conservan integramente la decision canonica'
    );
    $seleccionarMatch->execute([$idCanonicoBloqueado]);
    assertNotaCreditoUpsert(
        $decisionBloqueada === $seleccionarMatch->fetch(),
        'el bloqueo canonico conserva NULL y no hereda el XML historico'
    );
    $seleccionarMatch->execute([$idCanonicoBloqueo]);
    assertNotaCreditoUpsert(
        $bloqueoHistorico === $seleccionarMatch->fetch(),
        'el canonico vacio hereda el bloqueo historico aunque no exista XML'
    );

    $filasCanonicasLegacy = $pdo->prepare(
        'SELECT id, documento FROM notas_credito_lineas
          WHERE listado_id = ? ORDER BY id'
    );
    $filasCanonicasLegacy->execute([$listadoCanonico]);
    $filasCanonicasLegacy = $filasCanonicasLegacy->fetchAll();
    assertNotaCreditoUpsert(
        array_map('intval', array_column($filasCanonicasLegacy, 'id'))
            === [
                $idCanonicoTransferencia,
                $idCanonicoConflicto,
                $idCanonicoBloqueado,
                $idCanonicoBloqueo,
            ],
        'la consolidacion retiene los IDs canonicos y no mueve duplicados historicos'
    );

    echo "OK: NotaCreditoUpsert\n";
} finally {
    $limpiar();
}
