<?php
defined('ABSPATH') || exit;

/**
 * Renders ACF field groups as an HTML form on the frontend.
 */
class EFF_Form_Renderer {

    /**
     * Render the full form or success message.
     *
     * @param string              $post_type   CPT slug.
     * @param string              $field_group ACF field group key.
     * @param array|WP_Error|null $result      Submission result.
     */
    public function render(string $post_type, string $field_group, $result): string {
        $settings = EFF_Admin_Settings::get_settings();
        ob_start();

        // Custom CSS
        if (!empty($settings['custom_css'])) {
            echo '<style>' . $settings['custom_css'] . '</style>';
        }

        // Success message
        if (is_array($result) && !empty($result['success'])) {
            $msg = !empty($settings['success_message']) ? $settings['success_message'] : __('¡Registro enviado correctamente! Será revisado por un administrador antes de ser publicado.', 'acf-forms-frontend-creator');
            echo '<div class="eff-notice eff-notice--success">';
            echo '<p>' . esc_html($msg) . '</p>';
            echo '</div>';
        }

        // Error messages
        if (is_wp_error($result)) {
            echo '<div class="eff-notice eff-notice--error">';
            foreach ($result->get_error_messages() as $msg) {
                echo '<p>' . esc_html($msg) . '</p>';
            }
            echo '</div>';
        }

        // If success, don't show the form again (clean submission)
        if (is_array($result) && !empty($result['success'])) {
            echo '<p><a href="" class="eff-btn eff-btn--secondary">' . esc_html__('Enviar otro registro', 'acf-forms-frontend-creator') . '</a></p>';
            return ob_get_clean();
        }

        $fields = acf_get_fields($field_group);
        if (empty($fields)) {
            echo '<p class="eff-error">' . esc_html__('No se encontraron campos para este formulario.', 'acf-forms-frontend-creator') . '</p>';
            return ob_get_clean();
        }

        $cpt_obj    = get_post_type_object($post_type);
        $cpt_label  = $cpt_obj ? $cpt_obj->labels->singular_name : $post_type;

        echo '<form method="post" class="eff-form" enctype="multipart/form-data" novalidate>';
        wp_nonce_field('eff_submit_' . $post_type, 'eff_nonce');
        echo '<input type="hidden" name="eff_post_type" value="' . esc_attr($post_type) . '">';
        echo '<input type="hidden" name="eff_field_group" value="' . esc_attr($field_group) . '">';

        // Honeypot anti-spam
        if (!empty($settings['enable_honeypot'])) {
            echo '<div class="eff-hp" aria-hidden="true" style="position:absolute;left:-9999px;"><label>No llenar<input type="text" name="eff_website" value="" tabindex="-1" autocomplete="off"></label></div>';
        }

        // Title field
        echo '<div class="eff-field eff-field--required">';
        echo '<label for="eff_title" class="eff-label">' . sprintf(esc_html__('Nombre de %s', 'acf-forms-frontend-creator'), esc_html($cpt_label)) . ' <span class="eff-required">*</span></label>';
        echo '<input type="text" id="eff_title" name="eff_title" class="eff-input" required value="' . esc_attr($this->old_value('eff_title')) . '">';
        echo '</div>';

        // ACF fields
        foreach ($fields as $field) {
            $this->render_field($field);
        }

        $btn_text = !empty($settings['submit_button_text']) ? $settings['submit_button_text'] : __('Enviar registro', 'acf-forms-frontend-creator');
        echo '<input type="hidden" name="eff_submit" value="1">';
        echo '<div class="eff-field eff-field--submit">';
        echo '<button type="submit" class="eff-btn eff-btn--primary">' . esc_html($btn_text) . '</button>';
        echo '</div>';
        echo '</form>';

        return ob_get_clean();
    }

    /**
     * Render a single ACF field as HTML.
     */
    private function render_field(array $field, int $depth = 0): void {
        // Skip fields that shouldn't be on frontend
        $skip_types = ['message', 'accordion', 'tab', 'clone'];
        if (in_array($field['type'], $skip_types, true)) {
            return;
        }

        // Group field: render sub-fields recursively
        if ('group' === $field['type'] && !empty($field['sub_fields'])) {
            echo '<fieldset class="eff-fieldset">';
            if (!empty($field['label'])) {
                echo '<legend class="eff-legend">' . esc_html($field['label']) . '</legend>';
            }
            foreach ($field['sub_fields'] as $sub) {
                $sub['name'] = $field['name'] . '_' . $sub['name'];
                $sub['key']  = $field['key'] . '_' . $sub['key'];
                $this->render_field($sub, $depth + 1);
            }
            echo '</fieldset>';
            return;
        }

        // Repeater field: render as a repeatable set
        if ('repeater' === $field['type'] && !empty($field['sub_fields'])) {
            echo '<fieldset class="eff-fieldset eff-repeater" data-field="' . esc_attr($field['name']) . '" data-min="' . esc_attr($field['min'] ?? 0) . '" data-max="' . esc_attr($field['max'] ?? 0) . '">';
            if (!empty($field['label'])) {
                echo '<legend class="eff-legend">' . esc_html($field['label']);
                if (!empty($field['required'])) {
                    echo ' <span class="eff-required">*</span>';
                }
                echo '</legend>';
            }
            echo '<div class="eff-repeater-rows">';
            // Render one initial row
            echo '<div class="eff-repeater-row" data-index="0">';
            foreach ($field['sub_fields'] as $sub) {
                $sub['name'] = $field['name'] . '_0_' . $sub['name'];
                $sub['key']  = $field['key'] . '_0_' . $sub['key'];
                $this->render_field($sub, $depth + 1);
            }
            echo '<button type="button" class="eff-btn eff-btn--small eff-repeater-remove">' . esc_html__('Eliminar', 'acf-forms-frontend-creator') . '</button>';
            echo '</div>';
            echo '</div>';
            echo '<button type="button" class="eff-btn eff-btn--secondary eff-repeater-add">' . esc_html__('Agregar', 'acf-forms-frontend-creator') . '</button>';
            echo '</fieldset>';
            return;
        }

        $required      = !empty($field['required']);
        $required_attr = $required ? ' required' : '';
        $required_star = $required ? ' <span class="eff-required">*</span>' : '';
        $field_name    = 'acf[' . esc_attr($field['name']) . ']';
        $field_id      = 'eff_' . esc_attr($field['name']);
        $old           = $this->old_value($field_name);
        $classes        = 'eff-field' . ($required ? ' eff-field--required' : '');

        echo '<div class="' . esc_attr($classes) . '">';

        switch ($field['type']) {
            case 'text':
            case 'url':
            case 'email':
            case 'number':
            case 'password':
                $type     = $field['type'];
                $attrs    = '';
                if (!empty($field['placeholder'])) {
                    $attrs .= ' placeholder="' . esc_attr($field['placeholder']) . '"';
                }
                if (!empty($field['maxlength'])) {
                    $attrs .= ' maxlength="' . esc_attr($field['maxlength']) . '"';
                }
                if ('number' === $type) {
                    if (isset($field['min'])) {
                        $attrs .= ' min="' . esc_attr($field['min']) . '"';
                    }
                    if (isset($field['max'])) {
                        $attrs .= ' max="' . esc_attr($field['max']) . '"';
                    }
                    if (isset($field['step'])) {
                        $attrs .= ' step="' . esc_attr($field['step']) . '"';
                    }
                }
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<input type="' . esc_attr($type) . '" id="' . $field_id . '" name="' . $field_name . '" class="eff-input" value="' . esc_attr($old ?: ($field['default_value'] ?? '')) . '"' . $attrs . $required_attr . '>';
                break;

            case 'textarea':
                $rows = $field['rows'] ?? 4;
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<textarea id="' . $field_id . '" name="' . $field_name . '" class="eff-textarea" rows="' . esc_attr($rows) . '"' . (!empty($field['placeholder']) ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '') . (!empty($field['maxlength']) ? ' maxlength="' . esc_attr($field['maxlength']) . '"' : '') . $required_attr . '>' . esc_textarea($old ?: ($field['default_value'] ?? '')) . '</textarea>';
                break;

            case 'wysiwyg':
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<textarea id="' . $field_id . '" name="' . $field_name . '" class="eff-textarea eff-textarea--large" rows="8"' . $required_attr . '>' . esc_textarea($old ?: ($field['default_value'] ?? '')) . '</textarea>';
                break;

            case 'select':
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                $multiple = !empty($field['multiple']) ? ' multiple' : '';
                $name_sel = !empty($field['multiple']) ? $field_name . '[]' : $field_name;
                echo '<select id="' . $field_id . '" name="' . $name_sel . '" class="eff-select"' . $required_attr . $multiple . '>';
                if (empty($field['multiple'])) {
                    echo '<option value="">' . esc_html($field['placeholder'] ?? __('Seleccionar...', 'acf-forms-frontend-creator')) . '</option>';
                }
                foreach (($field['choices'] ?? []) as $val => $label) {
                    $selected = ($old === (string)$val) ? ' selected' : '';
                    echo '<option value="' . esc_attr($val) . '"' . $selected . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
                break;

            case 'radio':
                echo '<fieldset class="eff-fieldset--inline">';
                echo '<legend class="eff-label">' . esc_html($field['label']) . $required_star . '</legend>';
                foreach (($field['choices'] ?? []) as $val => $label) {
                    $checked = ($old === (string)$val) || (!$old && ($field['default_value'] ?? '') === (string)$val) ? ' checked' : '';
                    $rid = $field_id . '_' . sanitize_key($val);
                    echo '<label class="eff-radio-label" for="' . $rid . '">';
                    echo '<input type="radio" id="' . $rid . '" name="' . $field_name . '" value="' . esc_attr($val) . '"' . $checked . $required_attr . '> ';
                    echo esc_html($label) . '</label>';
                }
                echo '</fieldset>';
                break;

            case 'checkbox':
                echo '<fieldset class="eff-fieldset--inline">';
                echo '<legend class="eff-label">' . esc_html($field['label']) . $required_star . '</legend>';
                foreach (($field['choices'] ?? []) as $val => $label) {
                    $checked = is_array($old) && in_array((string)$val, $old, true) ? ' checked' : '';
                    $cid = $field_id . '_' . sanitize_key($val);
                    echo '<label class="eff-checkbox-label" for="' . $cid . '">';
                    echo '<input type="checkbox" id="' . $cid . '" name="' . $field_name . '[]" value="' . esc_attr($val) . '"' . $checked . '> ';
                    echo esc_html($label) . '</label>';
                }
                echo '</fieldset>';
                break;

            case 'true_false':
                echo '<label class="eff-checkbox-label" for="' . $field_id . '">';
                $checked = ($old === '1' || (!$old && !empty($field['default_value']))) ? ' checked' : '';
                echo '<input type="checkbox" id="' . $field_id . '" name="' . $field_name . '" value="1"' . $checked . '> ';
                echo esc_html($field['label']);
                if (!empty($field['message'])) {
                    echo ' — <em>' . esc_html($field['message']) . '</em>';
                }
                echo '</label>';
                break;

            case 'date_picker':
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<input type="date" id="' . $field_id . '" name="' . $field_name . '" class="eff-input" value="' . esc_attr($old ?: ($field['default_value'] ?? '')) . '"' . $required_attr . '>';
                break;

            case 'date_time_picker':
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<input type="datetime-local" id="' . $field_id . '" name="' . $field_name . '" class="eff-input" value="' . esc_attr($old ?: ($field['default_value'] ?? '')) . '"' . $required_attr . '>';
                break;

            case 'time_picker':
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<input type="time" id="' . $field_id . '" name="' . $field_name . '" class="eff-input" value="' . esc_attr($old ?: ($field['default_value'] ?? '')) . '"' . $required_attr . '>';
                break;

            case 'color_picker':
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<input type="color" id="' . $field_id . '" name="' . $field_name . '" class="eff-input eff-input--color" value="' . esc_attr($old ?: ($field['default_value'] ?? '#000000')) . '"' . $required_attr . '>';
                break;

            case 'file':
            case 'image':
                if ('image' === $field['type']) {
                    $accept = ' accept="image/*"';
                } else {
                    $settings = EFF_Admin_Settings::get_settings();
                    $allowed  = $settings['allowed_file_types'] ?? '';
                    if (!empty($allowed)) {
                        $exts   = array_map('trim', explode(',', $allowed));
                        $accept = ' accept=".' . esc_attr(implode(',.', $exts)) . '"';
                    } else {
                        $accept = '';
                    }
                }
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<input type="file" id="' . $field_id . '" name="' . $field_name . '" class="eff-input"' . $accept . $required_attr . '>';
                break;

            default:
                // Fallback: render as text input
                echo '<label for="' . $field_id . '" class="eff-label">' . esc_html($field['label']) . $required_star . '</label>';
                echo '<input type="text" id="' . $field_id . '" name="' . $field_name . '" class="eff-input" value="' . esc_attr($old ?: ($field['default_value'] ?? '')) . '"' . $required_attr . '>';
                break;
        }

        if (!empty($field['instructions'])) {
            echo '<p class="eff-instructions">' . esc_html($field['instructions']) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Get previously submitted value for repopulation after validation errors.
     */
    private function old_value(string $name): mixed {
        // Handle array-style names: acf[field_name]
        if (str_starts_with($name, 'acf[')) {
            $key = str_replace(['acf[', ']'], '', $name);
            $acf = $_POST['acf'] ?? [];
            return $acf[$key] ?? '';
        }
        return $_POST[$name] ?? '';
    }
}
