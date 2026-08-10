<?php
require_once __DIR__ . '/DocumentoArchivo.php';
require_once __DIR__ . '/XmlParser.php';
require_once __DIR__ . '/../models/Factura.php';
require_once __DIR__ . '/../models/Semana.php';

/**
 * Ordena el archivo local sin perder la relación XML/PDF ni romper las rutas
 * persistidas.
 *
 * La carpeta de un documento sale de su fecha de emisión y su tipo —dos datos
 * que no cambian nunca—, así que ordenar es idempotente: correrlo dos veces no
 * mueve nada la segunda. La única excepción es PAGOS SEMANALES, donde se
 * reúnen los pares completos de una semana para poder entregarlos.
 */
class OrganizadorDocumentos
{
    private $archivo;
    private $facturas;
    private $raiz;
    private $log;
    private $indiceCache;
    private $estadoCache;
    private $lockArchivo;
    private $indiceMetaAnterior = [];
    private $indiceAnterior = [];
    private $indiceActual = [];
    private $rutasPorExtension = ['xml' => [], 'pdf' => []];
    private $rutasPorHash = ['xml' => [], 'pdf' => []];
    private $rutasReclamadas = [];

    public function __construct($raiz = '', $facturas = null)
    {
        // También prepara carpeta_pago cuando esta clase corre desde la
        // tarea programada antes de que alguien abra el módulo semanal.
        if ($facturas === null) {
            new Semana();
        }
        $this->archivo = new DocumentoArchivo($raiz);
        $real = realpath($this->archivo->raiz());
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('La carpeta raíz del archivo local no existe.');
        }
        $this->raiz = rtrim($real, '/\\');
        $this->facturas = $facturas ?: new Factura();
        $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $this->log = $logDir . DIRECTORY_SEPARATOR . 'organizador_documentos.log';
        $cacheDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $claveRaiz = substr(sha1($this->claveRuta($this->raiz)), 0, 16);
        $this->indiceCache = $cacheDir . DIRECTORY_SEPARATOR . 'documentos_indice_' . $claveRaiz . '.json';
        $this->estadoCache = $cacheDir . DIRECTORY_SEPARATOR . 'documentos_estado_' . $claveRaiz . '.json';
        $this->lockArchivo = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'locks' . DIRECTORY_SEPARATOR . 'organizador_documentos_' . $claveRaiz . '.lock';
    }

    public function ejecutar($dryRun = false, $incluirNoRegistrados = true)
    {
        $resumen = $this->resumenBase((bool) $dryRun);
        $lockDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0777, true) && !is_dir($lockDir)) {
            throw new RuntimeException('No se pudo crear el directorio de bloqueos del organizador.');
        }
        $fp = @fopen($this->lockArchivo, 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            $resumen['omitido_por_bloqueo'] = true;
            if (is_resource($fp)) {
                fclose($fp);
            }
            return $resumen;
        }

        try {
            $this->organizarRegistrados($resumen, (bool) $dryRun);
            if ($incluirNoRegistrados) {
                $this->organizarNoRegistrados($resumen, (bool) $dryRun);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        if (!$dryRun) {
            $this->registrarLog($resumen);
        }
        return $resumen;
    }

    /**
     * Reconoce archivos registrados que fueron movidos o renombrados dentro
     * de la carpeta raíz. A diferencia de ejecutar(), nunca mueve archivos:
     * acepta la ubicación elegida por la persona y actualiza solamente la BD.
     *
     * El modo incremental calcula hashes únicamente cuando falta una ruta.
     * El modo completo vuelve a validar el contenido de todos los XML/PDF.
     */
    public function reconciliar($dryRun = false, $completo = false, array $ids = [])
    {
        $dryRun = (bool) $dryRun;
        $completo = (bool) $completo;
        $resumen = $this->resumenBase($dryRun);
        $resumen['modo'] = $completo ? 'reconciliacion_completa' : 'reconciliacion_incremental';

        $lockDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0777, true) && !is_dir($lockDir)) {
            throw new RuntimeException('No se pudo crear el directorio de bloqueos del organizador.');
        }
        $fp = @fopen($this->lockArchivo, 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            $resumen['omitido_por_bloqueo'] = true;
            if (is_resource($fp)) fclose($fp);
            return $resumen;
        }

        try {
            $filas = $this->facturas->getParaOrganizarArchivos($ids);
            $resumen['registrados_revisados'] = count($filas);
            $faltantes = $this->estadoRutasFaltantes($filas);
            $estadoAnterior = $this->cargarEstadoReconciliacion();
            $mismaFirmaPendiente = $faltantes['firma'] !== ''
                && hash_equals((string) ($estadoAnterior['firma_faltantes'] ?? ''), $faltantes['firma']);

            if ($completo || ($faltantes['firma'] !== ''
                && (!$mismaFirmaPendiente || !is_file($this->indiceCache)))) {
                $this->construirIndice($completo, $resumen);
                $this->reconciliarFilas($filas, $dryRun, $completo, $resumen);
                if (!$dryRun) {
                    $this->guardarIndice($completo);
                    $filasActualizadas = $this->facturas->getParaOrganizarArchivos($ids);
                    $estadoFinal = $this->estadoRutasFaltantes($filasActualizadas);
                    $this->guardarEstadoReconciliacion($estadoFinal['firma'], $completo);
                }
            } else {
                // Camino habitual: si todas las rutas siguen vigentes, o los
                // faltantes son exactamente los ya revisados, no se recorre
                // el árbol ni se lee el contenido de ningún archivo.
                $resumen['rutas_vigentes'] = $faltantes['vigentes'];
                $resumen['archivos_no_encontrados'] = $faltantes['archivos'];
                $resumen['faltantes_en_disco'] = $faltantes['documentos_sin_archivos'];
                $resumen['revision_omitida_sin_cambios'] = $mismaFirmaPendiente;
                if (!$dryRun && (string) ($estadoAnterior['firma_faltantes'] ?? '') !== $faltantes['firma']) {
                    $this->guardarEstadoReconciliacion($faltantes['firma'], false);
                }
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        if (!$dryRun) {
            $this->registrarLog($resumen);
        }
        return $resumen;
    }

    /** La tarea frecuente usa esto para hacer como máximo una lectura completa al día. */
    public function requiereReconciliacionCompleta($intervaloSegundos = 86400)
    {
        $estado = $this->cargarEstadoReconciliacion();
        $ultima = strtotime((string) ($estado['ultima_revision_completa'] ?? ''));
        // Compatibilidad con el primer índice creado antes de existir el
        // archivo de estado liviano: evita repetir inmediatamente una lectura
        // completa costosa después de una actualización de la aplicación.
        if ($ultima === false && is_file($this->indiceCache)) {
            $indice = json_decode((string) @file_get_contents($this->indiceCache), true);
            if (is_array($indice)
                && $this->claveRuta((string) ($indice['raiz'] ?? '')) === $this->claveRuta($this->raiz)) {
                $ultima = strtotime((string) ($indice['ultima_revision_completa'] ?? ''));
                if ($ultima !== false) {
                    $estado['version'] = 1;
                    $estado['raiz'] = $this->raiz;
                    $estado['ultima_revision_completa'] = date('Y-m-d H:i:s', $ultima);
                    $json = json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (is_string($json)) @file_put_contents($this->estadoCache, $json, LOCK_EX);
                }
            }
        }
        return $ultima === false || (time() - $ultima) >= max(3600, (int) $intervaloSegundos);
    }

    public function organizarIds(array $ids, $dryRun = false)
    {
        $resumen = $this->resumenBase((bool) $dryRun);
        $lockDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0777, true);
        }
        $fp = @fopen($this->lockArchivo, 'c');
        if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
            $resumen['omitido_por_bloqueo'] = true;
            if (is_resource($fp)) fclose($fp);
            return $resumen;
        }
        try {
            $this->organizarRegistrados($resumen, (bool) $dryRun, $ids);
            if (!$dryRun) {
                $this->registrarLog($resumen);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $resumen;
    }

    private function estadoRutasFaltantes(array $filas)
    {
        $items = [];
        $vigentes = 0;
        $archivosFaltantes = 0;
        $documentosSinArchivos = 0;
        foreach ($filas as $fila) {
            $existentesDocumento = 0;
            foreach ([['ruta_xml', 'hash_xml'], ['ruta_pdf', 'hash_pdf']] as $campos) {
                $ruta = trim((string) ($fila[$campos[0]] ?? ''));
                $hash = $this->hashValido((string) ($fila[$campos[1]] ?? ''));
                $existe = $ruta !== '' && is_file($ruta) && $this->rutaDentroRaiz($ruta);
                if ($existe) {
                    $vigentes++;
                    $existentesDocumento++;
                    continue;
                }
                if ($ruta !== '' || $hash !== '') {
                    $archivosFaltantes++;
                    $items[] = implode('|', [
                        (int) ($fila['id'] ?? 0),
                        $campos[0],
                        $this->claveRuta($ruta),
                        $hash,
                    ]);
                }
            }
            if ($existentesDocumento === 0) {
                $documentosSinArchivos++;
            }
        }
        sort($items, SORT_STRING);
        return [
            'firma' => $items ? hash('sha256', implode("\n", $items)) : '',
            'vigentes' => $vigentes,
            'archivos' => $archivosFaltantes,
            'documentos_sin_archivos' => $documentosSinArchivos,
        ];
    }

    private function cargarEstadoReconciliacion()
    {
        if (!is_file($this->estadoCache)) {
            return [];
        }
        $estado = json_decode((string) @file_get_contents($this->estadoCache), true);
        if (!is_array($estado)
            || $this->claveRuta((string) ($estado['raiz'] ?? '')) !== $this->claveRuta($this->raiz)) {
            return [];
        }
        return $estado;
    }

    private function guardarEstadoReconciliacion($firmaFaltantes, $completo)
    {
        $anterior = $this->cargarEstadoReconciliacion();
        $ultimaCompleta = $anterior['ultima_revision_completa']
            ?? ($this->indiceMetaAnterior['ultima_revision_completa'] ?? null);
        $estado = [
            'version' => 1,
            'raiz' => $this->raiz,
            'firma_faltantes' => (string) $firmaFaltantes,
            'ultimo_escaneo' => date('Y-m-d H:i:s'),
            'ultima_revision_completa' => $completo
                ? date('Y-m-d H:i:s')
                : $ultimaCompleta,
        ];
        $json = json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($this->estadoCache, $json, LOCK_EX);
        }
    }

    private function construirIndice($completo, array &$resumen)
    {
        $this->cargarIndiceAnterior();
        $this->indiceActual = [];
        $this->rutasPorExtension = ['xml' => [], 'pdf' => []];
        $this->rutasPorHash = ['xml' => [], 'pdf' => []];
        $this->rutasReclamadas = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->raiz, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $ext = strtolower($item->getExtension());
            if (!in_array($ext, ['xml', 'pdf'], true)) {
                continue;
            }

            $ruta = $item->getPathname();
            $clave = $this->claveRuta($ruta);
            $tamano = (int) $item->getSize();
            $mtime = (int) $item->getMTime();
            $anterior = $this->indiceAnterior[$clave] ?? [];
            $hash = '';
            if (!$completo
                && (int) ($anterior['tamano'] ?? -1) === $tamano
                && (int) ($anterior['mtime'] ?? -1) === $mtime) {
                $hash = $this->hashValido((string) ($anterior['hash'] ?? ''));
            }

            $this->indiceActual[$clave] = [
                'ruta' => $ruta,
                'extension' => $ext,
                'tamano' => $tamano,
                'mtime' => $mtime,
                'hash' => $hash,
            ];
            $this->rutasPorExtension[$ext][] = $clave;

            if ($completo) {
                $hash = $this->calcularHashIndice($clave, $resumen);
            }
            if ($hash !== '') {
                $this->registrarRutaPorHash($ext, $hash, $clave);
            }
        }
        $resumen['archivos_indexados'] = count($this->indiceActual);
    }

    private function cargarIndiceAnterior()
    {
        $this->indiceMetaAnterior = [];
        $this->indiceAnterior = [];
        if (!is_file($this->indiceCache)) {
            return;
        }
        $datos = json_decode((string) @file_get_contents($this->indiceCache), true);
        if (!is_array($datos)
            || $this->claveRuta((string) ($datos['raiz'] ?? '')) !== $this->claveRuta($this->raiz)
            || !is_array($datos['archivos'] ?? null)) {
            return;
        }
        $this->indiceMetaAnterior = $datos;
        $this->indiceAnterior = $datos['archivos'];
    }

    private function guardarIndice($completo)
    {
        $dir = dirname($this->indiceCache);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return;
        }
        $datos = [
            'version' => 1,
            'raiz' => $this->raiz,
            'actualizado_en' => date('Y-m-d H:i:s'),
            'ultima_revision_completa' => $completo
                ? date('Y-m-d H:i:s')
                : ($this->indiceMetaAnterior['ultima_revision_completa'] ?? null),
            'archivos' => $this->indiceActual,
        ];
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }
        // El lock del organizador ya serializa escritores. La escritura con
        // LOCK_EX funciona también en Windows, donde rename() no reemplaza de
        // forma fiable un archivo destino que ya existe.
        @file_put_contents($this->indiceCache, $json, LOCK_EX);
    }

    private function reconciliarFilas(array $filas, $dryRun, $completo, array &$resumen)
    {
        // Primero reserva todas las rutas todavía válidas. Así un archivo
        // ya asociado nunca se reutiliza para reparar otro registro.
        foreach ($filas as $fila) {
            foreach (['ruta_xml', 'ruta_pdf'] as $campo) {
                $ruta = trim((string) ($fila[$campo] ?? ''));
                if ($ruta !== '' && is_file($ruta) && $this->rutaDentroRaiz($ruta)) {
                    $this->rutasReclamadas[$this->claveRuta($ruta)] = true;
                }
            }
        }

        foreach ($filas as $fila) {
            try {
                $id = (int) ($fila['id'] ?? 0);
                $xmlAntes = trim((string) ($fila['ruta_xml'] ?? ''));
                $pdfAntes = trim((string) ($fila['ruta_pdf'] ?? ''));
                $hashXml = $this->hashValido((string) ($fila['hash_xml'] ?? ''));
                $hashPdf = $this->hashValido((string) ($fila['hash_pdf'] ?? ''));
                if ($hashXml === '') $hashXml = $this->hashAnteriorDeRuta($xmlAntes);
                if ($hashPdf === '') $hashPdf = $this->hashAnteriorDeRuta($pdfAntes);
                $xml = $xmlAntes !== '' && is_file($xmlAntes) && $this->rutaDentroRaiz($xmlAntes) ? $xmlAntes : '';
                $pdf = $pdfAntes !== '' && is_file($pdfAntes) && $this->rutaDentroRaiz($pdfAntes) ? $pdfAntes : '';

                if ($completo && $xml !== '' && $hashXml !== '') {
                    $actual = $this->hashDeRutaIndexada($xml, $resumen);
                    if ($actual !== $hashXml) {
                        $resumen['contenido_modificado']++;
                        $alternativa = $this->buscarPorHash($hashXml, 'xml', $xmlAntes, $resumen);
                        if ($alternativa !== '') $xml = $alternativa;
                    }
                }
                if ($completo && $pdf !== '' && $hashPdf !== '') {
                    $actual = $this->hashDeRutaIndexada($pdf, $resumen);
                    if ($actual !== $hashPdf) {
                        $resumen['contenido_modificado']++;
                        $alternativa = $this->buscarPorHash($hashPdf, 'pdf', $pdfAntes, $resumen);
                        if ($alternativa !== '') $pdf = $alternativa;
                    }
                }

                if ($xml === '' && ($xmlAntes !== '' || $hashXml !== '')) {
                    $xml = $this->resolverRuta('xml', $hashXml, $xmlAntes, '', $resumen);
                }

                // La pareja junto al XML evita leer PDFs grandes cuando ambos
                // fueron movidos o renombrados conservando el mismo nombre base.
                if ($pdf === '' && ($pdfAntes !== '' || $hashPdf !== '')) {
                    $pareja = $xml !== ''
                        ? dirname($xml) . DIRECTORY_SEPARATOR . pathinfo($xml, PATHINFO_FILENAME) . '.pdf'
                        : '';
                    $pdf = $this->resolverRuta('pdf', $hashPdf, $pdfAntes, $pareja, $resumen, $completo);
                }
                if ($xml === '' && $pdf !== '' && ($xmlAntes !== '' || $hashXml !== '')) {
                    $pareja = dirname($pdf) . DIRECTORY_SEPARATOR . pathinfo($pdf, PATHINFO_FILENAME) . '.xml';
                    $xml = $this->resolverRuta('xml', $hashXml, $xmlAntes, $pareja, $resumen, $completo);
                }

                foreach ([['xml', $xmlAntes, $xml, $hashXml], ['pdf', $pdfAntes, $pdf, $hashPdf]] as $dato) {
                    [$ext, $antes, $ahora, $hashEsperado] = $dato;
                    if ($ahora !== '') {
                        $claveAhora = $this->claveRuta($ahora);
                        $this->rutasReclamadas[$claveAhora] = true;
                        if ($hashEsperado !== '' && isset($this->indiceActual[$claveAhora])
                            && $this->hashValido((string) ($this->indiceActual[$claveAhora]['hash'] ?? '')) === '') {
                            $this->indiceActual[$claveAhora]['hash'] = $hashEsperado;
                            $this->registrarRutaPorHash($ext, $hashEsperado, $claveAhora);
                        }
                    }
                    if (($antes !== '' || $hashEsperado !== '') && $ahora === '') {
                        $resumen['archivos_no_encontrados']++;
                    }
                }

                $cambioXml = $xml !== '' && !$this->mismaRuta($xml, $xmlAntes);
                $cambioPdf = $pdf !== '' && !$this->mismaRuta($pdf, $pdfAntes);
                if (!$cambioXml && !$cambioPdf) {
                    if ($xml !== '' || $pdf !== '') $resumen['rutas_vigentes'] += ($xml !== '' ? 1 : 0) + ($pdf !== '' ? 1 : 0);
                    if ($xml === '' && $pdf === '') $resumen['faltantes_en_disco']++;
                    continue;
                }

                foreach ([[$xmlAntes, $xml, $cambioXml], [$pdfAntes, $pdf, $cambioPdf]] as $cambio) {
                    if (!$cambio[2]) continue;
                    $resumen['archivos_relocalizados']++;
                    if ($cambio[0] !== '' && strcasecmp(basename($cambio[0]), basename($cambio[1])) !== 0) {
                        $resumen['renombres_detectados']++;
                    }
                    if ($cambio[0] !== '' && !$this->mismaRuta(dirname($cambio[0]), dirname($cambio[1]))) {
                        $resumen['movimientos_manuales_detectados']++;
                    }
                }

                $xmlDb = $xml !== '' ? $xml : $xmlAntes;
                $pdfDb = $pdf !== '' ? $pdf : $pdfAntes;
                $this->agregarOperacion($resumen, [
                    'documento_id' => $id,
                    'accion' => $dryRun ? 'actualizar_ruta_planificada' : 'ruta_actualizada',
                    'xml' => $xmlDb,
                    'pdf' => $pdfDb,
                ]);
                if ($dryRun) {
                    $resumen['rutas_por_actualizar']++;
                    continue;
                }

                try {
                    $this->facturas->begin();
                    $this->facturas->actualizarUbicacionArchivos($id, $xmlDb, $pdfDb);
                    $this->facturas->commit();
                } catch (Throwable $e) {
                    $this->facturas->rollback();
                    throw $e;
                }
                $resumen['rutas_actualizadas']++;
            } catch (Throwable $e) {
                $resumen['errores']++;
                $this->agregarOperacion($resumen, [
                    'documento_id' => (int) ($fila['id'] ?? 0),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolverRuta($extension, $hashEsperado, $rutaAnterior, $pareja, array &$resumen, $validarPareja = false)
    {
        if ($pareja !== '' && is_file($pareja) && $this->rutaDentroRaiz($pareja)) {
            $clavePareja = $this->claveRuta($pareja);
            if (!isset($this->rutasReclamadas[$clavePareja])) {
                if (!$validarPareja || $hashEsperado === ''
                    || $this->hashDeRutaIndexada($pareja, $resumen) === $hashEsperado) {
                    return $pareja;
                }
            }
        }
        if ($hashEsperado !== '') {
            $porHash = $this->buscarPorHash($hashEsperado, $extension, $rutaAnterior, $resumen);
            if ($porHash !== '') {
                return $porHash;
            }
        }
        return $this->buscarPorNombre($rutaAnterior, $extension);
    }

    private function buscarPorHash($hash, $extension, $rutaAnterior, array &$resumen)
    {
        foreach ($this->rutasPorHash[$extension][$hash] ?? [] as $clave) {
            if (!isset($this->rutasReclamadas[$clave]) && isset($this->indiceActual[$clave])) {
                return (string) $this->indiceActual[$clave]['ruta'];
            }
        }

        $tamanoAnterior = null;
        $claveAnterior = $this->claveRuta($rutaAnterior);
        if ($rutaAnterior !== '' && isset($this->indiceAnterior[$claveAnterior]['tamano'])) {
            $tamanoAnterior = (int) $this->indiceAnterior[$claveAnterior]['tamano'];
        }
        $candidatas = $this->rutasPorExtension[$extension] ?? [];
        if ($tamanoAnterior !== null) {
            $mismoTamano = array_values(array_filter($candidatas, function ($clave) use ($tamanoAnterior) {
                return (int) ($this->indiceActual[$clave]['tamano'] ?? -1) === $tamanoAnterior;
            }));
            if ($mismoTamano) {
                $candidatas = $mismoTamano;
            }
        }

        $nombreAnterior = strtolower(basename($rutaAnterior));
        usort($candidatas, function ($a, $b) use ($nombreAnterior) {
            $aMismo = strtolower(basename((string) ($this->indiceActual[$a]['ruta'] ?? ''))) === $nombreAnterior ? 0 : 1;
            $bMismo = strtolower(basename((string) ($this->indiceActual[$b]['ruta'] ?? ''))) === $nombreAnterior ? 0 : 1;
            return $aMismo <=> $bMismo;
        });

        foreach ($candidatas as $clave) {
            if (isset($this->rutasReclamadas[$clave])) {
                continue;
            }
            if ($this->calcularHashIndice($clave, $resumen) === $hash) {
                return (string) $this->indiceActual[$clave]['ruta'];
            }
        }
        return '';
    }

    private function buscarPorNombre($rutaAnterior, $extension)
    {
        $nombre = strtolower(basename((string) $rutaAnterior));
        if ($nombre === '' || $nombre === '.' || $nombre === $extension) {
            return '';
        }
        $encontrada = '';
        foreach ($this->rutasPorExtension[$extension] ?? [] as $clave) {
            if (isset($this->rutasReclamadas[$clave])) continue;
            $ruta = (string) ($this->indiceActual[$clave]['ruta'] ?? '');
            if (strtolower(basename($ruta)) !== $nombre) continue;
            if ($encontrada !== '') return ''; // un nombre ambiguo no se asocia
            $encontrada = $ruta;
        }
        return $encontrada;
    }

    private function hashDeRutaIndexada($ruta, array &$resumen)
    {
        $clave = $this->claveRuta($ruta);
        return isset($this->indiceActual[$clave])
            ? $this->calcularHashIndice($clave, $resumen)
            : '';
    }

    private function hashAnteriorDeRuta($ruta)
    {
        if ($ruta === '') {
            return '';
        }
        $clave = $this->claveRuta($ruta);
        return $this->hashValido((string) ($this->indiceAnterior[$clave]['hash'] ?? ''));
    }

    private function calcularHashIndice($clave, array &$resumen)
    {
        if (!isset($this->indiceActual[$clave])) {
            return '';
        }
        $existente = $this->hashValido((string) ($this->indiceActual[$clave]['hash'] ?? ''));
        if ($existente !== '') {
            return $existente;
        }
        $ruta = (string) $this->indiceActual[$clave]['ruta'];
        $calculado = is_file($ruta) ? @hash_file('sha256', $ruta) : false;
        $hash = is_string($calculado) ? $this->hashValido($calculado) : '';
        if ($hash === '') {
            $resumen['errores_hash']++;
            return '';
        }
        $this->indiceActual[$clave]['hash'] = $hash;
        $resumen['hashes_calculados']++;
        $this->registrarRutaPorHash((string) $this->indiceActual[$clave]['extension'], $hash, $clave);
        return $hash;
    }

    private function registrarRutaPorHash($extension, $hash, $clave)
    {
        if (!isset($this->rutasPorHash[$extension][$hash])) {
            $this->rutasPorHash[$extension][$hash] = [];
        }
        if (!in_array($clave, $this->rutasPorHash[$extension][$hash], true)) {
            $this->rutasPorHash[$extension][$hash][] = $clave;
        }
    }

    private function hashValido($hash)
    {
        $hash = strtolower(trim((string) $hash));
        return preg_match('/^[a-f0-9]{64}$/', $hash) ? $hash : '';
    }

    private function organizarRegistrados(array &$resumen, $dryRun, array $ids = [])
    {
        $filas = $this->facturas->getParaOrganizarArchivos($ids);
        foreach ($filas as $fila) {
            $resumen['registrados_revisados']++;
            try {
                [$xml, $pdf] = $this->rutasExistentes($fila);
                if ($xml === '' && $pdf === '') {
                    $resumen['faltantes_en_disco']++;
                    continue;
                }
                if (($xml !== '' && !$this->rutaDentroRaiz($xml))
                    || ($pdf !== '' && !$this->rutaDentroRaiz($pdf))) {
                    $resumen['fuera_de_raiz']++;
                    continue;
                }

                // La carpeta depende solo de la fecha de emisión y del tipo,
                // que no cambian nunca. La única excepción es el pago semanal:
                // ahí los pares se reúnen aparte para poder entregarlos.
                // Solo los pares completos entran a la carpeta de pago: esa
                // carpeta existe para entregar los respaldos, y un documento
                // al que le falta el PDF no se puede entregar.
                $enPagoSemanal = $xml !== '' && $pdf !== ''
                    && !empty($fila['pago_semanal']) && !empty($fila['carpeta_pago']);
                $fecha = $this->fechaValida((string) ($fila['fecha_emision'] ?? ''), $xml !== '' ? $xml : $pdf);
                $tipo = $this->tipoValido((string) ($fila['tipo_documento'] ?? 'FE'));

                if ($enPagoSemanal) {
                    $estado = DocumentoArchivo::CARPETA_PAGOS;
                    $destinoDir = $this->archivo->carpetaPagoSemanal((string) $fila['carpeta_pago']);
                    $base = DocumentoArchivo::nombreBase($fila, (string) ($fila['proveedor_nombre'] ?? 'PROVEEDOR'));
                } else {
                    $estado = $tipo;
                    $destinoDir = $this->archivo->carpetaDocumento($fecha, $tipo);
                    $base = pathinfo($xml !== '' ? $xml : $pdf, PATHINFO_FILENAME);
                }
                $movimiento = $this->moverConjunto(['xml' => $xml, 'pdf' => $pdf], $destinoDir, $base, $dryRun);
                if (!$movimiento['cambio']) {
                    $resumen['ya_ordenados']++;
                    continue;
                }

                $this->agregarOperacion($resumen, [
                    'documento_id' => (int) $fila['id'],
                    'estado' => $estado,
                    'xml' => $movimiento['rutas']['xml'],
                    'pdf' => $movimiento['rutas']['pdf'],
                ]);
                if ($dryRun) {
                    $resumen['movimientos_planificados']++;
                    $this->contarEstado($resumen, $estado);
                    continue;
                }

                try {
                    $this->facturas->begin();
                    $this->facturas->actualizarUbicacionArchivos(
                        (int) $fila['id'],
                        $movimiento['rutas']['xml'],
                        $movimiento['rutas']['pdf']
                    );
                    $this->facturas->commit();
                } catch (Throwable $e) {
                    $this->facturas->rollback();
                    $this->revertirMovimientos($movimiento['movidos']);
                    throw $e;
                }

                $resumen['movidos']++;
                $this->contarEstado($resumen, $estado);
            } catch (Throwable $e) {
                $resumen['errores']++;
                $this->agregarOperacion($resumen, [
                    'documento_id' => (int) ($fila['id'] ?? 0),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function organizarNoRegistrados(array &$resumen, $dryRun)
    {
        $registradas = [];
        foreach ($this->facturas->getParaOrganizarArchivos([]) as $fila) {
            foreach (['ruta_xml', 'ruta_pdf'] as $campo) {
                $ruta = trim((string) ($fila[$campo] ?? ''));
                if ($ruta !== '' && is_file($ruta)) {
                    $registradas[$this->claveRuta($ruta)] = true;
                }
            }
        }

        $grupos = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->raiz, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $ext = strtolower($item->getExtension());
            if (!in_array($ext, ['xml', 'pdf'], true)) {
                continue;
            }
            $ruta = $item->getPathname();
            if (isset($registradas[$this->claveRuta($ruta)])) {
                continue;
            }
            $key = $this->claveRuta($item->getPath() . DIRECTORY_SEPARATOR . $item->getBasename('.' . $item->getExtension()));
            $grupos[$key][$ext] = $ruta;
        }

        foreach ($grupos as $archivos) {
            $resumen['no_registrados_revisados']++;
            try {
                $xml = (string) ($archivos['xml'] ?? '');
                $pdf = (string) ($archivos['pdf'] ?? '');
                $info = $xml !== '' ? $this->clasificarXml($xml) : [
                    'clase' => 'documento',
                    'tipo' => $this->tipoDesdeRuta($pdf),
                    'fecha' => $this->fechaValida('', $pdf),
                ];

                if ($info['clase'] === 'mensaje_hacienda') {
                    $destinoDir = $this->carpetaIgnorados($info['fecha'], 'MENSAJE HACIENDA SOLO');
                    $estado = 'MENSAJE HACIENDA SOLO';
                } elseif ($info['clase'] === 'invalido') {
                    $destinoDir = $this->carpetaIgnorados($info['fecha'], 'XML INVALIDO');
                    $estado = 'XML INVALIDO';
                } else {
                    $estado = $info['tipo'];
                    $destinoDir = $this->archivo->carpetaDocumento($info['fecha'], $info['tipo']);
                }

                $base = pathinfo($xml !== '' ? $xml : $pdf, PATHINFO_FILENAME);
                $movimiento = $this->moverConjunto(['xml' => $xml, 'pdf' => $pdf], $destinoDir, $base, $dryRun);
                if (!$movimiento['cambio']) {
                    continue;
                }
                $this->agregarOperacion($resumen, [
                    'no_registrado' => true,
                    'estado' => $estado,
                    'xml' => $movimiento['rutas']['xml'],
                    'pdf' => $movimiento['rutas']['pdf'],
                ]);
                if ($dryRun) {
                    $resumen['movimientos_planificados']++;
                } else {
                    $resumen['movidos_no_registrados']++;
                }
                $this->contarEstado($resumen, $estado);
            } catch (Throwable $e) {
                $resumen['errores']++;
                $this->agregarOperacion($resumen, ['no_registrado' => true, 'error' => $e->getMessage()]);
            }
        }
    }

    private function rutasExistentes(array $fila)
    {
        $xml = trim((string) ($fila['ruta_xml'] ?? ''));
        $pdf = trim((string) ($fila['ruta_pdf'] ?? ''));
        $xml = $xml !== '' && is_file($xml) ? $xml : '';
        $pdf = $pdf !== '' && is_file($pdf) ? $pdf : '';

        // Recupera pares históricos que físicamente están juntos, pero cuyo
        // PDF o XML no quedó enlazado en la base de datos.
        if ($xml !== '' && $pdf === '') {
            $candidato = dirname($xml) . DIRECTORY_SEPARATOR . pathinfo($xml, PATHINFO_FILENAME) . '.pdf';
            if (is_file($candidato)) {
                $pdf = $candidato;
            }
        } elseif ($pdf !== '' && $xml === '') {
            $candidato = dirname($pdf) . DIRECTORY_SEPARATOR . pathinfo($pdf, PATHINFO_FILENAME) . '.xml';
            if (is_file($candidato)) {
                $xml = $candidato;
            }
        }
        return [$xml, $pdf];
    }

    private function moverConjunto(array $origenes, $destinoDir, $base, $dryRun)
    {
        $existentes = [];
        foreach (['xml', 'pdf'] as $ext) {
            $ruta = trim((string) ($origenes[$ext] ?? ''));
            if ($ruta !== '' && is_file($ruta)) {
                $this->asegurarDentroRaiz($ruta);
                $existentes[$ext] = $ruta;
            }
        }
        if (!$existentes) {
            throw new RuntimeException('No hay archivos existentes para organizar.');
        }

        $this->asegurarDestinoDentroRaiz($destinoDir);
        $base = preg_replace('/[^A-Za-z0-9._-]+/u', '_', trim((string) $base));
        $base = trim($base, '._-') !== '' ? trim($base, '._-') : 'DOCUMENTO';
        $baseDestino = $base;
        $intento = 0;
        while ($this->hayColision($existentes, $destinoDir, $baseDestino)) {
            $hashSource = reset($existentes);
            $sufijo = strtoupper(substr((string) hash_file('sha256', $hashSource), 0, 8));
            $baseDestino = $base . '_DUP_' . $sufijo . ($intento > 0 ? '_' . $intento : '');
            $intento++;
            if ($intento > 100) {
                throw new RuntimeException('No se pudo resolver una colisión de nombres en ' . $destinoDir . '.');
            }
        }

        $rutas = ['xml' => '', 'pdf' => ''];
        $cambio = false;
        foreach ($existentes as $ext => $origen) {
            $destino = $destinoDir . DIRECTORY_SEPARATOR . $baseDestino . '.' . $ext;
            $rutas[$ext] = $destino;
            if (!$this->mismaRuta($origen, $destino)) {
                $cambio = true;
            }
        }
        if (!$cambio || $dryRun) {
            return ['cambio' => $cambio, 'rutas' => $rutas, 'movidos' => []];
        }

        if (!is_dir($destinoDir) && !mkdir($destinoDir, 0777, true) && !is_dir($destinoDir)) {
            throw new RuntimeException('No se pudo crear la carpeta destino: ' . $destinoDir);
        }
        $movidos = [];
        try {
            foreach ($existentes as $ext => $origen) {
                $destino = $rutas[$ext];
                if ($this->mismaRuta($origen, $destino)) {
                    continue;
                }
                if (!rename($origen, $destino)) {
                    throw new RuntimeException('No se pudo mover ' . $origen . ' a ' . $destino . '.');
                }
                $movidos[] = ['origen' => $origen, 'destino' => $destino];
            }
        } catch (Throwable $e) {
            $this->revertirMovimientos($movidos);
            throw $e;
        }
        return ['cambio' => true, 'rutas' => $rutas, 'movidos' => $movidos];
    }

    private function hayColision(array $origenes, $destinoDir, $base)
    {
        foreach ($origenes as $ext => $origen) {
            $destino = $destinoDir . DIRECTORY_SEPARATOR . $base . '.' . $ext;
            if (is_file($destino) && !$this->mismaRuta($origen, $destino)) {
                return true;
            }
        }
        return false;
    }

    private function revertirMovimientos(array $movidos)
    {
        foreach (array_reverse($movidos) as $movido) {
            if (is_file($movido['destino']) && !is_file($movido['origen'])) {
                @rename($movido['destino'], $movido['origen']);
            }
        }
    }

    private function clasificarXml($ruta)
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($ruta);
        if ($xml === false) {
            return ['clase' => 'invalido', 'tipo' => 'FE', 'fecha' => $this->fechaValida('', $ruta)];
        }
        if (stripos($xml->getName(), 'mensaje') !== false) {
            $nodes = $xml->xpath('//*[local-name()="Clave"]');
            $clave = is_array($nodes) && isset($nodes[0]) ? preg_replace('/\D+/', '', (string) $nodes[0]) : '';
            return [
                'clase' => 'mensaje_hacienda',
                'tipo' => 'FE',
                'fecha' => $this->fechaDesdeClave($clave, $ruta),
            ];
        }
        try {
            $doc = XmlInvoiceParser::parseCfdiFromFile($ruta);
            return [
                'clase' => 'documento',
                'tipo' => $this->tipoValido((string) ($doc['tipo_documento'] ?? 'FE')),
                'fecha' => $this->fechaValida((string) ($doc['fecha_emision'] ?? ''), $ruta),
            ];
        } catch (Throwable $e) {
            return ['clase' => 'invalido', 'tipo' => 'FE', 'fecha' => $this->fechaValida('', $ruta)];
        }
    }

    private function fechaDesdeClave($clave, $ruta)
    {
        if (strlen((string) $clave) === 50) {
            $dmy = substr($clave, 3, 6);
            $fecha = DateTime::createFromFormat('!dmy', $dmy);
            if ($fecha && $fecha->format('dmy') === $dmy) {
                return $fecha->format('Y-m-d');
            }
        }
        return $this->fechaValida('', $ruta);
    }

    private function fechaValida($fecha, $ruta)
    {
        $ts = strtotime((string) $fecha);
        if ($ts === false) {
            $ts = is_file($ruta) ? filemtime($ruta) : time();
        }
        return date('Y-m-d', $ts ?: time());
    }

    private function tipoValido($tipo)
    {
        $tipo = strtoupper(trim((string) $tipo));
        return in_array($tipo, ['FE', 'NC', 'ND'], true) ? $tipo : 'FE';
    }

    private function tipoDesdeRuta($ruta)
    {
        $texto = mb_strtoupper((string) $ruta, 'UTF-8');
        return strpos($texto, 'NC_') !== false || strpos($texto, 'NOTAS DE CR') !== false ? 'NC' : 'FE';
    }

    private function carpetaIgnorados($fecha, $motivo)
    {
        $ts = strtotime((string) $fecha) ?: time();
        $meses = [1=>'ENERO',2=>'FEBRERO',3=>'MARZO',4=>'ABRIL',5=>'MAYO',6=>'JUNIO',7=>'JULIO',8=>'AGOSTO',9=>'SEPTIEMBRE',10=>'OCTUBRE',11=>'NOVIEMBRE',12=>'DICIEMBRE'];
        return $this->raiz . DIRECTORY_SEPARATOR . date('Y', $ts)
            . DIRECTORY_SEPARATOR . date('m', $ts) . ' ' . $meses[(int) date('n', $ts)]
            . DIRECTORY_SEPARATOR . 'IGNORADOS' . DIRECTORY_SEPARATOR . $motivo;
    }

    private function asegurarDentroRaiz($ruta)
    {
        $real = realpath($ruta);
        if ($real === false || !$this->esRutaDentro($real, $this->raiz)) {
            throw new RuntimeException('Se rechazó una ruta fuera de la carpeta raíz: ' . $ruta);
        }
    }

    private function rutaDentroRaiz($ruta)
    {
        $real = realpath($ruta);
        return $real !== false && $this->esRutaDentro($real, $this->raiz);
    }

    private function asegurarDestinoDentroRaiz($ruta)
    {
        $normal = $this->normalizarRuta($ruta);
        if (!$this->esRutaDentro($normal, $this->raiz)) {
            throw new RuntimeException('Se rechazó un destino fuera de la carpeta raíz: ' . $ruta);
        }
    }

    private function esRutaDentro($ruta, $raiz)
    {
        $ruta = rtrim($this->normalizarRuta($ruta), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $raiz = rtrim($this->normalizarRuta($raiz), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($ruta, $raiz, strlen($raiz)) === 0
            : strncmp($ruta, $raiz, strlen($raiz)) === 0;
    }

    private function normalizarRuta($ruta)
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $ruta);
    }

    private function claveRuta($ruta)
    {
        $ruta = $this->normalizarRuta($ruta);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($ruta) : $ruta;
    }

    private function mismaRuta($a, $b)
    {
        return $this->claveRuta($a) === $this->claveRuta($b);
    }

    private function resumenBase($dryRun)
    {
        return [
            'dry_run' => $dryRun,
            'raiz' => $this->raiz,
            'modo' => 'organizacion_forzada',
            'registrados_revisados' => 0,
            'no_registrados_revisados' => 0,
            'archivos_indexados' => 0,
            'hashes_calculados' => 0,
            'errores_hash' => 0,
            'rutas_vigentes' => 0,
            'rutas_por_actualizar' => 0,
            'rutas_actualizadas' => 0,
            'archivos_relocalizados' => 0,
            'renombres_detectados' => 0,
            'movimientos_manuales_detectados' => 0,
            'archivos_no_encontrados' => 0,
            'contenido_modificado' => 0,
            'revision_omitida_sin_cambios' => false,
            'movimientos_planificados' => 0,
            'movidos' => 0,
            'movidos_no_registrados' => 0,
            'ya_ordenados' => 0,
            'faltantes_en_disco' => 0,
            'fuera_de_raiz' => 0,
            'por_fecha' => 0,
            'pago_semanal' => 0,
            'ignorados' => 0,
            'errores' => 0,
            'omitido_por_bloqueo' => false,
            'operaciones' => [],
        ];
    }

    /** Reparte el movimiento en el contador que le toca según a dónde fue. */
    private function contarEstado(array &$resumen, $estado)
    {
        if ($estado === DocumentoArchivo::CARPETA_PAGOS) $resumen['pago_semanal']++;
        elseif (in_array($estado, ['FE', 'NC', 'ND'], true)) $resumen['por_fecha']++;
        else $resumen['ignorados']++;
    }

    private function agregarOperacion(array &$resumen, array $operacion)
    {
        if (count($resumen['operaciones']) < 200) {
            $resumen['operaciones'][] = $operacion;
        }
    }

    private function registrarLog(array $resumen)
    {
        $linea = json_encode([
            'fecha' => date('Y-m-d H:i:s'),
            'resumen' => array_diff_key($resumen, ['operaciones' => true]),
            'operaciones' => $resumen['operaciones'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($linea)) {
            @file_put_contents($this->log, $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
