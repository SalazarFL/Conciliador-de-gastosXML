<?php
/**
 * Deshace los emparejamientos que unieron una línea del ERP con un comprobante
 * que no es el suyo.
 *
 * Uso: php cli/reparar_enganches_falsos.php [--aplicar]
 *
 * Sin --aplicar solo informa.
 *
 * Qué salió mal: hasta el arreglo de `FacturaErp::elegirCandidata`, el enganche
 * que corre al importar un XML se conformaba con que coincidieran los últimos
 * ocho dígitos del número. Cuando la línea del ERP y el comprobante traían cada
 * uno su consecutivo de veinte dígitos y no eran el mismo, los comparaba igual
 * por la cola: así la línea `00110191010000065118` (Pipasa, ₡90.950,31) quedó
 * respaldada por `00100001010000065118` (Coopeagropal, ₡9.769.985,83). Encima
 * anotaba 100/100 en los dos scores, de modo que el error no se distinguía de
 * un emparejamiento bueno en ninguna pantalla.
 *
 * La regla de esta reparación es estrecha a propósito: solo toca filas donde
 * las DOS partes traen consecutivo de veinte dígitos y son distintos. Eso no es
 * un parecido dudoso, es prueba de que son documentos diferentes. Los enganches
 * por número corto —donde el ERP no trae consecutivo— no se tocan: ahí el
 * número corto es lo único que hay y decidirlo desde aquí sería adivinar.
 *
 * Tampoco toca `match_manual = 1`: si una persona vinculó esa línea a mano, su
 * decisión vale más que esta regla.
 *
 * Qué hace con cada una:
 *   - si el comprobante correcto ya está en la base y libre -> lo engancha
 *   - si no -> deja la línea sin respaldo, que es la verdad
 *
 * Los XML que se sueltan no se borran ni se tocan: quedan disponibles para que
 * la verificación del pago o una persona los enganchen donde corresponda.
 */
if (PHP_SAPI !== 'cli') { exit("Solo CLI.\n"); }

$aplicar = in_array('--aplicar', $argv, true);

$config = require __DIR__ . '/../app/config/database.php';
$pdo = new PDO(
    $config['dsn'],
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/**
 * Las líneas cuyo comprobante tiene otro consecutivo, con el comprobante
 * correcto ya localizado cuando existe.
 *
 * El correcto se busca en la misma sociedad: un consecutivo puede repetirse
 * entre empresas y cruzarlas sería cambiar un error por otro. Y se exige que
 * esté libre —sin otra línea que ya lo reclame— para no robárselo a nadie.
 */
$sql = "SELECT e.id, e.sociedad_id, e.documento, e.proveedor_codigo, e.proveedor_nombre,
               e.monto, e.factura_xml_id, e.estado_respaldo,
               x.consecutivo_completo AS consecutivo_actual, x.total AS total_actual,
               px.razon_social AS proveedor_actual,
               (SELECT b.id
                  FROM facturas_xml b
                 WHERE REGEXP_REPLACE(COALESCE(b.consecutivo_completo,''), '[^0-9]', '') = e.documento
                   AND b.sociedad_id = e.sociedad_id
                   AND NOT EXISTS (SELECT 1 FROM facturas_erp o
                                    WHERE o.factura_xml_id = b.id AND o.id <> e.id)
                 ORDER BY b.id LIMIT 1) AS xml_correcto
          FROM facturas_erp e
          JOIN facturas_xml x ON x.id = e.factura_xml_id
          LEFT JOIN proveedores px ON px.id = x.proveedor_id
         WHERE e.documento REGEXP '^[0-9]{20}$'
           AND e.match_manual = 0
           AND REGEXP_REPLACE(COALESCE(x.consecutivo_completo,''), '[^0-9]', '') <> e.documento
         ORDER BY e.sociedad_id, e.id";

$filas = $pdo->query($sql)->fetchAll();

if (!$filas) {
    echo "No hay emparejamientos falsos que reparar.\n";
    exit(0);
}

printf("%d línea(s) del ERP están respaldadas por un comprobante de otro consecutivo.\n\n", count($filas));

$reenganchar = [];
$soltar = [];

foreach ($filas as $f) {
    $destino = (int) ($f['xml_correcto'] ?? 0);
    if ($destino > 0) {
        $reenganchar[] = $f;
    } else {
        $soltar[] = $f;
    }
    printf(
        "  ERP #%-6s %s  %-34s ₡%s\n      tenía: XML #%-6s %s  %s (₡%s)\n      %s\n",
        $f['id'],
        $f['documento'],
        mb_substr((string) $f['proveedor_nombre'], 0, 34),
        number_format((float) $f['monto'], 2),
        $f['factura_xml_id'],
        $f['consecutivo_actual'],
        mb_substr((string) ($f['proveedor_actual'] ?? '—'), 0, 30),
        number_format((float) $f['total_actual'], 2),
        $destino > 0
            ? "queda: XML #{$destino}, que sí trae ese consecutivo"
            : 'queda: sin respaldo (el comprobante correcto no está en la base)'
    );
}

printf(
    "\nResumen: %d se reenganchan al comprobante correcto, %d quedan sin respaldo.\n",
    count($reenganchar),
    count($soltar)
);

if (!$aplicar) {
    echo "\nEnsayo. Para escribirlo: php cli/reparar_enganches_falsos.php --aplicar\n";
    exit(0);
}

/**
 * El reenganche recalcula la diferencia contra el comprobante correcto, y
 * anota los scores que corresponden: el consecutivo coincide entero, así que
 * el número vale 100; el proveedor se deja en 0 y lo resuelve la verificación
 * del pago, que es la que consulta el mapa de códigos. Poner 100 sin haberlo
 * comprobado es justamente lo que hacía invisible este error.
 */
$reengancha = $pdo->prepare(
    "UPDATE facturas_erp
        SET factura_xml_id = ?, estado_respaldo = ?, diferencia = ?,
            score_numero = 100.0, score_proveedor = 0.0
      WHERE id = ? AND match_manual = 0"
);
$suelta = $pdo->prepare(
    "UPDATE facturas_erp
        SET factura_xml_id = NULL, estado_respaldo = 'sin_respaldo', diferencia = NULL,
            score_numero = NULL, score_proveedor = NULL
      WHERE id = ? AND match_manual = 0"
);
$totalDe = $pdo->prepare('SELECT total FROM facturas_xml WHERE id = ?');

$pdo->beginTransaction();
try {
    foreach ($reenganchar as $f) {
        $destino = (int) $f['xml_correcto'];
        $totalDe->execute([$destino]);
        $total = (float) $totalDe->fetchColumn();
        $diferencia = round((float) $f['monto'] - $total, 2);
        $estado = abs($diferencia) <= 1.0 ? 'respaldada' : 'con_diferencia';
        $reengancha->execute([
            $destino,
            $estado,
            $estado === 'con_diferencia' ? $diferencia : null,
            (int) $f['id'],
        ]);
    }
    foreach ($soltar as $f) {
        $suelta->execute([(int) $f['id']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "No se escribió nada: " . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "\nHecho: %d reenganchadas, %d sin respaldo.\n",
    count($reenganchar),
    count($soltar)
);
echo "Los comprobantes sueltos siguen en la base; la verificación del pago o una\n";
echo "persona pueden engancharlos donde corresponda.\n";
