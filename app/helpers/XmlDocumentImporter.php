<?php
require_once __DIR__ . '/XmlParser.php';
require_once __DIR__ . '/DocumentoArchivo.php';
require_once __DIR__ . '/../models/Factura.php';
require_once __DIR__ . '/../models/FacturaXmlDetalle.php';
require_once __DIR__ . '/../models/Proveedor.php';
require_once __DIR__ . '/../models/Sociedad.php';

/** Importa un FE/NC, archiva sus archivos y guarda solo metadatos en BD. */
class XmlDocumentImporter
{
    /**
     * Tipos que nunca se guardan, decida lo que decida quien llame. Hoy solo
     * la nota de débito. Los mensajes de Hacienda no están en esta lista
     * porque ni siquiera llegan hasta aquí: XmlInvoiceParser los rechaza, que
     * es donde corresponde —no son un tipo de comprobante, no son un
     * comprobante—.
     */
    public const NUNCA_SE_GUARDAN = ['ND'];

    private $facturas;
    private $proveedores;
    private $archivo;

    public function __construct($carpetaRaiz = '')
    {
        $this->facturas = new Factura();
        $this->proveedores = new Proveedor();
        $this->archivo = new DocumentoArchivo($carpetaRaiz);
    }

    public function importar($xmlPath, $pdfPath = null, array $contexto = [])
    {
        $doc = XmlInvoiceParser::parseCfdiFromFile($xmlPath);
        $tipo = strtoupper(trim((string) ($doc['tipo_documento'] ?? 'FE')));

        // Los únicos documentos que se guardan son factura y nota de crédito.
        // Las notas de débito se descartan siempre, y esto va por encima de
        // 'tipos_permitidos' a propósito: es una regla del negocio, no una
        // opción de cada módulo, y un módulo nuevo que pasara la lista
        // equivocada las volvería a meter sin que nadie lo note.
        if (in_array($tipo, self::NUNCA_SE_GUARDAN, true)) {
            throw new RuntimeException(
                'Las notas de débito no se guardan en el sistema (' . $tipo . ').'
            );
        }

        $permitidos = array_diff(
            array_map('strtoupper', $contexto['tipos_permitidos'] ?? ['FE', 'NC']),
            self::NUNCA_SE_GUARDAN
        );
        if (!in_array($tipo, $permitidos, true)) {
            throw new RuntimeException('Tipo de documento no permitido en este módulo: ' . $tipo . '.');
        }

        if (!empty($contexto['validar_receptor'])) {
            $cedula = preg_replace('/\D+/', '', (string) ($contexto['cedula_receptor'] ?? ''));
            $receptor = preg_replace('/\D+/', '', (string) ($doc['receptor_id'] ?? ''));
            if ($cedula !== '' && $receptor !== '' && $cedula !== $receptor) {
                throw new RuntimeException('El receptor del XML (' . $receptor . ') no corresponde a la sociedad activa.');
            }
        }

        $proveedorId = (int) $this->proveedores->obtenerOCrear(
            (string) ($doc['rfc_emisor'] ?? ''),
            (string) ($doc['razon_social_emisor'] ?? '')
        );
        $hash = (string) ($doc['hash_documento'] ?? ($doc['hash_xml'] ?? ''));
        $existente = $hash !== '' ? $this->facturas->findByHashRecord($hash) : null;
        if (!$existente && !empty($doc['consecutivo_completo'])) {
            $existente = $this->facturas->findByConsecutivoRecord(
                (string) $doc['consecutivo_completo'],
                $proveedorId,
                (string) ($doc['fecha_emision'] ?? '')
            );
        }

        if ($existente) {
            return $this->completarExistente($existente, $xmlPath, $pdfPath, $doc, $contexto);
        }

        $archivado = $this->archivo->archivar(
            $xmlPath,
            is_string($pdfPath) ? $pdfPath : null,
            $doc,
            (string) ($doc['razon_social_emisor'] ?? '')
        );

        $metadata = is_array($doc['metadata'] ?? null) ? $doc['metadata'] : [];
        $metadata['archivo_local'] = true;
        $metadata['origen'] = (string) ($contexto['origen'] ?? 'carga_directa');

        try {
            $this->facturas->begin();
            $id = (int) $this->facturas->crear([
                'importacion_id' => !empty($contexto['importacion_id']) ? (int) $contexto['importacion_id'] : null,
                'semana_id' => $tipo === 'FE' && !empty($contexto['semana_id']) ? (int) $contexto['semana_id'] : null,
                // Quien importa manda: el worker de un lote de correo trabaja
                // con la sociedad DEL LOTE, que puede no ser la seleccionada
                // en pantalla si alguien cambió de empresa mientras corría.
                'sociedad_id' => !empty($contexto['sociedad_id']) ? (int) $contexto['sociedad_id'] : 0,
                'consecutivo_completo' => $doc['consecutivo_completo'],
                'clave' => $doc['clave'] ?? null,
                'tipo_documento' => $tipo,
                'receptor_id' => $doc['receptor_id'] ?? null,
                'numero_factura_asistente' => $doc['numero_factura_asistente'],
                'proveedor_id' => $proveedorId,
                'fecha_emision' => $doc['fecha_emision'],
                'subtotal' => $doc['subtotal'],
                'iva' => $doc['iva'],
                'total' => $doc['total'],
                'moneda' => $doc['moneda'],
                'tipo_comprobante' => $doc['tipo_comprobante'] ?? null,
                'archivo_xml' => $archivado['archivo_xml'],
                'ruta_xml' => $archivado['ruta_xml'],
                'ruta_pdf' => $archivado['ruta_pdf'],
                'archivo_pdf' => $archivado['archivo_pdf'],
                'hash_pdf' => $archivado['hash_pdf'],
                'estado_pdf' => $archivado['estado_pdf'],
                'correo_cuenta_id' => $contexto['correo_cuenta_id'] ?? null,
                'correo_carpeta' => $contexto['correo_carpeta'] ?? null,
                'correo_uidvalidity' => $contexto['correo_uidvalidity'] ?? null,
                'correo_uid' => $contexto['correo_uid'] ?? null,
                'fecha_correo' => $contexto['fecha_correo'] ?? null,
                'archivado_en' => $archivado['archivado_en'],
                'hash_xml' => $archivado['hash_xml'],
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
            $this->facturas->commit();
        } catch (Throwable $e) {
            $this->facturas->rollback();
            DocumentoArchivo::eliminarCreados($archivado);
            throw $e;
        }

        // Aquí NO se reorganiza el árbol: el documento se queda donde lo dejó
        // DocumentoArchivo al archivarlo. Mover lo ya archivado es una orden
        // explícita de la persona (Correo → Ordenar el archivo).
        if ($tipo === 'NC') {
            $this->extraerDetalle((int) $id, (string) $archivado['ruta_xml']);
        }


        return [
            'estado' => 'importado',
            'id' => $id,
            'tipo_documento' => $tipo,
            'pdf_pendiente' => $archivado['estado_pdf'] !== 'disponible',
            'documento' => $doc,
            'archivado' => $archivado,
        ];
    }

    private function completarExistente(array $existente, $xmlPath, $pdfPath, array $doc, array $contexto)
    {
        $cambios = [];
        $creados = [];
        $tipo = strtoupper((string) ($existente['tipo_documento'] ?? ($doc['tipo_documento'] ?? 'FE')));
        $semanaAnterior = !empty($existente['semana_id']) ? (int) $existente['semana_id'] : null;
        $semanaDestino = $tipo === 'FE' && !empty($contexto['semana_id'])
            ? (int) $contexto['semana_id']
            : null;
        $mismaSemana = $semanaDestino !== null && $semanaAnterior === $semanaDestino;
        $moverSemana = $semanaDestino !== null && $semanaAnterior !== $semanaDestino;

        // Repara rutas históricas obsoletas si el XML vuelve a llegar.
        if (empty($existente['ruta_xml']) || !is_file((string) $existente['ruta_xml'])) {
            $creados = $this->archivo->archivar(
                $xmlPath,
                is_string($pdfPath) ? $pdfPath : null,
                $doc,
                (string) ($existente['proveedor_nombre'] ?? ($doc['razon_social_emisor'] ?? ''))
            );
            $cambios = $creados;
        } elseif (is_string($pdfPath) && $pdfPath !== '' && is_file($pdfPath)
            && (empty($existente['ruta_pdf']) || !is_file((string) $existente['ruta_pdf']))) {
            $cambios = $this->archivo->archivarPdfPara($existente, $pdfPath);
            $creados = $cambios;
        }

        $cambios['correo_cuenta_id'] = $contexto['correo_cuenta_id'] ?? null;
        $cambios['correo_carpeta'] = $contexto['correo_carpeta'] ?? null;
        $cambios['correo_uidvalidity'] = $contexto['correo_uidvalidity'] ?? null;
        $cambios['correo_uid'] = $contexto['correo_uid'] ?? null;
        $cambios['fecha_correo'] = $contexto['fecha_correo'] ?? null;

        try {
            $this->facturas->begin();
            $this->facturas->actualizarArchivos((int) $existente['id'], $cambios);
            if ($moverSemana) {
                $this->facturas->asignarSemana((int) $existente['id'], $semanaDestino);
            }
            $this->facturas->commit();
        } catch (Throwable $e) {
            $this->facturas->rollback();
            DocumentoArchivo::eliminarCreados($creados);
            if (!empty($creados['pdf_creado']) && !empty($creados['ruta_pdf']) && is_file($creados['ruta_pdf'])) {
                @unlink($creados['ruta_pdf']);
            }
            throw $e;
        }

        if ($tipo === 'NC') {
            $ruta = (string) ($cambios['ruta_xml'] ?? ($existente['ruta_xml'] ?? ''));
            $this->extraerDetalle((int) $existente['id'], $ruta);
        }


        $pdfDisponible = !empty($cambios['ruta_pdf']) || (!empty($existente['ruta_pdf']) && is_file((string) $existente['ruta_pdf']));
        $estado = $moverSemana
            ? 'movida_semana'
            : ($mismaSemana ? 'duplicado_semana' : (!empty($cambios['ruta_pdf']) ? 'pdf_completado' : 'duplicado'));
        return [
            'estado' => $estado,
            'id' => (int) $existente['id'],
            'tipo_documento' => $tipo,
            'pdf_pendiente' => !$pdfDisponible,
            'semana_anterior' => $semanaAnterior,
            'semana_id' => $moverSemana ? $semanaDestino : $semanaAnterior,
            'movida_semana' => $moverSemana,
            'duplicado_misma_semana' => $mismaSemana,
            'documento' => $doc,
            'archivado' => $cambios,
        ];
    }

    private function extraerDetalle($id, $rutaXml)
    {
        if ($rutaXml === '' || !is_file($rutaXml)) {
            return;
        }
        try {
            (new FacturaXmlDetalle())->extraerYGuardar((int) $id, $rutaXml);
        } catch (Throwable $e) {
            // Best effort: el CLI de backfill reintenta los pendientes.
        }
    }

}
