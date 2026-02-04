# Documentación de Base de Datos - XMLConcilia

## Información General

**Base de datos:** `bd_xmlconcilia`  
**Motor:** MySQL 5.7+ / MariaDB (XAMPP)  
**Charset:** utf8mb4_unicode_ci  
**Motor de almacenamiento:** InnoDB

---

## Tablas Principales

### 1. `proveedores`
**Propósito:** Catálogo normalizado de proveedores/emisores de facturas.

**Campos principales:**
- `id` - Identificador único
- `rfc` - RFC del proveedor (único, puede ser NULL)
- `razon_social` - Nombre completo del proveedor
- `razon_social_normalizada` - Versión normalizada (sin acentos, minúsculas) para facilitar búsquedas y matching
- `alias` - Nombre corto o alias opcional
- `activo` - Bandera para proveedores activos/inactivos

**Índices:**
- `uk_rfc` - Único en RFC
- `idx_razon_social_norm` - Para búsquedas por nombre normalizado
- `idx_activo` - Filtrado de proveedores activos

---

### 2. `catalogo_estados`
**Propósito:** Catálogo de estados posibles para el proceso de conciliación.

**Estados predefinidos:**

| Código | Nombre | Descripción | Color |
|--------|--------|-------------|-------|
| `conciliada` | Conciliada | Factura y gasto coinciden perfectamente | #28a745 (verde) |
| `con_diferencias` | Con Diferencias | Factura y gasto existen pero con diferencias en montos | #ffc107 (amarillo) |
| `pendiente` | Pendiente | Factura XML sin gasto asociado | #17a2b8 (azul) |
| `gasto_sin_xml` | Gasto sin XML | Gasto registrado sin factura XML | #dc3545 (rojo) |

**Campos:**
- `codigo` - Identificador textual único
- `nombre` - Nombre descriptivo
- `color_hex` - Color para interfaz de usuario
- `orden` - Orden de presentación

---

### 3. `importaciones`
**Propósito:** Auditoría de todas las cargas masivas (XML y gastos).

**Campos principales:**
- `tipo` - ENUM('xml', 'gastos')
- `archivo_origen` - Nombre del archivo importado
- `total_registros` - Total de registros procesados
- `registros_exitosos` / `registros_fallidos` - Contadores
- `errores` - JSON con detalles de errores
- `importado_por` - Usuario/operador (NULL por ahora, preparado para autenticación)
- `duracion_segundos` - Tiempo de procesamiento
- `metadata` - JSON con información adicional

**Índices:**
- `idx_tipo` - Filtrado por tipo de importación
- `idx_fecha` - Orden cronológico

---

### 4. `facturas_xml`
**Propósito:** Almacén de facturas electrónicas (CFDI) procesadas desde archivos XML.

**Campos principales:**
- `consecutivo_completo` - UUID o folio fiscal completo (único)
- `numero_factura_asistente` - Últimos 10 dígitos del consecutivo, sin ceros a la izquierda
- `proveedor_id` - FK a tabla proveedores
- `fecha_emision` - Fecha de emisión de la factura
- `subtotal` / `iva` / `total` - Montos (DECIMAL 18,2)
- `moneda` - Código ISO 4217 (ej: MXN, USD)
- `archivo_xml` - Nombre del archivo
- `hash_xml` - SHA-256 del contenido (opcional, para detectar duplicados)
- `xml_contenido` - Contenido completo del XML (opcional)

**Índices:**
- `uk_consecutivo` - Único, evita duplicados
- `idx_numero_factura` - Para matching con gastos
- `idx_proveedor` - Filtrado por proveedor
- `idx_fecha_emision` - Reportes por fecha
- `idx_fecha_proveedor` - Compuesto para reportes

**Relaciones:**
- FK a `proveedores` (RESTRICT)
- FK a `importaciones` (SET NULL)

---

### 5. `gastos_raw`
**Propósito:** Almacén de líneas individuales de gastos importados desde Excel/CSV.

**Campos principales:**
- `numero_factura` - Número de factura reportado en el gasto
- `proveedor_texto` - Nombre del proveedor (texto libre)
- `fecha_gasto` - Fecha del gasto
- `monto_base` / `iva` / `total` - Montos
- `categoria`, `centro_costos`, `proyecto` - Campos de clasificación
- `fila_archivo` - Número de fila en el archivo original
- `metadata` - JSON para campos adicionales flexibles

**Índices:**
- `idx_numero_factura` - Para consolidación
- `idx_fecha_gasto` - Reportes por fecha
- `idx_proveedor_texto` - Búsquedas por proveedor

**Relaciones:**
- FK a `importaciones` (SET NULL)

---

### 6. `gastos_consolidados`
**Propósito:** Gastos agrupados y consolidados por número de factura.

**Regla de negocio:** Se agrupa por `numero_factura`, sumando los montos y contando las líneas.

**Campos principales:**
- `numero_factura` - Único, el número de factura consolidado
- `cantidad_items` - Número de líneas agrupadas
- `fecha_min` / `fecha_max` - Rango de fechas de los gastos
- `suma_base` / `suma_iva` / `suma_total` - Sumas consolidadas

**Índices:**
- `uk_numero_factura` - Único
- `idx_fecha_min`, `idx_fecha_max` - Reportes por fecha

---

### 7. `conciliaciones`
**Propósito:** Registro del resultado del proceso de conciliación entre facturas XML y gastos.

**Lógica de relaciones:**
- `factura_xml_id` puede ser NULL → caso: "gasto_sin_xml"
- `gasto_consolidado_id` puede ser NULL → caso: "pendiente" (factura sin gasto)
- **Restricción:** Al menos uno de los dos debe ser NOT NULL (constraint `chk_al_menos_uno`)

**Campos principales:**
- `estado_id` - FK a catalogo_estados
- `diferencia_base` / `diferencia_iva` / `diferencia_total` - Cálculo: (XML - Gasto)
- `porcentaje_diferencia` - Porcentaje de diferencia respecto al total
- `fecha_conciliacion` - Timestamp del proceso
- `notas` - Observaciones manuales

**Índices:**
- `idx_estado` - Filtrado por estado
- `idx_fecha_conciliacion` - Orden cronológico
- `idx_estado_fecha` - Compuesto para reportes

**Relaciones:**
- FK a `facturas_xml` (CASCADE)
- FK a `gastos_consolidados` (CASCADE)
- FK a `catalogo_estados` (RESTRICT)

---

## Reglas de Negocio

### Normalización del Número de Factura
**Regla para `numero_factura_asistente`:**
1. Del campo `consecutivo_completo` (UUID/folio fiscal), extraer los últimos 10 caracteres.
2. Remover ceros a la izquierda.
3. Si el resultado queda vacío, usar "0".

**Ejemplo:**
- Consecutivo: `A1B2C3D4-E5F6-G7H8-I9J0-K1L2M3N4O5P6`
- Últimos 10: `M3N4O5P6`
- Sin ceros: `M3N4O5P6` (no hay ceros al inicio)
- Resultado: `M3N4O5P6`

### Estados de Conciliación

1. **conciliada**: 
   - Existe factura XML
   - Existe gasto consolidado
   - Los montos coinciden exactamente (diferencia = 0)

2. **con_diferencias**:
   - Existe factura XML
   - Existe gasto consolidado
   - Los montos NO coinciden (diferencia > 0)

3. **pendiente**:
   - Existe factura XML
   - NO existe gasto consolidado
   - `gasto_consolidado_id` es NULL

4. **gasto_sin_xml**:
   - NO existe factura XML
   - Existe gasto consolidado
   - `factura_xml_id` es NULL

### Proceso de Consolidación de Gastos

1. Agrupar registros de `gastos_raw` por `numero_factura`
2. Calcular:
   - `cantidad_items` = COUNT(*)
   - `suma_base` = SUM(monto_base)
   - `suma_iva` = SUM(iva)
   - `suma_total` = SUM(total)
   - `fecha_min` = MIN(fecha_gasto)
   - `fecha_max` = MAX(fecha_gasto)
3. Insertar en `gastos_consolidados`

### Cálculo de Diferencias

```sql
diferencia_base = factura.subtotal - gasto.suma_base
diferencia_iva = factura.iva - gasto.suma_iva
diferencia_total = factura.total - gasto.suma_total
porcentaje_diferencia = ABS(diferencia_total / factura.total * 100)
```

---

## Índices para Reportes

### Índices principales que soportan reportes:

1. **Reportes por fecha:**
   - `facturas_xml.idx_fecha_emision`
   - `gastos_raw.idx_fecha_gasto`
   - `gastos_consolidados.idx_fecha_min`, `idx_fecha_max`
   - `conciliaciones.idx_fecha_conciliacion`

2. **Reportes por proveedor:**
   - `facturas_xml.idx_proveedor`
   - `facturas_xml.idx_fecha_proveedor` (compuesto)
   - `proveedores.idx_razon_social_norm`

3. **Reportes por estado:**
   - `conciliaciones.idx_estado`
   - `conciliaciones.idx_estado_fecha` (compuesto)

4. **Búsquedas por número de factura:**
   - `facturas_xml.idx_numero_factura`
   - `gastos_raw.idx_numero_factura`
   - `gastos_consolidados.uk_numero_factura`

---

## Vistas Útiles

### `v_conciliaciones_completas`
Vista que combina datos de conciliaciones con información completa de facturas, proveedores y gastos.

**Uso típico:**
```sql
SELECT * FROM v_conciliaciones_completas 
WHERE estado_codigo = 'con_diferencias'
  AND fecha_emision BETWEEN '2026-01-01' AND '2026-01-31'
ORDER BY diferencia_total DESC;
```

---

## Consultas SQL Típicas para Reportes

### 1. Reporte de Conciliaciones por Estado y Rango de Fechas

```sql
SELECT 
    e.nombre AS estado,
    COUNT(*) AS total_registros,
    SUM(CASE WHEN c.factura_xml_id IS NOT NULL THEN f.total ELSE 0 END) AS total_facturas,
    SUM(CASE WHEN c.gasto_consolidado_id IS NOT NULL THEN g.suma_total ELSE 0 END) AS total_gastos,
    SUM(ABS(c.diferencia_total)) AS suma_diferencias
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
LEFT JOIN facturas_xml f ON c.factura_xml_id = f.id
LEFT JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id
WHERE c.fecha_conciliacion BETWEEN '2026-01-01' AND '2026-01-31'
GROUP BY e.id, e.nombre
ORDER BY e.orden;
```

### 2. Reporte de Facturas por Proveedor con Estado de Conciliación

```sql
SELECT 
    p.razon_social AS proveedor,
    p.rfc,
    COUNT(DISTINCT f.id) AS total_facturas,
    SUM(f.total) AS monto_total_facturas,
    COUNT(DISTINCT c.id) AS total_conciliadas,
    SUM(CASE WHEN e.codigo = 'conciliada' THEN 1 ELSE 0 END) AS conciliadas_ok,
    SUM(CASE WHEN e.codigo = 'con_diferencias' THEN 1 ELSE 0 END) AS con_diferencias,
    SUM(CASE WHEN e.codigo = 'pendiente' THEN 1 ELSE 0 END) AS pendientes
FROM proveedores p
INNER JOIN facturas_xml f ON p.id = f.proveedor_id
LEFT JOIN conciliaciones c ON f.id = c.factura_xml_id
LEFT JOIN catalogo_estados e ON c.estado_id = e.id
WHERE f.fecha_emision BETWEEN '2026-01-01' AND '2026-12-31'
GROUP BY p.id, p.razon_social, p.rfc
ORDER BY monto_total_facturas DESC;
```

### 3. Reporte de Diferencias Significativas (>5%)

```sql
SELECT 
    f.numero_factura_asistente,
    f.fecha_emision,
    p.razon_social AS proveedor,
    f.total AS factura_total,
    g.suma_total AS gasto_total,
    c.diferencia_total,
    c.porcentaje_diferencia,
    c.notas
FROM conciliaciones c
INNER JOIN facturas_xml f ON c.factura_xml_id = f.id
INNER JOIN proveedores p ON f.proveedor_id = p.id
INNER JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id
INNER JOIN catalogo_estados e ON c.estado_id = e.id
WHERE e.codigo = 'con_diferencias'
  AND c.porcentaje_diferencia > 5.00
  AND f.fecha_emision BETWEEN '2026-01-01' AND '2026-12-31'
ORDER BY c.porcentaje_diferencia DESC;
```

---

## Diagrama de Relaciones (Texto)

```
proveedores (1) ──────< (N) facturas_xml
                              │
                              │ (0,1)
                              │
                              ▼
importaciones (1) ──────< (N) facturas_xml
importaciones (1) ──────< (N) gastos_raw
                              │
                              │ (agrupación)
                              │
                              ▼
                         gastos_consolidados
                              │
                              │ (0,1)
                              │
                              ▼
catalogo_estados (1) ──< (N) conciliaciones (N) >── (0,1) facturas_xml
                                                (N) >── (0,1) gastos_consolidados
```

---

## Mantenimiento y Optimización

### Recomendaciones:

1. **Limpieza periódica de logs:**
   - Purgar registros antiguos de `importaciones` después de 1 año
   - Opcional: archivar XML antiguos y limpiar campo `xml_contenido`

2. **Monitoreo de índices:**
   - Revisar uso de índices con `EXPLAIN` en consultas lentas
   - Considerar índices adicionales según patrones de uso

3. **Backup:**
   - Backup diario de la base de datos
   - Especial atención a carpetas de uploads (XML físicos)

4. **Optimización:**
   - Ejecutar `OPTIMIZE TABLE` mensualmente en tablas grandes
   - Monitorear tamaño de tabla `gastos_raw` (puede crecer rápidamente)

---

## Migración y Versionamiento

- **Versión actual:** 000_init.sql (estructura inicial)
- **Próximas migraciones:** Crear archivos `001_*.sql`, `002_*.sql`, etc.
- **Rollback:** Incluir scripts de reversión cuando sea necesario

---

**Última actualización:** 2026-02-03  
**Versión del esquema:** 1.0.0
