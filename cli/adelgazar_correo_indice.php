<?php
/**
 * Mide y compacta correo_indice sin tocar correos IMAP ni documentos.
 *
 * Uso:
 *   php cli/adelgazar_correo_indice.php
 *   php cli/adelgazar_correo_indice.php --aplicar --retencion-dias=1825
 *   php cli/adelgazar_correo_indice.php --aplicar --retencion-dias=1825 --cuenta=4
 *
 * Sin --aplicar es solo lectura. Al aplicar toma el mismo lock que la
 * sincronización, poda en tandas, quita un índice redundante, acorta Reply-To
 * (después de comprobar los datos) y reconstruye la tabla para devolver el
 * espacio al disco. Es repetible.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI.\n");
}

@set_time_limit(0);
date_default_timezone_set('America/Guatemala');

$aplicar = in_array('--aplicar', $argv, true);
$retencionDias = 1825;
$cuentaId = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--retencion-dias=') === 0) {
        $retencionDias = (int) substr($arg, strlen('--retencion-dias='));
    } elseif (strpos($arg, '--cuenta=') === 0) {
        $cuentaId = (int) substr($arg, strlen('--cuenta='));
    }
}
if ($retencionDias < 0 || $retencionDias > 3650) {
    exit("--retencion-dias debe estar entre 0 y 3650.\n");
}
if ($cuentaId < 0) {
    exit("--cuenta debe ser un id positivo.\n");
}

$config = require __DIR__ . '/../app/config/database.php';
$pdo = new PDO(
    $config['dsn'],
    $config['username'],
    $config['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true,
    ]
);

function mib($bytes)
{
    return number_format(((float) $bytes) / 1048576, 1, '.', '') . ' MiB';
}

function estadisticasTabla(PDO $pdo)
{
    $estado = $pdo->query("SELECT DATA_LENGTH, INDEX_LENGTH, DATA_FREE
                           FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='correo_indice'")
        ->fetch() ?: [];
    $filas = (int) $pdo->query('SELECT COUNT(*) FROM correo_indice')->fetchColumn();
    return [
        'filas' => $filas,
        'datos' => (int) ($estado['DATA_LENGTH'] ?? 0),
        'indices' => (int) ($estado['INDEX_LENGTH'] ?? 0),
        'libre' => (int) ($estado['DATA_FREE'] ?? 0),
    ];
}

/** Tamaño físico del .ibd principal y los auxiliares del FULLTEXT local. */
function archivosFisicos(PDO $pdo)
{
    $resultado = ['tabla' => 0, 'fulltext' => 0];
    try {
        $dataDir = rtrim((string) $pdo->query('SELECT @@datadir')->fetchColumn(), '/\\');
        $base = $dataDir . DIRECTORY_SEPARATOR . (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $principal = $base . DIRECTORY_SEPARATOR . 'correo_indice.ibd';
        if (is_file($principal)) {
            $resultado['tabla'] = (int) filesize($principal);
        }

        $stmt = $pdo->query("SELECT TABLE_ID FROM information_schema.INNODB_SYS_TABLES
                             WHERE NAME=CONCAT(DATABASE(), '/correo_indice') LIMIT 1");
        $tableId = (int) $stmt->fetchColumn();
        if ($tableId > 0) {
            // InnoDB nombra estos archivos con hexadecimal minúsculo. glob()
            // en Windows conserva la distinción de mayúsculas en el patrón.
            $hex = strtolower(str_pad(dechex($tableId), 16, '0', STR_PAD_LEFT));
            foreach (glob($base . DIRECTORY_SEPARATOR . 'FTS_' . $hex . '_*.ibd') ?: [] as $archivo) {
                $resultado['fulltext'] += (int) filesize($archivo);
            }
        }
    } catch (Throwable $e) {
        // En hosting el datadir puede no ser visible: las medidas lógicas
        // siguen siendo suficientes y este bloque queda simplemente en cero.
    }
    return $resultado;
}

function imprimirMedida($titulo, array $tabla, array $fisico)
{
    echo "\n{$titulo}\n";
    echo '  Filas:              ' . number_format($tabla['filas'], 0, '.', ',') . "\n";
    echo '  Datos lógicos:     ' . mib($tabla['datos']) . "\n";
    echo '  Índices lógicos:   ' . mib($tabla['indices']) . "\n";
    echo '  Espacio libre:      ' . mib($tabla['libre']) . "\n";
    if ($fisico['tabla'] > 0) {
        echo '  Archivo de tabla:   ' . mib($fisico['tabla']) . "\n";
    }
    if ($fisico['fulltext'] > 0) {
        echo '  Auxiliares FULLTEXT:' . str_pad('', 3) . mib($fisico['fulltext']) . "\n";
    }
}

$antes = estadisticasTabla($pdo);
$fisicoAntes = archivosFisicos($pdo);
imprimirMedida('Estado actual', $antes, $fisicoAntes);

$corte = $retencionDias > 0
    ? (int) strtotime('-' . $retencionDias . ' days', strtotime(date('Y-m-d 00:00:00')))
    : 0;
$whereCuenta = $cuentaId > 0 ? ' AND cuenta_id = ?' : '';
$paramsCuenta = $cuentaId > 0 ? [$cuentaId] : [];
$podables = 0;
if ($corte > 0) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM correo_indice
                           WHERE timestamp > 0 AND timestamp < ?{$whereCuenta}");
    $stmt->execute(array_merge([$corte], $paramsCuenta));
    $podables = (int) $stmt->fetchColumn();
}

$replyMax = (int) $pdo->query('SELECT COALESCE(MAX(CHAR_LENGTH(reply_to)),0) FROM correo_indice')->fetchColumn();
$tieneIndiceTimestamp = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='correo_indice' AND INDEX_NAME='idx_timestamp'"
)->fetchColumn() > 0;
$paginasTimestamp = (int) $pdo->query(
    "SELECT COALESCE(MAX(stat_value),0) FROM mysql.innodb_index_stats
     WHERE database_name=DATABASE() AND table_name='correo_indice'
       AND index_name='idx_timestamp' AND stat_name='size'"
)->fetchColumn();
$ahorroPoda = $antes['filas'] > 0
    ? (int) round(($antes['datos'] + $antes['indices']) * ($podables / $antes['filas']))
    : 0;

echo "\nCambios medidos\n";
echo '  Retención:          ' . ($retencionDias > 0 ? $retencionDias . ' días' : 'sin límite') . "\n";
echo '  Filas podables:      ' . number_format($podables, 0, '.', ',') . "\n";
echo '  Ahorro poda (aprox): ' . mib($ahorroPoda) . "\n";
echo '  idx_timestamp:       ' . ($tieneIndiceTimestamp ? mib($paginasTimestamp * 16384) . ' redundante' : 'ya no existe') . "\n";
echo '  Reply-To máximo:    ' . $replyMax . " caracteres (límite nuevo: 255)\n";
echo '  FULLTEXT:            se conserva; sus auxiliares ocupan ' . mib($fisicoAntes['fulltext']) . "\n";

if (!$aplicar) {
    echo "\nSolo medición: no se cambió ninguna fila.\n";
    echo "Para aplicar: php cli/adelgazar_correo_indice.php --aplicar --retencion-dias={$retencionDias}"
        . ($cuentaId > 0 ? " --cuenta={$cuentaId}" : '') . "\n";
    exit(0);
}
if ($replyMax > 255) {
    exit("No se aplica: hay Reply-To de más de 255 caracteres.\n");
}

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/helpers/MailFetcher.php';
require_once __DIR__ . '/../app/helpers/CorreoSync.php';
require_once __DIR__ . '/../app/models/CorreoIndice.php';

$fpLock = @fopen(CorreoSync::rutaLock(), 'c');
if ($fpLock === false || !flock($fpLock, LOCK_EX | LOCK_NB)) {
    exit("Otra sincronización está en curso. Intenta de nuevo cuando termine.\n");
}

try {
    echo "\nAplicando con la sincronización pausada por lock...\n";
    $pdo->exec("ALTER TABLE correo_cuentas
                ADD COLUMN IF NOT EXISTS indice_retencion_dias
                INT UNSIGNED NOT NULL DEFAULT 1825 AFTER dias_atras");
    $pdo->exec("ALTER TABLE correo_carpetas
                ADD COLUMN IF NOT EXISTS mensajes_omitidos
                INT UNSIGNED NOT NULL DEFAULT 0 AFTER mensajes,
                ADD COLUMN IF NOT EXISTS retencion_dias
                INT UNSIGNED NOT NULL DEFAULT 0 AFTER mensajes_omitidos");

    if ($cuentaId > 0) {
        $stmt = $pdo->prepare('UPDATE correo_cuentas SET indice_retencion_dias = ? WHERE id = ?');
        $stmt->execute([$retencionDias, $cuentaId]);
        $cuentas = [$cuentaId];
    } else {
        $pdo->prepare('UPDATE correo_cuentas SET indice_retencion_dias = ?')->execute([$retencionDias]);
        $cuentas = array_map('intval', $pdo->query('SELECT id FROM correo_cuentas ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    $totalPodadas = 0;
    if ($corte > 0) {
        foreach ($cuentas as $idCuenta) {
            $indice = (new CorreoIndice())->setCuenta($idCuenta);
            $podadasCuenta = 0;
            do {
                $n = $indice->podarAntesDe($corte, 1000);
                $podadasCuenta += $n;
                $totalPodadas += $n;
                if ($n > 0 && $podadasCuenta % 10000 === 0) {
                    echo '  Cuenta ' . $idCuenta . ': ' . number_format($podadasCuenta, 0, '.', ',') . " podadas...\n";
                }
            } while ($n > 0);
            echo '  Cuenta ' . $idCuenta . ': ' . number_format($podadasCuenta, 0, '.', ',') . " filas podadas.\n";
        }
    }

    // La política ya quedó aplicada a esta fotografía del índice. Si el
    // usuario la cambia después, CorreoSync detectará la diferencia y hará
    // una sola reconstrucción de las carpetas afectadas.
    if ($cuentaId > 0) {
        $pdo->prepare('UPDATE correo_carpetas SET retencion_dias = ? WHERE cuenta_id = ?')
            ->execute([$retencionDias, $cuentaId]);
    } else {
        $pdo->prepare('UPDATE correo_carpetas SET retencion_dias = ?')->execute([$retencionDias]);
    }

    $largoColumna = (int) $pdo->query(
        "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='correo_indice' AND COLUMN_NAME='reply_to'"
    )->fetchColumn();
    $tieneIndiceTimestamp = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='correo_indice' AND INDEX_NAME='idx_timestamp'"
    )->fetchColumn() > 0;

    $cambios = [];
    if ($tieneIndiceTimestamp) {
        $cambios[] = 'DROP INDEX idx_timestamp';
    }
    if ($largoColumna > 255) {
        $cambios[] = 'MODIFY reply_to VARCHAR(255) NULL DEFAULT NULL';
    }

    if ($cambios) {
        echo "  Reconstruyendo y compactando correo_indice; puede tardar varios minutos...\n";
        $pdo->exec('ALTER TABLE correo_indice ' . implode(', ', $cambios) . ', ALGORITHM=COPY');
    } elseif ($totalPodadas > 0) {
        echo "  Compactando correo_indice; puede tardar varios minutos...\n";
        $pdo->query('OPTIMIZE TABLE correo_indice')->fetchAll();
    }
    $pdo->query('ANALYZE TABLE correo_indice')->fetchAll();

    clearstatcache();
    $despues = estadisticasTabla($pdo);
    $fisicoDespues = archivosFisicos($pdo);
    imprimirMedida('Estado final', $despues, $fisicoDespues);

    echo "\nRecuperado\n";
    echo '  Filas:              ' . number_format($antes['filas'] - $despues['filas'], 0, '.', ',') . "\n";
    echo '  Lógico tabla+índices:' . str_pad('', 2)
        . mib(($antes['datos'] + $antes['indices']) - ($despues['datos'] + $despues['indices'])) . "\n";
    if ($fisicoAntes['tabla'] > 0 && $fisicoDespues['tabla'] > 0) {
        echo '  Físico total:       '
            . mib(($fisicoAntes['tabla'] + $fisicoAntes['fulltext'])
                - ($fisicoDespues['tabla'] + $fisicoDespues['fulltext'])) . "\n";
    }
    echo "Los documentos y los mensajes del servidor no se modificaron.\n";
} finally {
    flock($fpLock, LOCK_UN);
    fclose($fpLock);
}
