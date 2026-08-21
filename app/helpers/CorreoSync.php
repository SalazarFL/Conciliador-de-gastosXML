<?php
/**
 * Núcleo de sincronización del índice local del buzón.
 *
 * Se extrajo de CorreoController para que lo compartan dos disparadores:
 *   1. El navegador (AJAX en tandas de ~20s mientras el módulo está abierto).
 *   2. La tarea programada de Windows (cli/sync_correo.php), que lo mantiene
 *      al día aunque el módulo esté cerrado.
 *
 * Actualiza el índice carpeta por carpeta hasta agotar el presupuesto de
 * tiempo. Carpeta sin cambios = 1 solo viaje (STATUS); con correo nuevo = 1
 * viaje extra por los encabezados nuevos; renumerada o con movidos/eliminados
 * = reindexado de esa carpeta. Se procesan primero las nunca sincronizadas y
 * las más viejas; las revisadas hace poco se saltan (ya están al día en esta
 * pasada). La fase 2 rellena los nombres de adjuntos y destinatarios CC
 * por tandas.
 *
 * Las dos fases reparten el presupuesto: las carpetas nunca se llevan toda la
 * tanda. Antes la fase 2 solo corría cuando la vuelta por las carpetas
 * terminaba dentro de la misma tanda, y en un buzón de 150 carpetas eso
 * nunca pasaba (solo el STATUS de todas cuesta ~25 s): la cola de metadatos
 * se quedaba parada días enteros mientras el navegador seguía pidiendo
 * tandas sin fin.
 *
 * Devuelve stats con 'completado' = false cuando quedan carpetas o metadatos
 * pendientes, para que el disparador vuelva a llamar hasta terminar.
 */

require_once __DIR__ . '/MailFetcher.php';

class CorreoSync
{
    /**
     * Archivo de lock que serializa TODA sincronización (navegador y CLI).
     * Es el mismo que usa cli/sync_correo.php: nunca corren dos
     * sincronizaciones a la vez contra el buzón ni escriben doble el índice.
     */
    public static function rutaLock()
    {
        return MailFetcher::storagePath() . DIRECTORY_SEPARATOR . 'sync_auto.lock';
    }

    /**
     * Intenta tomar el lock sin bloquear. Devuelve el handle (hay que
     * conservarlo mientras dura la corrida y soltarlo con liberarLock)
     * o null si otra sincronización está en curso.
     */
    public static function adquirirLock()
    {
        $fp = @fopen(self::rutaLock(), 'c');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        return $fp;
    }

    public static function liberarLock($fp)
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @param MailFetcher|null $fetcherCompartido Conexión IMAP a reutilizar
     *        entre tandas (la usa el CLI para no pagar el saludo TLS cada
     *        12 s). Si se pasa, el que llama es responsable de cerrarla.
     */
    public static function ejecutar(array $config, $indice, $presupuestoSegundos = 20, $fetcherCompartido = null)
    {
        $inicio = microtime(true);

        $fetcherPropio = !($fetcherCompartido instanceof MailFetcher);
        $fetcher = $fetcherPropio ? new MailFetcher($config) : $fetcherCompartido;
        if ($fetcherPropio || !$fetcher->estaConectado()) {
            $fetcher->conectar();
        }

        $stats = [
            'carpetas' => 0, 'nuevos' => 0, 'reindexadas' => 0,
            'podados' => 0, 'capturas_detectadas' => 0,
            'restantes' => 0, 'carpetas_totales' => 0, 'carpetas_por_revisar' => 0,
            'metadatos_resueltos' => 0, 'metadatos_pendientes' => 0,
            'completado' => true,
        ];

        // Reparto del presupuesto: las carpetas se llevan como mucho esta
        // parte de la tanda y el resto queda garantizado para la cola de
        // metadatos, que si no se queda sin turno para siempre. La primera
        // carpeta siempre entra (el reloj se mira antes de cada una), así
        // que ninguna de las dos fases se queda en cero.
        $presupuestoCarpetas = $presupuestoSegundos * 0.6;

        try {
            // El índice es una caché buscable, no el archivo documental. Una
            // retención finita evita que crezca para siempre; timestamp=0 se
            // conserva porque no hay una fecha fiable con la cual podarlo.
            $retencionDias = max(0, (int) ($config['indice_retencion_dias'] ?? 0));
            $capturaAutomatica = !empty($config['captura_automatica']);
            $capturaActivadaEn = trim((string) ($config['captura_activada_en'] ?? ''));
            $corteRetencion = $retencionDias > 0
                ? (int) strtotime('-' . $retencionDias . ' days', strtotime(date('Y-m-d 00:00:00')))
                : 0;
            if ($corteRetencion > 0) {
                // Una tanda pequeña mantiene el costo predecible. El CLI de
                // mantenimiento recorre todas las tandas al cambiar la política.
                $stats['podados'] = $indice->podarAntesDe($corteRetencion, 1000);
            }

            $carpetas = $fetcher->carpetasABuscar();
            $estados = $indice->getCarpetas();

            // Nunca sincronizadas primero, luego de la más vieja a la más nueva
            $edad = function ($carpeta) use ($estados) {
                $registro = $estados[$carpeta] ?? null;
                return ($registro && $registro['edad_sync'] !== null)
                    ? (int) $registro['edad_sync']
                    : PHP_INT_MAX;
            };
            usort($carpetas, function ($a, $b) use ($edad) {
                return $edad($b) <=> $edad($a);
            });

            // Carpetas que toca revisar en esta pasada. Una pasada completa
            // por un buzón grande no cabe en una sola tanda, así que la
            // ventana de frescura tiene que ser bastante más larga que lo que
            // tarda la vuelta entera: con 2 minutos, las primeras carpetas ya
            // estaban "viejas" antes de llegar a las últimas y cada tanda
            // volvía a empezar por el principio sin terminar nunca.
            // El correo nuevo llega a la carpeta base, y esa sí se vigila
            // seguido para que la bandeja no se vea atrasada.
            $carpetaBase = trim((string) ($config['carpeta'] ?? 'INBOX'));
            $porRevisar = [];
            foreach ($carpetas as $carpeta) {
                $ventana = ($carpeta === $carpetaBase) ? 60 : 300;
                if ($edad($carpeta) < $ventana) {
                    continue;
                }
                $porRevisar[] = $carpeta;
            }
            $stats['carpetas_totales'] = count($carpetas);
            $stats['carpetas_por_revisar'] = count($porRevisar);

            foreach ($porRevisar as $i => $carpeta) {
                $registro = $estados[$carpeta] ?? null;

                if ((microtime(true) - $inicio) > $presupuestoCarpetas) {
                    // Presupuesto agotado: el que llama vuelve a llamar para seguir
                    $stats['restantes'] = count($porRevisar) - $i;
                    $stats['completado'] = false;
                    break;
                }

                $estado = $fetcher->estadoCarpeta($carpeta);
                if ($estado === null) {
                    continue;
                }
                $stats['carpetas']++;

                $nombre = $fetcher->nombreLegibleCarpeta($carpeta);
                $ultimoUid = $registro ? (int) $registro['ultimo_uid'] : 0;
                $omitidosRetencion = $registro ? (int) ($registro['mensajes_omitidos'] ?? 0) : 0;

                $uidvalidityCambio = $registro
                    && (int) $registro['uidvalidity'] !== $estado['uidvalidity'];
                $resync = !$registro
                    || $uidvalidityCambio
                    // Al ampliar la retención (incluso a 0), una reconstrucción
                    // recupera del servidor los encabezados antes omitidos.
                    || (int) ($registro['retencion_dias'] ?? 0) !== $retencionDias;

                if ($resync) {
                    // Carpeta nueva o renumerada por el servidor: completa
                    $filas = $estado['mensajes'] > 0
                        ? $fetcher->overviewCarpeta($carpeta, '1:*')
                        : [];
                    if ($estado['mensajes'] > 0 && empty($filas)) {
                        throw new RuntimeException("IMAP no devolvió encabezados para reconstruir {$nombre}.");
                    }
                    // Una cuenta nueva o un UIDVALIDITY regenerado trae una
                    // fotografía completa. Solo se encolan mensajes fechados
                    // desde la activación, nunca todo el archivo histórico.
                    if ($capturaAutomatica && (!$registro || $uidvalidityCambio)) {
                        $stats['capturas_detectadas'] += self::registrarCapturas(
                            (int) ($config['cuenta_id'] ?? 0),
                            $carpeta,
                            (int) $estado['uidvalidity'],
                            $filas,
                            $capturaActivadaEn
                        );
                    }
                    [$filas, $omitidosRetencion] = self::aplicarRetencion($filas, $corteRetencion);
                    $stats['nuevos'] += $indice->reemplazarCarpeta(
                        $carpeta,
                        $nombre,
                        $estado['uidvalidity'],
                        $filas
                    );
                    $stats['reindexadas']++;
                } elseif ($estado['uidnext'] > $ultimoUid + 1) {
                    // Solo lo nuevo. Nota IMAP: 'n:*' devuelve al menos el
                    // último mensaje aunque n supere su UID; se filtra aquí.
                    $filas = $fetcher->overviewCarpeta($carpeta, ($ultimoUid + 1) . ':*');
                    $filas = array_values(array_filter($filas, function ($f) use ($ultimoUid) {
                        return $f['uid'] > $ultimoUid;
                    }));
                    // Esta rama representa exactamente UIDs que el servidor
                    // acaba de agregar; se encolan aunque su cabecera Date sea
                    // antigua o incorrecta.
                    if ($capturaAutomatica && $filas) {
                        $stats['capturas_detectadas'] += self::registrarCapturas(
                            (int) ($config['cuenta_id'] ?? 0),
                            $carpeta,
                            (int) $estado['uidvalidity'],
                            $filas
                        );
                    }
                    [$filas, $omitidosNuevos] = self::aplicarRetencion($filas, $corteRetencion);
                    $omitidosRetencion += $omitidosNuevos;
                    $stats['nuevos'] += $indice->insertarLote($carpeta, $nombre, $estado['uidvalidity'], $filas);
                }

                // Movidos/eliminados: el total local incluye tanto las filas
                // conservadas como las omitidas deliberadamente por retención.
                if (!$resync
                    && $indice->contarCarpeta($carpeta) + $omitidosRetencion !== $estado['mensajes']) {
                    $filas = $estado['mensajes'] > 0
                        ? $fetcher->overviewCarpeta($carpeta, '1:*')
                        : [];
                    if ($estado['mensajes'] > 0 && empty($filas)) {
                        throw new RuntimeException("IMAP no devolvió encabezados para reconstruir {$nombre}.");
                    }
                    [$filas, $omitidosRetencion] = self::aplicarRetencion($filas, $corteRetencion);
                    $indice->reemplazarCarpeta(
                        $carpeta,
                        $nombre,
                        $estado['uidvalidity'],
                        $filas
                    );
                    $stats['reindexadas']++;
                }

                $indice->guardarEstadoCarpeta(
                    $carpeta,
                    $estado['uidvalidity'],
                    max(0, $estado['uidnext'] - 1),
                    $estado['mensajes'],
                    $omitidosRetencion,
                    $retencionDias
                );
            }

            // ── Fase 2: nombres de adjuntos y destinatarios CC/Reply-To ──
            // Se rellenan en UNA sola pasada (antes eran dos colas separadas:
            // un mensaje con ambos pendientes cambiaba de carpeta y se
            // procesaba dos veces). pendientesMetadatos agrupa por carpeta,
            // así cada carpeta IMAP se abre una vez por tanda y del mensaje
            // se leen estructura y encabezados en el mismo viaje.
            // Corre en TODAS las tandas, queden o no carpetas por revisar:
            // es la única forma de que la cola avance en un buzón con tantas
            // carpetas que la vuelta nunca cabe entera en una tanda.
            $stats['adjuntos'] = 0;
            $stats['cc'] = 0;
            $agotado = false;

            foreach ($indice->pendientesMetadatos(500) as $fila) {
                if ((microtime(true) - $inicio) > $presupuestoSegundos) {
                    $agotado = true;
                    break;
                }

                // Un correo cuenta como resuelto cuando ya no le falta
                // nada; si el buzón no responde por él, se reintenta.
                $resuelto = true;

                if (!empty($fila['adjuntos_pendiente'])) {
                    $texto = $fetcher->adjuntosDeMensaje((int) $fila['uid'], (string) $fila['carpeta']);
                    if ($texto !== null) {
                        $indice->guardarAdjuntos((int) $fila['id'], $texto);
                        $stats['adjuntos']++;
                    } else {
                        $resuelto = false;
                    }
                }

                if (!empty($fila['cc_pendiente'])) {
                    $destinatarios = $fetcher->destinatariosDeMensaje((int) $fila['uid'], (string) $fila['carpeta']);
                    if ($destinatarios !== null) {
                        $indice->guardarDestinatarios(
                            (int) $fila['id'],
                            $destinatarios['cc'],
                            $destinatarios['reply_to']
                        );
                        $stats['cc']++;
                    } else {
                        $resuelto = false;
                    }
                }

                if ($resuelto) {
                    $stats['metadatos_resueltos']++;
                }
            }

            // Sigue habiendo pendientes → el que llama repite; pero si en
            // toda la tanda no se avanzó nada (solo carpetas inaccesibles),
            // se da por completado para no ciclar.
            // 'metadatos_pendientes' cuenta CORREOS (una visita resuelve
            // adjuntos y destinatarios juntos); las otras dos cuentan
            // campos y se conservan para el registro de la tarea.
            $stats['metadatos_pendientes'] = $indice->contarPendientesMetadatos();
            $stats['adjuntos_pendientes'] = $indice->contarPendientesAdjuntos();
            $stats['cc_pendientes'] = $indice->contarPendientesCc();
            if ($stats['metadatos_pendientes'] > 0
                && ($stats['metadatos_resueltos'] > 0 || $agotado)) {
                $stats['completado'] = false;
            }
        } finally {
            if ($fetcherPropio) {
                $fetcher->cerrar();
            }
        }

        $stats['segundos'] = round(microtime(true) - $inicio, 1);

        return $stats;
    }

    /** Separa encabezados que quedan en el índice de los vencidos. */
    private static function aplicarRetencion(array $filas, $timestampMinimo)
    {
        $timestampMinimo = (int) $timestampMinimo;
        if ($timestampMinimo <= 0) {
            return [array_values($filas), 0];
        }

        $conservar = [];
        $omitidos = 0;
        foreach ($filas as $fila) {
            $timestamp = (int) ($fila['timestamp'] ?? 0);
            if ($timestamp > 0 && $timestamp < $timestampMinimo) {
                $omitidos++;
            } else {
                $conservar[] = $fila;
            }
        }
        return [$conservar, $omitidos];
    }

    private static function registrarCapturas($cuentaId, $carpeta, $uidvalidity,
                                               array $filas, $desde = '')
    {
        if (!$filas || (int) $cuentaId <= 0) {
            return 0;
        }
        require_once __DIR__ . '/../models/CorreoCapturaAutomatica.php';
        return (new CorreoCapturaAutomatica())->registrarNuevos(
            (int) $cuentaId,
            (string) $carpeta,
            (int) $uidvalidity,
            $filas,
            (string) $desde
        );
    }
}
