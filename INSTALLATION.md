# Guía de Instalación - Nexo Fiscal

## ✅ Requisitos del Sistema

- **Servidor Web**: Apache 2.4+ con XAMPP
- **PHP**: 7.4 o superior
- **MySQL**: 5.7 o superior
- **Extensiones PHP requeridas**:
  - PDO y PDO_MySQL
  - XML
  - JSON
  - MBString
  - FileInfo

## 📋 Pasos de Instalación

### 1. Clonar o Descargar el Proyecto

El proyecto ya está ubicado en:
```
C:\xampp\htdocs\xmlconcilia
```

### 2. Configurar Base de Datos

#### 2.1 Crear la Base de Datos

1. Abrir **phpMyAdmin** (http://localhost/phpmyadmin)
2. Crear nueva base de datos `bd_xmlconcilia` con:
   - **Cotejamiento**: `utf8mb4_unicode_ci`

#### 2.2 Ejecutar Migraciones SQL (EN ORDEN)

Ejecutar los siguientes archivos SQL en phpMyAdmin en **este orden exacto**:

**1. Schema Inicial:**
```sql
-- Ejecutar: database/schema.sql
```

**2. Sistema de Revisión:**
```sql
-- Ejecutar: database/migrations/001_revision_y_estado.sql
```

**3. Campos Normalizados (PENDIENTE):**
```sql
-- Ejecutar: database/migrations/002_campos_normalizados.sql
```

### 3. Verificar Configuración de PHP

Copiar `app/config/local.ejemplo.php` como `app/config/local.php` y llenarlo
con los datos del servidor de base de datos:

```php
return [
    'database' => [
        'host'     => 'servidor',   // NO 'localhost': la base es una sola y compartida
        'port'     => '3306',
        'database' => 'bd_xmlconcilia',
        'username' => 'xmlconcilia',
        'password' => '',
    ],
    'pdftotext_path' => '',
];
```

`local.php` no se versiona (contiene la contraseña y el repositorio es
público). `app/config/database.php` solo lee ese archivo; no lo edites.

Ver [docs/INSTALACION.md](docs/INSTALACION.md) para la instalación completa.

Editar `app/config/config.php` para ajustar URLs:

```php
return [
    'app_url' => 'http://localhost/xmlconcilia/public',
    'base_uri' => '/xmlconcilia/public',
    // ...
];
```

### 4. Configurar Permisos de Carpetas

Crear y dar permisos de escritura a:

```bash
# En PowerShell (ejecutar desde c:\xampp\htdocs\xmlconcilia):

# Crear carpetas de storage si no existen
New-Item -ItemType Directory -Force -Path "storage/logs"
New-Item -ItemType Directory -Force -Path "storage/cache"

# Crear carpetas de uploads si no existen
New-Item -ItemType Directory -Force -Path "public/uploads/xml"
New-Item -ItemType Directory -Force -Path "public/uploads/gastos"
```

### 5. Iniciar Apache y MySQL

1. Abrir **XAMPP Control Panel**
2. Iniciar **Apache**
3. Iniciar **MySQL**

### 6. Acceder a la Aplicación

Abrir navegador en:
```
http://localhost/xmlconcilia/public/
```

## 🧪 Verificar Instalación

### Checklist de Verificación

- [ ] Base de datos `bd_xmlconcilia` creada
- [ ] Ejecutados: `schema.sql` + `001_revision_y_estado.sql` + `002_campos_normalizados.sql`
- [ ] Apache y MySQL corriendo en XAMPP
- [ ] Página principal carga sin errores
- [ ] Navegación entre secciones funciona
- [ ] No hay errores en `storage/logs/app.log`

### Verificar Conexión a Base de Datos

La página principal debe mostrar estadísticas (aunque estén en 0):
- ✅ Facturas XML: 0
- ✅ Gastos Registrados: 0
- ✅ Conciliadas: 0
- ✅ Pendientes Revisión: 0

Si aparecen los números, la conexión a BD está funcionando correctamente.

## 🔧 Solución de Problemas Comunes

### Error: "Base de datos no encontrada"

**Solución:**
1. Verificar que MySQL esté corriendo en XAMPP
2. Verificar que la base de datos `bd_xmlconcilia` existe en phpMyAdmin
3. Revisar credenciales en `app/config/local.php`
4. O mejor: `php cli/diagnostico.php`, que lo dice directamente

### Error: "Cannot modify header information"

**Solución:**
- Verificar que no haya espacios o BOM antes de `<?php` en archivos
- Verificar que no haya `echo` o `var_dump` antes de redirecciones

### Error 404 en todas las rutas

**Solución:**
1. Verificar que `.htaccess` existe en `public/`
2. Verificar que `mod_rewrite` esté habilitado en Apache (XAMPP lo tiene por defecto)
3. Reiniciar Apache en XAMPP

### Errores de PDO/Conexión

**Solución:**
1. Verificar extensión PDO habilitada: revisar `php.ini`
2. Descomentar líneas:
   ```ini
   extension=pdo_mysql
   extension=mysqli
   ```
3. Reiniciar Apache

### Páginas en blanco sin errores

**Solución:**
1. Habilitar display_errors en `php.ini`:
   ```ini
   display_errors = On
   error_reporting = E_ALL
   ```
2. Revisar logs: `storage/logs/app.log`
3. Revisar error_log de Apache: `C:\xampp\apache\logs\error.log`

## 📚 Siguiente Paso

Una vez instalado, consultar:
- **docs/README_BD.md** - Documentación completa de la base de datos
- **docs/ALGORITMO_CONCILIACION.md** - Algoritmo de fuzzy matching

## 🎯 Tareas Pendientes de Implementación

- [ ] Helpers de normalización (XmlParser, FileUploader, Validator)
- [ ] Controladores completos (FacturasController, GastosController, ConciliacionController, ReportesController)
- [ ] Vistas de importación y gestión
- [ ] Lógica de conciliación con fuzzy matching
- [ ] Sistema de revisión manual (frontend)
- [ ] Exportación de reportes

## 📞 Soporte

Si encuentras problemas durante la instalación, revisa:
1. `storage/logs/app.log` - Logs de la aplicación
2. `C:\xampp\apache\logs\error.log` - Logs de Apache
3. phpMyAdmin - Verificar que las tablas se crearon correctamente
