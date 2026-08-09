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

| Orden | Código | Nombre | Descripción | Color |
|-------|--------|--------|-------------|-------|
| 1 | `conciliada` | Conciliada | Factura y gasto coinciden perfectamente (100%) | #28a745 (verde) |
| 2 | `requiere_revision` | Requiere Revisión | Coincidencia parcial o sugerida; requiere validación humana | #fd7e14 (naranja) |
| 3 | `con_diferencias` | Con Diferencias | Factura y gasto existen pero con diferencias en montos | #ffc107 (amarillo) |
| 4 | `pendiente` | Pendiente | Factura XML sin gasto asociado | #17a2b8 (azul) |
| 5 | `gasto_sin_xml` | Gasto sin XML | Gasto registrado sin factura XML | #dc3545 (rojo) |

**⚠️ Regla de Negocio - "Verde solo si 100%":**
- **Estado `conciliada` (verde)** se asigna ÚNICAMENTE cuando:
  1. `numero_factura_normalizado` coincide exactamente (100%)
  2. `proveedor_normalizado` coincide exactamente (100%)
  3. Diferencias de montos están dentro de tolerancia configurada
  4. `score_total` = 100 y `match_tipo` = 'exacto'

- Si NO se cumple alguna condición → estado `requiere_revision` (naranja)
- El sistema NO concilia automáticamente casos con coincidencia parcial

**Campos:**
- `codigo` - Identificador textual único
- `nombre` - Nombre descriptivo
- `color_hex` - Color para interfaz de usuario
- `orden` - Orden de presentación (1=más favorable)

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
- `archivo_xml` / `archivo_pdf` - Nombre del archivo (sin la ruta)
- `ruta_xml` / `ruta_pdf` - Dónde está el documento, **relativo a la carpeta
  compartida** de cada computadora (ej. `2026/07 JULIO/Facturas/EN SISTEMA/FE_….xml`).
  La base nunca guarda el documento en sí, solo dónde encontrarlo; ver
  `app/helpers/RutaDocumento.php`.
- `hash_xml` - SHA-256 del contenido (opcional, para detectar duplicados)

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
**Propósito:** Gastos agrupados y consolidados por número de factura y proveedor.

**Regla de negocio:** Se agrupa por `(numero_factura, proveedor_texto)`, sumando los montos y contando las líneas. Esto permite que diferentes proveedores tengan el mismo número de factura.

**Campos principales:**
- `numero_factura` - Número de factura consolidado
- `proveedor_texto` - Proveedor predominante (normalizado, NOT NULL)
- `cantidad_items` - Número de líneas agrupadas
- `fecha_min` / `fecha_max` - Rango de fechas de los gastos
- `suma_base` / `suma_iva` / `suma_total` - Sumas consolidadas

**Índices:**
- `uk_numero_factura_proveedor` - Único compuesto (permite mismo número en diferentes proveedores)
- `idx_numero_factura` - Búsqueda por número solo
- `idx_fecha_min`, `idx_fecha_max` - Reportes por fecha

---

### 7. `conciliaciones`
**Propósito:** Registro del resultado del proceso de conciliación entre facturas XML y gastos con sistema de scoring y revisión manual.

**Lógica de relaciones:**
- `factura_xml_id` puede ser NULL → caso: "gasto_sin_xml"
- `gasto_consolidado_id` puede ser NULL → caso: "pendiente" (factura sin gasto)
- **Validación:** Al menos uno de los dos debe ser NOT NULL (validar en código PHP, no con CHECK)

**Campos de scoring (matching automático):**
- `score_numero` - Score de coincidencia del número (0-100)
- `score_proveedor` - Score de coincidencia del proveedor (0-100)
- `score_total` - Score total ponderado (0-100, calculado como: 60% número + 40% proveedor)
- `match_tipo` - ENUM('exacto', 'sugerido', 'manual')
  - `exacto`: Match 100% automático → verde
  - `sugerido`: Match parcial → requiere revisión (naranja)
  - `manual`: Conciliación forzada por usuario
- `observaciones_match` - Detalles del proceso de matching automático

**Campos de diferencias:**
- `diferencia_base` / `diferencia_iva` / `diferencia_total` - Cálculo: (XML - Gasto)
- `porcentaje_diferencia` - Porcentaje de diferencia respecto al total
- `estado_id` - FK a catalogo_estados

**Campos de revisión manual (auditoría):**
- `revisado` - TINYINT(1), indica si fue revisado (0=no, 1=sí)
- `revisado_por` - VARCHAR(100), usuario que revisó
- `revisado_en` - TIMESTAMP, fecha/hora de revisión
- `revision_comentario` - TEXT, comentarios del revisor

**Campos adicionales:**
- `fecha_conciliacion` - Timestamp del proceso de conciliación
- `notas` - Observaciones generales
- `conciliado_por` - Usuario que realizó la conciliación (futuro)

**Índices:**
- `idx_estado` - Filtrado por estado
- `idx_fecha_conciliacion` - Orden cronológico
- `idx_estado_fecha` - Compuesto para reportes
- `idx_revisado` - Filtrado por estado de revisión
- `idx_estado_revisado` - Compuesto estado + revisión
- `idx_revisado_en` - Fecha de revisión
- `idx_match_tipo` - Tipo de match
- `idx_score_total` - Score total

**Relaciones:**
- FK a `facturas_xml` (RESTRICT para preservar auditoría)
- FK a `gastos_consolidados` (RESTRICT para preservar auditoría)
- FK a `catalogo_estados` (RESTRICT)

**⚠️ Importante:** 
- No se permite borrar facturas o gastos si tienen conciliaciones asociadas (ON DELETE RESTRICT)
- El campo `revisado` debe marcarse en TRUE solo después de validación humana
- Use el procedimiento `sp_marcar_revisado(conciliacion_id, usuario, comentario)` para marcar revisiones

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

**Regla Principal: Verde solo si 100%**

Los estados se asignan según las siguientes reglas estrictas:

1. **conciliada** (🟢 Verde): 
   - Existe factura XML
   - Existe gasto consolidado
   - `numero_factura_normalizado` coincide 100%
   - `proveedor_normalizado` coincide 100%
   - Los montos coinciden dentro de tolerancia (≤ $0.05 o ≤ 1%)
   - `score_total` = 100
   - `match_tipo` = 'exacto'

2. **requiere_revision** (🟠 Naranja):
   - Existe factura XML
   - Existe gasto consolidado
   - Coincidencia PARCIAL detectada automáticamente
   - `score_total` < 100 (típicamente 75-99)
   - `match_tipo` = 'sugerido'
   - **Requiere validación humana antes de aprobar**
   - El sistema NO concilia automáticamente estos casos

3. **con_diferencias** (🟡 Amarillo):
   - Existe factura XML
   - Existe gasto consolidado
   - Números y proveedor coinciden
   - Los montos NO coinciden (diferencia > tolerancia)
   - `match_tipo` = 'exacto' o 'manual'
   - Puede requerir revisión según política

4. **pendiente** (🔵 Azul):
   - Existe factura XML
   - NO existe gasto consolidado
   - `gasto_consolidado_id` es NULL
   - Esperando carga de gastos

5. **gasto_sin_xml** (🔴 Rojo):
   - NO existe factura XML
   - Existe gasto consolidado
   - `factura_xml_id` es NULL
   - Puede indicar gasto no documentado o XML faltante

### Proceso de Consolidación de Gastos

1. Normalizar el `proveedor_texto` (quitar acentos, convertir a mayúsculas, etc.)
2. Agrupar registros de `gastos_raw` por `(numero_factura, proveedor_texto_normalizado)`
3. Calcular:
   - `cantidad_items` = COUNT(*)
   - `suma_base` = SUM(monto_base)
   - `suma_iva` = SUM(iva)
   - `suma_total` = SUM(total)
   - `fecha_min` = MIN(fecha_gasto)
   - `fecha_max` = MAX(fecha_gasto)
4. Insertar/actualizar en `gastos_consolidados`

**⚠️ Nota:** La clave única compuesta `(numero_factura, proveedor_texto)` permite que diferentes proveedores usen la misma numeración de facturas, evitando colisiones.

### Cálculo de Diferencias

```sql
diferencia_base = factura.subtotal - gasto.suma_base
diferencia_iva = factura.iva - gasto.suma_iva
diferencia_total = factura.total - gasto.suma_total
porcentaje_diferencia = ABS(diferencia_total / factura.total * 100)
```

---

## Sistema de Revisión Manual

### Propósito

El sistema de revisión manual permite auditoría y validación humana de las conciliaciones, especialmente aquellas marcadas como `requiere_revision` donde existe coincidencia parcial.

### Campos de Auditoría

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `revisado` | TINYINT(1) | Bandera de revisión (0=pendiente, 1=revisado) |
| `revisado_por` | VARCHAR(100) | Usuario que realizó la revisión |
| `revisado_en` | TIMESTAMP | Fecha y hora de la revisión |
| `revision_comentario` | TEXT | Comentarios del revisor |

### Flujo de Trabajo

1. **Conciliación Automática:**
   - Sistema ejecuta matching automático
   - Match 100% → estado `conciliada`, `revisado` = 0 (opcional revisar)
   - Match parcial → estado `requiere_revision`, `revisado` = 0 (obligatorio revisar)

2. **Revisión Manual:**
   - Usuario accede a lista de pendientes (`revisado` = 0)
   - Revisa detalles de cada conciliación
   - Verifica scores, diferencias, documentos
   - Marca como revisado con comentario

3. **Aprobación:**
   - Usuario aprueba o rechaza la conciliación
   - Si aprueba: actualizar `revisado` = 1, registrar usuario/fecha/comentario
   - Si rechaza: puede cambiar estado o eliminar conciliación

### Uso del Procedimiento Almacenado

```sql
-- Marcar conciliación como revisada
CALL sp_marcar_revisado(
    123,                              -- ID de conciliación
    'juan.perez@empresa.com',         -- Usuario revisor
    'Revisado y aprobado. Diferencia por redondeo.'  -- Comentario
);
```

### Interfaz de Usuario Sugerida

**Vista de Revisión:**
```
┌─────────────────────────────────────────────────┐
│ [📋 123] Requiere Revisión (Score: 85%)         │
├─────────────────────────────────────────────────┤
│ Factura: A-1234  |  Gasto: A1234                │
│ Match Número: 🟡 80%  |  Match Proveedor: 🟢 92% │
│ Diferencia: $15.00 (0.13%)                      │
│                                                  │
│ [☑ Marcar como Revisado]                        │
│ Comentario: [____________________________]      │
│ [✅ Aprobar] [❌ Rechazar] [📝 Notas]            │
└─────────────────────────────────────────────────┘
```

---

## Consultas SQL para Reportes

### A) Pendientes de Revisión (Estado requiere_revision y NO revisados)

```sql
-- Conciliaciones que requieren revisión manual
SELECT 
    c.id,
    f.numero_factura_asistente AS factura_num,
    p.razon_social AS proveedor,
    g.numero_factura AS gasto_num,
    c.score_total,
    c.diferencia_total,
    c.porcentaje_diferencia,
    c.fecha_conciliacion
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
LEFT JOIN facturas_xml f ON c.factura_xml_id = f.id
LEFT JOIN proveedores p ON f.proveedor_id = p.id
LEFT JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id
WHERE e.codigo = 'requiere_revision'
  AND c.revisado = 0
ORDER BY c.score_total DESC, c.fecha_conciliacion DESC;
```

**Uso de la vista simplificada:**
```sql
-- Usando vista pre-construida
SELECT *
FROM v_pendientes_revision
WHERE estado_codigo = 'requiere_revision'
LIMIT 100;
```

### B) Con Diferencias No Revisadas

```sql
-- Conciliaciones con diferencias que aún no han sido revisadas
SELECT 
    c.id,
    f.numero_factura_asistente,
    p.razon_social AS proveedor,
    f.total AS factura_total,
    g.suma_total AS gasto_total,
    c.diferencia_total,
    c.porcentaje_diferencia,
    CASE 
        WHEN c.porcentaje_diferencia <= 1.0 THEN '🟢 Menor'
        WHEN c.porcentaje_diferencia <= 5.0 THEN '🟡 Media'
        ELSE '🔴 Alta'
    END AS severidad
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
INNER JOIN facturas_xml f ON c.factura_xml_id = f.id
INNER JOIN proveedores p ON f.proveedor_id = p.id
INNER JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id
WHERE e.codigo = 'con_diferencias'
  AND c.revisado = 0
ORDER BY c.porcentaje_diferencia DESC;
```

### C) Conciliadas por Rango de Fechas y Proveedor

```sql
-- Reporte de conciliaciones exitosas filtrado
SELECT 
    f.fecha_emision,
    f.numero_factura_asistente,
    p.razon_social AS proveedor,
    p.rfc,
    f.total AS monto_factura,
    g.suma_total AS monto_gasto,
    c.diferencia_total,
    c.revisado,
    c.revisado_por,
    c.revisado_en
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
INNER JOIN facturas_xml f ON c.factura_xml_id = f.id
INNER JOIN proveedores p ON f.proveedor_id = p.id
INNER JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id
WHERE e.codigo = 'conciliada'
  AND f.fecha_emision BETWEEN '2026-01-01' AND '2026-01-31'
  AND p.id = 5  -- Cambiar por el ID del proveedor deseado
ORDER BY f.fecha_emision DESC;
```

**Variante con nombre de proveedor:**
```sql
-- Mismo reporte usando nombre de proveedor
SELECT 
    f.fecha_emision,
    f.numero_factura_asistente,
    p.razon_social AS proveedor,
    f.total AS monto_factura,
    c.match_tipo,
    c.score_total,
    c.revisado
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
INNER JOIN facturas_xml f ON c.factura_xml_id = f.id
INNER JOIN proveedores p ON f.proveedor_id = p.id
WHERE e.codigo = 'conciliada'
  AND f.fecha_emision BETWEEN '2026-01-01' AND '2026-12-31'
  AND p.razon_social LIKE '%ACME%'
ORDER BY f.fecha_emision DESC, f.total DESC;
```

### D) Resumen de Revisiones por Usuario

```sql
-- Estadísticas de revisión por usuario
SELECT 
    c.revisado_por AS usuario,
    COUNT(*) AS total_revisados,
    SUM(CASE WHEN e.codigo = 'conciliada' THEN 1 ELSE 0 END) AS aprobadas,
    SUM(CASE WHEN e.codigo = 'requiere_revision' THEN 1 ELSE 0 END) AS pendientes,
    SUM(CASE WHEN e.codigo = 'con_diferencias' THEN 1 ELSE 0 END) AS con_diferencias,
    MIN(c.revisado_en) AS primera_revision,
    MAX(c.revisado_en) AS ultima_revision,
    AVG(c.score_total) AS score_promedio
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
WHERE c.revisado = 1
  AND c.revisado_en >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY c.revisado_por
ORDER BY total_revisados DESC;
```

### E) Conciliaciones por Estado de Revisión

```sql
-- Vista general del estado de revisión
SELECT 
    e.nombre AS estado,
    COUNT(*) AS total,
    SUM(CASE WHEN c.revisado = 1 THEN 1 ELSE 0 END) AS revisadas,
    SUM(CASE WHEN c.revisado = 0 THEN 1 ELSE 0 END) AS sin_revisar,
    ROUND(SUM(CASE WHEN c.revisado = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) AS porcentaje_revision
FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
GROUP BY e.id, e.nombre, e.orden
ORDER BY e.orden;
```

**Usando vista pre-construida:**
```sql
SELECT * FROM v_resumen_revision;
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
Vista que combina datos de conciliaciones con información completa de facturas, proveedores y gastos, incluyendo campos de scoring y revisión.

**Campos incluidos:**
- Datos completos de factura y gasto
- Scores (numero, proveedor, total)
- Estado y tipo de match
- Campos de revisión (revisado, revisado_por, revisado_en, revision_comentario)
- Diferencias y porcentajes

**Uso típico:**
```sql
SELECT * FROM v_conciliaciones_completas 
WHERE estado_codigo = 'con_diferencias'
  AND fecha_emision BETWEEN '2026-01-01' AND '2026-01-31'
  AND revisado = 0
ORDER BY diferencia_total DESC;
```

### `v_pendientes_revision`
Vista especializada para listar conciliaciones que requieren atención (no revisadas con estado `requiere_revision` o `con_diferencias`).

**Ordenamiento:** Por prioridad (requiere_revision primero) y score total descendente.

**Uso típico:**
```sql
SELECT * FROM v_pendientes_revision LIMIT 50;
```

### `v_resumen_revision`
Vista de estadísticas agregadas por estado mostrando total, sin revisar, revisadas y porcentaje.

**Uso típico:**
```sql
SELECT * FROM v_resumen_revision;
```

**Resultado esperado:**
```
estado_codigo      | total | sin_revisar | revisadas | porcentaje_revisado
-------------------|-------|-------------|-----------|--------------------
conciliada         | 150   | 10          | 140       | 93.33
requiere_revision  | 45    | 38          | 7         | 15.56
con_diferencias    | 20    | 15          | 5         | 25.00
pendiente          | 12    | 12          | 0         | 0.00
gasto_sin_xml      | 8     | 8           | 0         | 0.00
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
Consideraciones Importantes de MySQL 5.7

### ⚠️ Limitaciones conocidas:

1. **CHECK constraints no funcionan:**
   - MySQL 5.7 acepta la sintaxis CHECK pero **la ignora completamente**
   - La validación de que `factura_xml_id` o `gasto_consolidado_id` sea NOT NULL debe hacerse en **código PHP**
   - En MySQL 8.0+ estos constraints sí funcionan

2. **Integridad referencial:**
   - Las FK usan `ON DELETE RESTRICT` para preservar auditoría
   - No se puede borrar una factura o gasto si tiene conciliaciones asociadas
   - Si necesitas eliminar, primero elimina las conciliaciones o usa soft-delete

3. **Normalización de proveedores:**
   - La clave única `(numero_factura, proveedor_texto)` requiere texto normalizado consistente
   - Implementar función de normalización en PHP antes de insertar
   - Ejemplo: "Empresa S.A. de C.V." → "EMPRESA SA DE CV"

## Mantenimiento y Optimización

### Recomendaciones:

1. **Limpieza periódica de logs:**
   - Purgar registros antiguos de `importaciones` después de 1 año
   - Los XML y PDF no ocupan espacio en la base: viven en la carpeta
     compartida y aquí solo queda la ruta relativa

2. **Monitoreo de índices:**
   - Revisar uso de índices con `EXPLAIN` en consultas lentas
   - Considerar índices adicionales según patrones de uso

3. **Backup:**
   - Backup diario de la base de datos
   - Especial atención a carpetas de uploads (XML físicos)

4. **Optimización:**
   - Ejecutar `OPTIMIZE TABLE` mensualmente en tablas grandes
   - Monitorear tamaño de tabla `gastos_raw` (puede crecer rápidamente)

5. **Validaciones en código:**
   - Validar que al menos un ID esté presente en conciliaciones
   - Normalizar `proveedor_texto` antes de insertar en `gastos_consolidados`
   - Validar unicidad de `(numero_factura, proveedor)` antes de consolidar
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
