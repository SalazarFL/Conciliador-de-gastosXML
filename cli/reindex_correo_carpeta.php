<?php
/** Reconstruye de forma controlada una carpeta del índice local de correo. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo corre por línea de comandos.\n");
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');
date_default_timezone_set('America/Mexico_City');

$root = dirname(__DIR__);
require_once $root . '/app/core/Model.php';
require_once $root . '/app/models/CorreoCuenta.php';
require_once $root . '/app/models/CorreoIndice.php';
require_once $root . '/app/helpers/MailFetcher.php';
require_once $root . '/app/helpers/CorreoSync.php';

$cuentaId = isset($argv[1]) ? (int) $argv[1] : 0;
$carpeta = isset($argv[2]) ? trim((string) $argv[2]) : 'INBOX';
if ($cuentaId <= 0 || $carpeta === '') {
    fwrite(STDERR, "Uso: php cli/reindex_correo_carpeta.php CUENTA_ID [CARPETA]\n");
    exit(2);
}

$lock = CorreoSync::adquirirLock();
if ($lock === null) {
    fwrite(STDERR, "Otra sincronización está en curso; intente nuevamente.\n");
    exit(3);
}

$fetcher = null;
$codigoSalida = 0;
try {
    $cuentas = new CorreoCuenta();
    $config = $cuentas->configPara($cuentaId);
    if ($config === null || !MailFetcher::configurado($config)) {
        throw new RuntimeException("La cuenta {$cuentaId} no tiene una configuración IMAP válida.");
    }

    $fetcher = new MailFetcher($config);
    $fetcher->conectar();
    $estado = $fetcher->estadoCarpeta($carpeta);
    if ($estado === null) {
        throw new RuntimeException("No fue posible consultar la carpeta {$carpeta}.");
    }

    echo "Descargando {$estado['mensajes']} encabezados vigentes de {$carpeta}...\n";
    $filas = $estado['mensajes'] > 0 ? $fetcher->overviewCarpeta($carpeta, '1:*') : [];
    if ($estado['mensajes'] > 0 && empty($filas)) {
        throw new RuntimeException('IMAP no devolvió encabezados; el índice anterior no fue modificado.');
    }

    $indice = (new CorreoIndice())->setCuenta($cuentaId);
    $insertadas = $indice->reemplazarCarpeta(
        $carpeta,
        $fetcher->nombreLegibleCarpeta($carpeta),
        $estado['uidvalidity'],
        $filas
    );
    $indice->guardarEstadoCarpeta(
        $carpeta,
        $estado['uidvalidity'],
        max(0, $estado['uidnext'] - 1),
        $estado['mensajes']
    );

    echo "OK: {$insertadas} encabezados vigentes indexados en {$carpeta}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    $codigoSalida = 1;
} finally {
    if ($fetcher instanceof MailFetcher) {
        $fetcher->cerrar();
    }
    CorreoSync::liberarLock($lock);
}

exit($codigoSalida);
