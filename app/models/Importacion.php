<?php
/**
 * Modelo de Importación
 * Registra procesos de carga de XML y gastos
 */

class Importacion extends Model
{
    protected $table = 'importaciones';

    public function getLatestByTipo($tipo)
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE tipo = ?
                ORDER BY fecha_importacion DESC, id DESC
                LIMIT 1";

        return $this->fetchOne($sql, [$tipo]);
    }

    public function crear($data)
    {
        $sql = "INSERT INTO {$this->table}
                (tipo, archivo_origen, ruta_archivo, total_registros, registros_exitosos, registros_fallidos, errores, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        return $this->insert($sql, [
            $data['tipo'],
            $data['archivo_origen'],
            $data['ruta_archivo'] ?? null,
            $data['total_registros'] ?? 0,
            $data['registros_exitosos'] ?? 0,
            $data['registros_fallidos'] ?? 0,
            $data['errores'] ?? null,
            $data['metadata'] ?? null
        ]);
    }

    public function actualizarResumen($id, array $data)
    {
        $allowedFields = [
            'archivo_origen',
            'ruta_archivo',
            'total_registros',
            'registros_exitosos',
            'registros_fallidos',
            'errores',
            'metadata',
        ];

        $sets = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $sets[] = "{$field} = ?";
            $params[] = $data[$field];
        }

        if (empty($sets)) {
            return 0;
        }

        $params[] = (int) $id;

        $sql = "UPDATE {$this->table}
                SET " . implode(', ', $sets) . "
                WHERE id = ?";

        return $this->execute($sql, $params);
    }

    public function actualizarMetadata($id, array $metadata)
    {
        $importacion = $this->findById((int) $id);
        $actual = [];

        if (!empty($importacion['metadata'])) {
            $decoded = json_decode((string) $importacion['metadata'], true);
            if (is_array($decoded)) {
                $actual = $decoded;
            }
        }

        $merged = array_merge($actual, $metadata);

        return $this->actualizarResumen($id, [
            'metadata' => json_encode($merged, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function obtenerMetadataArray($id)
    {
        $importacion = $this->findById((int) $id);
        if (!$importacion || empty($importacion['metadata'])) {
            return [];
        }

        $decoded = json_decode((string) $importacion['metadata'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public function cerrar($id, $total, $exitosos, $fallidos, $errores = [])
    {
        $sql = "UPDATE {$this->table}
                SET total_registros = ?, registros_exitosos = ?, registros_fallidos = ?, errores = ?
                WHERE id = ?";

        return $this->execute($sql, [
            (int) $total,
            (int) $exitosos,
            (int) $fallidos,
            !empty($errores) ? json_encode($errores, JSON_UNESCAPED_UNICODE) : null,
            (int) $id
        ]);
    }
}
