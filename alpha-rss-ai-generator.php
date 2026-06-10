<?php
/*
Plugin Name: Alpha RSS AI Generator
Description: Geradores RSS com reescrita via OpenAI, imagens do Pexels, SEO, execuÃƒÂ§ÃƒÂµes manuais e agendamento aleatório.
Version: 1.6.9
Author: OpenAI
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

if (!class_exists('Alpha_RSS_AI_Generator')) {
    final class Alpha_RSS_AI_Generator
    {
    const VERSION = '1.6.9';
    const DB_VERSION = '1.6.9';
        const CRON_HOOK = 'alpha_rss_ai_generator_tick';
        const OPTION_KEY = 'alpha_rss_ai_settings';
        const TABLE_SUFFIX_GENERATORS = 'arc_generators';
        const TABLE_SUFFIX_RUNS = 'arc_runs';
        const TABLE_SUFFIX_ITEMS = 'arc_items';
        const TABLE_SUFFIX_LISTS = 'arc_keyword_lists';
        const TABLE_SUFFIX_LIST_ROWS = 'arc_keyword_list_rows';
        const TABLE_SUFFIX_IMPORT_LOGS = 'arc_keyword_import_logs';

        private static $instance = null;
        public static $table_generators;
        public static $table_runs;
        public static $table_items;
        public static $table_lists;
        public static $table_list_rows;
        public static $table_import_logs;

        public static function instance()
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct()
        {
            global $wpdb;
            self::$table_generators = $wpdb->prefix . self::TABLE_SUFFIX_GENERATORS;
            self::$table_runs = $wpdb->prefix . self::TABLE_SUFFIX_RUNS;
            self::$table_items = $wpdb->prefix . self::TABLE_SUFFIX_ITEMS;
            self::$table_lists = $wpdb->prefix . self::TABLE_SUFFIX_LISTS;
            self::$table_list_rows = $wpdb->prefix . self::TABLE_SUFFIX_LIST_ROWS;
            self::$table_import_logs = $wpdb->prefix . self::TABLE_SUFFIX_IMPORT_LOGS;
        }

        public function admin_menu()
        {
            if (class_exists('Alpha_RSS_AI_Generator_Admin')) {
                (new Alpha_RSS_AI_Generator_Admin())->admin_menu();
            }
        }

        public function register_rest_routes()
        {
            if (class_exists('Alpha_RSS_AI_Generator_REST')) {
                (new Alpha_RSS_AI_Generator_REST())->register_rest_routes();
            }
        }

        public static function activate()
        {
            self::instance()->maybe_upgrade_schema();
            self::instance()->ensure_cron_scheduled();

            $settings = get_option(self::OPTION_KEY, array());
            if (!is_array($settings)) {
                $settings = array();
            }
            $settings = array_merge(self::default_settings(), $settings);
            update_option(self::OPTION_KEY, $settings, false);

            if (class_exists('Alpha_RSS_AI_Related_Posts')) {
                $related_defaults = Alpha_RSS_AI_Related_Posts::get_default_settings();
                $related_settings = get_option(Alpha_RSS_AI_Related_Posts::OPTION_KEY, array());
                if (!is_array($related_settings)) {
                    $related_settings = array();
                }
                update_option(Alpha_RSS_AI_Related_Posts::OPTION_KEY, array_merge($related_defaults, $related_settings), false);
            }

            self::instance()->seed_next_run_for_active_generators();
        }

        public static function deactivate()
        {
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
            while ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK);
                $timestamp = wp_next_scheduled(self::CRON_HOOK);
            }
        }

        public function boot()
        {
            self::maybe_upgrade_schema();
            if (class_exists('Alpha_RSS_AI_Related_Posts')) {
                new Alpha_RSS_AI_Related_Posts();
            }
            if (class_exists('Alpha_RSS_AI_Generated_Posts')) {
                new Alpha_RSS_AI_Generated_Posts();
            }
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('admin_post_arc_save_settings', array($this, 'handle_save_settings'));
            add_action('admin_post_arc_save_generator', array($this, 'handle_save_generator'));
            add_action('admin_post_arc_delete_generator', array($this, 'handle_delete_generator'));
            add_action('admin_post_arc_run_generator', array($this, 'handle_run_generator'));
            add_action('admin_post_arc_duplicate_generator', array($this, 'handle_duplicate_generator'));
            add_action(self::CRON_HOOK, array($this, 'cron_tick'));
            add_filter('cron_schedules', array($this, 'add_cron_schedule'));
            add_action('rest_api_init', array($this, 'register_rest_routes'));
            add_action('init', array($this, 'ensure_cron_scheduled'));
        }

        public function add_cron_schedule($schedules)
        {
            if (!isset($schedules['alpha_five_minutes'])) {
                $schedules['alpha_five_minutes'] = array(
                    'interval' => 300,
                    'display'  => 'A cada 5 minutos',
                );
            }
            return $schedules;
        }

        public static function maybe_upgrade_schema()
        {
            $stored_version = get_option('alpha_rss_ai_db_version', '0');
            if ($stored_version !== self::DB_VERSION) {
                self::create_tables();
                update_option('alpha_rss_ai_db_version', self::DB_VERSION, false);
            }
        }

        public function ensure_cron_scheduled()
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 300, 'alpha_five_minutes', self::CRON_HOOK);
            }
        }

        public static function create_tables()
        {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset_collate = $wpdb->get_charset_collate();

            $sql_generators = "CREATE TABLE " . self::$table_generators . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(255) NOT NULL,
                feed_url text NOT NULL,
                source_type varchar(20) NOT NULL DEFAULT 'rss',
                list_id bigint(20) unsigned NOT NULL DEFAULT 0,
                keyword_list_mode varchar(20) NOT NULL DEFAULT 'keywords',
                status varchar(20) NOT NULL DEFAULT 'active',
                post_type varchar(60) NOT NULL DEFAULT 'post',
                post_status varchar(20) NOT NULL DEFAULT 'draft',
                author_id bigint(20) unsigned NOT NULL DEFAULT 0,
                category_ids longtext DEFAULT NULL,
                tags_default longtext DEFAULT NULL,
                custom_taxonomies longtext DEFAULT NULL,
                custom_meta longtext DEFAULT NULL,
                filters_json longtext DEFAULT NULL,
                model varchar(120) NOT NULL DEFAULT 'gpt-4.1-mini',
                temperature decimal(4,2) NOT NULL DEFAULT 0.70,
                max_tokens int(11) NOT NULL DEFAULT 3000,
                posts_per_run int(11) NOT NULL DEFAULT 1,
                schedule_type varchar(20) NOT NULL DEFAULT 'interval',
                interval_minutes int(11) NOT NULL DEFAULT 180,
                jitter_minutes int(11) NOT NULL DEFAULT 30,
                daily_start varchar(5) NOT NULL DEFAULT '08:00',
                daily_end varchar(5) NOT NULL DEFAULT '22:00',
                image_source_mode varchar(30) NOT NULL DEFAULT 'rss_or_pexels',
                pexels_enabled tinyint(1) NOT NULL DEFAULT 1,
                pexels_query varchar(255) NOT NULL DEFAULT '{{pexels_tags}}',
                source_video_enabled tinyint(1) NOT NULL DEFAULT 0,
                video_selector_class varchar(255) NOT NULL DEFAULT '',
                image_selector_class varchar(255) NOT NULL DEFAULT '',
                link_selector_class varchar(255) NOT NULL DEFAULT '',
                content_image_size varchar(20) NOT NULL DEFAULT 'medium',
                seo_enabled tinyint(1) NOT NULL DEFAULT 1,
                generation_language varchar(80) NOT NULL DEFAULT 'Português do Brasil',
                prompt_template longtext DEFAULT NULL,
                content_prompt_template longtext DEFAULT NULL,
                related_posts_enabled tinyint(1) NOT NULL DEFAULT 0,
                related_posts_position varchar(20) NOT NULL DEFAULT 'end',
                related_posts_interval int(11) NOT NULL DEFAULT 4,
                related_posts_min_h2 int(11) NOT NULL DEFAULT 1,
                related_posts_links_per_block int(11) NOT NULL DEFAULT 2,
                related_posts_same_category_only tinyint(1) NOT NULL DEFAULT 1,
                related_posts_allow_fallback tinyint(1) NOT NULL DEFAULT 1,
                related_posts_style varchar(20) NOT NULL DEFAULT 'list',
                related_posts_phrases longtext DEFAULT NULL,
                source_link_phrases longtext DEFAULT NULL,
                source_context_filters_json longtext DEFAULT NULL,
                last_run_at datetime DEFAULT NULL,
                next_run_at datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY status_next_run (status, next_run_at)
            ) {$charset_collate};";

            $sql_lists = "CREATE TABLE " . self::$table_lists . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                list_name varchar(255) NOT NULL,
                original_filename varchar(255) NOT NULL,
                file_path text DEFAULT NULL,
                file_type varchar(20) NOT NULL,
                headers_json longtext DEFAULT NULL,
                column_map_json longtext DEFAULT NULL,
                total_rows int(11) DEFAULT 0,
                generated_rows int(11) DEFAULT 0,
                pending_rows int(11) DEFAULT 0,
                invalid_rows int(11) DEFAULT 0,
                failed_rows int(11) DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) {$charset_collate};";

            $sql_list_rows = "CREATE TABLE " . self::$table_list_rows . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                list_id bigint(20) unsigned NOT NULL,
                row_number int(11) NOT NULL,
                row_data longtext NOT NULL,
                keyword varchar(255) DEFAULT NULL,
                source_title text DEFAULT NULL,
                source_url text DEFAULT NULL,
                final_slug varchar(255) DEFAULT NULL,
                slug_extension varchar(20) DEFAULT NULL,
                slug_is_valid tinyint(1) NOT NULL DEFAULT 1,
                row_status varchar(20) NOT NULL DEFAULT 'pending',
                post_id bigint(20) unsigned DEFAULT NULL,
                error_message text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY list_status (list_id, row_status),
                KEY list_row (list_id, row_number)
            ) {$charset_collate};";

            $sql_runs = "CREATE TABLE " . self::$table_runs . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                generator_id bigint(20) unsigned NOT NULL,
                item_guid varchar(255) DEFAULT NULL,
                item_permalink text DEFAULT NULL,
                post_id bigint(20) unsigned DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'info',
                message text DEFAULT NULL,
                request_json longtext DEFAULT NULL,
                response_json longtext DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY generator_status (generator_id, status),
                KEY item_guid (item_guid(191))
            ) {$charset_collate};";

            $sql_import_logs = "CREATE TABLE " . self::$table_import_logs . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                list_id bigint(20) unsigned NOT NULL DEFAULT 0,
                row_number int(11) NOT NULL DEFAULT 0,
                level varchar(20) NOT NULL DEFAULT 'error',
                code varchar(120) DEFAULT NULL,
                message text DEFAULT NULL,
                row_data longtext DEFAULT NULL,
                context_json longtext DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY list_level (list_id, level),
                KEY list_row (list_id, row_number)
            ) {$charset_collate};";

            $sql_items = "CREATE TABLE " . self::$table_items . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                generator_id bigint(20) unsigned NOT NULL,
                item_guid varchar(255) NOT NULL,
                item_permalink text DEFAULT NULL,
                item_title text DEFAULT NULL,
                post_id bigint(20) unsigned DEFAULT NULL,
                item_hash varchar(64) DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY generator_item (generator_id, item_guid(191)),
                KEY generator_post (generator_id, post_id)
            ) {$charset_collate};";

            dbDelta($sql_generators);
            dbDelta($sql_lists);
            dbDelta($sql_list_rows);
            dbDelta($sql_runs);
            dbDelta($sql_import_logs);
            dbDelta($sql_items);
        }

        public static function default_settings()
        {
            return array(
                'openai_api_key' => '',
                'pexels_api_key' => '',
                'default_model' => 'gpt-4.1-mini',
                'default_temperature' => 0.7,
                'default_max_tokens' => 3000,
            );
        }

        public static function get_default_generation_language()
        {
            return 'Português do Brasil';
        }

        public static function get_default_pexels_query()
        {
            return '{{pexels_tags}}';
        }

        public static function get_default_source_link_cta_phrases()
        {
            return implode("\n", array(
                'Assista na plataforma',
                'Veja no catálogo',
                'Confira a fonte',
                'Abra a referência',
                'Acesse o link',
            ));
        }

        public static function get_default_related_posts_phrases()
        {
            return implode("\n", array(
                'Você também pode gostar de:',
                'Leia também:',
                'Veja também:',
                'Confira também:',
            ));
        }

        public static function normalize_generation_language_value($value)
        {
            $value = trim((string) $value);
            if ($value === '' || $value === 'Português do Brasil' || $value === 'PortuguÃƒÆ’Ã‚Âªs do Brasil' || $value === 'PortuguÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Âªs do Brasil') {
                return self::get_default_generation_language();
            }
            return $value;
        }

        public static function get_generator_status_label($status)
        {
            $map = array(
                'active' => 'Ativo',
                'inactive' => 'Inativo',
            );
            return isset($map[$status]) ? $map[$status] : ucfirst((string) $status);
        }

        public static function get_schedule_type_label($type)
        {
            $map = array(
                'interval' => 'Intervalo + variação',
                'daily_random' => 'Janela diária aleatória',
            );
            return isset($map[$type]) ? $map[$type] : ucfirst((string) $type);
        }

        public static function get_run_status_label($status)
        {
            $map = array(
                'success' => 'Sucesso',
                'error' => 'Erro',
                'info' => 'Informação',
                'warning' => 'Aviso',
            );
            return isset($map[$status]) ? $map[$status] : ucfirst((string) $status);
        }

        public static function get_settings()
        {
            $settings = get_option(self::OPTION_KEY, array());
            if (!is_array($settings)) {
                $settings = array();
            }
            return array_merge(self::default_settings(), $settings);
        }

        public static function get_default_keyword_list_mode()
        {
            return 'keywords';
        }

        public static function keyword_list_mode_uses_source_url($keyword_list_mode)
        {
            return sanitize_key((string) $keyword_list_mode) === 'url_reference';
        }

        public static function generator_uses_keyword_list_url_reference_mode($generator)
        {
            if (empty($generator['source_type']) || sanitize_key((string) $generator['source_type']) !== 'keyword_list') {
                return false;
            }

            $keyword_list_mode = !empty($generator['keyword_list_mode']) ? sanitize_key((string) $generator['keyword_list_mode']) : self::get_default_keyword_list_mode();
            return self::keyword_list_mode_uses_source_url($keyword_list_mode);
        }

        public static function generator_uses_source_page_context($generator)
        {
            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            return $source_type === 'rss' || self::generator_uses_keyword_list_url_reference_mode($generator);
        }

        public static function get_generator_source_context_filters($generator)
        {
            $filters_json = !empty($generator['source_context_filters_json']) ? trim((string) $generator['source_context_filters_json']) : '';
            if ($filters_json === '') {
                return array();
            }

            $decoded = json_decode($filters_json, true);
            if (!is_array($decoded)) {
                return array();
            }

            return Alpha_RSS_AI_Generator_Helper::normalize_source_context_filters($decoded);
        }

        public static function get_default_image_source_mode($source_type = 'rss', $keyword_list_mode = 'keywords')
        {
            $source_type = sanitize_key((string) $source_type);
            $keyword_list_mode = sanitize_key((string) $keyword_list_mode);
            if ($source_type === 'keyword_list') {
                if ($keyword_list_mode === 'url_reference') {
                    return 'rss_or_pexels';
                }
                return 'pexels';
            }
            return 'rss_or_pexels';
        }

        public static function normalize_image_source_mode($source_type, $image_source_mode = '', $legacy_pexels_enabled = null, $keyword_list_mode = 'keywords')
        {
            $source_type = sanitize_key((string) $source_type);
            $image_source_mode = sanitize_key((string) $image_source_mode);
            $keyword_list_mode = sanitize_key((string) $keyword_list_mode);
            $allowed = array('rss', 'rss_or_pexels', 'rss_or_dalle', 'pexels', 'dalle');

            if ($image_source_mode !== '' && in_array($image_source_mode, $allowed, true)) {
                if ($source_type === 'keyword_list' && $keyword_list_mode !== 'url_reference') {
                    if ($image_source_mode === 'rss' || $image_source_mode === 'rss_or_pexels') {
                        return 'pexels';
                    }
                    if ($image_source_mode === 'rss_or_dalle') {
                        return 'dalle';
                    }
                }
                return $image_source_mode;
            }

            if ($legacy_pexels_enabled !== null) {
                if ($source_type === 'keyword_list' && $keyword_list_mode !== 'url_reference') {
                    return !empty($legacy_pexels_enabled) ? 'pexels' : 'dalle';
                }
                return !empty($legacy_pexels_enabled) ? 'rss_or_pexels' : 'rss';
            }

            return self::get_default_image_source_mode($source_type, $keyword_list_mode);
        }

        public static function image_source_mode_uses_source_image($image_source_mode)
        {
            return in_array(sanitize_key((string) $image_source_mode), array('rss', 'rss_or_pexels', 'rss_or_dalle'), true);
        }

        public static function image_source_mode_uses_pexels($image_source_mode)
        {
            return in_array(sanitize_key((string) $image_source_mode), array('rss_or_pexels', 'pexels'), true);
        }

        public static function image_source_mode_uses_dalle($image_source_mode)
        {
            return in_array(sanitize_key((string) $image_source_mode), array('rss_or_dalle', 'dalle'), true);
        }

        public static function prepare_generator_record($generator)
        {
            if (!is_array($generator)) {
                return $generator;
            }

            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            $keyword_list_mode = isset($generator['keyword_list_mode']) ? sanitize_key((string) $generator['keyword_list_mode']) : self::get_default_keyword_list_mode();
            if ($source_type !== 'keyword_list') {
                $keyword_list_mode = self::get_default_keyword_list_mode();
            }
            $legacy_pexels_enabled = isset($generator['pexels_enabled']) ? !empty($generator['pexels_enabled']) : null;
            $image_source_mode = isset($generator['image_source_mode']) ? (string) $generator['image_source_mode'] : '';
            $image_source_mode = self::normalize_image_source_mode($source_type, $image_source_mode, $legacy_pexels_enabled, $keyword_list_mode);
            $prompt_template = isset($generator['prompt_template']) ? trim((string) $generator['prompt_template']) : '';
            if ($prompt_template === '' || self::prompt_template_looks_like_rss_default($prompt_template) || self::prompt_template_looks_like_keyword_default($prompt_template)) {
                $prompt_template = self::normalize_prompt_template_for_source_type($source_type, '', $keyword_list_mode);
            }
            $content_prompt_template = isset($generator['content_prompt_template']) ? trim((string) $generator['content_prompt_template']) : '';
            if ($content_prompt_template === '' || self::content_prompt_template_looks_like_legacy_default($content_prompt_template)) {
                $content_prompt_template = self::get_default_content_prompt_template_visible();
            }

            $generator['keyword_list_mode'] = $keyword_list_mode;
            $generator['image_source_mode'] = $image_source_mode;
            $generator['image_selector_class'] = isset($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '';
            $generator['link_selector_class'] = isset($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '';
            $generator['content_image_size'] = isset($generator['content_image_size']) ? self::normalize_image_display_size((string) $generator['content_image_size']) : 'medium';
            $generator['source_link_phrases'] = isset($generator['source_link_phrases']) ? sanitize_textarea_field((string) $generator['source_link_phrases']) : '';
            $source_context_filters = array(
                'exclude_phrases' => array(),
                'rating_label' => 'IMDb',
                'min_rating' => 0,
                'keep_unrated' => 0,
            );
            if (!empty($generator['source_context_filters_json'])) {
                $decoded_filters = json_decode((string) $generator['source_context_filters_json'], true);
                if (is_array($decoded_filters)) {
                    $source_context_filters = Alpha_RSS_AI_Generator_Helper::normalize_source_context_filters($decoded_filters);
                }
            }
            $generator['source_context_filters_json'] = isset($generator['source_context_filters_json']) ? trim((string) $generator['source_context_filters_json']) : '';
            $generator['source_context_exclude_phrases'] = !empty($source_context_filters['exclude_phrases']) ? implode("\n", (array) $source_context_filters['exclude_phrases']) : '';
            $generator['source_context_rating_label'] = isset($source_context_filters['rating_label']) ? (string) $source_context_filters['rating_label'] : 'IMDb';
            $generator['source_context_min_rating'] = isset($source_context_filters['min_rating']) ? (string) $source_context_filters['min_rating'] : '0';
            $generator['source_context_keep_unrated'] = !empty($source_context_filters['keep_unrated']) ? 1 : 0;
            $generator['pexels_enabled'] = self::image_source_mode_uses_pexels($image_source_mode) ? 1 : 0;
            $generator['prompt_template'] = $prompt_template;
            $generator['content_prompt_template'] = $content_prompt_template;

            return $generator;
        }

        public static function sanitize_settings($raw)
        {
            $current = self::get_settings();
            $current['openai_api_key'] = isset($raw['openai_api_key']) ? sanitize_text_field(wp_unslash($raw['openai_api_key'])) : '';
            $current['pexels_api_key'] = isset($raw['pexels_api_key']) ? sanitize_text_field(wp_unslash($raw['pexels_api_key'])) : '';
            $current['default_model'] = isset($raw['default_model']) ? sanitize_text_field(wp_unslash($raw['default_model'])) : $current['default_model'];
            $current['default_temperature'] = isset($raw['default_temperature']) ? floatval($raw['default_temperature']) : $current['default_temperature'];
            $current['default_max_tokens'] = isset($raw['default_max_tokens']) ? max(256, intval($raw['default_max_tokens'])) : $current['default_max_tokens'];
            return $current;
        }



        public static function redirect_with_notice($message, $type = 'success', $extra = array())
        {
            $url = add_query_arg(array_merge(array(
                'page' => 'alpha-rss-ai-generator',
                'arc_notice' => $message,
                'arc_notice_type' => $type,
            ), $extra), admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        }

        public static function get_generators($limit = 200)
        {
            global $wpdb;
            $limit = max(1, intval($limit));
            $generators = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::$table_generators . " ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
            if (!is_array($generators)) {
                return array();
            }

            foreach ($generators as &$generator) {
                $generator = self::prepare_generator_record($generator);
            }
            unset($generator);

            return $generators;
        }

        public static function get_generator($id)
        {
            global $wpdb;
            $generator = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table_generators . " WHERE id = %d", intval($id)), ARRAY_A);
            return self::prepare_generator_record($generator);
        }

        public static function get_keyword_lists($limit = 200)
        {
            global $wpdb;
            $limit = max(1, intval($limit));
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::$table_lists . " ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
        }

        public static function get_keyword_list($id)
        {
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$table_lists . " WHERE id = %d", intval($id)), ARRAY_A);
        }

        public static function get_post_view_link($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return '';
            }

            $post_status = get_post_status($post_id);
            if ($post_status === 'publish') {
                $permalink = get_permalink($post_id);
                if (!empty($permalink)) {
                    return $permalink;
                }
            } else {
                $preview_link = get_preview_post_link($post_id);
                if (!empty($preview_link)) {
                    return $preview_link;
                }
            }

            $permalink = get_permalink($post_id);
            return !empty($permalink) ? $permalink : '';
        }

        public static function get_post_edit_link($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return '';
            }

            $edit_link = get_edit_post_link($post_id, 'raw');
            if (!empty($edit_link)) {
                if (strpos($edit_link, 'action=edit') === false) {
                    $edit_link = add_query_arg('action', 'edit', $edit_link);
                }
                return $edit_link;
            }

            return admin_url('post.php?post=' . $post_id . '&action=edit');
        }

        public static function bulk_tables()
        {
            return array(
                'lists' => self::$table_lists,
                'rows' => self::$table_list_rows,
            );
        }

        public static function bulk_normalize_key($value)
        {
            $value = remove_accents((string) $value);
            $value = strtolower(trim($value));
            $value = preg_replace('/[^a-z0-9]+/', '', $value);
            return $value;
        }

        public static function bulk_sanitize_cell($value)
        {
            if (is_null($value)) {
                return '';
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_array($value) || is_object($value)) {
                return '';
            }

            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
            return trim(wp_strip_all_tags($value));
        }

        public static function bulk_make_unique_header($header, $existing_headers)
        {
            $base = trim((string) $header);
            if ($base === '') {
                $base = 'Column';
            }

            $candidate = $base;
            $counter = 2;
            while (in_array($candidate, $existing_headers, true)) {
                $candidate = $base . ' ' . $counter;
                $counter++;
            }

            return $candidate;
        }

        public static function bulk_detect_column_map($headers)
        {
            $normalized_headers = array();
            foreach ($headers as $header) {
                $normalized_headers[self::bulk_normalize_key($header)] = $header;
            }

            $find = function ($candidates) use ($normalized_headers) {
                foreach ($candidates as $candidate) {
                    $key = self::bulk_normalize_key($candidate);
                    if (isset($normalized_headers[$key])) {
                        return $normalized_headers[$key];
                    }
                }
                return '';
            };

            $source_url_column = $find(array('url', 'sourceurl', 'source_url', 'link', 'pageurl', 'page_url'));

            return array(
                'keyword_column' => $find(array('keyword', 'keywords', 'frasechave', 'frase-chave', 'termo', 'termos')),
                'source_title_column' => $find(array('title', 'titulo', 'titulooriginal', 'headline')),
                'source_url_column' => $source_url_column,
                'slug_column' => $find(array('slug', 'finalslug', 'final_url', 'finalurl')) ?: $source_url_column,
                'content_column' => $find(array('content', 'conteudo', 'conteúdo', 'body', 'text')),
                'tags_column' => $find(array('tags', 'tag')),
            );
        }

        public static function bulk_detect_csv_delimiter($file_path)
        {
            $handle = fopen($file_path, 'r');
            if (!$handle) {
                return ',';
            }

            $line = fgets($handle);
            fclose($handle);

            if ($line === false) {
                return ',';
            }

            $delimiters = array(',', ';', "\t", '|');
            $best_delimiter = ',';
            $best_count = 0;

            foreach ($delimiters as $delimiter) {
                $count = substr_count($line, $delimiter);
                if ($count > $best_count) {
                    $best_count = $count;
                    $best_delimiter = $delimiter;
                }
            }

            return $best_delimiter;
        }



        public static function bulk_row_to_assoc($headers, $row_values)
        {
            $row_data = array();
            foreach ($headers as $index => $header) {
                $row_data[$header] = isset($row_values[$index]) ? self::bulk_sanitize_cell($row_values[$index]) : '';
            }

            return $row_data;
        }

        public static function bulk_blocked_slug_extensions()
        {
            return array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'zip', 'rar', '7z', 'tar', 'gz', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp3', 'mp4', 'avi', 'mov', 'mkv', 'wmv', 'xml', 'json', 'js', 'css', 'txt', 'odt', 'html', 'htm', 'php', 'asp', 'aspx', 'jsp', 'xhtml', 'shtml');
        }

        public static function bulk_extract_slug_from_candidate($candidate)
        {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                return array(
                    'slug' => '',
                    'extension' => '',
                    'valid' => false,
                    'source_url' => '',
                    'original' => '',
                );
            }

            $is_url = filter_var($candidate, FILTER_VALIDATE_URL);
            $source_url = $is_url ? $candidate : '';
            $path = $is_url ? (string) parse_url($candidate, PHP_URL_PATH) : $candidate;
            $path = trim((string) $path);
            $path = trim($path, '/');

            if ($path === '') {
                return array(
                    'slug' => '',
                    'extension' => '',
                    'valid' => false,
                    'source_url' => $source_url,
                    'original' => $candidate,
                );
            }

            $parts = array_values(array_filter(explode('/', $path)));
            $last_part = !empty($parts) ? end($parts) : $path;
            $extension = strtolower(pathinfo($last_part, PATHINFO_EXTENSION));
            if ($extension && in_array($extension, self::bulk_blocked_slug_extensions(), true)) {
                return array(
                    'slug' => '',
                    'extension' => $extension,
                    'valid' => false,
                    'source_url' => $source_url,
                    'original' => $candidate,
                );
            }

            $slug_source = $extension ? pathinfo($last_part, PATHINFO_FILENAME) : $last_part;
            $slug = sanitize_title(rawurldecode($slug_source));

            return array(
                'slug' => $slug,
                'extension' => $extension,
                'valid' => $slug !== '',
                'source_url' => $source_url,
                'original' => $candidate,
            );
        }

        public static function bulk_resolve_slug_info($candidate)
        {
            $slug_info = self::bulk_extract_slug_from_candidate($candidate);
            if (!empty($slug_info['valid'])) {
                return $slug_info;
            }

            return $slug_info;
        }

        public static function bulk_normalize_url_for_dedupe($candidate)
        {
            $candidate = trim((string) $candidate);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_URL)) {
                return '';
            }

            $parts = wp_parse_url($candidate);
            if (empty($parts['host'])) {
                return '';
            }

            $host = strtolower($parts['host']);
            $host = preg_replace('/^www\./i', '', $host);
            $port = isset($parts['port']) ? intval($parts['port']) : 0;
            if ($port > 0 && !in_array($port, array(80, 443), true)) {
                $host .= ':' . $port;
            }

            $path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '';
            $path = preg_replace('#/+#', '/', $path);
            $path = rtrim($path, '/');
            if ($path === '/') {
                $path = '';
            }

            return $host . $path;
        }

        public static function bulk_find_row_value($row_data, $column_name)
        {
            if (empty($column_name) || !is_array($row_data)) {
                return '';
            }

            $target_key = self::bulk_normalize_key($column_name);
            foreach ($row_data as $header => $value) {
                if (self::bulk_normalize_key($header) === $target_key) {
                    return self::bulk_sanitize_cell($value);
                }
            }

            return '';
        }

        public static function bulk_parse_timestamp_value($value)
        {
            $raw = trim((string) $value);
            if ($raw === '') {
                return '';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return $raw . ' 00:00:00';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $raw)) {
                return preg_replace('/\s+/', ' ', $raw) . ':00';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $raw)) {
                return preg_replace('/\s+/', ' ', $raw);
            }

            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

            try {
                $numeric = is_numeric($raw) ? (float) $raw : null;
                $digits_only = preg_match('/^\d+$/', $raw) ? $raw : '';

                if ($digits_only !== '' && strlen($digits_only) === 8) {
                    $date = DateTime::createFromFormat('Ymd', $digits_only, $timezone);
                    if ($date instanceof DateTimeInterface) {
                        return $date->format('Y-m-d H:i:s');
                    }
                }

                if ($digits_only !== '' && strlen($digits_only) === 14) {
                    $date = DateTime::createFromFormat('YmdHis', $digits_only, $timezone);
                    if ($date instanceof DateTimeInterface) {
                        return $date->format('Y-m-d H:i:s');
                    }
                }

                if ($numeric !== null && $numeric > 0 && $numeric < 1000000 && class_exists('\PhpOffice\PhpSpreadsheet\Shared\Date')) {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($numeric);
                    if ($date instanceof DateTimeInterface) {
                        return $date->format('Y-m-d H:i:s');
                    }
                }

                if ($digits_only !== '' && strlen($digits_only) >= 13 && $numeric !== null) {
                    $timestamp = intval(round($numeric / 1000));
                    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
                    return $date->format('Y-m-d H:i:s');
                }

                if ($digits_only !== '' && strlen($digits_only) >= 10 && $numeric !== null) {
                    $timestamp = intval(round($numeric));
                    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
                    return $date->format('Y-m-d H:i:s');
                }

                $timestamp = strtotime($raw);
                if ($timestamp !== false) {
                    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
                    return $date->format('Y-m-d H:i:s');
                }
            } catch (Exception $exception) {
                return '';
            }

            return '';
        }

        public static function bulk_extract_row_timestamp($row_data)
        {
            if (!is_array($row_data)) {
                return '';
            }

            $timestamp_columns = array(
                'Timestamp',
                'timestamp',
                'Data',
                'data',
                'Date',
                'date',
                'Published',
                'published',
                'Publication Date',
                'publication_date',
            );

            foreach ($timestamp_columns as $column_name) {
                $value = self::bulk_find_row_value($row_data, $column_name);
                if ($value === '') {
                    continue;
                }

                $normalized = self::bulk_parse_timestamp_value($value);
                if ($normalized !== '') {
                    return $normalized;
                }
            }

            return '';
        }



        public static function bulk_row_matches_filters($row_data, $filters)
        {
            if (empty($filters) || !is_array($filters)) {
                return true;
            }

            foreach ($filters as $filter) {
                if (!is_array($filter)) {
                    continue;
                }

                $column = isset($filter['column']) ? $filter['column'] : '';
                $operator = isset($filter['operator']) ? strtolower(trim($filter['operator'])) : 'contains';
                $expected = isset($filter['value']) ? (string) $filter['value'] : '';
                $value = self::bulk_find_row_value($row_data, $column);

                if ($column === '' && $operator !== 'empty' && $operator !== 'not_empty') {
                    return false;
                }

                $value_normalized = strtolower(trim((string) $value));
                $expected_normalized = strtolower(trim((string) $expected));

                switch ($operator) {
                    case 'equals':
                    case '=':
                    case 'eq':
                        if ($value_normalized !== $expected_normalized) {
                            return false;
                        }
                        break;
                    case 'not_equals':
                    case '!=':
                    case '<>':
                    case 'ne':
                        if ($value_normalized === $expected_normalized) {
                            return false;
                        }
                        break;
                    case 'greater':
                    case '>':
                    case 'gt':
                        if (floatval(str_replace(',', '.', $value)) <= floatval(str_replace(',', '.', $expected))) {
                            return false;
                        }
                        break;
                    case 'greater_or_equal':
                    case '>=':
                    case 'gte':
                        if (floatval(str_replace(',', '.', $value)) < floatval(str_replace(',', '.', $expected))) {
                            return false;
                        }
                        break;
                    case 'less':
                    case '<':
                    case 'lt':
                        if (floatval(str_replace(',', '.', $value)) >= floatval(str_replace(',', '.', $expected))) {
                            return false;
                        }
                        break;
                    case 'less_or_equal':
                    case '<=':
                    case 'lte':
                        if (floatval(str_replace(',', '.', $value)) > floatval(str_replace(',', '.', $expected))) {
                            return false;
                        }
                        break;
                    case 'empty':
                        if ($value_normalized !== '') {
                            return false;
                        }
                        break;
                    case 'not_empty':
                        if ($value_normalized === '') {
                            return false;
                        }
                        break;
                    case 'contains':
                    default:
                        if ($expected_normalized !== '' && mb_strpos($value_normalized, $expected_normalized) === false) {
                            return false;
                        }
                        break;
                }
            }

            return true;
        }

        public static function bulk_get_list_counts($list_id)
        {
            global $wpdb;
            $tables = self::bulk_tables();

            self::bulk_prune_keyword_list_rows($list_id);

            $counts = array(
                'total_rows' => 0,
                'generated_rows' => 0,
                'pending_rows' => 0,
                'invalid_rows' => 0,
                'failed_rows' => 0,
            );

            $counts['total_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d", $list_id)));
            $counts['generated_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'generated'", $list_id)));
            $counts['pending_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'pending'", $list_id)));
            $counts['invalid_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'invalid_slug'", $list_id)));
            $counts['failed_rows'] = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'failed'", $list_id)));

            return $counts;
        }

        public static function bulk_prune_keyword_list_rows($list_id)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id FROM {$tables['rows']} WHERE list_id = %d AND row_status IN ('invalid_slug', 'duplicate')",
                intval($list_id)
            ));

            if (empty($rows)) {
                return 0;
            }

            $removed = 0;
            foreach ($rows as $row) {
                $wpdb->delete($tables['rows'], array('id' => intval($row->id)), array('%d'));
                $removed++;
            }

            return $removed;
        }

        public static function bulk_refresh_list_counts($list_id)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $counts = self::bulk_get_list_counts($list_id);

            $wpdb->update(
                $tables['lists'],
                array(
                    'total_rows' => $counts['total_rows'],
                    'generated_rows' => $counts['generated_rows'],
                    'pending_rows' => $counts['pending_rows'],
                    'invalid_rows' => $counts['invalid_rows'],
                    'failed_rows' => $counts['failed_rows'],
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $list_id),
                array('%d', '%d', '%d', '%d', '%d', '%s'),
                array('%d')
            );

            return $counts;
        }

        public static function bulk_get_pending_rows_batch($list_id, $limit = 50, $offset = 0)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $limit = max(1, intval($limit));
            $offset = max(0, intval($offset));

            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'pending' ORDER BY row_number ASC LIMIT %d OFFSET %d",
                $list_id,
                $limit,
                $offset
            ));
        }

        public static function bulk_find_next_keyword_row($list_id, $filters = array(), $source_context_filters = array())
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $scan_offset = 0;
            $scan_limit = 250;
            $list = null;
            $use_source_context_filters = !empty($source_context_filters) && is_array($source_context_filters);
            if ($use_source_context_filters) {
                $list = self::get_keyword_list($list_id);
                if (!$list) {
                    return null;
                }
            }

            while (true) {
                $pending_rows = self::bulk_get_pending_rows_batch($list_id, $scan_limit, $scan_offset);
                if (empty($pending_rows)) {
                    return null;
                }

                foreach ($pending_rows as $row) {
                    $row_data = json_decode($row->row_data, true);
                    if (!is_array($row_data)) {
                        continue;
                    }

                    if (intval($row->slug_is_valid) !== 1) {
                        $wpdb->update(
                            $tables['rows'],
                            array(
                                'row_status' => 'invalid_slug',
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => intval($row->id)),
                            array('%s', '%s'),
                            array('%d')
                        );
                        continue;
                    }

                    if (!self::bulk_row_matches_filters($row_data, $filters)) {
                        continue;
                    }

                    if ($use_source_context_filters) {
                        $temp_item = self::build_keyword_list_item_from_row($list, $row, false, '', '', '', $source_context_filters);
                        if (!Alpha_RSS_AI_Generator_Helper::source_context_item_matches_filters($temp_item, $source_context_filters)) {
                            continue;
                        }
                    }

                    return $row;
                }

                $scan_offset += $scan_limit;
            }
        }

        public static function bulk_count_matching_keyword_rows($list_id, $filters = array(), $source_context_filters = array())
        {
            $scan_offset = 0;
            $scan_limit = 250;
            $available = 0;
            $list = null;
            $use_source_context_filters = !empty($source_context_filters) && is_array($source_context_filters);
            if ($use_source_context_filters) {
                $list = self::get_keyword_list($list_id);
                if (!$list) {
                    return 0;
                }
            }

            while (true) {
                $pending_rows = self::bulk_get_pending_rows_batch($list_id, $scan_limit, $scan_offset);
                if (empty($pending_rows)) {
                    break;
                }

                foreach ($pending_rows as $row) {
                    if (intval($row->slug_is_valid) !== 1) {
                        continue;
                    }

                    $row_data = json_decode($row->row_data, true);
                    if (!is_array($row_data)) {
                        continue;
                    }

                    if (!self::bulk_row_matches_filters($row_data, $filters)) {
                        continue;
                    }

                    if ($use_source_context_filters) {
                        $temp_item = self::build_keyword_list_item_from_row($list, $row, false, '', '', '', $source_context_filters);
                        if (!Alpha_RSS_AI_Generator_Helper::source_context_item_matches_filters($temp_item, $source_context_filters)) {
                            continue;
                        }
                    }

                    $available++;
                }

                $scan_offset += $scan_limit;
            }

            return $available;
        }

        public static function bulk_build_manual_generator($list, $settings = array())
        {
            $settings = is_array($settings) ? $settings : array();

            $category_ids = isset($settings['category_ids']) ? $settings['category_ids'] : array();
            if (!is_array($category_ids)) {
                $category_ids = self::parse_list_field($category_ids);
            }

            $tags_default = isset($settings['tags_default']) ? $settings['tags_default'] : array();
            if (!is_array($tags_default)) {
                $tags_default = self::parse_list_field($tags_default);
            }

            $custom_taxonomies = isset($settings['custom_taxonomies']) ? $settings['custom_taxonomies'] : '';
            if (is_array($custom_taxonomies)) {
                $custom_taxonomies = wp_json_encode($custom_taxonomies);
            } else {
                $custom_taxonomies = wp_json_encode(self::parse_key_value_lines($custom_taxonomies));
            }

            $custom_meta = isset($settings['custom_meta']) ? $settings['custom_meta'] : '';
            if (is_array($custom_meta)) {
                $custom_meta = wp_json_encode($custom_meta);
            } else {
                $custom_meta = wp_json_encode(self::parse_key_value_lines($custom_meta));
            }

            $model = isset($settings['model']) ? sanitize_text_field($settings['model']) : '';
            if ($model === '') {
                $model = self::get_settings()['default_model'];
            }

            $temperature = isset($settings['temperature']) ? floatval($settings['temperature']) : floatval(self::get_settings()['default_temperature']);
            $temperature = max(0.0, min(2.0, $temperature));

            $max_tokens = isset($settings['max_tokens']) ? max(256, intval($settings['max_tokens'])) : intval(self::get_settings()['default_max_tokens']);

            $post_status = isset($settings['post_status']) ? sanitize_key($settings['post_status']) : 'draft';
            if (!in_array($post_status, array('draft', 'publish', 'pending', 'private', 'future'), true)) {
                $post_status = 'draft';
            }

            $post_type = isset($settings['post_type']) ? sanitize_key($settings['post_type']) : 'post';
            if (!post_type_exists($post_type)) {
                $post_type = 'post';
            }

            $keyword_list_mode = !empty($settings['keyword_list_mode']) ? sanitize_key((string) $settings['keyword_list_mode']) : 'keywords';
            $image_source_mode = !empty($settings['image_source_mode'])
                ? sanitize_key((string) $settings['image_source_mode'])
                : self::get_default_image_source_mode('keyword_list', $keyword_list_mode);

            return array(
                'id' => 0,
                'name' => !empty($list['list_name']) ? $list['list_name'] : 'Lista manual',
                'feed_url' => '',
                'source_type' => 'keyword_list',
                'list_id' => intval($list['id']),
                'keyword_list_mode' => $keyword_list_mode,
                'status' => 'active',
                'post_type' => $post_type,
                'post_status' => $post_status,
                'author_id' => isset($settings['author_id']) ? intval($settings['author_id']) : 0,
                'category_ids' => wp_json_encode(array_values(array_filter(array_map('intval', $category_ids)))),
                'tags_default' => wp_json_encode(array_values(array_filter(array_map('sanitize_text_field', $tags_default)))),
                'custom_taxonomies' => $custom_taxonomies,
                'custom_meta' => $custom_meta,
                'filters_json' => '',
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => $max_tokens,
                'posts_per_run' => 1,
                'schedule_type' => 'interval',
                'interval_minutes' => 180,
                'jitter_minutes' => 30,
                'daily_start' => '08:00',
                'daily_end' => '22:00',
                'image_source_mode' => $image_source_mode,
                'pexels_enabled' => 1,
                'pexels_query' => !empty($settings['pexels_query']) ? sanitize_text_field($settings['pexels_query']) : self::get_default_pexels_query(),
                'source_video_enabled' => !empty($settings['source_video_enabled']) ? 1 : 0,
                'video_selector_class' => !empty($settings['video_selector_class']) ? sanitize_text_field($settings['video_selector_class']) : '',
                'image_selector_class' => !empty($settings['image_selector_class']) ? sanitize_text_field($settings['image_selector_class']) : '',
                'link_selector_class' => !empty($settings['link_selector_class']) ? sanitize_text_field($settings['link_selector_class']) : '',
                'content_image_size' => !empty($settings['content_image_size']) ? self::normalize_image_display_size($settings['content_image_size']) : 'medium',
                'source_context_filters_json' => wp_json_encode(array(
                    'exclude_phrases' => Alpha_RSS_AI_Generator_Helper::parse_source_context_filter_phrases(!empty($settings['source_context_exclude_phrases']) ? sanitize_textarea_field($settings['source_context_exclude_phrases']) : ''),
                    'rating_label' => !empty($settings['source_context_rating_label']) ? sanitize_text_field($settings['source_context_rating_label']) : 'IMDb',
                    'min_rating' => isset($settings['source_context_min_rating']) ? max(0, floatval(str_replace(',', '.', sanitize_text_field((string) $settings['source_context_min_rating'])))) : 0,
                    'keep_unrated' => !empty($settings['source_context_keep_unrated']) ? 1 : 0,
                )),
                'seo_enabled' => !empty($settings['seo_enabled']) ? 1 : 0,
                'generation_language' => !empty($settings['generation_language']) ? Alpha_RSS_AI_Generator::normalize_generation_language_value($settings['generation_language']) : Alpha_RSS_AI_Generator::get_default_generation_language(),
                'content_prompt_template' => '',
                'related_posts_enabled' => !empty($settings['related_posts_enabled']) ? 1 : 0,
                'related_posts_position' => !empty($settings['related_posts_position']) ? sanitize_key($settings['related_posts_position']) : 'end',
                'related_posts_interval' => !empty($settings['related_posts_interval']) ? max(1, intval($settings['related_posts_interval'])) : 4,
                'related_posts_min_h2' => !empty($settings['related_posts_min_h2']) ? max(0, intval($settings['related_posts_min_h2'])) : 1,
                'related_posts_links_per_block' => !empty($settings['related_posts_links_per_block']) ? max(1, intval($settings['related_posts_links_per_block'])) : 2,
                'related_posts_same_category_only' => !empty($settings['related_posts_same_category_only']) ? 1 : 0,
                'related_posts_allow_fallback' => !empty($settings['related_posts_allow_fallback']) ? 1 : 0,
                'related_posts_style' => !empty($settings['related_posts_style']) ? sanitize_key($settings['related_posts_style']) : 'list',
                'related_posts_phrases' => !empty($settings['related_posts_phrases']) ? sanitize_textarea_field($settings['related_posts_phrases']) : '',
                'source_link_phrases' => !empty($settings['source_link_phrases']) ? sanitize_textarea_field($settings['source_link_phrases']) : self::get_default_source_link_cta_phrases(),
                'prompt_template' => '',
                'content_prompt_template' => '',
            );
        }

        public static function bulk_rebuild_keyword_list_rows($list_id, $column_map)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d ORDER BY row_number ASC",
                intval($list_id)
            ));

            if (empty($rows)) {
                return array(
                    'updated' => 0,
                    'invalid' => 0,
                    'duplicate' => 0,
                );
            }

            $seen_canonical_urls = array();
            $seen_final_slugs = array();
            $updated = 0;
            $invalid = 0;
            $duplicate = 0;

            foreach ($rows as $row) {
                $current_status = isset($row->row_status) ? (string) $row->row_status : '';
                if (in_array($current_status, array('generated', 'failed', 'processing'), true)) {
                    continue;
                }

                $row_data = json_decode($row->row_data, true);
                if (!is_array($row_data)) {
                    $row_data = array();
                }

                $resolved = Alpha_RSS_AI_Generator_Helper::bulk_resolve_keyword_row($row_data, $column_map);
                $row_status = $resolved['row_status'];
                $error_message = $resolved['error_message'];
                $canonical_source_url = $resolved['canonical_source_url'];
                $slug_key = $resolved['slug_key'];

                if ($row_status === 'pending') {
                    if ($canonical_source_url !== '' && isset($seen_canonical_urls[$canonical_source_url])) {
                        $row_status = 'duplicate';
                        $error_message = 'Linha duplicada por URL';
                        $duplicate++;
                    } elseif ($slug_key !== '' && isset($seen_final_slugs[$slug_key])) {
                        $row_status = 'duplicate';
                        $error_message = 'Linha duplicada por slug';
                        $duplicate++;
                    }
                }

                if ($row_status === 'invalid_slug') {
                    $invalid++;
                }

                if ($row_status !== 'pending') {
                    $wpdb->delete($tables['rows'], array('id' => intval($row->id)), array('%d'));
                    continue;
                }

                if ($canonical_source_url !== '') {
                    $seen_canonical_urls[$canonical_source_url] = true;
                }
                if ($slug_key !== '') {
                    $seen_final_slugs[$slug_key] = true;
                }

                $wpdb->update(
                    $tables['rows'],
                    array(
                        'keyword' => $resolved['keyword'],
                        'source_title' => $resolved['source_title'],
                        'source_url' => $resolved['source_url'],
                        'final_slug' => $resolved['final_slug'],
                        'slug_extension' => $resolved['slug_extension'],
                        'slug_is_valid' => intval($resolved['slug_is_valid']),
                        'row_status' => $row_status,
                        'error_message' => $error_message,
                        'updated_at' => current_time('mysql'),
                    ),
                    array('id' => intval($row->id)),
                    array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'),
                    array('%d')
                );

                $updated++;
            }

            return array(
                'updated' => $updated,
                'invalid' => $invalid,
                'duplicate' => $duplicate,
            );
        }

        public static function parse_list_field($value)
        {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $value = trim((string) $value);
            if ($value === '') {
                return array();
            }
            $parts = preg_split('/[,\n\r;]+/', $value);
            $parts = array_filter(array_map('trim', $parts));
            return array_values(array_unique($parts));
        }

        public static function parse_key_value_lines($value)
        {
            $result = array();
            $lines = preg_split('/\r\n|\n|\r/', (string) $value);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '=') === false) {
                    continue;
                }
                list($key, $raw) = array_map('trim', explode('=', $line, 2));
                if ($key !== '') {
                    $result[$key] = $raw;
                }
            }
            return $result;
        }

        public static function normalize_generator_payload($raw)
        {
            $settings = self::get_settings();
            $payload = array();

            $payload['name'] = isset($raw['name']) ? sanitize_text_field(wp_unslash($raw['name'])) : '';
            $payload['source_type'] = isset($raw['source_type']) ? sanitize_key($raw['source_type']) : 'rss';
            $payload['feed_url'] = isset($raw['feed_url']) ? esc_url_raw(wp_unslash($raw['feed_url'])) : '';
            $payload['list_id'] = isset($raw['list_id']) ? intval($raw['list_id']) : 0;
            $payload['status'] = isset($raw['status']) ? sanitize_key($raw['status']) : 'active';
            $payload['post_type'] = isset($raw['post_type']) ? sanitize_key($raw['post_type']) : 'post';
            $payload['post_status'] = isset($raw['post_status']) ? sanitize_key($raw['post_status']) : 'draft';
            $payload['author_id'] = isset($raw['author_id']) ? intval($raw['author_id']) : 0;
            $payload['category_ids'] = wp_json_encode(self::parse_list_field(isset($raw['category_ids']) ? $raw['category_ids'] : ''));
            $payload['tags_default'] = wp_json_encode(self::parse_list_field(isset($raw['tags_default']) ? $raw['tags_default'] : ''));
            $payload['custom_taxonomies'] = wp_json_encode(self::parse_key_value_lines(isset($raw['custom_taxonomies']) ? wp_unslash($raw['custom_taxonomies']) : ''));
            $payload['custom_meta'] = wp_json_encode(self::parse_key_value_lines(isset($raw['custom_meta']) ? wp_unslash($raw['custom_meta']) : ''));
            $filters_raw = isset($raw['filters_json']) ? wp_unslash($raw['filters_json']) : '';
            if (is_array($filters_raw)) {
                $filters_raw = wp_json_encode($filters_raw);
            }
            $payload['filters_json'] = trim((string) $filters_raw);
            $payload['model'] = isset($raw['model']) ? sanitize_text_field(wp_unslash($raw['model'])) : $settings['default_model'];
            $payload['temperature'] = isset($raw['temperature']) ? floatval($raw['temperature']) : floatval($settings['default_temperature']);
            $payload['max_tokens'] = isset($raw['max_tokens']) ? max(256, intval($raw['max_tokens'])) : intval($settings['default_max_tokens']);
            $payload['posts_per_run'] = isset($raw['posts_per_run']) ? max(1, intval($raw['posts_per_run'])) : 1;
            $payload['schedule_type'] = isset($raw['schedule_type']) ? sanitize_key($raw['schedule_type']) : 'interval';
            $payload['interval_minutes'] = isset($raw['interval_minutes']) ? max(1, intval($raw['interval_minutes'])) : 180;
            $payload['jitter_minutes'] = isset($raw['jitter_minutes']) ? max(0, intval($raw['jitter_minutes'])) : 30;
            $payload['daily_start'] = isset($raw['daily_start']) ? sanitize_text_field(wp_unslash($raw['daily_start'])) : '08:00';
            $payload['daily_end'] = isset($raw['daily_end']) ? sanitize_text_field(wp_unslash($raw['daily_end'])) : '22:00';
            $payload['keyword_list_mode'] = isset($raw['keyword_list_mode']) ? sanitize_key(wp_unslash($raw['keyword_list_mode'])) : self::get_default_keyword_list_mode();
            if ($payload['source_type'] !== 'keyword_list') {
                $payload['keyword_list_mode'] = self::get_default_keyword_list_mode();
            }
            if (!in_array($payload['keyword_list_mode'], array('keywords', 'url_reference'), true)) {
                $payload['keyword_list_mode'] = self::get_default_keyword_list_mode();
            }
            $legacy_pexels_enabled = isset($raw['pexels_enabled']) ? !empty($raw['pexels_enabled']) : null;
            $payload['image_source_mode'] = isset($raw['image_source_mode']) ? sanitize_key(wp_unslash($raw['image_source_mode'])) : '';
            $payload['image_source_mode'] = self::normalize_image_source_mode($payload['source_type'], $payload['image_source_mode'], $legacy_pexels_enabled, $payload['keyword_list_mode']);
            $payload['pexels_enabled'] = self::image_source_mode_uses_pexels($payload['image_source_mode']) ? 1 : 0;
            $payload['pexels_query'] = isset($raw['pexels_query']) ? sanitize_text_field(wp_unslash($raw['pexels_query'])) : self::get_default_pexels_query();
            $payload['source_video_enabled'] = !empty($raw['source_video_enabled']) ? 1 : 0;
            $payload['video_selector_class'] = isset($raw['video_selector_class']) ? sanitize_text_field(wp_unslash($raw['video_selector_class'])) : '';
            $payload['image_selector_class'] = isset($raw['image_selector_class']) ? sanitize_text_field(wp_unslash($raw['image_selector_class'])) : '';
            $payload['link_selector_class'] = isset($raw['link_selector_class']) ? sanitize_text_field(wp_unslash($raw['link_selector_class'])) : '';
            $payload['content_image_size'] = isset($raw['content_image_size']) ? self::normalize_image_display_size(sanitize_key(wp_unslash($raw['content_image_size']))) : 'medium';
            $payload['seo_enabled'] = !empty($raw['seo_enabled']) ? 1 : 0;
            $payload['generation_language'] = isset($raw['generation_language']) ? self::normalize_generation_language_value(sanitize_text_field(wp_unslash($raw['generation_language']))) : self::get_default_generation_language();
            $payload['prompt_template'] = isset($raw['prompt_template']) ? wp_kses_post(wp_unslash($raw['prompt_template'])) : '';
            $payload['prompt_template'] = self::normalize_prompt_template_for_source_type($payload['source_type'], $payload['prompt_template'], $payload['keyword_list_mode']);
            $payload['content_prompt_template'] = isset($raw['content_prompt_template']) ? wp_kses_post(wp_unslash($raw['content_prompt_template'])) : '';
            if (trim($payload['content_prompt_template']) === '' || self::content_prompt_template_looks_like_legacy_default($payload['content_prompt_template'])) {
                $payload['content_prompt_template'] = self::get_default_content_prompt_template_visible();
            }
            $payload['related_posts_enabled'] = !empty($raw['related_posts_enabled']) ? 1 : 0;
            $payload['related_posts_position'] = isset($raw['related_posts_position']) ? sanitize_key(wp_unslash($raw['related_posts_position'])) : 'end';
            if (!in_array($payload['related_posts_position'], array('end', 'paragraphs', 'words'), true)) {
                $payload['related_posts_position'] = 'end';
            }
            $payload['related_posts_interval'] = isset($raw['related_posts_interval']) ? max(1, intval($raw['related_posts_interval'])) : 4;
            $payload['related_posts_min_h2'] = isset($raw['related_posts_min_h2']) ? max(0, intval($raw['related_posts_min_h2'])) : 1;
            $payload['related_posts_links_per_block'] = isset($raw['related_posts_links_per_block']) ? max(1, intval($raw['related_posts_links_per_block'])) : 2;
            $payload['related_posts_same_category_only'] = !empty($raw['related_posts_same_category_only']) ? 1 : 0;
            $payload['related_posts_allow_fallback'] = !empty($raw['related_posts_allow_fallback']) ? 1 : 0;
            $payload['related_posts_style'] = isset($raw['related_posts_style']) ? sanitize_key(wp_unslash($raw['related_posts_style'])) : 'list';
            if (!in_array($payload['related_posts_style'], array('inline', 'list', 'cards'), true)) {
                $payload['related_posts_style'] = 'list';
            }
            $payload['related_posts_phrases'] = isset($raw['related_posts_phrases']) ? sanitize_textarea_field(wp_unslash($raw['related_posts_phrases'])) : '';
            $payload['source_link_phrases'] = isset($raw['source_link_phrases']) ? sanitize_textarea_field(wp_unslash($raw['source_link_phrases'])) : '';
            $source_context_exclude_phrases = isset($raw['source_context_exclude_phrases']) ? sanitize_textarea_field(wp_unslash($raw['source_context_exclude_phrases'])) : '';
            $source_context_rating_label = isset($raw['source_context_rating_label']) ? sanitize_text_field(wp_unslash($raw['source_context_rating_label'])) : 'IMDb';
            $source_context_min_rating = isset($raw['source_context_min_rating']) ? floatval(str_replace(',', '.', sanitize_text_field(wp_unslash($raw['source_context_min_rating'])))) : 0;
            $source_context_keep_unrated = !empty($raw['source_context_keep_unrated']) ? 1 : 0;
            $payload['source_context_filters_json'] = wp_json_encode(array(
                'exclude_phrases' => Alpha_RSS_AI_Generator_Helper::parse_source_context_filter_phrases($source_context_exclude_phrases),
                'rating_label' => $source_context_rating_label !== '' ? $source_context_rating_label : 'IMDb',
                'min_rating' => max(0, $source_context_min_rating),
                'keep_unrated' => $source_context_keep_unrated ? 1 : 0,
            ));
            if ($payload['name'] === '') {
                return new WP_Error('arc_invalid_generator', 'Nome do gerador é obrigatório.');
            }
            if ($payload['source_type'] === 'keyword_list' && $payload['list_id'] <= 0) {
                return new WP_Error('arc_invalid_generator', 'Selecione uma lista de palavras-chave.');
            }
            if ($payload['source_type'] !== 'keyword_list' && $payload['feed_url'] === '') {
                return new WP_Error('arc_invalid_generator', 'URL do feed é obrigatória para geradores RSS.');
            }
            if ($payload['source_type'] === 'keyword_list' && trim($payload['prompt_template']) === '') {
                $payload['prompt_template'] = self::get_default_keyword_prompt_template();
            }
            if ($payload['source_type'] !== 'keyword_list' && trim($payload['prompt_template']) === '') {
                $payload['prompt_template'] = self::get_default_prompt_template();
            }
            if (!in_array($payload['schedule_type'], array('interval', 'daily_random'), true)) {
                $payload['schedule_type'] = 'interval';
            }
            if (!in_array($payload['status'], array('active', 'inactive'), true)) {
                $payload['status'] = 'active';
            }
            return $payload;
        }

        public static function insert_run_log($generator_id, $status, $message, $context = array(), $post_id = null, $item_guid = null, $item_permalink = null)
        {
            global $wpdb;
            $wpdb->insert(
                self::$table_runs,
                array(
                    'generator_id' => intval($generator_id),
                    'item_guid' => $item_guid,
                    'item_permalink' => $item_permalink,
                    'post_id' => $post_id ? intval($post_id) : null,
                    'status' => sanitize_key($status),
                    'message' => sanitize_text_field($message),
                    'request_json' => !empty($context['request']) ? wp_json_encode($context['request']) : null,
                    'response_json' => !empty($context['response']) ? wp_json_encode($context['response']) : null,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
            );
        }

        public static function insert_import_log($list_id, $row_number, $level, $code, $message, $row_data = array(), $context = array())
        {
            global $wpdb;
            $wpdb->insert(
                self::$table_import_logs,
                array(
                    'list_id' => intval($list_id),
                    'row_number' => max(0, intval($row_number)),
                    'level' => sanitize_key($level),
                    'code' => sanitize_key($code),
                    'message' => sanitize_text_field($message),
                    'row_data' => !empty($row_data) ? wp_json_encode($row_data) : null,
                    'context_json' => !empty($context) ? wp_json_encode($context) : null,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }

        public static function get_recent_import_logs($limit = 20, $list_id = 0)
        {
            global $wpdb;
            $limit = max(1, intval($limit));
            $list_id = max(0, intval($list_id));
            if ($list_id > 0) {
                return $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM " . self::$table_import_logs . " WHERE list_id = %d ORDER BY created_at DESC LIMIT %d",
                    $list_id,
                    $limit
                ), ARRAY_A);
            }

            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM " . self::$table_import_logs . " ORDER BY created_at DESC LIMIT %d",
                $limit
            ), ARRAY_A);
        }

        public static function get_default_prompt_template()
        {
            return "Voce e um editor SEO. Sua tarefa e gerar apenas os elementos editoriais do artigo em {{generation_language}}.\n"
                . "Retorne somente JSON valido com estas chaves: title, slug, excerpt, tags, pexels_tags, meta_description, focus_keyword.\n"
                . "Nao gere content_html nesta etapa. O corpo do artigo sera criado em uma segunda chamada interna.\n"
                . "Use a fonte apenas como base factual. Nao invente fatos, nao copie frases e nao use Markdown.\n"
                . "O titulo deve ser natural, fiel aos fatos e adequado ao idioma final.\n"
                . "A slug deve ser curta, limpa e coerente com o titulo.\n"
                . "A meta description deve ser objetiva e curta.\n"
                . "As tags devem ter no maximo 4 termos.\n"
                . "Pexels_tags deve ser um array com no maximo 4 termos visuais, concretos e especificos, sem palavras genericas.\n"
                . "Resumo da fonte: {{source_excerpt}}\n"
                . "Conteudo da fonte: {{source_content}}\n"
                . "Titulo do item: {{source_title}}\n"
                . "Link do item: {{source_permalink}}\n"
                . "Site: {{site_name}}\n"
                . "Gerador: {{generator_name}}\n"
                . "Idioma final: {{generation_language}}\n"
                . "Regras:\n"
                . "- Foque em title, slug, excerpt, tags, meta description e focus keyword.\n"
                . "- Nao escreva o corpo do artigo nesta resposta.\n"
                . "- Mantenha o tom factual e direto.\n"
                . "- Se a fonte for pobre, simplifique em vez de inventar.\n"
                . "- Se a pauta for entretenimento, mantenha nomes proprios, obras e entidades reais quando existirem no contexto.\n"
                . "- Se houver selecao de tags no gerador, use apenas esses termos nas tags finais; se nao houver selecao, retorne [] para tags.";
        }

        public static function get_default_keyword_prompt_template()
        {
            return "Voce e um editor de conteudo especializado em criar artigos originais a partir de planilhas e palavras-chave.\n"
                . "Escreva o texto final em {{generation_language}}.\n"
                . "Retorne somente JSON valido com estas chaves: title, slug, excerpt, tags, pexels_tags, meta_description, focus_keyword.\n"
                . "Nao gere content_html nesta etapa. O corpo do artigo sera criado em uma segunda chamada interna.\n"
                . "Use a keyword, a URL de origem e os dados da linha apenas como base factual.\n"
                . "O titulo deve ser natural, fiel aos fatos e adequado ao idioma final.\n"
                . "A slug final deve permanecer exatamente igual a {{final_slug}}.\n"
                . "A meta description deve ser curta e objetiva.\n"
                . "As tags devem ter no maximo 4 termos e, quando houver selecao no gerador, somente termos dessa lista.\n"
                . "Pexels_tags deve ser um array com no maximo 4 termos visuais, concretos e especificos, sem palavras genericas.\n"
                . "Se houver titulo na planilha, use-o como base principal; se nao houver, crie um titulo forte e natural a partir da keyword.\n"
                . "Resumo da fonte: {{source_excerpt}}\n"
                . "Conteudo da fonte: {{source_content}}\n"
                . "Keyword: {{keyword}}\n"
                . "Titulo da origem: {{source_title}}\n"
                . "URL de origem: {{source_url}}\n"
                . "Slug final: {{final_slug}}\n"
                . "Dados da linha: {{row_data}}\n"
                . "Site: {{site_name}}\n"
                . "Gerador: {{generator_name}}\n"
                . "Idioma final: {{generation_language}}\n"
                . "Regras:\n"
                . "- Preserve os fatos, mas reescreva do zero.\n"
                . "- Nao invente fatos fora da keyword, da URL e dos dados da linha.\n"
                . "- Nao use Markdown; use apenas JSON.\n"
                . "- Se a pauta for entretenimento, mantenha nomes proprios e entidades reais quando existirem no contexto.\n"
                . "- Se houver selecao de tags no gerador, use apenas esses termos nas tags finais; se nao houver selecao, retorne [] para tags.";
        }

        public static function prompt_template_looks_like_keyword_default($prompt_template)
        {
            $prompt_template = (string) $prompt_template;
            return strpos($prompt_template, 'Você é um editor de conteúdo especializado em criar artigos originais a partir de planilhas e palavras-chave.') !== false;
        }

        public static function get_default_content_prompt_template()
        {
            return "Voce e um redator editorial focado exclusivamente em escrever o corpo do artigo.\n"
                . "Escreva em {{generation_language}}.\n"
                . "Retorne apenas JSON valido com a chave content_html.\n"
                . "Nao gere title, slug, tags ou meta dados nesta etapa.\n"
                . "Use o titulo ja definido: {{generated_title}}.\n"
                . "Use o focus keyword ja definido: {{generated_focus_keyword}}.\n"
                . "Use a meta description ja definida: {{generated_meta_description}}.\n"
                . "Use a fonte apenas como base factual.\n"
                . "Conteudo da fonte: {{source_content}}\n"
                . "Resumo da fonte: {{source_excerpt}}\n"
                . "URL de origem: {{source_url}}\n"
                . "Titulo da origem: {{source_title}}\n"
                . "Keyword: {{keyword}}\n"
                . "Slug final: {{generated_slug}}\n"
                . "Palavras-chave selecionadas: {{selected_tags}}\n"
                . "Objetivo:\n"
                . "- Escrever um artigo completo, natural e fiel aos fatos.\n"
                . "- Priorize 1000 a 1800 palavras quando houver material suficiente.\n"
                . "- Use paragrafos curtos, 2 a 4 H2, e avance com fatos novos em cada bloco.\n"
                . "- Nao repita os mesmos argumentos e nao invente informacoes.\n"
                . "- Mantenha o foco no titulo ja definido e desenvolva o texto ao redor dele.\n"
                . "- Use HTML simples apenas no content_html.\n"
                . "- Se a fonte for pobre, encurte em vez de encher linguiça.\n"
                . "- Se a pauta for entretenimento, escreva de forma concreta, visual e direta.";
        }
        public static function get_default_content_prompt_template_visible()
        {
            return "Voce e um redator editorial focado exclusivamente em escrever o corpo do artigo.\n"
                . "Escreva um texto final natural, completo e fiel aos fatos.\n"
                . "Retorne apenas JSON valido com a chave content_html.\n"
                . "Nao gere title, slug, tags ou meta dados nesta etapa.\n"
                . "Use apenas HTML simples no content_html.\n"
                . "Objetivo:\n"
                . "- Escrever um artigo com cara de texto humano, sem soar mecanico.\n"
                . "- Priorize 1000 a 1800 palavras quando houver material suficiente.\n"
                . "- Use paragrafos curtos e 2 a 4 H2 para quebrar o texto.\n"
                . "- Avance com fatos novos em cada bloco e evite repeticao de ideias.\n"
                . "- Mantenha o foco no titulo ja definido e desenvolva o texto ao redor dele.\n"
                . "- Use o titulo e o contexto fornecidos pelo backend como base factual.\n"
                . "- Se a fonte for pobre, encurte em vez de encher linguica.\n"
                . "- Se a pauta for entretenimento, escreva de forma concreta, visual e direta.\n"
                . "- Nao use Markdown.\n"
                . "- Nao invente informacoes.";
        }

        public static function content_prompt_template_looks_like_legacy_default($prompt_template)
        {
            $prompt_template = (string) $prompt_template;
            return strpos($prompt_template, 'Voce e um redator editorial focado exclusivamente em escrever o corpo do artigo.') !== false
                && strpos($prompt_template, 'Use o titulo ja definido:') !== false
                && strpos($prompt_template, 'Use o focus keyword ja definido:') !== false
                && strpos($prompt_template, 'Retorne apenas JSON valido com a chave content_html.') !== false;
        }

        public static function prompt_template_looks_like_rss_default($prompt_template)
        {
            $prompt_template = (string) $prompt_template;
            return strpos($prompt_template, 'Você é um editor jornalístico especializado em reescrever conteúdo de RSS.') !== false
                || strpos($prompt_template, 'Você é um jornalista de portal focado em SEO e no estilo GEO') !== false
                || strpos($prompt_template, '[DIRETRIZES DE ESCRITA E ESTILO (GEO)]') !== false;
        }

        public static function normalize_prompt_template_for_source_type($source_type, $prompt_template, $keyword_list_mode = 'keywords')
        {
            $source_type = sanitize_key((string) $source_type);
            $keyword_list_mode = sanitize_key((string) $keyword_list_mode);
            $prompt_template = trim((string) $prompt_template);

            if ($prompt_template === '') {
                if ($source_type === 'keyword_list') {
                    return $keyword_list_mode === 'url_reference' ? self::get_default_prompt_template() : self::get_default_keyword_prompt_template();
                }
                return self::get_default_prompt_template();
            }

            return $prompt_template;
        }

        public static function get_generator_selected_tags($generator)
        {
            $tags = array();
            if (!empty($generator['tags_default'])) {
                $decoded = json_decode((string) $generator['tags_default'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $tag) {
                        $tag = sanitize_text_field($tag);
                        if ($tag !== '') {
                            $tags[] = $tag;
                        }
                    }
                }
            }

            $tags = array_values(array_unique($tags));
            return $tags;
        }

        public static function normalize_tag_key($tag)
        {
            $tag = sanitize_text_field((string) $tag);
            $tag = trim(preg_replace('/\s+/u', ' ', $tag));
            return mb_strtolower($tag, 'UTF-8');
        }

        public static function filter_tags_against_selected_pool($generated_tags, $selected_tags, $max = 6)
        {
            $generated_tags = is_array($generated_tags) ? $generated_tags : array();
            $selected_tags = is_array($selected_tags) ? $selected_tags : array();

            $generated_map = array();
            foreach ($generated_tags as $tag) {
                $tag = sanitize_text_field($tag);
                $key = self::normalize_tag_key($tag);
                if ($tag !== '' && $key !== '') {
                    $generated_map[$key] = $tag;
                }
            }

            $selected_map = array();
            foreach ($selected_tags as $tag) {
                $tag = sanitize_text_field($tag);
                $key = self::normalize_tag_key($tag);
                if ($tag !== '' && $key !== '') {
                    $selected_map[$key] = $tag;
                }
            }

            if (!empty($selected_map)) {
                $filtered = array();
                foreach ($generated_map as $key => $tag) {
                    if (isset($selected_map[$key])) {
                        $filtered[] = $selected_map[$key];
                    }
                }

                if (empty($filtered)) {
                    $filtered = array_slice(array_values($selected_map), 0, max(1, min(3, count($selected_map))));
                }

                return array_slice(array_values(array_unique($filtered)), 0, max(1, intval($max)));
            }

            return array_slice(array_values(array_unique(array_values($generated_map))), 0, max(1, intval($max)));
        }

        public static function filter_pexels_tags($tags, $max = 4)
        {
            $tags = is_array($tags) ? $tags : array();
            $filtered = array();
            $generic_terms = array(
                'action',
                'acao',
                'ação',
                'cinema',
                'film',
                'filme',
                'filmes',
                'movie',
                'movies',
                'trailer',
                'trailers',
                'serie',
                'series',
                'série',
                'tv',
                'televisao',
                'televisão',
                'luta',
                'fight',
                'fighting',
                'drama',
                'aventura',
                'adventure',
                'comedy',
                'comedia',
                'comédia',
                'terror',
                'horror',
                'suspense',
                'sports',
                'esporte',
                'esportes',
                'nostalgia',
                'anos 1990',
                'anos 2000',
                '1990',
                '1990s',
                '2000',
                '2000s',
                '80s',
                '90s',
                'people',
                'person',
                'personagem',
            );

            foreach ($tags as $tag) {
                $tag = sanitize_text_field($tag);
                $tag = trim(preg_replace('/\s+/u', ' ', $tag));
                if ($tag === '') {
                    continue;
                }

                $key = self::normalize_tag_key($tag);
                if ($key === '') {
                    continue;
                }

                if (preg_match('/^(?:19|20)\d{2}(?:s)?$/', $key)) {
                    continue;
                }
                if (preg_match('/\b(?:19|20)\d{2}\b/', $key)) {
                    continue;
                }
                if (in_array($key, $generic_terms, true)) {
                    continue;
                }

                $filtered[$key] = $tag;
            }

            return array_slice(array_values($filtered), 0, max(1, intval($max)));
        }

        public static function request_openai_json($generator, $prompt, $context = array())
        {
            $context = is_array($context) ? $context : array();
            $log_parts = array();
            $log_parts[] = 'stage=' . (isset($context['stage']) ? sanitize_key((string) $context['stage']) : 'unknown');
            $log_parts[] = 'source_type=' . (isset($context['source_type']) ? sanitize_key((string) $context['source_type']) : 'unknown');
            if (!empty($context['item_guid'])) {
                $log_parts[] = 'item_guid=' . (string) $context['item_guid'];
            }
            if (!empty($context['item_title'])) {
                $log_parts[] = 'item_title=' . sanitize_text_field((string) $context['item_title']);
            }
            if (isset($context['attempt'])) {
                $log_parts[] = 'attempt=' . intval($context['attempt']);
            }
            if (isset($context['minimum_words'])) {
                $log_parts[] = 'minimum_words=' . intval($context['minimum_words']);
            }
            if (isset($context['current_words'])) {
                $log_parts[] = 'current_words=' . intval($context['current_words']);
            }
            if (isset($context['excerpt_length'])) {
                $log_parts[] = 'excerpt_length=' . intval($context['excerpt_length']);
            }
            if (isset($context['content_length'])) {
                $log_parts[] = 'content_length=' . intval($context['content_length']);
            }
            if (!empty($context['source_context_enriched'])) {
                $log_parts[] = 'source_context_enriched=1';
            }
            $settings = self::get_settings();
            $api_key = trim((string) $settings['openai_api_key']);
            if ($api_key === '') {
                return new WP_Error('arc_missing_openai_key', 'A chave da API da OpenAI nao esta configurada.');
            }

            $model = trim((string) $generator['model']);
            if ($model === '') {
                $model = $settings['default_model'];
            }

            $temperature = max(0.0, min(2.0, floatval($generator['temperature'])));
            $max_tokens = max(256, intval($generator['max_tokens']));

            $body = array(
                'model' => $model,
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'Você é um editor jornalístico especializado. Retorne apenas JSON valido.'
                    ),
                    array(
                        'role' => 'user',
                        'content' => $prompt,
                    ),
                ),
                'temperature' => $temperature,
                'max_completion_tokens' => $max_tokens,
            );
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
                'timeout' => 240,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($body),
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);

            if ($code !== 200) {
                $message = isset($data['error']['message']) ? $data['error']['message'] : 'Erro desconhecido da OpenAI';
                return new WP_Error('arc_openai_error', $message);
            }

            $text = '';
            if (isset($data['choices'][0]['message']['content'])) {
                $text = trim((string) $data['choices'][0]['message']['content']);
            }
            return self::parse_ai_json($text, $context);
        }

        public static function parse_ai_json($text, $context = array())
        {
            $text = trim((string) $text);
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);

            $data = json_decode($text, true);
            if (is_array($data)) {
                return self::normalize_generated_article($data, $context);
            }

            return new WP_Error('arc_invalid_ai_json', 'A resposta da OpenAI nao veio em JSON valido.');
        }

        public static function normalize_generated_article($data, $context = array())
        {
            $allow_missing_content_html = !empty($context['allow_missing_content_html']);
            $article = array();
            $article['title'] = isset($data['title']) ? sanitize_text_field($data['title']) : (isset($data['titulo']) ? sanitize_text_field($data['titulo']) : '');
            $article['slug'] = isset($data['slug']) ? sanitize_title($data['slug']) : '';
            $article['excerpt'] = isset($data['excerpt']) ? sanitize_textarea_field($data['excerpt']) : (isset($data['resumo']) ? sanitize_textarea_field($data['resumo']) : '');
            $article['content_html'] = isset($data['content_html']) ? wp_kses_post($data['content_html']) : (isset($data['conteudo_html']) ? wp_kses_post($data['conteudo_html']) : '');
            $article['meta_description'] = isset($data['meta_description']) ? sanitize_text_field($data['meta_description']) : (isset($data['meta_descricao']) ? sanitize_text_field($data['meta_descricao']) : '');
            $article['focus_keyword'] = isset($data['focus_keyword']) ? sanitize_text_field($data['focus_keyword']) : (isset($data['palavra_chave_foco']) ? sanitize_text_field($data['palavra_chave_foco']) : '');
            $article['tags'] = array();
            $article['pexels_tags'] = array();

            if (isset($data['tags'])) {
                $tags_source = is_array($data['tags']) ? $data['tags'] : self::parse_list_field($data['tags']);
                foreach ($tags_source as $tag) {
                    $tag = sanitize_text_field($tag);
                    if ($tag !== '') {
                        $article['tags'][] = $tag;
                    }
                }
            }

            if (isset($data['pexels_tags'])) {
                $pexels_tags_source = is_array($data['pexels_tags']) ? $data['pexels_tags'] : self::parse_list_field($data['pexels_tags']);
                foreach ($pexels_tags_source as $tag) {
                    $tag = sanitize_text_field($tag);
                    if ($tag !== '') {
                        $article['pexels_tags'][] = $tag;
                    }
                }
            } elseif (isset($data['image_tags'])) {
                $image_tags_source = is_array($data['image_tags']) ? $data['image_tags'] : self::parse_list_field($data['image_tags']);
                foreach ($image_tags_source as $tag) {
                    $tag = sanitize_text_field($tag);
                    if ($tag !== '') {
                        $article['pexels_tags'][] = $tag;
                    }
                }
            }

            if ($article['title'] === '') {
                $article['title'] = 'Artigo sem titulo';
            }
            if ($article['slug'] === '') {
                $article['slug'] = sanitize_title($article['title']);
            }
            if (!$allow_missing_content_html && $article['excerpt'] === '') {
                $article['excerpt'] = wp_trim_words(wp_strip_all_tags($article['content_html']), 24);
            }
            if (!$allow_missing_content_html && $article['content_html'] === '') {
                $article['content_html'] = '<p>' . esc_html($article['excerpt']) . '</p>';
            }
            if (!empty($article['pexels_tags'])) {
                $article['pexels_tags'] = self::filter_pexels_tags($article['pexels_tags'], 4);
            }

            $source_title = !empty($data['source_title']) ? (string) $data['source_title'] : '';
            $article['title'] = Alpha_RSS_AI_Generator_Helper::normalize_generated_title($article['title'], $source_title);

            return $article;
        }



        public static function create_gutenberg_block($block_name, $inner_html, $attrs = array())
        {
            return array(
                'blockName' => trim((string) $block_name),
                'attrs' => is_array($attrs) ? $attrs : array(),
                'innerBlocks' => array(),
                'innerContent' => array((string) $inner_html),
            );
        }

        public static function normalize_embed_target_url($video_url)
        {
            $video_url = esc_url_raw(trim((string) $video_url));
            if ($video_url === '') {
                return '';
            }

            $parts = wp_parse_url($video_url);
            if (!is_array($parts) || empty($parts['host'])) {
                return $video_url;
            }

            $host = strtolower((string) $parts['host']);
            $path = isset($parts['path']) ? (string) $parts['path'] : '';
            $query = isset($parts['query']) ? (string) $parts['query'] : '';
            parse_str($query, $query_args);

            if (strpos($host, 'youtu.be') !== false) {
                $video_id = trim(ltrim($path, '/'));
                if ($video_id !== '') {
                    return 'https://www.youtube.com/watch?v=' . rawurlencode($video_id);
                }
            }

            if (strpos($host, 'youtube.com') !== false) {
                if (preg_match('~^/embed/([^/?#&]+)~i', $path, $matches)) {
                    return 'https://www.youtube.com/watch?v=' . rawurlencode($matches[1]);
                }
                if (!empty($query_args['v'])) {
                    return 'https://www.youtube.com/watch?v=' . rawurlencode((string) $query_args['v']);
                }
                if (preg_match('~[?&]v=([^&#]+)~i', $video_url, $matches)) {
                    return 'https://www.youtube.com/watch?v=' . rawurlencode($matches[1]);
                }
            }

            if (strpos($host, 'player.vimeo.com') !== false) {
                if (preg_match('~^/video/([0-9]+)~', $path, $matches)) {
                    return 'https://vimeo.com/' . rawurlencode($matches[1]);
                }
            }

            if (strpos($host, 'vimeo.com') !== false) {
                if (preg_match('~^/([0-9]+)~', $path, $matches)) {
                    return 'https://vimeo.com/' . rawurlencode($matches[1]);
                }
            }

            if (strpos($host, 'dailymotion.com') !== false) {
                if (preg_match('~^/embed/video/([^/?#&]+)~i', $path, $matches)) {
                    return 'https://www.dailymotion.com/video/' . rawurlencode($matches[1]);
                }
                if (preg_match('~^/video/([^/?#&]+)~i', $path, $matches)) {
                    return 'https://www.dailymotion.com/video/' . rawurlencode($matches[1]);
                }
            }

            if (strpos($host, 'tiktok.com') !== false) {
                return $video_url;
            }

            if (strpos($host, 'streamable.com') !== false) {
                return $video_url;
            }

            return $video_url;
        }

        public static function build_manual_video_figure_html($embed_html, $video_url = '')
        {
            $embed_html = trim((string) $embed_html);
            $video_url = esc_url_raw(trim((string) $video_url));

            if ($embed_html === '' && $video_url === '') {
                return '';
            }

            $provider_slug = $video_url !== '' ? self::detect_video_provider_slug($video_url) : 'video';
            $class_names = array(
                'wp-block-embed',
                'is-type-video',
                'is-provider-' . $provider_slug,
                'wp-block-embed-' . $provider_slug,
                'wp-embed-aspect-16-9',
                'wp-has-aspect-ratio',
            );

            $figure  = '<figure class="' . esc_attr(implode(' ', array_unique($class_names))) . '">';
            $figure .= '<div class="wp-block-embed__wrapper">' . "\n";
            $figure .= esc_html(self::normalize_embed_target_url($video_url)) . "\n";
            $figure .= '</div>';
            $figure .= '</figure>';

            return $figure;
        }

        public static function dom_node_outer_html($node)
        {
            if (!is_object($node) || empty($node->ownerDocument)) {
                return '';
            }

            $html = $node->ownerDocument->saveHTML($node);
            return is_string($html) ? trim($html) : '';
        }

        public static function append_gutenberg_blocks_from_dom_node($node, array &$blocks)
        {
            if (!is_object($node)) {
                return;
            }

            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $text = trim(preg_replace('/\s+/', ' ', html_entity_decode((string) $node->nodeValue, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'))));
                if ($text !== '') {
                    $blocks[] = self::create_gutenberg_block('core/paragraph', '<p>' . esc_html($text) . '</p>');
                }
                return;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
                return;
            }

            $tag = strtolower((string) $node->nodeName);
            $outer_html = self::dom_node_outer_html($node);
            if ($outer_html === '') {
                return;
            }

            if (preg_match('/^h([1-6])$/', $tag, $matches)) {
                $blocks[] = self::create_gutenberg_block('core/heading', $outer_html, array('level' => intval($matches[1])));
                return;
            }

            switch ($tag) {
                case 'p':
                    $blocks[] = self::create_gutenberg_block('core/paragraph', $outer_html);
                    return;

                case 'ul':
                case 'ol':
                    $blocks[] = self::create_gutenberg_block('core/list', $outer_html);
                    return;

                case 'blockquote':
                    $blocks[] = self::create_gutenberg_block('core/quote', $outer_html);
                    return;

                case 'pre':
                    $blocks[] = self::create_gutenberg_block('core/code', $outer_html);
                    return;

                case 'hr':
                    $blocks[] = self::create_gutenberg_block('core/separator', '<hr />');
                    return;

                case 'figure':
                case 'iframe':
                    $video_url = self::extract_video_url_from_embed_html($outer_html, '');
                    if ($video_url !== '') {
                        $blocks[] = self::build_gutenberg_embed_block_from_html($outer_html, $video_url);
                        return;
                    }
                    $blocks[] = self::create_gutenberg_block('core/html', $outer_html);
                    return;

                case 'img':
                    $blocks[] = self::create_gutenberg_block('core/html', $outer_html);
                    return;

                case 'script':
                case 'style':
                    return;
            }

            $child_blocks_before = count($blocks);
            foreach ($node->childNodes as $child) {
                self::append_gutenberg_blocks_from_dom_node($child, $blocks);
            }

            if (count($blocks) === $child_blocks_before) {
                $text = trim(wp_strip_all_tags($outer_html));
                if ($text !== '') {
                    $blocks[] = self::create_gutenberg_block('core/paragraph', '<p>' . esc_html($text) . '</p>');
                } else {
                    $blocks[] = self::create_gutenberg_block('core/html', $outer_html);
                }
            }
        }

        public static function build_gutenberg_embed_block_from_html($embed_html, $video_url = '')
        {
            $embed_html = trim((string) $embed_html);
            $video_url = esc_url_raw(trim((string) $video_url));
            if ($embed_html === '' && $video_url === '') {
                return array();
            }

            $manual_html = self::build_manual_video_figure_html($embed_html, $video_url);
            $block_html = $manual_html !== '' ? $manual_html : $embed_html;

            return self::create_gutenberg_block('core/embed', $block_html, array(
                'url' => self::normalize_embed_target_url($video_url),
                'type' => 'video',
                'providerNameSlug' => self::detect_video_provider_slug($video_url),
                'responsive' => true,
            ));
        }

        public static function convert_html_fragment_to_gutenberg_blocks($html, $prepend_embed_html = '', $prepend_embed_url = '')
        {
            $html = trim((string) $html);
            $blocks = array();

            if ($prepend_embed_html !== '' || $prepend_embed_url !== '') {
                $embed_block = self::build_gutenberg_embed_block_from_html($prepend_embed_html, $prepend_embed_url);
                if (!empty($embed_block)) {
                    $blocks[] = $embed_block;
                }
            }

            if ($html === '') {
                return !empty($blocks) ? serialize_blocks($blocks) : '';
            }

            if (strpos($html, '<!-- wp:') !== false) {
                return !empty($blocks) ? serialize_blocks($blocks) . "\n\n" . $html : $html;
            }

            $previous_libxml_state = libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="arc-gutenberg-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previous_libxml_state);

            if ($loaded) {
                $root = $dom->getElementById('arc-gutenberg-root');
                if (!$root && $dom->getElementsByTagName('div')->length > 0) {
                    $root = $dom->getElementsByTagName('div')->item(0);
                }
                if ($root) {
                    foreach ($root->childNodes as $child) {
                        self::append_gutenberg_blocks_from_dom_node($child, $blocks);
                    }
                }
            }

            if (empty($blocks)) {
                $plain_text = trim(wp_strip_all_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'))));
                if ($plain_text !== '') {
                    $blocks[] = self::create_gutenberg_block('core/paragraph', '<p>' . esc_html($plain_text) . '</p>');
                } else {
                    $blocks[] = self::create_gutenberg_block('core/html', $html);
                }
            }

            return !empty($blocks) ? serialize_blocks($blocks) : '';
        }

        public static function clean_source_text($text)
        {
            $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            $text = wp_strip_all_tags($text);
            $text = preg_replace('/\s+/', ' ', $text);
            return trim($text);
        }

        public static function extract_page_title_from_html($html)
        {
            $html = (string) $html;
            if ($html === '') {
                return '';
            }

            if (preg_match('/<meta[^>]+(?:property|name|itemprop)=["\'](?:og:title|twitter:title)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                return self::clean_source_text(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')));
            }

            if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $matches)) {
                return self::clean_source_text($matches[1]);
            }

            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                return self::clean_source_text($matches[1]);
            }

            return '';
        }

        public static function extract_page_content_from_html($html)
        {
            $html = (string) $html;
            if ($html === '') {
                return '';
            }

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            if (!$loaded) {
                return '';
            }

            $xpath = new DOMXPath($dom);
            $selectors = array(
                '//article',
                '//*[@role="main"]',
                '//main',
                '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " content ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]',
                '//*[contains(concat(" ", normalize-space(@class), " "), " story-body ")]',
                '//*[@id="content"]',
                '//*[@id="main"]',
            );

            $best = '';
            foreach ($selectors as $selector) {
                $nodes = $xpath->query($selector);
                if (!$nodes || $nodes->length === 0) {
                    continue;
                }

                $candidate = '';
                for ($i = 0; $i < min(2, $nodes->length); $i++) {
                    $node = $nodes->item($i);
                    if ($node) {
                        $candidate .= ' ' . $node->textContent;
                    }
                }

                $candidate = self::clean_source_text($candidate);
                if (strlen($candidate) > strlen($best)) {
                    $best = $candidate;
                }
            }

            if ($best === '') {
                $paragraphs = $xpath->query('//p');
                if ($paragraphs && $paragraphs->length > 0) {
                    $parts = array();
                    for ($i = 0; $i < min(30, $paragraphs->length); $i++) {
                        $paragraph = $paragraphs->item($i);
                        if (!$paragraph) {
                            continue;
                        }
                        $text = self::clean_source_text($paragraph->textContent);
                        if ($text !== '') {
                            $parts[] = $text;
                        }
                    }
                    $best = trim(implode("\n\n", $parts));
                }
            }

            if ($best === '') {
                return '';
            }

            if (strlen($best) > 8000) {
                $best = substr($best, 0, 8000);
            }

            return $best;
        }

        public static function extract_page_context($url, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $source_context_filters = array())
        {
            $url = esc_url_raw(trim((string) $url));
            if ($url === '') {
                return array(
                    'title' => '',
                    'content' => '',
                    'excerpt' => '',
                    'outline' => array(),
                    'outline_sections' => array(),
                    'outline_text' => '',
                );
            }

            $html = Alpha_RSS_AI_Generator_Helper::fetch_source_page_html($url, 5, 'page_context');
            if ($html === '') {
                return array(
                    'title' => '',
                    'content' => '',
                    'excerpt' => '',
                    'outline' => array(),
                    'outline_sections' => array(),
                    'outline_text' => '',
                );
            }
            $title = self::extract_page_title_from_html($html);
            $content = self::extract_page_content_from_html($html);
            $excerpt = $content !== '' ? wp_trim_words($content, 24) : '';
            $outline = Alpha_RSS_AI_Generator_Helper::extract_page_outline_from_html($html, $url, 8, 10, 5, $image_selector_class, $link_selector_class);
            $page_context = array(
                'title' => $title,
                'content' => $content,
                'excerpt' => $excerpt,
                'outline' => $outline,
                'outline_sections' => $outline,
                'outline_text' => Alpha_RSS_AI_Generator_Helper::format_page_outline_for_prompt($outline),
            );
            $page_context = Alpha_RSS_AI_Generator_Helper::apply_source_context_filters_to_page_context($page_context, $source_context_filters);
            $outline = !empty($page_context['outline']) && is_array($page_context['outline']) ? $page_context['outline'] : array();
            $outline_text = !empty($page_context['outline_text']) ? (string) $page_context['outline_text'] : '';

            return $page_context;
        }

        public static function rss_item_needs_page_context($item)
        {
            $title = isset($item['title']) ? trim((string) $item['title']) : '';
            $excerpt = isset($item['excerpt']) ? trim((string) $item['excerpt']) : '';
            $content = isset($item['content']) ? trim((string) $item['content']) : '';
            $combined = trim(preg_replace('/\s+/', ' ', $title . ' ' . $excerpt . ' ' . $content));

            if ($combined === '') {
                return true;
            }

            $excerpt_length = strlen($excerpt);
            $content_length = strlen($content);
            $combined_length = strlen($combined);

            if ($content_length < 220 && $excerpt_length < 180) {
                return true;
            }

            if ($content !== '' && $excerpt !== '' && $content === $excerpt) {
                return true;
            }

            if ($combined_length < 420) {
                return true;
            }

            return false;
        }

        public static function merge_sparse_source_text($base, $addition)
        {
            $base = trim((string) $base);
            $addition = trim((string) $addition);

            if ($base === '') {
                return $addition;
            }
            if ($addition === '') {
                return $base;
            }
            if (stripos($addition, $base) !== false) {
                return $addition;
            }
            if (stripos($base, $addition) !== false) {
                return $base;
            }

            return trim($base . "\n\n" . $addition);
        }

        public static function maybe_enrich_rss_item_context($generator, $item)
        {
            $should_use_source_page_context = self::generator_uses_source_page_context($generator);
            if (!$should_use_source_page_context) {
                return $item;
            }

            $permalink = !empty($item['permalink']) ? trim((string) $item['permalink']) : '';
            if ($permalink === '') {
                return $item;
            }

            $video_selector_class = !empty($generator['video_selector_class']) ? $generator['video_selector_class'] : '';
            $image_selector_class = !empty($generator['image_selector_class']) ? $generator['image_selector_class'] : '';
            $link_selector_class = !empty($generator['link_selector_class']) ? $generator['link_selector_class'] : '';
            $page_context = self::extract_page_context($permalink, $video_selector_class, $image_selector_class, $link_selector_class, self::get_generator_source_context_filters($generator));
            if (empty($page_context) || (!empty($page_context['title']) === false && !empty($page_context['content']) === false && !empty($page_context['excerpt']) === false && !empty($page_context['outline_text']) === false)) {
                return $item;
            }

            $original_excerpt = isset($item['excerpt']) ? (string) $item['excerpt'] : '';
            $original_content = isset($item['content']) ? (string) $item['content'] : '';
            $page_title = !empty($page_context['title']) ? trim((string) $page_context['title']) : '';
            $page_excerpt = !empty($page_context['excerpt']) ? trim((string) $page_context['excerpt']) : '';
            $page_content = !empty($page_context['content']) ? trim((string) $page_context['content']) : '';
            $page_outline_text = !empty($page_context['outline_text']) ? trim((string) $page_context['outline_text']) : '';

            if ($page_title !== '' && (empty($item['title']) || strlen(trim((string) $item['title'])) < 45)) {
                $item['title'] = $page_title;
            }

            if ($page_excerpt !== '') {
                $item['excerpt'] = self::merge_sparse_source_text($original_excerpt, $page_excerpt);
            }

            if ($page_content !== '') {
                $item['content'] = self::merge_sparse_source_text($original_content, $page_content);
            }

            $item['source_page_title'] = $page_title;
            $item['source_page_excerpt'] = $page_excerpt;
            $item['source_page_content'] = $page_content;
            $item['source_page_outline'] = $page_outline_text;
            if (!empty($page_context['outline']) && is_array($page_context['outline'])) {
                $item['source_page_outline_sections'] = $page_context['outline'];
            }
            $item['source_context_enriched'] = 1;

            return $item;
        }

        public static function url_looks_like_image($url)
        {
            return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif|bmp)(?:$|[?#])/i', (string) $url);
        }

        public static function url_looks_like_video($url)
        {
            return (bool) preg_match('/\.(mp4|m4v|mov|webm|ogv|m3u8)(?:$|[?#])/i', (string) $url);
        }

        public static function is_probably_bad_featured_image_url($url, $context = '')
        {
            $url = trim((string) $url);
            if ($url === '') {
                return true;
            }

            if (preg_match('/\.svg(?:$|[?#])/i', $url)) {
                return true;
            }

            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $basename = strtolower((string) pathinfo($path, PATHINFO_FILENAME));
            $haystack = strtolower($url . ' ' . (string) $context . ' ' . $basename);
            $bad_terms = array(
                'logo',
                'site-logo',
                'brand',
                'icon',
                'avatar',
                'sprite',
                'placeholder',
                'default',
                'favicon',
                'wordmark',
                'masthead',
                'header-logo',
                'footer-logo',
                'watermark',
                'badge',
            );

            foreach ($bad_terms as $bad_term) {
                if ($bad_term !== '' && strpos($haystack, $bad_term) !== false) {
                    return true;
                }
            }

            return false;
        }

        public static function is_video_embed_url($url)
        {
            $url = (string) $url;
            if (self::url_looks_like_video($url)) {
                return true;
            }
            return (bool) preg_match('~(youtube\.com|youtu\.be|vimeo\.com|dailymotion\.com|tiktok\.com|streamable\.com)~i', $url);
        }

        public static function resolve_url_against_base($url, $base_url = '')
        {
            $url = trim((string) $url);
            $base_url = trim((string) $base_url);
            if ($url === '') {
                return '';
            }
            if (preg_match('~^https?://~i', $url)) {
                return esc_url_raw($url);
            }
            if (strpos($url, '//') === 0) {
                $scheme = wp_parse_url($base_url, PHP_URL_SCHEME);
                if (!$scheme) {
                    $scheme = 'https';
                }
                return esc_url_raw($scheme . ':' . $url);
            }
            if ($base_url === '') {
                return esc_url_raw($url);
            }

            $parts = wp_parse_url($base_url);
            if (empty($parts['host'])) {
                return esc_url_raw($url);
            }

            $scheme = !empty($parts['scheme']) ? $parts['scheme'] : 'https';
            $host = $parts['host'];
            $port = !empty($parts['port']) ? ':' . $parts['port'] : '';

            if (substr($url, 0, 1) === '/') {
                return esc_url_raw($scheme . '://' . $host . $port . $url);
            }

            $path = !empty($parts['path']) ? $parts['path'] : '/';
            $directory = preg_replace('~/[^/]*$~', '/', $path);

            return esc_url_raw($scheme . '://' . $host . $port . $directory . $url);
        }



        public static function extract_media_from_enclosures($item, $allow_video = true)
        {
            $media = array(
                'image_url' => '',
                'video_url' => '',
                'video_embed_html' => '',
                'video_source' => '',
            );

            $enclosures = array();
            if (is_object($item)) {
                if (method_exists($item, 'get_enclosures')) {
                    $enclosures = (array) $item->get_enclosures();
                } elseif (method_exists($item, 'get_enclosure')) {
                    $enclosure = $item->get_enclosure();
                    if ($enclosure) {
                        $enclosures = array($enclosure);
                    }
                }
            } elseif (is_array($item)) {
                if (!empty($item['enclosures']) && is_array($item['enclosures'])) {
                    $enclosures = $item['enclosures'];
                } elseif (!empty($item['enclosure'])) {
                    $enclosure = $item['enclosure'];
                    $enclosures = is_array($enclosure) ? $enclosure : array($enclosure);
                }
            }

            foreach ($enclosures as $enclosure) {
                if (!is_object($enclosure)) {
                    continue;
                }

                $type = '';
                if (method_exists($enclosure, 'get_type')) {
                    $type = strtolower(trim((string) $enclosure->get_type()));
                }
                if ($type === '' && method_exists($enclosure, 'get_real_type')) {
                    $type = strtolower(trim((string) $enclosure->get_real_type()));
                }

                $link = '';
                if (method_exists($enclosure, 'get_link')) {
                    $link = esc_url_raw(trim((string) $enclosure->get_link()));
                }

                $thumbnail = '';
                if (method_exists($enclosure, 'get_thumbnails')) {
                    $thumbnails = (array) $enclosure->get_thumbnails();
                    if (!empty($thumbnails)) {
                        $thumbnail = esc_url_raw(trim((string) reset($thumbnails)));
                    }
                }
                if ($thumbnail === '' && method_exists($enclosure, 'get_thumbnail')) {
                    $thumbnail = esc_url_raw(trim((string) $enclosure->get_thumbnail()));
                }

                if ($media['image_url'] === '') {
                    if ($thumbnail !== '') {
                        $media['image_url'] = $thumbnail;
                    } elseif ($link !== '' && (self::url_looks_like_image($link) || strpos($type, 'image/') === 0)) {
                        $media['image_url'] = $link;
                    }
                }

                if ($allow_video && $media['video_url'] === '' && $link !== '' && (self::url_looks_like_video($link) || strpos($type, 'video/') === 0 || self::is_video_embed_url($link))) {
                    $media['video_url'] = $link;
                }

                if ($media['image_url'] !== '' && $media['video_url'] !== '') {
                    break;
                }
            }

            return $media;
        }

        public static function extract_html_attributes($html_tag)
        {
            $attributes = array();
            $html_tag = (string) $html_tag;
            if ($html_tag === '' || !preg_match('/^<\s*[\w:-]+\b([^>]*)>/i', $html_tag, $matches)) {
                return $attributes;
            }

            $attr_string = $matches[1];
            if (!preg_match_all('/([\w:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $attr_string, $attr_matches, PREG_SET_ORDER)) {
                return $attributes;
            }

            foreach ($attr_matches as $attr_match) {
                $name = strtolower($attr_match[1]);
                $value = '';
                if (isset($attr_match[2]) && $attr_match[2] !== '') {
                    $value = $attr_match[2];
                } elseif (isset($attr_match[3]) && $attr_match[3] !== '') {
                    $value = $attr_match[3];
                } elseif (isset($attr_match[4]) && $attr_match[4] !== '') {
                    $value = $attr_match[4];
                }
                $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            }

            return $attributes;
        }

        public static function html_class_has_video_marker($class_value)
        {
            $class_value = strtolower(trim((string) $class_value));
            if ($class_value === '') {
                return false;
            }
            return (bool) preg_match('/(?:^|\s)(gallery-image-video|gallery-video|oembed|video|player|youtube|vimeo|dailymotion|tiktok)(?:\s|$)/i', $class_value);
        }

        public static function normalize_video_selector_class_tokens($selector_class)
        {
            $selector_class = trim((string) $selector_class);
            if ($selector_class === '') {
                return array();
            }

            $selector_class = str_replace(array("\r", "\n", "\t", ','), ' ', $selector_class);
            $selector_class = preg_replace('/\s+/', ' ', $selector_class);
            if ($selector_class === '') {
                return array();
            }

            $tokens = array();
            foreach (explode(' ', $selector_class) as $token) {
                $token = strtolower(sanitize_html_class(trim($token)));
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }

            return array_values(array_unique($tokens));
        }

        public static function extract_dom_node_attributes($node)
        {
            $attributes = array();
            if (!is_object($node) || !property_exists($node, 'attributes') || empty($node->attributes)) {
                return $attributes;
            }

            foreach ($node->attributes as $attribute) {
                if (!is_object($attribute) || !isset($attribute->name)) {
                    continue;
                }

                $name = strtolower((string) $attribute->name);
                if ($name === '') {
                    continue;
                }

                $value = isset($attribute->value) ? (string) $attribute->value : '';
                $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            }

            return $attributes;
        }

        public static function extract_video_candidate_from_html($html, $base_url = '', $video_selector_class = '')
        {
            $result = array(
                'video_url' => '',
                'video_embed_html' => '',
                'video_source' => '',
                'video_class' => '',
                'video_attr' => '',
                'video_tag' => '',
            );

            $html = (string) $html;
            if ($html === '') {
                return $result;
            }

            if ($video_selector_class !== '') {
                $tokens = self::normalize_video_selector_class_tokens($video_selector_class);
                if (empty($tokens) || !class_exists('DOMDocument') || !class_exists('DOMXPath')) {
                    return $result;
                }

                $previous_libxml = libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                $dom_html = $html;
                $dom_html = '<?xml encoding="utf-8" ?>' . $dom_html;

                $loaded = $dom->loadHTML($dom_html, LIBXML_NOWARNING | LIBXML_NOERROR);
                libxml_clear_errors();
                libxml_use_internal_errors($previous_libxml);

                if ($loaded) {
                    $xpath = new DOMXPath($dom);
                    $class_expr = "translate(concat(' ', normalize-space(@class), ' '), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')";
                    $conditions = array();
                    foreach ($tokens as $token) {
                        $conditions[] = "contains(" . $class_expr . ", ' " . strtolower($token) . " ')";
                    }

                    $query = '//*[' . implode(' and ', $conditions) . ']';
                    $wrappers = $xpath->query($query);
                    if ($wrappers && $wrappers->length > 0) {
                        foreach ($wrappers as $wrapper) {
                            $iframe_nodes = $xpath->query('.//iframe', $wrapper);
                            if (!$iframe_nodes || $iframe_nodes->length === 0) {
                                continue;
                            }

                            $iframe_node = $iframe_nodes->item(0);
                            $iframe_attrs = self::extract_dom_node_attributes($iframe_node);
                            $candidate_url = '';
                            $candidate_attr = '';
                            foreach (array('src', 'data-src', 'data-oembed-url', 'data-lazy-src', 'data-video-url', 'data-url') as $attr_name) {
                                if (!empty($iframe_attrs[$attr_name])) {
                                    $candidate_url = (string) $iframe_attrs[$attr_name];
                                    $candidate_attr = $attr_name;
                                    break;
                                }
                            }

                            if ($candidate_url === '') {
                                continue;
                            }

                            $resolved_url = self::resolve_url_against_base($candidate_url, $base_url);
                            if ($resolved_url === '') {
                                continue;
                            }

                            $wrapper_attrs = self::extract_dom_node_attributes($wrapper);
                            $result['video_url'] = $resolved_url;
                            $result['video_source'] = 'selector_class_match';
                            $result['video_class'] = !empty($wrapper_attrs['class']) ? $wrapper_attrs['class'] : implode(' ', $tokens);
                            $result['video_attr'] = $candidate_attr;
                            $result['video_tag'] = 'iframe';

                            return $result;
                        }
                    }
                }

                return $result;
            }

            $wrapper_matches = array();
            if (preg_match_all('/<div\b(?=[^>]*class=["\'][^"\']*(?:slide-key|image-holder|gallery-image-holder|credit-image-wrap|lead-image-holder)[^"\']*["\'])[^>]*>.*?<iframe\b[^>]*src=["\']([^"\']+)["\'][^>]*>.*?<\/iframe>.*?<\/div>/is', $html, $wrapper_matches, PREG_SET_ORDER) && !empty($wrapper_matches)) {
                foreach ($wrapper_matches as $wrapper_match) {
                    $wrapper_html = isset($wrapper_match[0]) ? (string) $wrapper_match[0] : '';
                    $candidate_url = isset($wrapper_match[1]) ? (string) $wrapper_match[1] : '';
                    $resolved_url = $candidate_url !== '' ? self::resolve_url_against_base($candidate_url, $base_url) : '';
                    if ($resolved_url !== '' && self::is_video_embed_url($resolved_url)) {
                        $result['video_url'] = $resolved_url;
                        $result['video_source'] = 'wrapper_iframe_match';
                        $result['video_class'] = 'slide-key/image-holder wrapper';
                        $result['video_attr'] = 'src';
                        $result['video_tag'] = 'div';

                        return $result;
                    }
                }
            }

            $iframe_matches = array();
            if (preg_match_all('/<iframe\b[^>]*>.*?<\/iframe>/is', $html, $iframe_matches) && !empty($iframe_matches[0])) {
                foreach ($iframe_matches[0] as $iframe_html) {
                    $attrs = self::extract_html_attributes($iframe_html);
                    $class_value = isset($attrs['class']) ? (string) $attrs['class'] : '';
                    $candidate_url = '';
                    $candidate_attr = '';
                    foreach (array('src', 'data-src', 'data-oembed-url', 'data-lazy-src', 'data-video-url', 'data-url') as $attr_name) {
                        if (!empty($attrs[$attr_name])) {
                            $candidate_url = (string) $attrs[$attr_name];
                            $candidate_attr = $attr_name;
                            break;
                        }
                    }

                    $resolved_url = $candidate_url !== '' ? self::resolve_url_against_base($candidate_url, $base_url) : '';
                    $class_matches = self::html_class_has_video_marker($class_value);
                    $url_matches = $resolved_url !== '' && self::is_video_embed_url($resolved_url);

                    if ($class_matches || $url_matches) {
                        $result['video_url'] = $url_matches ? $resolved_url : $result['video_url'];
                        $result['video_source'] = $class_matches ? 'iframe_class_match' : 'iframe_url_match';
                        $result['video_class'] = $class_value;
                        $result['video_attr'] = $candidate_attr;
                        $result['video_tag'] = 'iframe';

                        return $result;
                    }
                }
            }

            $video_matches = array();
            if (preg_match_all('/<(video|source)\b[^>]*>.*?<\/\1>/is', $html, $video_matches) && !empty($video_matches[0])) {
                foreach ($video_matches[0] as $video_html) {
                    $attrs = self::extract_html_attributes($video_html);
                    $candidate_url = '';
                    $candidate_attr = '';
                    foreach (array('src', 'data-src', 'data-video-url', 'data-url', 'data-lazy-src') as $attr_name) {
                        if (!empty($attrs[$attr_name])) {
                            $candidate_url = (string) $attrs[$attr_name];
                            $candidate_attr = $attr_name;
                            break;
                        }
                    }

                    $resolved_url = $candidate_url !== '' ? self::resolve_url_against_base($candidate_url, $base_url) : '';
                    if ($resolved_url !== '' && self::is_video_embed_url($resolved_url)) {
                        $result['video_url'] = $resolved_url;
                        $result['video_embed_html'] = $video_html;
                        $result['video_source'] = 'video_tag_match';
                        $result['video_attr'] = $candidate_attr;
                        $result['video_tag'] = strpos($video_html, '<source') !== false ? 'source' : 'video';

                        return $result;
                    }
                }
            }

            $source_matches = array();
            if (preg_match_all('/<source\b[^>]*>/i', $html, $source_matches) && !empty($source_matches[0])) {
                foreach ($source_matches[0] as $source_html) {
                    $attrs = self::extract_html_attributes($source_html);
                    $candidate_url = '';
                    $candidate_attr = '';
                    foreach (array('src', 'data-src', 'data-video-url', 'data-url', 'data-lazy-src') as $attr_name) {
                        if (!empty($attrs[$attr_name])) {
                            $candidate_url = (string) $attrs[$attr_name];
                            $candidate_attr = $attr_name;
                            break;
                        }
                    }

                    $resolved_url = $candidate_url !== '' ? self::resolve_url_against_base($candidate_url, $base_url) : '';
                    if ($resolved_url !== '' && self::is_video_embed_url($resolved_url)) {
                        $result['video_url'] = $resolved_url;
                        $result['video_source'] = 'source_tag_match';
                        $result['video_attr'] = $candidate_attr;
                        $result['video_tag'] = 'source';

                        return $result;
                    }
                }
            }

            if (preg_match('~(https?://[^"\'<>\s]+(?:youtube\.com/embed/|youtu\.be/|player\.vimeo\.com/video/|vimeo\.com/|dailymotion\.com/embed/video/|tiktok\.com/embed/|streamable\.com/[^"\'<>\s]+))~i', $html, $matches)) {
                $candidate_url = self::resolve_url_against_base($matches[1], $base_url);
                if ($candidate_url !== '') {
                    $result['video_url'] = $candidate_url;
                    $result['video_source'] = 'url_scan_match';

                    return $result;
                }
            }

            return $result;
        }



        public static function resolve_item_media($item, $permalink, $excerpt_html, $content_html, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '')
        {
            $allow_video_enclosures = trim((string) $video_selector_class) === '';
            $page_media = array(
                'image_url' => '',
                'image_source' => '',
                'image_class' => '',
                'image_attr' => '',
                'image_tag' => '',
                'link_url' => '',
                'link_text' => '',
                'link_source' => '',
                'video_url' => '',
                'video_embed_html' => '',
                'video_source' => '',
            );

            $candidates = array();
            if (!empty($content_html)) {
                $candidates[] = Alpha_RSS_AI_Generator_Helper::extract_media_from_html($content_html, $permalink, $video_selector_class, $image_selector_class, $link_selector_class);
            }
            if (!empty($excerpt_html)) {
                $candidates[] = Alpha_RSS_AI_Generator_Helper::extract_media_from_html($excerpt_html, $permalink, $video_selector_class, $image_selector_class, $link_selector_class);
            }

            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                foreach (array('image_url', 'image_source', 'image_class', 'image_attr', 'image_tag', 'link_url', 'link_text', 'link_source', 'video_url', 'video_embed_html', 'video_source') as $key) {
                    if (empty($page_media[$key]) && !empty($candidate[$key])) {
                        $page_media[$key] = $candidate[$key];
                    }
                }
            }

            if (!empty($permalink) && (empty($page_media['image_url']) || empty($page_media['video_url']) || empty($page_media['link_url']))) {
                $source_page_media = Alpha_RSS_AI_Generator_Helper::extract_media_from_source_page($permalink, $video_selector_class, $image_selector_class, $link_selector_class);
                if (is_array($source_page_media)) {
                    foreach (array('image_url', 'image_source', 'image_class', 'image_attr', 'image_tag', 'link_url', 'link_text', 'link_source', 'video_url', 'video_embed_html', 'video_source') as $key) {
                        if (empty($page_media[$key]) && !empty($source_page_media[$key])) {
                            $page_media[$key] = $source_page_media[$key];
                        }
                    }
                }
            }

            $media = $page_media;
            $enclosure_media = self::extract_media_from_enclosures($item, $allow_video_enclosures);

            if ((!isset($media['image_url']) || $media['image_url'] === '') && !empty($enclosure_media['image_url'])) {
                if (self::is_probably_bad_featured_image_url($enclosure_media['image_url'], $permalink)) {
                    self::log_image_debug('image_candidate_rejected', array(
                        'stage' => 'enclosure',
                        'permalink' => $permalink,
                        'image_url' => $enclosure_media['image_url'],
                    ));
                } else {
                    $media['image_url'] = $enclosure_media['image_url'];
                    if (empty($media['image_source'])) {
                        $media['image_source'] = 'enclosure_fallback';
                    }
                    if (empty($media['image_tag'])) {
                        $media['image_tag'] = 'enclosure';
                    }
                }
            }

            if ($media['video_url'] === '' && !empty($enclosure_media['video_url'])) {
                $media['video_url'] = $enclosure_media['video_url'];
            }
            if (empty($media['video_source']) && !empty($enclosure_media['video_url'])) {
                $media['video_source'] = 'enclosure_fallback';
            }

            return $media;
        }

        public static function hydrate_rss_item_for_generation($generator, $item)
        {
            return $item;
        }

        public static function resolve_item_media_for_generation($generator, $item)
        {
            $permalink = !empty($item['permalink']) ? trim((string) $item['permalink']) : '';
            if ($permalink === '') {
                return $item;
            }

            $video_selector_class = !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '';
            $image_selector_class = !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '';
            $link_selector_class = !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '';
            $excerpt_html = isset($item['excerpt_html']) ? (string) $item['excerpt_html'] : (isset($item['excerpt']) ? (string) $item['excerpt'] : '');
            $content_html = isset($item['content_html']) ? (string) $item['content_html'] : (isset($item['content']) ? (string) $item['content'] : '');
            $media = self::resolve_item_media($item, $permalink, $excerpt_html, $content_html, $video_selector_class, $image_selector_class, $link_selector_class);

            if (!empty($media['image_url'])) {
                $item['source_image_url'] = $media['image_url'];
            }
            if (!empty($media['link_url'])) {
                $item['source_link_url'] = $media['link_url'];
            }
            if (!empty($media['link_text'])) {
                $item['source_link_text'] = $media['link_text'];
            }
            if (!empty($media['video_url'])) {
                $item['source_video_url'] = $media['video_url'];
            }
            if (!empty($media['video_embed_html'])) {
                $item['source_video_embed_html'] = $media['video_embed_html'];
            }
            if (!empty($media['video_source'])) {
                $item['source_video_source'] = $media['video_source'];
            }
            if (!empty($image_selector_class)) {
                $item['source_image_selector_class'] = $image_selector_class;
            }
            if (!empty($link_selector_class)) {
                $item['source_link_selector_class'] = $link_selector_class;
            }

            return $item;
        }

        public static function extract_video_url_from_embed_html($html, $base_url = '')
        {
            $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
            if ($html === '') {
                return '';
            }

            if (preg_match('/<iframe\b[^>]*(?:src|data-src|data-oembed-url|data-lazy-src|data-video-url|data-url)=["\']([^"\']+)["\']/i', $html, $matches)) {
                return self::normalize_embed_target_url(self::resolve_url_against_base(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url));
            }

            if (preg_match('~(https?://(?:www\.)?youtube\.com/watch\?(?:[^"\'<>\s]|&amp;|&)+|https?://youtu\.be/[^"\'<>\s]+|https?://(?:www\.)?vimeo\.com/[0-9]+|https?://player\.vimeo\.com/video/[0-9]+|https?://(?:www\.)?dailymotion\.com/(?:video|embed/video)/[^"\'<>\s]+|https?://(?:www\.)?tiktok\.com/@[^"\'<>\s]+/video/\d+|https?://(?:www\.)?streamable\.com/[^"\'<>\s]+)~i', $html, $matches)) {
                return self::normalize_embed_target_url(self::resolve_url_against_base(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url));
            }

            if (preg_match('~(https?://[^"\'<>\s]+(?:youtube\.com/embed/|youtu\.be/|player\.vimeo\.com/video/|vimeo\.com/|dailymotion\.com/embed/video/|tiktok\.com/embed/|streamable\.com/[^"\'<>\s]+))~i', $html, $matches)) {
                return self::normalize_embed_target_url(self::resolve_url_against_base($matches[1], $base_url));
            }

            return '';
        }

        public static function detect_video_provider_slug($video_url)
        {
            $video_url = (string) $video_url;
            if (preg_match('~(youtube\.com|youtu\.be)~i', $video_url)) {
                return 'youtube';
            }
            if (preg_match('~vimeo\.com~i', $video_url)) {
                return 'vimeo';
            }
            if (preg_match('~dailymotion\.com~i', $video_url)) {
                return 'dailymotion';
            }
            if (preg_match('~tiktok\.com~i', $video_url)) {
                return 'tiktok';
            }
            if (preg_match('~streamable\.com~i', $video_url)) {
                return 'streamable';
            }
            return 'video';
        }

        public static function wrap_video_embed_block_html($video_url, $embed_html, &$debug = null)
        {
            $video_url = esc_url_raw(trim((string) $video_url));
            $embed_html = trim((string) $embed_html);
            $provider = self::detect_video_provider_slug($video_url);
            $debug = array(
                'mode' => 'wp_embed_block',
                'source' => '',
                'provider' => $provider,
                'embed_length' => 0,
            );

            if ($embed_html === '') {
                return '';
            }

            $classes = array(
                'wp-block-embed',
                'is-type-video',
                'is-provider-' . $provider,
                'wp-block-embed-' . $provider,
                'wp-embed-aspect-16-9',
                'wp-has-aspect-ratio',
            );
            $wrapped = '<figure class="' . esc_attr(implode(' ', $classes)) . '"><div class="wp-block-embed__wrapper">' . $embed_html . '</div></figure>';
            $debug['embed_length'] = strlen($wrapped);

            return $wrapped;
        }

        public static function build_video_embed_html($video_url, &$debug = null)
        {
            $video_url = esc_url_raw(trim((string) $video_url));
            $debug = array(
                'mode' => 'empty',
                'source' => '',
                'provider' => self::detect_video_provider_slug($video_url),
                'embed_length' => 0,
            );
            if ($video_url === '') {
                return '';
            }

            $embed = wp_oembed_get($video_url);
            if (is_string($embed) && trim($embed) !== '') {
                $debug['source'] = 'wp_oembed_get';
                return self::wrap_video_embed_block_html($video_url, $embed, $debug);
            }

            if (self::url_looks_like_video($video_url)) {
                if (!function_exists('wp_video_shortcode')) {
                    require_once ABSPATH . WPINC . '/media.php';
                }
                $shortcode = wp_video_shortcode(array('src' => $video_url));
                if (is_string($shortcode) && trim($shortcode) !== '') {
                    $debug['source'] = 'wp_video_shortcode';
                    return self::wrap_video_embed_block_html($video_url, $shortcode, $debug);
                }
                $debug['source'] = 'shortcode_video';
                $debug['mode'] = 'video_url_fallback';
                return '<figure class="wp-block-embed is-type-video is-provider-video wp-block-embed-video wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper"><a href="' . esc_url($video_url) . '">' . esc_html($video_url) . '</a></div></figure>';
            }

            if (self::is_video_embed_url($video_url)) {
                $embed = wp_oembed_get($video_url);
                if (is_string($embed) && trim($embed) !== '') {
                    $debug['source'] = 'wp_oembed_get';
                    return self::wrap_video_embed_block_html($video_url, $embed, $debug);
                }
                $debug['source'] = 'embed_url_fallback';
                $debug['mode'] = 'embed_url_fallback';
                return '<figure class="wp-block-embed is-type-video is-provider-video wp-block-embed-video wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper"><a href="' . esc_url($video_url) . '">' . esc_html($video_url) . '</a></div></figure>';
            }

            return '';
        }

        public static function log_image_debug($stage, $context = array())
        {
            $parts = array();
            foreach ((array) $context as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $encoded = wp_json_encode($value);
                    $parts[] = $key . '=' . ($encoded !== false ? $encoded : '[unserializable]');
                    continue;
                }

                if ($value === null) {
                    $parts[] = $key . '=null';
                    continue;
                }

                if ($value === true) {
                    $parts[] = $key . '=1';
                    continue;
                }

                if ($value === false) {
                    $parts[] = $key . '=0';
                    continue;
                }

                $parts[] = $key . '=' . (string) $value;
            }
        }

        public static function build_featured_image_filename($title, $image_url, $source_label = 'source')
        {
            $base_title = sanitize_file_name(sanitize_title((string) $title));
            if ($base_title === '') {
                $base_title = 'featured-image';
            }

            $source_label = sanitize_key((string) $source_label);
            if ($source_label !== '') {
                $base_title .= '-' . $source_label;
            }

            $path = (string) wp_parse_url((string) $image_url, PHP_URL_PATH);
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp');
            if (!in_array($extension, $allowed_extensions, true)) {
                $extension = 'jpg';
            }

            return $base_title . '.' . $extension;
        }

        public static function build_featured_image_alt_text($title, $source_label = 'source', $query = '', $credit = '')
        {
            $alt = trim(wp_strip_all_tags((string) $title));
            if ($alt === '') {
                $alt = trim((string) $query);
            }
            if ($alt === '') {
                $alt = 'Imagem destacada';
            }

            return $alt;
        }

        public static function normalize_image_display_size($size)
        {
            $size = sanitize_key((string) $size);
            $allowed = array('thumbnail', 'medium', 'medium_large', 'large', 'full');
            if (!in_array($size, $allowed, true)) {
                return 'medium';
            }

            return $size;
        }

        public static function download_image_attachment_from_url($post_id, $image_url, $title, $source_label = 'source', $query = '', $credit = '')
        {
            $post_id = intval($post_id);
            $image_url = esc_url_raw(trim((string) $image_url));
            if ($image_url === '') {
                self::log_image_debug('skip_empty_url', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'title' => $title,
                ));
                return false;
            }

            if ($source_label === 'source' && self::is_probably_bad_featured_image_url($image_url, $title)) {
                self::log_image_debug('skip_bad_source_image', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'image_url' => $image_url,
                    'title' => $title,
                ));
                return false;
            }

            self::log_image_debug('start_download', array(
                'post_id' => $post_id,
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'query' => $query,
                'credit' => $credit,
            ));

            if (!function_exists('download_url')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!function_exists('media_handle_sideload')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $file_name = self::build_featured_image_filename($title, $image_url, $source_label);
            $alt_text = self::build_featured_image_alt_text($title, $source_label, $query, $credit);
            self::log_image_debug('prepared_attachment', array(
                'post_id' => $post_id,
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'file_name' => $file_name,
                'alt_text' => $alt_text,
            ));

            $tmp = download_url($image_url);
            if (is_wp_error($tmp)) {
                self::log_image_debug('download_failed', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'image_url' => $image_url,
                    'error' => $tmp->get_error_message(),
                ));
                return false;
            }

            $file_array = array(
                'name' => $file_name,
                'tmp_name' => $tmp,
            );

            $previous_error_handler = set_error_handler(function ($errno, $errstr, $errfile, $errline) {
                if (($errno === E_WARNING || $errno === E_NOTICE) && stripos((string) $errstr, 'exif_read_data(') !== false) {
                    return true;
                }
                if (($errno === E_WARNING || $errno === E_NOTICE) && stripos((string) $errstr, 'Incorrect APP1 Exif Identifier Code') !== false) {
                    return true;
                }
                return false;
            });
            $attachment_id = media_handle_sideload($file_array, $post_id, $title);
            restore_error_handler();
            @unlink($tmp);

            if (is_wp_error($attachment_id)) {
                self::log_image_debug('sideload_failed', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'image_url' => $image_url,
                    'error' => $attachment_id->get_error_message(),
                ));
                return false;
            }

            $attachment_update = wp_update_post(array(
                'ID' => $attachment_id,
                'post_title' => $title,
                'post_excerpt' => $alt_text,
            ), true);
            if (is_wp_error($attachment_update)) {
                self::log_image_debug('attachment_update_failed', array(
                    'post_id' => $post_id,
                    'source_label' => sanitize_key($source_label),
                    'attachment_id' => intval($attachment_id),
                    'error' => $attachment_update->get_error_message(),
                ));
            }

            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            if ($post_id > 0) {
                wp_update_post(array(
                    'ID' => $attachment_id,
                    'post_parent' => $post_id,
                ));
            }

            self::log_image_debug('attachment_ready', array(
                'post_id' => $post_id,
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'attachment_id' => intval($attachment_id),
                'alt_text' => $alt_text,
                'file_name' => $file_name,
            ));

            return $attachment_id;
        }

        public static function build_attachment_image_figure_html($attachment_id, $size = 'medium', $alt_text = '', $align = 'alignleft')
        {
            $attachment_id = intval($attachment_id);
            if ($attachment_id <= 0) {
                return '';
            }

            $size = self::normalize_image_display_size($size);
            $image_url = wp_get_attachment_image_url($attachment_id, $size);
            if ($image_url === false || $image_url === '') {
                $image_url = wp_get_attachment_url($attachment_id);
                $size = 'full';
            }
            if ($image_url === false || $image_url === '') {
                return '';
            }

            $alt_text = trim((string) $alt_text);
            if ($alt_text === '') {
                $alt_text = trim((string) get_the_title($attachment_id));
            }
            if ($alt_text === '') {
                $alt_text = 'Imagem';
            }

            $class_names = array(
                'wp-block-image',
                sanitize_html_class((string) $align),
                'size-' . sanitize_html_class($size),
            );

            return '<figure class="' . esc_attr(implode(' ', array_values(array_filter(array_unique($class_names))))) . '"><img src="' . esc_url($image_url) . '" alt="' . esc_attr($alt_text) . '" /></figure>';
        }

        public static function download_and_set_featured_image_from_url($post_id, $image_url, $title, $source_label = 'source', $query = '', $credit = '')
        {
            $attachment_id = self::download_image_attachment_from_url($post_id, $image_url, $title, $source_label, $query, $credit);
            if (!$attachment_id) {
                return false;
            }

            $alt_text = self::build_featured_image_alt_text($title, $source_label, $query, $credit);
            $thumbnail_set = set_post_thumbnail($post_id, $attachment_id);
            self::log_image_debug('thumbnail_result', array(
                'post_id' => intval($post_id),
                'source_label' => sanitize_key($source_label),
                'image_url' => $image_url,
                'attachment_id' => intval($attachment_id),
                'thumbnail_set' => $thumbnail_set ? 1 : 0,
                'alt_text' => $alt_text,
            ));
            update_post_meta($post_id, '_arc_featured_image_source', sanitize_key($source_label));
            update_post_meta($post_id, '_arc_featured_image_url', $image_url);
            update_post_meta($post_id, '_arc_featured_image_alt', $alt_text);
            if ($query !== '') {
                update_post_meta($post_id, '_arc_featured_image_query', sanitize_text_field($query));
            }
            if ($credit !== '') {
                update_post_meta($post_id, '_arc_pexels_credit', sanitize_text_field($credit));
            }

            return $attachment_id;
        }

        public static function get_rss_items($feed_url, $limit = 10, $include_media = true, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '')
        {
            if (!function_exists('fetch_feed')) {
                require_once ABSPATH . WPINC . '/feed.php';
            }

            $feed = fetch_feed($feed_url);
            if (is_wp_error($feed)) {
                return $feed;
            }

            $limit = max(1, intval($limit));
            $items = $feed->get_items(0, $limit);
            $results = array();

            foreach ($items as $item) {
                $guid = method_exists($item, 'get_id') ? (string) $item->get_id() : '';
                if ($guid === '') {
                    $guid = method_exists($item, 'get_permalink') ? (string) $item->get_permalink() : '';
                }
                if ($guid === '') {
                    $guid = md5((string) $item->get_title() . '|' . (string) $item->get_date('c'));
                }

                $excerpt_html = method_exists($item, 'get_description') ? (string) $item->get_description() : '';
                $content_html = method_exists($item, 'get_content') ? (string) $item->get_content() : '';
                $permalink = method_exists($item, 'get_permalink') ? (string) $item->get_permalink() : '';
                $feed_title = method_exists($feed, 'get_title') ? (string) $feed->get_title() : '';
                $categories = array();
                if (method_exists($item, 'get_categories')) {
                    $item_categories = $item->get_categories();
                    if (is_array($item_categories)) {
                        foreach ($item_categories as $cat) {
                            if (is_object($cat) && method_exists($cat, 'get_label')) {
                                $label = (string) $cat->get_label();
                                if ($label !== '') {
                                    $categories[] = $label;
                                }
                            }
                        }
                    }
                }

                $media = $include_media ? self::resolve_item_media($item, $permalink, $excerpt_html, $content_html, $video_selector_class, $image_selector_class, $link_selector_class) : array();

                $results[] = array(
                    'guid' => $guid,
                    'title' => (string) $item->get_title(),
                    'permalink' => $permalink,
                    'excerpt_html' => $excerpt_html,
                    'content_html' => $content_html,
                    'excerpt' => self::clean_source_text($excerpt_html),
                    'content' => self::clean_source_text($content_html),
                    'feed_title' => $feed_title,
                    'date' => (string) $item->get_date('c'),
                    'categories' => array_values(array_unique($categories)),
                    'source_image_url' => isset($media['image_url']) ? $media['image_url'] : '',
                    'source_link_url' => isset($media['link_url']) ? $media['link_url'] : '',
                    'source_link_text' => isset($media['link_text']) ? $media['link_text'] : '',
                    'source_video_url' => isset($media['video_url']) ? $media['video_url'] : '',
                    'source_video_embed_html' => isset($media['video_embed_html']) ? $media['video_embed_html'] : '',
                    'source_video_source' => isset($media['video_source']) ? $media['video_source'] : '',
                );
            }

            return $results;
        }

        public static function get_rss_items_for_generator($generator, $limit = 10, $include_media = false, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $source_context_filters = array())
        {
            $feed_url = !empty($generator['feed_url']) ? (string) $generator['feed_url'] : '';
            if ($feed_url === '') {
                return array();
            }

            $limit = max(1, intval($limit));
            $fetch_limit = min(500, max(200, $limit * 10));
            $items = self::get_rss_items($feed_url, $fetch_limit, $include_media, $video_selector_class, $image_selector_class, $link_selector_class);
            if (is_wp_error($items)) {
                return $items;
            }

            $available_items = array();
            $generator_id = !empty($generator['id']) ? intval($generator['id']) : 0;
            foreach ($items as $item) {
                if ($generator_id > 0 && self::is_item_processed($generator_id, $item['guid'])) {
                    continue;
                }
                if (!empty($source_context_filters) && !Alpha_RSS_AI_Generator_Helper::source_context_item_matches_filters($item, $source_context_filters)) {
                    continue;
                }
                $available_items[] = $item;
                if (count($available_items) >= $limit) {
                    break;
                }
            }

            return $available_items;
        }

        public static function bulk_row_data_summary($row_data)
        {
            if (!is_array($row_data)) {
                return '';
            }

            $preferred = array();
            $fallback = array();
            foreach ($row_data as $label => $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }

                $line = $label . ': ' . $value;
                $normalized_label = remove_accents((string) $label);
                if (preg_match('/(keyword|palavra.?chave|url|slug|title|titulo|content|conteu|excerpt|descricao|meta|tags?|author|autor)/i', $normalized_label)) {
                    $preferred[] = $line;
                } else {
                    $fallback[] = $line;
                }
            }

            $parts = !empty($preferred) ? $preferred : array_slice($fallback, 0, 5);
            $summary = implode("\n", $parts);
            if (strlen($summary) > 6000) {
                $summary = substr($summary, 0, 6000);
            }

            return $summary;
        }

        public static function build_keyword_list_item_from_row($list, $row, $include_media = true, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $source_context_filters = array())
        {
            $row_data = array();
            if (is_object($row) && isset($row->row_data)) {
                $row_data = json_decode($row->row_data, true);
            } elseif (is_array($row) && isset($row['row_data'])) {
                $row_data = json_decode($row['row_data'], true);
            }
            if (!is_array($row_data)) {
                $row_data = array();
            }

            $row_id = is_object($row) ? intval($row->id) : intval(isset($row['id']) ? $row['id'] : 0);
            $keyword = is_object($row) ? (string) $row->keyword : (string) (isset($row['keyword']) ? $row['keyword'] : '');
            $source_title = is_object($row) ? (string) $row->source_title : (string) (isset($row['source_title']) ? $row['source_title'] : '');
            $source_url = is_object($row) ? (string) $row->source_url : (string) (isset($row['source_url']) ? $row['source_url'] : '');
            $final_slug = is_object($row) ? (string) $row->final_slug : (string) (isset($row['final_slug']) ? $row['final_slug'] : '');
            $row_timestamp = self::bulk_extract_row_timestamp($row_data);

            $item = array(
                'guid' => 'listrow:' . $row_id,
                'title' => $source_title !== '' ? $source_title : $keyword,
                'keyword' => $keyword,
                'source_title' => $source_title,
                'source_url' => $source_url,
                'final_slug' => $final_slug,
                'row_data' => $row_data,
                'feed_title' => is_array($list) && !empty($list['list_name']) ? $list['list_name'] : '',
                'permalink' => $source_url,
                'excerpt' => '',
                'content' => '',
                'date' => $row_timestamp,
                'categories' => array(),
                'source_image_url' => '',
                'source_link_url' => '',
                'source_link_text' => '',
                'source_video_url' => '',
                'source_image_selector_class' => $image_selector_class,
                'source_link_selector_class' => $link_selector_class,
            );

            if ($include_media && $source_url !== '') {
                $page_context = self::extract_page_context($source_url, $video_selector_class, $image_selector_class, $link_selector_class, $source_context_filters);
                if ($item['title'] === '' && !empty($page_context['title'])) {
                    $item['title'] = $page_context['title'];
                }
                if (!empty($page_context['title'])) {
                    $item['source_page_title'] = $page_context['title'];
                }
                if (!empty($page_context['excerpt'])) {
                    $item['excerpt'] = $page_context['excerpt'];
                    $item['source_page_excerpt'] = $page_context['excerpt'];
                }
                if (!empty($page_context['content'])) {
                    $item['content'] = $page_context['content'];
                    $item['source_page_content'] = $page_context['content'];
                }
                if (!empty($page_context['outline_text'])) {
                    $item['source_page_outline'] = $page_context['outline_text'];
                }
                if (!empty($page_context['outline']) && is_array($page_context['outline'])) {
                    $item['source_page_outline_sections'] = $page_context['outline'];
                }
                if (!empty($page_context['excerpt']) || !empty($page_context['content']) || !empty($page_context['title']) || !empty($page_context['outline_text'])) {
                    $item['source_context_enriched'] = 1;
                }

                $media = Alpha_RSS_AI_Generator_Helper::extract_media_from_source_page($source_url, $video_selector_class, $image_selector_class, $link_selector_class);
                if (!empty($media['image_url'])) {
                    $item['source_image_url'] = $media['image_url'];
                }
                if (!empty($media['link_url'])) {
                    $item['source_link_url'] = $media['link_url'];
                }
                if (!empty($media['link_text'])) {
                    $item['source_link_text'] = $media['link_text'];
                }
                if (!empty($media['video_url'])) {
                    $item['source_video_url'] = $media['video_url'];
                }
            }

            if ($item['excerpt'] === '') {
                $item['excerpt'] = !empty($item['source_title']) ? $item['source_title'] : self::bulk_row_data_summary($row_data);
            }
            if ($item['content'] === '') {
                $item['content'] = self::bulk_row_data_summary($row_data);
            }

            return $item;
        }

        public static function get_keyword_list_items($list_id, $limit = 10, $include_media = false, $video_selector_class = '')
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['lists']} WHERE id = %d", intval($list_id)), ARRAY_A);
            if (!$list) {
                return new WP_Error('arc_keyword_list_missing', 'Lista nao encontrada');
            }

            $limit = max(1, intval($limit));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d AND row_status = 'pending' ORDER BY row_number DESC LIMIT %d",
                intval($list_id),
                $limit
            ));

            $results = array();
            foreach ($rows as $row) {
                $results[] = self::build_keyword_list_item_from_row($list, $row, $include_media, $video_selector_class);
            }

            return $results;
        }

        public static function get_keyword_list_items_for_generator($generator_id, $list_id, $limit = 10, $include_media = false, $video_selector_class = '', $image_selector_class = '', $link_selector_class = '', $source_context_filters = array())
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['lists']} WHERE id = %d", intval($list_id)), ARRAY_A);
            if (!$list) {
                return new WP_Error('arc_keyword_list_missing', 'Lista nao encontrada');
            }

            $limit = max(1, intval($limit));
            $generator_id = intval($generator_id);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.* FROM {$tables['rows']} r
                 WHERE r.list_id = %d
                   AND r.row_status = 'pending'
                   AND NOT EXISTS (
                       SELECT 1 FROM " . self::$table_items . " i
                       WHERE i.generator_id = %d
                         AND i.item_guid = CONCAT('listrow:', r.id)
                   )
                 ORDER BY r.row_number DESC
                 LIMIT %d",
                intval($list_id),
                $generator_id,
                $limit
            ));

            $results = array();
            foreach ($rows as $row) {
                $item = self::build_keyword_list_item_from_row($list, $row, $include_media, $video_selector_class, $image_selector_class, $link_selector_class, $source_context_filters);
                if (!empty($source_context_filters) && !Alpha_RSS_AI_Generator_Helper::source_context_item_matches_filters($item, $source_context_filters)) {
                    continue;
                }
                $results[] = $item;
            }

            return $results;
        }

        public static function get_keyword_list_row_by_guid($list_id, $item_guid)
        {
            global $wpdb;
            $tables = self::bulk_tables();
            $item_guid = trim((string) $item_guid);
            if ($item_guid === '') {
                return null;
            }

            if (strpos($item_guid, 'listrow:') === 0) {
                $row_id = intval(substr($item_guid, 8));
                if ($row_id > 0) {
                    return $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$tables['rows']} WHERE list_id = %d AND id = %d LIMIT 1",
                        intval($list_id),
                        $row_id
                    ));
                }
            }

            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d AND final_slug = %s LIMIT 1",
                intval($list_id),
                $item_guid
            ));
        }



        public function rest_get_generator_items(WP_REST_Request $request)
        {
            $generator_id = intval($request->get_param('id'));
            $limit = isset($request['limit']) ? intval($request['limit']) : 20;
            $limit = max(1, min(40, $limit));
            $generator = self::get_generator($generator_id);

            if (!$generator) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Gerador não encontrado.',
                ), 404);
            }

            if (!empty($generator['source_type']) && $generator['source_type'] === 'keyword_list') {
                if (empty($generator['list_id'])) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Este gerador nao possui uma lista vinculada.',
                    ), 400);
                }

                $items = self::get_keyword_list_items_for_generator(
                    $generator_id,
                    intval($generator['list_id']),
                    $limit,
                    false,
                    !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                    !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                    !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                    self::get_generator_source_context_filters($generator)
                );
                if (is_wp_error($items)) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => $items->get_error_message(),
                    ), 400);
                }

                $available_items = array();
                foreach ($items as $item) {
                    if (self::is_item_processed($generator_id, $item['guid'])) {
                        continue;
                    }
                    $available_items[] = $item;
                }

                $list = self::get_keyword_list(intval($generator['list_id']));

                return rest_ensure_response(array(
                    'success' => true,
                    'generator' => array(
                        'id' => intval($generator['id']),
                        'name' => $generator['name'],
                        'source_type' => 'keyword_list',
                        'list_id' => intval($generator['list_id']),
                        'keyword_list_mode' => !empty($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : self::get_default_keyword_list_mode(),
                        'list_name' => $list ? $list['list_name'] : '',
                    ),
                    'available_count' => count($available_items),
                    'fetched_count' => count($items),
                    'items' => $available_items,
                ));
            }

            $items = self::get_rss_items_for_generator(
                $generator,
                $limit,
                false,
                !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                self::get_generator_source_context_filters($generator)
            );
            if (is_wp_error($items)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => $items->get_error_message(),
                ), 400);
            }

            $available_items = array();
            foreach ($items as $item) {
                if (self::is_item_processed($generator_id, $item['guid'])) {
                    continue;
                }
                $available_items[] = array(
                    'guid' => $item['guid'],
                    'title' => $item['title'],
                    'permalink' => $item['permalink'],
                    'excerpt' => $item['excerpt'],
                    'content' => $item['content'],
                    'feed_title' => $item['feed_title'],
                    'date' => $item['date'],
                    'categories' => $item['categories'],
                    'source_image_url' => !empty($item['source_image_url']) ? $item['source_image_url'] : '',
                    'source_video_url' => !empty($item['source_video_url']) ? $item['source_video_url'] : '',
                );
            }

            return rest_ensure_response(array(
                'success' => true,
                'generator' => array(
                    'id' => intval($generator['id']),
                    'name' => $generator['name'],
                    'feed_url' => $generator['feed_url'],
                    'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
                    'keyword_list_mode' => !empty($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : self::get_default_keyword_list_mode(),
                ),
                'available_count' => count($available_items),
                'fetched_count' => count($items),
                'items' => $available_items,
            ));
        }




        public static function rest_get_keyword_list(WP_REST_Request $request)
        {
            global $wpdb;
            $list_id = intval($request->get_param('id'));
            if (!$list_id) {
                return new WP_Error('arc_keyword_list_invalid', 'Lista invalida', array('status' => 400));
            }

            $tables = self::bulk_tables();
            $list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['lists']} WHERE id = %d", $list_id), ARRAY_A);
            if (!$list) {
                return new WP_Error('arc_keyword_list_missing', 'Lista nao encontrada', array('status' => 404));
            }

            self::bulk_refresh_list_counts($list_id);

            $preview_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$tables['rows']} WHERE list_id = %d ORDER BY row_number ASC LIMIT 50",
                $list_id
            ));

            $rows = array();
            foreach ($preview_rows as $row) {
                $row_data = json_decode($row->row_data, true);
                if (!is_array($row_data)) {
                    $row_data = array();
                }

                $rows[] = array(
                    'id' => intval($row->id),
                    'row_number' => intval($row->row_number),
                    'row_data' => $row_data,
                    'keyword' => $row->keyword,
                    'source_title' => isset($row->source_title) ? $row->source_title : '',
                    'source_url' => $row->source_url,
                    'final_slug' => $row->final_slug,
                    'slug_extension' => $row->slug_extension,
                    'slug_is_valid' => intval($row->slug_is_valid),
                    'row_status' => $row->row_status,
                    'post_id' => !empty($row->post_id) ? intval($row->post_id) : 0,
                    'error_message' => $row->error_message,
                    'processed_at' => $row->processed_at,
                );
            }

            $counts = self::bulk_get_list_counts($list_id);

            return rest_ensure_response(array(
                'success' => true,
                'list' => array(
                    'id' => intval($list['id']),
                    'list_name' => $list['list_name'],
                    'original_filename' => $list['original_filename'],
                    'file_type' => $list['file_type'],
                    'headers' => !empty($list['headers_json']) ? json_decode($list['headers_json'], true) : array(),
                    'column_map' => !empty($list['column_map_json']) ? json_decode($list['column_map_json'], true) : array(),
                    'counts' => $counts,
                    'status' => $list['status'],
                    'created_at' => $list['created_at'],
                    'updated_at' => $list['updated_at'],
                ),
                'rows' => $rows,
            ));
        }



        public function rest_update_keyword_list_columns(WP_REST_Request $request)
        {
            global $wpdb;
            $list_id = intval($request->get_param('id'));
            if (!$list_id) {
                return new WP_Error('arc_keyword_list_invalid', 'Lista invalida', array('status' => 400));
            }

            $payload = $request->get_json_params();
            if (!is_array($payload)) {
                $payload = array();
            }

            $column_map = isset($payload['column_map']) ? $payload['column_map'] : array();
            if (is_string($column_map) && trim($column_map) !== '') {
                $decoded = json_decode(wp_unslash($column_map), true);
                if (is_array($decoded)) {
                    $column_map = $decoded;
                }
            }
            if (!is_array($column_map)) {
                $column_map = array();
            }

            $wpdb->update(
                self::$table_lists,
                array(
                    'column_map_json' => wp_json_encode($column_map),
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => $list_id),
                array('%s', '%s'),
                array('%d')
            );

            $rebuild = self::bulk_rebuild_keyword_list_rows($list_id, $column_map);
            self::bulk_refresh_list_counts($list_id);

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Mapa de colunas atualizado com sucesso',
                'column_map' => $column_map,
                'rebuild' => $rebuild,
            ));
        }



        public function rest_delete_keyword_list(WP_REST_Request $request)
        {
            global $wpdb;
            $list_id = intval($request->get_param('id'));
            if (!$list_id) {
                return new WP_Error('arc_keyword_list_invalid', 'Lista invalida', array('status' => 400));
            }

            $tables = self::bulk_tables();
            $list = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['lists']} WHERE id = %d", $list_id));
            if (!$list) {
                return new WP_Error('arc_keyword_list_missing', 'Lista nao encontrada', array('status' => 404));
            }

            $wpdb->delete($tables['rows'], array('list_id' => $list_id), array('%d'));
            $wpdb->delete($tables['lists'], array('id' => $list_id), array('%d'));

            if (!empty($list->file_path) && file_exists($list->file_path)) {
                @unlink($list->file_path);
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Lista removida com sucesso',
            ));
        }

        public static function run_generator_item($generator, $item_guid)
        {
            $item_guid = trim((string) $item_guid);
            if ($item_guid === '') {
                return new WP_Error('arc_missing_item', 'Nenhum item foi selecionado.');
            }

            $selected_item = null;
            if (!empty($generator['source_type']) && $generator['source_type'] === 'keyword_list') {
                if (empty($generator['list_id'])) {
                    return new WP_Error('arc_missing_list', 'Este gerador não possui uma lista vinculada.');
                }

                $row = self::get_keyword_list_row_by_guid(intval($generator['list_id']), $item_guid);
                if (!$row) {
                    return new WP_Error('arc_item_not_found', 'O item selecionado não foi encontrado na lista.');
                }
                $selected_item = self::build_keyword_list_item_from_row(
                    self::get_keyword_list(intval($generator['list_id'])),
                    $row,
                    false,
                    !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                    !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                    !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                    self::get_generator_source_context_filters($generator)
                );
                if (!Alpha_RSS_AI_Generator_Helper::source_context_item_matches_filters($selected_item, self::get_generator_source_context_filters($generator))) {
                    return new WP_Error('arc_item_filtered', 'O item selecionado foi bloqueado pelos filtros da fonte.');
                }
            } else {
                $items = self::get_rss_items_for_generator(
                    $generator,
                    max(30, intval($generator['posts_per_run']) * 5),
                    false,
                    !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                    !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                    !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                    self::get_generator_source_context_filters($generator)
                );
                if (is_wp_error($items)) {
                    return $items;
                }

                foreach ($items as $item) {
                    if (isset($item['guid']) && (string) $item['guid'] === $item_guid) {
                        $selected_item = $item;
                        break;
                    }
                }

                if (!$selected_item) {
                    return new WP_Error('arc_item_not_found', 'O item selecionado não foi encontrado no feed.');
                }
            }

            if (self::is_item_processed($generator['id'], $selected_item['guid'])) {
                return new WP_Error('arc_item_processed', 'Esse item já foi gerado.');
            }

            $result = self::create_post_from_generator_item($generator, $selected_item);
            if (is_wp_error($result)) {
                return $result;
            }

            self::update_next_run_after_attempt($generator);
            $post_view_link = self::get_post_view_link(intval($result));
            $post_edit_link = self::get_post_edit_link(intval($result));

            return array(
                'post_id' => intval($result),
                'item_guid' => $selected_item['guid'],
                'item_title' => $selected_item['title'],
                'view_link' => $post_view_link ? $post_view_link : '',
                'edit_link' => $post_edit_link ? $post_edit_link : '',
                'permalink' => $post_view_link ? $post_view_link : '',
            );
        }

        public static function is_item_processed($generator_id, $guid)
        {
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . self::$table_items . " WHERE generator_id = %d AND item_guid = %s LIMIT 1",
                intval($generator_id),
                $guid
            ));
            return !empty($exists);
        }

        public static function mark_item_processed($generator_id, $item, $post_id)
        {
            global $wpdb;
            $wpdb->insert(
                self::$table_items,
                array(
                    'generator_id' => intval($generator_id),
                    'item_guid' => $item['guid'],
                    'item_permalink' => $item['permalink'],
                    'item_title' => $item['title'],
                    'post_id' => intval($post_id),
                    'item_hash' => md5($item['guid'] . '|' . $item['permalink']),
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        }

        public static function parse_time_to_seconds($time_string)
        {
            if (!preg_match('/^(\d{2}):(\d{2})$/', (string) $time_string, $matches)) {
                return 0;
            }
            $hours = max(0, min(23, intval($matches[1])));
            $minutes = max(0, min(59, intval($matches[2])));
            return ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS);
        }

        public static function format_timestamp_for_db($timestamp)
        {
            return wp_date('Y-m-d H:i:s', $timestamp, wp_timezone());
        }

        public static function schedule_next_run_for_generator($generator, $base_timestamp = null)
        {
            $base_timestamp = $base_timestamp ? intval($base_timestamp) : current_time('timestamp');
            $schedule_type = isset($generator['schedule_type']) ? $generator['schedule_type'] : 'interval';

            if ($schedule_type === 'daily_random') {
                $start_seconds = self::parse_time_to_seconds(isset($generator['daily_start']) ? $generator['daily_start'] : '08:00');
                $end_seconds = self::parse_time_to_seconds(isset($generator['daily_end']) ? $generator['daily_end'] : '22:00');
                if ($end_seconds <= $start_seconds) {
                    $end_seconds = min(DAY_IN_SECONDS - 60, $start_seconds + (8 * HOUR_IN_SECONDS));
                }

                $day_start = strtotime(wp_date('Y-m-d 00:00:00', $base_timestamp, wp_timezone()));
                $window_start = $day_start + $start_seconds;
                $window_end = $day_start + $end_seconds;

                if ($base_timestamp >= $window_end) {
                    $day_start += DAY_IN_SECONDS;
                    $window_start = $day_start + $start_seconds;
                    $window_end = $day_start + $end_seconds;
                }

                $lower = max($window_start, $base_timestamp + (5 * MINUTE_IN_SECONDS));
                if ($lower >= $window_end) {
                    $day_start += DAY_IN_SECONDS;
                    $window_start = $day_start + $start_seconds;
                    $window_end = $day_start + $end_seconds;
                    $lower = $window_start;
                }

                $next_timestamp = rand($lower, $window_end);
                return self::format_timestamp_for_db($next_timestamp);
            }

            $interval = max(1, intval($generator['interval_minutes']));
            $jitter = max(0, intval($generator['jitter_minutes']));
            $delay_minutes = $interval;
            if ($jitter > 0) {
                $delay_minutes += rand(0, $jitter);
            }
            return self::format_timestamp_for_db($base_timestamp + ($delay_minutes * MINUTE_IN_SECONDS));
        }

        public static function update_generator_schedule($generator_id)
        {
            $generator = self::get_generator($generator_id);
            if (!$generator) {
                return;
            }

            global $wpdb;
            $next_run_at = null;
            if ($generator['status'] === 'active') {
                $next_run_at = self::schedule_next_run_for_generator($generator);
            }

            $wpdb->update(
                self::$table_generators,
                array(
                    'next_run_at' => $next_run_at,
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => intval($generator_id)),
                array('%s', '%s'),
                array('%d')
            );
        }

        public static function seed_next_run_for_active_generators()
        {
            $generators = self::get_generators(500);
            foreach ($generators as $generator) {
                if ($generator['status'] !== 'active') {
                    continue;
                }
                if (empty($generator['next_run_at'])) {
                    self::update_generator_schedule($generator['id']);
                }
            }
        }

        public static function build_post_data($generator, $article, $item = array())
        {
            $post_type = post_type_exists($generator['post_type']) ? $generator['post_type'] : 'post';
            $post_status = in_array($generator['post_status'], array('publish', 'draft', 'pending', 'private', 'future'), true) ? $generator['post_status'] : 'draft';
            $author_id = intval($generator['author_id']);
            if ($author_id <= 0) {
                $author_id = get_current_user_id();
            }

            $forced_slug = '';
            if (!empty($item['final_slug'])) {
                $forced_slug = trim((string) $item['final_slug']);
            }

            $post_title = isset($article['title']) ? trim((string) $article['title']) : '';
            if ($post_title === '' && !empty($item['source_title'])) {
                $post_title = trim((string) $item['source_title']);
            }
            if ($post_title === '' && !empty($item['keyword'])) {
                $post_title = sanitize_text_field($item['keyword']);
            }
            $post_title = Alpha_RSS_AI_Generator_Helper::normalize_generated_title($post_title, !empty($item['source_title']) ? $item['source_title'] : '');
            $post_slug = $forced_slug !== '' ? $forced_slug : (!empty($article['slug']) ? $article['slug'] : sanitize_title($post_title));

            $default_tags = json_decode((string) $generator['tags_default'], true);
            if (!is_array($default_tags) || empty($default_tags)) {
                $article['tags'] = array();
            }

            $post_data = array(
                'post_type' => $post_type,
                'post_status' => $post_status,
                'post_author' => $author_id,
                'post_title' => $post_title,
                'post_name' => $post_slug,
                'post_content' => isset($article['content_html']) ? $article['content_html'] : '',
                'post_excerpt' => isset($article['excerpt']) ? $article['excerpt'] : '',
            );

            if (!empty($generator['source_type']) && $generator['source_type'] === 'keyword_list' && !empty($item['date'])) {
                $post_date = self::bulk_parse_timestamp_value($item['date']);
                if ($post_date !== '') {
                    $post_data['post_date'] = $post_date;
                    $post_data['post_date_gmt'] = get_gmt_from_date($post_date);
                    $post_data['post_modified'] = $post_date;
                    $post_data['post_modified_gmt'] = get_gmt_from_date($post_date);
                }
            }

            return $post_data;
        }

        public static function apply_taxonomies_and_meta($post_id, $generator, $article, $item)
        {
            $categories = json_decode((string) $generator['category_ids'], true);
            if (is_array($categories) && !empty($categories) && taxonomy_exists('category')) {
                $category_ids = array_values(array_filter(array_map('intval', $categories)));
                if (!empty($category_ids)) {
                    wp_set_object_terms($post_id, $category_ids, 'category', false);
                }
            }

            $default_tags = json_decode((string) $generator['tags_default'], true);
            $default_tags = is_array($default_tags) ? array_values(array_filter(array_map('sanitize_text_field', $default_tags))) : array();
            $tags = array();
            if (!empty($default_tags)) {
                $tags = self::filter_tags_against_selected_pool(!empty($article['tags']) && is_array($article['tags']) ? $article['tags'] : array(), $default_tags, 4);
            }
            if (!empty($tags) && taxonomy_exists('post_tag') && is_object_in_taxonomy(get_post_type($post_id), 'post_tag')) {
                wp_set_post_terms($post_id, $tags, 'post_tag', false);
            }

            $taxonomies = json_decode((string) $generator['custom_taxonomies'], true);
            if (is_array($taxonomies)) {
                foreach ($taxonomies as $taxonomy => $terms_csv) {
                    $taxonomy = sanitize_key($taxonomy);
                    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                        continue;
                    }
                    $terms = self::parse_list_field($terms_csv);
                    if (!empty($terms)) {
                        wp_set_object_terms($post_id, $terms, $taxonomy, false);
                    }
                }
            }

            $custom_meta = json_decode((string) $generator['custom_meta'], true);
            if (is_array($custom_meta)) {
                foreach ($custom_meta as $meta_key => $meta_value) {
                    $meta_key = sanitize_key($meta_key);
                    if ($meta_key !== '') {
                        update_post_meta($post_id, $meta_key, sanitize_text_field($meta_value));
                    }
                }
            }

            update_post_meta($post_id, '_arc_source_feed_url', $generator['feed_url']);
            update_post_meta($post_id, '_arc_source_item_guid', $item['guid']);
            update_post_meta($post_id, '_arc_source_item_permalink', $item['permalink']);
            update_post_meta($post_id, '_arc_source_item_title', $item['title']);
            if (!empty($item['keyword'])) {
                update_post_meta($post_id, '_arc_source_keyword', sanitize_text_field($item['keyword']));
            }
            if (!empty($item['source_title'])) {
                update_post_meta($post_id, '_arc_source_title', sanitize_text_field($item['source_title']));
            }
            if (!empty($item['source_url'])) {
                update_post_meta($post_id, '_arc_source_url', esc_url_raw($item['source_url']));
            }
            if (!empty($item['final_slug'])) {
                update_post_meta($post_id, '_arc_source_final_slug', sanitize_title($item['final_slug']));
            }
            if (!empty($generator['list_id'])) {
                update_post_meta($post_id, '_arc_source_list_id', intval($generator['list_id']));
            }
            if (!empty($generator['source_type'])) {
                update_post_meta($post_id, '_arc_source_type', sanitize_key($generator['source_type']));
            }
            if (!empty($item['source_image_url'])) {
                update_post_meta($post_id, '_arc_source_image_url', esc_url_raw($item['source_image_url']));
            }
            if (!empty($item['source_link_url'])) {
                update_post_meta($post_id, '_arc_source_link_url', esc_url_raw($item['source_link_url']));
            }
            if (!empty($item['source_link_text'])) {
                update_post_meta($post_id, '_arc_source_link_text', sanitize_text_field($item['source_link_text']));
            }
            if (!empty($item['source_image_selector_class'])) {
                update_post_meta($post_id, '_arc_source_image_selector_class', sanitize_text_field($item['source_image_selector_class']));
            }
            if (!empty($item['source_link_selector_class'])) {
                update_post_meta($post_id, '_arc_source_link_selector_class', sanitize_text_field($item['source_link_selector_class']));
            }
            if (!empty($generator['content_image_size'])) {
                update_post_meta($post_id, '_arc_content_image_size', self::normalize_image_display_size((string) $generator['content_image_size']));
            }
            if (!empty($item['source_page_title'])) {
                update_post_meta($post_id, '_arc_source_page_title', sanitize_text_field($item['source_page_title']));
            }
            if (!empty($item['source_page_excerpt'])) {
                update_post_meta($post_id, '_arc_source_page_excerpt', sanitize_text_field($item['source_page_excerpt']));
            }
            if (!empty($item['source_page_content'])) {
                update_post_meta($post_id, '_arc_source_page_content', wp_strip_all_tags($item['source_page_content']));
            }
            if (!empty($item['source_page_outline'])) {
                update_post_meta($post_id, '_arc_source_page_outline', sanitize_textarea_field($item['source_page_outline']));
            }
            if (!empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])) {
                update_post_meta($post_id, '_arc_source_page_outline_sections', wp_json_encode($item['source_page_outline_sections'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (!empty($item['source_video_url'])) {
                update_post_meta($post_id, '_arc_source_video_url', esc_url_raw($item['source_video_url']));
            }
            if (!empty($item['source_video_embed_html'])) {
                update_post_meta($post_id, '_arc_source_video_embed_html', $item['source_video_embed_html']);
            }
            if (!empty($item['source_video_source'])) {
                update_post_meta($post_id, '_arc_source_video_source', sanitize_text_field($item['source_video_source']));
            }
            if (!empty($item['date'])) {
                update_post_meta($post_id, '_arc_source_timestamp', sanitize_text_field($item['date']));
            }
            update_post_meta($post_id, '_arc_generator_id', intval($generator['id']));

            if (!empty($generator['seo_enabled'])) {
                self::sync_seo_meta($post_id, $generator, $article);
            }
        }

        public static function sync_seo_meta($post_id, $generator, $article)
        {
            $seo_title = $article['title'];
            $meta_description = $article['meta_description'];
            $focus_keyword = !empty($article['focus_keyword']) ? $article['focus_keyword'] : $article['title'];

            update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);

            update_post_meta($post_id, 'rank_math_title', $seo_title);
            update_post_meta($post_id, 'rank_math_description', $meta_description);
            update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);

            update_post_meta($post_id, '_wds_title', $seo_title);
            update_post_meta($post_id, '_wds_metadesc', $meta_description);
            update_post_meta($post_id, '_wds_focus_keyword', $focus_keyword);

            update_post_meta($post_id, '_aioseo_title', $seo_title);
            update_post_meta($post_id, '_aioseo_description', $meta_description);
            update_post_meta($post_id, '_aioseo_focus_keyword', $focus_keyword);
        }

        public static function build_pexels_query($generator, $item, $article)
        {
            $query = trim((string) $generator['pexels_query']);
            if ($query === '' || $query === '{title}' || $query === '{{title}}') {
                $query = self::get_default_pexels_query();
            }

            $pexels_tags = array();
            if (!empty($article['pexels_tags']) && is_array($article['pexels_tags'])) {
                $pexels_tags = $article['pexels_tags'];
            } elseif (!empty($article['tags']) && is_array($article['tags'])) {
                $pexels_tags = $article['tags'];
            }
            $pexels_tags = self::filter_pexels_tags(array_values(array_unique(array_filter(array_map('sanitize_text_field', $pexels_tags)))), 4);
            $selected_tags = self::get_generator_selected_tags($generator);
            if (empty($pexels_tags) && !empty($selected_tags)) {
                $pexels_tags = self::filter_pexels_tags($selected_tags, 4);
            }
            if (empty($pexels_tags)) {
                $fallback_terms = array();
                if (!empty($article['focus_keyword'])) {
                    $fallback_terms[] = $article['focus_keyword'];
                }
                if (!empty($article['title'])) {
                    $fallback_terms[] = $article['title'];
                }
                $fallback_terms = self::filter_pexels_tags($fallback_terms, 4);
                if (!empty($fallback_terms)) {
                    $pexels_tags = $fallback_terms;
                }
            }
            $pexels_search_terms = trim(implode(' ', array_slice($pexels_tags, 0, 4)));

            $query = strtr($query, array(
                '{{title}}' => $article['title'],
                '{{source_title}}' => $item['title'],
                '{{feed_title}}' => $item['feed_title'],
                '{{focus_keyword}}' => $article['focus_keyword'],
                '{{pexels_tags}}' => $pexels_search_terms,
                '{{pexels_tags_csv}}' => implode(', ', array_slice($pexels_tags, 0, 4)),
            ));
            $query = str_replace(array(',', ';'), ' ', $query);
            $query = preg_replace('/\s+/', ' ', $query);
            $query = trim($query);

            if ($query === '') {
                $query = $pexels_search_terms;
            }

            return $query;
        }

        public static function download_and_set_featured_image_from_pexels($post_id, $generator, $item, $article, $required = false)
        {
            $settings = self::get_settings();
            $api_key = trim((string) $settings['pexels_api_key']);
            if ($api_key === '' || empty($generator['pexels_enabled'])) {
                self::log_image_debug('pexels_skipped', array(
                    'post_id' => intval($post_id),
                    'required' => $required ? 1 : 0,
                    'pexels_enabled' => !empty($generator['pexels_enabled']) ? 1 : 0,
                    'has_api_key' => $api_key !== '' ? 1 : 0,
                    'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                ));
                if ($required) {
                    return new WP_Error('arc_pexels_required', 'A chave da API do Pexels precisa estar configurada para geradores de planilha.');
                }
                return false;
            }

            $query = self::build_pexels_query($generator, $item, $article);
            if ($query === '') {
                self::log_image_debug('pexels_empty_query', array(
                    'post_id' => intval($post_id),
                    'required' => $required ? 1 : 0,
                    'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                    'title' => !empty($article['title']) ? $article['title'] : '',
                ));
                return false;
            }

            self::log_image_debug('pexels_start', array(
                'post_id' => intval($post_id),
                'query' => $query,
                'required' => $required ? 1 : 0,
                'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
            ));

            $url = add_query_arg(array(
                'query' => $query,
                'per_page' => 1,
                'orientation' => 'landscape',
            ), 'https://api.pexels.com/v1/search');

            $response = wp_remote_get($url, array(
                'timeout' => 60,
                'headers' => array(
                    'Authorization' => $api_key,
                ),
            ));

            if (is_wp_error($response)) {
                self::log_image_debug('pexels_request_failed', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                    'error' => $response->get_error_message(),
                ));
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                self::log_image_debug('pexels_bad_status', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                    'status_code' => $code,
                ));
                return false;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($data['photos'][0]['src'])) {
                self::log_image_debug('pexels_no_photos', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                ));
                return false;
            }

            $photo = $data['photos'][0];
            $image_url = '';
            if (!empty($photo['src']['large'])) {
                $image_url = $photo['src']['large'];
            } elseif (!empty($photo['src']['original'])) {
                $image_url = $photo['src']['original'];
            }

            if ($image_url === '') {
                self::log_image_debug('pexels_no_image_url', array(
                    'post_id' => intval($post_id),
                    'query' => $query,
                ));
                return false;
            }

            self::log_image_debug('pexels_image_selected', array(
                'post_id' => intval($post_id),
                'query' => $query,
                'image_url' => $image_url,
                'photographer' => !empty($photo['photographer']) ? $photo['photographer'] : '',
            ));

            return self::download_and_set_featured_image_from_url(
                $post_id,
                $image_url,
                $article['title'],
                'pexels',
                $query,
                !empty($photo['photographer']) ? sanitize_text_field($photo['photographer']) : ''
            );
        }

        public static function build_dalle_prompt($generator, $item, $article)
        {
            $parts = array();

            if (!empty($article['title'])) {
                $parts[] = 'Artigo: ' . trim((string) $article['title']);
            }
            if (!empty($item['keyword'])) {
                $parts[] = 'Keyword: ' . trim((string) $item['keyword']);
            }
            if (!empty($article['focus_keyword'])) {
                $parts[] = 'Foco: ' . trim((string) $article['focus_keyword']);
            }

            $context = implode('. ', array_filter($parts));
            if ($context === '') {
                $context = 'news article illustration';
            }

            return trim(
                'Create a clean editorial featured image for a WordPress article. ' .
                    $context . '. ' .
                    'Photorealistic, high quality, horizontal composition, no text, no watermark, no logo.'
            );
        }

        public static function download_and_set_featured_image_from_dalle($post_id, $generator, $item, $article, $required = false)
        {
            $settings = self::get_settings();
            $api_key = trim((string) $settings['openai_api_key']);
            if ($api_key === '') {
                self::log_image_debug('dalle_skipped_no_api_key', array(
                    'post_id' => intval($post_id),
                    'required' => $required ? 1 : 0,
                    'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                    'title' => !empty($article['title']) ? $article['title'] : '',
                ));
                if ($required) {
                    return new WP_Error('arc_dalle_required', 'A chave da API da OpenAI precisa estar configurada para usar Dall-e.');
                }
                return false;
            }

            $prompt = self::build_dalle_prompt($generator, $item, $article);
            if ($prompt === '') {
                self::log_image_debug('dalle_empty_prompt', array(
                    'post_id' => intval($post_id),
                    'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                ));
                return false;
            }

            self::log_image_debug('dalle_start', array(
                'post_id' => intval($post_id),
                'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                'title' => !empty($article['title']) ? $article['title'] : '',
            ));

            $response = wp_remote_post('https://api.openai.com/v1/images/generations', array(
                'timeout' => 120,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode(array(
                    'model' => 'dall-e-3',
                    'prompt' => $prompt,
                    'size' => '1024x1024',
                    'n' => 1,
                    'response_format' => 'url',
                )),
            ));

            if (is_wp_error($response)) {
                self::log_image_debug('dalle_request_failed', array(
                    'post_id' => intval($post_id),
                    'error' => $response->get_error_message(),
                ));
                return false;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                self::log_image_debug('dalle_bad_status', array(
                    'post_id' => intval($post_id),
                    'status_code' => $code,
                ));
                return false;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            $image_url = '';
            if (!empty($data['data'][0]['url'])) {
                $image_url = esc_url_raw(trim((string) $data['data'][0]['url']));
            } elseif (!empty($data['data'][0]['b64_json'])) {
                self::log_image_debug('dalle_b64_received', array(
                    'post_id' => intval($post_id),
                    'length' => strlen((string) $data['data'][0]['b64_json']),
                ));
                $tmp = wp_upload_bits('dalle-' . time() . '.png', null, base64_decode((string) $data['data'][0]['b64_json']));
                if (!empty($tmp['error'])) {
                    self::log_image_debug('dalle_write_failed', array(
                        'post_id' => intval($post_id),
                        'error' => $tmp['error'],
                    ));
                    return false;
                }
                $image_url = !empty($tmp['url']) ? esc_url_raw($tmp['url']) : '';
            }

            if ($image_url === '') {
                self::log_image_debug('dalle_no_image_url', array(
                    'post_id' => intval($post_id),
                ));
                return false;
            }

            self::log_image_debug('dalle_image_selected', array(
                'post_id' => intval($post_id),
                'image_url' => $image_url,
            ));

            return self::download_and_set_featured_image_from_url(
                $post_id,
                $image_url,
                $article['title'],
                'dalle',
                $prompt,
                ''
            );
        }

        public static function maybe_set_source_featured_image($post_id, $item, $article)
        {
            if (empty($item['source_image_url'])) {
                self::log_image_debug('source_image_missing', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'title' => !empty($article['title']) ? $article['title'] : '',
                ));
                return false;
            }

            self::log_image_debug('source_image_start', array(
                'post_id' => intval($post_id),
                'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                'source_image_url' => $item['source_image_url'],
                'title' => !empty($article['title']) ? $article['title'] : '',
            ));

            if (self::is_probably_bad_featured_image_url($item['source_image_url'], !empty($article['title']) ? $article['title'] : '')) {
                self::log_image_debug('source_image_rejected', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'source_image_url' => $item['source_image_url'],
                    'title' => !empty($article['title']) ? $article['title'] : '',
                ));
                return false;
            }

            return self::download_and_set_featured_image_from_url(
                $post_id,
                $item['source_image_url'],
                $article['title'],
                'source',
                '',
                ''
            );
        }

        public static function create_post_from_generator_item($generator, $item)
        {
            $item = self::maybe_enrich_rss_item_context($generator, $item);
            $use_source_page_context = self::generator_uses_source_page_context($generator);
            if ($use_source_page_context) {
                $item = self::resolve_item_media_for_generation($generator, $item);
            }

            $article = Alpha_RSS_AI_Generator_Helper::call_openai($generator, $item);
            if (is_wp_error($article)) {
                return $article;
            }

            $is_keyword_list = !empty($generator['source_type']) && $generator['source_type'] === 'keyword_list';
            $is_keyword_list_url_reference = self::generator_uses_keyword_list_url_reference_mode($generator);
            $treat_like_rss = !$is_keyword_list || $is_keyword_list_url_reference;
            if ($is_keyword_list && !$is_keyword_list_url_reference) {
                $item['source_image_url'] = '';
            }

            if (!$use_source_page_context) {
                $item = self::resolve_item_media_for_generation($generator, $item);
                if ($is_keyword_list && !$is_keyword_list_url_reference) {
                    $item['source_image_url'] = '';
                }
            }

            if (!empty($item['final_slug'])) {
                $article['slug'] = sanitize_title($item['final_slug']);
            }
            if (!empty($item['keyword']) && empty($article['focus_keyword'])) {
                $article['focus_keyword'] = sanitize_text_field($item['keyword']);
            }

            $use_source_video = !empty($generator['source_video_enabled']);
            $source_video_embed_html = '';
            $source_video_url = '';
            if ($treat_like_rss && $use_source_video) {
                $source_video_embed_html = !empty($item['source_video_embed_html']) ? trim((string) $item['source_video_embed_html']) : '';
                $source_video_url = !empty($item['source_video_url']) ? esc_url_raw(trim((string) $item['source_video_url'])) : '';
            }

            $article['content_html'] = self::convert_html_fragment_to_gutenberg_blocks(
                isset($article['content_html']) ? $article['content_html'] : '',
                $source_video_embed_html,
                $source_video_url
            );

            $post_data = self::build_post_data($generator, $article, $item);
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                return $post_id;
            }

            if (!empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])) {
                $content_image_size = !empty($generator['content_image_size']) ? self::normalize_image_display_size((string) $generator['content_image_size']) : 'medium';
                $article['content_html'] = Alpha_RSS_AI_Generator_Helper::inject_outline_section_media_into_content(
                    $article['content_html'],
                    $item['source_page_outline_sections'],
                    $post_id,
                    $content_image_size,
                    !empty($generator['source_link_phrases']) ? $generator['source_link_phrases'] : ''
                );
                if ($article['content_html'] !== '') {
                    $update_content = wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $article['content_html'],
                    ), true);
                    if (is_wp_error($update_content)) {
                        self::insert_run_log($generator['id'], 'warning', $update_content->get_error_message(), array(
                            'request' => array(
                                'post_id' => $post_id,
                                'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                            ),
                        ), $post_id, $item['guid'], $item['permalink']);
                    }
                }
            }

            self::apply_taxonomies_and_meta($post_id, $generator, $article, $item);

            $has_source_image = !empty($item['source_image_url']);
            $source_image_set = false;
            $image_source_mode = !empty($generator['image_source_mode'])
                ? sanitize_key((string) $generator['image_source_mode'])
                : self::normalize_image_source_mode(
                    !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
                    '',
                    isset($generator['pexels_enabled']) ? !empty($generator['pexels_enabled']) : null,
                    !empty($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : self::get_default_keyword_list_mode()
                );

            $use_source_image = $treat_like_rss && self::image_source_mode_uses_source_image($image_source_mode);
            $use_pexels = self::image_source_mode_uses_pexels($image_source_mode);
            $use_dalle = self::image_source_mode_uses_dalle($image_source_mode);
            self::log_image_debug('image_pipeline_start', array(
                'post_id' => intval($post_id),
                'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : '',
                'image_source_mode' => $image_source_mode,
                'has_source_image' => $has_source_image ? 1 : 0,
                'pexels_enabled' => $use_pexels ? 1 : 0,
                'dalle_enabled' => $use_dalle ? 1 : 0,
                'keyword' => !empty($item['keyword']) ? $item['keyword'] : '',
                'treat_like_rss' => $treat_like_rss ? 1 : 0,
            ));
            self::log_image_debug('source_image_candidate', array(
                'post_id' => intval($post_id),
                'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                'has_source_image' => $has_source_image ? 1 : 0,
                'source_image_url' => !empty($item['source_image_url']) ? $item['source_image_url'] : '',
                'source_context_enriched' => !empty($item['source_context_enriched']) ? 1 : 0,
                'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : '',
                'image_source_mode' => $image_source_mode,
            ));
            if ($use_source_image && $has_source_image) {
                $source_image_set = (bool) self::maybe_set_source_featured_image($post_id, $item, $article);
                if (!$source_image_set) {
                    self::log_image_debug('source_image_fallback_to_external', array(
                        'post_id' => intval($post_id),
                        'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                        'source_image_url' => !empty($item['source_image_url']) ? $item['source_image_url'] : '',
                        'image_source_mode' => $image_source_mode,
                    ));
                    self::insert_run_log($generator['id'], 'warning', 'Falha ao inserir imagem da fonte; tentando fallback', array(
                        'request' => array(
                            'post_id' => $post_id,
                            'item_guid' => $item['guid'],
                        ),
                        'response' => array(
                            'source_image_url' => !empty($item['source_image_url']) ? $item['source_image_url'] : '',
                            'permalink' => !empty($item['permalink']) ? $item['permalink'] : '',
                        ),
                    ), $post_id, $item['guid'], $item['permalink']);
                }
            }
            $needs_fallback_image = !$has_source_image || !$source_image_set;
            if ($needs_fallback_image && $use_pexels) {
                self::log_image_debug('pexels_attempt', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'is_keyword_list' => $is_keyword_list ? 1 : 0,
                    'has_source_image' => $has_source_image ? 1 : 0,
                    'source_image_set' => $source_image_set ? 1 : 0,
                    'image_source_mode' => $image_source_mode,
                ));
                $pexels_result = self::download_and_set_featured_image_from_pexels($post_id, $generator, $item, $article, $is_keyword_list);
                if (is_wp_error($pexels_result)) {
                    if ($is_keyword_list && !$is_keyword_list_url_reference) {
                        return $pexels_result;
                    }
                    self::insert_run_log($generator['id'], 'warning', $pexels_result->get_error_message(), array(
                        'request' => array(
                            'post_id' => $post_id,
                            'item_guid' => $item['guid'],
                        ),
                        'response' => array(
                            'permalink' => !empty($item['permalink']) ? $item['permalink'] : '',
                        ),
                    ), $post_id, $item['guid'], $item['permalink']);
                }
            } elseif ($needs_fallback_image && $use_dalle) {
                self::log_image_debug('dalle_attempt', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'is_keyword_list' => $is_keyword_list ? 1 : 0,
                    'has_source_image' => $has_source_image ? 1 : 0,
                    'source_image_set' => $source_image_set ? 1 : 0,
                    'image_source_mode' => $image_source_mode,
                ));
                $dalle_result = self::download_and_set_featured_image_from_dalle($post_id, $generator, $item, $article, $is_keyword_list);
                if (is_wp_error($dalle_result)) {
                    if ($is_keyword_list && !$is_keyword_list_url_reference) {
                        return $dalle_result;
                    }
                    self::insert_run_log($generator['id'], 'warning', $dalle_result->get_error_message(), array(
                        'request' => array(
                            'post_id' => $post_id,
                            'item_guid' => $item['guid'],
                        ),
                        'response' => array(
                            'permalink' => !empty($item['permalink']) ? $item['permalink'] : '',
                        ),
                    ), $post_id, $item['guid'], $item['permalink']);
                }
            } elseif ($needs_fallback_image) {
                self::log_image_debug('image_fallback_skipped', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'has_source_image' => $has_source_image ? 1 : 0,
                    'source_image_set' => $source_image_set ? 1 : 0,
                    'image_source_mode' => $image_source_mode,
                    'pexels_enabled' => $use_pexels ? 1 : 0,
                    'dalle_enabled' => $use_dalle ? 1 : 0,
                ));
            } else {
                // Fonte já inserida com sucesso; não faz fallback.
            }
            if ($treat_like_rss && $use_source_video) {
                self::insert_run_log($generator['id'], 'info', 'Checagem de vídeo da fonte', array(
                    'request' => array(
                        'post_id' => $post_id,
                        'item_guid' => $item['guid'],
                    ),
                    'response' => array(
                        'source_video_url' => !empty($item['source_video_url']) ? $item['source_video_url'] : '',
                        'has_source_video' => !empty($item['source_video_url']) ? 1 : 0,
                        'video_selector_class' => !empty($generator['video_selector_class']) ? $generator['video_selector_class'] : '',
                        'permalink' => !empty($item['permalink']) ? $item['permalink'] : '',
                    ),
                ), $post_id, $item['guid'], $item['permalink']);
            }
            self::mark_item_processed($generator['id'], $item, $post_id);
            self::insert_run_log($generator['id'], 'success', 'Post criado', array(
                'request' => array('item' => $item['guid']),
                'response' => array('post_id' => $post_id, 'title' => $article['title']),
            ), $post_id, $item['guid'], $item['permalink']);

            return $post_id;
        }

        public function cron_tick()
        {
            if (get_transient('alpha_rss_ai_cron_lock')) {
                return;
            }
            set_transient('alpha_rss_ai_cron_lock', 1, 4 * MINUTE_IN_SECONDS);

            self::process_due_generators();

            delete_transient('alpha_rss_ai_cron_lock');
        }

        public static function process_due_generators($only_id = 0)
        {
            global $wpdb;

            $now = current_time('mysql');
            $sql = "SELECT * FROM " . self::$table_generators . " WHERE status = 'active'";
            $params = array();
            if ($only_id > 0) {
                $sql .= " AND id = %d";
                $params[] = intval($only_id);
            } else {
                $sql .= " AND (next_run_at IS NULL OR next_run_at <= %s)";
                $params[] = $now;
            }
            $sql .= " ORDER BY COALESCE(next_run_at, created_at) ASC LIMIT 10";

            $generators = $params ? $wpdb->get_results(call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $params)), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

            foreach ($generators as $generator) {
                self::run_generator($generator, false);
            }
        }

        public static function run_keyword_list_generator($generator, $manual = false)
        {
            global $wpdb;
            $list_id = !empty($generator['list_id']) ? intval($generator['list_id']) : 0;
            if ($list_id <= 0) {
                self::insert_run_log($generator['id'], 'error', 'Gerador sem lista vinculada', array(
                    'request' => array('manual' => $manual),
                ));
                self::update_next_run_after_attempt($generator);
                return new WP_Error('arc_missing_list', 'Este gerador não possui uma lista vinculada.');
            }

            $list = self::get_keyword_list($list_id);
            if (!$list) {
                self::insert_run_log($generator['id'], 'error', 'Lista vinculada nao encontrada', array(
                    'request' => array('manual' => $manual, 'list_id' => $list_id),
                ));
                self::update_next_run_after_attempt($generator);
                return new WP_Error('arc_missing_list', 'Lista vinculada não encontrada.');
            }

            $filters = array();
            if (!empty($generator['filters_json'])) {
                $decoded_filters = json_decode((string) $generator['filters_json'], true);
                if (is_array($decoded_filters)) {
                    $filters = $decoded_filters;
                }
            }
            $source_context_filters = self::get_generator_source_context_filters($generator);

            $created = 0;
            $skipped = 0;
            $failed = 0;
            $limit = max(1, intval($generator['posts_per_run']));
            $scan_offset = 0;
            $scan_limit = 250;
            $tables = self::bulk_tables();
            $video_selector_class = !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '';

            while ($created < $limit) {
                $pending_rows = self::bulk_get_pending_rows_batch($list_id, $scan_limit, $scan_offset);
                if (empty($pending_rows)) {
                    break;
                }

                foreach ($pending_rows as $row) {
                    if ($created >= $limit) {
                        break 2;
                    }

                    $row_data = json_decode($row->row_data, true);
                    if (!is_array($row_data)) {
                        continue;
                    }

                    if (intval($row->slug_is_valid) !== 1) {
                        $wpdb->update(
                            $tables['rows'],
                            array(
                                'row_status' => 'invalid_slug',
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => intval($row->id)),
                            array('%s', '%s'),
                            array('%d')
                        );
                        continue;
                    }

                    if (!self::bulk_row_matches_filters($row_data, $filters)) {
                        $skipped++;
                        continue;
                    }

                    $selected_item = self::build_keyword_list_item_from_row(
                        $list,
                        $row,
                        false,
                        $video_selector_class,
                        !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                        !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                        $source_context_filters
                    );
                    if (!empty($source_context_filters) && !Alpha_RSS_AI_Generator_Helper::source_context_item_matches_filters($selected_item, $source_context_filters)) {
                        $skipped++;
                        continue;
                    }
                    if (self::is_item_processed($generator['id'], $selected_item['guid'])) {
                        $skipped++;
                        continue;
                    }

                    $wpdb->update(
                        $tables['rows'],
                        array(
                            'row_status' => 'processing',
                            'updated_at' => current_time('mysql'),
                        ),
                        array('id' => intval($row->id)),
                        array('%s', '%s'),
                        array('%d')
                    );
                    $result = self::create_post_from_generator_item($generator, $selected_item);
                    if (is_wp_error($result)) {
                        $failed++;
                        $wpdb->update(
                            $tables['rows'],
                            array(
                                'row_status' => 'failed',
                                'error_message' => $result->get_error_message(),
                                'updated_at' => current_time('mysql'),
                            ),
                            array('id' => intval($row->id)),
                            array('%s', '%s', '%s'),
                            array('%d')
                        );
                        self::insert_run_log($generator['id'], 'error', $result->get_error_message(), array(
                            'request' => array('row_id' => intval($row->id), 'list_id' => $list_id),
                        ), null, $selected_item['guid'], $selected_item['permalink']);
                        continue;
                    }

                    $wpdb->update(
                        $tables['rows'],
                        array(
                            'row_status' => 'generated',
                            'post_id' => intval($result),
                            'error_message' => '',
                            'updated_at' => current_time('mysql'),
                            'processed_at' => current_time('mysql'),
                        ),
                        array('id' => intval($row->id)),
                        array('%s', '%d', '%s', '%s', '%s'),
                        array('%d')
                    );

                    $created++;
                }

                $scan_offset += $scan_limit;
            }

            $message = sprintf('Criados %d post(s), ignorados %d item(s), falharam %d item(s).', $created, $skipped, $failed);
            self::insert_run_log($generator['id'], 'success', $message, array(
                'request' => array('manual' => $manual, 'source_type' => 'keyword_list', 'list_id' => $list_id, 'filters' => $filters),
                'response' => array('created' => $created, 'skipped' => $skipped, 'failed' => $failed),
            ));

            self::bulk_refresh_list_counts($list_id);
            self::update_next_run_after_attempt($generator);

            return array(
                'created' => $created,
                'skipped' => $skipped,
                'failed' => $failed,
            );
        }

        public static function run_generator($generator, $manual = false)
        {
            if (!empty($generator['source_type']) && $generator['source_type'] === 'keyword_list') {
                return self::run_keyword_list_generator($generator, $manual);
            }

            $items = self::get_rss_items_for_generator(
                $generator,
                max(10, intval($generator['posts_per_run']) * 5),
                false,
                !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '',
                !empty($generator['image_selector_class']) ? sanitize_text_field((string) $generator['image_selector_class']) : '',
                !empty($generator['link_selector_class']) ? sanitize_text_field((string) $generator['link_selector_class']) : '',
                self::get_generator_source_context_filters($generator)
            );
            if (is_wp_error($items)) {
                self::insert_run_log($generator['id'], 'error', $items->get_error_message(), array(
                    'request' => array('feed_url' => $generator['feed_url']),
                ));
                self::update_next_run_after_attempt($generator);
                return $items;
            }

            $created = 0;
            $skipped = 0;
            $failed = 0;
            $limit = max(1, intval($generator['posts_per_run']));

            foreach ($items as $item) {
                if ($created >= $limit) {
                    break;
                }

                if (self::is_item_processed($generator['id'], $item['guid'])) {
                    $skipped++;
                    continue;
                }

                $result = self::create_post_from_generator_item($generator, $item);
                if (is_wp_error($result)) {
                    $failed++;
                    self::insert_run_log($generator['id'], 'error', $result->get_error_message(), array(
                        'request' => array('guid' => $item['guid']),
                    ), null, $item['guid'], $item['permalink']);
                    continue;
                }

                $created++;
            }

            $message = sprintf('Criados %d post(s), ignorados %d item(s) já processados, falharam %d item(s).', $created, $skipped, $failed);
            self::insert_run_log($generator['id'], 'success', $message, array(
                'request' => array('manual' => $manual),
                'response' => array('created' => $created, 'skipped' => $skipped, 'failed' => $failed),
            ));

            self::update_next_run_after_attempt($generator);
            return array(
                'created' => $created,
                'skipped' => $skipped,
                'failed' => $failed,
            );
        }

        public static function update_next_run_after_attempt($generator)
        {
            global $wpdb;
            $next_run_at = self::schedule_next_run_for_generator($generator);
            $wpdb->update(
                self::$table_generators,
                array(
                    'last_run_at' => current_time('mysql'),
                    'next_run_at' => $generator['status'] === 'active' ? $next_run_at : null,
                    'updated_at' => current_time('mysql'),
                ),
                array('id' => intval($generator['id'])),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }

        public static function save_generator($raw)
        {
            global $wpdb;
            $payload = self::normalize_generator_payload($raw);
            if (is_wp_error($payload)) {
                return $payload;
            }

            $generator_id = isset($raw['generator_id']) ? intval($raw['generator_id']) : 0;
            $now = current_time('mysql');

            $data = array(
                'name' => $payload['name'],
                'feed_url' => $payload['feed_url'],
                'source_type' => $payload['source_type'],
                'list_id' => $payload['list_id'],
                'keyword_list_mode' => $payload['keyword_list_mode'],
                'status' => $payload['status'],
                'post_type' => $payload['post_type'],
                'post_status' => $payload['post_status'],
                'author_id' => $payload['author_id'],
                'category_ids' => $payload['category_ids'],
                'tags_default' => $payload['tags_default'],
                'custom_taxonomies' => $payload['custom_taxonomies'],
                'custom_meta' => $payload['custom_meta'],
                'filters_json' => $payload['filters_json'],
                'model' => $payload['model'],
                'temperature' => $payload['temperature'],
                'max_tokens' => $payload['max_tokens'],
                'posts_per_run' => $payload['posts_per_run'],
                'schedule_type' => $payload['schedule_type'],
                'interval_minutes' => $payload['interval_minutes'],
                'jitter_minutes' => $payload['jitter_minutes'],
                'daily_start' => $payload['daily_start'],
                'daily_end' => $payload['daily_end'],
                'image_source_mode' => $payload['image_source_mode'],
                'pexels_enabled' => $payload['pexels_enabled'],
                'pexels_query' => $payload['pexels_query'],
                'source_video_enabled' => $payload['source_video_enabled'],
                'video_selector_class' => $payload['video_selector_class'],
                'image_selector_class' => $payload['image_selector_class'],
                'link_selector_class' => $payload['link_selector_class'],
                'content_image_size' => $payload['content_image_size'],
                'source_link_phrases' => $payload['source_link_phrases'],
                'source_context_filters_json' => $payload['source_context_filters_json'],
                'seo_enabled' => $payload['seo_enabled'],
                'generation_language' => $payload['generation_language'],
                'prompt_template' => $payload['prompt_template'],
                'content_prompt_template' => $payload['content_prompt_template'],
                'related_posts_enabled' => $payload['related_posts_enabled'],
                'related_posts_position' => $payload['related_posts_position'],
                'related_posts_interval' => $payload['related_posts_interval'],
                'related_posts_min_h2' => $payload['related_posts_min_h2'],
                'related_posts_links_per_block' => $payload['related_posts_links_per_block'],
                'related_posts_same_category_only' => $payload['related_posts_same_category_only'],
                'related_posts_allow_fallback' => $payload['related_posts_allow_fallback'],
                'related_posts_style' => $payload['related_posts_style'],
                'related_posts_phrases' => $payload['related_posts_phrases'],
                'updated_at' => $now,
            );

            if ($generator_id > 0) {
                $wpdb->update(
                    self::$table_generators,
                    $data,
                    array('id' => $generator_id)
                );
                self::update_generator_schedule($generator_id);
                return $generator_id;
            }

            $data['created_at'] = $now;
            $data['next_run_at'] = null;
            $wpdb->insert(self::$table_generators, $data);

            $generator_id = intval($wpdb->insert_id);
            self::update_generator_schedule($generator_id);
            return $generator_id;
        }

        public static function delete_generator($id)
        {
            global $wpdb;
            $id = intval($id);
            $wpdb->delete(self::$table_items, array('generator_id' => $id), array('%d'));
            $wpdb->delete(self::$table_runs, array('generator_id' => $id), array('%d'));
            $wpdb->delete(self::$table_generators, array('id' => $id), array('%d'));
        }

        public static function duplicate_generator($id)
        {
            $generator = self::get_generator($id);
            if (!$generator) {
                return new WP_Error('arc_missing_generator', 'Gerador não encontrado.');
            }

            $duplicated = array(
                'name' => $generator['name'] . ' copy',
                'feed_url' => $generator['feed_url'],
                'source_type' => $generator['source_type'],
                'list_id' => $generator['list_id'],
                'keyword_list_mode' => isset($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : self::get_default_keyword_list_mode(),
                'status' => $generator['status'],
                'post_type' => $generator['post_type'],
                'post_status' => $generator['post_status'],
                'author_id' => $generator['author_id'],
                'category_ids' => implode(',', (array) json_decode((string) $generator['category_ids'], true)),
                'tags_default' => implode(',', (array) json_decode((string) $generator['tags_default'], true)),
                'custom_taxonomies' => self::array_to_key_value_lines(json_decode((string) $generator['custom_taxonomies'], true)),
                'custom_meta' => self::array_to_key_value_lines(json_decode((string) $generator['custom_meta'], true)),
                'filters_json' => $generator['filters_json'],
                'model' => $generator['model'],
                'temperature' => $generator['temperature'],
                'max_tokens' => $generator['max_tokens'],
                'posts_per_run' => $generator['posts_per_run'],
                'schedule_type' => $generator['schedule_type'],
                'interval_minutes' => $generator['interval_minutes'],
                'jitter_minutes' => $generator['jitter_minutes'],
                'daily_start' => $generator['daily_start'],
                'daily_end' => $generator['daily_end'],
                'image_source_mode' => isset($generator['image_source_mode']) ? $generator['image_source_mode'] : '',
                'pexels_enabled' => $generator['pexels_enabled'],
                'pexels_query' => $generator['pexels_query'],
                'source_video_enabled' => $generator['source_video_enabled'],
                'video_selector_class' => isset($generator['video_selector_class']) ? $generator['video_selector_class'] : '',
                'image_selector_class' => isset($generator['image_selector_class']) ? $generator['image_selector_class'] : '',
                'link_selector_class' => isset($generator['link_selector_class']) ? $generator['link_selector_class'] : '',
                'content_image_size' => isset($generator['content_image_size']) ? $generator['content_image_size'] : 'medium',
                'source_link_phrases' => isset($generator['source_link_phrases']) ? $generator['source_link_phrases'] : '',
                'source_context_exclude_phrases' => isset($generator['source_context_exclude_phrases']) ? $generator['source_context_exclude_phrases'] : '',
                'source_context_rating_label' => isset($generator['source_context_rating_label']) ? $generator['source_context_rating_label'] : 'IMDb',
                'source_context_min_rating' => isset($generator['source_context_min_rating']) ? $generator['source_context_min_rating'] : '0',
                'source_context_keep_unrated' => isset($generator['source_context_keep_unrated']) ? $generator['source_context_keep_unrated'] : '0',
                'seo_enabled' => $generator['seo_enabled'],
                'generation_language' => $generator['generation_language'],
                'prompt_template' => $generator['prompt_template'],
                'related_posts_enabled' => isset($generator['related_posts_enabled']) ? $generator['related_posts_enabled'] : 0,
                'related_posts_position' => isset($generator['related_posts_position']) ? $generator['related_posts_position'] : 'end',
                'related_posts_interval' => isset($generator['related_posts_interval']) ? $generator['related_posts_interval'] : 4,
                'related_posts_min_h2' => isset($generator['related_posts_min_h2']) ? $generator['related_posts_min_h2'] : 1,
                'related_posts_links_per_block' => isset($generator['related_posts_links_per_block']) ? $generator['related_posts_links_per_block'] : 2,
                'related_posts_same_category_only' => isset($generator['related_posts_same_category_only']) ? $generator['related_posts_same_category_only'] : 1,
                'related_posts_allow_fallback' => isset($generator['related_posts_allow_fallback']) ? $generator['related_posts_allow_fallback'] : 1,
                'related_posts_style' => isset($generator['related_posts_style']) ? $generator['related_posts_style'] : 'list',
                'related_posts_phrases' => isset($generator['related_posts_phrases']) ? $generator['related_posts_phrases'] : '',
            );

            return self::save_generator($duplicated);
        }

        public function handle_save_settings()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('arc_save_settings', 'arc_settings_nonce');
            $settings = self::sanitize_settings($_POST);
            update_option(self::OPTION_KEY, $settings, false);
            self::redirect_with_notice('Configuracoes globais salvas com sucesso.');
        }

        public function handle_save_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('arc_save_generator', 'arc_generator_nonce');
            $saved = self::save_generator($_POST);
            if (is_wp_error($saved)) {
                self::redirect_with_notice($saved->get_error_message(), 'error');
            }
            self::redirect_with_notice('Gerador salvo com sucesso.');
        }

        public function handle_delete_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('arc_delete_generator', 'arc_delete_nonce');
            $id = isset($_POST['generator_id']) ? intval($_POST['generator_id']) : 0;
            if ($id > 0) {
                self::delete_generator($id);
            }
            self::redirect_with_notice('Gerador excluido com sucesso.');
        }

        public function handle_duplicate_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('arc_duplicate_generator', 'arc_duplicate_nonce');
            $id = isset($_POST['generator_id']) ? intval($_POST['generator_id']) : 0;
            $result = $id > 0 ? self::duplicate_generator($id) : new WP_Error('arc_invalid_generator', 'Gerador inválido.');
            if (is_wp_error($result)) {
                self::redirect_with_notice($result->get_error_message(), 'error');
            }
            self::redirect_with_notice('Gerador duplicado com sucesso.');
        }

        public function handle_run_generator()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }
            check_admin_referer('arc_run_generator', 'arc_run_nonce');
            $id = isset($_POST['generator_id']) ? intval($_POST['generator_id']) : 0;
            $generator = $id > 0 ? self::get_generator($id) : null;
            if (!$generator) {
                self::redirect_with_notice('Gerador não encontrado.', 'error');
            }
            $item_guid = isset($_POST['item_guid']) ? sanitize_text_field(wp_unslash($_POST['item_guid'])) : '';
            if ($item_guid !== '') {
                $result = self::run_generator_item($generator, $item_guid);
                if (is_wp_error($result)) {
                    self::redirect_with_notice($result->get_error_message(), 'error');
                }
                $item_link = !empty($result['view_link']) ? $result['view_link'] : (!empty($result['permalink']) ? $result['permalink'] : (!empty($result['edit_link']) ? $result['edit_link'] : ''));
                self::redirect_with_notice('Item gerado com sucesso.', 'success', array(
                    'arc_notice_link' => $item_link,
                ));
            }

            $result = self::run_generator($generator, true);
            if (is_wp_error($result)) {
                self::redirect_with_notice($result->get_error_message(), 'error');
            }
            self::redirect_with_notice(sprintf('Execução do gerador concluída. Criados %d, ignorados %d, falharam %d.', $result['created'], $result['skipped'], $result['failed']));
        }

        public static function array_to_key_value_lines($data)
        {
            if (!is_array($data)) {
                return '';
            }
            $lines = array();
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $lines[] = $key . '=' . $value;
            }
            return implode("\n", $lines);
        }

        public static function shorten_log_value($value, $limit = 120)
        {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            $limit = max(20, intval($limit));
            if (function_exists('mb_strimwidth')) {
                return mb_strimwidth($value, 0, $limit, '...');
            }
            return strlen($value) > $limit ? substr($value, 0, $limit - 3) . '...' : $value;
        }

        public static function format_run_log_summary($row)
        {
            $parts = array();
            $payloads = array();

            foreach (array('request_json', 'response_json') as $field) {
                if (empty($row[$field])) {
                    continue;
                }
                $decoded = json_decode((string) $row[$field], true);
                if (!is_array($decoded)) {
                    continue;
                }
                foreach (array('request', 'response') as $section) {
                    if (!empty($decoded[$section]) && is_array($decoded[$section])) {
                        $payloads[] = $decoded[$section];
                    }
                }
            }

            foreach ($payloads as $payload) {
                foreach (array('post_id', 'item_guid', 'item_permalink', 'source_video_url', 'source_video_source', 'video_selector_class', 'has_source_video', 'has_raw_embed_html', 'embed_mode', 'embed_source', 'provider', 'current_length', 'updated_length') as $key) {
                    if (!isset($payload[$key]) || $payload[$key] === '' || $payload[$key] === null) {
                        continue;
                    }
                    $value = is_bool($payload[$key]) ? ($payload[$key] ? '1' : '0') : (string) $payload[$key];
                    if ($key === 'source_video_url' || strlen($value) > 100) {
                        $value = self::shorten_log_value($value, 100);
                    }
                    $parts[] = $key . '=' . $value;
                }
            }

            if (!empty($row['post_id'])) {
                $parts[] = 'post_id=' . intval($row['post_id']);
            }
            if (!empty($row['item_guid'])) {
                $parts[] = 'item_guid=' . self::shorten_log_value($row['item_guid'], 60);
            }
            if (!empty($row['item_permalink'])) {
                $parts[] = 'item_permalink=' . self::shorten_log_value($row['item_permalink'], 100);
            }

            $parts = array_values(array_unique(array_filter($parts)));
            if (empty($parts)) {
                return '';
            }
            return implode(' Â· ', array_slice($parts, 0, 4));
        }

        public static function get_recent_runs($limit = 30)
        {
            global $wpdb;
            $limit = max(1, intval($limit));
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . self::$table_runs . " ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
        }
    }
}

register_activation_hook(__FILE__, array('Alpha_RSS_AI_Generator', 'activate'));
register_deactivation_hook(__FILE__, array('Alpha_RSS_AI_Generator', 'deactivate'));
add_action('plugins_loaded', function () {
    Alpha_RSS_AI_Generator::instance()->boot();
});


