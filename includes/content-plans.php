<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Alpha_RSS_AI_Content_Plans')) {
    final class Alpha_RSS_AI_Content_Plans
    {
        public const PAGE_SLUG = 'alpha-rss-ai-content-plans';
        private const META_PLAN_JSON = '_arc_content_plan_json';
        private const META_PLAN_GENERATED_AT = '_arc_content_plan_generated_at';
        private const META_PLAN_GENERATOR_ID = '_arc_content_plan_generator_id';
        private const META_PLAN_PILLAR_POST_ID = '_arc_content_plan_pillar_post_id';
        private const META_PLAN_SATELLITE_COUNT = '_arc_content_plan_satellite_count';
        private const META_PLAN_CONTENT_MODEL_TYPE = '_arc_content_plan_content_model_type';
        private const META_PLAN_PROMPT_MODEL_KEY = '_arc_content_plan_prompt_model_key';
        private const META_PLAN_OUTLINE_MODEL_KEY = '_arc_content_plan_outline_model_key';
        private const META_PLAN_TAVILY_JSON = '_arc_content_plan_tavily_json';
        private const META_PLAN_PLANNING_CUSTOM_PROMPT = '_arc_content_plan_planning_custom_prompt';
        private const MAX_PLANNING_SOURCE_WORDS = 1000;

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 22);
            add_action('admin_init', array($this, 'register_row_action_filters'));
            add_action('wp_ajax_arc_content_plan_search_posts', array($this, 'ajax_search_posts'));
            add_action('admin_post_arc_generate_content_plan', array($this, 'handle_generate_plan'));
            add_action('admin_post_arc_generate_content_satellites', array($this, 'handle_generate_satellites'));
            add_action('admin_post_arc_clear_content_plan', array($this, 'handle_clear_plan'));
        }

        public function admin_menu()
        {
            add_submenu_page(
                'alpha-rss-ai-generator',
                'Planejamento',
                'Planejamento',
                'manage_options',
                self::PAGE_SLUG,
                array($this, 'render_page')
            );
        }

        private static function get_request_param($key, $default = '')
        {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- admin read-only param helper.
            if (!isset($_GET[$key])) {
                return $default;
            }
            $value = wp_unslash($_GET[$key]);
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            if (is_array($value)) {
                return $default;
            }

            return sanitize_text_field((string) $value);
        }

        private static function get_generated_posts($limit = 30, $content_model_type = 'pillar')
        {
            $limit = max(1, intval($limit));
            $content_model_type = class_exists('Alpha_RSS_AI_Generator')
                ? Alpha_RSS_AI_Generator::normalize_content_model_type($content_model_type)
                : sanitize_key((string) $content_model_type);
            if ($content_model_type !== 'pillar' && $content_model_type !== 'satellite') {
                $content_model_type = 'pillar';
            }

            $posts = get_posts(array(
                'post_type' => 'any',
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => max(200, $limit * 10),
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_arc_generator_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            ));

            if (empty($posts) || !is_array($posts)) {
                return array();
            }

            $filtered = array();
            foreach ($posts as $post) {
                if (!$post instanceof WP_Post) {
                    continue;
                }

                $stored_content_model_type = (string) get_post_meta($post->ID, '_arc_content_model_type', true);
                if ($stored_content_model_type === '') {
                    $has_satellite_plan_marker = get_post_meta($post->ID, '_arc_content_plan_satellite_index', true) !== '';
                    $stored_content_model_type = $has_satellite_plan_marker ? 'satellite' : 'pillar';
                }
                if (class_exists('Alpha_RSS_AI_Generator')) {
                    $stored_content_model_type = Alpha_RSS_AI_Generator::normalize_content_model_type($stored_content_model_type);
                }
                if ($stored_content_model_type !== $content_model_type) {
                    continue;
                }

                $filtered[] = $post;
                if (count($filtered) >= $limit) {
                    break;
                }
            }

            return $filtered;
        }

        public static function build_plan_url($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return '';
            }

            return add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
            ), admin_url('admin.php'));
        }

        public function register_row_action_filters()
        {
            if (!is_admin()) {
                return;
            }

            $post_types = get_post_types(array('show_ui' => true), 'names');
            if (empty($post_types) || !is_array($post_types)) {
                return;
            }

            foreach ($post_types as $post_type) {
                add_filter($post_type . '_row_actions', array($this, 'add_plan_row_action'), 20, 2);
            }
        }

        public function add_plan_row_action($actions, $post)
        {
            if (!$post instanceof WP_Post || !current_user_can('manage_options')) {
                return $actions;
            }

            $plan_url = self::build_plan_url($post->ID);
            if ($plan_url === '') {
                return $actions;
            }

            $actions['alpha_rss_ai_plan'] = '<a href="' . esc_url($plan_url) . '" aria-label="Lincagem automática" title="Lincagem automática">Lincagem automática</a>';
            return $actions;
        }

        private static function build_picker_post_item($post)
        {
            if (!$post instanceof WP_Post) {
                return array();
            }

            $generator_id = intval(get_post_meta($post->ID, '_arc_generator_id', true));
            $generator_name = '';
            if ($generator_id > 0 && class_exists('Alpha_RSS_AI_Generator')) {
                $generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
                if (!empty($generator['name'])) {
                    $generator_name = (string) $generator['name'];
                }
            }

            $title = self::normalize_plain_text(get_the_title($post));
            $label = $title;
            if ($generator_name !== '') {
                $label .= ' - ' . $generator_name;
            }

            return array(
                'id' => intval($post->ID),
                'title' => $title,
                'label' => $label,
                'url' => get_permalink($post),
                'post_type' => get_post_type($post),
                'generator_name' => $generator_name,
            );
        }

        private static function query_picker_posts($search = '', $page = 1, $per_page = 10)
        {
            $search = self::normalize_plain_text($search);
            $page = max(1, intval($page));
            $per_page = max(1, min(20, intval($per_page)));
            $chunk_size = max($per_page * 4, 20);

            $query_args = array(
                'post_type' => 'any',
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => $chunk_size,
                'offset' => ($page - 1) * $chunk_size,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_arc_generator_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            );

            if ($search !== '') {
                $query_args['s'] = $search;
            }

            $posts = get_posts($query_args);
            if (empty($posts) || !is_array($posts)) {
                return array(
                    'items' => array(),
                    'has_more' => false,
                );
            }

            $items = array();
            foreach ($posts as $post) {
                if (!$post instanceof WP_Post) {
                    continue;
                }

                $stored_content_model_type = (string) get_post_meta($post->ID, '_arc_content_model_type', true);
                if ($stored_content_model_type === '') {
                    $has_satellite_plan_marker = get_post_meta($post->ID, '_arc_content_plan_satellite_index', true) !== '';
                    $stored_content_model_type = $has_satellite_plan_marker ? 'satellite' : 'pillar';
                }
                if (class_exists('Alpha_RSS_AI_Generator')) {
                    $stored_content_model_type = Alpha_RSS_AI_Generator::normalize_content_model_type($stored_content_model_type);
                }
                if ($stored_content_model_type !== 'pillar') {
                    continue;
                }

                $items[] = self::build_picker_post_item($post);
                if (count($items) >= $per_page) {
                    break;
                }
            }

            return array(
                'items' => $items,
                'has_more' => count($posts) >= $chunk_size,
            );
        }

        public function ajax_search_posts()
        {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permissão negada.'), 403);
            }

            check_ajax_referer('arc_content_plan_posts_search', 'nonce');

            $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;

            $result = self::query_picker_posts($search, $page, $per_page);
            wp_send_json_success($result);
        }

        private static function limit_plain_text_words($text, $max_words = self::MAX_PLANNING_SOURCE_WORDS)
        {
            $text = self::normalize_plain_text($text);
            $max_words = max(1, intval($max_words));
            if ($text === '') {
                return '';
            }

            if (function_exists('wp_trim_words')) {
                return trim((string) wp_trim_words($text, $max_words));
            }

            $parts = preg_split('/\s+/', $text);
            if (!is_array($parts) || empty($parts)) {
                return $text;
            }

            return trim(implode(' ', array_slice($parts, 0, $max_words)));
        }

        private static function limit_item_for_planning($item, $max_words = self::MAX_PLANNING_SOURCE_WORDS)
        {
            $item = is_array($item) ? $item : array();
            $max_words = max(1, intval($max_words));

            foreach (array('excerpt', 'content', 'source_page_excerpt', 'source_page_content', 'source_page_outline') as $key) {
                if (!empty($item[$key])) {
                    $item[$key] = self::limit_plain_text_words((string) $item[$key], $max_words);
                }
            }

            return $item;
        }

        private static function build_default_generator_context($post_id = 0)
        {
            $post_id = max(0, intval($post_id));
            $synthetic_generator_id = $post_id > 0 ? (100000000 + $post_id) : 0;
            return array(
                'id' => $synthetic_generator_id,
                'name' => $post_id > 0 ? 'Lincagem manual' : get_bloginfo('name'),
                'source_type' => 'post',
                'generation_language' => class_exists('Alpha_RSS_AI_Generator')
                    ? Alpha_RSS_AI_Generator::get_default_generation_language()
                    : get_bloginfo('language'),
                'content_length_class' => class_exists('Alpha_RSS_AI_Generator')
                    ? Alpha_RSS_AI_Generator::get_default_content_length_class()
                    : 'medium',
                'prompt_model_key' => '',
                'outline_model_key' => '',
                'prompt_models' => class_exists('Alpha_RSS_AI_Generator')
                    ? Alpha_RSS_AI_Generator::get_default_prompt_models()
                    : array(),
                'prompt_models_json' => class_exists('Alpha_RSS_AI_Generator')
                    ? wp_json_encode(Alpha_RSS_AI_Generator::get_default_prompt_models(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : '',
                'seo_enabled' => 1,
                'post_type' => 'post',
                'post_status' => 'draft',
            );
        }

        private static function lift_execution_time_limit($seconds = 300)
        {
            $seconds = max(30, intval($seconds));
            if (function_exists('set_time_limit')) {
                @set_time_limit($seconds);
            }
            if (function_exists('ini_set')) {
                @ini_set('max_execution_time', (string) $seconds);
            }
        }

        private static function normalize_plain_text($text)
        {
            $text = trim(wp_strip_all_tags((string) $text));
            $text = preg_replace('/\s+/', ' ', $text);
            return trim((string) $text);
        }

        private static function build_tavily_query($item, $planning_custom_prompt = '')
        {
            $item = is_array($item) ? $item : array();
            $parts = array();

            foreach (array('title', 'source_title', 'excerpt', 'source_page_excerpt') as $key) {
                if (!empty($item[$key])) {
                    $parts[] = self::limit_plain_text_words((string) $item[$key], 18);
                }
            }

            if ($planning_custom_prompt !== '') {
                $parts[] = self::limit_plain_text_words((string) $planning_custom_prompt, 18);
            }

            $parts = array_values(array_filter(array_map(array(__CLASS__, 'normalize_plain_text'), $parts), 'strlen'));
            $query = trim(implode(' ', array_slice($parts, 0, 3)));
            if ($query === '' && !empty($item['content'])) {
                $query = self::limit_plain_text_words((string) $item['content'], 24);
            }

            return self::normalize_plain_text($query);
        }

        private static function fetch_tavily_research($query, $max_results = 3)
        {
            $query = self::normalize_plain_text($query);
            if ($query === '') {
                return array();
            }

            $settings = class_exists('Alpha_RSS_AI_Generator') ? Alpha_RSS_AI_Generator::get_settings() : array();
            if (empty($settings['tavily_enabled'])) {
                return array();
            }

            $api_key = !empty($settings['tavily_api_key']) ? trim((string) $settings['tavily_api_key']) : '';
            if ($api_key === '') {
                return array();
            }

            $search_depth = !empty($settings['tavily_search_depth']) ? sanitize_key((string) $settings['tavily_search_depth']) : 'basic';
            if (!in_array($search_depth, array('basic', 'advanced'), true)) {
                $search_depth = 'basic';
            }

            $payload = array(
                'api_key' => $api_key,
                'query' => $query,
                'search_depth' => $search_depth,
                'max_results' => max(1, min(10, intval($max_results))),
                'include_answer' => !empty($settings['tavily_include_answer']),
                'include_images' => false,
                'include_raw_content' => false,
            );

            $response = wp_remote_post('https://api.tavily.com/search', array(
                'timeout' => 40,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ),
                'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));

            if (is_wp_error($response)) {
                return array();
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                return array();
            }

            $raw = json_decode((string) wp_remote_retrieve_body($response), true);
            if (!is_array($raw) || empty($raw)) {
                return array();
            }

            $results = array();
            if (!empty($raw['results']) && is_array($raw['results'])) {
                foreach ($raw['results'] as $result) {
                    if (!is_array($result)) {
                        continue;
                    }
                    $results[] = array(
                        'title' => !empty($result['title']) ? self::normalize_plain_text((string) $result['title']) : '',
                        'url' => !empty($result['url']) ? esc_url_raw((string) $result['url']) : '',
                        'content' => !empty($result['content']) ? self::normalize_plain_text((string) $result['content']) : '',
                        'score' => isset($result['score']) ? floatval($result['score']) : 0.0,
                    );
                }
            }

            return array(
                'query' => $query,
                'answer' => !empty($raw['answer']) ? self::normalize_plain_text((string) $raw['answer']) : '',
                'results' => $results,
            );
        }

        private static function format_tavily_research_for_prompt($context)
        {
            if (!is_array($context) || empty($context)) {
                return '';
            }

            $lines = array();
            if (!empty($context['query'])) {
                $lines[] = 'Consulta Tavily: ' . self::normalize_plain_text((string) $context['query']);
            }
            if (!empty($context['answer'])) {
                $lines[] = 'Resposta Tavily: ' . self::normalize_plain_text((string) $context['answer']);
            }
            if (!empty($context['results']) && is_array($context['results'])) {
                $count = 0;
                foreach ($context['results'] as $result) {
                    if (!is_array($result)) {
                        continue;
                    }
                    $count++;
                    if ($count > 5) {
                        break;
                    }
                    $result_line = trim(
                        ($count . '. ' . (!empty($result['title']) ? self::normalize_plain_text((string) $result['title']) : 'Resultado')) .
                        (!empty($result['content']) ? ' — ' . self::normalize_plain_text((string) $result['content']) : '') .
                        (!empty($result['url']) ? ' (' . esc_url_raw((string) $result['url']) . ')' : '')
                    );
                    if ($result_line !== '') {
                        $lines[] = $result_line;
                    }
                }
            }

            return self::limit_plain_text_words(implode("\n", $lines), 220);
        }

        private static function attach_tavily_context_to_item($item, $context)
        {
            $item = is_array($item) ? $item : array();
            $context = is_array($context) ? $context : array();
            $tavily_text = self::format_tavily_research_for_prompt($context);

            $item['tavily_query'] = !empty($context['query']) ? self::normalize_plain_text((string) $context['query']) : '';
            $item['tavily_context'] = $context;
            $item['tavily_text'] = $tavily_text;

            if ($tavily_text !== '') {
                foreach (array('content', 'source_page_content', 'excerpt', 'source_page_excerpt') as $key) {
                    if (!empty($item[$key])) {
                        $item[$key] = trim((string) $item[$key]) . "\n\n" . 'Pesquisa externa auxiliar do Tavily: ' . $tavily_text;
                    }
                }
            }

            return $item;
        }

        private static function get_post_excerpt_text($post)
        {
            if (!$post instanceof WP_Post) {
                return '';
            }

            $excerpt = trim((string) $post->post_excerpt);
            if ($excerpt !== '') {
                return self::normalize_plain_text($excerpt);
            }

            return self::normalize_plain_text(wp_trim_words(wp_strip_all_tags((string) $post->post_content), 40));
        }

        private static function resolve_pillar_context($post_id)
        {
            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('arc_content_plan_post_missing', 'Post pilar nao encontrado.');
            }

            $generator_id = intval(get_post_meta($post_id, '_arc_generator_id', true));
            $generator = array();
            if ($generator_id > 0 && class_exists('Alpha_RSS_AI_Generator')) {
                $generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
            }
            if (empty($generator) || !is_array($generator)) {
                $generator = self::build_default_generator_context($post_id);
            }

            $source_title = (string) get_post_meta($post_id, '_arc_source_title', true);
            $source_url = (string) get_post_meta($post_id, '_arc_source_url', true);
            $source_page_title = (string) get_post_meta($post_id, '_arc_source_page_title', true);
            $source_page_excerpt = (string) get_post_meta($post_id, '_arc_source_page_excerpt', true);
            $source_page_content = (string) get_post_meta($post_id, '_arc_source_page_content', true);
            $source_page_outline = (string) get_post_meta($post_id, '_arc_source_page_outline', true);
            $content_model_type = (string) get_post_meta($post_id, '_arc_content_model_type', true);
            if ($content_model_type === '' && isset($generator['content_model_type']) && class_exists('Alpha_RSS_AI_Generator')) {
                $content_model_type = Alpha_RSS_AI_Generator::normalize_content_model_type($generator['content_model_type']);
            }
            if ($content_model_type === '') {
                $content_model_type = 'pillar';
            }
            $source_url = $source_url !== '' ? $source_url : get_permalink($post_id);
            $source_page_title = $source_page_title !== '' ? $source_page_title : (string) $post->post_title;
            $source_page_excerpt = $source_page_excerpt !== '' ? $source_page_excerpt : self::get_post_excerpt_text($post);
            $source_page_content = $source_page_content !== '' ? $source_page_content : (string) $post->post_content;
            $source_page_outline = $source_page_outline !== '' ? $source_page_outline : '';

            $item = array(
                'guid' => (string) get_post_meta($post_id, '_arc_source_item_guid', true),
                'title' => (string) $post->post_title,
                'source_title' => $source_title !== '' ? $source_title : (string) $post->post_title,
                'permalink' => get_permalink($post_id),
                'source_url' => $source_url,
                'excerpt' => self::get_post_excerpt_text($post),
                'content' => self::normalize_plain_text($post->post_content),
                'feed_title' => !empty($generator['name']) ? (string) $generator['name'] : get_bloginfo('name'),
                'date' => (string) get_post_meta($post_id, '_arc_source_timestamp', true),
                'categories' => wp_get_post_terms($post_id, 'category', array('fields' => 'names')),
                'tags' => wp_get_post_terms($post_id, 'post_tag', array('fields' => 'names')),
                'source_image_url' => (string) get_post_meta($post_id, '_arc_source_image_url', true),
                'source_link_url' => (string) get_post_meta($post_id, '_arc_source_link_url', true),
                'source_link_text' => (string) get_post_meta($post_id, '_arc_source_link_text', true),
                'source_page_title' => $source_page_title,
                'source_page_excerpt' => $source_page_excerpt,
                'source_page_content' => $source_page_content,
                'source_page_outline' => $source_page_outline,
                'source_page_outline_sections' => array(),
                'source_video_url' => (string) get_post_meta($post_id, '_arc_source_video_url', true),
                'source_video_embed_html' => (string) get_post_meta($post_id, '_arc_source_video_embed_html', true),
                'source_video_source' => (string) get_post_meta($post_id, '_arc_source_video_source', true),
                'source_image_selector_class' => (string) get_post_meta($post_id, '_arc_source_image_selector_class', true),
                'source_link_selector_class' => (string) get_post_meta($post_id, '_arc_source_link_selector_class', true),
                'source_context_enriched' => 1,
                'pillar_post_id' => intval($post_id),
                'content_model_type' => $content_model_type,
            );

            $outline_sections_raw = (string) get_post_meta($post_id, '_arc_source_page_outline_sections', true);
            if ($outline_sections_raw !== '') {
                $outline_sections = json_decode($outline_sections_raw, true);
                if (is_array($outline_sections)) {
                    $item['source_page_outline_sections'] = $outline_sections;
                }
            }

            if (empty($item['source_page_content']) && !empty($post->post_content)) {
                $item['source_page_content'] = self::normalize_plain_text($post->post_content);
            }
            if (empty($item['source_page_excerpt'])) {
                $item['source_page_excerpt'] = $item['excerpt'];
            }
            if (empty($item['source_page_title'])) {
                $item['source_page_title'] = $item['title'];
            }

            $item = self::limit_item_for_planning($item, self::MAX_PLANNING_SOURCE_WORDS);

            return array(
                'generator' => $generator,
                'item' => $item,
                'post' => $post,
            );
        }

        private static function normalize_satellite_item($satellite, $index)
        {
            $satellite = is_array($satellite) ? $satellite : array();
            $title = !empty($satellite['title']) ? sanitize_text_field((string) $satellite['title']) : ('Satélite ' . intval($index));
            $slug = !empty($satellite['slug']) ? sanitize_title((string) $satellite['slug']) : sanitize_title($title);
            $suggestion = '';
            foreach (array('suggestion', 'summary', 'brief', 'description') as $key) {
                if (!empty($satellite[$key])) {
                    $suggestion = sanitize_textarea_field((string) $satellite[$key]);
                    break;
                }
            }

            return array(
                'index' => intval($index),
                'title' => $title,
                'slug' => $slug,
                'focus_keyword' => !empty($satellite['focus_keyword']) ? sanitize_text_field((string) $satellite['focus_keyword']) : '',
                'anchor_phrase' => !empty($satellite['anchor_phrase']) ? sanitize_text_field((string) $satellite['anchor_phrase']) : '',
                'suggestion' => $suggestion,
                'content_angle' => !empty($satellite['content_angle']) ? sanitize_text_field((string) $satellite['content_angle']) : '',
                'reason' => !empty($satellite['reason']) ? sanitize_textarea_field((string) $satellite['reason']) : '',
            );
        }

        private static function normalize_plan_response($plan, $satellite_count)
        {
            $satellite_count = max(1, intval($satellite_count));
            $normalized = array(
                'title' => !empty($plan['title']) ? sanitize_text_field((string) $plan['title']) : '',
                'slug' => !empty($plan['slug']) ? sanitize_title((string) $plan['slug']) : '',
                'satellites' => array(),
            );

            $raw_satellites = array();
            if (!empty($plan['satellites']) && is_array($plan['satellites'])) {
                $raw_satellites = $plan['satellites'];
            }

            foreach (array_slice($raw_satellites, 0, $satellite_count) as $index => $satellite) {
                $normalized['satellites'][] = self::normalize_satellite_item($satellite, $index + 1);
            }

            return $normalized;
        }

        private static function build_plan_prompt($generator, $item, $satellite_count, $outline_context = array(), $planning_custom_prompt = '')
        {
            $satellite_count = max(1, intval($satellite_count));
            $outline_context = is_array($outline_context) && !empty($outline_context)
                ? $outline_context
                : Alpha_RSS_AI_Generator_Helper::build_outline_context_base($generator);
            $outline_text = !empty($outline_context['outline_model_text']) ? (string) $outline_context['outline_model_text'] : '';

            $pillar_title = !empty($item['title']) ? self::normalize_plain_text($item['title']) : '';
            $pillar_url = !empty($item['permalink']) ? esc_url_raw((string) $item['permalink']) : '';
            $pillar_content = !empty($item['content']) ? self::limit_plain_text_words((string) $item['content'], self::MAX_PLANNING_SOURCE_WORDS) : '';
            $pillar_categories = !empty($item['categories']) && is_array($item['categories']) ? implode(', ', array_map('sanitize_text_field', $item['categories'])) : '';
            $pillar_tags = !empty($item['tags']) && is_array($item['tags']) ? implode(', ', array_map('sanitize_text_field', $item['tags'])) : '';
            $generation_language = !empty($generator['generation_language']) ? Alpha_RSS_AI_Generator::normalize_generation_language_value($generator['generation_language']) : Alpha_RSS_AI_Generator::get_default_generation_language();
            $available_prompt_models = class_exists('Alpha_RSS_AI_Generator') ? Alpha_RSS_AI_Generator::get_prompt_models($generator) : array();
            $available_prompt_models_text = array();
            foreach ($available_prompt_models as $available_prompt_model) {
                if (!is_array($available_prompt_model)) {
                    continue;
                }
                $available_prompt_models_text[] = Alpha_RSS_AI_Generator::format_prompt_model_for_prompt($available_prompt_model);
            }
            $available_prompt_models_text = !empty($available_prompt_models_text) ? implode("\n\n---\n\n", $available_prompt_models_text) : '';
            $recommended_prompt_model_key = !empty($outline_context['recommended_prompt_model_key']) ? sanitize_key((string) $outline_context['recommended_prompt_model_key']) : '';
            $recommended_outline_model_key = !empty($outline_context['recommended_outline_model_key']) ? sanitize_key((string) $outline_context['recommended_outline_model_key']) : '';
            $recommended_prompt_model = !empty($recommended_prompt_model_key) ? Alpha_RSS_AI_Generator::get_prompt_model($recommended_prompt_model_key, $generator) : array();
            $recommended_prompt_model_name = !empty($recommended_prompt_model['name']) ? (string) $recommended_prompt_model['name'] : '';
            $planning_custom_prompt = self::normalize_plain_text($planning_custom_prompt);
            $tavily_text = '';
            if (!empty($item['tavily_text'])) {
                $tavily_text = self::normalize_plain_text((string) $item['tavily_text']);
            } elseif (!empty($item['tavily_context']) && is_array($item['tavily_context'])) {
                $tavily_text = self::format_tavily_research_for_prompt($item['tavily_context']);
            }

            $lines = array(
                'Voce é um estrategista editorial e arquiteto de links internos.',
                'Analise o post abaixo e devolva somente JSON valido.',
                'Escolha ' . $satellite_count . ' frases viáveis para se tornarem kw de um post satélite.',
                'A resposta deve trazer as chaves: title, slug, satellites.',
                'satellites deve ser um array com exatamente ' . $satellite_count . ' objetos.',
                'Cada objeto deve ter: title, slug, focus_keyword, anchor_phrase, suggestion, content_angle, reason.',
                'Cada anchor_phrase precisa ser uma frase natural que possa receber um link no post pilar.',
                'Cada suggestion deve ser uma pauta editorial pronta, com 2 a 4 frases curtas, com sugestão do que o post pode abordar.',
                'Use content_angle para classificar o tipo da sugestao, priorizando: critica, resumo, opiniao, guia, comparacao, curiosidades, spoilers, ranking, debate, analise, contexto, checklist, releitura.',
                'Use o mesmo idioma final do gerador: ' . $generation_language . '.',
                $planning_custom_prompt !== '' ? 'Prompt personalizado do usuario: ' . $planning_custom_prompt : '',
                'URL do post pilar: ' . $pillar_url,
                'Conteúdo de referência do post pilar: ' . $pillar_content,
                $tavily_text !== '' ? 'Pesquisa externa auxiliar do Tavily: ' . $tavily_text : '',
            );

            $lines = array_values(array_filter($lines, 'strlen'));

            return implode("\n", $lines);
        }

        private static function get_plan_meta($post_id)
        {
            $raw = (string) get_post_meta($post_id, self::META_PLAN_JSON, true);
            if ($raw === '') {
                return array();
            }

            $plan = json_decode($raw, true);
            return is_array($plan) ? $plan : array();
        }

        private static function render_notice()
        {
            $notice = self::get_request_param('arc_notice', '');
            if ($notice === '') {
                return;
            }

            $message = '';
            $class = 'notice-success';

            if ($notice === 'plan_saved') {
                $message = 'Plano editorial salvo com sucesso.';
            } elseif ($notice === 'plan_cleared') {
                $message = 'Plano editorial removido.';
            } elseif ($notice === 'satellites_generated') {
                $count = intval(self::get_request_param('arc_count', 0));
                $message = $count > 0
                    ? sprintf('Satélites gerados com sucesso. %d post(s) criado(s) e linkados ao pilar.', $count)
                    : 'Satélites gerados com sucesso.';
            } elseif ($notice === 'satellite_error') {
                $message = self::get_request_param('arc_message', 'Não foi possível gerar os satélites.');
                $class = 'notice-error';
            } elseif ($notice === 'plan_error') {
                $message = self::get_request_param('arc_message', 'Não foi possível gerar o plano editorial.');
                $class = 'notice-error';
            }

            if ($message === '') {
                return;
            }

            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        public function handle_generate_plan()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }

            check_admin_referer('arc_generate_content_plan', 'arc_content_plan_nonce');
            self::lift_execution_time_limit(300);

            $post_id = isset($_POST['pillar_post_id']) ? intval($_POST['pillar_post_id']) : 0;
            $satellite_count = isset($_POST['satellite_count']) ? intval($_POST['satellite_count']) : 5;
            $satellite_count = max(1, min(12, $satellite_count));
            $planning_custom_prompt = isset($_POST['planning_custom_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['planning_custom_prompt'])) : '';
            update_post_meta($post_id, self::META_PLAN_PLANNING_CUSTOM_PROMPT, $planning_custom_prompt);

            $context = self::resolve_pillar_context($post_id);
            if (is_wp_error($context)) {
                $redirect = add_query_arg(array(
                    'page' => self::PAGE_SLUG,
                    'post_id' => $post_id,
                    'arc_notice' => 'plan_error',
                    'arc_message' => $context->get_error_message(),
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $generator = $context['generator'];
            $item = $context['item'];
            $item = self::limit_item_for_planning($item, self::MAX_PLANNING_SOURCE_WORDS);
            $global_settings = class_exists('Alpha_RSS_AI_Generator') ? Alpha_RSS_AI_Generator::get_settings() : array();
            $tavily_query = '';
            $tavily_context = array();
            if (!empty($global_settings['tavily_enabled'])) {
                $tavily_query = self::build_tavily_query($item, $planning_custom_prompt);
                $tavily_max_results = !empty($global_settings['tavily_max_results']) ? intval($global_settings['tavily_max_results']) : 3;
                $tavily_context = self::fetch_tavily_research($tavily_query, $tavily_max_results);
            }
            if (!empty($tavily_context)) {
                $item = self::attach_tavily_context_to_item($item, $tavily_context);
            }
            $outline_base_context = Alpha_RSS_AI_Generator_Helper::build_outline_context_base($generator);
            $outline_context = Alpha_RSS_AI_Generator_Helper::build_outline_context_from_source($generator, $item, array(), $outline_base_context);
            $prompt = self::build_plan_prompt($generator, $item, $satellite_count, $outline_context, $planning_custom_prompt);
            $plan = Alpha_RSS_AI_Generator::request_openai_json($generator, $prompt, array(
                'stage' => 'content_plan',
                'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
                'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                'item_title' => !empty($item['title']) ? $item['title'] : '',
                'preserve_extra_fields' => 1,
                'allow_missing_content_html' => 1,
                'source_context_enriched' => 1,
                'satellite_count' => $satellite_count,
            ));

            if (is_wp_error($plan)) {
                $redirect = add_query_arg(array(
                    'page' => self::PAGE_SLUG,
                    'post_id' => $post_id,
                    'arc_notice' => 'plan_error',
                    'arc_message' => $plan->get_error_message(),
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $normalized_plan = self::normalize_plan_response($plan, $satellite_count);
            $normalized_plan['pillar_post_id'] = $post_id;
            $normalized_plan['generator_id'] = intval($generator['id']);
            $normalized_plan['satellite_count'] = $satellite_count;
            $normalized_plan['generated_at'] = current_time('mysql');
            $normalized_plan['pillar_title'] = !empty($item['title']) ? $item['title'] : '';
            $normalized_plan['pillar_url'] = !empty($item['permalink']) ? $item['permalink'] : '';
            $normalized_plan['pillar_categories'] = !empty($item['categories']) && is_array($item['categories']) ? array_values($item['categories']) : array();
            $normalized_plan['pillar_tags'] = !empty($item['tags']) && is_array($item['tags']) ? array_values($item['tags']) : array();
            $normalized_plan['content_model_type'] = !empty($item['content_model_type']) ? Alpha_RSS_AI_Generator::normalize_content_model_type($item['content_model_type']) : 'pillar';
            $normalized_plan['content_model_label'] = Alpha_RSS_AI_Generator::get_content_model_label($normalized_plan['content_model_type']);
            $normalized_plan['planning_custom_prompt'] = $planning_custom_prompt;
            $normalized_plan['tavily_query'] = $tavily_query;
            $normalized_plan['tavily_context'] = !empty($tavily_context) ? $tavily_context : array();
            $normalized_plan['tavily_text'] = !empty($item['tavily_text']) ? $item['tavily_text'] : '';
            $normalized_plan['recommended_prompt_model_key'] = !empty($outline_context['recommended_prompt_model_key']) ? sanitize_key((string) $outline_context['recommended_prompt_model_key']) : '';
            $normalized_plan['recommended_outline_model_key'] = !empty($outline_context['recommended_outline_model_key']) ? sanitize_key((string) $outline_context['recommended_outline_model_key']) : '';
            $normalized_plan['outline_context'] = $outline_context;

            update_post_meta($post_id, self::META_PLAN_JSON, wp_json_encode($normalized_plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_post_meta($post_id, self::META_PLAN_TAVILY_JSON, wp_json_encode(array(
                'query' => $tavily_query,
                'context' => !empty($tavily_context) ? $tavily_context : array(),
                'text' => !empty($item['tavily_text']) ? $item['tavily_text'] : '',
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_post_meta($post_id, self::META_PLAN_GENERATED_AT, current_time('mysql'));
            update_post_meta($post_id, self::META_PLAN_GENERATOR_ID, intval($generator['id']));
            update_post_meta($post_id, self::META_PLAN_PILLAR_POST_ID, $post_id);
            update_post_meta($post_id, self::META_PLAN_SATELLITE_COUNT, $satellite_count);
            update_post_meta($post_id, self::META_PLAN_CONTENT_MODEL_TYPE, $normalized_plan['content_model_type']);
            update_post_meta($post_id, self::META_PLAN_PROMPT_MODEL_KEY, $normalized_plan['recommended_prompt_model_key']);
            update_post_meta($post_id, self::META_PLAN_OUTLINE_MODEL_KEY, $normalized_plan['recommended_outline_model_key']);
            delete_post_meta($post_id, '_arc_content_plan_satellite_post_ids');
            delete_post_meta($post_id, '_arc_content_plan_generated_satellites');

            $redirect = add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
                'arc_notice' => 'plan_saved',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        public function handle_clear_plan()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }

            check_admin_referer('arc_clear_content_plan', 'arc_content_plan_nonce');

            $post_id = isset($_POST['pillar_post_id']) ? intval($_POST['pillar_post_id']) : 0;
            if ($post_id > 0) {
                delete_post_meta($post_id, self::META_PLAN_JSON);
                delete_post_meta($post_id, self::META_PLAN_GENERATED_AT);
                delete_post_meta($post_id, self::META_PLAN_GENERATOR_ID);
                delete_post_meta($post_id, self::META_PLAN_PILLAR_POST_ID);
                delete_post_meta($post_id, self::META_PLAN_SATELLITE_COUNT);
                delete_post_meta($post_id, self::META_PLAN_CONTENT_MODEL_TYPE);
                delete_post_meta($post_id, self::META_PLAN_PROMPT_MODEL_KEY);
                delete_post_meta($post_id, self::META_PLAN_OUTLINE_MODEL_KEY);
                delete_post_meta($post_id, self::META_PLAN_PLANNING_CUSTOM_PROMPT);
                delete_post_meta($post_id, self::META_PLAN_TAVILY_JSON);
                delete_post_meta($post_id, '_arc_content_plan_satellite_post_ids');
                delete_post_meta($post_id, '_arc_content_plan_generated_satellites');
            }

            $redirect = add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
                'arc_notice' => 'plan_cleared',
            ), admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        private static function build_satellite_generation_item($context, $plan, $satellite)
        {
            $context = is_array($context) ? $context : array();
            $plan = is_array($plan) ? $plan : array();
            $satellite = self::normalize_satellite_item($satellite, isset($satellite['index']) ? intval($satellite['index']) : 1);

            $generator = !empty($context['generator']) && is_array($context['generator']) ? $context['generator'] : array();
            $post = !empty($context['post']) && $context['post'] instanceof WP_Post ? $context['post'] : null;
            $pillar_post_id = $post ? intval($post->ID) : intval(!empty($plan['pillar_post_id']) ? $plan['pillar_post_id'] : 0);
            $pillar_title = '';
            if (!empty($plan['pillar_title'])) {
                $pillar_title = self::normalize_plain_text((string) $plan['pillar_title']);
            } elseif ($post instanceof WP_Post) {
                $pillar_title = self::normalize_plain_text(get_the_title($post));
            }
            if ($pillar_title === '') {
                $pillar_title = !empty($satellite['title']) ? self::normalize_plain_text((string) $satellite['title']) : 'Pilar';
            }

            $pillar_url = '';
            if (!empty($plan['pillar_url'])) {
                $pillar_url = esc_url_raw((string) $plan['pillar_url']);
            } elseif ($post instanceof WP_Post) {
                $pillar_url = esc_url_raw(get_permalink($post));
            }

            $pillar_content = '';
            if (!empty($context['item']) && is_array($context['item']) && !empty($context['item']['content'])) {
                $pillar_content = self::normalize_plain_text((string) $context['item']['content']);
            } elseif ($post instanceof WP_Post) {
                $pillar_content = self::normalize_plain_text((string) $post->post_content);
            }

            $suggestion = !empty($satellite['suggestion']) ? self::normalize_plain_text((string) $satellite['suggestion']) : '';
            $content_angle = !empty($satellite['content_angle']) ? self::normalize_plain_text((string) $satellite['content_angle']) : '';
            $reason = !empty($satellite['reason']) ? self::normalize_plain_text((string) $satellite['reason']) : '';
            $anchor_phrase = !empty($satellite['anchor_phrase']) ? self::normalize_plain_text((string) $satellite['anchor_phrase']) : '';
            $tavily_text = '';
            if (!empty($plan['tavily_text'])) {
                $tavily_text = self::normalize_plain_text((string) $plan['tavily_text']);
            } elseif (!empty($plan['tavily_context']) && is_array($plan['tavily_context'])) {
                $tavily_text = self::format_tavily_research_for_prompt($plan['tavily_context']);
            }
            $source_content = implode("\n", array_filter(array(
                'Pilar: ' . $pillar_title,
                $pillar_content !== '' ? 'Conteúdo do pilar: ' . $pillar_content : '',
                $tavily_text !== '' ? 'Pesquisa externa auxiliar: ' . $tavily_text : '',
                $suggestion !== '' ? 'Sugestão editorial: ' . $suggestion : '',
                $content_angle !== '' ? 'Tipo de conteúdo: ' . $content_angle : '',
                $reason !== '' ? 'Motivo do satélite: ' . $reason : '',
                $anchor_phrase !== '' ? 'Âncora planejada: ' . $anchor_phrase : '',
            )));

            return array(
                'guid' => 'content-plan:' . $pillar_post_id . ':' . intval($satellite['index']),
                'title' => $satellite['title'],
                'source_title' => $satellite['title'],
                'keyword' => !empty($satellite['focus_keyword']) ? $satellite['focus_keyword'] : $satellite['title'],
                'permalink' => '',
                'source_url' => $pillar_url,
                'excerpt' => $suggestion,
                'content' => $source_content,
                'feed_title' => !empty($generator['name']) ? (string) $generator['name'] : get_bloginfo('name'),
                'date' => current_time('mysql'),
                'categories' => !empty($context['item']['categories']) && is_array($context['item']['categories']) ? $context['item']['categories'] : array(),
                'tags' => !empty($context['item']['tags']) && is_array($context['item']['tags']) ? $context['item']['tags'] : array(),
                'source_page_title' => $pillar_title,
                'source_page_excerpt' => '',
                'source_page_content' => $pillar_content,
                'source_page_outline' => '',
                'source_page_outline_sections' => array(),
                'source_context_enriched' => 1,
                'content_model_type' => 'satellite',
                'final_slug' => !empty($satellite['slug']) ? $satellite['slug'] : sanitize_title($satellite['title']),
                'content_plan_pillar_post_id' => $pillar_post_id,
                'content_plan_pillar_title' => $pillar_title,
                'content_plan_pillar_url' => $pillar_url,
                'content_plan_satellite_index' => intval($satellite['index']),
                'content_plan_satellite_title' => $satellite['title'],
                'content_plan_satellite_slug' => !empty($satellite['slug']) ? $satellite['slug'] : sanitize_title($satellite['title']),
                'content_plan_satellite_anchor_phrase' => $anchor_phrase,
                'content_plan_satellite_focus_keyword' => !empty($satellite['focus_keyword']) ? $satellite['focus_keyword'] : '',
                'content_plan_satellite_suggestion' => $suggestion,
                'content_plan_satellite_content_angle' => $content_angle,
                'content_plan_satellite_reason' => $reason,
                'content_plan_satellite' => $satellite,
                'content_plan_backlink_links' => array(
                    array(
                        'title' => $pillar_title !== '' ? $pillar_title : 'Voltar ao pilar',
                        'url' => $pillar_url,
                    ),
                ),
                'content_plan_backlink_label' => 'Voltar ao pilar:',
            );
        }

        private static function persist_generated_satellite_links($pillar_post_id, $plan, $generated_satellite_posts)
        {
            $pillar_post_id = intval($pillar_post_id);
            $generated_satellite_posts = is_array($generated_satellite_posts) ? array_values($generated_satellite_posts) : array();
            if ($pillar_post_id <= 0 || empty($generated_satellite_posts)) {
                return false;
            }

            $current_content = (string) get_post_field('post_content', $pillar_post_id);
            $pillar_links = array();
            $satellite_ids = array();
            foreach ($generated_satellite_posts as $generated) {
                if (empty($generated['post_id'])) {
                    continue;
                }
                $post_id = intval($generated['post_id']);
                if ($post_id <= 0) {
                    continue;
                }
                $satellite_ids[] = $post_id;
                $pillar_links[] = array(
                    'title' => !empty($generated['title']) ? $generated['title'] : get_the_title($post_id),
                    'url' => !empty($generated['url']) ? $generated['url'] : get_permalink($post_id),
                    'anchor_phrase' => !empty($generated['anchor_phrase']) ? $generated['anchor_phrase'] : '',
                    'slug' => !empty($generated['slug']) ? $generated['slug'] : get_post_field('post_name', $post_id),
                );
            }

            if (!empty($pillar_links)) {
                $current_content = Alpha_RSS_AI_Generator_Helper::inject_content_plan_links_into_content(
                    $current_content,
                    $pillar_links,
                    'pillar',
                    'Você também pode gostar de:'
                );
                wp_update_post(array(
                    'ID' => $pillar_post_id,
                    'post_content' => $current_content,
                ));
            }

            update_post_meta($pillar_post_id, '_arc_content_plan_satellite_post_ids', wp_json_encode($satellite_ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_post_meta($pillar_post_id, '_arc_content_plan_generated_satellites', wp_json_encode($generated_satellite_posts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (is_array($plan) && !empty($plan)) {
                $plan['generated_satellite_post_ids'] = $satellite_ids;
                $plan['generated_satellite_posts'] = $generated_satellite_posts;
                update_post_meta($pillar_post_id, self::META_PLAN_JSON, wp_json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return true;
        }

        public function handle_generate_satellites()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }

            check_admin_referer('arc_generate_content_satellites', 'arc_content_satellites_nonce');
            self::lift_execution_time_limit(300);

            $post_id = isset($_POST['pillar_post_id']) ? intval($_POST['pillar_post_id']) : 0;
            $context = self::resolve_pillar_context($post_id);
            if (is_wp_error($context)) {
                $redirect = add_query_arg(array(
                    'page' => self::PAGE_SLUG,
                    'post_id' => $post_id,
                    'arc_notice' => 'plan_error',
                    'arc_message' => $context->get_error_message(),
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $plan = self::get_plan_meta($post_id);
            $satellites = !empty($plan['satellites']) && is_array($plan['satellites']) ? $plan['satellites'] : array();
            if (empty($satellites)) {
                $redirect = add_query_arg(array(
                    'page' => self::PAGE_SLUG,
                    'post_id' => $post_id,
                    'arc_notice' => 'satellite_error',
                    'arc_message' => 'Não existe plano salvo com satélites para gerar.',
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $generator = $context['generator'];
            $satellite_generator = $generator;
            $satellite_generator['source_type'] = 'rss';
            $satellite_generator['content_model_type'] = 'satellite';
            $satellite_generator['use_final_slug'] = 1;
            $satellite_generator['post_status'] = 'publish';

            $generated_posts = array();
            $errors = array();

            foreach ($satellites as $satellite) {
                $normalized_satellite = self::normalize_satellite_item($satellite, isset($satellite['index']) ? intval($satellite['index']) : (count($generated_posts) + 1));
                $item = self::build_satellite_generation_item($context, $plan, $normalized_satellite);
                if (!Alpha_RSS_AI_Generator::claim_item_processing_slot($satellite_generator['id'], $item)) {
                    $errors[] = 'Item já estava em processamento.';
                    continue;
                }
                $post_result = Alpha_RSS_AI_Generator::create_post_from_generator_item($satellite_generator, $item);
                if (is_wp_error($post_result)) {
                    Alpha_RSS_AI_Generator::delete_item_processed_by_guid($satellite_generator['id'], $item['guid']);
                    $errors[] = $post_result->get_error_message();
                    continue;
                }

                $generated_posts[] = array(
                    'post_id' => intval($post_result),
                    'title' => $normalized_satellite['title'],
                    'slug' => (string) get_post_field('post_name', $post_result),
                    'url' => get_permalink($post_result),
                    'anchor_phrase' => $normalized_satellite['anchor_phrase'],
                );
            }

            self::persist_generated_satellite_links($post_id, $plan, $generated_posts);

            $redirect_args = array(
                'page' => self::PAGE_SLUG,
                'post_id' => $post_id,
            );
            if (!empty($generated_posts)) {
                $redirect_args['arc_notice'] = 'satellites_generated';
                $redirect_args['arc_count'] = count($generated_posts);
            } else {
                $redirect_args['arc_notice'] = 'satellite_error';
                $redirect_args['arc_message'] = !empty($errors) ? implode(' | ', array_slice($errors, 0, 3)) : 'Não foi possível gerar os satélites.';
            }

            $redirect = add_query_arg($redirect_args, admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        private static function render_picker_post_button($item, $selected_post_id = 0)
        {
            $item = is_array($item) ? $item : array();
            $item_id = isset($item['id']) ? intval($item['id']) : 0;
            if ($item_id <= 0) {
                return;
            }

            $is_selected = $selected_post_id > 0 && $selected_post_id === $item_id;
            $classes = array(
                'arc-plan-picker-item',
                'w-full',
                'rounded-xl',
                'border',
                'px-4',
                'py-3',
                'text-left',
                'text-sm',
                'transition',
                'focus:outline-none',
                'focus:ring-2',
                'focus:ring-indigo-200',
            );
            if ($is_selected) {
                $classes[] = 'border-indigo-500';
                $classes[] = 'bg-indigo-50';
            } else {
                $classes[] = 'border-slate-200';
                $classes[] = 'bg-white';
                $classes[] = 'hover:bg-slate-50';
            }

            echo '<button type="button" class="' . esc_attr(implode(' ', $classes)) . '" data-post-id="' . esc_attr($item_id) . '" data-post-title="' . esc_attr(!empty($item['title']) ? $item['title'] : '') . '" data-post-url="' . esc_attr(!empty($item['url']) ? $item['url'] : '') . '" data-post-type="' . esc_attr(!empty($item['post_type']) ? $item['post_type'] : 'post') . '">';
            echo esc_html(!empty($item['title']) ? $item['title'] : 'Post');
            echo '</button>';
        }

        private static function render_posts_selector($selected_post_id = 0)
        {
            $selected_post_id = intval($selected_post_id);
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $search_nonce = wp_create_nonce('arc_content_plan_posts_search');
            $initial_results = self::query_picker_posts('', 1, 10);
            $items = !empty($initial_results['items']) && is_array($initial_results['items']) ? $initial_results['items'] : array();
            $has_more = !empty($initial_results['has_more']);
            $button_label = $selected_post instanceof WP_Post ? self::normalize_plain_text(get_the_title($selected_post)) : 'Selecionar post';

            echo '<div id="arc-plan-picker" class="relative space-y-3" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr($search_nonce) . '" data-per-page="10" data-current-page="1" data-has-more="' . ($has_more ? '1' : '0') . '">';
            echo '<input type="hidden" name="pillar_post_id" id="arc-plan-picker-value" value="' . esc_attr($selected_post_id) . '" />';
            echo '<button type="button" id="arc-plan-picker-toggle" class="flex w-full items-center justify-between rounded-2xl border border-slate-300 bg-white px-4 py-3 text-left text-sm font-medium text-slate-900 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200" aria-expanded="false">';
            echo '<span id="arc-plan-picker-label">' . esc_html($button_label) . '</span>';
            echo '<span class="text-slate-400">⌄</span>';
            echo '</button>';

            echo '<div id="arc-plan-picker-menu" class="absolute left-0 right-0 top-full z-20 mt-2 hidden rounded-2xl border border-slate-200 bg-white shadow-soft">';
            echo '<div class="border-b border-slate-200 p-3">';
            echo '<div class="flex gap-2">';
            echo '<input id="arc-plan-picker-search" type="search" class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Pesquisar..." autocomplete="off" />';
            echo '<button type="button" id="arc-plan-picker-search-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Buscar</button>';
            echo '</div>';
            echo '</div>';
            echo '<div id="arc-plan-picker-results" class="max-h-80 space-y-2 overflow-y-auto p-3 pr-1">';
            if (!empty($items)) {
                foreach ($items as $item) {
                    self::render_picker_post_button($item, $selected_post_id);
                }
            } else {
                echo '<p class="text-sm text-slate-500">Nenhum post encontrado.</p>';
            }
            echo '</div>';
            echo '<div class="mt-3 flex justify-center">';
            echo '<button type="button" id="arc-plan-picker-load-more" class="' . ($has_more ? '' : 'hidden ') . 'inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Carregar mais</button>';
            echo '</div>';
            echo '<p id="arc-plan-picker-empty" class="hidden px-3 pb-3 text-sm text-slate-500">Nenhum resultado encontrado.</p>';
            echo '</div>';
            echo '</div>';
        }

        private static function render_plan_table($plan)
        {
            if (empty($plan) || !is_array($plan)) {
                echo '<div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">Nenhum plano gerado ainda.</div>';
                return;
            }

            $satellites = !empty($plan['satellites']) && is_array($plan['satellites']) ? $plan['satellites'] : array();

            echo '<div class="space-y-4">';
            echo '<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">';
            echo '<div class="flex flex-wrap items-center justify-between gap-3">';
            echo '<div>';
            echo '<h3 class="text-lg font-semibold text-slate-950">Plano editorial</h3>';
            if (!empty($plan['generated_at'])) {
                echo '<p class="mt-1 text-sm text-slate-500">Gerado em ' . esc_html($plan['generated_at']) . '</p>';
            }
            if (!empty($plan['content_model_label'])) {
                echo '<p class="mt-1 text-sm text-slate-500">Modelo editorial: ' . esc_html($plan['content_model_label']) . '</p>';
            }
            if (!empty($plan['generated_satellite_posts']) && is_array($plan['generated_satellite_posts'])) {
                echo '<p class="mt-1 text-sm text-slate-500">Satélites gerados: ' . esc_html(count($plan['generated_satellite_posts'])) . '</p>';
            }
            echo '</div>';
            echo '<div class="text-sm text-slate-500">' . esc_html(count($satellites)) . ' satélite(s)</div>';
            echo '</div>';
            echo '</div>';

            echo '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">';
            echo '<table class="min-w-full divide-y divide-slate-200">';
            echo '<thead class="bg-slate-50">';
            echo '<tr>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">#</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Título do satélite</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Âncora</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">KW</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Tipo</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Sugestão editorial</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody class="divide-y divide-slate-100 bg-white">';
            foreach ($satellites as $satellite) {
                $index = isset($satellite['index']) ? intval($satellite['index']) : 0;
                echo '<tr>';
                echo '<td class="px-4 py-4 text-sm font-medium text-slate-500">' . esc_html($index > 0 ? $index : '-') . '</td>';
                echo '<td class="px-4 py-4 text-sm font-semibold text-slate-900">' . esc_html(isset($satellite['title']) ? $satellite['title'] : '-') . '</td>';
                echo '<td class="px-4 py-4 text-sm text-slate-700">' . esc_html(isset($satellite['anchor_phrase']) ? $satellite['anchor_phrase'] : '-') . '</td>';
                echo '<td class="px-4 py-4 text-sm text-slate-700">' . esc_html(isset($satellite['focus_keyword']) ? $satellite['focus_keyword'] : '-') . '</td>';
                echo '<td class="px-4 py-4 text-sm text-slate-700">' . esc_html(isset($satellite['content_angle']) ? $satellite['content_angle'] : '-') . '</td>';
                echo '<td class="px-4 py-4 text-sm text-slate-700">' . esc_html(isset($satellite['suggestion']) ? $satellite['suggestion'] : '-') . '</td>';
                echo '</tr>';
            }
            if (empty($satellites)) {
                echo '<tr><td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">O plano não trouxe satélites.</td></tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissão negada.');
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            $selected_post_id = intval(self::get_request_param('post_id', 0));
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $plan = $selected_post_id > 0 ? self::get_plan_meta($selected_post_id) : array();
            $generated_at = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_GENERATED_AT, true) : '';
            if (!empty($generated_at)) {
                $plan['generated_at'] = $generated_at;
            }
            $stored_planning_custom_prompt = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_PLANNING_CUSTOM_PROMPT, true) : '';
            $plan_prompt_model_key = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_PROMPT_MODEL_KEY, true) : '';
            $plan_outline_model_key = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_OUTLINE_MODEL_KEY, true) : '';
            if ($plan_prompt_model_key !== '') {
                $plan['recommended_prompt_model_key'] = $plan_prompt_model_key;
            }
            if ($plan_outline_model_key !== '') {
                $plan['recommended_outline_model_key'] = $plan_outline_model_key;
            }
            if ($stored_planning_custom_prompt !== '' && empty($plan['planning_custom_prompt'])) {
                $plan['planning_custom_prompt'] = $stored_planning_custom_prompt;
            }

            $current_generator = null;
            if ($selected_post_id > 0) {
                $generator_id = intval(get_post_meta($selected_post_id, '_arc_generator_id', true));
                if ($generator_id > 0 && class_exists('Alpha_RSS_AI_Generator')) {
                    $current_generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
                }
            }
            if (!$current_generator && $selected_post_id > 0) {
                $current_generator = self::build_default_generator_context($selected_post_id);
            }

            $selected_content_model_type = '';
            if (!empty($plan['content_model_type'])) {
                $selected_content_model_type = Alpha_RSS_AI_Generator::normalize_content_model_type($plan['content_model_type']);
            }
            if ($selected_content_model_type === '' && $selected_post_id > 0) {
                $stored_content_model_type = (string) get_post_meta($selected_post_id, '_arc_content_model_type', true);
                if ($stored_content_model_type !== '') {
                    $selected_content_model_type = Alpha_RSS_AI_Generator::normalize_content_model_type($stored_content_model_type);
                }
            }
            if ($selected_content_model_type === '' && $current_generator && !empty($current_generator['content_model_type'])) {
                $selected_content_model_type = Alpha_RSS_AI_Generator::normalize_content_model_type($current_generator['content_model_type']);
            }
            if ($selected_content_model_type === '') {
                $selected_content_model_type = Alpha_RSS_AI_Generator::get_default_content_model_type();
            }

?>
            <script>
                window.tailwind = window.tailwind || {};
                window.tailwind.config = {
                    theme: {
                        extend: {
                            boxShadow: {
                                soft: '0 20px 50px -30px rgba(15, 23, 42, 0.35)'
                            }
                        }
                    }
                };
            </script>
            <script src="https://cdn.tailwindcss.com"></script>
            <div class="wrap">
                <div class="mb-6">
                    <p class="text-xs font-semibold text-indigo-500">Alpha RSS AI</p>
                    <h1 class="text-3xl font-semibold tracking-tight text-slate-950">Lincagem automática</h1>
                </div>

                <?php self::render_notice(); ?>

                <div class="grid gap-6 xl:grid-cols-[360px_minmax(0,1fr)]">
                    <aside class="space-y-6">
                        <div class=" border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h2 class="text-xl font-semibold text-slate-950">Parâmetros do Plano</h2>
                                </div>
                            </div>
                            <div class="mt-5"><?php self::render_posts_selector($selected_post_id); ?></div>

                            <div id="arc-plan-options" class="mt-5 space-y-4 <?php echo $selected_post instanceof WP_Post ? '' : 'hidden'; ?>">
                                <form id="arc-content-plan-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="space-y-4">
                                    <?php wp_nonce_field('arc_generate_content_plan', 'arc_content_plan_nonce'); ?>
                                    <input type="hidden" name="action" value="arc_generate_content_plan" />
                                    <input type="hidden" name="pillar_post_id" id="arc-content-plan-post-id" value="<?php echo esc_attr($selected_post instanceof WP_Post ? $selected_post->ID : 0); ?>" />

                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Qtd. satélites</label>
                                            <input type="number" min="1" max="12" name="satellite_count" value="<?php echo esc_attr(isset($plan['satellite_count']) ? intval($plan['satellite_count']) : 5); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                    </div>

                                    <details class="group rounded-2xl border border-slate-200 bg-slate-50">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-700">
                                            <span>Prompt personalizado</span>
                                            <span class="text-slate-400 transition group-open:rotate-180">⌄</span>
                                        </summary>
                                        <div class="border-t border-slate-200 px-4 py-4">
                                            <textarea name="planning_custom_prompt" rows="5" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Instruções extras para a IA, se precisar."><?php echo esc_textarea(isset($plan['planning_custom_prompt']) ? $plan['planning_custom_prompt'] : ''); ?></textarea>
                                        </div>
                                    </details>

                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-emerald-500">Gerar lincagem</button>
                                </form>

                                <?php if (!empty($plan)): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('arc_generate_content_satellites', 'arc_content_satellites_nonce'); ?>
                                        <input type="hidden" name="action" value="arc_generate_content_satellites" />
                                        <input type="hidden" name="pillar_post_id" value="<?php echo esc_attr($selected_post instanceof WP_Post ? $selected_post->ID : 0); ?>" />
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-slate-800">Gerar satélites</button>
                                    </form>

                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mt-3" data-swal-confirm="Remover o plano salvo deste post pilar?">
                                        <?php wp_nonce_field('arc_clear_content_plan', 'arc_content_plan_nonce'); ?>
                                        <input type="hidden" name="action" value="arc_clear_content_plan" />
                                        <input type="hidden" name="pillar_post_id" value="<?php echo esc_attr($selected_post instanceof WP_Post ? $selected_post->ID : 0); ?>" />
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-100">Limpar plano</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </aside>

                    <main class="min-w-0">
                        <?php self::render_plan_table($plan); ?>
                    </main>
                </div>
            </div>
            <script>
                (function () {
                    const picker = document.getElementById('arc-plan-picker');
                    const optionsWrap = document.getElementById('arc-plan-options');
                    const toggleButton = document.getElementById('arc-plan-picker-toggle');
                    const labelNode = document.getElementById('arc-plan-picker-label');
                    const menu = document.getElementById('arc-plan-picker-menu');
                    const searchInput = document.getElementById('arc-plan-picker-search');
                    const searchButton = document.getElementById('arc-plan-picker-search-btn');
                    const results = document.getElementById('arc-plan-picker-results');
                    const loadMoreButton = document.getElementById('arc-plan-picker-load-more');
                    const emptyState = document.getElementById('arc-plan-picker-empty');
                    const valueInputs = document.querySelectorAll('input[name="pillar_post_id"]');
                    const ajaxUrl = picker ? picker.dataset.ajaxUrl : '';
                    const nonce = picker ? picker.dataset.nonce : '';
                    const perPage = picker ? parseInt(picker.dataset.perPage || '10', 10) : 10;
                    let currentPage = picker ? parseInt(picker.dataset.currentPage || '1', 10) : 1;
                    let hasMore = picker ? picker.dataset.hasMore === '1' : false;
                    let currentSearch = '';
                    let selectedPostId = 0;
                    let loading = false;
                    let searchTimer = null;

                    if (!picker || !results || !optionsWrap || !toggleButton || !menu || !labelNode) {
                        return;
                    }

                    const escapeHtml = (value) => {
                        return String(value ?? '')
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    };

                    const setSelectedId = (postId) => {
                        selectedPostId = parseInt(postId || '0', 10) || 0;
                        valueInputs.forEach((input) => {
                            input.value = selectedPostId > 0 ? String(selectedPostId) : '';
                        });
                        optionsWrap.classList.toggle('hidden', selectedPostId <= 0);
                    };

                    const setMenuOpen = (isOpen) => {
                        menu.classList.toggle('hidden', !isOpen);
                        toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        if (isOpen && searchInput) {
                            window.setTimeout(() => searchInput.focus(), 0);
                        }
                    };

                    const syncActiveButtons = () => {
                        const buttons = results.querySelectorAll('.arc-plan-picker-item');
                        buttons.forEach((button) => {
                            const buttonId = parseInt(button.dataset.postId || '0', 10) || 0;
                            const active = selectedPostId > 0 && buttonId === selectedPostId;
                            button.setAttribute('aria-pressed', active ? 'true' : 'false');
                            button.classList.toggle('border-indigo-500', active);
                            button.classList.toggle('bg-indigo-50', active);
                            button.classList.toggle('border-slate-200', !active);
                            button.classList.toggle('bg-white', !active);
                        });
                    };

                    if (results) {
                        results.addEventListener('click', (event) => {
                            const button = event.target.closest('.arc-plan-picker-item');
                            if (!button || !results.contains(button)) {
                                return;
                            }

                            const item = {
                                id: parseInt(button.dataset.postId || '0', 10) || 0,
                                title: button.dataset.postTitle || button.textContent || 'Post',
                                post_type: button.dataset.postType || 'post'
                            };

                            if (item.id > 0) {
                                selectPost(item);
                            }
                        });
                    }

                    const updateLabel = (text) => {
                        labelNode.textContent = text && String(text).trim() !== '' ? String(text) : 'Selecionar post';
                    };

                    const selectPost = (item) => {
                        setSelectedId(item.id);
                        updateLabel(item.title || 'Selecionar post');
                        setMenuOpen(false);
                        syncActiveButtons();
                    };

                    const renderButton = (item) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'arc-plan-picker-item w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-indigo-200';
                        button.dataset.postId = item.id;
                        button.dataset.postType = item.post_type || 'post';
                        button.dataset.postTitle = item.title || '';
                        button.setAttribute('aria-pressed', selectedPostId > 0 && parseInt(item.id || '0', 10) === selectedPostId ? 'true' : 'false');
                        if (selectedPostId > 0 && parseInt(item.id || '0', 10) === selectedPostId) {
                            button.classList.add('border-indigo-500', 'bg-indigo-50');
                        } else {
                            button.classList.add('border-slate-200', 'bg-white', 'hover:bg-slate-50');
                        }

                        button.innerHTML = escapeHtml(item.title || 'Post');
                        return button;
                    };

                    const setLoadMoreState = () => {
                        if (!loadMoreButton) {
                            return;
                        }
                        loadMoreButton.classList.toggle('hidden', !hasMore);
                    };

                    const setEmptyState = (isEmpty) => {
                        if (!emptyState) {
                            return;
                        }
                        emptyState.classList.toggle('hidden', !isEmpty);
                    };

                    const fetchPosts = async ({ search = '', page = 1, append = false } = {}) => {
                        if (loading) {
                            return;
                        }
                        loading = true;
                        if (searchButton) {
                            searchButton.disabled = true;
                        }
                        if (loadMoreButton) {
                            loadMoreButton.disabled = true;
                        }

                        try {
                            const response = await fetch(ajaxUrl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                                },
                                body: new URLSearchParams({
                                    action: 'arc_content_plan_search_posts',
                                    nonce: nonce,
                                    search: search,
                                    page: String(page),
                                    per_page: String(perPage)
                                }).toString()
                            });

                            const payload = await response.json();
                            if (!payload || !payload.success) {
                                throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : 'Não foi possível carregar os posts.');
                            }

                            const data = payload.data || {};
                            const items = Array.isArray(data.items) ? data.items : [];
                            hasMore = !!data.has_more;
                            picker.dataset.hasMore = hasMore ? '1' : '0';
                            currentPage = page;

                            if (!append) {
                                results.innerHTML = '';
                            }

                            if (items.length === 0 && !append) {
                                setEmptyState(true);
                            } else {
                                setEmptyState(false);
                                items.forEach((item) => {
                                    results.appendChild(renderButton(item));
                                });
                            }

                            setLoadMoreState();
                            syncActiveButtons();
                        } catch (error) {
                            console.error(error);
                            if (!append) {
                                results.innerHTML = '<p class="text-sm text-rose-600">Falha ao carregar os posts. Tente novamente.</p>';
                            }
                            setLoadMoreState();
                        } finally {
                            loading = false;
                            if (searchButton) {
                                searchButton.disabled = false;
                            }
                            if (loadMoreButton) {
                                loadMoreButton.disabled = false;
                            }
                        }
                    };

                    const runSearch = () => {
                        currentSearch = searchInput ? searchInput.value.trim() : '';
                        fetchPosts({ search: currentSearch, page: 1, append: false });
                    };

                    const initialSelectedInput = Array.from(valueInputs).find((input) => parseInt(input.value || '0', 10) > 0);
                    if (initialSelectedInput) {
                        selectedPostId = parseInt(initialSelectedInput.value || '0', 10) || 0;
                        optionsWrap.classList.remove('hidden');
                    }

                    if (selectedPostId <= 0) {
                        optionsWrap.classList.add('hidden');
                    }

                    setLoadMoreState();
                    setEmptyState(results.querySelectorAll('.arc-plan-picker-item').length === 0);
                    syncActiveButtons();

                    if (toggleButton) {
                        toggleButton.addEventListener('click', () => {
                            const isOpen = menu.classList.contains('hidden');
                            setMenuOpen(isOpen);
                        });
                    }

                    document.addEventListener('click', (event) => {
                        if (!picker.contains(event.target)) {
                            setMenuOpen(false);
                        }
                    });

                    document.addEventListener('keydown', (event) => {
                        if (event.key === 'Escape') {
                            setMenuOpen(false);
                        }
                    });

                    if (searchInput) {
                        searchInput.addEventListener('input', () => {
                            window.clearTimeout(searchTimer);
                            searchTimer = window.setTimeout(runSearch, 250);
                        });
                        searchInput.addEventListener('keydown', (event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                runSearch();
                            }
                        });
                    }

                    if (searchButton) {
                        searchButton.addEventListener('click', runSearch);
                    }

                    if (loadMoreButton) {
                        loadMoreButton.addEventListener('click', () => {
                            if (!hasMore) {
                                return;
                            }
                            fetchPosts({ search: currentSearch, page: currentPage + 1, append: true });
                        });
                    }

                    if (selectedPostId > 0) {
                        const selectedButton = results.querySelector('.arc-plan-picker-item[aria-pressed="true"]');
                        if (selectedButton) {
                            updateLabel(selectedButton.textContent.trim());
                        }
                    }
                })();
            </script>
<?php
        }
    }
}
