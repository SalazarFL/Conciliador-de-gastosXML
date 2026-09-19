<?php
/**
 * Pasa los documentos de UNA empresa de la carpeta compartida a SharePoint.
 *
 * Cinco pasos, cada uno re-ejecutable y cada uno por su cuenta:
 *
 *   inventario  lee la BASE (no el árbol): qué archivos tiene la empresa en
 *               disco, con su SHA-256. Lo que ya no está en disco se anota
 *               como omitido: ya estaba roto, no frena nada.
 *   subir       a la MISMA ruta relativa en la biblioteca (SISTEMA/EMPRESA/…).
 *               Antes de subir se comprueba que el archivo del disco sea el
 *               que la base archivó.
 *   verificar   de a 20 por llamada: tamaño y hash declarado. Con --muestreo
 *               además baja unos cuantos y los rehashea de verdad.
 *   reescribir  pone `almacen` y los ids en cada fila, sin tocar su ruta, y
 *               al final —solo si no queda nada pendiente— cambia el ajuste
 *               de la empresa. Ese orden es la razón de la columna por fila:
 *               mientras dura, cada documento se lee de donde está.
 *   revertir    todo a disco. Instantáneo y sin pérdida porque NADA de esto
 *               borra el original del disco. Lo que se archivó en SharePoint
 *               después del corte no tiene original en disco: hay que pedir
 *               --bajando, y se baja ANTES de tocar ninguna fila.
 *
 * El avance vive en almacen_migracion (ver AlmacenMigracion): se puede cortar
 * en cualquier momento y retomar desde cualquier PC.
 *
 * `_TRABAJO` no se migra: se drena. Lo que esté en tránsito termina su vida
 * donde empezó, porque su ruta dice dónde está.
 */

require_once __DIR__ . '/AlmacenEnGraph.php';
require_once __DIR__ . '/RutaDocumento.php';
require_once __DIR__ . '/../models/AlmacenMigracion.php';
require_once __DIR__ . '/../models/Sociedad.php';

class MigracionGraph
{
    /** SharePoint topa la ruta alrededor de 400 caracteres. */
    public const MAX_RUTA = 400;

    /** Fallos de subida de un archivo antes de dejarlo en 'error'. */
    public const MAX_INTENTOS = 5;

    /** Fallos seguidos que cortan la tanda: la red se cayó, no es el archivo. */
    private const FALLOS_SEGUIDOS = 3;

    private $sociedadId;
    /** @var AlmacenDocumentos */
    private $disco;
    /** @var AlmacenEnGraph */
    private $nube;
    private $registro;
    private $progreso;

    public function __construct($sociedadId, AlmacenDocumentos $disco, AlmacenEnGraph $nube,
                                $registro = null, ?callable $progreso = null)
    {
        $this->sociedadId = (int) $sociedadId;
        if ($this->sociedadId <= 0) {
            throw new InvalidArgumentException('Falta la empresa que se quiere migrar.');
        }
        $this->disco = $disco;
        $this->nube = $nube;
        $this->registro = $registro ?: new AlmacenMigracion();
        $this->progreso = $progreso;
    }

    // ── 1. Inventario ─────────────────────────────────────────────────────

    public function inventario($dryRun = false)
    {
        $r = ['inventariados' => 0, 'omitidos' => 0, 'rutas_largas' => 0, 'ya_en_nube' => 0];
        foreach ($this->registro->documentosEnDisco($this->sociedadId) as $i => $archivo) {
            $ruta = (string) $archivo['ruta'];
            if (AlmacenDocumentos::tipoDeDireccion($ruta) !== '') {
                $r['ya_en_nube']++;   // una ruta con prefijo ya vive en otro almacén
                continue;
            }
            $relativa = RutaDocumento::relativa($ruta);
            $estado = 'inventariado';
            $error = null;
            $tamano = null;
            if (RutaDocumento::esAbsoluta($relativa)) {
                $estado = 'omitido';
                $error = 'Está fuera de la carpeta compartida: no tiene una ruta que llevar a SharePoint.';
            } elseif (($identidad = $this->disco->identidad(RutaDocumento::absoluta($relativa))) === null) {
                $estado = 'omitido';
                $error = 'No está en el disco: ya estaba perdido antes de migrar.';
            } elseif (strlen($relativa) > self::MAX_RUTA) {
                $estado = 'error';
                $error = 'La ruta pasa de ' . self::MAX_RUTA . ' caracteres y SharePoint no la acepta.';
                $r['rutas_largas']++;
            } else {
                $tamano = (int) $identidad['tamano'];
            }
            $r[$estado === 'omitido' ? 'omitidos' : 'inventariados']++;
            if (!$dryRun) {
                $this->registro->inventariar($this->sociedadId, $archivo, $estado, $tamano, $error);
            }
            $this->avisar('inventario', $i + 1);
        }
        return $r;
    }

    // ── 2. Subir ──────────────────────────────────────────────────────────

    public function subir($limite = 0, $dryRun = false)
    {
        $r = ['subidos' => 0, 'ya_estaban' => 0, 'conflictos' => 0, 'fallidos' => 0, 'omitidos' => 0,
              'por_subir' => 0, 'cortado' => ''];
        $seguidos = 0;
        foreach ($this->registro->enEstado($this->sociedadId, 'inventariado', $limite) as $i => $m) {
            if ($dryRun) {
                $r['por_subir']++;
                continue;
            }
            $relativa = RutaDocumento::relativa((string) $m['ruta']);
            $enDisco = RutaDocumento::absoluta($relativa);

            $hash = $this->hashEnDisco($enDisco);
            if ($hash === '') {
                $this->registro->marcar($m['id'], 'omitido', ['error' => 'Ya no está en el disco.']);
                $r['omitidos']++;
                continue;
            }
            if ((string) $m['sha256'] !== '' && !hash_equals((string) $m['sha256'], $hash)) {
                // Subirlo sería llevar a SharePoint algo que la base no
                // reconoce, con el hash de otra cosa. Que lo mire alguien.
                $this->registro->marcar($m['id'], 'conflicto',
                    ['error' => 'El archivo del disco no es el que la base archivó (el SHA-256 no coincide).']);
                $r['conflictos']++;
                continue;
            }

            try {
                $escrito = $this->nube->escribir($relativa, $enDisco, ['hash' => $hash]);
                $this->registro->marcar($m['id'], 'subido', [
                    'item_id' => $escrito['item_id'], 'etag' => $escrito['etag'], 'sha256' => $hash,
                    'tamano' => (int) $escrito['tamano'], 'error' => null,
                ]);
                $r[$escrito['creado'] ? 'subidos' : 'ya_estaban']++;
                $seguidos = 0;
            } catch (AlmacenConflicto $e) {
                $this->registro->marcar($m['id'], 'conflicto',
                    ['error' => 'En SharePoint ya hay otro archivo con ese nombre, con otro contenido.']);
                $r['conflictos']++;
            } catch (Throwable $e) {
                $agotado = (int) $m['intentos'] + 1 >= self::MAX_INTENTOS;
                $this->registro->marcar($m['id'], $agotado ? 'error' : 'inventariado',
                    ['error' => $e->getMessage(), 'intento' => true]);
                $r['fallidos']++;
                if (++$seguidos >= self::FALLOS_SEGUIDOS) {
                    $r['cortado'] = 'Se cortó la tanda tras ' . $seguidos . ' fallos seguidos: ' . $e->getMessage();
                    break;
                }
            }
            $this->avisar('subida', $i + 1);
        }
        return $r;
    }

    // ── 3. Verificar ──────────────────────────────────────────────────────

    public function verificar($limite = 0, $muestreo = 0, $dryRun = false)
    {
        $r = ['verificados' => 0, 'sin_hash_declarado' => 0, 'distintos' => 0, 'a_subir_de_nuevo' => 0,
              'sin_respuesta' => 0, 'muestreo_bien' => 0, 'muestreo_mal' => 0, 'por_verificar' => 0];
        $filas = $this->registro->enEstado($this->sociedadId, 'subido', $limite);
        if ($dryRun) {
            $r['por_verificar'] = count($filas);
            return $r;
        }

        $respuestas = $this->pedirItems($filas);
        foreach ($filas as $m) {
            $resp = $respuestas[(string) $m['id']] ?? null;
            $codigo = (int) ($resp['codigo'] ?? 0);
            if ($codigo === 404) {
                $this->registro->marcar($m['id'], 'inventariado',
                    ['error' => 'SharePoint no lo tiene: se vuelve a subir.', 'intento' => true]);
                $r['a_subir_de_nuevo']++;
                continue;
            }
            if ($codigo !== 200 || !is_array($resp['json'])) {
                $r['sin_respuesta']++;   // un 429 que no alcanzó: la próxima
                continue;
            }
            $json = $resp['json'];
            $declarado = strtolower((string) ($json['listItem']['fields'][$this->nube->columnaHash()] ?? ''));
            $problema = '';
            if ($declarado !== '' && !hash_equals((string) $m['sha256'], $declarado)) {
                $problema = 'El hash declarado en SharePoint no es el del archivo.';
            } elseif ((int) ($json['size'] ?? -1) !== (int) $m['tamano']) {
                $problema = 'El tamaño en SharePoint (' . (int) ($json['size'] ?? 0) . ') no es el del archivo ('
                    . (int) $m['tamano'] . ').';
            }
            if ($problema !== '') {
                $this->registro->marcar($m['id'], 'error', ['error' => $problema]);
                $r['distintos']++;
                continue;
            }
            if ($declarado === '') {
                $r['sin_hash_declarado']++;
            }
            $this->registro->marcar($m['id'], 'verificado',
                ['etag' => (string) ($json['cTag'] ?? $m['etag']), 'error' => null]);
            $r['verificados']++;
        }

        // Lo de arriba es lo que SharePoint DICE. Esto es comprobarlo.
        if ($muestreo > 0) {
            foreach ($this->registro->muestra($this->sociedadId, 'verificado', $muestreo) as $m) {
                $hash = $this->hashDe($this->nube, AlmacenEnGraph::PREFIJO . RutaDocumento::relativa((string) $m['ruta']));
                if ($hash !== '' && hash_equals((string) $m['sha256'], $hash)) {
                    $r['muestreo_bien']++;
                } else {
                    $this->registro->marcar($m['id'], 'error',
                        ['error' => 'Bajado de SharePoint, su SHA-256 no es el del archivo.']);
                    $r['muestreo_mal']++;
                }
            }
        }
        return $r;
    }

    /** De a 20 por llamada. Si la biblioteca no tiene la columna del hash, sin ella. */
    private function pedirItems(array $filas)
    {
        $graph = $this->nube->cliente();
        $conHash = $this->nube->tieneColumnaHash() !== false;
        for ($vuelta = 0; $vuelta < 2; $vuelta++) {
            $peticiones = [];
            foreach ($filas as $m) {
                $peticiones[(string) $m['id']] = ['metodo' => 'GET', 'url' => $graph->unidad() . '/items/'
                    . rawurlencode((string) $m['item_id']) . '?$select=id,size,cTag'
                    . ($conHash ? '&$expand=listItem($expand=fields($select=' . $this->nube->columnaHash() . '))' : '')];
            }
            $respuestas = $peticiones ? $graph->lote($peticiones) : [];
            $rechazoLaColumna = $conHash && array_filter($respuestas, function ($resp) {
                return (int) $resp['codigo'] === 400;
            });
            if (!$rechazoLaColumna) {
                return $respuestas;
            }
            $conHash = false;
        }
        return $respuestas;
    }

    // ── 4. Reescribir y cortar ────────────────────────────────────────────

    public function reescribir($dryRun = false)
    {
        $r = ['filas_pasadas' => 0, 'filas_cambiadas' => 0, 'empresa_cortada' => false, 'falta_para_cortar' => ''];
        if ($dryRun) {
            $r['filas_pasadas'] = count($this->registro->filasListas($this->sociedadId, 1000000));
            return $r;
        }

        while ($filas = $this->registro->filasListas($this->sociedadId, 200)) {
            $this->registro->begin();
            try {
                foreach ($filas as $fila) {
                    $archivos = [];
                    $ids = [];
                    foreach ($this->registro->porFila($this->sociedadId, $fila['tabla'], $fila['fila_id']) as $m) {
                        if ($m['estado'] === 'verificado') {
                            $archivos[$m['campo']] = $m;
                            $ids[] = (int) $m['id'];
                        }
                    }
                    if ($this->registro->pasarFila($fila['tabla'], $fila['fila_id'], $archivos)) {
                        foreach ($ids as $id) {
                            $this->registro->marcar($id, 'reescrito');
                        }
                        $r['filas_pasadas']++;
                    } else {
                        // Entre el inventario y ahora la fila cambió —el
                        // organizador la acomodó, alguien la repuso—: lo
                        // subido ya no es lo que ella dice. Vuelve al
                        // principio; el próximo --inventario toma la ruta nueva.
                        foreach ($ids as $id) {
                            $this->registro->marcar($id, 'inventariado',
                                ['error' => 'La fila cambió desde el inventario: hay que volver a inventariar.']);
                        }
                        $r['filas_cambiadas']++;
                    }
                }
                $this->registro->commit();
            } catch (Throwable $e) {
                $this->registro->rollback();
                throw $e;
            }
            $this->avisar('reescritura', $r['filas_pasadas']);
        }

        $falta = $this->faltaParaCortar();
        if ($falta === '') {
            (new Sociedad())->definirAlmacen($this->sociedadId, AlmacenEnGraph::TIPO);
            $r['empresa_cortada'] = true;
        } else {
            $r['falta_para_cortar'] = $falta;
        }
        return $r;
    }

    /**
     * Por qué todavía no se puede pasar la empresa, o '' si ya se puede.
     *
     * Hace falta que no quede nada a medias y que no haya documentos en disco
     * que el inventario no conozca —los que entraron mientras se migraba—.
     * Lo omitido no frena: ya estaba perdido, y sigue igual de perdido.
     */
    public function faltaParaCortar()
    {
        $pendientes = $this->registro->cuantosPendientes($this->sociedadId);
        $conocidos = [];
        foreach ($this->registro->todos($this->sociedadId) as $m) {
            $conocidos[$m['tabla'] . '|' . $m['fila_id'] . '|' . $m['campo']] = (string) $m['ruta'];
        }
        $nuevos = 0;
        foreach ($this->registro->documentosEnDisco($this->sociedadId) as $a) {
            $clave = $a['tabla'] . '|' . $a['fila_id'] . '|' . $a['campo'];
            if (($conocidos[$clave] ?? null) !== (string) $a['ruta']
                && AlmacenDocumentos::tipoDeDireccion($a['ruta']) === '') {
                $nuevos++;
            }
        }
        $motivos = [];
        if ($pendientes > 0) {
            $motivos[] = $pendientes . ' archivo(s) sin terminar (ver --estado)';
        }
        if ($nuevos > 0) {
            $motivos[] = $nuevos . ' archivo(s) en disco que el inventario no conoce: corre --todo otra vez';
        }
        return implode('; ', $motivos);
    }

    // ── 5. Revertir ───────────────────────────────────────────────────────

    public function revertir($bajando = false, $dryRun = false)
    {
        $r = ['filas' => 0, 'desde_el_original' => 0, 'por_bajar' => 0, 'bajados' => 0,
              'rehusado' => '', 'fallos' => []];

        // Primero el plan entero, sin tocar nada.
        $plan = [];
        foreach ($this->registro->documentosEnNube($this->sociedadId) as $f) {
            $migrados = [];
            foreach ($this->registro->porFila($this->sociedadId, $f['tabla'], $f['id']) as $m) {
                $migrados[$m['campo']] = $m;
            }
            $entrada = ['tabla' => $f['tabla'], 'fila_id' => (int) $f['id'], 'rutas' => [], 'bajar' => []];
            foreach (['ruta_xml', 'ruta_pdf'] as $campo) {
                $ruta = trim((string) ($f[$campo] ?? ''));
                if ($ruta === '') {
                    continue;
                }
                $m = $migrados[$campo] ?? null;
                if ($m && $m['estado'] === 'omitido') {
                    // Ya faltaba antes de migrar: vuelve a su ruta, igual de
                    // faltante. Bajarlo es imposible, nunca subió.
                    $entrada['rutas'][$campo] = (string) $m['ruta'];
                } elseif ($m && $m['estado'] === 'reescrito'
                    && $this->disco->identidad(RutaDocumento::absoluta(RutaDocumento::relativa((string) $m['ruta']))) !== null) {
                    // El original sigue en disco, donde estaba.
                    $entrada['rutas'][$campo] = (string) $m['ruta'];
                    $r['desde_el_original']++;
                } else {
                    // Se archivó en SharePoint después del corte (o a su
                    // original de disco lo borraron): hay que bajarlo.
                    $relativa = RutaDocumento::relativa(preg_replace('/^' . preg_quote(AlmacenEnGraph::PREFIJO, '/') . '/i', '', $ruta));
                    $entrada['bajar'][$campo] = $relativa;
                    $entrada['rutas'][$campo] = $relativa;
                    $r['por_bajar']++;
                }
            }
            $plan[] = $entrada;
        }
        $r['filas'] = count($plan);

        if ($r['por_bajar'] > 0 && !$bajando) {
            $r['rehusado'] = $r['por_bajar'] . ' documento(s) solo existen en SharePoint (se archivaron después del '
                . 'corte). Hay que bajarlos antes: corre --revertir --bajando.';
            return $r;
        }
        if ($dryRun) {
            return $r;
        }

        // Se baja TODO antes de tocar una sola fila: si algo falla, la
        // empresa sigue entera en SharePoint y lo bajado es un sobrante
        // inofensivo en el disco.
        foreach ($plan as $entrada) {
            foreach ($entrada['bajar'] as $campo => $relativa) {
                $local = $this->nube->rutaLocal(AlmacenEnGraph::PREFIJO . $relativa);
                if ($local === null) {
                    $r['fallos'][] = $relativa . ': no se pudo bajar de SharePoint.';
                    continue;
                }
                try {
                    $this->disco->escribir(RutaDocumento::absoluta($relativa), $local);
                    $r['bajados']++;
                } catch (Throwable $e) {
                    $r['fallos'][] = $relativa . ': ' . $e->getMessage();
                } finally {
                    $this->nube->soltarLocal($local);
                }
            }
        }
        if ($r['fallos']) {
            $r['rehusado'] = count($r['fallos']) . ' documento(s) no se pudieron bajar: no se revirtió nada.';
            return $r;
        }

        // Lo nuevo va a disco desde ya; después, fila por fila.
        (new Sociedad())->definirAlmacen($this->sociedadId, AlmacenDocumentos::DISCO);
        $this->registro->begin();
        try {
            foreach ($plan as $entrada) {
                $this->registro->devolverFila($entrada['tabla'], $entrada['fila_id'], $entrada['rutas']);
                $this->registro->marcarRevertidos($this->sociedadId, $entrada['tabla'], $entrada['fila_id']);
            }
            $this->registro->commit();
        } catch (Throwable $e) {
            $this->registro->rollback();
            throw $e;
        }
        return $r;
    }

    // ── Estado ────────────────────────────────────────────────────────────

    public function estado()
    {
        return [
            'empresa_en' => AlmacenDocumentos::tipoDeSociedad($this->sociedadId),
            'archivos' => $this->registro->resumen($this->sociedadId),
            'filas_en_sharepoint' => count($this->registro->documentosEnNube($this->sociedadId)),
            'falta_para_cortar' => $this->faltaParaCortar(),
            'fallidos' => $this->registro->fallidos($this->sociedadId),
        ];
    }

    public function reintentar()
    {
        return $this->registro->reintentar($this->sociedadId);
    }

    // ── Interno ───────────────────────────────────────────────────────────

    private function hashEnDisco($ruta)
    {
        return $this->hashDe($this->disco, $ruta);
    }

    private function hashDe(AlmacenDocumentos $almacen, $ruta)
    {
        $fp = $almacen->abrir($ruta);
        if ($fp === false) {
            return '';
        }
        $contexto = hash_init('sha256');
        hash_update_stream($contexto, $fp);
        fclose($fp);
        return hash_final($contexto);
    }

    private function avisar($paso, $n)
    {
        if ($this->progreso && $n % 100 === 0) {
            ($this->progreso)($paso, $n);
        }
    }
}
