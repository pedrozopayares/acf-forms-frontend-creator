<?php
/**
 * Plugin Name: ACF Forms Frontend Creator
 * Description: Muestra un formulario en el frontend para crear registros de Custom Post Types con campos ACF. Los registros quedan en estado pendiente hasta aprobación del administrador.
 * Version: 1.2.0
 * Author: Impactos
 * Text Domain: acf-forms-frontend-creator
 * Requires Plugins: advanced-custom-fields
 */

defined('ABSPATH') || exit;

define('EFF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EFF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EFF_VERSION', '1.2.0');

require_once EFF_PLUGIN_DIR . 'includes/class-form-renderer.php';
require_once EFF_PLUGIN_DIR . 'includes/class-form-handler.php';
require_once EFF_PLUGIN_DIR . 'includes/class-admin-approval.php';
require_once EFF_PLUGIN_DIR . 'includes/class-admin-settings.php';

/**
 * Main plugin class.
 */
final class AFFC_Plugin {

    private static ?self $instance = null;
    private EFF_Form_Renderer $renderer;
    private EFF_Form_Handler $handler;
    private EFF_Admin_Approval $approval;
    private EFF_Admin_Settings $settings;

    public static function instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->renderer = new EFF_Form_Renderer();
        $this->handler  = new EFF_Form_Handler();
        $this->approval = new EFF_Admin_Approval();
        $this->settings = new EFF_Admin_Settings();

        add_shortcode('acf_frontend_form', [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_eff_submit_form', [$this, 'ajax_submit']);
        add_action('wp_ajax_nopriv_eff_submit_form', [$this, 'ajax_submit']);
    }

    /**
     * Shortcode: [acf_frontend_form post_type="organizacion-esal"]
     * or:        [acf_frontend_form field_group="group_69b42dfb12d85"]
     *
     * Parameters:
     *  - post_type:   Slug of the CPT to create.
     *  - field_group: ACF field group key. If provided without post_type,
     *                 the plugin resolves the CPT from the group's location rules.
     */
    public function shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'post_type'   => '',
            'field_group' => '',
        ], $atts, 'acf_frontend_form');

        // Require ACF
        if (!function_exists('acf_get_fields')) {
            return '<p class="eff-error">' . esc_html__('Advanced Custom Fields es requerido para este formulario.', 'acf-forms-frontend-creator') . '</p>';
        }

        // Resolve post type and field group
        $resolved = $this->resolve_params($atts['post_type'], $atts['field_group']);
        if (is_wp_error($resolved)) {
            return '<p class="eff-error">' . esc_html($resolved->get_error_message()) . '</p>';
        }

        $post_type   = $resolved['post_type'];
        $field_group = $resolved['field_group'];

        // Process form submission
        $result = $this->handler->maybe_process($post_type, $field_group);

        return $this->renderer->render($post_type, $field_group, $result);
    }

    /**
     * Resolve post_type ↔ field_group relationship.
     */
    private function resolve_params(string $post_type, string $field_group): array|WP_Error {
        if (empty($post_type) && empty($field_group)) {
            return new WP_Error('missing_params', 'Debe especificar post_type o field_group en el shortcode.');
        }

        // If only field_group provided, try to find the associated post_type
        if (empty($post_type) && !empty($field_group)) {
            $post_type = $this->get_post_type_from_group($field_group);
            if (empty($post_type)) {
                return new WP_Error('no_cpt', 'No se pudo determinar el Custom Post Type del grupo de campos.');
            }
        }

        // Validate that CPT exists
        if (!post_type_exists($post_type)) {
            return new WP_Error('invalid_cpt', sprintf('El tipo de contenido "%s" no existe.', $post_type));
        }

        // If only post_type provided, find associated field groups
        if (!empty($post_type) && empty($field_group)) {
            $field_group = $this->get_field_group_for_post_type($post_type);
            if (empty($field_group)) {
                return new WP_Error('no_group', sprintf('No se encontraron grupos de campos ACF para "%s".', $post_type));
            }
        }

        return [
            'post_type'   => sanitize_key($post_type),
            'field_group' => sanitize_text_field($field_group),
        ];
    }

    /**
     * Get the post_type from a field group's location rules.
     */
    private function get_post_type_from_group(string $group_key): string {
        $groups = acf_get_field_groups();
        foreach ($groups as $group) {
            if ($group['key'] !== $group_key) {
                continue;
            }
            if (!empty($group['location'])) {
                foreach ($group['location'] as $or_group) {
                    foreach ($or_group as $rule) {
                        if ('post_type' === ($rule['param'] ?? '') && '==' === ($rule['operator'] ?? '')) {
                            return $rule['value'] ?? '';
                        }
                    }
                }
            }
        }
        return '';
    }

    /**
     * Get the first field group assigned to a CPT.
     */
    private function get_field_group_for_post_type(string $post_type): string {
        $groups = acf_get_field_groups(['post_type' => $post_type]);
        return !empty($groups[0]['key']) ? $groups[0]['key'] : '';
    }

    public function enqueue_assets(): void {
        if (!is_singular()) {
            return;
        }

        global $post;
        if ($post && has_shortcode($post->post_content, 'acf_frontend_form')) {
            wp_enqueue_style(
                'eff-frontend',
                EFF_PLUGIN_URL . 'assets/css/frontend-form.css',
                [],
                EFF_VERSION
            );
            wp_enqueue_script(
                'eff-frontend',
                EFF_PLUGIN_URL . 'assets/js/frontend-form.js',
                [],
                EFF_VERSION,
                true
            );
            wp_localize_script('eff-frontend', 'effAjax', [
                'url' => admin_url('admin-ajax.php'),
            ]);
        }
    }

    /**
     * Handle AJAX form submission.
     */
    public function ajax_submit(): void {
        $post_type   = sanitize_key($_POST['eff_post_type'] ?? '');
        $field_group = sanitize_text_field($_POST['eff_field_group'] ?? '');

        if (empty($post_type) || empty($field_group)) {
            wp_send_json_error(['messages' => [__('Parámetros del formulario inválidos.', 'acf-forms-frontend-creator')]]);
        }

        $result = $this->handler->maybe_process($post_type, $field_group);

        if (null === $result) {
            wp_send_json_error(['messages' => [__('No se recibieron datos del formulario.', 'acf-forms-frontend-creator')]]);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(['messages' => $result->get_error_messages()]);
        }

        if (is_array($result) && !empty($result['success'])) {
            $settings = EFF_Admin_Settings::get_settings();
            $msg = !empty($settings['success_message'])
                ? $settings['success_message']
                : __('¡Registro enviado correctamente! Será revisado por un administrador antes de ser publicado.', 'acf-forms-frontend-creator');
            wp_send_json_success(['message' => $msg]);
        }

        wp_send_json_error(['messages' => [__('Error desconocido al procesar el formulario.', 'acf-forms-frontend-creator')]]);
    }
}

add_action('plugins_loaded', function () {
    AFFC_Plugin::instance();
});
