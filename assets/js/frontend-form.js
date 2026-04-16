/**
 * ACF Forms Frontend Creator – Repeater & form enhancements.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initSteps();
        initRepeaters();
        initConditionalLogic();
        initFormEnhancements();
    });

    /**
     * Tab navigation: switch panels on click.
     */
    function initTabs() {
        document.querySelectorAll('.eff-tabs').forEach(function (tabContainer) {
            var buttons = tabContainer.querySelectorAll('.eff-tabs__btn');
            var panels  = tabContainer.querySelectorAll('.eff-tabs__panel');

            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetIndex = btn.dataset.tab;

                    // Deactivate all
                    buttons.forEach(function (b) {
                        b.classList.remove('eff-tabs__btn--active');
                        b.setAttribute('aria-selected', 'false');
                    });
                    panels.forEach(function (p) {
                        p.classList.remove('eff-tabs__panel--active');
                    });

                    // Activate target
                    btn.classList.add('eff-tabs__btn--active');
                    btn.setAttribute('aria-selected', 'true');
                    var targetPanel = tabContainer.querySelector('[data-tab-panel="' + targetIndex + '"]');
                    if (targetPanel) {
                        targetPanel.classList.add('eff-tabs__panel--active');
                    }
                });
            });
        });
    }

    /**
     * Reveal the tab panel containing a given element (for validation errors).
     */
    function revealFieldInTabs(el) {
        var panel = el.closest('.eff-tabs__panel');
        if (!panel) return;

        var tabContainer = panel.closest('.eff-tabs');
        if (!tabContainer) return;

        var tabIndex = panel.dataset.tabPanel;

        tabContainer.querySelectorAll('.eff-tabs__btn').forEach(function (b) {
            b.classList.remove('eff-tabs__btn--active');
            b.setAttribute('aria-selected', 'false');
        });
        tabContainer.querySelectorAll('.eff-tabs__panel').forEach(function (p) {
            p.classList.remove('eff-tabs__panel--active');
        });

        var btn = tabContainer.querySelector('[data-tab="' + tabIndex + '"]');
        if (btn) {
            btn.classList.add('eff-tabs__btn--active');
            btn.setAttribute('aria-selected', 'true');
        }
        panel.classList.add('eff-tabs__panel--active');
    }

    /**
     * Multi-step wizard: step navigation with per-step validation.
     */
    function initSteps() {
        document.querySelectorAll('.eff-wizard').forEach(function (wizard) {
            var panels   = wizard.querySelectorAll('.eff-wizard__panel');
            var steps    = wizard.querySelectorAll('.eff-wizard__step');
            var form     = wizard.closest('.eff-form');
            var submitWrap = form ? form.querySelector('.eff-field--submit') : null;

            // Hide submit button until last step
            if (submitWrap) {
                submitWrap.style.display = 'none';
            }

            function goToStep(index) {
                panels.forEach(function (p) { p.classList.remove('eff-wizard__panel--active'); });
                steps.forEach(function (s) {
                    s.classList.remove('eff-wizard__step--active');
                    s.classList.remove('eff-wizard__step--done');
                });

                // Mark completed steps
                for (var j = 0; j < index; j++) {
                    steps[j].classList.add('eff-wizard__step--done');
                }

                if (panels[index]) {
                    panels[index].classList.add('eff-wizard__panel--active');
                }
                if (steps[index]) {
                    steps[index].classList.add('eff-wizard__step--active');
                }

                // Show/hide submit button
                var isLast = index === panels.length - 1;
                if (submitWrap) {
                    submitWrap.style.display = isLast ? '' : 'none';
                }

                // Scroll to top of wizard
                wizard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            function validatePanel(panel) {
                var errors = [];

                // Clear previous error styling in this panel
                panel.querySelectorAll('.eff-client-error').forEach(function (el) { el.remove(); });
                panel.querySelectorAll('.eff-input--error').forEach(function (el) {
                    el.classList.remove('eff-input--error');
                });

                // Required fields
                panel.querySelectorAll('[required]').forEach(function (input) {
                    // Skip fields hidden by conditional logic
                    var wrapper = input.closest('[data-eff-hidden]');
                    if (wrapper) return;

                    if (input.type === 'file') {
                        if (!input.files || input.files.length === 0) {
                            errors.push(input);
                        }
                    } else if (input.type === 'checkbox' || input.type === 'radio') {
                        var name = input.name;
                        var form = input.closest('form');
                        if (!form.querySelector('input[name="' + name + '"]:checked')) {
                            if (errors.indexOf(input) === -1) errors.push(input);
                        }
                    } else {
                        if (!input.value || input.value.trim() === '') {
                            errors.push(input);
                        }
                    }
                });

                // File type validation
                panel.querySelectorAll('input[type="file"]').forEach(function (input) {
                    if (!input.files || input.files.length === 0) return;
                    var acceptAttr = input.getAttribute('accept');
                    if (!acceptAttr) return;
                    var fileExt = '.' + input.files[0].name.split('.').pop().toLowerCase();
                    var allowedList = acceptAttr.split(',').map(function (a) { return a.trim().toLowerCase(); });
                    var allowed = allowedList.some(function (a) {
                        return a.indexOf('/') !== -1 || a === fileExt;
                    });
                    if (!allowed) {
                        errors.push(input);
                    }
                });

                if (errors.length > 0) {
                    errors.forEach(function (input) {
                        input.classList.add('eff-input--error');
                        var container = input.closest('.eff-field') || input.parentNode;
                        var msg = document.createElement('p');
                        msg.className = 'eff-client-error';
                        msg.textContent = input.type === 'file'
                            ? 'Debe seleccionar un archivo.'
                            : 'Este campo es obligatorio.';
                        container.appendChild(msg);
                    });

                    // Open any closed accordion containing the first error
                    var details = errors[0].closest('details.eff-accordion');
                    if (details && !details.open) { details.open = true; }

                    var first = errors[0].closest('.eff-field') || errors[0].parentNode;
                    first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    errors[0].focus();
                    return false;
                }

                return true;
            }

            // Next buttons
            wizard.addEventListener('click', function (e) {
                if (!e.target.classList.contains('eff-wizard__next')) return;

                var currentPanel = e.target.closest('.eff-wizard__panel');
                var currentIndex = parseInt(currentPanel.dataset.stepPanel, 10);

                // Also validate the title field if on step 0
                if (currentIndex === 0 && form) {
                    var titleInput = form.querySelector('#eff_title');
                    if (titleInput && titleInput.hasAttribute('required') && (!titleInput.value || titleInput.value.trim() === '')) {
                        titleInput.classList.add('eff-input--error');
                        var container = titleInput.closest('.eff-field') || titleInput.parentNode;
                        if (!container.querySelector('.eff-client-error')) {
                            var msg = document.createElement('p');
                            msg.className = 'eff-client-error';
                            msg.textContent = 'Este campo es obligatorio.';
                            container.appendChild(msg);
                        }
                        container.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        titleInput.focus();
                        return;
                    }
                }

                if (!validatePanel(currentPanel)) return;

                goToStep(currentIndex + 1);
            });

            // Previous buttons
            wizard.addEventListener('click', function (e) {
                if (!e.target.classList.contains('eff-wizard__prev')) return;

                var currentPanel = e.target.closest('.eff-wizard__panel');
                var currentIndex = parseInt(currentPanel.dataset.stepPanel, 10);
                goToStep(currentIndex - 1);
            });

            // Clickable progress steps (only for completed steps)
            steps.forEach(function (step) {
                step.addEventListener('click', function () {
                    if (step.classList.contains('eff-wizard__step--done')) {
                        goToStep(parseInt(step.dataset.step, 10));
                    }
                });
            });
        });
    }

    /**
     * Reveal the wizard step containing a given element (for server-side validation errors).
     */
    function revealFieldInSteps(el) {
        var panel = el.closest('.eff-wizard__panel');
        if (!panel) return;

        var wizard = panel.closest('.eff-wizard');
        if (!wizard) return;

        var stepIndex = parseInt(panel.dataset.stepPanel, 10);
        var panels = wizard.querySelectorAll('.eff-wizard__panel');
        var steps  = wizard.querySelectorAll('.eff-wizard__step');
        var form   = wizard.closest('.eff-form');
        var submitWrap = form ? form.querySelector('.eff-field--submit') : null;

        panels.forEach(function (p) { p.classList.remove('eff-wizard__panel--active'); });
        steps.forEach(function (s) {
            s.classList.remove('eff-wizard__step--active');
            s.classList.remove('eff-wizard__step--done');
        });

        for (var j = 0; j < stepIndex; j++) {
            steps[j].classList.add('eff-wizard__step--done');
        }

        panel.classList.add('eff-wizard__panel--active');
        if (steps[stepIndex]) {
            steps[stepIndex].classList.add('eff-wizard__step--active');
        }

        if (submitWrap) {
            submitWrap.style.display = stepIndex === panels.length - 1 ? '' : 'none';
        }
    }

    function initRepeaters() {
        document.querySelectorAll('.eff-repeater').forEach(function (repeater) {
            var fieldName = repeater.dataset.field;
            var maxRows   = parseInt(repeater.dataset.max, 10) || 0;
            var rowsWrap  = repeater.querySelector('.eff-repeater-rows');
            var addBtn    = repeater.querySelector('.eff-repeater-add');

            if (!rowsWrap || !addBtn) return;

            // Add row
            addBtn.addEventListener('click', function () {
                var rows     = rowsWrap.querySelectorAll('.eff-repeater-row');
                var newIndex = rows.length;

                if (maxRows > 0 && newIndex >= maxRows) {
                    return;
                }

                var template = rows[0];
                if (!template) return;

                var clone = template.cloneNode(true);
                clone.dataset.index = newIndex;

                // Update field names and IDs
                clone.querySelectorAll('[name]').forEach(function (input) {
                    input.name = input.name.replace(
                        new RegExp(fieldName + '_\\d+_'),
                        fieldName + '_' + newIndex + '_'
                    );
                    // Clear values
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        input.checked = false;
                    } else {
                        input.value = '';
                    }
                });

                clone.querySelectorAll('[id]').forEach(function (el) {
                    el.id = el.id.replace(/_\d+_/, '_' + newIndex + '_');
                });

                clone.querySelectorAll('label[for]').forEach(function (el) {
                    el.htmlFor = el.htmlFor.replace(/_\d+_/, '_' + newIndex + '_');
                });

                rowsWrap.appendChild(clone);
                updateRemoveButtons(rowsWrap);
            });

            // Remove row (delegated)
            rowsWrap.addEventListener('click', function (e) {
                if (!e.target.classList.contains('eff-repeater-remove')) return;

                var rows = rowsWrap.querySelectorAll('.eff-repeater-row');
                var minRows = parseInt(repeater.dataset.min, 10) || 1;
                if (rows.length <= minRows) return;

                e.target.closest('.eff-repeater-row').remove();
                reindexRows(rowsWrap, fieldName);
                updateRemoveButtons(rowsWrap);
            });

            updateRemoveButtons(rowsWrap);
        });
    }

    function reindexRows(rowsWrap, fieldName) {
        rowsWrap.querySelectorAll('.eff-repeater-row').forEach(function (row, idx) {
            row.dataset.index = idx;
            row.querySelectorAll('[name]').forEach(function (input) {
                input.name = input.name.replace(
                    new RegExp(fieldName + '_\\d+_'),
                    fieldName + '_' + idx + '_'
                );
            });
        });
    }

    function updateRemoveButtons(rowsWrap) {
        var rows = rowsWrap.querySelectorAll('.eff-repeater-row');
        var repeater = rowsWrap.closest('.eff-repeater');
        var minRows  = parseInt(repeater.dataset.min, 10) || 1;

        rows.forEach(function (row) {
            var btn = row.querySelector('.eff-repeater-remove');
            if (btn) {
                btn.style.display = rows.length <= minRows ? 'none' : '';
            }
        });
    }

    /**
     * Form enhancements: scroll to notices, loading state, AJAX submission.
     */
    function initFormEnhancements() {
        // Scroll to notice if present (server-side fallback)
        var notice = document.querySelector('.eff-notice');
        if (notice) {
            notice.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        document.querySelectorAll('.eff-form').forEach(function (form) {
            var btn = form.querySelector('.eff-btn--primary');
            var originalBtnText = btn ? btn.textContent : '';

            form.addEventListener('submit', function (e) {
                e.preventDefault();

                // Remove previous notices and client errors
                var oldNotice = form.parentNode.querySelector('.eff-notice');
                if (oldNotice) oldNotice.remove();
                form.querySelectorAll('.eff-client-error').forEach(function (el) {
                    el.remove();
                });
                form.querySelectorAll('.eff-input--error').forEach(function (el) {
                    el.classList.remove('eff-input--error');
                });

                // Client-side required validation
                var errors = [];
                form.querySelectorAll('[required]').forEach(function (input) {
                    // Skip fields hidden by conditional logic
                    var wrapper = input.closest('[data-eff-hidden]');
                    if (wrapper) return;

                    if (input.type === 'file') {
                        if (!input.files || input.files.length === 0) {
                            errors.push(input);
                        }
                    } else if (input.type === 'checkbox' || input.type === 'radio') {
                        var name = input.name;
                        if (!form.querySelector('input[name="' + name + '"]:checked')) {
                            if (errors.indexOf(input) === -1) errors.push(input);
                        }
                    } else {
                        if (!input.value || input.value.trim() === '') {
                            errors.push(input);
                        }
                    }
                });

                // Client-side file type validation using accept attribute
                var fileTypeErrors = [];
                form.querySelectorAll('input[type="file"]').forEach(function (input) {
                    if (!input.files || input.files.length === 0) return;
                    var acceptAttr = input.getAttribute('accept');
                    if (!acceptAttr) return;
                    var file = input.files[0];
                    var fileName = file.name;
                    var fileExt = '.' + fileName.split('.').pop().toLowerCase();
                    var allowedList = acceptAttr.split(',').map(function (a) { return a.trim().toLowerCase(); });
                    // Check if extension matches any pattern in accept
                    var allowed = allowedList.some(function (a) {
                        if (a.indexOf('/') !== -1) {
                            // MIME pattern like image/* — skip client check, server validates
                            return true;
                        }
                        return a === fileExt;
                    });
                    if (!allowed) {
                        fileTypeErrors.push({ input: input, ext: fileExt, allowed: acceptAttr });
                    }
                });

                if (fileTypeErrors.length > 0) {
                    fileTypeErrors.forEach(function (err) {
                        err.input.classList.add('eff-input--error');
                        var container = err.input.closest('.eff-field') || err.input.parentNode;
                        var msg = document.createElement('p');
                        msg.className = 'eff-client-error';
                        msg.textContent = 'Tipo de archivo no permitido (' + err.ext + '). Permitidos: ' + err.allowed;
                        container.appendChild(msg);
                    });
                    var firstErr = fileTypeErrors[0].input;
                    revealFieldInTabs(firstErr);
                    var details = firstErr.closest('details.eff-accordion');
                    if (details && !details.open) {
                        details.open = true;
                    }
                    var firstContainer = firstErr.closest('.eff-field') || firstErr.parentNode;
                    firstContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstErr.focus();
                    return;
                }

                if (errors.length > 0) {
                    showFieldErrors(errors);
                    return;
                }

                // Terms & privacy checkbox validation
                var termsCheckbox = form.querySelector('#eff_terms_accepted');
                if (termsCheckbox && termsCheckbox.hasAttribute('required') && !termsCheckbox.checked) {
                    termsCheckbox.classList.add('eff-input--error');
                    var termsContainer = termsCheckbox.closest('.eff-field') || termsCheckbox.parentNode;
                    var termsMsg = document.createElement('p');
                    termsMsg.className = 'eff-client-error';
                    termsMsg.textContent = 'Debe aceptar los términos de servicio y la política de privacidad.';
                    termsContainer.appendChild(termsMsg);
                    termsContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    termsCheckbox.focus();
                    return;
                }

                // Loading state
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Enviando...';
                }

                // Build FormData and add AJAX action
                var formData = new FormData(form);
                formData.append('action', 'eff_submit_form');

                fetch(effAjax.url, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        // Show success message, hide form
                        var successDiv = document.createElement('div');
                        successDiv.className = 'eff-notice eff-notice--success';
                        successDiv.innerHTML = '<p>' + escapeHtml(data.data.message) + '</p>';
                        form.parentNode.insertBefore(successDiv, form);

                        var retryP = document.createElement('p');
                        retryP.innerHTML = '<a href="" class="eff-btn eff-btn--secondary">Enviar otro registro</a>';
                        form.parentNode.insertBefore(retryP, form);

                        form.style.display = 'none';
                        successDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else {
                        // Show server-side error messages
                        var messages = data.data && data.data.messages ? data.data.messages : ['Error desconocido.'];
                        var errorDiv = document.createElement('div');
                        errorDiv.className = 'eff-notice eff-notice--error';
                        messages.forEach(function (msg) {
                            var p = document.createElement('p');
                            p.textContent = msg;
                            errorDiv.appendChild(p);
                        });
                        form.parentNode.insertBefore(errorDiv, form);
                        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        resetBtn();
                    }
                })
                .catch(function () {
                    var errorDiv = document.createElement('div');
                    errorDiv.className = 'eff-notice eff-notice--error';
                    errorDiv.innerHTML = '<p>Error de conexi\u00f3n. Por favor intenta de nuevo.</p>';
                    form.parentNode.insertBefore(errorDiv, form);
                    errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    resetBtn();
                });
            });

            function resetBtn() {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = originalBtnText;
                }
            }

            function showFieldErrors(fieldErrors) {
                fieldErrors.forEach(function (input) {
                    input.classList.add('eff-input--error');
                    var container = input.closest('.eff-field') || input.parentNode;
                    var msg = document.createElement('p');
                    msg.className = 'eff-client-error';
                    msg.textContent = input.type === 'file'
                        ? 'Debe seleccionar un archivo.'
                        : 'Este campo es obligatorio.';
                    container.appendChild(msg);
                });

                // Reveal the first error field if inside a tab, wizard step, or closed accordion
                var firstInput = fieldErrors[0];
                revealFieldInTabs(firstInput);
                revealFieldInSteps(firstInput);
                var details = firstInput.closest('details.eff-accordion');
                if (details && !details.open) {
                    details.open = true;
                }

                var first = firstInput.closest('.eff-field') || firstInput.parentNode;
                first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInput.focus();
            }

            // Clear error styling on input/change
            form.addEventListener('input', function (e) {
                clearFieldError(e.target);
            });
            form.addEventListener('change', function (e) {
                clearFieldError(e.target);
            });
        });
    }

    function clearFieldError(target) {
        if (target.classList.contains('eff-input--error')) {
            target.classList.remove('eff-input--error');
        }
        var container = target.closest('.eff-field') || target.parentNode;
        if (container) {
            var err = container.querySelector('.eff-client-error');
            if (err) err.remove();
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    /**
     * Conditional logic: show/hide fields based on ACF conditional_logic rules.
     *
     * Each field with conditions has: data-eff-conditions='[[{"field":"name","operator":"==","value":"x"}]]'
     * Structure: array of OR groups, each containing AND rules.
     * A field is visible if ANY OR group is fully satisfied (all AND rules pass).
     */
    function initConditionalLogic() {
        var condFields = document.querySelectorAll('[data-eff-conditions]');
        if (condFields.length === 0) return;

        // Parse conditions and collect trigger field names
        var entries = [];
        var triggerNames = {};

        condFields.forEach(function (el) {
            var raw = el.getAttribute('data-eff-conditions');
            try {
                var conditions = JSON.parse(raw);
            } catch (e) {
                return;
            }
            var entry = { el: el, conditions: conditions };
            entries.push(entry);

            // Collect all trigger field names
            conditions.forEach(function (orGroup) {
                orGroup.forEach(function (rule) {
                    triggerNames[rule.field] = true;
                });
            });
        });

        if (entries.length === 0) return;

        // Find the form(s) containing conditional fields
        var forms = [];
        document.querySelectorAll('.eff-form').forEach(function (f) { forms.push(f); });

        /**
         * Get the current value of a field by its ACF field name.
         */
        function getFieldValue(fieldName) {
            for (var fi = 0; fi < forms.length; fi++) {
                var form = forms[fi];

                // Radio buttons
                var checkedRadio = form.querySelector('input[name="acf[' + fieldName + ']"][type="radio"]:checked');
                if (checkedRadio) return checkedRadio.value;

                // Check if there are radio inputs for this field (unchecked = empty)
                var radios = form.querySelectorAll('input[name="acf[' + fieldName + ']"][type="radio"]');
                if (radios.length > 0) return '';

                // Checkboxes (multi-value)
                var checkboxes = form.querySelectorAll('input[name="acf[' + fieldName + '][]"][type="checkbox"]');
                if (checkboxes.length > 0) {
                    var vals = [];
                    checkboxes.forEach(function (cb) {
                        if (cb.checked) vals.push(cb.value);
                    });
                    return vals;
                }

                // Single checkbox (true_false)
                var singleCb = form.querySelector('input[name="acf[' + fieldName + ']"][type="checkbox"]');
                if (singleCb) return singleCb.checked ? '1' : '0';

                // Select
                var sel = form.querySelector('select[name="acf[' + fieldName + ']"], select[name="acf[' + fieldName + '][]"]');
                if (sel) {
                    if (sel.multiple) {
                        var selectedVals = [];
                        for (var oi = 0; oi < sel.options.length; oi++) {
                            if (sel.options[oi].selected && sel.options[oi].value !== '') {
                                selectedVals.push(sel.options[oi].value);
                            }
                        }
                        return selectedVals;
                    }
                    return sel.value;
                }

                // Text/number/date/etc inputs and textareas
                var input = form.querySelector(
                    'input[name="acf[' + fieldName + ']"], textarea[name="acf[' + fieldName + ']"]'
                );
                if (input) return input.value;
            }
            return '';
        }

        /**
         * Evaluate a single rule against the current field value.
         */
        function evaluateRule(rule) {
            var actual = getFieldValue(rule.field);
            var expected = rule.value;
            var op = rule.operator;

            // Handle array values (checkboxes, multi-selects)
            if (Array.isArray(actual)) {
                switch (op) {
                    case '==':
                        return actual.indexOf(expected) !== -1;
                    case '!=':
                        return actual.indexOf(expected) === -1;
                    case '==empty':
                        return actual.length === 0;
                    case '!=empty':
                        return actual.length > 0;
                    default:
                        return actual.indexOf(expected) !== -1;
                }
            }

            // Scalar comparison
            switch (op) {
                case '==':
                    return String(actual) === String(expected);
                case '!=':
                    return String(actual) !== String(expected);
                case '==empty':
                    return actual === '' || actual === null || actual === undefined;
                case '!=empty':
                    return actual !== '' && actual !== null && actual !== undefined;
                case '==contains':
                    return String(actual).indexOf(String(expected)) !== -1;
                case '==pattern':
                    try { return new RegExp(expected).test(String(actual)); } catch (e) { return false; }
                case '<':
                    return parseFloat(actual) < parseFloat(expected);
                case '>':
                    return parseFloat(actual) > parseFloat(expected);
                case '<=':
                    return parseFloat(actual) <= parseFloat(expected);
                case '>=':
                    return parseFloat(actual) >= parseFloat(expected);
                default:
                    return String(actual) === String(expected);
            }
        }

        /**
         * Evaluate all conditions for a single entry (OR of ANDs).
         */
        function evaluateConditions(conditions) {
            for (var i = 0; i < conditions.length; i++) {
                var orGroup = conditions[i];
                var allPass = true;
                for (var j = 0; j < orGroup.length; j++) {
                    if (!evaluateRule(orGroup[j])) {
                        allPass = false;
                        break;
                    }
                }
                if (allPass) return true;
            }
            return false;
        }

        /**
         * Run all conditional logic evaluations and show/hide fields.
         */
        function runConditionalLogic() {
            entries.forEach(function (entry) {
                var visible = evaluateConditions(entry.conditions);
                if (visible) {
                    entry.el.classList.remove('eff-field--hidden');
                    entry.el.removeAttribute('data-eff-hidden');
                } else {
                    entry.el.classList.add('eff-field--hidden');
                    entry.el.setAttribute('data-eff-hidden', '1');

                    // Clear validation errors when hiding
                    entry.el.querySelectorAll('.eff-input--error').forEach(function (el) {
                        el.classList.remove('eff-input--error');
                    });
                    entry.el.querySelectorAll('.eff-client-error').forEach(function (el) {
                        el.remove();
                    });
                }
            });
        }

        // Initial evaluation
        runConditionalLogic();

        // Bind change/input events on trigger fields
        forms.forEach(function (form) {
            form.addEventListener('change', function (e) {
                var target = e.target;
                if (!target.name) return;

                // Extract field name from acf[field_name] or acf[field_name][]
                var match = target.name.match(/^acf\[([^\]]+)\]/);
                if (!match) return;

                var changedName = match[1];
                if (triggerNames[changedName]) {
                    runConditionalLogic();
                }
            });

            form.addEventListener('input', function (e) {
                var target = e.target;
                if (!target.name || target.type === 'checkbox' || target.type === 'radio') return;

                var match = target.name.match(/^acf\[([^\]]+)\]/);
                if (!match) return;

                var changedName = match[1];
                if (triggerNames[changedName]) {
                    runConditionalLogic();
                }
            });
        });
    }
})();
