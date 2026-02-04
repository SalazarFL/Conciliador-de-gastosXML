# VerificadorXMLConciliacion

Sistema de verificación y conciliación de facturas XML con gastos.

## Descripción
Aplicación web desarrollada en PHP con arquitectura MVC para la gestión y conciliación de facturas XML contra registros de gastos.

## Estructura del Proyecto
- **public/**: Punto de entrada y recursos públicos
- **app/**: Lógica de la aplicación (MVC)
- **storage/**: Logs y caché
- **database/**: Scripts SQL y migraciones
- **docs/**: Documentación

## Requisitos
- PHP 7.4 o superior
- MySQL 5.7 o superior
- XAMPP (Apache + MySQL)

## Instalación
1. Clonar/copiar el proyecto en `htdocs/xmlconcilia`
2. Configurar la base de datos en `app/config/database.php`
3. Importar el esquema desde `database/schema.sql`
4. Acceder via `http://localhost/xmlconcilia/public/`

## Características
- Carga de facturas XML (CFDI)
- Registro de gastos
- Conciliación automática
- Generación de reportes

---
Desarrollado para gestión de conciliación fiscal.
