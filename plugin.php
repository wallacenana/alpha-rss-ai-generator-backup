<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ALPHA_RSS_AI_GENERATOR_STYLE_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_STYLE_PATH', plugin_dir_path(__FILE__) . 'assets/css/style.css');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_STYLE_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_STYLE_URL', plugin_dir_url(__FILE__) . 'assets/css/style.css');
}

if (!defined('ALPHA_RSS_AI_GENERATOR_SCRIPT_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_SCRIPT_URL', plugin_dir_url(__FILE__) . 'assets/js/scripts.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_PATH', plugin_dir_path(__FILE__) . 'assets/js/swal-bridge.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_URL', plugin_dir_url(__FILE__) . 'assets/js/swal-bridge.js');
}

if (!function_exists('alpha_rss_ai_generator_enqueue_assets')) {
    function alpha_rss_ai_generator_enqueue_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === '' || strpos($page, 'alpha-rss-ai-') !== 0) {
            return;
        }

        if (file_exists(ALPHA_RSS_AI_GENERATOR_STYLE_PATH)) {
            wp_enqueue_style(
                'alpha-rss-ai-generator-style',
                ALPHA_RSS_AI_GENERATOR_STYLE_URL,
                array(),
                filemtime(ALPHA_RSS_AI_GENERATOR_STYLE_PATH)
            );
        }

        wp_enqueue_style(
            'alpha-rss-ai-generator-swal',
            'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css',
            array(),
            '11.0.0'
        );

        wp_enqueue_script(
            'alpha-rss-ai-generator-sweetalert2',
            'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js',
            array(),
            '11.0.0',
            false
        );

        if (file_exists(ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_PATH)) {
            wp_enqueue_script(
                'alpha-rss-ai-generator-swal-bridge',
                ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_URL,
                array('alpha-rss-ai-generator-sweetalert2'),
                filemtime(ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_PATH),
                false
            );
        }

        $script_path = plugin_dir_path(__FILE__) . 'assets/js/scripts.js';
        if (file_exists($script_path)) {
            wp_enqueue_script(
                'alpha-rss-ai-generator-script',
                ALPHA_RSS_AI_GENERATOR_SCRIPT_URL,
                array('jquery'),
                filemtime($script_path),
                true
            );
        }
    }
}

add_action('admin_enqueue_scripts', 'alpha_rss_ai_generator_enqueue_assets');
