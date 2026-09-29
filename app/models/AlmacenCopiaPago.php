<?php
/**
 * Qué copias de pago ya se hicieron en un almacén remoto, y de qué.
 *
 * En disco, reponer la carpeta del pago en cada vuelta cuesta nada: mirar si
 * la copia está es un stat(). Contra SharePoint son dos llamadas por archivo,
 * cada cinco minutos, por cada documento de cada pago: miles de llamadas
 * para confirmar lo que ya se sabe. Aquí queda anotado lo copiado con una
 * firma de todo lo que decide la copia —la carpeta, las rutas, los cTag del
 * original—; mientras la firma no cambie, no se sale a la red.
 *
 * El precio, dicho: si alguien borra a mano una copia de la carpeta del pago
 * en SharePoint, no se repone sola. Se repone al cambiar el pago, o borrando
 * esta fila.
 */
class AlmacenCopiaPago extends Model
{
    protected $table = 'almacen_copias_pago';

    public function __construct()
    {
        Esquema::unaVez(static::class, function () { $this->ensureTable(); });
    }

    private function ensureTable()
    {
        try {
            $this->execute("CREATE TABLE IF NOT EXISTS {$this->table} (
                `factura_id` INT UNSIGNED NOT NULL,
                `firma` CHAR(40) NOT NULL,
                `ruta_xml` VARCHAR(700) NULL DEFAULT NULL,
                `ruta_pdf` VARCHAR(700) NULL DEFAULT NULL,
                `copiado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`factura_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            // Sin permisos de DDL: la crea database/migration_almacen_graph.sql.
        }
    }

    /** @return array factura_id => firma */
    public function firmas(array $facturaIds)
    {
        $facturaIds = array_values(array_unique(array_filter(array_map('intval', $facturaIds))));
        $mapa = [];
        foreach (array_chunk($facturaIds, 500) as $grupo) {
            foreach ($this->fetchAll(
                "SELECT factura_id, firma FROM {$this->table}
                  WHERE factura_id IN (" . implode(',', array_fill(0, count($grupo), '?')) . ')',
                $grupo
            ) ?: [] as $fila) {
                $mapa[(int) $fila['factura_id']] = (string) $fila['firma'];
            }
        }
        return $mapa;
    }

    /**
     * Da por no hechas estas copias: la próxima vuelta del reconciliador las
     * vuelve a subir. Es cómo se repone una copia que alguien borró de la
     * carpeta del pago (ver ControlCarpetaPago).
     */
    public function olvidar(array $facturaIds)
    {
        $facturaIds = array_values(array_unique(array_filter(array_map('intval', $facturaIds))));
        foreach (array_chunk($facturaIds, 500) as $grupo) {
            $this->execute(
                "DELETE FROM {$this->table} WHERE factura_id IN (" . implode(',', array_fill(0, count($grupo), '?')) . ')',
                $grupo
            );
        }
    }

    public function anotar($facturaId, $firma, $rutaXml, $rutaPdf)
    {
        return $this->execute(
            "INSERT INTO {$this->table} (factura_id, firma, ruta_xml, ruta_pdf) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE firma = VALUES(firma), ruta_xml = VALUES(ruta_xml),
                 ruta_pdf = VALUES(ruta_pdf), copiado_en = NOW()",
            [(int) $facturaId, (string) $firma, RutaDocumento::relativa($rutaXml), RutaDocumento::relativa($rutaPdf)]
        );
    }
}
