# Algoritmo de Conciliación con Fuzzy Match

## Objetivo
Conciliar facturas XML con gastos consolidados usando matching inteligente por similitud de número y proveedor.

---

## Reglas de Normalización

### 1. Normalización de Número de Factura

**Entrada:** String con número de factura (puede incluir prefijos, guiones, espacios)

**Proceso:**
```
1. Remover espacios en blanco (inicio, fin, internos)
2. Remover guiones (-), barras (/), puntos (.)
3. Convertir a mayúsculas
4. Quitar ceros a la izquierda del componente numérico
5. Si queda vacío, usar "0"
```

**Ejemplos:**
```
"FAC-001234"        → "FAC1234"
"  A - 0000567  "   → "A567"
"12.345/2024"       → "123452024"
"000000"            → "0"
```

**Implementación PHP (sugerida):**
```php
function normalizarNumeroFactura($numero) {
    // Remover espacios y caracteres especiales
    $normalizado = preg_replace('/[\s\-\.\/]/', '', trim($numero));
    
    // Convertir a mayúsculas
    $normalizado = strtoupper($normalizado);
    
    // Quitar ceros a la izquierda de la parte numérica
    $normalizado = preg_replace('/^0+/', '', $normalizado);
    
    return empty($normalizado) ? '0' : $normalizado;
}
```

### 2. Normalización de Proveedor

**Entrada:** Razón social del proveedor

**Proceso:**
```
1. Convertir a mayúsculas
2. Remover acentos y diacríticos (á→A, é→E, ñ→N)
3. Remover tokens legales comunes:
   - S.A., SA, S.A. DE C.V., SOCIEDAD ANONIMA
   - S.R.L., SRL, SOCIEDAD DE RESPONSABILIDAD LIMITADA
   - S. DE R.L., S DE RL
   - LTDA, LIMITADA
   - S.C., SC, SOCIEDAD CIVIL
   - DE C.V., DE CV, C.V., CV
   - & CIA, Y CIA, Y COMPAÑIA
4. Remover puntuación (, . ; : - _ )
5. Remover palabras vacías (DE, LA, LOS, LAS, EL)
6. Colapsar espacios múltiples a uno solo
7. Trim (inicio y fin)
```

**Ejemplos:**
```
"Tecnología Avanzada S.A. de C.V."  → "TECNOLOGIA AVANZADA"
"López & García, S.R.L."            → "LOPEZ GARCIA"
"La Empresa Mexicana S.C."          → "EMPRESA MEXICANA"
"ACME Corporation LTDA."            → "ACME CORPORATION"
```

**Implementación PHP (sugerida):**
```php
function normalizarProveedor($razonSocial) {
    // Mayúsculas
    $normalizado = mb_strtoupper($razonSocial, 'UTF-8');
    
    // Remover acentos
    $acentos = ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N'];
    $normalizado = strtr($normalizado, $acentos);
    
    // Remover tokens legales (orden importa: del más largo al más corto)
    $tokensLegales = [
        'SOCIEDAD ANONIMA DE CAPITAL VARIABLE',
        'SOCIEDAD DE RESPONSABILIDAD LIMITADA DE CAPITAL VARIABLE',
        'SOCIEDAD DE RESPONSABILIDAD LIMITADA',
        'SOCIEDAD ANONIMA',
        'SOCIEDAD CIVIL',
        'S.A. DE C.V.', 'SA DE CV', 'S.A DE C.V', 'SA DE C.V.',
        'S. DE R.L. DE C.V.', 'S DE RL DE CV',
        'S. DE R.L.', 'S DE RL', 'S.R.L.', 'SRL',
        'DE C.V.', 'DE CV', 'C.V.', 'CV',
        'S.A.', 'SA', 'S.C.', 'SC',
        'LTDA.', 'LTDA', 'LIMITADA',
        '& CIA.', '& CIA', 'Y CIA', 'Y COMPANIA'
    ];
    
    foreach ($tokensLegales as $token) {
        $normalizado = str_replace($token, ' ', $normalizado);
    }
    
    // Remover puntuación
    $normalizado = preg_replace('/[,\.;:\-_]/', ' ', $normalizado);
    
    // Remover palabras vacías
    $palabrasVacias = ['DE', 'LA', 'LOS', 'LAS', 'EL', 'Y'];
    foreach ($palabrasVacias as $palabra) {
        $normalizado = preg_replace('/\b' . $palabra . '\b/', ' ', $normalizado);
    }
    
    // Colapsar espacios
    $normalizado = preg_replace('/\s+/', ' ', $normalizado);
    
    return trim($normalizado);
}
```

---

## Algoritmo de Scoring

### 1. Score de Número de Factura

**Criterios:**
- **100 puntos:** Match exacto (número normalizado idéntico)
- **80 puntos:** Uno contiene al otro (ej: "FAC1234" vs "1234")
- **60 puntos:** Distancia de Levenshtein ≤ 2 caracteres
- **40 puntos:** Distancia de Levenshtein ≤ 4 caracteres
- **0 puntos:** Distancia > 4 o totalmente diferente

**Implementación PHP:**
```php
function calcularScoreNumero($num1, $num2) {
    $num1_norm = normalizarNumeroFactura($num1);
    $num2_norm = normalizarNumeroFactura($num2);
    
    // Exacto
    if ($num1_norm === $num2_norm) {
        return 100.0;
    }
    
    // Contención
    if (strpos($num1_norm, $num2_norm) !== false || 
        strpos($num2_norm, $num1_norm) !== false) {
        return 80.0;
    }
    
    // Levenshtein
    $distancia = levenshtein($num1_norm, $num2_norm);
    
    if ($distancia <= 2) return 60.0;
    if ($distancia <= 4) return 40.0;
    
    return 0.0;
}
```

### 2. Score de Proveedor

**Criterios:**
- **100 puntos:** Match exacto (normalizado idéntico)
- **90 puntos:** Similitud ≥ 95% (similar_text)
- **75 puntos:** Similitud ≥ 85%
- **60 puntos:** Similitud ≥ 75%
- **40 puntos:** Similitud ≥ 60%
- **0 puntos:** Similitud < 60%

**Implementación PHP:**
```php
function calcularScoreProveedor($prov1, $prov2) {
    $prov1_norm = normalizarProveedor($prov1);
    $prov2_norm = normalizarProveedor($prov2);
    
    // Exacto
    if ($prov1_norm === $prov2_norm) {
        return 100.0;
    }
    
    // Similar text
    similar_text($prov1_norm, $prov2_norm, $porcentaje);
    
    if ($porcentaje >= 95) return 90.0;
    if ($porcentaje >= 85) return 75.0;
    if ($porcentaje >= 75) return 60.0;
    if ($porcentaje >= 60) return 40.0;
    
    return 0.0;
}
```

### 3. Score Total Ponderado

**Fórmula:**
```
Score Total = (Score Número × 60%) + (Score Proveedor × 40%)
```

**Pesos configurables en `configuracion_conciliacion`:**
- `peso_numero`: 60 (default)
- `peso_proveedor`: 40 (default)

**Implementación PHP:**
```php
function calcularScoreTotal($scoreNumero, $scoreProveedor, $pesoNum = 60, $pesoProv = 40) {
    return ($scoreNumero * $pesoNum / 100) + ($scoreProveedor * $pesoProv / 100);
}
```

---

## Proceso de Conciliación (Botón "Conciliar")

### PASO 1: Preparación

```
1. Verificar que existan datos en facturas_xml y gastos_consolidados
2. Cargar configuraciones (umbrales, pesos, tolerancias)
3. Inicializar contadores: exactos=0, probables=0, pendientes=0, errores=0
```

### PASO 2: Match Exacto (Primera Pasada)

**Para cada factura XML no conciliada:**

```
1. Obtener: numero_factura_asistente, proveedor_normalizado
2. Buscar en gastos_consolidados:
   WHERE numero_factura_normalizado = [factura.numero_normalizado]
     AND proveedor_normalizado = [factura.proveedor_normalizado]
     AND NOT EXISTS (conciliacion con este gasto)

3. Si encontrado (único):
   a. Calcular diferencias: base, iva, total
   b. Calcular score: numero=100, proveedor=100, total=100
   c. Determinar estado:
      - Si diferencias ≤ tolerancia → 'conciliada'
      - Si diferencias > tolerancia → 'con_diferencias'
   d. Insertar en conciliaciones:
      - match_tipo = 'exacto'
      - scores = 100
      - estado según diferencias
   e. Incrementar contador: exactos++
   f. Registrar en log_matching

4. Si no encontrado:
   → Pasar a PASO 3 (Match Probable)
```

### PASO 3: Match Probable (Segunda Pasada)

**Para cada factura XML sin match exacto:**

```
1. Buscar candidatos en gastos_consolidados:
   - Que NO tengan conciliación
   - Que el número tenga al menos 50% de similitud
   - LIMIT 10 (para no explotar)

2. Calcular scores para cada candidato:
   a. score_numero = calcularScoreNumero(factura.numero, gasto.numero)
   b. score_proveedor = calcularScoreProveedor(factura.prov, gasto.prov)
   c. score_total = calcularScoreTotal(score_numero, score_proveedor)

3. Ordenar candidatos por score_total DESC

4. Evaluar mejor candidato:
   
   a. Si score_total >= umbral_match_probable (75):
      - Verificar que no haya empate (2do candidato con score similar)
      - Si NO hay empate:
        * Calcular diferencias
        * Determinar estado según diferencias
        * Insertar conciliación:
          - match_tipo = 'probable'
          - scores registrados
          - observaciones_match = "Match automático por similitud"
        * Incrementar contador: probables++
        * Registrar en log_matching
      
      - Si HAY empate (diferencia < 5 puntos):
        * NO crear conciliación
        * Marcar para revisión manual
        * Registrar en log_matching: accion='empate_detectado'
   
   b. Si score_total < umbral_match_probable:
      - NO crear conciliación automática
      - Marcar para revisión manual
      - Registrar en log_matching: accion='score_bajo'
```

### PASO 4: Casos Especiales

**Facturas sin match (Pendientes):**
```
Para cada factura_xml sin conciliación:
1. Insertar en conciliaciones:
   - factura_xml_id = [id]
   - gasto_consolidado_id = NULL
   - estado_id = (estado 'pendiente')
   - match_tipo = 'manual'
   - scores = NULL
   - observaciones_match = "Factura sin gasto asociado"
2. Incrementar contador: pendientes++
```

**Gastos sin match (Gasto sin XML):**
```
Para cada gasto_consolidado sin conciliación:
1. Insertar en conciliaciones:
   - factura_xml_id = NULL
   - gasto_consolidado_id = [id]
   - estado_id = (estado 'gasto_sin_xml')
   - match_tipo = 'manual'
   - scores = NULL
   - observaciones_match = "Gasto sin factura XML"
2. Incrementar contador: sin_xml++
```

### PASO 5: Cálculo de Diferencias y Estados

**Para cada conciliación con factura Y gasto:**

```php
function determinarEstado($factura, $gasto, $tolerancia = 0.05, $toleranciaPct = 1.0) {
    $diff_base = abs($factura->subtotal - $gasto->suma_base);
    $diff_iva = abs($factura->iva - $gasto->suma_iva);
    $diff_total = abs($factura->total - $gasto->suma_total);
    
    $pct_diferencia = ($factura->total > 0) 
        ? ($diff_total / $factura->total * 100) 
        : 0;
    
    // Dentro de tolerancia de redondeo
    if ($diff_total <= $tolerancia) {
        return 'conciliada';
    }
    
    // Dentro de tolerancia porcentual
    if ($pct_diferencia <= $toleranciaPct) {
        return 'conciliada';
    }
    
    // Fuera de tolerancia
    return 'con_diferencias';
}
```

### PASO 6: Reporte Final

```
Retornar resumen:
{
    "total_facturas": X,
    "total_gastos": Y,
    "matches_exactos": N1,
    "matches_probables": N2,
    "pendientes": N3,
    "gastos_sin_xml": N4,
    "conciliadas": N5,
    "con_diferencias": N6,
    "requieren_revision": N7,
    "duracion_segundos": T
}
```

---

## Vista de Reporte con Semáforo

### Diseño de la Vista

```sql
CREATE OR REPLACE VIEW `v_reporte_conciliacion_detallado` AS
SELECT 
    c.id AS conciliacion_id,
    c.fecha_conciliacion,
    
    -- Estado general
    e.codigo AS estado_codigo,
    e.nombre AS estado_nombre,
    e.color_hex AS estado_color,
    c.match_tipo,
    
    -- Datos de factura
    f.id AS factura_id,
    f.consecutivo_completo AS factura_folio,
    f.numero_factura_asistente AS factura_numero,
    f.fecha_emision AS factura_fecha,
    f.subtotal AS factura_base,
    f.iva AS factura_iva,
    f.total AS factura_total,
    p.razon_social AS factura_proveedor,
    p.rfc AS factura_rfc,
    f.proveedor_normalizado AS factura_prov_norm,
    
    -- Datos de gasto
    g.id AS gasto_id,
    g.numero_factura AS gasto_numero,
    g.proveedor_texto AS gasto_proveedor,
    g.proveedor_normalizado AS gasto_prov_norm,
    g.suma_base AS gasto_base,
    g.suma_iva AS gasto_iva,
    g.suma_total AS gasto_total,
    g.cantidad_items AS gasto_items,
    g.fecha_min AS gasto_fecha_min,
    g.fecha_max AS gasto_fecha_max,
    
    -- Scores
    c.score_numero,
    c.score_proveedor,
    c.score_total,
    
    -- Diferencias
    c.diferencia_base,
    c.diferencia_iva,
    c.diferencia_total,
    c.porcentaje_diferencia,
    
    -- Semáforos (valores calculados)
    CASE 
        WHEN c.score_numero >= 100 THEN 'verde'
        WHEN c.score_numero >= 80 THEN 'amarillo'
        WHEN c.score_numero >= 60 THEN 'naranja'
        ELSE 'rojo'
    END AS semaforo_numero,
    
    CASE 
        WHEN c.score_proveedor >= 100 THEN 'verde'
        WHEN c.score_proveedor >= 90 THEN 'amarillo'
        WHEN c.score_proveedor >= 75 THEN 'naranja'
        ELSE 'rojo'
    END AS semaforo_proveedor,
    
    CASE 
        WHEN c.porcentaje_diferencia IS NULL THEN 'gris'
        WHEN c.porcentaje_diferencia <= 1.0 THEN 'verde'
        WHEN c.porcentaje_diferencia <= 5.0 THEN 'amarillo'
        WHEN c.porcentaje_diferencia <= 10.0 THEN 'naranja'
        ELSE 'rojo'
    END AS semaforo_montos,
    
    -- Observaciones
    c.observaciones_match,
    c.notas

FROM conciliaciones c
INNER JOIN catalogo_estados e ON c.estado_id = e.id
LEFT JOIN facturas_xml f ON c.factura_xml_id = f.id
LEFT JOIN proveedores p ON f.proveedor_id = p.id
LEFT JOIN gastos_consolidados g ON c.gasto_consolidado_id = g.id;
```

### Consulta de Reporte Filtrado

```sql
-- Ejemplo: Reporte de matches probables con diferencias
SELECT 
    factura_numero,
    factura_proveedor,
    gasto_numero,
    gasto_proveedor,
    score_total,
    semaforo_numero,
    semaforo_proveedor,
    semaforo_montos,
    diferencia_total,
    porcentaje_diferencia,
    estado_nombre
FROM v_reporte_conciliacion_detallado
WHERE match_tipo = 'probable'
  AND estado_codigo = 'con_diferencias'
  AND fecha_conciliacion >= '2026-01-01'
ORDER BY porcentaje_diferencia DESC;
```

---

## Interfaz de Usuario (Sugerencias)

### Dashboard Principal

```
┌─────────────────────────────────────────────────────────┐
│  [🔄 Ejecutar Conciliación]  [⚙️ Configurar]  [📊 Reportes] │
└─────────────────────────────────────────────────────────┘

Resumen General:
┌──────────────┬──────────────┬──────────────┬──────────────┐
│ ✅ Conciliadas│ ⚠️ Diferencias│ ⏳ Pendientes │ ❌ Sin XML    │
│     234      │      45      │      12      │      8       │
└──────────────┴──────────────┴──────────────┴──────────────┘

Tabla de Conciliaciones:
┌────────┬─────────┬─────────────┬─────────────┬───────┬──────────┬─────────┬────────┐
│ Estado │ Factura │ Proveedor   │ Gasto       │ Match │ Semáforo │ Dif $   │ Acción │
├────────┼─────────┼─────────────┼─────────────┼───────┼──────────┼─────────┼────────┤
│ ✅     │ A-1234  │ ACME CORP   │ A1234       │ 100%  │ 🟢🟢🟢   │ $0.00   │ [Ver]  │
│ ⚠️     │ B-5678  │ TECH INC    │ B-005678    │ 85%   │ 🟡🟢🟡   │ $15.50  │ [Rev]  │
│ ⏳     │ C-9999  │ SERVICES LLC│ -           │ -     │ 🔴--     │ -       │ [Link] │
└────────┴─────────┴─────────────┴─────────────┴───────┴──────────┴─────────┴────────┘
```

### Página de Revisión Manual

```
Conciliación #45 - Requiere Revisión

┌─ FACTURA XML ───────────────────────────────────────┐
│ Número: FAC-001234                                  │
│ Folio: UUID-XXXXXXXX-XXXX-XXXX                     │
│ Proveedor: Tecnología Avanzada S.A. de C.V.        │
│ Base: $10,000.00  |  IVA: $1,600.00  |  Total: $11,600.00 │
└─────────────────────────────────────────────────────┘

┌─ GASTO CONSOLIDADO (Candidato) ─────────────────────┐
│ Número: FAC1234                                     │
│ Proveedor: TECNOLOGIA AVANZADA SA                   │
│ Base: $10,015.00  |  IVA: $1,602.40  |  Total: $11,617.40 │
│ Items: 3 líneas consolidadas                        │
└─────────────────────────────────────────────────────┘

┌─ ANÁLISIS DE MATCH ─────────────────────────────────┐
│ Score Número: 🟢 95 / 100                           │
│ Score Proveedor: 🟢 92 / 100                        │
│ Score Total: 🟢 93.6 / 100                          │
│                                                      │
│ Diferencia Total: $17.40 (0.15%)                    │
│ Estado sugerido: ✅ Conciliada (dentro tolerancia)  │
└─────────────────────────────────────────────────────┘

[✅ Aprobar Match]  [❌ Rechazar]  [🔗 Ver Detalles]  [📝 Notas]
```

---

## Configuración Recomendada

### Umbrales por Defecto

```
peso_numero: 60%
peso_proveedor: 40%
umbral_match_exacto: 100
umbral_match_probable: 75
umbral_match_bajo: 50
tolerancia_redondeo: $0.05
tolerancia_porcentaje: 1.0%
```

### Ajustes según Casos de Uso

**Alta precisión (auditoría):**
- umbral_match_probable: 90
- tolerancia_porcentaje: 0.5%
- match_automatico: false

**Eficiencia (volumen alto):**
- umbral_match_probable: 70
- tolerancia_porcentaje: 2.0%
- match_automatico: true

---

**Versión:** 1.0  
**Fecha:** 2026-02-11
