<?php
/**
 * Importa un reporte PDF del ERP (Boleta Local o Devolución a Proveedor):
 * parsea y cuadra con ReporteErpParser, deduplica por hash del PDF, archiva
 * el original en storage/devoluciones/ y corre la verificación contra NC.
 * Un PDF que no cuadra se rechaza completo — nunca se importa a medias.
 */

require_once __DIR__ . '/ReporteErpParser.php';
require_once __DIR__ . '/DevolucionVerificador.php';
require_once __DIR__ . '/../models/Devolucion.php';

class DevolucionImporter
{
    private $modelo;

    public function __construct(Devolucion $modelo = null)
    {
        $this->modelo = $modelo ?: new Devolucion();
    }

    /**
     * Devuelve ['estado' => importada|duplicada|rechazada, ...detalle].
     * $contexto: sociedad_id, nombre_original.
     */
    public function importar($rutaPdf, array $contexto = [])
    {
        $nombreOriginal = (string) ($contexto['nombre_original'] ?? basename($rutaPdf));

        $parsed = ReporteErpParser::parseArchivo($rutaPdf);
        if (empty($parsed['ok'])) {
            return [
                'estado' => 'rechazada',
                'archivo' => $nombreOriginal,
                'errores' => $parsed['errores'] ?? ['El PDF no pudo validarse.'],
            ];
        }

        $hash = hash_file('sha256', $rutaPdf);
        $existente = $this->modelo->buscarPorHash($hash);
        if ($existente !== null) {
            return [
                'estado' => 'duplicada',
                'archivo' => $nombreOriginal,
                'id' => (int) $existente['id'],
                'numero' => (string) $existente['numero'],
            ];
        }

        $archivado = $this->archivarPdf($rutaPdf, $parsed, $hash);
        $datos = $this->mapear($parsed, $contexto, $nombreOriginal, $archivado, $hash);

        try {
            $this->modelo->begin();
            $id = (int) $this->modelo->crear($datos);
            foreach ($this->mapearLineas($parsed) as $linea) {
                $this->modelo->crearLinea($id, $linea);
            }
            $this->modelo->commit();
        } catch (Throwable $e) {
            $this->modelo->rollback();
            @unlink($archivado['ruta']);
            throw $e;
        }

        // La verificación corre aparte: si falla, la importación se conserva.
        $estadoVerificacion = 'pendiente';
        $errorVerificacion = null;
        try {
            $estadoVerificacion = DevolucionVerificador::verificar($id, $this->modelo, $contexto);
        } catch (Throwable $e) {
            $errorVerificacion = $e->getMessage();
        }

        return [
            'estado' => 'importada',
            'archivo' => $nombreOriginal,
            'id' => $id,
            'tipo' => (string) $parsed['tipo'],
            'numero' => (string) $datos['numero'],
            'verificacion' => $estadoVerificacion,
            'error_verificacion' => $errorVerificacion,
            'advertencias' => $parsed['advertencias'] ?? [],
        ];
    }

    private function archivarPdf($rutaPdf, array $parsed, $hash)
    {
        $anio = date('Y');
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'devoluciones' . DIRECTORY_SEPARATOR . $anio;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear el directorio de archivo de devoluciones.');
        }

        $numero = (string) ($parsed['tipo'] === 'boleta_local'
            ? ($parsed['no_boleta'] ?? '0')
            : ($parsed['devolucion_numero'] ?? '0'));
        $nombre = $parsed['tipo'] . '_' . preg_replace('/\D+/', '', $numero)
            . '_' . substr($hash, 0, 8) . '.pdf';
        $destino = $dir . DIRECTORY_SEPARATOR . $nombre;

        if (!copy($rutaPdf, $destino)) {
            throw new RuntimeException('No se pudo archivar el PDF de la devolución.');
        }
        return ['archivo' => $nombre, 'ruta' => $destino];
    }

    private function mapear(array $p, array $contexto, $nombreOriginal, array $archivado, $hash)
    {
        $sociedadId = !empty($contexto['sociedad_id']) ? (int) $contexto['sociedad_id'] : null;
        $advertencias = !empty($p['advertencias'])
            ? json_encode($p['advertencias'], JSON_UNESCAPED_UNICODE)
            : null;

        if ($p['tipo'] === 'boleta_local') {
            return [
                'sociedad_id' => $sociedadId,
                'tipo' => 'boleta_local',
                'numero' => (string) $p['no_boleta'],
                'sucursal' => $p['sucursal'] ?: null,
                'bodega' => $p['bodega'] ?: null,
                'numero_factura' => (string) $p['numero_factura'],
                'factura_xml_id' => null,
                'proveedor_codigo_erp' => null,
                'proveedor_nombre_erp' => $p['proveedor'] ?: null,
                'proveedor_id' => null,
                'fecha' => $p['fecha'],
                'estado_erp' => null,
                'usuario_erp' => null,
                'observaciones' => null,
                'cantidad_total' => (float) ($p['total_impreso']['cantidad'] ?? 0),
                'total' => null,
                'nc_esperada_cantidad' => (float) $p['nc_esperada_cantidad'] > 0 ? (float) $p['nc_esperada_cantidad'] : null,
                'nc_esperada_costo' => (float) $p['nc_esperada_costo'] > 0 ? (float) $p['nc_esperada_costo'] : null,
                'archivo_pdf' => $nombreOriginal,
                'ruta_pdf' => $archivado['ruta'],
                'hash_pdf' => $hash,
                'advertencias' => $advertencias,
            ];
        }

        return [
            'sociedad_id' => $sociedadId,
            'tipo' => 'devolucion_proveedor',
            'numero' => (string) $p['devolucion_numero'],
            'sucursal' => $p['sucursal'] ?: null,
            'bodega' => $p['bodega'] ?: null,
            'numero_factura' => null,
            'factura_xml_id' => null,
            'proveedor_codigo_erp' => $p['proveedor_codigo'] ?: null,
            'proveedor_nombre_erp' => $p['proveedor_nombre'] ?: null,
            'proveedor_id' => null,
            'fecha' => $p['fecha'],
            'estado_erp' => $p['estado'] ?: null,
            'usuario_erp' => $p['usuario'] ?: null,
            'observaciones' => $p['observaciones'] ?: null,
            'cantidad_total' => (float) $p['cantidad_total'],
            'total' => (float) $p['total'],
            'nc_esperada_cantidad' => null,
            'nc_esperada_costo' => null,
            'archivo_pdf' => $nombreOriginal,
            'ruta_pdf' => $archivado['ruta'],
            'hash_pdf' => $hash,
            'advertencias' => $advertencias,
        ];
    }

    private function mapearLineas(array $p)
    {
        $lineas = [];
        if ($p['tipo'] === 'boleta_local') {
            foreach ($p['secciones'] as $seccion => $filas) {
                foreach ($filas as $f) {
                    $lineas[] = [
                        'seccion' => $seccion,
                        'codigo' => $f['codigo'],
                        'nombre' => $f['nombre'],
                        'cantidad' => $f['cantidad'],
                        'costo' => $f['costo_neto'],
                        'impuesto' => $f['impuesto'],
                        'total' => $f['total'],
                        'dif_costo' => $f['dif_costo'],
                    ];
                }
            }
            return $lineas;
        }

        foreach ($p['lineas'] as $f) {
            $lineas[] = [
                'seccion' => null,
                'codigo' => $f['codigo'],
                'nombre' => $f['nombre'],
                'cantidad' => $f['cantidad'],
                'costo' => $f['costo_unitario'],
                'impuesto' => $f['impuesto_unitario'],
                'total' => $f['total'],
                'dif_costo' => null,
            ];
        }
        return $lineas;
    }
}
