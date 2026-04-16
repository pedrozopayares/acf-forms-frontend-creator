<?php
defined('ABSPATH') || exit;

/**
 * Renders ACF field groups as an HTML form on the frontend.
 */
class EFF_Form_Renderer {

    private array $field_key_map = [];

    /**
     * Render the full form or success message.
     *
     * @param string              $post_type   CPT slug.
     * @param string              $field_group ACF field group key.
     * @param array|WP_Error|null $result      Submission result.
     * @param string              $layout      Layout mode: auto, tabs, steps, accordion, flat.
     * @param string              $upload_dir  Custom upload subdirectory (from shortcode or settings).
     */
    public function render(string $post_type, string $field_group, $result, string $layout = 'auto', string $upload_dir = ''): string {
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

        // Build key→name map for conditional logic resolution
        $this->field_key_map = $this->build_field_key_map($fields);

        $cpt_obj    = get_post_type_object($post_type);
        $cpt_label  = $cpt_obj ? $cpt_obj->labels->singular_name : $post_type;

        echo '<form method="post" class="eff-form" enctype="multipart/form-data" novalidate>';
        wp_nonce_field('eff_submit_' . $post_type, 'eff_nonce');
        echo '<input type="hidden" name="eff_post_type" value="' . esc_attr($post_type) . '">';
        echo '<input type="hidden" name="eff_field_group" value="' . esc_attr($field_group) . '">';

        // Custom upload directory (shortcode > global setting)
        if (!empty($upload_dir)) {
            echo '<input type="hidden" name="eff_upload_dir" value="' . esc_attr($upload_dir) . '">';
        }

        // Honeypot anti-spam
        if (!empty($settings['enable_honeypot'])) {
            echo '<div class="eff-hp" aria-hidden="true" style="position:absolute;left:-9999px;"><label>No llenar<input type="text" name="eff_website" value="" tabindex="-1" autocomplete="off"></label></div>';
        }

        // ACF fields — detect layout structure (tabs/accordions)
        $structure = $this->build_sections($fields);

        // Resolve effective layout
        $effective_layout = $this->resolve_layout($layout, $structure);

        if ('steps' === $effective_layout && !empty($structure['sections'])) {
            $this->render_steps($structure['sections']);
        } elseif ('tabs' === $effective_layout && !empty($structure['sections'])) {
            $this->render_tabs($structure['sections']);
        } elseif ('accordions' === $effective_layout && !empty($structure['sections'])) {
            $this->render_accordions($structure['sections']);
        } else {
            foreach ($fields as $field) {
                $this->render_field($field);
            }
        }

        $btn_text = !empty($settings['submit_button_text']) ? $settings['submit_button_text'] : __('Enviar registro', 'acf-forms-frontend-creator');

        // Terms & privacy checkbox (plugin-injected, independent of ACF fields)
        if (!empty($settings['enable_terms_checkbox'])) {
            $terms_text = !empty($settings['terms_checkbox_text'])
                ? $settings['terms_checkbox_text']
                : __('Acepto los <a href="#">términos de servicio</a> y la <a href="#">política de privacidad</a>', 'acf-forms-frontend-creator');
            $terms_required = !empty($settings['terms_required']);
            $terms_required_attr = $terms_required ? ' required' : '';
            $terms_required_star = $terms_required ? ' <span class="eff-required">*</span>' : '';

            echo '<div class="eff-field eff-field--terms">';
            echo '<label class="eff-checkbox-label eff-terms-label" for="eff_terms_accepted">';
            echo '<input type="checkbox" id="eff_terms_accepted" name="eff_terms_accepted" value="1"' . $terms_required_attr . '> ';
            echo wp_kses($terms_text, ['a' => ['href' => [], 'target' => [], 'rel' => [], 'class' => []], 'strong' => [], 'em' => []]);
            echo $terms_required_star;
            echo '</label>';
            echo '</div>';
        }

        echo '<input type="hidden" name="eff_submit" value="1">';
        echo '<div class="eff-field eff-field--submit">';
        echo '<button type="submit" class="eff-btn eff-btn--primary">' . esc_html($btn_text) . '</button>';
        echo '</div>';
        echo '</form>';

        return ob_get_clean();
    }

    /**
     * Analyze field array and build a section structure based on ACF tab/accordion layout fields.
     */
    private function build_sections(array $fields): array {
        $has_tabs = false;
        $has_accordions = false;

        foreach ($fields as $field) {
            if ('tab' === $field['type']) { $has_tabs = true; }
            if ('accordion' === $field['type']) { $has_accordions = true; }
        }

        if ($has_tabs) {
            return [
                'type'     => 'tabs',
                'sections' => $this->split_by_type($fields, 'tab'),
            ];
        }

        if ($has_accordions) {
            return [
                'type'     => 'accordions',
                'sections' => $this->split_by_type($fields, 'accordion'),
            ];
        }

        return ['type' => 'flat', 'sections' => []];
    }

    /**
     * Resolve the effective layout from the shortcode attribute + detected structure.
     */
    private function resolve_layout(string $layout, array $structure): string {
        // Explicit layout override
        if (in_array($layout, ['tabs', 'steps', 'accordion', 'flat'], true)) {
            // steps/tabs/accordion need sections
            if (in_array($layout, ['tabs', 'steps', 'accordion'], true) && empty($structure['sections'])) {
                return 'flat';
            }
            return $layout;
        }

        // Auto: use detected structure type
        if ($structure['type'] === 'tabs') {
            return 'tabs';
        }
        if ($structure['type'] === 'accordions') {
            return 'accordions';
        }
        return 'flat';
    }

    /**
     * Split a flat field array by a layout field type (tab or accordion) into labeled sections.
     * Fields before the first layout field go into a section with empty label.
     */
    private function split_by_type(array $fields, string $layout_type): array {
        $sections       = [];
        $current_label  = '';
        $current_fields = [];

        foreach ($fields as $field) {
            if ($field['type'] === $layout_type) {
                // Save previous section if it has fields
                if (!empty($current_fields)) {
                    $sections[] = [
                        'label'  => $current_label,
                        'fields' => $current_fields,
                    ];
                }
                $current_label  = $field['label'] ?? '';
                $current_fields = [];
            } else {
                $current_fields[] = $field;
            }
        }

        // Last section
        if (!empty($current_fields)) {
            $sections[] = [
                'label'  => $current_label,
                'fields' => $current_fields,
            ];
        }

        return $sections;
    }

    /**
     * Render tab navigation and panels.
     */
    private function render_tabs(array $sections): void {
        if (empty($sections)) {
            return;
        }

        echo '<div class="eff-tabs">';

        // Tab navigation
        echo '<div class="eff-tabs__nav" role="tablist">';
        foreach ($sections as $i => $section) {
            $active = 0 === $i ? ' eff-tabs__btn--active' : '';
            $label  = !empty($section['label']) ? $section['label'] : sprintf(__('Sección %d', 'acf-forms-frontend-creator'), $i + 1);
            echo '<button type="button" class="eff-tabs__btn' . $active . '" role="tab" aria-selected="' . (0 === $i ? 'true' : 'false') . '" data-tab="' . $i . '">' . esc_html($label) . '</button>';
        }
        echo '</div>';

        // Tab panels
        foreach ($sections as $i => $section) {
            $active = 0 === $i ? ' eff-tabs__panel--active' : '';
            echo '<div class="eff-tabs__panel' . $active . '" role="tabpanel" data-tab-panel="' . $i . '">';

            // Check for nested accordions within this tab
            $has_acc = false;
            foreach ($section['fields'] as $field) {
                if ('accordion' === $field['type']) {
                    $has_acc = true;
                    break;
                }
            }

            if ($has_acc) {
                $acc_sections = $this->split_by_type($section['fields'], 'accordion');
                $this->render_accordions($acc_sections);
            } else {
                foreach ($section['fields'] as $field) {
                    $this->render_field($field);
                }
            }

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Render multi-step wizard with progress bar and per-step navigation.
     */
    private function render_steps(array $sections): void {
        if (empty($sections)) {
            return;
        }

        $total = count($sections);

        echo '<div class="eff-wizard" data-total-steps="' . $total . '">';

        // Progress bar
        echo '<div class="eff-wizard__progress">';
        foreach ($sections as $i => $section) {
            $label  = !empty($section['label']) ? $section['label'] : sprintf(__('Paso %d', 'acf-forms-frontend-creator'), $i + 1);
            $active = 0 === $i ? ' eff-wizard__step--active' : '';
            echo '<div class="eff-wizard__step' . $active . '" data-step="' . $i . '">';
            echo '<span class="eff-wizard__number">' . ($i + 1) . '</span>';
            echo '<span class="eff-wizard__label">' . esc_html($label) . '</span>';
            echo '</div>';
        }
        echo '</div>';

        // Step panels
        foreach ($sections as $i => $section) {
            $active = 0 === $i ? ' eff-wizard__panel--active' : '';
            echo '<div class="eff-wizard__panel' . $active . '" data-step-panel="' . $i . '">';

            // Check for nested accordions within this step
            $has_acc = false;
            foreach ($section['fields'] as $field) {
                if ('accordion' === $field['type']) {
                    $has_acc = true;
                    break;
                }
            }

            if ($has_acc) {
                $acc_sections = $this->split_by_type($section['fields'], 'accordion');
                $this->render_accordions($acc_sections);
            } else {
                foreach ($section['fields'] as $field) {
                    $this->render_field($field);
                }
            }

            // Step navigation buttons
            echo '<div class="eff-wizard__nav">';
            if ($i > 0) {
                echo '<button type="button" class="eff-btn eff-btn--secondary eff-wizard__prev">' . esc_html__('Anterior', 'acf-forms-frontend-creator') . '</button>';
            }
            if ($i < $total - 1) {
                echo '<button type="button" class="eff-btn eff-btn--primary eff-wizard__next">' . esc_html__('Siguiente', 'acf-forms-frontend-creator') . '</button>';
            }
            echo '</div>';

            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Render accordion (collapsible) sections using <details> elements.
     */
    private function render_accordions(array $sections): void {
        if (empty($sections)) {
            return;
        }

        foreach ($sections as $i => $section) {
            $open  = 0 === $i ? ' open' : '';
            $label = !empty($section['label']) ? $section['label'] : sprintf(__('Sección %d', 'acf-forms-frontend-creator'), $i + 1);
            echo '<details class="eff-accordion"' . $open . '>';
            echo '<summary class="eff-accordion__header">' . esc_html($label) . '</summary>';
            echo '<div class="eff-accordion__body">';
            foreach ($section['fields'] as $field) {
                $this->render_field($field);
            }
            echo '</div>';
            echo '</details>';
        }
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
            $cond_attr = $this->build_conditional_attr($field);
            echo '<fieldset class="eff-fieldset"' . $cond_attr . ' data-field-name="' . esc_attr($field['name']) . '">';
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
            $cond_attr = $this->build_conditional_attr($field);
            echo '<fieldset class="eff-fieldset eff-repeater"' . $cond_attr . ' data-field-name="' . esc_attr($field['name']) . '" data-field="' . esc_attr($field['name']) . '" data-min="' . esc_attr($field['min'] ?? 0) . '" data-max="' . esc_attr($field['max'] ?? 0) . '">';
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

        // Conditional logic data attribute
        $cond_attr = $this->build_conditional_attr($field);

        echo '<div class="' . esc_attr($classes) . '"' . $cond_attr . ' data-field-name="' . esc_attr($field['name']) . '">';

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
     * Build a map of ACF field keys to field names for resolving conditional logic references.
     */
    private function build_field_key_map(array $fields): array {
        $map = [];
        foreach ($fields as $field) {
            if (!empty($field['key']) && !empty($field['name'])) {
                $map[$field['key']] = $field['name'];
            }
            if (!empty($field['sub_fields'])) {
                $map = array_merge($map, $this->build_field_key_map($field['sub_fields']));
            }
        }
        return $map;
    }

    /**
     * Build the data-eff-conditions attribute from a field's conditional_logic.
     *
     * ACF conditional_logic structure:
     *   [ // OR groups
     *     [ // AND rules within a group
     *       ['field' => 'field_abc123', 'operator' => '==', 'value' => 'yes'],
     *       ...
     *     ],
     *     ...
     *   ]
     *
     * We translate field keys to field names for frontend evaluation.
     */
    private function build_conditional_attr(array $field): string {
        if (empty($field['conditional_logic']) || !is_array($field['conditional_logic'])) {
            return '';
        }

        $rules = [];
        foreach ($field['conditional_logic'] as $or_group) {
            if (!is_array($or_group)) {
                continue;
            }
            $and_rules = [];
            foreach ($or_group as $rule) {
                $target_key  = $rule['field'] ?? '';
                $target_name = $this->field_key_map[$target_key] ?? '';
                if (empty($target_name)) {
                    continue;
                }
                $and_rules[] = [
                    'field'    => $target_name,
                    'operator' => $rule['operator'] ?? '==',
                    'value'    => $rule['value'] ?? '',
                ];
            }
            if (!empty($and_rules)) {
                $rules[] = $and_rules;
            }
        }

        if (empty($rules)) {
            return '';
        }

        return ' data-eff-conditions="' . esc_attr(wp_json_encode($rules)) . '"';
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
