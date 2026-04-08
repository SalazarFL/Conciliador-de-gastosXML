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
