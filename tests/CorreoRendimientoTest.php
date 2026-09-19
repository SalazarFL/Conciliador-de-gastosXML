<?php
/**
 * Correo bajo carga: cuántos viajes a la base y cuánta memoria cuesta cada cosa.
 *
 * La base está al otro lado de la red (~85–105 ms por viaje, según las
 * mediciones anotadas en el propio código), así que una consulta de más por
 * correo no es un detalle: se multiplica por cada correo de cada lote. Estas
 * pruebas cuentan sentencias con la PDO falsa —el número no depende de la red— y
 * miden memoria en PHP. Los tiempos que aparecen en los mensajes son esa cuenta
 * multiplicada por 90 ms, no una medición contra la base.
 *
 * Uso: php tests/CorreoRendimientoTest.php
 */
require_once __DIR__ . '/soporte/CorreoSoporte.php';

Prueba::iniciar('Correo bajo carga');

const RTT_SEGUNDOS = 0.09;

Prueba::caso('sincronización sin novedades: un viaje al buzón por carpeta y ninguno a la base por carpeta', function () {
    $buzon = new BuzonFalso();
    $indice = new IndiceFalso();
    for ($i = 1; $i <= 50; $i++) {
        $carpeta = "INBOX.C{$i}";
        $buzon->estados[$carpeta] = ['uidvalidity' => 77, 'uidnext' => 1, 'mensajes' => 0];
        $indice->carpetas[$carpeta] = ['carpeta' => $carpeta, 'uidvalidity' => 77, 'ultimo_uid' => 0, 'mensajes' => 0,
            'mensajes_omitidos' => 0, 'retencion_dias' => 0, 'edad_sync' => 7200, 'edad_cambio' => null];
    }
    CorreoSync::ejecutar(['cuenta_id' => 3, 'carpeta' => 'INBOX', 'indice_retencion_dias' => 0], $indice, 60, $buzon);
    igual(50, count($buzon->llamadas), 'un STATUS por carpeta, nada más');
});

Prueba::caso('ritmo de carpetas: las de archivo vistas hace menos de una hora no se preguntan', function () {
    $buzon = new BuzonFalso();
    $indice = new IndiceFalso();
    for ($i = 1; $i <= 100; $i++) {
        $carpeta = "INBOX.2024.M{$i}";
        $buzon->estados[$carpeta] = ['uidvalidity' => 77, 'uidnext' => 1, 'mensajes' => 0];
        $indice->carpetas[$carpeta] = ['carpeta' => $carpeta, 'uidvalidity' => 77, 'ultimo_uid' => 0, 'mensajes' => 0,
            'mensajes_omitidos' => 0, 'retencion_dias' => 0, 'edad_sync' => 600, 'edad_cambio' => 90 * 86400];
    }
    $stats = CorreoSync::ejecutar(['cuenta_id' => 3, 'carpeta' => 'INBOX', 'indice_retencion_dias' => 0], $indice, 60, $buzon);
    igual(0, $stats['carpetas_por_revisar'], 'ninguna');
    igual([], $buzon->llamadas, 'cero viajes');
});

Prueba::caso('[P1] lote: los contadores del lote se recalculan una vez por tanda, no una vez por correo', function () {
    $correos = 10;
    Soporte::loteEnCurso($correos);
    $buzon = new BuzonFalso((new CorreoCuenta())->configPara(3));
    Soporte::$pdo->sentencias = [];

    $r = (new CorreoControllerSonda())->procesarTandaLote(50, $correos, 60, $buzon);

    igual($correos, $r['procesados_ahora'], 'la tanda procesó todo');
    $total = count(Soporte::$pdo->sentencias);
    $recalculos = count(array_filter(Soporte::$pdo->sentencias,
        fn($s) => preg_match('/UPDATE correo_lotes l SET\s+procesados = \(SELECT COUNT/', $s['sql'])));
    verificar($recalculos <= 2, sprintf(
        'antes %d recálculos (5 subconsultas cada uno) y %d sentencias para %d correos SIN adjuntos: %.1f por correo, '
        . '≈%.1f s de espera a la base por correo antes de bajar un solo XML',
        $recalculos, $total, $correos, $total / $correos, $total / $correos * RTT_SEGUNDOS));
});

Prueba::caso('[P2] búsqueda: pegar un texto largo no multiplica sin tope las condiciones LIKE', function () {
    // Alguien pega el cuerpo entero de un correo en el buscador.
    $texto = trim(str_repeat('Estimado cliente adjuntamos la factura electronica correspondiente al mes ', 60));
    $palabras = count(preg_split('/\s+/', $texto));
    (new CorreoIndice())->setCuenta(3)->buscar($texto, 'asunto_remitente', 0);
    $pagina = Soporte::$pdo->de('SELECT uid, clave')[0];
    verificar(count($pagina['params']) <= 51, sprintf(
        'antes %d palabras → %d parámetros, %d KB de SQL y %d LIKE "%%…%%" sin índice, dos veces (COUNT y página)',
        $palabras, count($pagina['params']), (int) (strlen($pagina['sql']) / 1024), substr_count($pagina['sql'], 'LIKE')));
});

Prueba::caso('[M3] buscar en el buzón no carga en memoria TODAS las marcas de "procesado" de la empresa', function () {
    // listarMensajes() pregunta yaProcesado() por cada correo de la página, y la
    // primera pregunta baja la tabla entera de la empresa. Medido en PHP 8.4:
    // 100 000 marcas ocupan ~10,6 MB, y la tabla solo crece.
    $marcas = [];
    for ($i = 0; $i < 20000; $i++) {
        $marcas[] = ['clave' => 'c3:1712345678:' . $i, 'procesado_en' => '2026-09-01 10:00:00'];
    }
    Soporte::$pdo->respuestas['FROM correo_procesados'] = $marcas;
    $f = new MailFetcher(['cuenta_id' => 3, 'sociedad_id' => 4]);
    $f->yaProcesado('c3:1712345678:5');
    $sql = Soporte::$pdo->de('FROM correo_procesados')[0]['sql'];
    verificar(stripos($sql, 'clave IN') !== false || stripos($sql, 'clave =') !== false,
        'antes "WHERE sociedad_id = ?" a secas: cada búsqueda baja todas las marcas de la empresa para mirar 500');
    unset($f);
});

Prueba::caso('memoria de un encabezado de la búsqueda IMAP: ~1 KB por correo (para dimensionar M1)', function () {
    $antes = memory_get_usage();
    $filas = [];
    for ($i = 0; $i < 5000; $i++) {
        $filas[] = ['uid' => $i, 'clave' => 'c3:1712345678:' . $i, 'carpeta' => 'INBOX.2026.A FACTURAS MG',
            'carpeta_nombre' => '2026/A FACTURAS MG', 'asunto' => 'Factura electrónica 00100001010000' . $i,
            'remitente' => 'Proveedor Demo S.A. <facturacion@proveedor.example>', 'cc' => '', 'reply_to' => '',
            'fecha' => '2026-09-01 10:00:00', 'timestamp' => 1756742400 + $i, 'procesado' => false];
    }
    $porFila = (memory_get_usage() - $antes) / 5000;
    verificar($porFila > 300 && $porFila < 3000, sprintf('%.0f bytes por fila', $porFila));
    unset($filas);
});

Prueba::terminar();
