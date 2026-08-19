<?php
/**
 * Engancha los comprobantes ya importados con su factura del ERP.
 *
 * Desde que el correo dejó de pedir semana, cada XML que entra busca solo su
 * factura en el ERP (FacturaErp::engancharXml, llamado por XmlDocumentImporter).
 * Pero los comprobantes que se importaron ANTES de que eso existiera solo se
 * engancharon si alguien los verificó dentro de un pago semanal: los demás
 * quedaron sueltos, con su factura marcada 'sin_respaldo' aunque el XML esté
 * guardado y archivado desde hace semanas.
 *
 * Eso no se notaba mientras el seguimiento solo miraba las facturas metidas en
 * un pago. Ahora que la cola mira todas las facturas con saldo pendiente, esas
 * facturas aparecen como "falta el XML" sin que falte nada.
 *
 * Este script es de una sola vez: recorre los comprobantes sin enganchar y les
 * busca su factura con las mismas reglas del enganche automático —igualdad de
 * consecutivo, desempate por monto, sin pisar los vínculos hechos a mano—.
 *
 * Uso:
 *   php cli/enganchar_facturas_erp.php            # simulación (no escribe)
 *   php cli/enganchar_facturas_erp.php --aplicar  # escribe
 */

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/core/Esquema.php';
require_once __DIR__ . '/../app/core/AlcanceSociedad.php';
require_once __DIR__ . '/../app/helpers/NumeroFactura.php';
require_once __DIR__ . '/../app/models/FacturaErp.php';
require_once __DIR__ . '/../app/models/Sociedad.php';

$aplicar = in_array('--aplicar', $argv, true);

class ConsultaEnganche extends Model
{
    protected $table = 'facturas_xml';
    public function __construct() {}
    public function q($sql, $p = []) { return $this->fetchAll($sql, $p); }
}

$db = new ConsultaEnganche();

/**
 * Los comprobantes que valen la pena mirar: facturas electrónicas que no
 * respaldan ninguna fila del ERP y cuyo consecutivo sí existe allá, en una
 * factura todavía sin XML.
 *
 * El prefiltro es una sola consulta para no llamar al enganche cinco mil veces
 * y que cuatro mil no encuentren nada: con la base en el servidor, cada llamada
 * son dos viajes de ida y vuelta.
 */
$candidatos = $db->q(
    "SELECT x.id, x.consecutivo_completo, x.numero_factura_asistente, x.total,
            x.sociedad_id, e.id AS erp_id, e.proveedor_nombre, e.monto AS erp_monto
       FROM facturas_xml x
       JOIN facturas_erp e
         ON e.documento = x.consecutivo_completo
        AND e.factura_xml_id IS NULL
      WHERE (x.tipo_documento IS NULL OR x.tipo_documento = 'FE')
        AND x.consecutivo_completo IS NOT NULL
        AND x.consecutivo_completo <> ''
        AND NOT EXISTS (SELECT 1 FROM facturas_erp o WHERE o.factura_xml_id = x.id)
      GROUP BY x.id
      ORDER BY x.id ASC"
) ?: [];

printf("Comprobantes sin enganchar que calzan con una factura del ERP: %d\n\n", count($candidatos));

if (!$candidatos) {
    echo "No hay nada que enganchar.\n";
    exit(0);
}

if (!$aplicar) {
    echo "SIMULACIÓN: no se escribe nada. Muestra de lo que se engancharía:\n\n";
    foreach (array_slice($candidatos, 0, 15) as $c) {
        printf("   %-22s  %-32s  XML %12s  ERP %12s\n",
            $c['consecutivo_completo'],
            mb_substr((string) $c['proveedor_nombre'], 0, 31),
            number_format((float) $c['total'], 2),
            number_format((float) $c['erp_monto'], 2));
    }
    if (count($candidatos) > 15) {
        printf("   … y %d más.\n", count($candidatos) - 15);
    }
    echo "\nVolvé a correrlo con --aplicar para engancharlos.\n";
    exit(0);
}

// Un modelo por sociedad: el enganche busca candidatas dentro del alcance de
// la empresa, y un mismo consecutivo puede existir en dos de ellas.
$modelos = [];
$conteo = ['enganchada' => 0, 'ya_tomada' => 0, 'ambigua' => 0, 'sin_erp' => 0, 'error' => 0];
$hecho = 0;

foreach ($candidatos as $c) {
    $sociedadId = (int) ($c['sociedad_id'] ?? 0);
    if (!isset($modelos[$sociedadId])) {
        $modelos[$sociedadId] = (new FacturaErp())->setSociedad($sociedadId);
    }

    try {
        $r = $modelos[$sociedadId]->engancharXml(
            (int) $c['id'],
            (string) $c['consecutivo_completo'],
            (string) $c['numero_factura_asistente'],
            (float) $c['total']
        );
        $estado = (string) ($r['estado'] ?? 'sin_erp');
        $conteo[$estado] = ($conteo[$estado] ?? 0) + 1;
    } catch (Throwable $e) {
        $conteo['error']++;
        fwrite(STDERR, "  XML {$c['id']}: {$e->getMessage()}\n");
    }

    if (++$hecho % 100 === 0) {
        printf("  %d de %d…\n", $hecho, count($candidatos));
    }
}

echo "\nResultado:\n";
printf("  Enganchadas:            %d\n", $conteo['enganchada']);
printf("  Ya tenían otro XML:     %d\n", $conteo['ya_tomada']);
printf("  Ambiguas (varias):      %d\n", $conteo['ambigua']);
printf("  Sin factura en el ERP:  %d\n", $conteo['sin_erp']);
if ($conteo['error']) {
    printf("  Con error:              %d\n", $conteo['error']);
}
echo "\nLas ambiguas y las que quedaron sin enganchar siguen en la cola de\n";
echo "seguimiento como \"falta el XML\": se vinculan a mano desde el pago.\n";
