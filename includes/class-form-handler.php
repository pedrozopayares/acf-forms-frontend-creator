<?php
defined('ABSPATH') || exit;

/**
 * Handles frontend form submissions, validates data, creates posts as pending.
 */
class EFF_Form_Handler {

    /**
     * Process the form submission if present.
     *
     * @return array|WP_Error|null  null = no submission, array = success, WP_Error = validation errors.
     */
    public function maybe_process(string $post_type, string $field_group) {
        if (empty($_POST['eff_submit'])) {
            return null;
        }

        // Load plugin settings
        $settings     = EFF_Admin_Settings::get_settings();
        $rate_seconds = max(0, (int) ($settings['rate_limit_seconds'] ?? 60));

        // Verify nonce
        if (!isset($_POST['eff_nonce']) || !wp_verify_nonce($_POST['eff_nonce'], 'eff_submit_' . $post_type)) {
            return new WP_Error('nonce_fail', __('Error de seguridad. Por favor recarga la página e intenta de nuevo.', 'acf-forms-frontend-creator'));
        }

        // Honeypot check
        if (!empty($settings['enable_honeypot']) && !empty($_POST['eff_website'])) {
            // Silently reject spam – return "success" to avoid leaking info
            return ['success' => true, 'post_id' => 0];
        }

        // Terms & privacy acceptance validation
        if (!empty($settings['enable_terms_checkbox']) && !empty($settings['terms_required'])) {
            if (empty($_POST['eff_terms_accepted'])) {
                return new WP_Error('terms_not_accepted', __('Debe aceptar los términos de servicio y la política de privacidad.', 'acf-forms-frontend-creator'));
            }
        }

        // reCAPTCHA validation
        if (!empty($settings['enable_recaptcha'])) {
            $token = sanitize_text_field($_POST['g-recaptcha-response'] ?? '');
            $secret_key = !empty($settings['recaptcha_secret_key']) ? $settings['recaptcha_secret_key'] : '';
            $min_score = isset($settings['recaptcha_min_score']) ? (float) $settings['recaptcha_min_score'] : 0.5;

            if (empty($token) || empty($secret_key)) {
                return new WP_Error('recaptcha_missing', __('Error de verificación de seguridad. Por favor recarga la página e intenta de nuevo.', 'acf-forms-frontend-creator'));
            }

            $recaptcha_result = EFF_Recaptcha::verify($token, $secret_key, $min_score);
            if (is_wp_error($recaptcha_result)) {
                return $recaptcha_result;
            }
        }

        // Rate limiting via transient (per IP)
        $ip_hash    = hash('sha256', $this->get_client_ip() . wp_salt('nonce'));
        $rate_key   = 'eff_rate_' . substr($ip_hash, 0, 20);
        if ($rate_seconds > 0 && get_transient($rate_key)) {
            return new WP_Error('rate_limit', __('Has enviado un registro recientemente. Por favor espera un momento antes de intentar de nuevo.', 'acf-forms-frontend-creator'));
        }

        // Validate ACF fields
        $fields = acf_get_fields($field_group);
        if (empty($fields)) {
            return new WP_Error('no_fields', __('No se encontraron campos para validar.', 'acf-forms-frontend-creator'));
        }

        $acf_data = $_POST['acf'] ?? [];
        $errors   = new WP_Error();

        $sanitized = $this->validate_fields($fields, $acf_data, $errors);

        if ($errors->has_errors()) {
            return $errors;
        }

        // Handle file uploads
        $file_values = $this->handle_file_uploads($fields, $acf_data, $errors, $post_type);
        if ($errors->has_errors()) {
            return $errors;
        }

        // Generate consecutive number for this post type
        $counter_key = 'eff_consecutive_' . $post_type;
        $consecutive = (int) get_option($counter_key, 0) + 1;
        update_option($counter_key, $consecutive, false);

        // Build auto-generated title: TYPE 0001
        $cpt_obj    = get_post_type_object($post_type);
        $type_label = $cpt_obj ? $cpt_obj->labels->singular_name : $post_type;
        $full_title = sprintf('%s %04d', strtoupper($type_label), $consecutive);

        // Create the post as pending
        $post_id = wp_insert_post([
            'post_type'   => $post_type,
            'post_title'  => $full_title,
            'post_status' => 'pending',
            'post_author' => get_current_user_id() ?: 1,
        ], true);

        if (is_wp_error($post_id)) {
            // Rollback consecutive on failure
            update_option($counter_key, $consecutive - 1, false);
            return new WP_Error('insert_fail', __('Error al crear el registro. Intenta de nuevo.', 'acf-forms-frontend-creator'));
        }

        // Save ACF field values
        foreach ($sanitized as $field_name => $value) {
            update_field($field_name, $value, $post_id);
        }

        // Save uploaded files
        foreach ($file_values as $field_name => $attachment_id) {
            update_field($field_name, $attachment_id, $post_id);
        }

        // Rename tmp-* attachments to {post_id}-{timestamp}-{seq}{ext} and link to post.
        if (!empty($file_values)) {
            $this->finalize_attachments($file_values, $post_id, $post_type);
        }

        // Store metadata about the submission
        update_post_meta($post_id, '_eff_submitted_from', 'frontend');
        update_post_meta($post_id, '_eff_submitted_ip', $ip_hash);
        update_post_meta($post_id, '_eff_submitted_at', current_time('mysql'));

        // Set rate limit
        if ($rate_seconds > 0) {
            set_transient($rate_key, 1, $rate_seconds);
        }

        // Email notification
        if (!empty($settings['notify_admin'])) {
            $this->send_notification($post_id, $full_title, $post_type, $settings);
        }

        // Send visitor a copy of their submission
        if (!empty($settings['send_visitor_copy'])) {
            $all_fields = acf_get_fields($field_group);
            $this->send_visitor_copy($post_id, $post_type, $sanitized, $file_values, $all_fields, $settings);
        }

        /**
         * Fires after a frontend form submission creates a pending post.
         *
         * @param int    $post_id    The created post ID.
         * @param string $post_type  The CPT slug.
         * @param array  $sanitized  Sanitized ACF values.
         */
        do_action('eff_after_submission', $post_id, $post_type, $sanitized);

        return ['success' => true, 'post_id' => $post_id];
    }

    /**
     * Validate and sanitize ACF fields recursively.
     */
    private function validate_fields(array $fields, array $data, WP_Error &$errors): array {
        $sanitized = [];

        foreach ($fields as $field) {
            $name  = $field['name'];
            $value = $data[$name] ?? null;

            // Skip non-input types
            if (in_array($field['type'], ['message', 'accordion', 'tab', 'clone'], true)) {
                continue;
            }

            // Skip file/image types (handled separately)
            if (in_array($field['type'], ['file', 'image'], true)) {
                continue;
            }

            // Skip fields hidden by conditional logic
            if ($this->is_field_conditionally_hidden($field, $fields, $data)) {
                continue;
            }

            // Required validation
            if (!empty($field['required'])) {
                if (null === $value || '' === $value || (is_array($value) && empty($value))) {
                    $errors->add(
                        'required_' . $name,
                        sprintf(__('El campo "%s" es obligatorio.', 'acf-forms-frontend-creator'), $field['label'])
                    );
                    continue;
                }
            }

            // Type-specific sanitization and validation
            $sanitized[$name] = $this->sanitize_by_type($field, $value, $errors);
        }

        return $sanitized;
    }

    /**
     * Sanitize a value based on its ACF field type.
     */
    private function sanitize_by_type(array $field, mixed $value, WP_Error &$errors): mixed {
        if (null === $value || '' === $value) {
            return $field['default_value'] ?? '';
        }

        switch ($field['type']) {
            case 'text':
            case 'password':
                $clean = sanitize_text_field(wp_unslash($value));
                if (!empty($field['maxlength']) && mb_strlen($clean) > (int)$field['maxlength']) {
                    $errors->add('maxlength_' . $field['name'], sprintf(__('"%s" excede la longitud máxima permitida.', 'acf-forms-frontend-creator'), $field['label']));
                }
                return $clean;

            case 'textarea':
            case 'wysiwyg':
                return sanitize_textarea_field(wp_unslash($value));

            case 'number':
                $num = filter_var($value, FILTER_VALIDATE_FLOAT);
                if (false === $num && !empty($field['required'])) {
                    $errors->add('invalid_' . $field['name'], sprintf(__('"%s" debe ser un número válido.', 'acf-forms-frontend-creator'), $field['label']));
                    return '';
                }
                if (isset($field['min']) && '' !== $field['min'] && $num < (float)$field['min']) {
                    $errors->add('min_' . $field['name'], sprintf(__('"%s" debe ser mayor o igual a %s.', 'acf-forms-frontend-creator'), $field['label'], $field['min']));
                }
                if (isset($field['max']) && '' !== $field['max'] && $num > (float)$field['max']) {
                    $errors->add('max_' . $field['name'], sprintf(__('"%s" debe ser menor o igual a %s.', 'acf-forms-frontend-creator'), $field['label'], $field['max']));
                }
                return $num;

            case 'email':
                $clean = sanitize_email(wp_unslash($value));
                if (!empty($value) && !is_email($clean)) {
                    $errors->add('email_' . $field['name'], sprintf(__('"%s" no es una dirección de email válida.', 'acf-forms-frontend-creator'), $field['label']));
                }
                return $clean;

            case 'url':
                $clean = esc_url_raw(wp_unslash($value));
                if (!empty($value) && empty($clean)) {
                    $errors->add('url_' . $field['name'], sprintf(__('"%s" no es una URL válida.', 'acf-forms-frontend-creator'), $field['label']));
                }
                return $clean;

            case 'select':
            case 'radio':
                $choices = array_keys($field['choices'] ?? []);
                if (is_array($value)) {
                    return array_filter(array_map('sanitize_text_field', $value), fn($v) => in_array($v, $choices, true));
                }
                $clean = sanitize_text_field($value);
                if (!empty($clean) && !in_array($clean, $choices, true)) {
                    $errors->add('choice_' . $field['name'], sprintf(__('"%s" tiene un valor no válido.', 'acf-forms-frontend-creator'), $field['label']));
                    return '';
                }
                return $clean;

            case 'checkbox':
                $choices = array_keys($field['choices'] ?? []);
                if (!is_array($value)) {
                    $value = [$value];
                }
                return array_filter(array_map('sanitize_text_field', $value), fn($v) => in_array($v, $choices, true));

            case 'true_false':
                return $value ? 1 : 0;

            case 'date_picker':
                $clean = sanitize_text_field($value);
                if (!empty($clean) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean)) {
                    $errors->add('date_' . $field['name'], sprintf(__('"%s" no tiene un formato de fecha válido.', 'acf-forms-frontend-creator'), $field['label']));
                    return '';
                }
                return $clean;

            case 'date_time_picker':
                $clean = sanitize_text_field($value);
                if (!empty($clean) && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $clean)) {
                    $errors->add('datetime_' . $field['name'], sprintf(__('"%s" no tiene un formato válido.', 'acf-forms-frontend-creator'), $field['label']));
                    return '';
                }
                return $clean;

            case 'time_picker':
                $clean = sanitize_text_field($value);
                if (!empty($clean) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $clean)) {
                    $errors->add('time_' . $field['name'], sprintf(__('"%s" no tiene un formato de hora válido.', 'acf-forms-frontend-creator'), $field['label']));
                    return '';
                }
                return $clean;

            case 'color_picker':
                $clean = sanitize_hex_color($value);
                return $clean ?: '';

            default:
                return sanitize_text_field(wp_unslash(is_array($value) ? '' : $value));
        }
    }

    /**
     * Send the visitor a copy of their submitted data via email.
     */
    private function send_visitor_copy(int $post_id, string $post_type, array $sanitized, array $file_values, array $fields, array $settings): void {
        // Find the first email field and get the visitor's email address
        $visitor_email = '';
        foreach ($fields as $field) {
            if ('email' === ($field['type'] ?? '') && !empty($sanitized[$field['name']])) {
                $visitor_email = $sanitized[$field['name']];
                break;
            }
        }

        if (empty($visitor_email) || !is_email($visitor_email)) {
            return;
        }

        $site_name = get_bloginfo('name');

        // Subject
        $subject = !empty($settings['visitor_email_subject'])
            ? str_replace('{site_name}', $site_name, $settings['visitor_email_subject'])
            : sprintf('Copia de tu registro en %s', $site_name);

        // Build field label map
        $label_map = [];
        $type_map  = [];
        foreach ($fields as $field) {
            if (!empty($field['name']) && !empty($field['label'])) {
                $label_map[$field['name']] = $field['label'];
                $type_map[$field['name']]  = $field['type'] ?? 'text';
            }
        }

        // Build HTML table rows
        $rows = '';
        foreach ($sanitized as $name => $value) {
            $label = $label_map[$name] ?? $name;
            $type  = $type_map[$name] ?? 'text';

            // Skip non-displayable types
            if (in_array($type, ['message', 'accordion', 'tab', 'clone'], true)) {
                continue;
            }

            // Format the value for display
            if (is_array($value)) {
                $display = esc_html(implode(', ', $value));
            } elseif ('true_false' === $type) {
                $display = $value ? __('Sí', 'acf-forms-frontend-creator') : __('No', 'acf-forms-frontend-creator');
            } else {
                $display = esc_html((string) $value);
            }

            if ('' === $display) {
                $display = '—';
            }

            $rows .= '<tr><td style="padding:8px 12px;border:1px solid #e0e0e0;font-weight:600;background:#f9f9f9;width:35%;">'
                . esc_html($label) . '</td><td style="padding:8px 12px;border:1px solid #e0e0e0;">'
                . $display . '</td></tr>';
        }

        // Add file fields (show filename only, not URL)
        foreach ($file_values as $name => $attachment_id) {
            $label    = $label_map[$name] ?? $name;
            $filename = get_the_title($attachment_id) ?: __('Archivo adjunto', 'acf-forms-frontend-creator');
            $rows .= '<tr><td style="padding:8px 12px;border:1px solid #e0e0e0;font-weight:600;background:#f9f9f9;width:35%;">'
                . esc_html($label) . '</td><td style="padding:8px 12px;border:1px solid #e0e0e0;">'
                . esc_html($filename) . '</td></tr>';
        }

        $header = !empty($settings['visitor_email_body_header'])
            ? esc_html($settings['visitor_email_body_header'])
            : 'Gracias por tu registro. A continuación encontrarás un resumen de la información enviada:';

        $footer = !empty($settings['visitor_email_body_footer'])
            ? esc_html($settings['visitor_email_body_footer'])
            : 'Tu registro será revisado por un administrador antes de ser publicado.';

        $body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">'
            . '<p>' . nl2br($header) . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;">' . $rows . '</table>'
            . '<p>' . nl2br($footer) . '</p>'
            . '<p style="color:#999;font-size:0.85em;">' . esc_html($site_name) . '</p>'
            . '</div>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($visitor_email, $subject, $body, $headers);
    }

    /**
     * Handle file/image uploads for the submission.
     */
    private function handle_file_uploads(array $fields, array $data, WP_Error &$errors, string $post_type = ''): array {
        $results = [];
        $has_files = !empty($_FILES['acf']);

        // Always validate required file fields, even if no files were uploaded
        foreach ($fields as $field) {
            if (!in_array($field['type'], ['file', 'image'], true)) {
                continue;
            }

            // Skip fields hidden by conditional logic
            if ($this->is_field_conditionally_hidden($field, $fields, $data)) {
                continue;
            }

            $name = $field['name'];

            // Check if file was uploaded for this field
            $file_uploaded = $has_files
                && !empty($_FILES['acf']['name'][$name])
                && !empty($_FILES['acf']['tmp_name'][$name])
                && (int) ($_FILES['acf']['error'][$name] ?? 4) === UPLOAD_ERR_OK;

            if (!$file_uploaded) {
                if (!empty($field['required'])) {
                    $errors->add('required_' . $name, sprintf(__('El campo "%s" es obligatorio.', 'acf-forms-frontend-creator'), $field['label']));
                }
                continue;
            }
        }

        // If there are required-file errors, return early before processing uploads
        if ($errors->has_errors() || !$has_files) {
            return $results;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Resolve custom upload sub-directory (inside wp-content/uploads) from shortcode or global settings.
        $custom_dir    = $this->resolve_upload_dir();
        $upload_filter = null;

        if (!empty($custom_dir)) {
            $upload_filter = function (array $uploads) use ($custom_dir): array {
                $uploads['subdir'] = '/' . $custom_dir;
                $uploads['path']   = $uploads['basedir'] . '/' . $custom_dir;
                $uploads['url']    = $uploads['baseurl'] . '/' . $custom_dir;

                if (!file_exists($uploads['path'])) {
                    wp_mkdir_p($uploads['path']);
                }

                return $uploads;
            };
            add_filter('upload_dir', $upload_filter);
        }

        /**
         * Extension point: allow other plugins to add their own `upload_dir` /
         * `wp_handle_upload_prefilter` filters before files are processed.
         * This keeps this plugin agnostic of any specific storage layout
         * (e.g. files outside wp-content/uploads, per-CPT folders, etc.).
         *
         * @param string $post_type  Target CPT slug.
         * @param array  $fields     All ACF fields for the form.
         */
        do_action('eff_pre_file_uploads', $post_type, $fields);

        foreach ($fields as $field) {
            if (!in_array($field['type'], ['file', 'image'], true)) {
                continue;
            }

            $name = $field['name'];

            // Skip if no file uploaded for this field (non-required already passed)
            if (empty($_FILES['acf']['name'][$name]) || empty($_FILES['acf']['tmp_name'][$name])) {
                continue;
            }

            // Rebuild $_FILES structure for wp_handle_upload
            $file = [
                'name'     => $_FILES['acf']['name'][$name],
                'type'     => $_FILES['acf']['type'][$name],
                'tmp_name' => $_FILES['acf']['tmp_name'][$name],
                'error'    => $_FILES['acf']['error'][$name],
                'size'     => $_FILES['acf']['size'][$name],
            ];

            // Validate file extension against plugin global settings
            $settings          = EFF_Admin_Settings::get_settings();
            $allowed_ext_str   = $settings['allowed_file_types'] ?? '';
            $max_size_mb       = (float) ($settings['max_file_size_mb'] ?? 2);

            if (!empty($allowed_ext_str)) {
                $allowed_exts = array_map('trim', array_map('strtolower', explode(',', $allowed_ext_str)));
                $file_ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($file_ext, $allowed_exts, true)) {
                    $errors->add(
                        'filetype_' . $name,
                        sprintf(
                            __('El archivo "%1$s" tiene un tipo no permitido (.%2$s). Tipos permitidos: %3$s', 'acf-forms-frontend-creator'),
                            $field['label'],
                            esc_html($file_ext),
                            esc_html($allowed_ext_str)
                        )
                    );
                    continue;
                }
            }

            // Validate file size against plugin global settings
            if ($max_size_mb > 0 && $file['size'] > $max_size_mb * 1024 * 1024) {
                $errors->add(
                    'filesize_' . $name,
                    sprintf(
                        __('El archivo "%1$s" excede el tamaño máximo permitido (%2$s MB).', 'acf-forms-frontend-creator'),
                        $field['label'],
                        $max_size_mb
                    )
                );
                continue;
            }

            // Validate mime type
            $allowed = ('image' === $field['type'])
                ? ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp']
                : null; // null = WordPress default allowed types

            $upload = wp_handle_upload($file, [
                'test_form' => false,
                'mimes'     => $allowed,
            ]);

            if (!empty($upload['error'])) {
                $errors->add('upload_' . $name, sprintf(__('Error al subir "%s": %s', 'acf-forms-frontend-creator'), $field['label'], $upload['error']));
                continue;
            }

            // Create attachment
            $attachment_id = wp_insert_attachment([
                'post_mime_type' => $upload['type'],
                'post_title'     => sanitize_file_name($file['name']),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'guid'           => $upload['url'],
            ], $upload['file']);

            if (!is_wp_error($attachment_id)) {
                wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));

                /**
                 * Extension point: allow other plugins to stamp custom meta on
                 * the attachment (e.g. markers for non-default storage locations).
                 *
                 * @param int    $attachment_id  Newly created attachment.
                 * @param array  $upload         wp_handle_upload() result (file, url, type).
                 * @param array  $field          ACF field definition.
                 * @param string $post_type      Target CPT slug.
                 */
                do_action('eff_after_attachment_created', $attachment_id, $upload, $field, $post_type);

                $results[$name] = $attachment_id;
            }
        }

        // Remove the custom upload directory filter
        if ($upload_filter) {
            remove_filter('upload_dir', $upload_filter);
        }

        /**
         * Extension point: companion to `eff_pre_file_uploads`. Allows other
         * plugins to remove their own upload_dir / prefilter hooks.
         *
         * @param string $post_type  Target CPT slug.
         * @param array  $fields     All ACF fields for the form.
         * @param array  $results    [field_name => attachment_id] successfully uploaded.
         */
        do_action('eff_post_file_uploads', $post_type, $fields, $results);

        return $results;
    }

    /**
     * Link the uploaded attachments to the newly created post (post_parent).
     *
     * Fires `eff_before_finalize_attachments` beforehand so other plugins can
     * rename/move files that live in custom locations, and
     * `eff_after_finalize_attachments` afterwards.
     *
     * @param array<string,int> $file_values  [field_name => attachment_id].
     */
    private function finalize_attachments(array $file_values, int $post_id, string $post_type): void {
        /**
         * Extension point: allow other plugins to rename/move the uploaded
         * attachments before they are linked to the post.
         *
         * @param array  $file_values  [field_name => attachment_id].
         * @param int    $post_id      Parent post ID.
         * @param string $post_type    Target CPT slug.
         */
        do_action('eff_before_finalize_attachments', $file_values, $post_id, $post_type);

        foreach ($file_values as $attachment_id) {
            wp_update_post([
                'ID'          => (int) $attachment_id,
                'post_parent' => $post_id,
            ]);
        }

        /**
         * Extension point: fires after default parenting of attachments.
         *
         * @param array  $file_values  [field_name => attachment_id].
         * @param int    $post_id      Parent post ID.
         * @param string $post_type    Target CPT slug.
         */
        do_action('eff_after_finalize_attachments', $file_values, $post_id, $post_type);
    }

    /**
     * Resolve the custom upload directory from POST data (shortcode attribute) or global settings.
     * Returns sanitized subdirectory or empty string for WordPress default.
     */
    private function resolve_upload_dir(): string {
        $dir = sanitize_text_field($_POST['eff_upload_dir'] ?? '');

        if (empty($dir)) {
            $settings = EFF_Admin_Settings::get_settings();
            $dir = $settings['custom_upload_dir'] ?? '';
        }

        if (empty($dir)) {
            return '';
        }

        // Security: reject path traversal
        $dir = str_replace('..', '', $dir);
        $dir = trim($dir, '/\\');

        if (empty($dir)) {
            return '';
        }

        return $dir;
    }

    /**
     * Get client IP safely (hashed for privacy).
     */
    private function get_client_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }

    /**
     * Send email notification to admin about a new pending submission.
     */
    private function send_notification(int $post_id, string $title, string $post_type, array $settings): void {
        $to = !empty($settings['notify_email']) ? $settings['notify_email'] : get_option('admin_email');
        if (empty($to)) {
            return;
        }

        $cpt_obj   = get_post_type_object($post_type);
        $cpt_label = $cpt_obj ? $cpt_obj->labels->singular_name : $post_type;
        $edit_link = admin_url('post.php?post=' . $post_id . '&action=edit');
        $site_name = get_bloginfo('name');

        $subject = sprintf('[%s] Nuevo registro pendiente: %s', $site_name, $title);
        $message = sprintf(
            "Se ha recibido un nuevo registro desde el formulario público.\n\n" .
            "Tipo: %s\n" .
            "Título: %s\n" .
            "Estado: Pendiente de aprobación\n" .
            "Fecha: %s\n\n" .
            "Revisar y aprobar:\n%s\n",
            $cpt_label,
            $title,
            current_time('d/m/Y H:i'),
            $edit_link
        );

        wp_mail($to, $subject, $message);
    }

    /**
     * Check if a field should be hidden based on its ACF conditional_logic and submitted data.
     *
     * @param array $field      The field being evaluated.
     * @param array $all_fields All fields in the group (to resolve keys to names).
     * @param array $data       Submitted ACF data keyed by field name.
     * @return bool True if the field should be hidden (conditions NOT met).
     */
    private function is_field_conditionally_hidden(array $field, array $all_fields, array $data): bool {
        if (empty($field['conditional_logic']) || !is_array($field['conditional_logic'])) {
            return false;
        }

        // Build key→name map
        $key_map = [];
        foreach ($all_fields as $f) {
            if (!empty($f['key']) && !empty($f['name'])) {
                $key_map[$f['key']] = $f['name'];
            }
        }

        // Evaluate OR groups (field is visible if ANY group passes)
        foreach ($field['conditional_logic'] as $or_group) {
            if (!is_array($or_group)) {
                continue;
            }

            $group_passes = true;
            foreach ($or_group as $rule) {
                $target_key  = $rule['field'] ?? '';
                $target_name = $key_map[$target_key] ?? '';
                if (empty($target_name)) {
                    $group_passes = false;
                    break;
                }

                $actual   = $data[$target_name] ?? '';
                $expected = $rule['value'] ?? '';
                $operator = $rule['operator'] ?? '==';

                if (!$this->evaluate_condition($actual, $operator, $expected)) {
                    $group_passes = false;
                    break;
                }
            }

            if ($group_passes) {
                return false; // Visible — at least one OR group passed
            }
        }

        return true; // Hidden — no OR group passed
    }

    /**
     * Evaluate a single conditional logic rule.
     */
    private function evaluate_condition(mixed $actual, string $operator, string $expected): bool {
        // Handle array values (checkboxes, multi-selects)
        if (is_array($actual)) {
            return match ($operator) {
                '=='        => in_array($expected, $actual, true),
                '!='        => !in_array($expected, $actual, true),
                '==empty'   => empty($actual),
                '!=empty'   => !empty($actual),
                default     => in_array($expected, $actual, true),
            };
        }

        $actual = (string) $actual;
        return match ($operator) {
            '=='         => $actual === $expected,
            '!='         => $actual !== $expected,
            '==empty'    => $actual === '',
            '!=empty'    => $actual !== '',
            '==contains' => str_contains($actual, $expected),
            '<'          => (float) $actual < (float) $expected,
            '>'          => (float) $actual > (float) $expected,
            '<='         => (float) $actual <= (float) $expected,
            '>='         => (float) $actual >= (float) $expected,
            default      => $actual === $expected,
        };
    }
}
