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
if (!defined('ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_PATH', plugin_dir_path(__FILE__) . 'assets/css/review-card.css');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_URL', plugin_dir_url(__FILE__) . 'assets/css/review-card.css');
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
if (!defined('ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_STYLE_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_STYLE_PATH', plugin_dir_path(__FILE__) . 'assets/css/pexels-media.css');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_STYLE_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_STYLE_URL', plugin_dir_url(__FILE__) . 'assets/css/pexels-media.css');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_SCRIPT_PATH')) {
    define('ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_SCRIPT_PATH', plugin_dir_path(__FILE__) . 'assets/js/pexels-media.js');
}
if (!defined('ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_SCRIPT_URL')) {
    define('ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_SCRIPT_URL', plugin_dir_url(__FILE__) . 'assets/js/pexels-media.js');
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

if (!function_exists('alpha_rss_ai_generator_enqueue_pexels_media_assets')) {
    function alpha_rss_ai_generator_enqueue_pexels_media_assets($hook_suffix) {
        if (!in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
            return;
        }

        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_media();

        if (file_exists(ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_STYLE_PATH)) {
            wp_enqueue_style(
                'alpha-rss-ai-pexels-media',
                ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_STYLE_URL,
                array(),
                filemtime(ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_STYLE_PATH)
            );
        }

        if (file_exists(ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_SCRIPT_PATH)) {
            wp_enqueue_script(
                'alpha-rss-ai-pexels-media',
                ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_SCRIPT_URL,
                array('jquery', 'media-editor', 'media-views', 'wp-util'),
                filemtime(ALPHA_RSS_AI_GENERATOR_PEXELS_MEDIA_SCRIPT_PATH),
                true
            );

            wp_localize_script('alpha-rss-ai-pexels-media', 'AlphaRSSAIPexelsMedia', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('alpha_rss_ai_pexels_media'),
                'postId' => isset($_GET['post']) ? absint($_GET['post']) : 0,
            ));
        }
    }
}

add_action('admin_enqueue_scripts', 'alpha_rss_ai_generator_enqueue_pexels_media_assets');

if (!function_exists('alpha_rss_ai_generator_enqueue_review_card_styles')) {
    function alpha_rss_ai_generator_enqueue_review_card_style_asset() {
        if (!file_exists(ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_PATH)) {
            return;
        }

        wp_enqueue_style(
            'alpha-rss-ai-review-card',
            ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_URL,
            array(),
            filemtime(ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_PATH)
        );
    }

    function alpha_rss_ai_generator_enqueue_review_card_styles() {
        alpha_rss_ai_generator_enqueue_review_card_style_asset();
    }

    function alpha_rss_ai_generator_enqueue_review_card_editor_styles($hook_suffix) {
        if (in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
            alpha_rss_ai_generator_enqueue_review_card_style_asset();
        }
    }

    function alpha_rss_ai_generator_add_review_card_editor_iframe_styles($editor_settings) {
        if (!file_exists(ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_PATH)) {
            return $editor_settings;
        }

        $css = file_get_contents(ALPHA_RSS_AI_GENERATOR_REVIEW_STYLE_PATH);
        if ($css === false || trim($css) === '') {
            return $editor_settings;
        }

        if (empty($editor_settings['styles']) || !is_array($editor_settings['styles'])) {
            $editor_settings['styles'] = array();
        }

        $editor_settings['styles'][] = array('css' => $css);
        return $editor_settings;
    }
}

add_action('wp_enqueue_scripts', 'alpha_rss_ai_generator_enqueue_review_card_styles');
add_action('enqueue_block_assets', 'alpha_rss_ai_generator_enqueue_review_card_styles');
add_action('enqueue_block_editor_assets', 'alpha_rss_ai_generator_enqueue_review_card_styles');
add_action('admin_enqueue_scripts', 'alpha_rss_ai_generator_enqueue_review_card_editor_styles');
add_filter('block_editor_settings_all', 'alpha_rss_ai_generator_add_review_card_editor_iframe_styles');
