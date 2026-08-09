<?php
/**
 * Modelo de Importación
 * Registra procesos de carga de XML y gastos
 */

class Importacion extends Model
{
    protected $table = 'importaciones';

    // Carpeta de la importación, dentro de la carpeta compartida.
    protected $camposRuta = ['ruta_archivo'];

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
            RutaDocumento::relativa($data['ruta_archivo'] ?? '') ?: null,
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
            $params[] = in_array($field, $this->camposRuta, true)
                ? (RutaDocumento::relativa($data[$field]) ?: null)
                : $data[$field];
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

    public function getAllByTipo(string $tipo, int $limit = 50): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = "SELECT * FROM {$this->table}
                WHERE tipo = ?
                ORDER BY fecha_importacion DESC, id DESC
                LIMIT {$limit}";
        return $this->fetchAll($sql, [$tipo]) ?: [];
    }

    public function getAllXmlPorDocumento(string $tipoDocumento, int $limit = 50): array
    {
        $limit = max(1, min(1000, $limit));
        $tipoDocumento = strtoupper($tipoDocumento);
        if ($tipoDocumento === 'NC') {
            $condicion = "(i.metadata LIKE '%\"tipo_documento\":\"NC\"%'
                         OR EXISTS (SELECT 1 FROM facturas_xml f WHERE f.importacion_id=i.id AND f.tipo_documento='NC'))";
        } else {
            $condicion = "(i.metadata IS NULL OR i.metadata NOT LIKE '%\"tipo_documento\":\"NC\"%')
                         AND NOT EXISTS (SELECT 1 FROM facturas_xml f WHERE f.importacion_id=i.id AND f.tipo_documento='NC')";
        }
        $sql = "SELECT i.* FROM {$this->table} i WHERE i.tipo='xml' AND {$condicion}
                ORDER BY i.fecha_importacion DESC, i.id DESC LIMIT {$limit}";
        return $this->fetchAll($sql) ?: [];
    }

    public function clearByTipo(string $tipo): int
    {
        $sql = "DELETE FROM {$this->table} WHERE tipo = ?";
        return (int) $this->execute($sql, [$tipo]);
    }
}
