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
if (!defined('ALPHA_RSS_AI_GENERATOR_TAILWIND_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_TAILWIND_PATH', plugin_dir_path(__FILE__) . 'assets/vendor/tailwind/tailwind.min.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_TAILWIND_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_TAILWIND_URL', plugin_dir_url(__FILE__) . 'assets/vendor/tailwind/tailwind.min.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_PATH', plugin_dir_path(__FILE__) . 'assets/js/swal-bridge.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_URL', plugin_dir_url(__FILE__) . 'assets/js/swal-bridge.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SWAL_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_SWAL_PATH', plugin_dir_path(__FILE__) . 'assets/vendor/sweetalert2/sweetalert2.all.min.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SWAL_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_SWAL_URL', plugin_dir_url(__FILE__) . 'assets/vendor/sweetalert2/sweetalert2.all.min.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SWAL_CSS_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_SWAL_CSS_PATH', plugin_dir_path(__FILE__) . 'assets/vendor/sweetalert2/sweetalert2.min.css');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SWAL_CSS_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_SWAL_CSS_URL', plugin_dir_url(__FILE__) . 'assets/vendor/sweetalert2/sweetalert2.min.css');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SELECT2_JS_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_SELECT2_JS_PATH', plugin_dir_path(__FILE__) . 'assets/vendor/select2/select2.min.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SELECT2_JS_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_SELECT2_JS_URL', plugin_dir_url(__FILE__) . 'assets/vendor/select2/select2.min.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SELECT2_CSS_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_SELECT2_CSS_PATH', plugin_dir_path(__FILE__) . 'assets/vendor/select2/select2.min.css');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_SELECT2_CSS_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_SELECT2_CSS_URL', plugin_dir_url(__FILE__) . 'assets/vendor/select2/select2.min.css');
}

if (!function_exists('alpha_rss_ai_generator_enqueue_assets')) {
    function alpha_rss_ai_generator_enqueue_assets() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin page slug used to scope asset loading.
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

        if (file_exists(ALPHA_RSS_AI_GENERATOR_SWAL_CSS_PATH)) {
            wp_enqueue_style(
                'alpha-rss-ai-generator-swal',
                ALPHA_RSS_AI_GENERATOR_SWAL_CSS_URL,
                array(),
                filemtime(ALPHA_RSS_AI_GENERATOR_SWAL_CSS_PATH)
            );
        }

        if (file_exists(ALPHA_RSS_AI_GENERATOR_SWAL_PATH)) {
            wp_enqueue_script(
                'alpha-rss-ai-generator-sweetalert2',
                ALPHA_RSS_AI_GENERATOR_SWAL_URL,
                array(),
                filemtime(ALPHA_RSS_AI_GENERATOR_SWAL_PATH),
                false
            );
        }

        if (file_exists(ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_PATH)) {
            wp_enqueue_script(
                'alpha-rss-ai-generator-swal-bridge',
                ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_URL,
                array('alpha-rss-ai-generator-sweetalert2'),
                filemtime(ALPHA_RSS_AI_GENERATOR_SWAL_BRIDGE_PATH),
                false
            );
        }

        if (file_exists(ALPHA_RSS_AI_GENERATOR_TAILWIND_PATH)) {
            wp_enqueue_script(
                'alpha-rss-ai-generator-tailwindcss',
                ALPHA_RSS_AI_GENERATOR_TAILWIND_URL,
                array(),
                filemtime(ALPHA_RSS_AI_GENERATOR_TAILWIND_PATH),
                false
            );
            wp_add_inline_script(
                'alpha-rss-ai-generator-tailwindcss',
                "window.tailwind = window.tailwind || {}; window.tailwind.config = { theme: { extend: { boxShadow: { soft: '0 20px 50px -30px rgba(15, 23, 42, 0.35)' } } } };",
                'before'
            );
        }

        if ($page === 'alpha-rss-ai-generator') {
            if (file_exists(ALPHA_RSS_AI_GENERATOR_SELECT2_CSS_PATH)) {
                wp_enqueue_style(
                    'alpha-rss-ai-generator-select2',
                    ALPHA_RSS_AI_GENERATOR_SELECT2_CSS_URL,
                    array(),
                    filemtime(ALPHA_RSS_AI_GENERATOR_SELECT2_CSS_PATH)
                );
            }
            if (file_exists(ALPHA_RSS_AI_GENERATOR_SELECT2_JS_PATH)) {
                wp_enqueue_script(
                    'alpha-rss-ai-generator-select2',
                    ALPHA_RSS_AI_GENERATOR_SELECT2_JS_URL,
                    array('jquery'),
                    filemtime(ALPHA_RSS_AI_GENERATOR_SELECT2_JS_PATH),
                    true
                );
            }
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
