<?php
/*
Plugin Name: Alpha RSS AI Generator
Description: Geradores RSS com reescrita via OpenAI, imagens do Pexels, SEO, execuções manuais e agendamento aleatório.
Version: 1.6.12
Author: Wallace Tavares e OpenAI
*/

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ALPHA_RSS_AI_GENERATOR_PLUGIN_DIR')) {
    define('ALPHA_RSS_AI_GENERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

$alpha_rss_ai_autoload_file = ALPHA_RSS_AI_GENERATOR_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($alpha_rss_ai_autoload_file)) {
    require_once $alpha_rss_ai_autoload_file;
}

require_once __DIR__ . '/plugin.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/generated-posts.php';
require_once __DIR__ . '/includes/related-posts.php';
require_once __DIR__ . '/includes/generator.php';

register_activation_hook(__FILE__, array('Alpha_RSS_AI_Generator', 'activate'));
register_deactivation_hook(__FILE__, array('Alpha_RSS_AI_Generator', 'deactivate'));
add_action('plugins_loaded', function () {
    Alpha_RSS_AI_Generator::instance()->boot();
});


