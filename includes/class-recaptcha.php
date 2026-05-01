<?php
/**
 * Google reCAPTCHA v3 handler.
 * Manages reCAPTCHA verification for form submissions.
 */
defined('ABSPATH') || exit;

class EFF_Recaptcha {

    /**
     * Verify reCAPTCHA token with Google.
     *
     * @param string $token      The reCAPTCHA response token from frontend.
     * @param string $secret_key The reCAPTCHA secret key.
     * @param float  $min_score  Minimum score threshold (0.0-1.0). Default 0.5.
     * @return array|WP_Error    Array with 'success' and 'score' on success, WP_Error on failure.
     */
    public static function verify(string $token, string $secret_key, float $min_score = 0.5) {
        if (empty($token) || empty($secret_key)) {
            return new WP_Error('recaptcha_missing_data', __('Token o clave secreta de reCAPTCHA faltante.', 'acf-forms-frontend-creator'));
        }

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout'   => 10,
            'sslverify' => true,
            'body'      => [
                'secret'   => sanitize_text_field($secret_key),
                'response' => sanitize_text_field($token),
            ],
        ]);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EFF reCAPTCHA: API request failed - ' . $response->get_error_message());
            }
            return new WP_Error('recaptcha_api_error', __('Error verificando reCAPTCHA. Por favor intenta de nuevo.', 'acf-forms-frontend-creator'));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['success'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EFF reCAPTCHA: Invalid response from Google - ' . $body);
            }
            return new WP_Error('recaptcha_invalid_response', __('Respuesta inválida de reCAPTCHA.', 'acf-forms-frontend-creator'));
        }

        // Check if reCAPTCHA validation was successful
        if (!$data['success']) {
            $error_codes = $data['error-codes'] ?? [];
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EFF reCAPTCHA: Verification failed - ' . implode(', ', $error_codes));
            }
            return new WP_Error('recaptcha_failed', __('Verificación de reCAPTCHA fallida. Por favor intenta de nuevo.', 'acf-forms-frontend-creator'));
        }

        // Check score if reCAPTCHA v3 (score-based)
        $score = isset($data['score']) ? (float) $data['score'] : 1.0;
        if ($score < $min_score) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("EFF reCAPTCHA: Score {$score} below threshold {$min_score}");
            }
            return new WP_Error('recaptcha_low_score', __('Verificación de seguridad fallida. Por favor intenta de nuevo.', 'acf-forms-frontend-creator'));
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("EFF reCAPTCHA: Verification successful (score: {$score})");
        }

        return [
            'success' => true,
            'score'   => $score,
        ];
    }

    /**
     * Check if reCAPTCHA is enabled in settings.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $settings = EFF_Admin_Settings::get_settings();
        return !empty($settings['enable_recaptcha']);
    }

    /**
     * Get reCAPTCHA site key from settings.
     *
     * @return string
     */
    public static function get_site_key(): string {
        $settings = EFF_Admin_Settings::get_settings();
        return isset($settings['recaptcha_site_key']) ? sanitize_text_field($settings['recaptcha_site_key']) : '';
    }

    /**
     * Get reCAPTCHA secret key from settings.
     *
     * @return string
     */
    public static function get_secret_key(): string {
        $settings = EFF_Admin_Settings::get_settings();
        return isset($settings['recaptcha_secret_key']) ? sanitize_text_field($settings['recaptcha_secret_key']) : '';
    }

    /**
     * Get minimum score threshold from settings.
     *
     * @return float
     */
    public static function get_min_score(): float {
        $settings = EFF_Admin_Settings::get_settings();
        $score = isset($settings['recaptcha_min_score']) ? (float) $settings['recaptcha_min_score'] : 0.5;
        return max(0.0, min(1.0, $score)); // Clamp between 0.0 and 1.0
    }
}
