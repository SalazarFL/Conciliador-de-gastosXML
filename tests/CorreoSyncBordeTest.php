<?php
/**
 * La sincronización del índice y el cliente IMAP, en sus bordes.
 *
 * Caminos felices (carpeta nueva, correo nuevo, nada nuevo), bordes del
 * protocolo (el 'n:*' que devuelve el último aunque no haya nada, UIDVALIDITY
 * regenerado, carpetas que no abren), valores nulos y vacíos en todo lo que
 * llega del servidor, y el reparto del presupuesto de tiempo.
 *
 * Nada toca IMAP ni la base: el buzón y el índice son dobles en memoria.
 *
 * Uso: php tests/CorreoSyncBordeTest.php
 */
require_once __DIR__ . '/soporte/CorreoSoporte.php';

Prueba::iniciar('Correo: sincronización y cliente IMAP en sus bordes');

function configSync(array $cambios = []): array
{
    return $cambios + ['cuenta_id' => 3, 'carpeta' => 'INBOX', 'indice_retencion_dias' => 0];
}

function registroCarpeta(string $carpeta, array $cambios = []): array
{
    return $cambios + [
        'carpeta' => $carpeta, 'uidvalidity' => 77, 'ultimo_uid' => 3, 'mensajes' => 3,
        'mensajes_omitidos' => 0, 'retencion_dias' => 0, 'edad_sync' => 600, 'edad_cambio' => 3600,
    ];
}

// ── CorreoSync::ejecutar — caminos felices ─────────────────────────────────

Prueba::caso('carpeta nunca vista: se reconstruye entera y queda con su último UID', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 4, 'mensajes' => 3]];
    $buzon->mensajes = ['INBOX' => [BuzonFalso::fila(1), BuzonFalso::fila(2), BuzonFalso::fila(3)]];
    $indice = new IndiceFalso();

    $stats = CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    igual(['INBOX'], $indice->reemplazos, 'una carpeta nueva se reemplaza entera');
    igual(3, $stats['nuevos'], 'los tres encabezados cuentan como nuevos');
    igual(1, $stats['reindexadas'], 'cuenta como reindexada');
    igual(3, $indice->carpetas['INBOX']['ultimo_uid'], 'ultimo_uid = uidnext - 1');
    verificar($indice->carpetas['INBOX']['hubo_cambio'], 'una carpeta nueva queda marcada como viva');
    verificar($stats['completado'], 'sin cola de metadatos, la tanda queda completa');
});

Prueba::caso('correo nuevo: solo se piden los UID nuevos y no se reindexa', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 6, 'mensajes' => 5]];
    $buzon->mensajes = ['INBOX' => array_map([BuzonFalso::class, 'fila'], [1, 2, 3, 4, 5])];
    $indice = new IndiceFalso();
    $indice->carpetas = ['INBOX' => registroCarpeta('INBOX')];
    foreach ([1, 2, 3] as $uid) {
        $indice->filas['INBOX'][$uid] = BuzonFalso::fila($uid);
    }

    $stats = CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    verificar(in_array('overview:INBOX:4:*', $buzon->llamadas, true), 'pide desde el UID 4');
    igual([['INBOX', [4, 5]]], $indice->inserciones, 'inserta solo 4 y 5');
    igual([], $indice->reemplazos, 'no reconstruye la carpeta');
    igual(2, $stats['nuevos'], 'dos nuevos');
});

Prueba::caso('nada nuevo: un solo STATUS, sin overview ni escrituras', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 4, 'mensajes' => 3]];
    $indice = new IndiceFalso();
    $indice->carpetas = ['INBOX' => registroCarpeta('INBOX')];
    foreach ([1, 2, 3] as $uid) {
        $indice->filas['INBOX'][$uid] = BuzonFalso::fila($uid);
    }

    CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    igual(['status:INBOX'], $buzon->llamadas, 'solo el STATUS');
    verificar(!$indice->carpetas['INBOX']['hubo_cambio'], 'mirar no es cambiar');
});

// ── Bordes del protocolo ───────────────────────────────────────────────────

Prueba::caso("'n:*' devuelve el último aunque n lo supere: no se reinserta", function () {
    // UIDNEXT avanzó por correos que llegaron y se borraron antes de la visita.
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 10, 'mensajes' => 3]];
    $buzon->mensajes = ['INBOX' => array_map([BuzonFalso::class, 'fila'], [1, 2, 3])];
    $indice = new IndiceFalso();
    $indice->carpetas = ['INBOX' => registroCarpeta('INBOX')];
    foreach ([1, 2, 3] as $uid) {
        $indice->filas['INBOX'][$uid] = BuzonFalso::fila($uid);
    }

    $stats = CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    igual([['INBOX', []]], $indice->inserciones, 'el UID 3 que devolvió el servidor se descarta');
    igual(0, $stats['nuevos'], 'nada nuevo');
    igual(9, $indice->carpetas['INBOX']['ultimo_uid'], 'el puntero avanza a uidnext - 1');
    verificar(!$indice->carpetas['INBOX']['hubo_cambio'], 'un UID que avanzó sin dejar nada no revive la carpeta');
});

Prueba::caso('UIDVALIDITY regenerado: se reconstruye aunque los conteos coincidan', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 99, 'uidnext' => 4, 'mensajes' => 3]];
    $buzon->mensajes = ['INBOX' => array_map([BuzonFalso::class, 'fila'], [1, 2, 3])];
    $indice = new IndiceFalso();
    $indice->carpetas = ['INBOX' => registroCarpeta('INBOX')];
    foreach ([1, 2, 3] as $uid) {
        $indice->filas['INBOX'][$uid] = BuzonFalso::fila($uid);
    }

    CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    igual(['INBOX'], $indice->reemplazos, 'los UID viejos no identifican nada: reconstrucción');
    igual(99, $indice->carpetas['INBOX']['uidvalidity'], 'queda el uidvalidity nuevo');
});

Prueba::caso('correos movidos o borrados: el conteo no cuadra y se reconstruye', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 4, 'mensajes' => 2]];
    $buzon->mensajes = ['INBOX' => array_map([BuzonFalso::class, 'fila'], [1, 3])];
    $indice = new IndiceFalso();
    $indice->carpetas = ['INBOX' => registroCarpeta('INBOX')];
    foreach ([1, 2, 3] as $uid) {
        $indice->filas['INBOX'][$uid] = BuzonFalso::fila($uid);
    }

    CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    igual(['INBOX'], $indice->reemplazos, 'se reconstruye');
    igual([1, 3], array_keys($indice->filas['INBOX']), 'el borrado desaparece del índice');
    verificar($indice->carpetas['INBOX']['hubo_cambio'], 'alguien usó la carpeta: sigue viva');
});

Prueba::caso('una carpeta que no responde al STATUS se salta sin tumbar la tanda', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = [
        'INBOX' => ['uidvalidity' => 77, 'uidnext' => 1, 'mensajes' => 0],
        'INBOX.Rota' => null,
    ];
    $indice = new IndiceFalso();

    $stats = CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    igual(1, $stats['carpetas'], 'solo cuenta la que respondió');
    igual(2, $stats['carpetas_totales'], 'pero las dos existen');
    verificar(!isset($indice->carpetas['INBOX.Rota']), 'la que no respondió no se da por sincronizada');
});

Prueba::caso('carpeta con mensajes y overview vacío: se corta con error, no vacía el índice', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 4, 'mensajes' => 3]];
    $buzon->mensajes = ['INBOX' => []];
    $indice = new IndiceFalso();

    lanza(fn() => CorreoSync::ejecutar(configSync(), $indice, 20, $buzon), 'no devolvió encabezados');
    igual([], $indice->reemplazos, 'no se reemplazó la carpeta por nada');
});

Prueba::caso('carpeta vacía (0 mensajes) nueva: se registra sin pedir overview', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 1, 'mensajes' => 0]];
    $indice = new IndiceFalso();

    CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    igual(['status:INBOX'], $buzon->llamadas, 'sin overview');
    igual(0, $indice->carpetas['INBOX']['ultimo_uid'], 'uidnext 1 → último UID 0, nunca -1');
});

// ── Retención ──────────────────────────────────────────────────────────────

Prueba::caso('retención: lo vencido se omite y se cuenta; lo sin fecha se conserva', function () {
    $viejo = strtotime('-400 days');
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 4, 'mensajes' => 3]];
    $buzon->mensajes = ['INBOX' => [
        BuzonFalso::fila(1, $viejo),
        array_merge(BuzonFalso::fila(2), ['timestamp' => 0, 'fecha' => null]),
        BuzonFalso::fila(3, time()),
    ]];
    $indice = new IndiceFalso();

    CorreoSync::ejecutar(configSync(['indice_retencion_dias' => 365]), $indice, 20, $buzon);

    igual([2, 3], array_keys($indice->filas['INBOX']), 'queda el sin fecha y el reciente');
    igual(1, $indice->carpetas['INBOX']['mensajes_omitidos'], 'el vencido se anota como omitido');
    igual(1, $indice->podados, 'se poda una tanda del índice');
});

Prueba::caso('aplicarRetencion: corte 0, timestamp faltante, negativo y justo en el corte', function () {
    $corte = 1000;
    $filas = [['uid' => 1], ['uid' => 2, 'timestamp' => -5], ['uid' => 3, 'timestamp' => 1000],
              ['uid' => 4, 'timestamp' => 999], ['uid' => 5, 'timestamp' => null]];
    [$sinCorte, $omitidosSinCorte] = Soporte::privado(CorreoSync::class, 'aplicarRetencion', $filas, 0);
    igual(5, count($sinCorte), 'sin corte no se omite nada');
    igual(0, $omitidosSinCorte, 'ni se cuenta');

    [$conservadas, $omitidos] = Soporte::privado(CorreoSync::class, 'aplicarRetencion', $filas, $corte);
    igual([1, 2, 3, 5], array_column($conservadas, 'uid'), 'solo el 999 queda fuera: el corte es estricto');
    igual(1, $omitidos, 'un omitido');
});

// ── Presupuesto y cola de metadatos ────────────────────────────────────────

Prueba::caso('presupuesto agotado: la tanda queda incompleta y dice cuántas faltan', function () {
    $buzon = new BuzonFalso();
    $buzon->demoraStatus = 0.05;
    for ($i = 1; $i <= 6; $i++) {
        $buzon->estados["INBOX.C{$i}"] = ['uidvalidity' => 77, 'uidnext' => 1, 'mensajes' => 0];
    }
    $indice = new IndiceFalso();

    $stats = CorreoSync::ejecutar(configSync(), $indice, 0.1, $buzon);

    verificar(!$stats['completado'], 'no se da por completa');
    verificar($stats['restantes'] > 0, 'informa carpetas restantes');
    verificar($stats['carpetas'] >= 1, 'la primera carpeta siempre entra');
});

Prueba::caso('metadatos: lo que el buzón no contesta sigue pendiente y la tanda pide otra', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = [];
    $buzon->adjuntos = ['INBOX.Cerrada|8' => null];
    $indice = new IndiceFalso();
    $indice->cola = [
        ['id' => 1, 'carpeta' => 'INBOX', 'uid' => 7, 'adjuntos_pendiente' => 1, 'cc_pendiente' => 1],
        ['id' => 2, 'carpeta' => 'INBOX.Cerrada', 'uid' => 8, 'adjuntos_pendiente' => 1, 'cc_pendiente' => 0],
    ];

    $stats = CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    igual(1, $stats['metadatos_resueltos'], 'solo se resolvió el que respondió');
    igual(1, $stats['metadatos_pendientes'], 'queda uno');
    verificar(!$stats['completado'], 'hubo avance: se pide otra tanda');
});

Prueba::caso('metadatos: si nada avanza, se da por completa para no ciclar', function () {
    $buzon = new BuzonFalso();
    $buzon->adjuntos = ['INBOX.Cerrada|8' => null];
    $indice = new IndiceFalso();
    $indice->cola = [['id' => 2, 'carpeta' => 'INBOX.Cerrada', 'uid' => 8, 'adjuntos_pendiente' => 1, 'cc_pendiente' => 0]];

    $stats = CorreoSync::ejecutar(configSync(), $indice, 20, $buzon);

    verificar($stats['completado'], 'completa, aunque con cola');
    igual('avisar', CorreoSync::decisionRezago($stats), 'y eso es lo que enciende la campana');
});

Prueba::caso('decisionRezago con datos vacíos, nulos y en texto', function () {
    igual('cerrar', CorreoSync::decisionRezago([]), 'sin datos no hay rezago');
    igual('cerrar', CorreoSync::decisionRezago(['metadatos_pendientes' => null, 'completado' => true]), 'null es cero');
    igual('cerrar', CorreoSync::decisionRezago(['metadatos_pendientes' => '0']), '"0" es cero');
    igual('nada', CorreoSync::decisionRezago(['metadatos_pendientes' => '12']), 'sin "completado" es tanda a medias');
    igual('avisar', CorreoSync::decisionRezago(['metadatos_pendientes' => 12, 'completado' => 1]), 'completa con cola');
});

// ── Captura automática ─────────────────────────────────────────────────────

Prueba::caso('captura automática: en la primera indexación solo entra lo posterior a la activación', function () {
    $activacion = '2026-09-01 10:00:02';
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 5, 'mensajes' => 4]];
    $buzon->mensajes = ['INBOX' => [
        BuzonFalso::fila(1, strtotime('2026-08-01 09:00:00')),
        BuzonFalso::fila(2, strtotime('2026-09-01 10:00:01')),
        BuzonFalso::fila(3, strtotime('2026-09-01 10:00:02')),
        array_merge(BuzonFalso::fila(4), ['timestamp' => 0]),
    ]];

    CorreoSync::ejecutar(configSync(['captura_automatica' => true, 'captura_activada_en' => $activacion]),
        new IndiceFalso(), 20, $buzon);

    $inserts = Soporte::$pdo->de('INSERT IGNORE INTO correo_capturas_auto');
    igual(1, count($inserts), 'una sola escritura a la cola');
    verificar(in_array('c3:77:3', $inserts[0]['params'], true), 'entra el del segundo exacto de la activación');
    verificar(!in_array('c3:77:2', $inserts[0]['params'], true), 'no entra el de un segundo antes');
    verificar(!in_array('c3:77:4', $inserts[0]['params'], true), 'ni uno sin fecha: podría ser de cualquier año');
});

Prueba::caso('captura automática sin cuenta (cuenta_id 0): no se encola nada', function () {
    $buzon = new BuzonFalso();
    $buzon->estados = ['INBOX' => ['uidvalidity' => 77, 'uidnext' => 2, 'mensajes' => 1]];
    $buzon->mensajes = ['INBOX' => [BuzonFalso::fila(1, time())]];

    CorreoSync::ejecutar(configSync(['cuenta_id' => 0, 'captura_automatica' => true]), new IndiceFalso(), 20, $buzon);

    igual(0, Soporte::$pdo->contar('correo_capturas_auto'), 'sin cuenta no hay cola');
});

// ── RitmoCarpetas: lo que no cubría RitmoCarpetasTest ──────────────────────

Prueba::caso('RitmoCarpetas con lista vacía, claves faltantes y edades negativas', function () {
    igual([], RitmoCarpetas::porRevisar([]), 'nada que revisar');
    igual(['X'], RitmoCarpetas::porRevisar([['carpeta' => 'X']]), 'sin edades es una carpeta nunca vista: toca');
    igual([], RitmoCarpetas::porRevisar([['carpeta' => 'Y', 'edad_sync' => -30, 'edad_cambio' => 10]]),
        'un reloj adelantado no la hace urgente ni rompe el orden');
    igual(['A', 'B'], RitmoCarpetas::porRevisar([
        ['carpeta' => 'B', 'edad_sync' => null], ['carpeta' => 'A', 'edad_sync' => null],
    ]), 'empate de urgencia infinita: se ordena por nombre');
    igual(['base' => 0, 'activas' => 0, 'archivo' => 0], RitmoCarpetas::resumen([]), 'resumen vacío');
});

// ── MailFetcher: lo que se puede probar sin servidor ───────────────────────

Prueba::caso('configurado(): nulos, vacíos, espacios y el host de ejemplo', function () {
    verificar(!MailFetcher::configurado(null), 'null');
    verificar(!MailFetcher::configurado([]), 'vacío');
    verificar(!MailFetcher::configurado(['host' => '  ', 'usuario' => 'a', 'password' => 'b']), 'host en blanco');
    verificar(!MailFetcher::configurado(['host' => 'mail.tudominio.com', 'usuario' => 'a', 'password' => 'b']),
        'el host del archivo de ejemplo no cuenta');
    verificar(!MailFetcher::configurado(['host' => 'mail.x', 'usuario' => 'a', 'password' => " \t"]), 'password en blanco');
    verificar(MailFetcher::configurado(['host' => 'mail.x', 'usuario' => 'a', 'password' => '0']),
        'un password "0" es un password');
});

Prueba::caso('nombre legible de carpeta: prefijo, mUTF-7, jerarquía y vacío', function () {
    igual('2026/Facturación', MailFetcher::nombreLegibleEstatico('INBOX.2026.Facturaci&APM-n'), 'mUTF-7 y puntos');
    igual('Archivo', MailFetcher::nombreLegibleEstatico('inbox.Archivo'), 'el prefijo no distingue mayúsculas');
    igual('INBOX', MailFetcher::nombreLegibleEstatico('INBOX'), 'la bandeja sola se llama igual');
    igual('', MailFetcher::nombreLegibleEstatico(''), 'vacío no revienta');
    igual('Mal&Formado', MailFetcher::nombreLegibleEstatico('Mal&Formado'), 'mUTF-7 inválido se deja tal cual');
});

Prueba::caso('adjuntos de la estructura MIME: secciones, anidados, sin nombre y límites de vista', function () {
    $f = new MailFetcher([]);
    $estructura = Soporte::multiparte([
        Soporte::parteMime('', ['type' => 0, 'subtype' => 'PLAIN']),
        Soporte::multiparte([
            Soporte::parteMime('FE-001.XML'),
            Soporte::parteMime('FE-001.pdf', ['bytes' => 16 * 1048576]),
        ]),
        Soporte::parteMime('factura.pdf.exe'),
        Soporte::parteMime('vacio.pdf', ['bytes' => 0]),
    ]);

    $adjuntos = Soporte::privado($f, 'recolectarAdjuntos', $estructura);

    igual(['2.1', '2.2', '3', '4'], array_column($adjuntos, 'seccion'), 'la parte sin nombre no es adjunto; secciones anidadas');
    igual('xml', $adjuntos[0]['tipo_vista'], 'la extensión no distingue mayúsculas');
    verificar(!$adjuntos[1]['visualizable'], 'más de 15 MB no se previsualiza');
    igual('', $adjuntos[2]['tipo_vista'], 'factura.pdf.exe no es un PDF');
    verificar($adjuntos[3]['visualizable'], 'tamaño desconocido (0) se permite; el límite se revisa al leer');

    igual('FE-001.pdf', Soporte::privado($f, 'nombreDeParte', Soporte::privado($f, 'buscarPartePorSeccion', $estructura, '2.2')),
        'la sección 2.2 se encuentra');
    igual(null, Soporte::privado($f, 'buscarPartePorSeccion', $estructura, '1'), 'la parte de texto no es un adjunto pedible');
    igual(null, Soporte::privado($f, 'buscarPartePorSeccion', $estructura, '9.9'), 'una sección inexistente es null');

    $unaSola = Soporte::parteMime('solo.xml');
    igual('1', Soporte::privado($f, 'recolectarAdjuntos', $unaSola)[0]['seccion'], 'mensaje de una sola parte: sección 1');
});

Prueba::caso('nombre de la parte: filename manda sobre name, y se decodifica RFC 2047', function () {
    $f = new MailFetcher([]);
    $ambos = Soporte::parteMime('del-disposition.xml', [
        'ifparameters' => 1, 'parameters' => [(object) ['attribute' => 'NAME', 'value' => 'del-type.xml']],
    ]);
    igual('del-disposition.xml', Soporte::privado($f, 'nombreDeParte', $ambos), 'filename primero');

    $soloName = Soporte::parteMime('', [
        'ifparameters' => 1, 'parameters' => [(object) ['attribute' => 'name', 'value' => '=?UTF-8?B?' . base64_encode('Factura Nº 7.pdf') . '?=']],
    ]);
    igual('Factura Nº 7.pdf', Soporte::privado($f, 'nombreDeParte', $soloName), 'name codificado');

    igual('', Soporte::privado($f, 'nombreDeParte', (object) []), 'una parte sin parámetros no tiene nombre');
});

Prueba::caso('texto de encabezados: charset inventado, Latin-1, bytes crudos y UTF-8 inválido', function () {
    $f = new MailFetcher([]);
    igual('Factura á', Soporte::privado($f, 'decodificarTexto', '=?X-INVENTADO?Q?Factura_=E1?='), 'charset desconocido no tumba nada');
    igual('Facturación', Soporte::privado($f, 'decodificarTexto', '=?ISO-8859-1?Q?Facturaci=F3n?='), 'Latin-1');
    igual('Factura á cruda', Soporte::privado($f, 'decodificarTexto', "Factura \xE1 cruda"), 'Windows-1252 sin declarar');
    $limpio = Soporte::privado($f, 'convertirAUtf8', "ok \xC3\x28 \xFF", 'UTF-8');
    verificar(mb_check_encoding($limpio, 'UTF-8'), 'siempre sale UTF-8 válido: si no, json_encode vacía la respuesta');
    igual('', Soporte::privado($f, 'decodificarTexto', ''), 'vacío');
});

Prueba::caso('clave del mensaje: con y sin cuenta', function () {
    igual('0:5', Soporte::privado(new MailFetcher([]), 'claveMensaje', 5), 'sin cuenta, la clave legado');
    igual('c12:0:5', Soporte::privado(new MailFetcher(['cuenta_id' => '12']), 'claveMensaje', '5'), 'con cuenta, prefijada');
});

Prueba::caso('ZIP adjunto: solo XML/PDF, sin rutas y sin dejar el ZIP temporal', function () {
    $zipRuta = Soporte::carpetaTemporal() . DIRECTORY_SEPARATOR . 'paquete.zip';
    $zip = new ZipArchive();
    $zip->open($zipRuta, ZipArchive::CREATE);
    $zip->addFromString('FE-1.xml', '<FacturaElectronica/>');
    $zip->addFromString('sub/../../../FE-1.PDF', '%PDF-1.4');
    $zip->addFromString('macro.exe', 'MZ');
    $zip->addFromString('vacio.xml', '');
    $zip->close();

    $f = new MailFetcher([]);
    $adjuntos = Soporte::privado($f, 'extraerDeZip', file_get_contents($zipRuta), 'paquete.zip');
    foreach (rutasDeAdjuntos($adjuntos) as $ruta) {
        Soporte::anotarTemporal($ruta);
    }

    igual(['FE-1.xml', 'FE-1.PDF'], array_column($adjuntos, 'nombre'), 'el .exe y el vacío no pasan; la ruta se reduce al nombre');
    $tmp = realpath(MailFetcher::storagePath('tmp'));
    foreach (rutasDeAdjuntos($adjuntos) as $ruta) {
        igual($tmp, realpath(dirname($ruta)), 'todo queda dentro de storage/correo/tmp');
    }
    igual([], glob($tmp . DIRECTORY_SEPARATOR . '*paquete.zip') ?: [], 'el ZIP intermedio se borra');
});

Prueba::caso('ZIP corrupto o vacío: ningún adjunto y ningún temporal', function () {
    $f = new MailFetcher([]);
    igual([], Soporte::privado($f, 'extraerDeZip', 'esto no es un zip', 'roto.zip'), 'corrupto');
    igual([], glob(MailFetcher::storagePath('tmp') . DIRECTORY_SEPARATOR . '*roto.zip') ?: [], 'sin basura');
});

Prueba::caso('guardarTemp: un nombre con recorrido de rutas no sale de tmp', function () {
    $f = new MailFetcher([]);
    $ruta = Soporte::privado($f, 'guardarTemp', 'x', '..\\..\\..\\public\\shell.php');
    Soporte::anotarTemporal((string) $ruta);
    igual(realpath(MailFetcher::storagePath('tmp')), realpath(dirname($ruta)), 'se queda en tmp');
    verificar(strpos(basename($ruta), '..\\') === false && strpos(basename($ruta), '/') === false, 'sin separadores');
});

Prueba::terminar();
