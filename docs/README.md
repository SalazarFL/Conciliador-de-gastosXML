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

Ver **[INSTALACION.md](INSTALACION.md)**: la aplicación se instala en la
computadora de cada persona, la base de datos es una sola en el servidor y los
documentos viven en una carpeta de SharePoint sincronizada. Ese documento
explica cómo se monta, cómo se actualiza y qué hacer cuando alguien reporta un
problema.

Comprobación rápida de cualquier instalación: `php cli/diagnostico.php`

## Características
- Carga de facturas XML (CFDI)
- Registro de gastos
- Conciliación automática
- Generación de reportes

---
Desarrollado para gestión de conciliación fiscal.
