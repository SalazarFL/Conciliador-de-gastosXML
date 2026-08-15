# Nexo Fiscal

Sistema de control documental y conciliación fiscal.

## Descripción
Aplicación web desarrollada en PHP con arquitectura MVC para integrar correo,
comprobantes XML y PDF, facturas del ERP, pagos semanales, notas de crédito,
devoluciones y seguimiento documental.

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
- Captura y procesamiento de documentos desde correo
- Carga de comprobantes XML y PDF
- Conciliación con facturas del ERP
- Gestión de pagos semanales y notas de crédito
- Seguimiento de documentos e incidencias

---
Desarrollado para gestión de conciliación fiscal.
