<?php

/**
 * Retira una sociedad sin documentos y reasigna sus usuarios a la sociedad
 * activa. La transacción se cancela si cualquier tabla de trabajo la usa.
 *
 * Uso: php scripts/retirar-sociedad-obsoleta.php <id>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$sociedadId = (int) ($argv[1] ?? 0);
if ($sociedadId <= 0) {
    fwrite(STDERR, "Uso: php scripts/retirar-sociedad-obsoleta.php <id>\n");
    exit(2);
}

$config = require __DIR__ . '/../app/config/database.php';
$pdo = new PDO($config['dsn'], $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    $pdo->beginTransaction();

    $buscar = $pdo->prepare('SELECT id, nombre, activa FROM sociedades WHERE id = ? FOR UPDATE');
    $buscar->execute([$sociedadId]);
    $obsoleta = $buscar->fetch();
    if (!$obsoleta) {
        $pdo->rollBack();
        echo "Sin cambios: la sociedad {$sociedadId} ya no existe.\n";
        exit(0);
    }
    if ((int) $obsoleta['activa'] === 1) {
        throw new RuntimeException('No se puede retirar la sociedad activa por omisión.');
    }

    $destino = $pdo->query(
        'SELECT id FROM sociedades WHERE activa = 1 AND id <> ' . $sociedadId . ' ORDER BY id LIMIT 1 FOR UPDATE'
    )->fetchColumn();
    if (!$destino) {
        throw new RuntimeException('No existe otra sociedad activa para reasignar usuarios.');
    }

    $columnas = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'sociedad_id'
         ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($columnas as $tabla) {
        if ($tabla === 'usuarios') {
            continue;
        }
        $tablaSegura = str_replace('`', '``', (string) $tabla);
        $consulta = $pdo->prepare("SELECT COUNT(*) FROM `{$tablaSegura}` WHERE sociedad_id = ?");
        $consulta->execute([$sociedadId]);
        $usos = (int) $consulta->fetchColumn();
        if ($usos > 0) {
            throw new RuntimeException(
                "La sociedad {$sociedadId} conserva {$usos} registro(s) en {$tabla}; no se eliminó."
            );
        }
    }

    $reasignar = $pdo->prepare('UPDATE usuarios SET sociedad_id = ? WHERE sociedad_id = ?');
    $reasignar->execute([(int) $destino, $sociedadId]);

    $eliminar = $pdo->prepare('DELETE FROM sociedades WHERE id = ?');
    $eliminar->execute([$sociedadId]);
    if ($eliminar->rowCount() !== 1) {
        throw new RuntimeException('La sociedad no pudo eliminarse de forma segura.');
    }

    $pdo->commit();
    echo "Sociedad {$sociedadId} retirada; usuarios reasignados: {$reasignar->rowCount()}.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}
