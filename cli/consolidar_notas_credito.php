<?php
/**
 * Convierte las fotos históricas de Notas de Crédito en un acumulado por
 * sociedad. Conserva los IDs de las líneas, sus vínculos XML y la carga de
 * origen; no elimina listados, archivos ni filas.
 *
 * Uso:
 *   php cli/consolidar_notas_credito.php
 *   php cli/consolidar_notas_credito.php --aplicar
 */

require_once __DIR__ . '/../app/core/Model.php';
require_once __DIR__ . '/../app/models/NotaCredito.php';

$aplicar = in_array('--aplicar', $argv, true);

class ConsolidacionNotasCredito extends NotaCredito
{
    public function sociedadesConListados()
    {
        return $this->fetchAll(
            "SELECT s.id, s.nombre, COUNT(l.id) AS listados
               FROM sociedades s
               JOIN notas_credito_listados l ON l.sociedad_id = s.id
              GROUP BY s.id, s.nombre
              ORDER BY s.id"
        ) ?: [];
    }
}

$modelo = new ConsolidacionNotasCredito();
$sociedades = $modelo->sociedadesConListados();
if (!$sociedades) {
    echo "No hay listados de notas de crédito para consolidar.\n";
    exit(0);
}

echo $aplicar
    ? "APLICANDO consolidación acumulativa\n\n"
    : "SIMULACIÓN (no se escribe nada; usa --aplicar)\n\n";

$totalRecuperables = 0;
foreach ($sociedades as $sociedad) {
    $sociedadId = (int) $sociedad['id'];
    $previa = $modelo->previsualizarImportacion($sociedadId, []);
    $recuperables = (int) $previa['recuperables'];
    $totalRecuperables += $recuperables;

    if (!$aplicar) {
        printf(
            "#%-4d %-35s listados=%-3d acumuladas=%-6d recuperar=%d\n",
            $sociedadId,
            mb_substr((string) $sociedad['nombre'], 0, 35),
            (int) $sociedad['listados'],
            (int) $previa['acumuladas'],
            $recuperables
        );
        continue;
    }

    $resultado = $modelo->consolidarSociedad($sociedadId);
    printf(
        "#%-4d %-35s acumuladas=%-6d recuperadas=%d\n",
        $sociedadId,
        mb_substr((string) $sociedad['nombre'], 0, 35),
        (int) $resultado['total'],
        (int) $resultado['recuperadas']
    );
}

echo "\n";
if ($aplicar) {
    echo "Consolidación terminada. Documentos recuperados: {$totalRecuperables}.\n";
} else {
    echo "Documentos que se recuperarían: {$totalRecuperables}.\n";
    echo "Nada se escribió. Ejecuta de nuevo con --aplicar para confirmar.\n";
}
