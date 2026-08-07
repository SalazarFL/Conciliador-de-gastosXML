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
 * las más viejas; las sincronizadas hace <2 min se saltan (ya están al día en
 * esta ronda). La fase 2 rellena los nombres de adjuntos y destinatarios CC
 * por tandas.
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

        $stats = ['carpetas' => 0, 'nuevos' => 0, 'reindexadas' => 0, 'restantes' => 0, 'completado' => true];

        try {
            $carpetas = $fetcher->carpetasABuscar();
            $estados = $indice->getCarpetas();

            // Nunca sincronizadas primero, luego de la más vieja a la más nueva
            usort($carpetas, function ($a, $b) use ($estados) {
                $ta = !empty($estados[$a]['ultima_sync']) ? (int) strtotime($estados[$a]['ultima_sync']) : 0;
                $tb = !empty($estados[$b]['ultima_sync']) ? (int) strtotime($estados[$b]['ultima_sync']) : 0;
                return $ta - $tb;
            });

            foreach ($carpetas as $i => $carpeta) {
                // Sincronizada hace <2 min: al día para esta ronda
                $registro = $estados[$carpeta] ?? null;
                if ($registro && !empty($registro['ultima_sync'])
                    && (time() - (int) strtotime($registro['ultima_sync'])) < 120) {
                    continue;
                }

                if ((microtime(true) - $inicio) > $presupuestoSegundos) {
                    // Presupuesto agotado: el que llama vuelve a llamar para seguir
                    $stats['restantes'] = count($carpetas) - $i;
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

                $resync = !$registro || (int) $registro['uidvalidity'] !== $estado['uidvalidity'];

                if ($resync) {
                    // Carpeta nueva o renumerada por el servidor: completa
                    $filas = $estado['mensajes'] > 0
                        ? $fetcher->overviewCarpeta($carpeta, '1:*')
                        : [];
                    if ($estado['mensajes'] > 0 && empty($filas)) {
                        throw new RuntimeException("IMAP no devolvió encabezados para reconstruir {$nombre}.");
                    }
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
                    $stats['nuevos'] += $indice->insertarLote($carpeta, $nombre, $estado['uidvalidity'], $filas);
                }

                // Movidos/eliminados: si el conteo no cuadra, reindexar
                if (!$resync && $indice->contarCarpeta($carpeta) !== $estado['mensajes']) {
                    $filas = $estado['mensajes'] > 0
                        ? $fetcher->overviewCarpeta($carpeta, '1:*')
                        : [];
                    if ($estado['mensajes'] > 0 && empty($filas)) {
                        throw new RuntimeException("IMAP no devolvió encabezados para reconstruir {$nombre}.");
                    }
                    $indice->reemplazarCarpeta(
                        $carpeta,
                        $nombre,
                        $estado['uidvalidity'],
                        $filas
                    );
                    $stats['reindexadas']++;
                }

                $indice->guardarEstadoCarpeta($carpeta, $estado['uidvalidity'], max(0, $estado['uidnext'] - 1), $estado['mensajes']);
            }

            // ── Fase 2: nombres de adjuntos y destinatarios CC/Reply-To ──
            // Se rellenan en UNA sola pasada (antes eran dos colas separadas:
            // un mensaje con ambos pendientes cambiaba de carpeta y se
            // procesaba dos veces). pendientesMetadatos agrupa por carpeta,
            // así cada carpeta IMAP se abre una vez por tanda y del mensaje
            // se leen estructura y encabezados en el mismo viaje.
            $stats['adjuntos'] = 0;
            $stats['cc'] = 0;
            if ($stats['completado']) {
                $agotado = false;

                foreach ($indice->pendientesMetadatos(500) as $fila) {
                    if ((microtime(true) - $inicio) > $presupuestoSegundos) {
                        $agotado = true;
                        break;
                    }

                    if (!empty($fila['adjuntos_pendiente'])) {
                        $texto = $fetcher->adjuntosDeMensaje((int) $fila['uid'], (string) $fila['carpeta']);
                        if ($texto !== null) {
                            $indice->guardarAdjuntos((int) $fila['id'], $texto);
                            $stats['adjuntos']++;
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
                        }
                    }
                }

                // Sigue habiendo pendientes → el que llama repite; pero si en
                // toda la tanda no se avanzó nada (solo carpetas inaccesibles),
                // se da por completado para no ciclar.
                $stats['adjuntos_pendientes'] = $indice->contarPendientesAdjuntos();
                $stats['cc_pendientes'] = $indice->contarPendientesCc();
                $pendientes = $stats['adjuntos_pendientes'] + $stats['cc_pendientes'];
                $procesados = $stats['adjuntos'] + $stats['cc'];
                if ($pendientes > 0 && ($procesados > 0 || $agotado)) {
                    $stats['completado'] = false;
                }
            }
        } finally {
            if ($fetcherPropio) {
                $fetcher->cerrar();
            }
        }

        $stats['segundos'] = round(microtime(true) - $inicio, 1);

        return $stats;
    }
}
