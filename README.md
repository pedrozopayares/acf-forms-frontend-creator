# ACF Forms Frontend Creator

Plugin de WordPress que permite crear formularios en el frontend para registrar entradas de Custom Post Types (CPT) con campos de Advanced Custom Fields (ACF). Los registros quedan en estado **pendiente** hasta la aprobación del administrador.

## Características

- **Formularios automáticos** a partir de grupos de campos ACF existentes
- **Shortcode configurable**: `[acf_frontend_form post_type="mi-cpt"]`
- **Envío por AJAX** sin recarga de página
- **Validación en 3 capas**: HTML5, JavaScript client-side y PHP server-side
- **Títulos con consecutivo**: `TIPO 0001 - Nombre del registro`
- **Aprobación manual** con meta box de observaciones para el admin
- **Panel de configuración** con ajustes globales del plugin
- **Control de archivos**: tipos permitidos y tamaño máximo configurables
- **Anti-spam**: honeypot + rate limiting por IP
- **Notificaciones por email** al administrador al recibir nuevos registros
- **Soporte de campos**: text, textarea, wysiwyg, number, email, url, select, radio, checkbox, true_false, date_picker, datetime, time, color, file, image, group, repeater

## Requisitos

- WordPress 6.0+
- PHP 8.0+
- [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) (gratuito o PRO)

## Instalación

1. Descarga o clona este repositorio en `wp-content/plugins/acf-forms-frontend-creator/`
2. Activa el plugin desde el panel de WordPress
3. Ve a **ACF Forms → Configuración** para ajustar las opciones globales

## Uso

### Shortcode básico

```
[acf_frontend_form post_type="organizacion-esal"]
```

### Con grupo de campos específico

```
[acf_frontend_form field_group="group_abc123"]
```

### Parámetros del shortcode

| Parámetro     | Descripción                                      |
|---------------|--------------------------------------------------|
| `post_type`   | Slug del CPT donde se crean los registros        |
| `field_group` | Key del grupo de campos ACF a renderizar         |

Si solo se especifica `post_type`, el plugin detecta automáticamente el grupo de campos ACF asociado.

## Configuración

En **ACF Forms → Configuración** puedes ajustar:

- Mensaje de éxito personalizado
- Texto del botón de envío
- Límite de envío por IP (segundos)
- Honeypot anti-spam
- Notificaciones por email
- Tipos de archivo permitidos (ej: `jpg,png,pdf`)
- Tamaño máximo de archivo (MB)
- CSS personalizado

## Aprobación de registros

Los registros creados desde el frontend quedan en estado **pendiente**. El administrador puede:

1. Ir al listado del CPT en el admin
2. Revisar los datos del registro
3. Agregar observaciones en el meta box "Aprobación Frontend"
4. Cambiar el estado a **Publicado** para aprobar

## Estructura del plugin

```
acf-forms-frontend-creator/
├── acf-forms-frontend-creator.php   # Archivo principal, shortcode y AJAX
├── includes/
│   ├── class-form-renderer.php      # Renderizado del formulario HTML
│   ├── class-form-handler.php       # Validación y creación de posts
│   ├── class-admin-approval.php     # Meta box y columnas del admin
│   └── class-admin-settings.php     # Página de configuración
├── assets/
│   ├── js/frontend-form.js          # Repeaters y envío AJAX
│   └── css/frontend-form.css        # Estilos del formulario
└── README.md
```

## Licencia

GPL v2 o posterior.
