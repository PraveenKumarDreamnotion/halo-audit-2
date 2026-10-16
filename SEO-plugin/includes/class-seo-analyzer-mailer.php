<?php
declare(strict_types=1);

class Seo_Analyzer_Mailer {
    private static function get_brevo_api_key() {
        return get_option('seo_analyzer_brevo_api_key');
    }

    private static function get_sender_email() {
        return get_option('seo_analyzer_sender_email', get_option('admin_email'));
    }

    public static function send_email($to, $subject, $message) {
        $api_key = self::get_brevo_api_key();
        
        if (empty($api_key)) {
            // Fallback to default WordPress mail if no API key is set
            return wp_mail($to, $subject, $message);
        }

        $url = 'https://api.brevo.com/v3/smtp/email';
        $sender_email = self::get_sender_email();
        $site_name = get_bloginfo('name');
        // If site name is empty, use a default name
        $sender_name = !empty($site_name) ? $site_name : 'SEO Analyzer';

        // Extract recipient name from email address
        $recipient_name = substr($to, 0, strpos($to, '@'));
        $recipient_name = ucwords(str_replace(['.', '_', '-'], ' ', $recipient_name));

        $body = array(
            'sender' => array(
                'email' => $sender_email,
                'name' => $sender_name
            ),
            'to' => array(
                array(
                    'email' => $to,
                    'name' => $recipient_name // Use extracted name from email
                )
            ),
            'subject' => $subject,
            'htmlContent' => nl2br($message), // Convert newlines to <br> tags
            'textContent' => $message
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'accept' => 'application/json',
                'api-key' => $api_key,
                'content-type' => 'application/json',
            ),
            'body' => json_encode($body),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            // Fallback to default mail on error
            return wp_mail($to, $subject, $message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 201) {
            // Fallback to default mail on error
            return wp_mail($to, $subject, $message);
        }

        return true;
    }
} 