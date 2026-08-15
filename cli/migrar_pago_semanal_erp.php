<?php
/**
 * Migra los pagos semanales de `porpagar_facturas` a `facturas_erp`.
 *
 * El pago semanal dejó de guardar una copia de cada renglón del archivo del
 * ERP. Ahora la línea del pago ES la factura del ERP, marcada con
 * `porpagar_listado_id` y `semana_id`. Este script traslada lo que ya estaba:
 * resuelve cada línea vieja contra Facturas ERP —con el mismo resolutor que usa
 * la carga— y traslada además el emparejamiento con el XML que esa línea ya
 * tuviera, para no perder el trabajo de emparejar semanas enteras.
 *
 * No borra nada. Las líneas viejas quedan intactas; retirarlas es un paso
 * aparte y explícito (--retirar), que solo las renombra a
 * `porpagar_facturas_respaldo`.
 *
 * Uso:
 *   php cli/migrar_pago_semanal_erp.php            # simulación (no escribe)
 *   php cli/migrar_pago_semanal_erp.php --aplicar  # escribe
 *   php cli/migrar_pago_semanal_erp.php --retirar  # renombra la tabla vieja
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Esquema.php';
require_once __DIR__ . '/../app/core/AlcanceSociedad.php';
require_once __DIR__ . '/../app/helpers/NumeroFactura.php';
require_once __DIR__ . '/../app/helpers/FacturaMatcher.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';
require_once __DIR__ . '/../app/helpers/PagoSemanalResolutor.php';

$aplicar = in_array('--aplicar', $argv, true);
$retirar = in_array('--retirar', $argv, true);

class MigracionPago extends Model
{
    protected $table = 'porpagar_listados';
    public function __construct() {}
    public function q($sql, $p = []) { return $this->fetchAll($sql, $p); }
    public function uno($sql, $p = []) { return $this->fetchOne($sql, $p); }
    public function e($sql, $p = []) { return $this->execute($sql, $p); }
}

$db = new MigracionPago();

if ($retirar) {
    $existe = $db->uno("SHOW TABLES LIKE 'porpagar_facturas'");
    if (!$existe) {
        echo "porpagar_facturas ya no existe: nada que retirar.\n";
        exit(0);
    }
    if (!$aplicar) {
        echo "SIMULACIÓN: se renombraría porpagar_facturas -> porpagar_facturas_respaldo\n";
        echo "Volvé a correrlo con --retirar --aplicar para hacerlo.\n";
        exit(0);
    }
    $db->e('RENAME TABLE porpagar_facturas TO porpagar_facturas_respaldo');
    echo "porpagar_facturas renombrada a porpagar_facturas_respaldo.\n";
    echo "Cuando estés seguro: DROP TABLE porpagar_facturas_respaldo;\n";
    exit(0);
}

if (!$db->uno("SHOW TABLES LIKE 'porpagar_facturas'")) {
    echo "No hay porpagar_facturas: la migración ya se hizo.\n";
    exit(0);
}

echo $aplicar ? "APLICANDO\n\n" : "SIMULACIÓN (no se escribe nada; usá --aplicar)\n\n";

// El índice del ERP se arma una vez: son miles de filas y el resolutor lo
// recorre por listado.
$erpFilas = $db->q(
    "SELECT id, documento, numero_corto, proveedor_nombre, fecha_emision, monto, saldo,
            semana_id, porpagar_listado_id
       FROM facturas_erp WHERE tipo IN ('F','FE','FACT')"
);
echo 'Facturas ERP disponibles: ' . count($erpFilas) . "\n\n";

$totales = ['listados' => 0, 'resueltas' => 0, 'sin_erp' => 0, 'con_xml' => 0];

foreach ($db->q('SELECT id, nombre, semana_id, estado FROM porpagar_listados ORDER BY id') as $listado) {
    $listadoId = (int) $listado['id'];
    $semanaId = (int) ($listado['semana_id'] ?? 0);

    $lineas = $db->q(
        'SELECT id, numero, proveedor_texto, total, factura_xml_id, estado, diferencia, match_manual
           FROM porpagar_facturas WHERE listado_id = ?',
        [$listadoId]
    );
    if (!$lineas) {
        continue;
    }
    $totales['listados']++;

    $filas = array_map(function ($l) {
        return ['numero' => $l['numero'], 'proveedor' => $l['proveedor_texto'], 'saldo' => $l['total']];
    }, $lineas);

    $resolucion = PagoSemanalResolutor::resolver($filas, $erpFilas, $listadoId);
    $resumen = $resolucion['resumen'];

    printf(
        "#%-4s %-28s sem=%-4s %-8s líneas=%-5s resueltas=%-5s sin ERP=%-4s ambiguas=%s\n",
        $listadoId,
        mb_substr((string) $listado['nombre'], 0, 28),
        $semanaId ?: '-',
        $listado['estado'],
        count($lineas),
        $resumen['resuelta'],
        $resumen['ausente'],
        $resumen['ambigua']
    );

    $totales['resueltas'] += $resumen['resuelta'];
    $totales['sin_erp'] += $resumen['ausente'] + $resumen['ambigua'];

    if (!$aplicar || $semanaId <= 0) {
        continue;
    }

    // Se recorren juntas porque el resolutor devuelve las filas en el mismo
    // orden en que entraron: la enésima resuelta corresponde a la enésima línea.
    foreach ($resolucion['filas'] as $indice => $fila) {
        if ($fila['estado'] !== 'resuelta' || empty($fila['factura_erp_id'])) {
            continue;
        }
        $linea = $lineas[$indice];
        $erpId = (int) $fila['factura_erp_id'];

        $db->e(
            "UPDATE facturas_erp
                SET estado = 'asignada_semana', semana_id = ?, porpagar_listado_id = ?,
                    asignada_semana_en = COALESCE(asignada_semana_en, NOW()),
                    saldo_pago = COALESCE(saldo_pago, ?)
              WHERE id = ?",
            [$semanaId, $listadoId, (float) $linea['total'], $erpId]
        );

        // El emparejamiento con el XML ya costó trabajo humano en varias
        // semanas: se traslada tal cual, marca manual incluida.
        if (!empty($linea['factura_xml_id'])) {
            $db->e(
                "UPDATE facturas_erp
                    SET factura_xml_id = ?, estado_respaldo = ?, diferencia = ?, match_manual = ?
                  WHERE id = ?",
                [
                    (int) $linea['factura_xml_id'],
                    (string) ($linea['estado'] ?: 'sin_respaldo'),
                    $linea['diferencia'],
                    (int) $linea['match_manual'],
                    $erpId,
                ]
            );
            $totales['con_xml']++;
        }
    }

}

// Los totales se recalculan AL FINAL, no dentro del bucle. Una factura del ERP
// solo puede estar en un pago, así que cuando dos listados viejos se pisan —los
// hay: la misma semana cargada dos veces— el último se queda con las suyas y le
// baja el contador al anterior. Contando dentro del bucle, el del primero
// quedaba con el número de antes del robo.
if ($aplicar) {
    $db->e(
        'UPDATE porpagar_listados l
            SET l.total_lineas = (SELECT COUNT(*) FROM facturas_erp e WHERE e.porpagar_listado_id = l.id)'
    );
}

echo "\n";
echo 'Listados: ' . $totales['listados'] . "\n";
echo 'Facturas resueltas contra el ERP: ' . $totales['resueltas'] . "\n";
echo 'Sin factura en el ERP (quedan fuera): ' . $totales['sin_erp'] . "\n";
echo 'Emparejamientos con XML trasladados: ' . $totales['con_xml'] . "\n";

if (!$aplicar) {
    echo "\nNada se escribió. Corré con --aplicar para hacerlo.\n";
} else {
    echo "\nHecho. Revisá el módulo y, cuando estés conforme:\n";
    echo "  php cli/migrar_pago_semanal_erp.php --retirar --aplicar\n";
}
