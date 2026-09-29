<?php
/**
 * Mantiene la base al día con lo que la gente hace en la biblioteca de
 * SharePoint desde el navegador.
 *
 * En disco eso lo hace OrganizadorDocumentos recorriendo el árbol y
 * recalculando hashes. Contra SharePoint ese camino es impagable —cada archivo
 * sería una descarga— y además innecesario: cada documento tiene un id que no
 * cambia aunque lo muevan o le cambien el nombre, y SharePoint dice qué cambió
 * desde la última vez que se preguntó (delta).
 *
 * ── Qué hace en cada vuelta ──
 *
 *  1. Lee los cambios de la biblioteca desde la última vez. Un delta vacío es
 *     UNA llamada: más barato que el recorrido del disco.
 *  2. Por cada documento conocido que cambió, lo pide por su id (de a 20 por
 *     llamada) y decide:
 *       - está en otra ruta   → alguien lo movió: se corrige la fila;
 *       - no está             → lo borraron: se marca sin archivo, y el
 *                               flujo de reponer desde el correo se hace cargo;
 *       - volvió              → lo restauraron de la papelera: se quita la marca;
 *       - cambió su contenido → se baja, se compara con el hash de la base y,
 *                               si no coincide, se avisa.
 *  3. Una carpeta que cambió y bajo la cual la base no tiene nada —la
 *     renombraron o la movieron— se recorre, y lo que tenga dentro se sigue.
 *  4. Revisión rotativa: además repasa por id unos cientos de filas en cada
 *     vuelta, empezando donde terminó la anterior. Es la red de seguridad de
 *     todo lo que el delta no cuente (una carpeta borrada entera, un enlace
 *     de cambios que SharePoint dio por vencido). En unas horas pasa por toda
 *     la biblioteca, y nunca baja un archivo para eso: solo lista.
 *  5. Arma las copias de la carpeta del pago de los documentos que están en
 *     SharePoint. Ver AlmacenCopiaPago.
 *
 * Lo que NO hace: archivar lo que alguien dejó en la biblioteca por su cuenta.
 * Tampoco lo hace el organizador en la tarea programada: lo que no está en la
 * base se queda donde lo pusieron.
 *
 * ── Con Nexo Fiscal en una carpeta ──
 *
 * Si Nexo Fiscal vive en una carpeta de una biblioteca que ya se usaba (ver
 * ClienteGraph::carpeta()), los cambios se piden sobre esa carpeta y no sobre
 * la biblioteca, y las rutas que da SharePoint —siempre desde la raíz— se
 * pasan a relativas a la carpeta. Un documento que alguien sacó de la carpeta
 * cuenta como borrado: para Nexo Fiscal ya no está, y si lo devuelven, la
 * próxima vuelta lo encuentra por su id.
 *
 * SharePoint no siempre da el enlace de cambios de una carpeta —con permiso
 * solo sobre ella, o en bibliotecas donde solo lo da de la raíz—. Entonces se
 * sigue sin él: la revisión rotativa pasa a repasar más filas por vuelta y es
 * la que se entera de todo, algo más tarde. Se vuelve a probar cada seis horas.
 *
 * Corre con un candado en la base: varias PC tienen la tarea programada y el
 * enlace de cambios es uno solo.
 */

require_once __DIR__ . '/AlmacenEnGraph.php';
require_once __DIR__ . '/DocumentoArchivo.php';
require_once __DIR__ . '/../models/Factura.php';
require_once __DIR__ . '/../models/BloqueoEnBase.php';
require_once __DIR__ . '/../models/AlmacenDelta.php';
require_once __DIR__ . '/../models/AlmacenCopiaPago.php';

class ReconciliadorGraph
{
    /** Filas que se repasan por id en cada vuelta: diez llamadas en lote. */
    public const LOTE_REVISION = 100;

    /**
     * Sin enlace de cambios, la rotativa es la única que se entera de lo que
     * pasa en la biblioteca: repasa más por vuelta —treinta llamadas en lote,
     * unos quince segundos— para dar la vuelta entera en pocas horas.
     */
    public const LOTE_REVISION_SIN_CAMBIOS = 300;

    /** Cada cuánto se vuelve a probar si SharePoint da el enlace de cambios. */
    private const REPROBAR_CAMBIOS = 21600;

    /** Páginas de cambios por vuelta. Lo que sobre queda para la siguiente. */
    public const MAX_PAGINAS_DELTA = 50;

    /** Tope de lo que se recorre bajo una carpeta movida, por vuelta. */
    public const MAX_HIJOS = 5000;

    /**
     * Copias de pago por vuelta. Al pasar una empresa a SharePoint, todos sus
     * documentos que ya estaban en un pago necesitan su copia allá: pueden
     * ser miles, y hacerlos de una vez tendría a la tarea colgada horas. Se
     * hacen de a tantos; el resto, en las vueltas siguientes.
     */
    public const MAX_COPIAS_POR_VUELTA = 100;

    private const BLOQUEO = 'reconciliador_sharepoint';

    private const CAMPOS = '?$select=id,name,cTag,eTag,size,parentReference,file,folder';

    /** Carpetas de la biblioteca que no guardan documentos del sistema. */
    private const FUERA = [RutaDocumento::TRABAJO, DocumentoArchivo::CARPETA_PAGOS, DocumentoArchivo::CARPETA_PAGOS_VIEJA];

    /** @var AlmacenEnGraph */
    private $almacen;
    /** @var ClienteGraph */
    private $graph;
    private $facturas;
    private $delta;
    private $copias;
    private $log;

    /** Las filas ya revisadas en esta vuelta: la rotativa no las repite. */
    private $revisadas = [];

    /**
     * La ruta de la carpeta de Nexo Fiscal desde la raíz de la biblioteca,
     * como la tiene SharePoint en esta vuelta ('' = la biblioteca entera;
     * null = la de la configuración, porque no se pudo preguntar).
     */
    private $rutaCarpeta = null;

    /** El id de la carpeta, para no tratarla como un cambio más. */
    private $idCarpeta = '';

    public function __construct(AlmacenEnGraph $almacen, $facturas = null, $delta = null, $copias = null)
    {
        $this->almacen = $almacen;
        $this->graph = $almacen->cliente();
        $this->facturas = $facturas ?: new Factura();
        if (method_exists($this->facturas, 'setSociedad')) {
            $this->facturas->setSociedad(0);   // todas las empresas: corre sin nadie delante
        }
        $this->delta = $delta ?: new AlmacenDelta();
        $this->copias = $copias ?: new AlmacenCopiaPago();
        $this->log = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . 'logs' . DIRECTORY_SEPARATOR . 'reconciliador_sharepoint.log';
    }

    /**
     * ¿Hay algo que vigilar? Siempre que esta computadora llegue a
     * SharePoint: todas las empresas archivan ahí. Sin credenciales no hay
     * con qué preguntar, y eso lo dice Diagnóstico, no la tarea.
     */
    public static function hayQueCorrer()
    {
        return AlmacenDocumentos::disponible(AlmacenEnGraph::TIPO);
    }

    public function ejecutar($dryRun = false)
    {
        $dryRun = (bool) $dryRun;
        $r = [
            'dry_run' => $dryRun,
            'cambios_leidos' => 0,
            'delta_iniciado' => false,
            'delta_reiniciado' => false,
            'sin_enlace_de_cambios' => false,
            'carpetas_recorridas' => 0,
            'documentos_revisados' => 0,
            'rutas_actualizadas' => 0,
            'ids_adoptados' => 0,
            'marcados_sin_archivo' => 0,
            'desmarcados' => 0,
            'editados' => 0,
            'sin_respuesta' => 0,
            'vuelta_completa' => false,
            'copias_pago' => 0,
            'copias_pago_vigentes' => 0,
            'copias_pago_planificadas' => 0,
            'sin_par_completo' => 0,
            'copias_pago_para_despues' => 0,
            'errores' => 0,
            'errores_copia_pago' => 0,
            'operaciones' => [],
        ];

        $this->revisadas = [];
        $candado = BloqueoEnBase::tomar(self::BLOQUEO);
        if ($candado === false) {
            $r['omitido_por_bloqueo'] = true;
            return $r;
        }
        try {
            // El enlace de cambios vale para una biblioteca y una carpeta: si
            // cambia cualquiera de las dos, se empieza de nuevo.
            $alcance = $this->graph->alcance();
            $estado = $this->delta->leer(AlmacenEnGraph::TIPO, $alcance);
            $this->conocerLaCarpeta();

            // 1-3. Lo que cambió.
            $cambios = $this->leerCambios((string) $estado['delta_link'], $r);
            if ($cambios['reiniciar_revision']) {
                $estado['cursor_revision'] = 0;
            }
            $ids = $cambios['archivos'] + $this->idsBajoCarpetas($cambios['carpetas'], $r);
            if ($ids) {
                $this->revisarFilas($this->facturas->getPorItems(array_keys($ids)), $dryRun, $r);
            }

            // 4. La revisión rotativa.
            $lote = $r['sin_enlace_de_cambios'] ? self::LOTE_REVISION_SIN_CAMBIOS : self::LOTE_REVISION;
            $filas = $this->facturas->getEnAlmacen(AlmacenEnGraph::TIPO, (int) $estado['cursor_revision'], $lote);
            $this->revisarFilas($filas, $dryRun, $r);
            $cursor = count($filas) < $lote ? 0 : (int) end($filas)['id'];
            $r['vuelta_completa'] = $cursor === 0;

            // 5. Las copias de pago.
            $this->copiasDePago($dryRun, $r);

            if (!$dryRun) {
                $this->delta->guardar(AlmacenEnGraph::TIPO, $alcance, $cambios['enlace'], $cursor, $r['vuelta_completa']);
            }
        } finally {
            if ($candado === true) {
                BloqueoEnBase::soltar(self::BLOQUEO);
            }
        }

        if (!$dryRun) {
            $this->registrarLog($r);
        }
        return $r;
    }

    // ── 1. Los cambios ────────────────────────────────────────────────────

    /**
     * @return array archivos (id => true), carpetas (id => true), enlace (el
     *               que se guarda para la próxima) y reiniciar_revision.
     */
    private function leerCambios($enlace, array &$r)
    {
        $salida = ['archivos' => [], 'carpetas' => [], 'enlace' => $enlace, 'reiniciar_revision' => false];

        // SharePoint ya dijo que no da el enlace de cambios de este alcance:
        // no se pregunta en cada vuelta, solo cada tanto.
        if ($this->graph->recordado('sin_cambios_' . $this->claveAlcance(), self::REPROBAR_CAMBIOS) === true) {
            $r['sin_enlace_de_cambios'] = true;
            return $salida;
        }

        if ($enlace === '') {
            // La primera vez se empieza desde ahora, no desde el principio: la
            // enumeración inicial sería la biblioteca entera, y lo que ya
            // estaba lo cubre la revisión rotativa.
            $nuevo = $this->enlaceDesdeAhora();
            if ($nuevo === null) {
                $r['sin_enlace_de_cambios'] = true;
                return $salida;
            }
            $salida['enlace'] = $nuevo;
            $r['delta_iniciado'] = true;
            return $salida;
        }

        $url = $enlace;
        for ($pagina = 0; $url !== '' && $pagina < self::MAX_PAGINAS_DELTA; $pagina++) {
            $resp = $this->graph->pedir('GET', $url, ['aceptar' => [410]]);
            if ($resp['codigo'] === 410) {
                // SharePoint dio el enlace por vencido (resyncRequired). Lo
                // que pasó entretanto no se sabe: se vuelve a empezar desde
                // ahora y la revisión rotativa arranca de nuevo, desde el
                // principio, para no perderse nada.
                $r['delta_reiniciado'] = true;
                $nuevo = $this->enlaceDesdeAhora();
                $r['sin_enlace_de_cambios'] = $nuevo === null;
                return ['archivos' => [], 'carpetas' => [], 'enlace' => (string) $nuevo,
                        'reiniciar_revision' => true];
            }
            foreach (($resp['json']['value'] ?? []) as $item) {
                $id = (string) ($item['id'] ?? '');
                // La raíz, o la carpeta de Nexo Fiscal misma: cambia cada vez
                // que cambia algo adentro, y lo de adentro ya viene en la lista.
                if ($id === '' || isset($item['root']) || ($this->idCarpeta !== '' && $id === $this->idCarpeta)) {
                    continue;
                }
                $r['cambios_leidos']++;
                if (isset($item['folder']) && !isset($item['deleted'])) {
                    $salida['carpetas'][$id] = true;
                } else {
                    $salida['archivos'][$id] = true;
                }
            }
            $siguiente = (string) ($resp['json']['@odata.nextLink'] ?? '');
            $final = (string) ($resp['json']['@odata.deltaLink'] ?? '');
            // Si se corta por el tope, se guarda la página siguiente: el
            // enlace de la página también sirve para retomar.
            $salida['enlace'] = $final !== '' ? $final : ($siguiente !== '' ? $siguiente : $salida['enlace']);
            $url = $final !== '' ? '' : $siguiente;
        }
        return $salida;
    }

    /**
     * El enlace de cambios desde ahora, de la biblioteca o de la carpeta de
     * Nexo Fiscal. null si SharePoint no lo da para ese alcance: el de una
     * carpeta no siempre existe (contesta 400 o 501), y con permiso solo
     * sobre la carpeta el de la biblioteca tampoco (403). Un fallo de red, en
     * cambio, sube: no es un "no", es un "ahora no".
     */
    private function enlaceDesdeAhora()
    {
        try {
            $resp = $this->graph->pedir('GET', $this->graph->raizApi() . '/delta?token=latest');
        } catch (ErrorGraph $e) {
            if (!in_array($e->codigoHttp(), [400, 403, 404, 405, 501], true)) {
                throw $e;
            }
            $this->graph->recordar('sin_cambios_' . $this->claveAlcance(), true);
            return null;
        }
        $enlace = (string) ($resp['json']['@odata.deltaLink'] ?? '');
        if ($enlace === '') {
            throw new RuntimeException('SharePoint no devolvió el enlace de cambios de la biblioteca.');
        }
        $this->graph->recordar('sin_cambios_' . $this->claveAlcance(), null);
        return $enlace;
    }

    /** Lo que se recuerda del enlace de cambios vale para una carpeta, no para otra. */
    private function claveAlcance()
    {
        return substr(md5($this->graph->alcance()), 0, 12);
    }

    /**
     * Averigua, una vez por vuelta, dónde está hoy la carpeta de Nexo
     * Fiscal. Las rutas que devuelve SharePoint son desde la raíz de la
     * biblioteca, y para pasarlas a relativas hay que saber qué quitarles: si
     * alguien renombró la carpeta, la ruta de la configuración ya no sirve y
     * todo parecería fuera de ella.
     */
    private function conocerLaCarpeta()
    {
        $this->rutaCarpeta = null;
        $this->idCarpeta = '';
        if ($this->graph->carpeta() === '') {
            $this->rutaCarpeta = '';
            return;
        }
        $this->idCarpeta = $this->graph->idCarpeta();
        $this->rutaCarpeta = $this->almacen->rutaActualDeLaCarpeta();
    }

    /**
     * Las carpetas que cambiaron. Una que se creó al archivar ya tiene
     * documentos en la base bajo su ruta, y no hay nada que hacer. Una bajo
     * la cual la base no tiene nada fue renombrada o movida con todo lo de
     * dentro —SharePoint avisa de la carpeta, no de cada hijo—: se recorre y
     * se devuelven los ids de lo que contenga.
     */
    private function idsBajoCarpetas(array $carpetas, array &$r)
    {
        if (!$carpetas) {
            return [];
        }
        $peticiones = [];
        foreach (array_keys($carpetas) as $id) {
            $peticiones[$id] = ['metodo' => 'GET', 'url' => $this->urlItem($id)];
        }
        $ids = [];
        $presupuesto = self::MAX_HIJOS;
        foreach ($this->graph->lote($peticiones) as $resp) {
            if ((int) $resp['codigo'] !== 200 || !is_array($resp['json'])) {
                continue;
            }
            $ruta = $this->rutaDe($resp['json']);
            if (!is_string($ruta) || $this->fueraDelArchivo($ruta)
                || $this->facturas->hayEnCarpeta(AlmacenEnGraph::TIPO, AlmacenEnGraph::PREFIJO . $ruta)) {
                continue;
            }
            $r['carpetas_recorridas']++;
            $pendientes = [$ruta];
            while ($pendientes && $presupuesto > 0) {
                $carpeta = array_shift($pendientes);
                foreach ($this->almacen->listarCarpeta(AlmacenEnGraph::PREFIJO . $carpeta) as $hijo) {
                    if (--$presupuesto < 0) {
                        break;
                    }
                    if ($hijo['es_carpeta']) {
                        $pendientes[] = $carpeta . '/' . $hijo['nombre'];
                    } else {
                        $ids[$hijo['item_id']] = true;
                    }
                }
            }
        }
        return $ids;
    }

    // ── 2 y 4. Revisar documentos por su id ──────────────────────────────

    /**
     * Pide a SharePoint cada documento de estas filas por su id y pone la
     * base al día. Las dos mitades del par van juntas: la marca "sin
     * archivo" es de la fila, y solo se quita si están las dos.
     */
    private function revisarFilas(array $filas, $dryRun, array &$r)
    {
        $filas = array_values(array_filter($filas, function ($fila) {
            return !isset($this->revisadas[(int) $fila['id']]);
        }));
        $peticiones = [];
        foreach ($filas as $fila) {
            $this->revisadas[(int) $fila['id']] = true;
            foreach (['xml', 'pdf'] as $ext) {
                $ruta = trim((string) ($fila['ruta_' . $ext] ?? ''));
                if (AlmacenDocumentos::tipoDeDireccion($ruta) !== AlmacenEnGraph::TIPO) {
                    continue;
                }
                $itemId = trim((string) ($fila['item_' . $ext . '_id'] ?? ''));
                // Sin id —una fila vieja, un PDF que llegó después—, por su
                // ruta; y si está, se adopta el id para la próxima.
                $url = $itemId !== ''
                    ? $this->urlItem($itemId)
                    : $this->graph->rutaItem(substr($ruta, strlen(AlmacenEnGraph::PREFIJO))) . self::CAMPOS;
                $peticiones[(int) $fila['id'] . ':' . $ext] = ['metodo' => 'GET', 'url' => $url];
            }
        }
        if (!$peticiones) {
            return;
        }
        $respuestas = $this->graph->lote($peticiones);

        $faltan = [];
        $estan = [];
        foreach ($filas as $fila) {
            $id = (int) $fila['id'];
            $pedidas = 0;
            $falta = false;
            $duda = false;
            foreach (['xml', 'pdf'] as $ext) {
                $clave = $id . ':' . $ext;
                if (!isset($peticiones[$clave])) {
                    continue;
                }
                $pedidas++;
                $resp = $respuestas[$clave] ?? null;
                $codigo = (int) ($resp['codigo'] ?? 0);
                if ($codigo === 404) {
                    $falta = true;
                    continue;
                }
                $ruta = $codigo === 200 && is_array($resp['json']) ? $this->rutaDe($resp['json']) : null;
                if ($ruta === false) {
                    // Lo sacaron de la carpeta de Nexo Fiscal: para el
                    // sistema es como si lo hubieran borrado. Si lo devuelven,
                    // la próxima vuelta lo encuentra por su id y lo desmarca.
                    $falta = true;
                    continue;
                }
                if ($ruta === null) {
                    // Sin respuesta clara —un 429 que no alcanzó, un 503— no
                    // se decide nada: la próxima vuelta lo vuelve a pedir.
                    $duda = true;
                    $r['sin_respuesta']++;
                    continue;
                }
                try {
                    $this->seguir($fila, $ext, $resp['json'], AlmacenEnGraph::PREFIJO . $ruta, $dryRun, $r);
                } catch (Throwable $e) {
                    $r['errores']++;
                    $this->operacion($r, ['documento_id' => $id, 'error' => $e->getMessage()]);
                }
            }
            if ($pedidas === 0) {
                continue;
            }
            $r['documentos_revisados']++;
            if ($falta) {
                $faltan[] = $id;
                if (empty($fila['archivo_faltante_en'])) {
                    $r['marcados_sin_archivo']++;
                    $this->operacion($r, ['documento_id' => $id, 'accion' => 'sin_archivo']);
                }
            } elseif (!$duda) {
                $estan[] = $id;
                if (!empty($fila['archivo_faltante_en'])) {
                    $r['desmarcados']++;
                    $this->operacion($r, ['documento_id' => $id, 'accion' => 'volvio']);
                }
            }
        }
        if (!$dryRun && ($faltan || $estan)) {
            $this->facturas->marcarFaltantesEnAlmacen($faltan, $estan);
        }
    }

    /** Lo que SharePoint dice de un documento, contra lo que dice la fila. */
    private function seguir(array $fila, $ext, array $item, $rutaActual, $dryRun, array &$r)
    {
        $id = (int) $fila['id'];
        $rutaFila = (string) ($fila['ruta_' . $ext] ?? '');
        $itemFila = (string) ($fila['item_' . $ext . '_id'] ?? '');
        $etagFila = (string) ($fila['etag_' . $ext] ?? '');
        $itemId = (string) ($item['id'] ?? '');
        $etag = (string) ($item['cTag'] ?? ($item['eTag'] ?? ''));

        $movido = !$this->mismaRuta($rutaFila, $rutaActual);
        $adoptado = $itemFila === '' && $itemId !== '';
        $otroEtag = $etag !== '' && $etag !== $etagFila;

        if ($movido) {
            $r['rutas_actualizadas']++;
            $this->operacion($r, ['documento_id' => $id, 'accion' => 'movido', 'de' => $rutaFila, 'a' => $rutaActual]);
        }
        if ($adoptado) {
            $r['ids_adoptados']++;
        }
        // Otro cTag es otro contenido. Casi siempre es falso aviso —SharePoint
        // también lo cambia al tocar las columnas—, así que se comprueba
        // contra el hash antes de decir nada. Es la única descarga del
        // reconciliador, y pasa una vez por cambio.
        if ($otroEtag && $etagFila !== '') {
            $hashFila = strtolower(trim((string) ($fila['hash_' . $ext] ?? '')));
            $hashActual = $this->hashDe($rutaActual);
            if ($hashFila !== '' && $hashActual !== '' && !hash_equals($hashFila, $hashActual)) {
                $r['editados']++;
                $this->operacion($r, ['documento_id' => $id, 'accion' => 'contenido_distinto', 'ruta' => $rutaActual,
                    'aviso' => 'El ' . strtoupper($ext) . ' en SharePoint ya no es el que se archivó: alguien lo editó o lo reemplazó.']);
            }
        }
        if (($movido || $adoptado || $otroEtag) && !$dryRun) {
            $this->facturas->seguirEnAlmacen($id, $ext, $rutaActual, $itemId, $etag);
        }
    }

    private function hashDe($ruta)
    {
        $fp = $this->almacen->abrir($ruta);
        if ($fp === false) {
            return '';
        }
        $contexto = hash_init('sha256');
        hash_update_stream($contexto, $fp);
        fclose($fp);
        return hash_final($contexto);
    }

    // ── 5. Las copias de pago ────────────────────────────────────────────

    private function copiasDePago($dryRun, array &$r)
    {
        $filas = array_filter(
            $this->facturas->getParaOrganizarArchivos([], AlmacenEnGraph::TIPO, true),
            function ($f) { return !empty($f['en_pago']) && trim((string) ($f['carpeta_pago'] ?? '')) !== ''; }
        );
        if (!$filas) {
            return;
        }
        $hechas = $this->copias->firmas(array_column($filas, 'id'));
        $archivo = null;

        foreach ($filas as $fila) {
            $id = (int) $fila['id'];
            $xml = trim((string) ($fila['ruta_xml'] ?? ''));
            $pdf = trim((string) ($fila['ruta_pdf'] ?? ''));
            if ($xml === '' || $pdf === '' || !empty($fila['archivo_faltante_en'])) {
                $r['sin_par_completo']++;
                continue;
            }
            // Todo lo que decide la copia: dónde va, cómo se llama y qué se
            // copia. El original por su id y su cTag, no por su ruta: que
            // alguien lo cambie de carpeta no cambia lo que hay que entregar.
            $firma = sha1(json_encode([
                $fila['carpeta_pago'], $fila['proveedor_nombre'] ?? '', $fila['numero_factura_asistente'] ?? '',
                $fila['fecha_emision'] ?? '', $fila['tipo_documento'] ?? '',
                $fila['item_xml_id'] ?? '', $fila['item_pdf_id'] ?? '', $fila['etag_xml'] ?? '', $fila['etag_pdf'] ?? '',
            ]));
            if (($hechas[$id] ?? '') === $firma) {
                $r['copias_pago_vigentes']++;
                continue;
            }
            if ($dryRun) {
                $r['copias_pago_planificadas']++;
                continue;
            }
            if ($r['copias_pago'] + $r['errores_copia_pago'] >= self::MAX_COPIAS_POR_VUELTA) {
                $r['copias_pago_para_despues']++;
                continue;
            }
            try {
                $archivo = $archivo ?: DocumentoArchivo::sobreAlmacen($this->almacen);
                $copia = $archivo->copiarAPago($xml, $pdf, $fila, (string) $fila['carpeta_pago'],
                    (string) ($fila['proveedor_nombre'] ?? 'PROVEEDOR'));
                if ($copia['ruta_xml'] === '' || $copia['ruta_pdf'] === '') {
                    // El original no contestó: sin anotar, se reintenta.
                    $r['sin_par_completo']++;
                    continue;
                }
                $this->copias->anotar($id, $firma, $copia['ruta_xml'], $copia['ruta_pdf']);
                if ($copia['copiados'] > 0) {
                    $r['copias_pago']++;
                    $this->operacion($r, ['documento_id' => $id, 'accion' => 'copia_pago', 'xml' => $copia['ruta_xml']]);
                } else {
                    $r['copias_pago_vigentes']++;
                }
            } catch (Throwable $e) {
                $r['errores_copia_pago']++;
                $this->operacion($r, ['documento_id' => $id, 'accion' => 'copia_pago', 'error' => $e->getMessage()]);
            }
        }
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function urlItem($id)
    {
        return $this->graph->unidad() . '/items/' . rawurlencode((string) $id) . self::CAMPOS;
    }

    /**
     * La ruta de un driveItem pedido por id, relativa a donde vive Nexo
     * Fiscal —la biblioteca o su carpeta—.
     *
     * SharePoint la da en parentReference.path como
     * '/drives/{id}/root:/SISTEMA/EMPRESA', desde la raíz de la biblioteca y
     * con cada tramo codificado.
     *
     * @return string|false|null La ruta; false si el documento está fuera de
     *         la carpeta de Nexo Fiscal; null si la respuesta no la trae.
     */
    private function rutaDe(array $item)
    {
        $padre = $item['parentReference']['path'] ?? null;
        $nombre = (string) ($item['name'] ?? '');
        if (!is_string($padre) || $nombre === '') {
            return null;
        }
        $pos = strpos($padre, 'root:');
        if ($pos === false) {
            return null;
        }
        $carpeta = trim(rawurldecode(substr($padre, $pos + 5)), '/');
        $relativa = $this->graph->relativaALaCarpeta(ltrim($carpeta . '/' . $nombre, '/'), $this->rutaCarpeta);

        return $relativa === null || $relativa === '' ? false : $relativa;
    }

    private function fueraDelArchivo($ruta)
    {
        $primera = strtok(str_replace('\\', '/', (string) $ruta), '/');
        foreach (self::FUERA as $carpeta) {
            if (strcasecmp((string) $primera, $carpeta) === 0) {
                return true;
            }
        }
        return false;
    }

    /** SharePoint no distingue mayúsculas en los nombres; la base tampoco debería. */
    private function mismaRuta($a, $b)
    {
        $normal = function ($ruta) {
            $ruta = (string) $ruta;
            if (stripos($ruta, AlmacenEnGraph::PREFIJO) === 0) {
                $ruta = substr($ruta, strlen(AlmacenEnGraph::PREFIJO));
            }
            return mb_strtolower(trim(str_replace('\\', '/', $ruta), '/'), 'UTF-8');
        };
        return $normal($a) === $normal($b);
    }

    private function operacion(array &$r, array $operacion)
    {
        // Como el organizador: la lista es para leerla, no un volcado.
        if (count($r['operaciones']) < 200) {
            $r['operaciones'][] = $operacion;
        }
    }

    private function registrarLog(array $r)
    {
        $hubo = $r['rutas_actualizadas'] + $r['marcados_sin_archivo'] + $r['desmarcados'] + $r['editados']
            + $r['copias_pago'] + $r['errores'] + $r['errores_copia_pago'] + (int) $r['delta_reiniciado'];
        if ($hubo === 0) {
            return;   // una vuelta sin novedades no es noticia
        }
        $dir = dirname($this->log);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $linea = date('Y-m-d H:i:s') . ' ' . json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->log, $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
