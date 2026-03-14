<?php
defined('ABSPATH') || exit;

/**
 * Adds admin meta box for approval observations on posts created from the frontend form.
 */
class EFF_Admin_Approval {

    public function __construct() {
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post', [$this, 'save_meta_box'], 10, 2);
        add_filter('manage_posts_columns', [$this, 'add_column'], 10, 2);
        add_action('manage_posts_custom_column', [$this, 'render_column'], 10, 2);
    }

    /**
     * Register the observation meta box on all post types that have frontend submissions.
     */
    public function register_meta_box(): void {
        $screen = get_current_screen();
        if (!$screen || 'post' !== $screen->base) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        // Only show on posts created from the frontend
        if (!get_post_meta($post_id, '_eff_submitted_from', true)) {
            return;
        }

        add_meta_box(
            'eff_admin_observation',
            __('Observación del Administrador', 'acf-forms-frontend-creator'),
            [$this, 'render_meta_box'],
            $screen->post_type,
            'side',
            'high'
        );
    }

    /**
     * Render the observation meta box.
     */
    public function render_meta_box(WP_Post $post): void {
        wp_nonce_field('eff_observation_' . $post->ID, 'eff_observation_nonce');

        $observation = get_post_meta($post->ID, '_eff_admin_observation', true);
        $submitted   = get_post_meta($post->ID, '_eff_submitted_at', true);

        echo '<p class="description">';
        echo esc_html__('Registro enviado desde el formulario público.', 'acf-forms-frontend-creator');
        if ($submitted) {
            echo '<br><strong>' . esc_html__('Fecha de envío:', 'acf-forms-frontend-creator') . '</strong> ' . esc_html($submitted);
        }
        echo '</p>';

        echo '<p style="margin-top:12px"><label for="eff_admin_observation"><strong>' . esc_html__('Observación (solo visible en el admin):', 'acf-forms-frontend-creator') . '</strong></label></p>';
        echo '<textarea id="eff_admin_observation" name="eff_admin_observation" rows="4" style="width:100%">' . esc_textarea($observation) . '</textarea>';
        echo '<p class="description">' . esc_html__('Esta observación NO es visible ni editable desde el frontend.', 'acf-forms-frontend-creator') . '</p>';

        if ('pending' === $post->post_status) {
            echo '<hr style="margin:12px 0">';
            echo '<p><strong>' . esc_html__('Estado:', 'acf-forms-frontend-creator') . '</strong> <span style="color:#b32d2e">⏳ ' . esc_html__('Pendiente de aprobación', 'acf-forms-frontend-creator') . '</span></p>';
            echo '<p class="description">' . esc_html__('Para aprobar este registro, cambia el estado a "Publicado" y haz clic en "Publicar".', 'acf-forms-frontend-creator') . '</p>';
        } elseif ('publish' === $post->post_status) {
            echo '<hr style="margin:12px 0">';
            echo '<p><strong>' . esc_html__('Estado:', 'acf-forms-frontend-creator') . '</strong> <span style="color:#00a32a">✅ ' . esc_html__('Aprobado y publicado', 'acf-forms-frontend-creator') . '</span></p>';
        }
    }

    /**
     * Save the observation when the post is saved.
     */
    public function save_meta_box(int $post_id, WP_Post $post): void {
        // Verify nonce
        if (!isset($_POST['eff_observation_nonce']) || !wp_verify_nonce($_POST['eff_observation_nonce'], 'eff_observation_' . $post_id)) {
            return;
        }

        // Check this is a frontend-submitted post
        if (!get_post_meta($post_id, '_eff_submitted_from', true)) {
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Skip autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $observation = sanitize_textarea_field(wp_unslash($_POST['eff_admin_observation'] ?? ''));
        update_post_meta($post_id, '_eff_admin_observation', $observation);

        // Track who approved and when
        if ('publish' === $post->post_status && !get_post_meta($post_id, '_eff_approved_at', true)) {
            update_post_meta($post_id, '_eff_approved_at', current_time('mysql'));
            update_post_meta($post_id, '_eff_approved_by', get_current_user_id());
        }
    }

    /**
     * Add "Origen" column to CPT list tables for frontend submissions.
     */
    public function add_column(array $columns, string $post_type): array {
        // Only add for post types that have frontend submissions
        global $wpdb;
        $has_submissions = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_eff_submitted_from' AND p.post_type = %s
             LIMIT 1",
            $post_type
        ));

        if ($has_submissions) {
            $columns['eff_origin']      = __('Origen', 'acf-forms-frontend-creator');
            $columns['eff_observation'] = __('Observaciones', 'acf-forms-frontend-creator');
        }

        return $columns;
    }

    /**
     * Render the "Origen" column.
     */
    public function render_column(string $column, int $post_id): void {
        if ('eff_origin' === $column) {
            $from = get_post_meta($post_id, '_eff_submitted_from', true);
            if ('frontend' === $from) {
                $approved = get_post_meta($post_id, '_eff_approved_at', true);
                echo '<span title="' . esc_attr__('Enviado desde formulario público', 'acf-forms-frontend-creator') . '">📋 Frontend</span>';
                if ($approved) {
                    $user_id = get_post_meta($post_id, '_eff_approved_by', true);
                    $user    = get_userdata($user_id);
                    $name    = $user ? $user->display_name : __('Desconocido', 'acf-forms-frontend-creator');
                    echo '<br><small>✅ ' . esc_html($name) . '</small>';
                }
            }
        }

        if ('eff_observation' === $column) {
            $observation = get_post_meta($post_id, '_eff_admin_observation', true);
            if ($observation) {
                echo '<span title="' . esc_attr($observation) . '">' . esc_html(wp_trim_words($observation, 12, '…')) . '</span>';
            } else {
                echo '<span style="color:#999">—</span>';
            }
        }
    }
}
