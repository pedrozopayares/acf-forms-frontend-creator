<?php
defined('ABSPATH') || exit;

/**
 * Admin settings page for ACF Forms Frontend Creator.
 */
class EFF_Admin_Settings {

    private string $option_key = 'eff_settings';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Get saved settings with defaults.
     */
    public static function get_settings(): array {
        $defaults = [
            'success_message'    => '¡Registro enviado correctamente! Será revisado por un administrador antes de ser publicado.',
            'submit_button_text' => 'Enviar registro',
            'rate_limit_seconds' => 60,
            'enable_honeypot'    => 1,
            'notify_admin'       => 1,
            'notify_email'       => get_option('admin_email'),
            'send_visitor_copy'       => 0,
            'visitor_email_subject'   => 'Copia de tu registro en {site_name}',
            'visitor_email_body_header' => 'Gracias por tu registro. A continuación encontrarás un resumen de la información enviada:',
            'visitor_email_body_footer' => 'Tu registro será revisado por un administrador antes de ser publicado.',
            'allowed_file_types' => 'jpg,jpeg,png,gif,webp,pdf',
            'max_file_size_mb'   => 2,
            'custom_upload_dir'  => '',
            'enable_terms_checkbox'  => 0,
            'terms_checkbox_text'    => 'Acepto los <a href="#">términos de servicio</a> y la <a href="#">política de privacidad</a>',
            'terms_required'         => 1,
            'custom_css'         => '',
        ];
        $saved = get_option('eff_settings', []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    public function register_menu(): void {
        add_menu_page(
            __('ACF Forms', 'acf-forms-frontend-creator'),
            __('ACF Forms', 'acf-forms-frontend-creator'),
            'manage_options',
            'eff-settings',
            [$this, 'render_page'],
            'dashicons-feedback',
            58
        );

        add_submenu_page(
            'eff-settings',
            __('Configuración', 'acf-forms-frontend-creator'),
            __('Configuración', 'acf-forms-frontend-creator'),
            'manage_options',
            'eff-settings',
            [$this, 'render_page']
        );

        add_submenu_page(
            'eff-settings',
            __('Registros pendientes', 'acf-forms-frontend-creator'),
            __('Pendientes', 'acf-forms-frontend-creator'),
            'manage_options',
            'eff-pending',
            [$this, 'render_pending_page']
        );

        add_submenu_page(
            'eff-settings',
            __('Guía de uso', 'acf-forms-frontend-creator'),
            __('Guía de uso', 'acf-forms-frontend-creator'),
            'manage_options',
            'eff-guide',
            [$this, 'render_guide_page']
        );
    }

    public function register_settings(): void {
        register_setting($this->option_key, $this->option_key, [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);

        // ── Section: General ──
        add_settings_section('eff_general', __('General', 'acf-forms-frontend-creator'), '__return_false', 'eff-settings');

        add_settings_field('success_message', __('Mensaje de éxito', 'acf-forms-frontend-creator'), [$this, 'field_textarea'], 'eff-settings', 'eff_general', [
            'id' => 'success_message',
            'description' => __('Mensaje que se muestra al visitante tras enviar el formulario.', 'acf-forms-frontend-creator'),
        ]);

        add_settings_field('submit_button_text', __('Texto del botón', 'acf-forms-frontend-creator'), [$this, 'field_text'], 'eff-settings', 'eff_general', [
            'id' => 'submit_button_text',
            'description' => __('Texto que aparece en el botón de envío.', 'acf-forms-frontend-creator'),
        ]);

        // ── Section: Security ──
        add_settings_section('eff_security', __('Seguridad', 'acf-forms-frontend-creator'), '__return_false', 'eff-settings');

        add_settings_field('rate_limit_seconds', __('Límite de envío (segundos)', 'acf-forms-frontend-creator'), [$this, 'field_number'], 'eff-settings', 'eff_security', [
            'id' => 'rate_limit_seconds',
            'description' => __('Tiempo mínimo entre envíos desde la misma IP.', 'acf-forms-frontend-creator'),
            'min' => 0,
            'max' => 3600,
        ]);

        add_settings_field('enable_honeypot', __('Honeypot anti-spam', 'acf-forms-frontend-creator'), [$this, 'field_checkbox'], 'eff-settings', 'eff_security', [
            'id' => 'enable_honeypot',
            'label' => __('Activar campo honeypot oculto para detectar bots.', 'acf-forms-frontend-creator'),
        ]);

        // ── Section: Notifications ──
        add_settings_section('eff_notifications', __('Notificaciones', 'acf-forms-frontend-creator'), '__return_false', 'eff-settings');

        add_settings_field('notify_admin', __('Notificar al administrador', 'acf-forms-frontend-creator'), [$this, 'field_checkbox'], 'eff-settings', 'eff_notifications', [
            'id' => 'notify_admin',
            'label' => __('Enviar email al recibir un nuevo registro desde el frontend.', 'acf-forms-frontend-creator'),
        ]);

        add_settings_field('notify_email', __('Email de notificación', 'acf-forms-frontend-creator'), [$this, 'field_email'], 'eff-settings', 'eff_notifications', [
            'id' => 'notify_email',
            'description' => __('Dirección de email para recibir notificaciones.', 'acf-forms-frontend-creator'),
        ]);

        // ── Section: Visitor Email Copy ──
        add_settings_section('eff_visitor_email', __('Copia al visitante', 'acf-forms-frontend-creator'), '__return_false', 'eff-settings');

        add_settings_field('send_visitor_copy', __('Enviar copia al visitante', 'acf-forms-frontend-creator'), [$this, 'field_checkbox'], 'eff-settings', 'eff_visitor_email', [
            'id' => 'send_visitor_copy',
            'label' => __('Enviar al visitante una copia del registro por email. Se usa el primer campo de tipo email del formulario.', 'acf-forms-frontend-creator'),
        ]);

        add_settings_field('visitor_email_subject', __('Asunto del email', 'acf-forms-frontend-creator'), [$this, 'field_text'], 'eff-settings', 'eff_visitor_email', [
            'id' => 'visitor_email_subject',
            'description' => __('Asunto del email enviado al visitante. Usa {site_name} como placeholder para el nombre del sitio.', 'acf-forms-frontend-creator'),
        ]);

        add_settings_field('visitor_email_body_header', __('Encabezado del email', 'acf-forms-frontend-creator'), [$this, 'field_textarea'], 'eff-settings', 'eff_visitor_email', [
            'id' => 'visitor_email_body_header',
            'description' => __('Texto introductorio que aparece antes del resumen de datos.', 'acf-forms-frontend-creator'),
        ]);

        add_settings_field('visitor_email_body_footer', __('Pie del email', 'acf-forms-frontend-creator'), [$this, 'field_textarea'], 'eff-settings', 'eff_visitor_email', [
            'id' => 'visitor_email_body_footer',
            'description' => __('Texto que aparece después del resumen de datos.', 'acf-forms-frontend-creator'),
        ]);

        // ── Section: Terms & Privacy ──
        add_settings_section('eff_terms', __('Términos y privacidad', 'acf-forms-frontend-creator'), '__return_false', 'eff-settings');

        add_settings_field('enable_terms_checkbox', __('Mostrar aceptación de términos', 'acf-forms-frontend-creator'), [$this, 'field_checkbox'], 'eff-settings', 'eff_terms', [
            'id' => 'enable_terms_checkbox',
            'label' => __('Mostrar casilla de aceptación de términos de servicio y política de privacidad.', 'acf-forms-frontend-creator'),
        ]);

        add_settings_field('terms_checkbox_text', __('Texto de la casilla', 'acf-forms-frontend-creator'), [$this, 'field_textarea_html'], 'eff-settings', 'eff_terms', [
            'id' => 'terms_checkbox_text',
            'description' => __('Texto que se muestra junto a la casilla. Se permite HTML para enlaces (<a>). Ejemplo: Acepto los <a href="/terminos">términos</a>.', 'acf-forms-frontend-creator'),
        ]);

        add_settings_field('terms_required', __('Aceptación obligatoria', 'acf-forms-frontend-creator'), [$this, 'field_checkbox'], 'eff-settings', 'eff_terms', [
            'id' => 'terms_required',
            'label' => __('El visitante debe aceptar los términos para enviar el formulario.', 'acf-forms-frontend-creator'),
        ]);

        // ── Section: Files ──
        add_settings_section('eff_files', __('Archivos', 'acf-forms-frontend-creator'), '__return_false', 'eff-settings');

        add_settings_field('allowed_file_types', __('Tipos de archivo permitidos', 'acf-forms-frontend-creator'), [$this, 'field_text'], 'eff-settings', 'eff_files', [
            'id' => 'allowed_file_types',
            'description' => __('Extensiones separadas por comas (ej: jpg,png,pdf).', 'acf-forms-frontend-creator'),
        ]);

        add_settings_field('max_file_size_mb', __('Tamaño máximo (MB)', 'acf-forms-frontend-creator'), [$this, 'field_number'], 'eff-settings', 'eff_files', [
            'id' => 'max_file_size_mb',
            'description' => __('Tamaño máximo por archivo en megabytes.', 'acf-forms-frontend-creator'),
            'min' => 1,
            'max' => 100,
        ]);

        add_settings_field('custom_upload_dir', __('Directorio de subida', 'acf-forms-frontend-creator'), [$this, 'field_text'], 'eff-settings', 'eff_files', [
            'id' => 'custom_upload_dir',
            'description' => __('Subdirectorio dentro de wp-content/uploads/ para almacenar archivos. Dejar vacío para usar la estructura predeterminada de WordPress (año/mes). Se puede sobreescribir por formulario con el atributo upload_dir del shortcode.', 'acf-forms-frontend-creator'),
        ]);

        // ── Section: Appearance ──
        add_settings_section('eff_appearance', __('Apariencia', 'acf-forms-frontend-creator'), '__return_false', 'eff-settings');

        add_settings_field('custom_css', __('CSS personalizado', 'acf-forms-frontend-creator'), [$this, 'field_code'], 'eff-settings', 'eff_appearance', [
            'id' => 'custom_css',
            'description' => __('CSS adicional para el formulario del frontend.', 'acf-forms-frontend-creator'),
        ]);
    }

    public function sanitize_settings(array $input): array {
        $upload_dir = sanitize_text_field($input['custom_upload_dir'] ?? '');
        // Security: reject path traversal and leading slashes
        $upload_dir = str_replace('..', '', $upload_dir);
        $upload_dir = trim($upload_dir, '/\\');

        return [
            'success_message'    => sanitize_textarea_field($input['success_message'] ?? ''),
            'submit_button_text' => sanitize_text_field($input['submit_button_text'] ?? 'Enviar registro'),
            'rate_limit_seconds' => absint($input['rate_limit_seconds'] ?? 60),
            'enable_honeypot'    => !empty($input['enable_honeypot']) ? 1 : 0,
            'notify_admin'       => !empty($input['notify_admin']) ? 1 : 0,
            'notify_email'       => sanitize_email($input['notify_email'] ?? ''),
            'send_visitor_copy'       => !empty($input['send_visitor_copy']) ? 1 : 0,
            'visitor_email_subject'   => sanitize_text_field($input['visitor_email_subject'] ?? ''),
            'visitor_email_body_header' => sanitize_textarea_field($input['visitor_email_body_header'] ?? ''),
            'visitor_email_body_footer' => sanitize_textarea_field($input['visitor_email_body_footer'] ?? ''),
            'allowed_file_types' => sanitize_text_field($input['allowed_file_types'] ?? ''),
            'max_file_size_mb'   => max(1, absint($input['max_file_size_mb'] ?? 2)),
            'custom_upload_dir'  => $upload_dir,
            'enable_terms_checkbox'  => !empty($input['enable_terms_checkbox']) ? 1 : 0,
            'terms_checkbox_text'    => wp_kses($input['terms_checkbox_text'] ?? '', [
                'a' => ['href' => [], 'target' => [], 'rel' => [], 'class' => []],
                'strong' => [],
                'em' => [],
            ]),
            'terms_required'         => !empty($input['terms_required']) ? 1 : 0,
            'custom_css'         => wp_strip_all_tags($input['custom_css'] ?? ''),
        ];
    }

    // ── Field renderers ──────────────────────────────────────

    public function field_text(array $args): void {
        $settings = self::get_settings();
        $id = $args['id'];
        echo '<input type="text" id="' . esc_attr($id) . '" name="eff_settings[' . esc_attr($id) . ']" value="' . esc_attr($settings[$id] ?? '') . '" class="regular-text">';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function field_email(array $args): void {
        $settings = self::get_settings();
        $id = $args['id'];
        echo '<input type="email" id="' . esc_attr($id) . '" name="eff_settings[' . esc_attr($id) . ']" value="' . esc_attr($settings[$id] ?? '') . '" class="regular-text">';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function field_number(array $args): void {
        $settings = self::get_settings();
        $id = $args['id'];
        $min = $args['min'] ?? 0;
        $max = $args['max'] ?? '';
        echo '<input type="number" id="' . esc_attr($id) . '" name="eff_settings[' . esc_attr($id) . ']" value="' . esc_attr($settings[$id] ?? '') . '" min="' . esc_attr($min) . '" max="' . esc_attr($max) . '" class="small-text">';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function field_textarea(array $args): void {
        $settings = self::get_settings();
        $id = $args['id'];
        echo '<textarea id="' . esc_attr($id) . '" name="eff_settings[' . esc_attr($id) . ']" rows="3" class="large-text">' . esc_textarea($settings[$id] ?? '') . '</textarea>';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function field_checkbox(array $args): void {
        $settings = self::get_settings();
        $id = $args['id'];
        $checked = !empty($settings[$id]) ? 'checked' : '';
        echo '<label for="' . esc_attr($id) . '">';
        echo '<input type="checkbox" id="' . esc_attr($id) . '" name="eff_settings[' . esc_attr($id) . ']" value="1" ' . $checked . '>';
        echo ' ' . esc_html($args['label'] ?? '') . '</label>';
    }

    public function field_code(array $args): void {
        $settings = self::get_settings();
        $id = $args['id'];
        echo '<textarea id="' . esc_attr($id) . '" name="eff_settings[' . esc_attr($id) . ']" rows="6" class="large-text code" style="font-family:monospace">' . esc_textarea($settings[$id] ?? '') . '</textarea>';
        if (!empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function field_textarea_html(array $args): void {
        $settings = self::get_settings();
        $id = $args['id'];
        echo '<textarea id="' . esc_attr($id) . '" name="eff_settings[' . esc_attr($id) . ']" rows="3" class="large-text">' . esc_textarea($settings[$id] ?? '') . '</textarea>';
        if (!empty($args['description'])) {
            echo '<p class="description">' . wp_kses($args['description'], ['a' => ['href' => []], 'code' => []]) . '</p>';
        }
    }

    // ── Page renderers ───────────────────────────────────────

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap eff-admin-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="eff-admin-header">
                <p><?php esc_html_e('Configura el comportamiento del formulario público de registro..', 'acf-forms-frontend-creator'); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_key);
                do_settings_sections('eff-settings');
                submit_button(__('Guardar cambios', 'acf-forms-frontend-creator'));
                ?>
            </form>
        </div>
        <?php
    }

    public function render_pending_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get all CPTs that have pending frontend submissions
        global $wpdb;
        $pending = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_type, p.post_date, pm2.meta_value AS submitted_at
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_eff_submitted_from'
             LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_eff_submitted_at'
             WHERE p.post_status = 'pending'
             ORDER BY p.post_date DESC
             LIMIT 200"
        );
        ?>
        <div class="wrap eff-admin-wrap">
            <h1><?php esc_html_e('Registros pendientes de aprobación', 'acf-forms-frontend-creator'); ?></h1>

            <?php if (empty($pending)) : ?>
                <div class="eff-admin-empty">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <p><?php esc_html_e('No hay registros pendientes. ¡Todo al día!', 'acf-forms-frontend-creator'); ?></p>
                </div>
            <?php else : ?>
                <p><?php printf(esc_html__('%d registro(s) pendiente(s) de revisión.', 'acf-forms-frontend-creator'), count($pending)); ?></p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Título', 'acf-forms-frontend-creator'); ?></th>
                            <th><?php esc_html_e('Tipo', 'acf-forms-frontend-creator'); ?></th>
                            <th><?php esc_html_e('Fecha de envío', 'acf-forms-frontend-creator'); ?></th>
                            <th><?php esc_html_e('Acciones', 'acf-forms-frontend-creator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $item) :
                            $cpt_obj = get_post_type_object($item->post_type);
                            $cpt_label = $cpt_obj ? $cpt_obj->labels->singular_name : $item->post_type;
                            $edit_link = get_edit_post_link($item->ID);
                        ?>
                            <tr>
                                <td><strong><a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($item->post_title ?: __('(sin título)', 'acf-forms-frontend-creator')); ?></a></strong></td>
                                <td><?php echo esc_html($cpt_label); ?></td>
                                <td><?php echo esc_html($item->submitted_at ?: $item->post_date); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($edit_link); ?>" class="button button-small"><?php esc_html_e('Revisar', 'acf-forms-frontend-creator'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_guide_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap eff-admin-wrap">
            <h1><?php esc_html_e('Guía de uso – ACF Forms Frontend Creator', 'acf-forms-frontend-creator'); ?></h1>

            <div class="eff-guide-section">
                <h2><?php esc_html_e('Shortcode', 'acf-forms-frontend-creator'); ?></h2>
                <p><?php esc_html_e('Usa el shortcode en cualquier página o entrada para mostrar el formulario:', 'acf-forms-frontend-creator'); ?></p>

                <table class="eff-guide-table widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Parámetro', 'acf-forms-frontend-creator'); ?></th>
                            <th><?php esc_html_e('Ejemplo', 'acf-forms-frontend-creator'); ?></th>
                            <th><?php esc_html_e('Descripción', 'acf-forms-frontend-creator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>post_type</code></td>
                            <td><code>[acf_frontend_form post_type="organizacion-esal"]</code></td>
                            <td><?php esc_html_e('Slug del Custom Post Type. El plugin detecta automáticamente el grupo de campos ACF asociado.', 'acf-forms-frontend-creator'); ?></td>
                        </tr>
                        <tr>
                            <td><code>field_group</code></td>
                            <td><code>[acf_frontend_form field_group="group_69b42dfb12d85"]</code></td>
                            <td><?php esc_html_e('Clave del grupo de campos ACF. El plugin detecta automáticamente el CPT desde las reglas de ubicación del grupo.', 'acf-forms-frontend-creator'); ?></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Ambos', 'acf-forms-frontend-creator'); ?></td>
                            <td><code>[acf_frontend_form post_type="organizacion-esal" field_group="group_69b42dfb12d85"]</code></td>
                            <td><?php esc_html_e('Especifica ambos para evitar la resolución automática.', 'acf-forms-frontend-creator'); ?></td>
                        </tr>
                        <tr>
                            <td><code>upload_dir</code></td>
                            <td><code>[acf_frontend_form post_type="organizacion-esal" upload_dir="formularios/esal"]</code></td>
                            <td><?php esc_html_e('Subdirectorio dentro de wp-content/uploads/ para los archivos de este formulario. Sobreescribe la configuración global.', 'acf-forms-frontend-creator'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="eff-guide-section">
                <h2><?php esc_html_e('Flujo de trabajo', 'acf-forms-frontend-creator'); ?></h2>
                <ol class="eff-guide-steps">
                    <li><?php esc_html_e('Un visitante llena y envía el formulario en el frontend.', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('Se crea un registro del CPT en estado "Pendiente".', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('El administrador recibe una notificación por email (si está activado).', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('Desde el panel de administración, el administrador revisa el registro.', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('El administrador puede agregar una observación interna (no visible en el frontend).', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('Al cambiar el estado a "Publicado", el registro se hace visible en el sitio.', 'acf-forms-frontend-creator'); ?></li>
                </ol>
            </div>

            <div class="eff-guide-section">
                <h2><?php esc_html_e('Tipos de campo soportados', 'acf-forms-frontend-creator'); ?></h2>
                <p><?php esc_html_e('El formulario renderiza automáticamente los siguientes tipos de campo ACF:', 'acf-forms-frontend-creator'); ?></p>
                <div class="eff-guide-columns">
                    <ul>
                        <li><code>text</code></li>
                        <li><code>textarea</code></li>
                        <li><code>wysiwyg</code></li>
                        <li><code>number</code></li>
                        <li><code>email</code></li>
                        <li><code>url</code></li>
                        <li><code>password</code></li>
                    </ul>
                    <ul>
                        <li><code>select</code></li>
                        <li><code>radio</code></li>
                        <li><code>checkbox</code></li>
                        <li><code>true_false</code></li>
                        <li><code>date_picker</code></li>
                        <li><code>date_time_picker</code></li>
                        <li><code>time_picker</code></li>
                    </ul>
                    <ul>
                        <li><code>color_picker</code></li>
                        <li><code>file</code></li>
                        <li><code>image</code></li>
                        <li><code>group</code> <?php esc_html_e('(recursivo)', 'acf-forms-frontend-creator'); ?></li>
                        <li><code>repeater</code> <?php esc_html_e('(dinámico)', 'acf-forms-frontend-creator'); ?></li>
                    </ul>
                </div>
            </div>

            <div class="eff-guide-section">
                <h2><?php esc_html_e('Seguridad', 'acf-forms-frontend-creator'); ?></h2>
                <ul>
                    <li><?php esc_html_e('Verificación de nonce en cada envío.', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('Campo honeypot para detección de bots.', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('Límite de velocidad por IP (configurable).', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('Sanitización y validación de todos los campos según su tipo.', 'acf-forms-frontend-creator'); ?></li>
                    <li><?php esc_html_e('Las IPs se almacenan como hash (SHA-256) para privacidad.', 'acf-forms-frontend-creator'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function enqueue_assets(string $hook): void {
        if (!str_starts_with($hook, 'acf-forms_page_') && 'toplevel_page_eff-settings' !== $hook) {
            return;
        }
        wp_enqueue_style('eff-admin', EFF_PLUGIN_URL . 'assets/css/admin.css', [], EFF_VERSION);
    }
}
