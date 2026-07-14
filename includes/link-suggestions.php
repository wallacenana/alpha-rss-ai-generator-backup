<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Alpha_RSS_AI_Link_Suggestions')) {
    final class Alpha_RSS_AI_Link_Suggestions
    {
        public const PAGE_SLUG = 'alpha-rss-ai-link-suggestions';

        private const META_JSON = '_arc_link_suggestions_json';
        private const META_GENERATED_AT = '_arc_link_suggestions_generated_at';
        private const META_SOURCE_POST_ID = '_arc_link_suggestions_source_post_id';
        private const META_CUSTOM_PROMPT = '_arc_link_suggestions_custom_prompt';
        private const META_REQUESTED_COUNT = '_arc_link_suggestions_requested_count';
        private const META_APPLIED_AT = '_arc_link_suggestions_applied_at';
        private const META_APPLIED_COUNT = '_arc_link_suggestions_applied_count';
        private const MAX_SOURCE_WORDS = 1000;
        private const MAX_TARGET_POSTS = 25;

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 22);
            add_action('admin_init', array($this, 'register_row_action_filters'));
            add_action('wp_ajax_arc_link_suggestions_search_posts', array($this, 'ajax_search_posts'));
            add_action('admin_post_arc_generate_link_suggestions', array($this, 'handle_generate_link_suggestions'));
            add_action('admin_post_arc_apply_link_suggestions', array($this, 'handle_apply_link_suggestions'));
            add_action('admin_post_arc_clear_link_suggestions', array($this, 'handle_clear_link_suggestions'));
        }

        public function admin_menu()
        {
            add_submenu_page(
                'alpha-rss-ai-generator',
                'Sugestões de links',
                'Sugestões de links',
                'manage_options',
                self::PAGE_SLUG,
                array($this, 'render_page')
            );

            remove_submenu_page('alpha-rss-ai-generator', self::PAGE_SLUG);
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
                add_filter($post_type . '_row_actions', array($this, 'add_row_action'), 20, 2);
            }
        }

        public function add_row_action($actions, $post)
        {
            if (!$post instanceof WP_Post || !current_user_can('manage_options')) {
                return $actions;
            }

            $url = self::build_page_url($post->ID);
            if ($url === '') {
                return $actions;
            }

            $actions['alpha_rss_ai_link_suggestions'] = '<a href="' . esc_url($url) . '" aria-label="Lincagem automática" title="Lincagem automática">Lincagem automática</a>';
            return $actions;
        }

        private static function build_page_url($post_id)
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

        private static function get_request_param($key, $default = '')
        {
            if (!isset($_GET[$key])) {
                return $default;
            }

            $value = wp_unslash($_GET[$key]);
            if (is_array($value)) {
                return $default;
            }

            return sanitize_text_field((string) $value);
        }

        private static function normalize_plain_text($text)
        {
            $text = trim(wp_strip_all_tags((string) $text));
            $text = preg_replace('/\s+/', ' ', $text);
            return trim((string) $text);
        }

        private static function extract_linkable_text_from_html($html)
        {
            $html = (string) $html;
            if ($html === '') {
                return '';
            }

            if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
                return self::normalize_plain_text(wp_strip_all_tags($html));
            }

            libxml_use_internal_errors(true);
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = @$dom->loadHTML('<?xml encoding="utf-8" ?><div id="arc-link-source-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            if (!$loaded) {
                return self::normalize_plain_text(wp_strip_all_tags($html));
            }

            $xpath = new DOMXPath($dom);
            $root = $dom->getElementById('arc-link-source-root');
            if (!$root) {
                return self::normalize_plain_text(wp_strip_all_tags($html));
            }

            $nodes = $xpath->query('.//p | .//li | .//blockquote | .//td | .//th | .//figcaption | .//summary', $root);
            if (!$nodes || $nodes->length === 0) {
                return self::normalize_plain_text(wp_strip_all_tags($html));
            }

            $parts = array();
            $skipped_paragraphs = 0;
            for ($i = 0; $i < $nodes->length; $i++) {
                $node = $nodes->item($i);
                if (!$node || !property_exists($node, 'textContent')) {
                    continue;
                }

                $text = self::normalize_plain_text((string) $node->textContent);
                if ($text === '') {
                    continue;
                }

                if (mb_strlen($text, 'UTF-8') < 20) {
                    continue;
                }

                if (strtolower((string) $node->nodeName) === 'p' && $skipped_paragraphs < 1) {
                    $skipped_paragraphs++;
                    continue;
                }

                if (preg_match('/^(veja também|voce também pode gostar de|você também pode gostar de|assista online|assista agora|leia também|leia mais|continue lendo)$/iu', $text)) {
                    continue;
                }

                $parts[] = $text;
            }

            $linkable_text = trim(implode(' ', $parts));
            if ($linkable_text === '') {
                $linkable_text = self::normalize_plain_text(wp_strip_all_tags($html));
            }

            return $linkable_text;
        }

        private static function normalize_link_suggestion_key($text)
        {
            $text = self::normalize_plain_text($text);
            if ($text === '') {
                return '';
            }

            if (function_exists('remove_accents')) {
                $text = remove_accents($text);
            }

            $text = strtolower($text);
            $text = preg_replace('/\([^)]*\)/u', ' ', $text);
            $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
            $text = preg_replace('/\s+/u', ' ', $text);
            return trim((string) $text);
        }

        private static function normalize_link_suggestion_anchor($anchor, $source_linkable_text = '')
        {
            $anchor = self::normalize_plain_text($anchor);
            if ($anchor === '') {
                return '';
            }

            $anchor_words = array_values(array_filter(preg_split('/\s+/u', $anchor), 'strlen'));
            $word_count = count($anchor_words);
            if ($word_count < 2) {
                return '';
            }

            $generic_terms = array(
                'trailer', 'mostra', 'mostrou', 'mostrando', 'novo', 'nova', 'filme', 'filmes',
                'serie', 'series', 'série', 'séries', 'episodio', 'episodios', 'episódio', 'episódios',
                'lanca', 'lança', 'lancou', 'lançou', 'lançado', 'lançada', 'produçao', 'produção',
                'producoes', 'produções', 'drama', 'ação', 'acao', 'interno', 'interna', 'internos', 'internas',
            );

            $content_words = 0;
            $generic_word_count = 0;
            foreach ($anchor_words as $word) {
                $normalized_word = self::normalize_link_suggestion_key($word);
                if ($normalized_word === '') {
                    continue;
                }

                if (in_array($normalized_word, $generic_terms, true)) {
                    $generic_word_count++;
                }

                if (mb_strlen($normalized_word, 'UTF-8') >= 4) {
                    $content_words++;
                }
            }

            if ($content_words < 1 || $generic_word_count >= $word_count) {
                return '';
            }

            $source_lookup = self::normalize_link_suggestion_key($source_linkable_text);
            if ($source_lookup === '') {
                return '';
            }

            $best_fragment = '';
            for ($size = min(4, $word_count); $size >= 2; $size--) {
                for ($offset = 0; $offset <= $word_count - $size; $offset++) {
                    $fragment = implode(' ', array_slice($anchor_words, $offset, $size));
                    $fragment_lookup = self::normalize_link_suggestion_key($fragment);
                    if ($fragment_lookup === '') {
                        continue;
                    }
                    if (mb_stripos($source_lookup, $fragment_lookup, 0, 'UTF-8') !== false) {
                        $best_fragment = self::normalize_plain_text($fragment);
                        break 2;
                    }
                }
            }

            if ($best_fragment !== '') {
                return $best_fragment;
            }

            if ($word_count <= 4) {
                $anchor_lookup = self::normalize_link_suggestion_key($anchor);
                if ($anchor_lookup !== '' && mb_stripos($source_lookup, $anchor_lookup, 0, 'UTF-8') !== false) {
                    return $anchor;
                }
            }

            return '';
        }

        private static function limit_plain_text_words($text, $max_words = self::MAX_SOURCE_WORDS)
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

        private static function get_default_generator_context()
        {
            $settings = class_exists('Alpha_RSS_AI_Generator') ? Alpha_RSS_AI_Generator::get_settings() : array();

            return array(
                'id' => 0,
                'name' => get_bloginfo('name'),
                'source_type' => 'post',
                'model' => !empty($settings['default_model']) ? (string) $settings['default_model'] : '',
                'temperature' => isset($settings['default_temperature']) ? floatval($settings['default_temperature']) : 0.4,
                'max_tokens' => isset($settings['default_max_tokens']) ? max(512, intval($settings['default_max_tokens'])) : 2000,
                'generation_language' => class_exists('Alpha_RSS_AI_Generator')
                    ? Alpha_RSS_AI_Generator::get_default_generation_language()
                    : get_bloginfo('language'),
            );
        }

        private static function get_source_context($post_id)
        {
            $post_id = intval($post_id);
            $post = $post_id > 0 ? get_post($post_id) : null;
            if (!$post instanceof WP_Post) {
                return new WP_Error('arc_link_suggestions_post_missing', 'Post não encontrado.');
            }

            $generator = array();
            $generator_id = intval(get_post_meta($post_id, '_arc_generator_id', true));
            if ($generator_id > 0 && class_exists('Alpha_RSS_AI_Generator')) {
                $generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
            }
            if (empty($generator) || !is_array($generator)) {
                $generator = self::get_default_generator_context();
            }

            $source_title = self::normalize_plain_text(get_the_title($post));
            $source_content_html = (string) $post->post_content;
            $source_linkable_text = self::extract_linkable_text_from_html($source_content_html);

            $post_types = array_values(array_diff(get_post_types(array('public' => true), 'names'), array('attachment', 'revision', 'nav_menu_item')));
            if (empty($post_types)) {
                $post_types = array('post');
            }

            $candidates = self::query_candidate_posts($post_id, self::MAX_TARGET_POSTS, $post_types);

            return array(
                'generator' => $generator,
                'post' => $post,
                'source' => array(
                    'id' => $post_id,
                    'title' => $source_title,
                    'content_html' => $source_content_html,
                    'linkable_text' => $source_linkable_text,
                ),
                'candidates' => $candidates,
            );
        }

        private static function build_picker_post_item($post)
        {
            if (!$post instanceof WP_Post) {
                return array();
            }

            $title = self::normalize_plain_text(get_the_title($post));
            return array(
                'id' => intval($post->ID),
                'title' => $title,
                'label' => $title !== '' ? $title . ' - ' . $post->post_type : ('Post ' . intval($post->ID)),
                'post_type' => $post->post_type,
                'status' => $post->post_status,
            );
        }

        private static function query_candidate_posts($exclude_post_id = 0, $limit = 25, $post_types = array())
        {
            $exclude_post_id = intval($exclude_post_id);
            $limit = max(1, min(50, intval($limit)));
            $post_types = is_array($post_types) && !empty($post_types) ? array_values($post_types) : array('post');

            $posts = get_posts(array(
                'post_type' => $post_types,
                'post_status' => array('publish'),
                'posts_per_page' => $limit + 5,
                'orderby' => 'date',
                'order' => 'DESC',
                'post__not_in' => $exclude_post_id > 0 ? array($exclude_post_id) : array(),
            ));

            if (empty($posts) || !is_array($posts)) {
                return array();
            }

            $items = array();
            foreach ($posts as $post) {
                if (!$post instanceof WP_Post) {
                    continue;
                }

                $items[] = array(
                    'id' => intval($post->ID),
                    'title' => self::normalize_plain_text(get_the_title($post)),
                );

                if (count($items) >= $limit) {
                    break;
                }
            }

            return $items;
        }

        private static function query_picker_posts($search = '', $page = 1, $per_page = 10)
        {
            $search = self::normalize_plain_text($search);
            $page = max(1, intval($page));
            $per_page = max(1, min(20, intval($per_page)));
            $chunk_size = max($per_page * 4, 20);

            $post_types = array_values(array_diff(get_post_types(array('public' => true), 'names'), array('attachment', 'revision', 'nav_menu_item')));
            if (empty($post_types)) {
                $post_types = array('post');
            }

            $query_args = array(
                'post_type' => $post_types,
                'post_status' => array('publish'),
                'posts_per_page' => $chunk_size,
                'offset' => ($page - 1) * $chunk_size,
                'orderby' => 'date',
                'order' => 'DESC',
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
                wp_send_json_error(array('message' => 'Permissao negada.'), 403);
            }

            check_ajax_referer('arc_link_suggestions_posts_search', 'nonce');

            $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
            $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 10;

            $result = self::query_picker_posts($search, $page, $per_page);
            wp_send_json_success($result);
        }

        private static function render_picker_post_button($item, $selected_post_id = 0)
        {
            $item = is_array($item) ? $item : array();
            $post_id = isset($item['id']) ? intval($item['id']) : 0;
            $post_title = isset($item['title']) ? self::normalize_plain_text($item['title']) : '';
            $post_type = isset($item['post_type']) ? self::normalize_plain_text($item['post_type']) : 'post';
            $is_active = $selected_post_id > 0 && $post_id === intval($selected_post_id);
            $button = '<button type="button" class="arc-link-picker-item w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-indigo-200' . ($is_active ? ' border-indigo-500 bg-indigo-50' : ' border-slate-200 bg-white hover:bg-slate-50') . '" data-post-id="' . esc_attr($post_id) . '" data-post-title="' . esc_attr($post_title) . '" data-post-type="' . esc_attr($post_type) . '" aria-pressed="' . ($is_active ? 'true' : 'false') . '">';
            $button .= '<div class="font-medium text-slate-900">' . esc_html(isset($item['label']) && $item['label'] !== '' ? $item['label'] : (isset($item['title']) ? $item['title'] : 'Post')) . '</div>';
            $button .= '<div class="mt-1 text-xs text-slate-500">ID ' . esc_html($post_id) . ' · ' . esc_html($post_type) . '</div>';
            $button .= '</button>';
            return $button;
        }

        private static function render_posts_selector($selected_post_id = 0)
        {
            $selected_post_id = intval($selected_post_id);
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $selected_title = $selected_post instanceof WP_Post ? self::normalize_plain_text(get_the_title($selected_post)) : '';
            $selected_meta = $selected_post instanceof WP_Post ? 'ID ' . intval($selected_post_id) : '';
            $button_label = $selected_title !== '' ? $selected_title : 'Selecionar post';

            $search_nonce = wp_create_nonce('arc_link_suggestions_posts_search');
            $initial = self::query_picker_posts('', 1, 10);
            $has_more = !empty($initial['has_more']);
            $items = !empty($initial['items']) && is_array($initial['items']) ? $initial['items'] : array();

            echo '<div id="arc-link-picker" class="relative space-y-3" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr($search_nonce) . '" data-per-page="10" data-current-page="1" data-has-more="' . ($has_more ? '1' : '0') . '" data-selected-title="' . esc_attr($selected_title) . '" data-selected-meta="' . esc_attr($selected_meta) . '">';
            echo '<input type="hidden" name="source_post_id" id="arc-link-picker-value" value="' . esc_attr($selected_post_id) . '" />';
            echo '<button type="button" id="arc-link-picker-toggle" class="flex w-full items-center justify-between rounded-2xl border border-slate-300 bg-white px-4 py-3 text-left text-sm font-medium text-slate-900 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-indigo-200" aria-expanded="false">';
            echo '<span id="arc-link-picker-label">' . esc_html($button_label) . '</span>';
            echo '<svg class="h-4 w-4 shrink-0 text-slate-400 transition" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            echo '</button>';
            echo '<div id="arc-link-picker-menu" class="absolute left-0 right-0 top-full z-20 mt-2 hidden rounded-2xl border border-slate-200 bg-white shadow-soft">';
            echo '<div class="flex items-center gap-2 border-b border-slate-200 p-3">';
            echo '<input id="arc-link-picker-search" type="search" class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Buscar post..." autocomplete="off" />';
            echo '<button type="button" id="arc-link-picker-search-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Buscar</button>';
            echo '</div>';
            echo '<div id="arc-link-picker-results" class="max-h-80 space-y-2 overflow-y-auto p-3 pr-1">';
            if (!empty($items)) {
                foreach ($items as $item) {
                    echo self::render_picker_post_button($item, $selected_post_id);
                }
            }
            echo '</div>';
            echo '<div class="border-t border-slate-200 p-3">';
            echo '<button type="button" id="arc-link-picker-load-more" class="' . ($has_more ? '' : 'hidden ') . 'inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Carregar mais</button>';
            echo '<p id="arc-link-picker-empty" class="hidden px-1 pt-2 text-sm text-slate-500">Nenhum post encontrado.</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        private static function get_suggestions_meta($post_id)
        {
            $raw = (string) get_post_meta(intval($post_id), self::META_JSON, true);
            if ($raw === '') {
                return array();
            }

            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        }

        private static function normalize_suggestion_item($item, $index, $candidate_lookup = array(), $source_linkable_text = '')
        {
            $item = is_array($item) ? $item : array();
            $anchor = '';
            foreach (array('anchor', 'anchor_phrase', 'phrase', 'keyword', 'kw', 'ancora', 'ancora_texto', 'text_anchor') as $key) {
                if (!empty($item[$key])) {
                    $anchor = self::normalize_plain_text((string) $item[$key]);
                    break;
                }
            }

            $apply_anchor = self::normalize_link_suggestion_anchor($anchor, $source_linkable_text);
            if ($apply_anchor === '') {
                $apply_anchor = $anchor;
            }

            $post_id = 0;
            foreach (array('post_id', 'id', 'target_post_id', 'target_id', 'postid') as $key) {
                if (!empty($item[$key])) {
                    $post_id = intval($item[$key]);
                    break;
                }
            }

            $title = '';
            foreach (array('title', 'post_title', 'target_title', 'nome', 'nome_post', 'post') as $key) {
                if (!empty($item[$key])) {
                    $title = self::normalize_plain_text((string) $item[$key]);
                    break;
                }
            }

            if ($post_id <= 0 && $title !== '') {
                $title_lookup = self::normalize_plain_text($title);
                if (isset($candidate_lookup['title'][$title_lookup])) {
                    $post_id = intval($candidate_lookup['title'][$title_lookup]['id']);
                    if ($candidate_lookup['title'][$title_lookup]['title'] !== '') {
                        $title = (string) $candidate_lookup['title'][$title_lookup]['title'];
                    }
                } else {
                    $loose_title_lookup = self::normalize_link_suggestion_key($title);
                    if ($loose_title_lookup !== '' && isset($candidate_lookup['loose_title'][$loose_title_lookup])) {
                        $post_id = intval($candidate_lookup['loose_title'][$loose_title_lookup]['id']);
                        if ($candidate_lookup['loose_title'][$loose_title_lookup]['title'] !== '') {
                            $title = (string) $candidate_lookup['loose_title'][$loose_title_lookup]['title'];
                        }
                    }
                }
            }

            if ($title === '' && $post_id > 0 && isset($candidate_lookup['id'][$post_id])) {
                $title = (string) $candidate_lookup['id'][$post_id]['title'];
            }

            $reason = '';
            foreach (array('reason', 'motivo', 'description', 'suggestion', 'sugestao', 'sugestão') as $key) {
                if (!empty($item[$key])) {
                    $reason = sanitize_textarea_field((string) $item[$key]);
                    break;
                }
            }

            return array(
                'index' => intval($index),
                'anchor' => $anchor,
                'apply_anchor' => $apply_anchor,
                'post_id' => $post_id,
                'title' => $title,
                'reason' => $reason,
            );
        }

        private static function normalize_link_suggestions_response($response, $requested_count, $candidate_lookup = array(), $source_post_id = 0, $source_linkable_text = '')
        {
            $requested_count = max(1, intval($requested_count));
            $source_post_id = intval($source_post_id);
            $normalized = array(
                'source_post_id' => 0,
                'requested_count' => $requested_count,
                'suggestions' => array(),
            );

            if (!is_array($response)) {
                return $normalized;
            }

            if (!empty($response['source_post_id'])) {
                $normalized['source_post_id'] = intval($response['source_post_id']);
            }

            $items = array();
            if (!empty($response['suggestions']) && is_array($response['suggestions'])) {
                $items = $response['suggestions'];
            } elseif (!empty($response['links']) && is_array($response['links'])) {
                $items = $response['links'];
            } elseif (!empty($response['items']) && is_array($response['items'])) {
                $items = $response['items'];
            } elseif (!empty($response['results']) && is_array($response['results'])) {
                $items = $response['results'];
            } elseif (!empty($response['data']) && is_array($response['data'])) {
                $nested = $response['data'];
                if (!empty($nested['suggestions']) && is_array($nested['suggestions'])) {
                    $items = $nested['suggestions'];
                } elseif (!empty($nested['links']) && is_array($nested['links'])) {
                    $items = $nested['links'];
                } elseif (!empty($nested['items']) && is_array($nested['items'])) {
                    $items = $nested['items'];
                } elseif (!empty($nested['results']) && is_array($nested['results'])) {
                    $items = $nested['results'];
                }
            } elseif (!empty($response) && array_values($response) === $response) {
                $items = $response;
            }

            if (empty($items) && !empty($response) && is_array($response)) {
                foreach ($response as $key => $value) {
                    if (!is_string($key) && !is_int($key)) {
                        continue;
                    }
                    if (!is_array($value)) {
                        continue;
                    }
                    if (is_int($key) || ctype_digit((string) $key)) {
                        $items[] = $value;
                    }
                }
            }

            $seen = array();
            $seen_post_ids = array();
            foreach ($items as $index => $item) {
                $suggestion = self::normalize_suggestion_item($item, $index + 1, $candidate_lookup, $source_linkable_text);
                if ($suggestion['anchor'] === '' || intval($suggestion['post_id']) <= 0) {
                    continue;
                }

                if ($source_post_id > 0 && intval($suggestion['post_id']) === $source_post_id) {
                    continue;
                }

                $anchor_key = self::normalize_link_suggestion_key($suggestion['anchor']);
                $dedupe_key = intval($suggestion['post_id']) . '|' . $anchor_key;
                if ($anchor_key === '' || isset($seen[$dedupe_key]) || isset($seen['anchor:' . $anchor_key])) {
                    continue;
                }
                if (isset($seen_post_ids[intval($suggestion['post_id'])])) {
                    continue;
                }
                $seen[$dedupe_key] = true;
                $seen['anchor:' . $anchor_key] = true;
                $seen_post_ids[intval($suggestion['post_id'])] = true;

                $normalized['suggestions'][] = $suggestion;
                if (count($normalized['suggestions']) >= $requested_count) {
                    break;
                }
            }

            return $normalized;
        }

        private static function build_link_suggestion_prompt($source, $candidates, $requested_count, $custom_prompt = '')
        {
            $source = is_array($source) ? $source : array();
            $candidates = is_array($candidates) ? array_values($candidates) : array();
            $requested_count = max(1, intval($requested_count));

            $source_payload = array(
                'id' => !empty($source['id']) ? intval($source['id']) : 0,
                'title' => !empty($source['title']) ? (string) $source['title'] : '',
                'content_html' => !empty($source['content_html']) ? (string) $source['content_html'] : '',
                'linkable_text' => !empty($source['linkable_text']) ? (string) $source['linkable_text'] : '',
            );

            $candidate_payload = array();
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidate_payload[] = array(
                    'id' => !empty($candidate['id']) ? intval($candidate['id']) : 0,
                    'title' => !empty($candidate['title']) ? (string) $candidate['title'] : '',
                );
            }

            $custom_prompt = self::normalize_plain_text($custom_prompt);

            $lines = array(
                'Analise o conteúdo de origem e encontre frases que possam receber links internos.',
                'ID do post de origem: ' . (!empty($source_payload['id']) ? intval($source_payload['id']) : 0) . '.',
                'Não invente títulos, IDs ou URLs.',
                'Nunca sugira o post de origem.',
                'Não repita o mesmo post alvo.',
                'Seria interessante que fossem escolhidos termos por todo o conteúdo permitido, ou seja, do início ao fim e que evitasse que fossem muito próximos ou até mesmo no mesmo parágrafo.',
                'Cada sugestao deve ter somente: anchor e post_id.',
                'Não use frase completa, não use o primeiro parágrafo, não use headings, legendas e botoes.',
                'Retorne até ' . $requested_count . ' sugestões.',
                'Não complete a lista repetindo o mesmo post_id.',
                $custom_prompt !== '' ? 'Observação do usuário: ' . $custom_prompt : '',
                'Conteudo de origem em JSON: ' . wp_json_encode($source_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'Posts candidatos em JSON: ' . wp_json_encode($candidate_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'FORMATO DE SAIDA',
                'Retorne exclusivamente o JSON valido com exatamente esta estrutura:',
                '{',
                '  "suggestions": [',
                '    {',
                '      "anchor": "",',
                '      "post_id": 0',
                '    }',
                '  ]',
                '}',
            );

            $lines = array_values(array_filter($lines, 'strlen'));
            return implode("\n", $lines);
        }

        private static function build_candidate_lookup($candidates)
        {
            $lookup = array(
                'id' => array(),
                'title' => array(),
                'loose_title' => array(),
            );

            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $id = !empty($candidate['id']) ? intval($candidate['id']) : 0;
                $title = !empty($candidate['title']) ? self::normalize_plain_text((string) $candidate['title']) : '';
                $loose_title = !empty($candidate['title']) ? self::normalize_link_suggestion_key((string) $candidate['title']) : '';
                if ($id > 0) {
                    $lookup['id'][$id] = $candidate;
                }
                if ($title !== '') {
                    $lookup['title'][self::normalize_plain_text($title)] = $candidate;
                }
                if ($loose_title !== '') {
                    $lookup['loose_title'][$loose_title] = $candidate;
                }
            }

            return $lookup;
        }

        private static function build_link_suggestions_fallback($source, $candidates, $requested_count)
        {
            $source = is_array($source) ? $source : array();
            $candidates = is_array($candidates) ? array_values($candidates) : array();
            $requested_count = max(1, intval($requested_count));

            $source_linkable_text = !empty($source['linkable_text']) ? self::normalize_plain_text((string) $source['linkable_text']) : '';
            $source_haystack = $source_linkable_text;
            if ($source_haystack === '') {
                return array();
            }

            $scores = array();
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $post_id = !empty($candidate['id']) ? intval($candidate['id']) : 0;
                $title = !empty($candidate['title']) ? self::normalize_plain_text((string) $candidate['title']) : '';
                if ($post_id <= 0 || $title === '') {
                    continue;
                }

                $normalized_title = self::normalize_link_suggestion_key($title);
                if ($normalized_title === '') {
                    continue;
                }

                $score = 0;
                if (mb_stripos($source_haystack, $title, 0, 'UTF-8') !== false) {
                    $score += 500;
                }

                $tokens = array_values(array_filter(preg_split('/\s+/u', $normalized_title), 'strlen'));
                $token_count = count($tokens);
                if ($token_count > 0) {
                    $max_window = min(4, $token_count);
                    for ($window = $max_window; $window >= 1; $window--) {
                        for ($offset = 0; $offset <= $token_count - $window; $offset++) {
                            $phrase = implode(' ', array_slice($tokens, $offset, $window));
                            if ($phrase === '') {
                                continue;
                            }
                            if (mb_stripos(self::normalize_link_suggestion_key($source_haystack), $phrase, 0, 'UTF-8') !== false) {
                                $score += ($window * 120) + 5;
                            }
                        }
                    }
                }

                if ($score <= 0) {
                    continue;
                }

                $scores[] = array(
                    'candidate' => $candidate,
                    'score' => $score,
                );
            }

            if (empty($scores)) {
                return array();
            }

            usort($scores, static function ($left, $right) {
                $left_score = isset($left['score']) ? intval($left['score']) : 0;
                $right_score = isset($right['score']) ? intval($right['score']) : 0;
                if ($left_score === $right_score) {
                    return 0;
                }
                return ($left_score > $right_score) ? -1 : 1;
            });

            $fallback = array();
            $seen_post_ids = array();
            foreach ($scores as $item) {
                $candidate = isset($item['candidate']) && is_array($item['candidate']) ? $item['candidate'] : array();
                $post_id = !empty($candidate['id']) ? intval($candidate['id']) : 0;
                $title = !empty($candidate['title']) ? self::normalize_plain_text((string) $candidate['title']) : '';
                if ($post_id <= 0 || $title === '' || isset($seen_post_ids[$post_id])) {
                    continue;
                }

                $anchor = $title;
                if (!empty($source_haystack) && mb_stripos($source_haystack, $title, 0, 'UTF-8') !== false) {
                    $anchor = $title;
                } else {
                    $tokens = array_values(array_filter(preg_split('/\s+/u', self::normalize_link_suggestion_key($title)), 'strlen'));
                    $candidate_anchor = '';
                    for ($window = min(4, count($tokens)); $window >= 1; $window--) {
                        for ($offset = 0; $offset <= count($tokens) - $window; $offset++) {
                            $phrase = implode(' ', array_slice($tokens, $offset, $window));
                            if ($phrase === '') {
                                continue;
                            }
                            if (mb_stripos(self::normalize_link_suggestion_key($source_haystack), $phrase, 0, 'UTF-8') !== false) {
                                $candidate_anchor = $phrase;
                                break 2;
                            }
                        }
                    }

                    if ($candidate_anchor === '') {
                        continue;
                    }

                    $anchor = $candidate_anchor;
                }

                $fallback[] = array(
                    'index' => count($fallback) + 1,
                    'anchor' => $anchor,
                    'post_id' => $post_id,
                    'title' => $title,
                    'reason' => 'fallback',
                );
                $seen_post_ids[$post_id] = true;

                if (count($fallback) >= $requested_count) {
                    break;
                }
            }

            return $fallback;
        }

        private static function resolve_post_by_id($post_id)
        {
            $post_id = intval($post_id);
            if ($post_id <= 0) {
                return null;
            }

            $post = get_post($post_id);
            if (!($post instanceof WP_Post)) {
                return null;
            }

            if ($post->post_status !== 'publish') {
                return null;
            }

            return $post;
        }

        private static function build_internal_link_rules_from_suggestions($suggestions)
        {
            $suggestions = is_array($suggestions) ? $suggestions : array();
            $rules = array();
            $seen_post_ids = array();

            foreach ($suggestions as $suggestion) {
                if (!is_array($suggestion)) {
                    continue;
                }

                $post_id = !empty($suggestion['post_id']) ? intval($suggestion['post_id']) : 0;
                $anchor = !empty($suggestion['apply_anchor']) ? self::normalize_plain_text((string) $suggestion['apply_anchor']) : '';
                if ($anchor === '' && !empty($suggestion['anchor'])) {
                    $anchor = self::normalize_plain_text((string) $suggestion['anchor']);
                }
                if ($post_id <= 0 || $anchor === '') {
                    continue;
                }

                if (isset($seen_post_ids[$post_id])) {
                    continue;
                }

                $target_post = self::resolve_post_by_id($post_id);
                if (!$target_post instanceof WP_Post) {
                    continue;
                }

                $permalink = get_permalink($target_post);
                if ($permalink === '') {
                    continue;
                }

                $seen_post_ids[$post_id] = true;
                $rules[] = array(
                    'quantity' => 1,
                    'phrase' => $anchor,
                    'url' => $permalink,
                    'target_blank' => 0,
                    'nofollow' => 0,
                    'sponsored' => 0,
                    'ugc' => 0,
                );
            }

            return $rules;
        }

        private static function render_notice()
        {
            $notice = self::get_request_param('arc_notice', '');
            if ($notice === '') {
                return;
            }

            $class = 'notice-success';
            $message = '';

            if ($notice === 'generated') {
                $count = intval(self::get_request_param('arc_count', 0));
                $message = $count > 0 ? sprintf('Sugestões geradas com sucesso. %d item(s) pronto(s) para aplicar.', $count) : 'Sugestões geradas com sucesso.';
            } elseif ($notice === 'applied') {
                $count = intval(self::get_request_param('arc_count', 0));
                $message = $count > 0 ? sprintf('Links aplicados com sucesso. %d link(s) inserido(s).', $count) : 'Links aplicados com sucesso.';
            } elseif ($notice === 'cleared') {
                $message = 'Sugestões removidas.';
            } elseif ($notice === 'error') {
                $message = self::get_request_param('arc_message', 'Não foi possivel concluir a operacao.');
                $class = 'notice-error';
            }

            if ($message === '') {
                return;
            }

            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        private static function render_suggestions_table($plan)
        {
            if (empty($plan) || !is_array($plan)) {
                echo '<div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">Nenhuma sugestao gerada ainda.</div>';
                return;
            }

            $suggestions = !empty($plan['suggestions']) && is_array($plan['suggestions']) ? $plan['suggestions'] : array();
            if (empty($suggestions)) {
                echo '<div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">A IA não retornou sugestões válidas.</div>';
                return;
            }

            echo '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">';
            echo '<div class="border-b border-slate-200 px-6 py-4">';
            echo '<div class="flex flex-wrap items-start justify-between gap-3">';
            echo '<div>';
            echo '<h2 class="text-lg font-semibold text-slate-950">Sugestões salvas</h2>';
            if (!empty($plan['generated_at'])) {
                echo '<p class="mt-1 text-sm text-slate-500">Gerado em ' . esc_html($plan['generated_at']) . '</p>';
            }
            echo '</div>';
            echo '<div class="text-sm text-slate-500">' . esc_html(count($suggestions)) . ' sugestao(oes)</div>';
            echo '</div>';
            echo '</div>';

            echo '<div class="overflow-x-auto">';
            echo '<table class="min-w-full divide-y divide-slate-200">';
            echo '<thead class="bg-slate-50"><tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">';
            echo '<th class="px-6 py-3">#</th>';
            echo '<th class="px-6 py-3">Ancora</th>';
            echo '<th class="px-6 py-3">Post ID</th>';
            echo '<th class="px-6 py-3">Titulo</th>';
            echo '</tr></thead>';
            echo '<tbody class="divide-y divide-slate-100 bg-white">';
            foreach ($suggestions as $index => $suggestion) {
                $anchor = !empty($suggestion['anchor']) ? (string) $suggestion['anchor'] : '-';
                $post_id = !empty($suggestion['post_id']) ? intval($suggestion['post_id']) : 0;
                $title = !empty($suggestion['title']) ? (string) $suggestion['title'] : '-';

                echo '<tr class="align-top">';
                echo '<td class="px-6 py-4 text-sm text-slate-600">' . esc_html(intval($index) + 1) . '</td>';
                echo '<td class="px-6 py-4 text-sm text-slate-900">' . esc_html($anchor) . '</td>';
                echo '<td class="px-6 py-4 text-sm text-slate-700">' . esc_html($post_id > 0 ? $post_id : '-') . '</td>';
                echo '<td class="px-6 py-4 text-sm font-medium text-slate-900">' . esc_html($title) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
        }

        public function handle_generate_link_suggestions()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissao negada.');
            }

            check_admin_referer('arc_generate_link_suggestions', 'arc_link_suggestions_nonce');
            self::lift_execution_time_limit(300);

            $post_id = isset($_POST['source_post_id']) ? intval($_POST['source_post_id']) : 0;
            $requested_count = isset($_POST['suggestion_count']) ? intval($_POST['suggestion_count']) : 5;
            $requested_count = max(1, min(25, $requested_count));
            $custom_prompt = isset($_POST['custom_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['custom_prompt'])) : '';

            $context = self::get_source_context($post_id);
            if (is_wp_error($context)) {
                $this->redirect_with_notice($context->get_error_message(), 'error', array(
                    'post_id' => $post_id,
                ));
            }

            $generator = $context['generator'];
            $source = $context['source'];
            $candidates = $context['candidates'];

            $prompt = self::build_link_suggestion_prompt($source, $candidates, $requested_count, $custom_prompt);
            $response = Alpha_RSS_AI_Generator::request_openai_json($generator, $prompt, array(
                'stage' => 'link_suggestions',
                'source_type' => 'post',
                'item_guid' => !empty($source['id']) ? 'post:' . intval($source['id']) : '',
                'item_title' => !empty($source['title']) ? (string) $source['title'] : '',
                'source_context_enriched' => 1,
                'allow_missing_content_html' => 1,
                'preserve_extra_fields' => 1,
            ));

            if (is_wp_error($response)) {
                $this->redirect_with_notice($response->get_error_message(), 'error', array(
                    'post_id' => $post_id,
                ));
            }

            $candidate_lookup = self::build_candidate_lookup($candidates);
            $normalized = self::normalize_link_suggestions_response($response, $requested_count, $candidate_lookup, !empty($source['id']) ? intval($source['id']) : 0, !empty($source['linkable_text']) ? (string) $source['linkable_text'] : '');
            $normalized['source_post_id'] = $post_id;
            $normalized['requested_count'] = $requested_count;
            $normalized['generated_at'] = current_time('mysql');
            $normalized['custom_prompt'] = $custom_prompt;
            $normalized['source'] = array(
                'id' => intval($source['id']),
                'title' => isset($source['title']) ? $source['title'] : '',
            );
            $normalized['candidates_count'] = count($candidates);

            update_post_meta($post_id, self::META_JSON, wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_post_meta($post_id, self::META_GENERATED_AT, current_time('mysql'));
            update_post_meta($post_id, self::META_SOURCE_POST_ID, $post_id);
            update_post_meta($post_id, self::META_CUSTOM_PROMPT, $custom_prompt);
            update_post_meta($post_id, self::META_REQUESTED_COUNT, $requested_count);
            delete_post_meta($post_id, self::META_APPLIED_AT);
            delete_post_meta($post_id, self::META_APPLIED_COUNT);

            if (empty($normalized['suggestions'])) {
                $this->redirect_with_notice('Não foi possivel montar sugestoes validas.', 'error', array(
                    'post_id' => $post_id,
                ));
            }

            $this->redirect_with_notice('generated', 'success', array(
                'post_id' => $post_id,
                'arc_count' => count($normalized['suggestions']),
            ));
        }

        public function handle_apply_link_suggestions()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissao negada.');
            }

            check_admin_referer('arc_apply_link_suggestions', 'arc_link_suggestions_nonce');

            $post_id = isset($_POST['source_post_id']) ? intval($_POST['source_post_id']) : 0;
            if ($post_id <= 0) {
                $this->redirect_with_notice('Post invalido.', 'error', array(
                    'post_id' => $post_id,
                ));
            }

            $plan = self::get_suggestions_meta($post_id);
            $suggestions = !empty($plan['suggestions']) && is_array($plan['suggestions']) ? $plan['suggestions'] : array();
            if (empty($suggestions)) {
                $this->redirect_with_notice('Não existem sugestoes para aplicar.', 'error', array(
                    'post_id' => $post_id,
                ));
            }

            $rules = self::build_internal_link_rules_from_suggestions($suggestions);
            if (empty($rules)) {
                $this->redirect_with_notice('Nenhum post valido foi encontrado para aplicar os links.', 'error', array(
                    'post_id' => $post_id,
                ));
            }

            $content = (string) get_post_field('post_content', $post_id);
            if ($content === '') {
                $this->redirect_with_notice('O post não possui conteúdo para receber links.', 'error', array(
                    'post_id' => $post_id,
                ));
            }

            $generator = self::get_default_generator_context();
            $generator['internal_links_json'] = wp_json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $generator['internal_links_count'] = count($rules);

            $updated_content = Alpha_RSS_AI_Generator_Helper::apply_internal_links_to_content(
                $content,
                $generator,
                array(
                    'post_id' => $post_id,
                )
            );

            if ($updated_content === '' || $updated_content === $content) {
                $this->redirect_with_notice('Nenhum link foi inserido no conteúdo.', 'error', array(
                    'post_id' => $post_id,
                ));
            }

            $update_result = wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $updated_content,
            ), true);

            if (is_wp_error($update_result)) {
                $this->redirect_with_notice($update_result->get_error_message(), 'error', array(
                    'post_id' => $post_id,
                ));
            }

            update_post_meta($post_id, self::META_APPLIED_AT, current_time('mysql'));
            update_post_meta($post_id, self::META_APPLIED_COUNT, count($rules));

            $this->redirect_with_notice('applied', 'success', array(
                'post_id' => $post_id,
                'arc_count' => count($rules),
            ));
        }

        public static function generate_and_apply_link_suggestions_to_post($post_id, $generator = array(), $requested_count = 0, $custom_prompt = '', $content_html = '')
        {
            $post_id = intval($post_id);
            $post = $post_id > 0 ? get_post($post_id) : null;
            if (!$post instanceof WP_Post) {
                return new WP_Error('arc_link_suggestions_post_missing', 'Post não encontrado.');
            }

            self::lift_execution_time_limit(300);

            $generator = is_array($generator) ? $generator : array();
            $requested_count = intval($requested_count);
            if ($requested_count <= 0) {
                $requested_count = !empty($generator['internal_links_count']) ? intval($generator['internal_links_count']) : 5;
            }
            $requested_count = max(1, min(25, $requested_count));
            $custom_prompt = self::normalize_plain_text($custom_prompt);

            $content_html = $content_html !== '' ? (string) $content_html : (string) $post->post_content;
            if ($content_html === '') {
                return array(
                    'source_post_id' => $post_id,
                    'requested_count' => $requested_count,
                    'suggestions' => array(),
                    'applied_count' => 0,
                    'content_html' => '',
                );
            }

            $source_title = self::normalize_plain_text(get_the_title($post));
            $source_linkable_text = self::extract_linkable_text_from_html($content_html);

            $post_types = array_values(array_diff(get_post_types(array('public' => true), 'names'), array('attachment', 'revision', 'nav_menu_item')));
            if (empty($post_types)) {
                $post_types = array('post');
            }

            $candidates = self::query_candidate_posts($post_id, self::MAX_TARGET_POSTS, $post_types);
            if (empty($candidates)) {
                return array(
                    'source_post_id' => $post_id,
                    'requested_count' => $requested_count,
                    'suggestions' => array(),
                    'applied_count' => 0,
                    'content_html' => $content_html,
                    'generated_at' => current_time('mysql'),
                    'custom_prompt' => $custom_prompt,
                    'source' => array(
                        'id' => $post_id,
                        'title' => $source_title,
                    ),
                    'candidates_count' => 0,
                );
            }

            $source = array(
                'id' => $post_id,
                'title' => $source_title,
                'content_html' => $content_html,
                'linkable_text' => $source_linkable_text,
            );
            $prompt = self::build_link_suggestion_prompt($source, $candidates, $requested_count, $custom_prompt);
            $response = Alpha_RSS_AI_Generator::request_openai_json($generator, $prompt, array(
                'stage' => 'link_suggestions',
                'source_type' => 'post',
                'item_guid' => 'post:' . $post_id,
                'item_title' => $source_title,
                'source_context_enriched' => 1,
                'allow_missing_content_html' => 1,
                'preserve_extra_fields' => 1,
            ));

            if (is_wp_error($response)) {
                return $response;
            }

            $candidate_lookup = self::build_candidate_lookup($candidates);
            $normalized = self::normalize_link_suggestions_response($response, $requested_count, $candidate_lookup, $post_id, $source_linkable_text);
            $normalized['source_post_id'] = $post_id;
            $normalized['requested_count'] = $requested_count;
            $normalized['generated_at'] = current_time('mysql');
            $normalized['custom_prompt'] = $custom_prompt;
            $normalized['source'] = array(
                'id' => $post_id,
                'title' => $source_title,
            );
            $normalized['candidates_count'] = count($candidates);

            update_post_meta($post_id, self::META_JSON, wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_post_meta($post_id, self::META_GENERATED_AT, current_time('mysql'));
            update_post_meta($post_id, self::META_SOURCE_POST_ID, $post_id);
            update_post_meta($post_id, self::META_CUSTOM_PROMPT, $custom_prompt);
            update_post_meta($post_id, self::META_REQUESTED_COUNT, $requested_count);
            delete_post_meta($post_id, self::META_APPLIED_AT);
            delete_post_meta($post_id, self::META_APPLIED_COUNT);

            if (empty($normalized['suggestions'])) {
                return array_merge($normalized, array(
                    'applied_count' => 0,
                    'content_html' => $content_html,
                ));
            }

            $rules = self::build_internal_link_rules_from_suggestions($normalized['suggestions']);
            if (empty($rules)) {
                return array_merge($normalized, array(
                    'applied_count' => 0,
                    'content_html' => $content_html,
                ));
            }

            $working_generator = $generator;
            $working_generator['internal_links_json'] = wp_json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $working_generator['internal_links_count'] = count($rules);

            $updated_content = Alpha_RSS_AI_Generator_Helper::apply_internal_links_to_content(
                $content_html,
                $working_generator,
                array(
                    'post_id' => $post_id,
                    'item_guid' => 'post:' . $post_id,
                )
            );

            if ($updated_content !== '' && $updated_content !== $content_html) {
                $update_result = wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $updated_content,
                ), true);
                if (!is_wp_error($update_result)) {
                    $content_html = $updated_content;
                }
            }

            update_post_meta($post_id, self::META_APPLIED_AT, current_time('mysql'));
            update_post_meta($post_id, self::META_APPLIED_COUNT, count($rules));

            return array_merge($normalized, array(
                'applied_count' => count($rules),
                'content_html' => $content_html,
            ));
        }

        public function handle_clear_link_suggestions()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissao negada.');
            }

            check_admin_referer('arc_clear_link_suggestions', 'arc_link_suggestions_nonce');

            $post_id = isset($_POST['source_post_id']) ? intval($_POST['source_post_id']) : 0;
            if ($post_id > 0) {
                delete_post_meta($post_id, self::META_JSON);
                delete_post_meta($post_id, self::META_GENERATED_AT);
                delete_post_meta($post_id, self::META_SOURCE_POST_ID);
                delete_post_meta($post_id, self::META_CUSTOM_PROMPT);
                delete_post_meta($post_id, self::META_REQUESTED_COUNT);
                delete_post_meta($post_id, self::META_APPLIED_AT);
                delete_post_meta($post_id, self::META_APPLIED_COUNT);
            }

            $this->redirect_with_notice('cleared', 'success', array(
                'post_id' => $post_id,
            ));
        }

        private function redirect_with_notice($message, $type = 'success', $extra = array())
        {
            $url = add_query_arg(array_merge(array(
                'page' => self::PAGE_SLUG,
                'arc_notice' => $message,
                'arc_notice_type' => $type,
            ), $extra), admin_url('admin.php'));

            wp_safe_redirect($url);
            exit;
        }

        private static function render_selected_card($selected_post_id)
        {
            $selected_post_id = intval($selected_post_id);
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $title = $selected_post instanceof WP_Post ? self::normalize_plain_text(get_the_title($selected_post)) : '';
            $post_type = $selected_post instanceof WP_Post && !empty($selected_post->post_type) ? $selected_post->post_type : 'post';
            $hidden_class = $selected_post instanceof WP_Post ? '' : ' hidden';

            $html = '<div id="arc-link-selected-card" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm' . esc_attr($hidden_class) . '">';
            $html .= '<div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Post selecionado</div>';
            $html .= '<div id="arc-link-selected-title" class="mt-2 text-base font-semibold text-slate-950">' . esc_html($title) . '</div>';
            $html .= '<div id="arc-link-selected-meta" class="mt-1 text-sm text-slate-500">' . ($selected_post instanceof WP_Post ? 'ID ' . esc_html($selected_post_id) . ' · ' . esc_html($post_type) : '') . '</div>';
            $html .= '</div>';

            return $html;
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            $selected_post_id = intval(self::get_request_param('post_id', 0));
            $plan = $selected_post_id > 0 ? self::get_suggestions_meta($selected_post_id) : array();
            $stored_generated_at = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_GENERATED_AT, true) : '';
            if ($stored_generated_at !== '') {
                $plan['generated_at'] = $stored_generated_at;
            }
            $stored_custom_prompt = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_CUSTOM_PROMPT, true) : '';
            if ($stored_custom_prompt !== '' && empty($plan['custom_prompt'])) {
                $plan['custom_prompt'] = $stored_custom_prompt;
            }
            $stored_requested_count = $selected_post_id > 0 ? intval(get_post_meta($selected_post_id, self::META_REQUESTED_COUNT, true)) : 0;
            if ($stored_requested_count > 0 && empty($plan['requested_count'])) {
                $plan['requested_count'] = $stored_requested_count;
            }

            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $selected_title = $selected_post instanceof WP_Post ? self::normalize_plain_text(get_the_title($selected_post)) : '';
            $selected_meta = $selected_post instanceof WP_Post ? 'ID ' . intval($selected_post_id) . ' · ' . $selected_post->post_type : '';

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
            <div class="wrap arc-wrap min-h-screen bg-slate-100 text-slate-900">
                <h1 class="screen-reader-text">Alpha RSS AI</h1>
                <div class="mb-6">
                    <div class="text-xs font-semibold uppercase tracking-[0.25em] text-indigo-600">Alpha RSS AI</div>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Sugestões de links</h1>
                </div>

                <?php self::render_notice(); ?>

                <div class="grid gap-6 xl:grid-cols-[360px_minmax(0,1fr)]">
                    <aside class="space-y-6">
                        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-soft">
                            <div>
                                <h2 class="text-lg font-semibold text-slate-950">Parametros</h2>
                            </div>

                            <div class="mt-5">
                                <?php self::render_posts_selector($selected_post_id); ?>
                            </div>

                            <div class="mt-5">
                                <?php echo self::render_selected_card($selected_post_id); ?>
                            </div>

                            <div id="arc-link-options" class="mt-5 space-y-4 <?php echo $selected_post instanceof WP_Post ? '' : 'hidden'; ?>">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="space-y-4">
                                    <?php wp_nonce_field('arc_generate_link_suggestions', 'arc_link_suggestions_nonce'); ?>
                                    <input type="hidden" name="action" value="arc_generate_link_suggestions" />
                                    <input type="hidden" name="source_post_id" id="arc-link-picker-value-form" value="<?php echo esc_attr($selected_post_id); ?>" />

                                    <div>
                                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Qtd. links</label>
                                        <input type="number" min="1" max="25" name="suggestion_count" value="<?php echo esc_attr(isset($plan['requested_count']) ? intval($plan['requested_count']) : 5); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>

                                    <details class="group rounded-2xl border border-slate-200 bg-slate-50">
                                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-semibold text-slate-700">
                                            <span>Prompt personalizado</span>
                                            <svg class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false"><path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </summary>
                                        <div class="border-t border-slate-200 px-4 py-4">
                                            <textarea name="custom_prompt" rows="5" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Instrucoes extras para a IA, se precisar."><?php echo esc_textarea(isset($plan['custom_prompt']) ? $plan['custom_prompt'] : ''); ?></textarea>
                                        </div>
                                    </details>

                                    <button type="submit" id="arc-link-generate-button" <?php echo $selected_post instanceof WP_Post ? '' : 'disabled="disabled"'; ?> class="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-emerald-500 <?php echo $selected_post instanceof WP_Post ? '' : 'opacity-50 cursor-not-allowed'; ?>">Gerar sugestões</button>
                                </form>

                                <?php if (!empty($plan) && !empty($plan['suggestions']) && is_array($plan['suggestions'])): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('arc_apply_link_suggestions', 'arc_link_suggestions_nonce'); ?>
                                        <input type="hidden" name="action" value="arc_apply_link_suggestions" />
                                        <input type="hidden" name="source_post_id" value="<?php echo esc_attr($selected_post_id); ?>" />
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-slate-800">Aplicar links</button>
                                    </form>

                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mt-3" data-swal-confirm="Remover as sugestoes salvas deste post?">
                                        <?php wp_nonce_field('arc_clear_link_suggestions', 'arc_link_suggestions_nonce'); ?>
                                        <input type="hidden" name="action" value="arc_clear_link_suggestions" />
                                        <input type="hidden" name="source_post_id" value="<?php echo esc_attr($selected_post_id); ?>" />
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-100">Limpar sugestões</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </aside>

                    <main class="min-w-0">
                        <?php self::render_suggestions_table($plan); ?>
                    </main>
                </div>
            </div>

            <script>
                (function () {
                    const picker = document.getElementById('arc-link-picker');
                    const optionsWrap = document.getElementById('arc-link-options');
                    const toggleButton = document.getElementById('arc-link-picker-toggle');
                    const labelNode = document.getElementById('arc-link-picker-label');
                    const menu = document.getElementById('arc-link-picker-menu');
                    const searchInput = document.getElementById('arc-link-picker-search');
                    const searchButton = document.getElementById('arc-link-picker-search-btn');
                    const results = document.getElementById('arc-link-picker-results');
                    const loadMoreButton = document.getElementById('arc-link-picker-load-more');
                    const emptyState = document.getElementById('arc-link-picker-empty');
                    const generateButton = document.getElementById('arc-link-generate-button');
                    const valueInputs = document.querySelectorAll('input[name="source_post_id"], #arc-link-picker-value-form');
                    const selectedCard = document.getElementById('arc-link-selected-card');
                    const selectedTitle = document.getElementById('arc-link-selected-title');
                    const selectedMeta = document.getElementById('arc-link-selected-meta');
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
                        if (selectedCard) {
                            selectedCard.classList.toggle('hidden', selectedPostId <= 0);
                        }
                        syncOptionsVisibility();
                        syncGenerateState();
                    };

                    const syncOptionsVisibility = () => {
                        const hasSearchText = searchInput ? searchInput.value.trim() !== '' : false;
                        const shouldShowOptions = selectedPostId > 0 || hasSearchText;
                        optionsWrap.classList.toggle('hidden', !shouldShowOptions);
                    };

                    const syncGenerateState = () => {
                        if (!generateButton) {
                            return;
                        }
                        const canGenerate = selectedPostId > 0;
                        generateButton.disabled = !canGenerate;
                        generateButton.classList.toggle('opacity-50', !canGenerate);
                        generateButton.classList.toggle('cursor-not-allowed', !canGenerate);
                    };

                    const setMenuOpen = (isOpen) => {
                        menu.classList.toggle('hidden', !isOpen);
                        toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        if (isOpen && searchInput) {
                            window.setTimeout(() => searchInput.focus(), 0);
                        }
                    };

                    const syncActiveButtons = () => {
                        const buttons = results.querySelectorAll('.arc-link-picker-item');
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
                            const button = event.target.closest('.arc-link-picker-item');
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
                        if (selectedTitle) {
                            selectedTitle.textContent = item.title || 'Post';
                        }
                        if (selectedMeta) {
                            selectedMeta.textContent = 'ID ' + String(item.id || 0) + ' · ' + String(item.post_type || 'post');
                        }
                        setMenuOpen(false);
                        syncActiveButtons();
                    };

                    const renderButton = (item) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'arc-link-picker-item w-full rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-2 focus:ring-indigo-200';
                        button.dataset.postId = item.id;
                        button.dataset.postTitle = item.title || '';
                        button.dataset.postType = item.post_type || 'post';
                        button.setAttribute('aria-pressed', selectedPostId > 0 && parseInt(item.id || '0', 10) === selectedPostId ? 'true' : 'false');
                        if (selectedPostId > 0 && parseInt(item.id || '0', 10) === selectedPostId) {
                            button.classList.add('border-indigo-500', 'bg-indigo-50');
                        } else {
                            button.classList.add('border-slate-200', 'bg-white', 'hover:bg-slate-50');
                        }

                        button.innerHTML = '<div class="font-medium text-slate-900">' + escapeHtml(item.label || item.title || 'Post') + '</div><div class="mt-1 text-xs text-slate-500">ID ' + escapeHtml(item.id || 0) + ' · ' + escapeHtml(item.post_type || 'post') + '</div>';
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
                                    action: 'arc_link_suggestions_search_posts',
                                    nonce: nonce,
                                    search: search,
                                    page: String(page),
                                    per_page: String(perPage)
                                }).toString()
                            });

                            const payload = await response.json();
                            if (!payload || !payload.success) {
                                throw new Error((payload && payload.data && payload.data.message) ? payload.data.message : 'Não foi possivel carregar os posts.');
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
                        syncOptionsVisibility();
                        fetchPosts({ search: currentSearch, page: 1, append: false });
                    };

                    const initialSelectedInput = Array.from(valueInputs).find((input) => parseInt(input.value || '0', 10) > 0);
                    if (initialSelectedInput) {
                        selectedPostId = parseInt(initialSelectedInput.value || '0', 10) || 0;
                        optionsWrap.classList.remove('hidden');
                        if (selectedCard) {
                            selectedCard.classList.remove('hidden');
                        }
                    }

                    if (selectedPostId <= 0) {
                        optionsWrap.classList.add('hidden');
                        if (selectedCard) {
                            selectedCard.classList.add('hidden');
                        }
                    }
                    syncGenerateState();

                    const initialTitle = picker.dataset.selectedTitle || '';
                    const initialMeta = picker.dataset.selectedMeta || '';
                    if (initialTitle !== '') {
                        updateLabel(initialTitle);
                    }
                    if (selectedTitle && initialTitle !== '') {
                        selectedTitle.textContent = initialTitle;
                    }
                    if (selectedMeta && initialMeta !== '') {
                        selectedMeta.textContent = initialMeta;
                    }

                    setLoadMoreState();
                    setEmptyState(results.querySelectorAll('.arc-link-picker-item').length === 0);
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
                            syncOptionsVisibility();
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
                })();
            </script>
            <?php
        }
    }
}
