<?php
/**
 * Vuelve a bajar del correo el XML y el PDF de un documento que se quedó sin
 * archivo, y los deja exactamente donde su ruta dice.
 *
 * Para qué: los comprobantes viven en una carpeta compartida, y una carpeta
 * compartida es una carpeta que alguien puede borrar. Cuando eso pasa, el
 * documento sigue completo en la base —proveedor, montos, semana de pago— pero
 * su respaldo electrónico deja de existir, y el respaldo es justo lo que hay
 * que entregar. Hasta ahora la única salida era la papelera de SharePoint o
 * pedírselo otra vez al proveedor.
 *
 * Lo que hace posible reponerlo es que la fila guarda de dónde salió: la
 * cuenta, la carpeta y el UID del mensaje. El mensaje sigue en el buzón aunque
 * el archivo ya no esté en el disco.
 *
 * Y lo que hace que reponerlo sea confiable es el hash. Se guardó el SHA-256
 * del XML y del PDF cuando se archivaron, así que un adjunto solo se acepta si
 * su contenido da exactamente ese hash: no se repone "un" documento parecido,
 * se repone el mismo. Un adjunto que no coincide se descarta y el documento se
 * informa como no recuperado, nunca se escribe a medias.
 *
 * No inventa ubicaciones: si la fila conserva su ruta, el archivo vuelve ahí.
 * Solo cuando la fila no tiene ruta —nunca se archivó— se le da una nueva, la
 * del mes que le corresponde, igual que cualquier documento recién importado.
 */

require_once __DIR__ . '/MailFetcher.php';
require_once __DIR__ . '/DocumentoArchivo.php';
require_once __DIR__ . '/RutaDocumento.php';
require_once __DIR__ . '/../models/Factura.php';
require_once __DIR__ . '/../models/CorreoCuenta.php';

class RecuperadorDocumentos
{
    private $facturas;
    private $cuentas;
    private $raiz;
    private $archivo = null;
    private $abrirBuzon;

    /**
     * @param callable|null $abrirBuzon function(array $config): MailFetcher —
     *        se inyecta para poder probar todo esto sin un servidor de correo.
     */
    public function __construct($facturas = null, $cuentas = null, $raiz = '', callable $abrirBuzon = null)
    {
        $this->facturas = $facturas ?: new Factura();
        $this->cuentas = $cuentas ?: new CorreoCuenta();
        $this->raiz = (string) $raiz;
        $this->abrirBuzon = $abrirBuzon ?: static function (array $config) {
            return new MailFetcher($config);
        };
    }

    public static function resumenBase()
    {
        return [
            'revisados' => 0,
            'recuperados' => 0,
            'ya_estaban' => 0,
            'sin_buzon' => 0,
            'sin_mensaje' => 0,
            'sin_coincidencia' => 0,
            'errores' => 0,
            'detalle' => [],
        ];
    }

    /**
     * Repone los documentos indicados. Cada uno es una fila de
     * Factura::getRecuperablesDelCorreo().
     */
    public function recuperar(array $documentos)
    {
        $resumen = self::resumenBase();
        if (!$documentos) {
            return $resumen;
        }
        if (!MailFetcher::extensionDisponible()) {
            throw new RuntimeException('La extensión imap de PHP no está activa en este servidor.');
        }

        // De a un buzón por vez, y dentro de él por carpeta: abrir una carpeta
        // IMAP cuesta un viaje, y reponer una semana entera son cientos de
        // documentos que salieron casi todos del mismo sitio.
        $porCuenta = [];
        foreach ($documentos as $documento) {
            $porCuenta[(int) ($documento['correo_cuenta_id'] ?? 0)][] = $documento;
        }

        foreach ($porCuenta as $cuentaId => $suyos) {
            $config = $cuentaId > 0 ? $this->cuentas->configPara($cuentaId) : null;
            if (!is_array($config) || !MailFetcher::configurado($config)) {
                foreach ($suyos as $documento) {
                    $resumen['revisados']++;
                    $resumen['sin_buzon']++;
                    $this->anotar($resumen, $documento, 'sin_buzon',
                        'El buzón del que salió ya no está configurado.');
                }
                continue;
            }

            usort($suyos, static function ($a, $b) {
                return strcmp((string) ($a['correo_carpeta'] ?? ''), (string) ($b['correo_carpeta'] ?? ''));
            });

            $buzon = call_user_func($this->abrirBuzon, $config);
            try {
                $buzon->conectar();
                foreach ($suyos as $documento) {
                    $this->recuperarUno($buzon, $documento, $resumen);
                }
            } catch (Throwable $e) {
                $resumen['errores']++;
                $this->anotar($resumen, ['id' => 0], 'error', $e->getMessage());
            } finally {
                $buzon->cerrar();
            }
        }

        return $resumen;
    }

    private function recuperarUno($buzon, array $documento, array &$resumen)
    {
        $resumen['revisados']++;
        $id = (int) ($documento['id'] ?? 0);

        try {
            $destinoXml = trim((string) ($documento['ruta_xml'] ?? ''));
            $destinoPdf = trim((string) ($documento['ruta_pdf'] ?? ''));
            $hashXml = $this->hashValido($documento['hash_xml'] ?? '');
            $hashPdf = $this->hashValido($documento['hash_pdf'] ?? '');

            $faltaXml = $destinoXml === '' || !is_file($destinoXml);
            // Un PDF sin ruta no es un faltante: puede ser un documento que
            // nunca trajo PDF. Solo se repone lo que la base prometió.
            $faltaPdf = $destinoPdf !== '' && !is_file($destinoPdf);

            if (!$faltaXml && !$faltaPdf) {
                $resumen['ya_estaban']++;
                $this->anotar($resumen, $documento, 'ya_estaba', 'Los archivos ya están en su sitio.');
                $this->limpiarMarca($id);
                return;
            }

            if ($hashXml === '') {
                $resumen['sin_coincidencia']++;
                $this->anotar($resumen, $documento, 'sin_hash',
                    'No se guardó la huella del XML: no hay con qué comprobar que lo que baje sea el mismo.');
                return;
            }

            $mensaje = $buzon->extraerMensaje(
                (int) ($documento['correo_uid'] ?? 0),
                (string) ($documento['correo_carpeta'] ?? '')
            );
            $adjuntos = array_merge(
                is_array($mensaje['xmls'] ?? null) ? $mensaje['xmls'] : [],
                is_array($mensaje['pdfs'] ?? null) ? $mensaje['pdfs'] : []
            );

            if (!$adjuntos) {
                $resumen['sin_mensaje']++;
                $this->anotar($resumen, $documento, 'sin_mensaje',
                    'El mensaje del que salió ya no tiene adjuntos, o ya no está en el buzón.');
                return;
            }

            try {
                $xmlTemporal = $this->adjuntoConHash($adjuntos, $hashXml);
                $pdfTemporal = $hashPdf !== '' ? $this->adjuntoConHash($adjuntos, $hashPdf) : '';

                if ($xmlTemporal === '') {
                    $resumen['sin_coincidencia']++;
                    $this->anotar($resumen, $documento, 'sin_coincidencia',
                        'Ninguno de los adjuntos de ese mensaje es el XML que se archivó.');
                    return;
                }

                $escrito = $destinoXml !== ''
                    ? $this->reponerEnSuRuta($documento, $xmlTemporal, $pdfTemporal, $faltaXml, $faltaPdf)
                    : $this->archivarDeNuevo($documento, $xmlTemporal, $pdfTemporal);
            } finally {
                foreach ($adjuntos as $adjunto) {
                    if (!empty($adjunto['ruta']) && is_file($adjunto['ruta'])) {
                        @unlink($adjunto['ruta']);
                    }
                }
            }

            $resumen['recuperados']++;
            $this->anotar($resumen, $documento, 'recuperado', $escrito['mensaje'], $escrito);
        } catch (Throwable $e) {
            $resumen['errores']++;
            $this->anotar($resumen, $documento, 'error', $e->getMessage());
        }
    }

    /**
     * El caso normal: la fila conserva su ruta y el archivo vuelve ahí mismo,
     * con el nombre que ya tenía. Nada más se mueve de sitio.
     */
    private function reponerEnSuRuta(array $documento, $xmlTemporal, $pdfTemporal, $faltaXml, $faltaPdf)
    {
        $repuestos = [];
        if ($faltaXml) {
            $destino = (string) $documento['ruta_xml'];
            $this->escribirValidado($xmlTemporal, $destino, $this->hashValido($documento['hash_xml'] ?? ''));
            $repuestos[] = 'XML';
        }
        if ($faltaPdf && $pdfTemporal !== '') {
            $destino = (string) $documento['ruta_pdf'];
            $this->escribirValidado($pdfTemporal, $destino, $this->hashValido($documento['hash_pdf'] ?? ''));
            $repuestos[] = 'PDF';
        }

        $this->limpiarMarca((int) $documento['id']);

        $pendientePdf = $faltaPdf && $pdfTemporal === '';
        return [
            'ruta_xml' => (string) $documento['ruta_xml'],
            'ruta_pdf' => (string) ($documento['ruta_pdf'] ?? ''),
            'mensaje' => 'Repuesto en su ruta (' . ($repuestos ? implode(' y ', $repuestos) : 'nada que reponer') . ')'
                . ($pendientePdf ? '. El PDF del mensaje no coincide con el que se archivó.' : ''),
        ];
    }

    /**
     * La fila nunca tuvo ruta: el par se archiva como cualquier documento
     * recién importado, en la carpeta del mes que le toca por fecha y tipo.
     */
    private function archivarDeNuevo(array $documento, $xmlTemporal, $pdfTemporal)
    {
        $archivado = $this->archivo()->archivar(
            $xmlTemporal,
            $pdfTemporal !== '' ? $pdfTemporal : null,
            $documento,
            (string) ($documento['proveedor_nombre'] ?? 'PROVEEDOR')
        );

        $this->facturas->actualizarArchivos((int) $documento['id'], [
            'ruta_xml' => $archivado['ruta_xml'],
            'archivo_xml' => $archivado['archivo_xml'],
            'hash_xml' => $archivado['hash_xml'],
            'ruta_pdf' => $archivado['ruta_pdf'],
            'archivo_pdf' => $archivado['archivo_pdf'],
            'hash_pdf' => $archivado['hash_pdf'],
            // Solo se sube de estado cuando de verdad hay PDF: un documento
            // marcado como histórico sin PDF no se degrada a "pendiente".
            'estado_pdf' => $archivado['ruta_pdf'] ? 'disponible' : null,
            'archivado_en' => $archivado['archivado_en'],
        ]);
        $this->limpiarMarca((int) $documento['id']);

        return [
            'ruta_xml' => (string) $archivado['ruta_xml'],
            'ruta_pdf' => (string) ($archivado['ruta_pdf'] ?? ''),
            'mensaje' => 'Archivado de nuevo en la carpeta de su mes.',
        ];
    }

    /** El adjunto cuyo contenido da exactamente esta huella, o cadena vacía. */
    private function adjuntoConHash(array $adjuntos, $hashEsperado)
    {
        foreach ($adjuntos as $adjunto) {
            $ruta = (string) ($adjunto['ruta'] ?? '');
            if ($ruta === '' || !is_file($ruta)) {
                continue;
            }
            if (hash_file('sha256', $ruta) === $hashEsperado) {
                return $ruta;
            }
        }
        return '';
    }

    /**
     * Escribe por un temporal y solo renombra si la copia da la huella
     * esperada, igual que al archivar por primera vez: nunca queda un archivo
     * a medias con el nombre del bueno.
     */
    private function escribirValidado($origen, $destino, $hashEsperado)
    {
        $carpeta = dirname($destino);
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0777, true) && !is_dir($carpeta)) {
            throw new RuntimeException('No se pudo crear la carpeta ' . $carpeta . '.');
        }
        if (is_file($destino)) {
            throw new RuntimeException('Ya hay un archivo en ' . $destino . '; no se toca.');
        }

        $parcial = $destino . '.partial_' . str_replace('.', '', uniqid('', true));
        if (!copy($origen, $parcial)) {
            throw new RuntimeException('No se pudo escribir ' . basename($destino) . ' en la carpeta compartida.');
        }
        if ($hashEsperado !== '' && hash_file('sha256', $parcial) !== $hashEsperado) {
            @unlink($parcial);
            throw new RuntimeException('Lo bajado no coincide con lo que se archivó: no se repuso nada.');
        }
        if (!rename($parcial, $destino)) {
            @unlink($parcial);
            throw new RuntimeException('No se pudo dejar el archivo en ' . $destino . '.');
        }
        return $destino;
    }

    private function limpiarMarca($id)
    {
        if ($id > 0 && method_exists($this->facturas, 'marcarArchivosFaltantes')) {
            $this->facturas->marcarArchivosFaltantes([], [$id]);
        }
    }

    private function archivo()
    {
        if ($this->archivo === null) {
            $this->archivo = new DocumentoArchivo($this->raiz);
        }
        return $this->archivo;
    }

    private function hashValido($hash)
    {
        $hash = strtolower(trim((string) $hash));
        return preg_match('/^[0-9a-f]{64}$/', $hash) ? $hash : '';
    }

    private function anotar(array &$resumen, array $documento, $estado, $mensaje, array $extra = [])
    {
        if (count($resumen['detalle']) >= 500) {
            return;
        }
        $resumen['detalle'][] = array_merge([
            'id' => (int) ($documento['id'] ?? 0),
            'documento' => (string) ($documento['consecutivo_completo'] ?? ''),
            'proveedor' => (string) ($documento['proveedor_nombre'] ?? ''),
            'estado' => $estado,
            'mensaje' => $mensaje,
        ], $extra);
    }
}
