<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Alpha_RSS_AI_Generated_Posts')) {
    final class Alpha_RSS_AI_Generated_Posts
    {
        public const PAGE_SLUG = 'alpha-rss-ai-generated-posts';

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 21);
            add_action('admin_post_arc_regenerate_generated_post', array($this, 'handle_regenerate_post'));
            add_action('admin_post_arc_delete_generated_post', array($this, 'handle_delete_post'));
        }

        public function admin_menu()
        {
            add_submenu_page(
                'alpha-rss-ai-generator',
                'Posts gerados',
                'Posts gerados',
                'manage_options',
                self::PAGE_SLUG,
                array($this, 'render_page')
            );
        }

        private static function truncate_text($text, $limit = 120)
        {
            $text = trim((string) $text);
            if ($text === '') {
                return '';
            }

            $limit = max(20, intval($limit));
            if (function_exists('mb_strimwidth')) {
                return mb_strimwidth($text, 0, $limit, '...');
            }

            return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
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

        private static function get_filtered_query($paged, $per_page, $generator_id = 0, $search = '')
        {
            $args = array(
                'post_type' => 'any',
                'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
                'posts_per_page' => max(1, intval($per_page)),
                'paged' => max(1, intval($paged)),
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => array(
                    array(
                        'key' => '_arc_generator_id',
                        'compare' => 'EXISTS',
                    ),
                ),
            );

            if (intval($generator_id) > 0) {
                $args['meta_query'][] = array(
                    'key' => '_arc_generator_id',
                    'value' => intval($generator_id),
                    'compare' => '=',
                    'type' => 'NUMERIC',
                );
            }

            if ($search !== '') {
                $args['s'] = $search;
            }

            return new WP_Query($args);
        }

        private static function get_generator_name($generator_id)
        {
            static $cache = array();
            $generator_id = intval($generator_id);
            if ($generator_id <= 0) {
                return '';
            }

            if (!array_key_exists($generator_id, $cache)) {
                $generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
                $cache[$generator_id] = !empty($generator['name']) ? $generator['name'] : '';
            }

            return $cache[$generator_id];
        }

        private static function resolve_generated_post_context($post_id)
        {
            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('arc_generated_post_missing', 'Post nao encontrado.');
            }

            $generator_id = intval(get_post_meta($post_id, '_arc_generator_id', true));
            if ($generator_id <= 0) {
                return new WP_Error('arc_generated_post_missing_generator', 'Este post nao possui gerador vinculado.');
            }

            $generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
            if (!$generator) {
                return new WP_Error('arc_generated_post_generator_missing', 'Gerador original nao encontrado.');
            }

            $item = array(
                'guid' => (string) get_post_meta($post_id, '_arc_source_item_guid', true),
                'title' => (string) get_post_meta($post_id, '_arc_source_item_title', true),
                'permalink' => (string) get_post_meta($post_id, '_arc_source_item_permalink', true),
                'excerpt' => '',
                'content' => '',
                'feed_title' => '',
                'date' => (string) get_post_meta($post_id, '_arc_source_timestamp', true),
                'categories' => array(),
                'source_image_url' => (string) get_post_meta($post_id, '_arc_source_image_url', true),
                'source_link_url' => (string) get_post_meta($post_id, '_arc_source_link_url', true),
                'source_link_text' => (string) get_post_meta($post_id, '_arc_source_link_text', true),
                'source_page_title' => (string) get_post_meta($post_id, '_arc_source_page_title', true),
                'source_page_excerpt' => (string) get_post_meta($post_id, '_arc_source_page_excerpt', true),
                'source_page_content' => (string) get_post_meta($post_id, '_arc_source_page_content', true),
                'source_page_outline' => (string) get_post_meta($post_id, '_arc_source_page_outline', true),
                'source_page_outline_sections' => array(),
                'source_video_url' => (string) get_post_meta($post_id, '_arc_source_video_url', true),
                'source_video_embed_html' => (string) get_post_meta($post_id, '_arc_source_video_embed_html', true),
                'source_video_source' => (string) get_post_meta($post_id, '_arc_source_video_source', true),
                'outline_target_h2_min' => intval(get_post_meta($post_id, '_arc_outline_target_h2_min', true)),
                'outline_target_h2_max' => intval(get_post_meta($post_id, '_arc_outline_target_h2_max', true)),
                'outline_target_h2_count' => intval(get_post_meta($post_id, '_arc_outline_target_h2_count', true)),
                'outline_block_quantities' => array(),
                'source_image_selector_class' => (string) get_post_meta($post_id, '_arc_source_image_selector_class', true),
                'source_link_selector_class' => (string) get_post_meta($post_id, '_arc_source_link_selector_class', true),
                'source_title' => (string) get_post_meta($post_id, '_arc_source_title', true),
                'source_url' => (string) get_post_meta($post_id, '_arc_source_url', true),
                'keyword' => (string) get_post_meta($post_id, '_arc_source_keyword', true),
                'final_slug' => (string) get_post_meta($post_id, '_arc_source_final_slug', true),
                'source_context_enriched' => 0,
            );
            $original_item = $item;

            $outline_sections_raw = (string) get_post_meta($post_id, '_arc_source_page_outline_sections', true);
            if ($outline_sections_raw !== '') {
                $outline_sections = json_decode($outline_sections_raw, true);
                if (is_array($outline_sections)) {
                    $item['source_page_outline_sections'] = $outline_sections;
                }
            }

            $outline_block_quantities_raw = (string) get_post_meta($post_id, '_arc_outline_block_quantities', true);
            if ($outline_block_quantities_raw !== '') {
                $outline_block_quantities = json_decode($outline_block_quantities_raw, true);
                if (is_array($outline_block_quantities)) {
                    $item['outline_block_quantities'] = $outline_block_quantities;
                }
            }

            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            $video_selector_class = !empty($generator['video_selector_class']) ? sanitize_text_field((string) $generator['video_selector_class']) : '';
            $image_selector_class = !empty($generator['image_selector_class'])
                ? sanitize_text_field((string) $generator['image_selector_class'])
                : (string) get_post_meta($post_id, '_arc_source_image_selector_class', true);
            $link_selector_class = !empty($generator['link_selector_class'])
                ? sanitize_text_field((string) $generator['link_selector_class'])
                : (string) get_post_meta($post_id, '_arc_source_link_selector_class', true);
            $content_image_size = !empty($generator['content_image_size'])
                ? Alpha_RSS_AI_Generator::normalize_image_display_size((string) $generator['content_image_size'])
                : Alpha_RSS_AI_Generator::normalize_image_display_size((string) get_post_meta($post_id, '_arc_content_image_size', true));
            $content_selector = !empty($generator['content_selector'])
                ? sanitize_text_field((string) $generator['content_selector'])
                : (string) get_post_meta($post_id, '_arc_content_selector', true);
            if (empty($generator['content_image_size'])) {
                $generator['content_image_size'] = $content_image_size;
            }
            if (empty($generator['image_selector_class']) && $image_selector_class !== '') {
                $generator['image_selector_class'] = $image_selector_class;
            }
            if (empty($generator['link_selector_class']) && $link_selector_class !== '') {
                $generator['link_selector_class'] = $link_selector_class;
            }
            if (empty($generator['content_selector']) && $content_selector !== '') {
                $generator['content_selector'] = $content_selector;
            }

            if ($source_type === 'keyword_list') {
                $list_id = intval(get_post_meta($post_id, '_arc_source_list_id', true));
                if ($list_id <= 0 && !empty($generator['list_id'])) {
                    $list_id = intval($generator['list_id']);
                }
                if ($list_id <= 0) {
                    return new WP_Error('arc_generated_post_missing_list', 'Este post nao possui lista vinculada.');
                }

                $list = Alpha_RSS_AI_Generator::get_keyword_list($list_id);
                if (!$list) {
                    return new WP_Error('arc_generated_post_list_missing', 'Lista original nao encontrada.');
                }

                $row = Alpha_RSS_AI_Generator::get_keyword_list_row_by_guid($list_id, $item['guid']);
                if (!$row) {
                    return new WP_Error('arc_generated_post_row_missing', 'Linha original nao encontrada na lista.');
                }

                $item = Alpha_RSS_AI_Generator::build_keyword_list_item_from_row($list, $row, true, $video_selector_class, $image_selector_class, $link_selector_class, array(), $content_selector);
                foreach (array(
                    'source_image_url',
                    'source_link_url',
                    'source_link_text',
                    'source_video_url',
                    'source_video_embed_html',
                    'source_video_source',
                    'outline_target_h2_min',
                    'outline_target_h2_max',
                    'outline_target_h2_count',
                    'outline_block_quantities',
                    'source_image_selector_class',
                    'source_link_selector_class',
                    'source_page_title',
                    'source_page_excerpt',
                    'source_page_content',
                    'source_page_outline',
                    'source_page_outline_sections',
                ) as $key) {
                    if ((empty($item[$key]) || $item[$key] === array()) && !empty($original_item[$key])) {
                        $item[$key] = $original_item[$key];
                    }
                }
                if ($item['title'] === '' && !empty($post->post_title)) {
                    $item['title'] = $post->post_title;
                }
                if (empty($item['source_title']) && !empty(get_post_meta($post_id, '_arc_source_title', true))) {
                    $item['source_title'] = (string) get_post_meta($post_id, '_arc_source_title', true);
                }
                if (empty($item['source_url']) && !empty(get_post_meta($post_id, '_arc_source_url', true))) {
                    $item['source_url'] = (string) get_post_meta($post_id, '_arc_source_url', true);
                }
                if (empty($item['keyword']) && !empty(get_post_meta($post_id, '_arc_source_keyword', true))) {
                    $item['keyword'] = (string) get_post_meta($post_id, '_arc_source_keyword', true);
                }
            } else {
                $feed_url = (string) get_post_meta($post_id, '_arc_source_feed_url', true);
                if ($feed_url === '' && !empty($generator['feed_url'])) {
                    $feed_url = (string) $generator['feed_url'];
                }
                if ($feed_url === '') {
                    return new WP_Error('arc_generated_post_missing_feed', 'Feed original nao encontrado.');
                }

                $rss_items = Alpha_RSS_AI_Generator::get_rss_items(
                    $feed_url,
                    500,
                    true,
                    $video_selector_class,
                    $image_selector_class,
                    $link_selector_class
                );
                if (is_wp_error($rss_items)) {
                    return $rss_items;
                }

                $found_item = null;
                foreach ($rss_items as $candidate) {
                    $candidate_guid = isset($candidate['guid']) ? (string) $candidate['guid'] : '';
                    $candidate_permalink = isset($candidate['permalink']) ? (string) $candidate['permalink'] : '';
                    if (($item['guid'] !== '' && $candidate_guid === $item['guid']) || ($item['permalink'] !== '' && $candidate_permalink === $item['permalink'])) {
                        $found_item = $candidate;
                        break;
                    }
                }

                if (!$found_item) {
                    return new WP_Error('arc_generated_post_item_missing', 'Item original nao encontrado no feed.');
                }

                $item = $found_item;
                foreach (array(
                    'source_image_url',
                    'source_link_url',
                    'source_link_text',
                    'source_video_url',
                    'source_video_embed_html',
                    'source_video_source',
                    'outline_target_h2_min',
                    'outline_target_h2_max',
                    'outline_target_h2_count',
                    'outline_block_quantities',
                    'source_image_selector_class',
                    'source_link_selector_class',
                    'source_page_title',
                    'source_page_excerpt',
                    'source_page_content',
                    'source_page_outline',
                    'source_page_outline_sections',
                ) as $key) {
                    if ((empty($item[$key]) || $item[$key] === array()) && !empty($original_item[$key])) {
                        $item[$key] = $original_item[$key];
                    }
                }
                $item['source_title'] = !empty($item['title']) ? $item['title'] : $item['source_title'];
                if (empty($item['source_url']) && !empty($item['permalink'])) {
                    $item['source_url'] = $item['permalink'];
                }
            }

            $title_outline_count = Alpha_RSS_AI_Generator_Helper::extract_outline_target_h2_count_from_title(
                !empty($post->post_title) ? $post->post_title : (isset($item['title']) ? $item['title'] : ''),
                !empty($item['source_title']) ? $item['source_title'] : ''
            );
            if ($title_outline_count > 0) {
                $item['outline_target_h2_min'] = $title_outline_count;
                $item['outline_target_h2_max'] = $title_outline_count;
                $item['outline_target_h2_count'] = $title_outline_count;
            }

            $item = Alpha_RSS_AI_Generator::maybe_enrich_rss_item_context($generator, $item);
            $item = Alpha_RSS_AI_Generator::resolve_item_media_for_generation($generator, $item);

            return array(
                'generator' => $generator,
                'item' => $item,
                'post' => $post,
            );
        }

        private static function maybe_set_generated_thumbnail($post_id, $generator, $item, &$article, $reuse_existing_thumbnail = false)
        {
            $existing_thumbnail_id = intval(get_post_thumbnail_id($post_id));
            if ($reuse_existing_thumbnail && $existing_thumbnail_id > 0) {
                return $existing_thumbnail_id;
            }

            $is_keyword_list = !empty($generator['source_type']) && $generator['source_type'] === 'keyword_list';
            $is_keyword_list_url_reference = Alpha_RSS_AI_Generator::generator_uses_keyword_list_url_reference_mode($generator);
            $treat_like_rss = !$is_keyword_list || $is_keyword_list_url_reference;

            $image_source_mode = !empty($generator['image_source_mode'])
                ? sanitize_key((string) $generator['image_source_mode'])
                : Alpha_RSS_AI_Generator::normalize_image_source_mode(
                    !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
                    '',
                    isset($generator['pexels_enabled']) ? !empty($generator['pexels_enabled']) : null,
                    !empty($generator['keyword_list_mode']) ? $generator['keyword_list_mode'] : Alpha_RSS_AI_Generator::get_default_keyword_list_mode()
                );

            $has_source_image = !empty($item['source_image_url']);
            $source_image_set = false;
            $use_source_image = $treat_like_rss;
            $use_pexels = Alpha_RSS_AI_Generator::image_source_mode_uses_pexels($image_source_mode);
            $use_dalle = Alpha_RSS_AI_Generator::image_source_mode_uses_dalle($image_source_mode);

            if ($use_source_image && $has_source_image) {
                Alpha_RSS_AI_Generator::log_image_debug('thumbnail_try_source', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'source_image_url' => !empty($item['source_image_url']) ? $item['source_image_url'] : '',
                ));
                $source_image_set = (bool) Alpha_RSS_AI_Generator::maybe_set_source_featured_image($post_id, $item, $article);
                Alpha_RSS_AI_Generator::log_image_debug('thumbnail_source_done', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'source_image_set' => $source_image_set ? 1 : 0,
                ));
            }

            $needs_fallback_image = !$has_source_image || !$source_image_set;
            if ($needs_fallback_image && $use_pexels) {
                Alpha_RSS_AI_Generator::log_image_debug('thumbnail_try_pexels', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'has_source_image' => $has_source_image ? 1 : 0,
                    'source_image_set' => $source_image_set ? 1 : 0,
                ));
                $pexels_result = Alpha_RSS_AI_Generator::download_and_set_featured_image_from_pexels($post_id, $generator, $item, $article, $is_keyword_list);
                Alpha_RSS_AI_Generator::log_image_debug('thumbnail_pexels_done', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'result' => is_wp_error($pexels_result) ? 'wp_error' : ($pexels_result ? 'ok' : 'false'),
                ));
                if (is_wp_error($pexels_result)) {
                    if ($is_keyword_list && !$is_keyword_list_url_reference) {
                        return $pexels_result;
                    }
                }
            } elseif ($needs_fallback_image && $use_dalle) {
                Alpha_RSS_AI_Generator::log_image_debug('thumbnail_try_dalle', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'has_source_image' => $has_source_image ? 1 : 0,
                    'source_image_set' => $source_image_set ? 1 : 0,
                ));
                $dalle_result = Alpha_RSS_AI_Generator::download_and_set_featured_image_from_dalle($post_id, $generator, $item, $article, $is_keyword_list);
                Alpha_RSS_AI_Generator::log_image_debug('thumbnail_dalle_done', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'result' => is_wp_error($dalle_result) ? 'wp_error' : ($dalle_result ? 'ok' : 'false'),
                ));
                if (is_wp_error($dalle_result)) {
                    if ($is_keyword_list && !$is_keyword_list_url_reference) {
                        return $dalle_result;
                    }
                }
            } else {
                Alpha_RSS_AI_Generator::log_image_debug('thumbnail_fallback_skipped', array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    'needs_fallback_image' => $needs_fallback_image ? 1 : 0,
                    'use_pexels' => $use_pexels ? 1 : 0,
                    'use_dalle' => $use_dalle ? 1 : 0,
                ));
            }

            return true;
        }

        public function handle_regenerate_post()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            check_admin_referer('arc_regenerate_generated_post', 'arc_regenerate_nonce');

            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
            if ($post_id <= 0) {
                $this->redirect_with_notice('Post invalido.', 'error');
            }

            $context = self::resolve_generated_post_context($post_id);
            if (is_wp_error($context)) {
                $this->redirect_with_notice($context->get_error_message(), 'error');
            }

            $generator = $context['generator'];
            $item = $context['item'];
            $post = $context['post'];

            $article = Alpha_RSS_AI_Generator_Helper::call_openai($generator, $item);
            if (is_wp_error($article)) {
                $this->redirect_with_notice($article->get_error_message(), 'error');
            }

            $title_outline_count = Alpha_RSS_AI_Generator_Helper::extract_outline_target_h2_count_from_title(
                !empty($article['title']) ? $article['title'] : '',
                !empty($item['source_title']) ? $item['source_title'] : (!empty($item['title']) ? $item['title'] : '')
            );
            if ($title_outline_count > 0) {
                $item['outline_target_h2_min'] = $title_outline_count;
                $item['outline_target_h2_max'] = $title_outline_count;
                $item['outline_target_h2_count'] = $title_outline_count;
            }

            $title = !empty($article['title']) ? trim((string) $article['title']) : '';
            if ($title === '' && !empty($item['source_title'])) {
                $title = trim((string) $item['source_title']);
            }
            if ($title === '' && !empty($item['title'])) {
                $title = trim((string) $item['title']);
            }
            if ($title === '' && !empty($post->post_title)) {
                $title = (string) $post->post_title;
            }

            $excerpt = isset($article['excerpt']) ? (string) $article['excerpt'] : '';
            if ($excerpt === '' && !empty($post->post_excerpt)) {
                $excerpt = (string) $post->post_excerpt;
            }
            if ($excerpt === '' && !empty($article['meta_description'])) {
                $excerpt = (string) $article['meta_description'];
            }
            if ($excerpt === '' && !empty($content_html)) {
                $excerpt = wp_trim_words(wp_strip_all_tags((string) $content_html), 28);
            }

            $use_source_video = !empty($generator['source_video_enabled']);
            $source_video_embed_html = '';
            $source_video_url = '';
            $source_type = !empty($generator['source_type']) ? sanitize_key((string) $generator['source_type']) : 'rss';
            $is_keyword_list = $source_type === 'keyword_list';
            $is_keyword_list_url_reference = Alpha_RSS_AI_Generator::generator_uses_keyword_list_url_reference_mode($generator);
            $treat_like_rss = !$is_keyword_list || $is_keyword_list_url_reference;
            if ($treat_like_rss && $use_source_video) {
                $source_video_embed_html = !empty($item['source_video_embed_html']) ? trim((string) $item['source_video_embed_html']) : '';
                $source_video_url = !empty($item['source_video_url']) ? esc_url_raw(trim((string) $item['source_video_url'])) : '';
            }

            $content_html = isset($article['content_html']) ? (string) $article['content_html'] : '';
            if ($content_html === '' && !empty($post->post_content)) {
                $content_html = (string) $post->post_content;
            }

            $content_html = Alpha_RSS_AI_Generator_Helper::apply_internal_links_to_content(
                $content_html,
                $generator,
                array(
                    'post_id' => intval($post_id),
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                )
            );
            $article['content_html'] = $content_html;

            $article['content_html'] = Alpha_RSS_AI_Generator::convert_html_fragment_to_gutenberg_blocks(
                isset($article['content_html']) ? $article['content_html'] : '',
                $source_video_embed_html,
                $source_video_url
            );

            $content_html = isset($article['content_html']) ? (string) $article['content_html'] : '';

            if (!empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])) {
                $content_image_size = !empty($generator['content_image_size'])
                    ? Alpha_RSS_AI_Generator::normalize_image_display_size((string) $generator['content_image_size'])
                    : 'medium';
                $existing_image_map = array();
                if ($content_html !== '') {
                    $existing_image_map = Alpha_RSS_AI_Generator_Helper::extract_outline_section_image_map_from_content($content_html);
                }
                $content_html = Alpha_RSS_AI_Generator_Helper::inject_outline_section_media_into_content(
                    $content_html,
                    $item['source_page_outline_sections'],
                    $post_id,
                    $content_image_size,
                    !empty($generator['source_link_phrases']) ? $generator['source_link_phrases'] : '',
                    Alpha_RSS_AI_Generator::generator_uses_source_content_images($generator),
                    false,
                    $generator,
                    array(
                        'post_id' => intval($post_id),
                        'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                    ),
                    $existing_image_map
                );
            }

            $internal_link_rules = array();
            if (!empty($generator['internal_links_json'])) {
                $internal_link_rules = Alpha_RSS_AI_Generator_Helper::parse_internal_link_rules($generator['internal_links_json']);
            }
            $auto_internal_links_count = isset($generator['internal_links_count']) ? intval($generator['internal_links_count']) : 0;
            if ($auto_internal_links_count <= 0 && empty($internal_link_rules) && in_array($source_type, array('rss', 'keyword_list'), true)) {
                $auto_internal_links_count = 5;
            }
            if (empty($internal_link_rules) && $auto_internal_links_count > 0 && class_exists('Alpha_RSS_AI_Link_Suggestions')) {
                $auto_link_result = Alpha_RSS_AI_Link_Suggestions::generate_and_apply_link_suggestions_to_post(
                    $post_id,
                    $generator,
                    $auto_internal_links_count,
                    '',
                    $content_html
                );
                if (is_array($auto_link_result) && !empty($auto_link_result['content_html'])) {
                    $content_html = (string) $auto_link_result['content_html'];
                    $article['content_html'] = $content_html;
                }
            }

            $update_result = wp_update_post(array(
                'ID' => intval($post_id),
                'post_title' => $title,
                'post_content' => $content_html,
                'post_excerpt' => $excerpt,
                'post_name' => !empty($post->post_name) ? $post->post_name : '',
                'post_status' => !empty($post->post_status) ? $post->post_status : 'draft',
                'post_type' => !empty($post->post_type) ? $post->post_type : 'post',
                'post_author' => !empty($post->post_author) ? intval($post->post_author) : get_current_user_id(),
                'post_parent' => !empty($post->post_parent) ? intval($post->post_parent) : 0,
                'menu_order' => isset($post->menu_order) ? intval($post->menu_order) : 0,
                'edit_date' => true,
            ), true);

            if (is_wp_error($update_result)) {
                $this->redirect_with_notice($update_result->get_error_message(), 'error');
            }

            Alpha_RSS_AI_Generator::apply_taxonomies_and_meta($post_id, $generator, $article, $item);
            $thumbnail_result = self::maybe_set_generated_thumbnail($post_id, $generator, $item, $article, true);
            if (is_wp_error($thumbnail_result)) {
                $this->redirect_with_notice($thumbnail_result->get_error_message(), 'error');
            }

            Alpha_RSS_AI_Generator::insert_run_log($generator['id'], 'success', 'Post regenerado manualmente', array(
                'request' => array(
                    'post_id' => $post_id,
                    'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
                ),
                'response' => array(
                    'title' => $title,
                ),
            ), $post_id, !empty($item['guid']) ? $item['guid'] : '', !empty($item['permalink']) ? $item['permalink'] : '');

            $view_link = Alpha_RSS_AI_Generator::get_post_view_link($post_id);
            $edit_link = Alpha_RSS_AI_Generator::get_post_edit_link($post_id);

            $this->redirect_with_notice('Post regenerado com sucesso.', 'success', array(
                'arc_notice_link' => $view_link ? $view_link : $edit_link,
            ));
        }

        public function handle_delete_post()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            check_admin_referer('arc_delete_generated_post', 'arc_delete_generated_post_nonce');

            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
            if ($post_id <= 0) {
                $this->redirect_with_notice('Post invalido.', 'error');
            }

            if (!get_post($post_id)) {
                $this->redirect_with_notice('Post nao encontrado.', 'error');
            }

            if (!wp_trash_post($post_id)) {
                $this->redirect_with_notice('Nao foi possivel excluir o post.', 'error');
            }

            $this->redirect_with_notice('Post enviado para a lixeira.', 'success');
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

        private static function render_notice()
        {
            if (empty($_GET['arc_notice'])) {
                return;
            }

            $type = isset($_GET['arc_notice_type']) ? sanitize_key(wp_unslash($_GET['arc_notice_type'])) : 'success';
            $class = 'notice notice-' . ($type === 'error' ? 'error' : 'success');
            $message = sanitize_text_field(wp_unslash($_GET['arc_notice']));
            $link = isset($_GET['arc_notice_link']) ? esc_url_raw(wp_unslash($_GET['arc_notice_link'])) : '';

            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message);
            if ($link !== '' && $type !== 'error') {
                echo ' <a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer" class="ml-2 inline-flex items-center rounded-md border border-current/20 px-2 py-0.5 text-xs font-semibold text-inherit no-underline">Abrir conteudo</a>';
            }
            echo '</p></div>';
        }

        private static function render_post_status_badge($status)
        {
            $label = class_exists('Alpha_RSS_AI_Generator_Admin')
                ? Alpha_RSS_AI_Generator_Admin::get_post_status_label($status)
                : ucfirst((string) $status);

            $class = 'bg-slate-100 text-slate-700';
            if ($status === 'publish') {
                $class = 'bg-emerald-100 text-emerald-700';
            } elseif ($status === 'draft' || $status === 'pending') {
                $class = 'bg-amber-100 text-amber-700';
            } elseif ($status === 'private') {
                $class = 'bg-indigo-100 text-indigo-700';
            }

            return '<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            $paged = max(1, intval(self::get_request_param('paged', 1)));
            $per_page = 20;
            $search = self::get_request_param('s', '');
            $generator_id = intval(self::get_request_param('generator_id', 0));
            $generators = Alpha_RSS_AI_Generator::get_generators(200);
            $query = self::get_filtered_query($paged, $per_page, $generator_id, $search);
            $total_items = intval($query->found_posts);
            $total_pages = max(1, intval($query->max_num_pages));
            $selected_generator_name = $generator_id > 0 ? self::get_generator_name($generator_id) : '';

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
                <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.25em] text-indigo-600">Alpha RSS AI</div>
                        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950 lg:text-[2.15rem]">Posts gerados</h1>
                        <p class="mt-2 max-w-3xl text-[13px] leading-5 text-slate-600">Veja tudo que o plugin já publicou ou salvou e use a regeneração para rodar o mesmo post com o prompt atual do gerador. O slug atual do post é mantido.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=alpha-rss-ai-generator')); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50">Ir para geradores</a>
                    </div>
                </div>

                <?php self::render_notice(); ?>

                <div class="mb-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="grid gap-4 px-6 py-5 lg:grid-cols-12">
                        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                        <div class="lg:col-span-5">
                            <label class="mb-1 block text-[13px] font-medium text-slate-700">Buscar</label>
                            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Título, conteúdo ou origem" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="lg:col-span-4">
                            <label class="mb-1 block text-[13px] font-medium text-slate-700">Filtrar por gerador</label>
                            <select name="generator_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                <option value="0"<?php selected($generator_id <= 0); ?>>Todos os geradores</option>
                                <?php foreach ($generators as $generator): ?>
                                    <option value="<?php echo esc_attr($generator['id']); ?>"<?php selected($generator_id === intval($generator['id'])); ?>><?php echo esc_html($generator['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end gap-3 lg:col-span-3">
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Filtrar</button>
                            <a href="<?php echo esc_url(add_query_arg(array('page' => self::PAGE_SLUG), admin_url('admin.php'))); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Limpar</a>
                        </div>
                    </form>
                </div>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-base font-semibold text-slate-950">Lista de posts</h2>
                            <p class="mt-1 text-[13px] text-slate-500">
                                <?php if ($selected_generator_name !== ''): ?>
                                    Mostrando posts do gerador <strong><?php echo esc_html($selected_generator_name); ?></strong>.
                                <?php else: ?>
                                    Mostrando posts de todos os geradores.
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-4 py-2 text-[13px] text-slate-600">
                            <?php echo esc_html(number_format_i18n($total_items)); ?> post(s)
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                                    <th class="px-6 py-3">Post</th>
                                    <th class="px-6 py-3">Gerador</th>
                                    <th class="px-6 py-3">Origem</th>
                                    <th class="px-6 py-3">Status</th>
                                    <th class="px-6 py-3">Data</th>
                                    <th class="px-6 py-3">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php if ($query->have_posts()): ?>
                                    <?php
                                    global $post;
                                    foreach ($query->posts as $post):
                                        setup_postdata($post);
                                        $post_id = intval($post->ID);
                                        $generator_id_row = intval(get_post_meta($post_id, '_arc_generator_id', true));
                                        $generator_name = self::get_generator_name($generator_id_row);
                                        $source_type = (string) get_post_meta($post_id, '_arc_source_type', true);
                                        $source_title = (string) get_post_meta($post_id, '_arc_source_title', true);
                                        $source_keyword = (string) get_post_meta($post_id, '_arc_source_keyword', true);
                                        $source_url = (string) get_post_meta($post_id, '_arc_source_url', true);
                                        $source_permalink = (string) get_post_meta($post_id, '_arc_source_item_permalink', true);
                                        $source_external_link = $source_permalink !== '' ? $source_permalink : $source_url;
                                        $source_label = $source_title !== '' ? $source_title : ($source_keyword !== '' ? $source_keyword : ($source_url !== '' ? $source_url : $source_permalink));
                                        $view_link = Alpha_RSS_AI_Generator::get_post_view_link($post_id);
                                        $edit_link = Alpha_RSS_AI_Generator::get_post_edit_link($post_id);
                                        $can_regenerate = $generator_id_row > 0 && !empty($generator_name);
                                        ?>
                                        <tr class="align-top">
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-semibold leading-5 text-slate-950"><?php echo esc_html(get_the_title($post_id)); ?></div>
                                                <div class="mt-1 text-[11px] text-slate-500">#<?php echo esc_html($post_id); ?> · <?php echo esc_html(get_post_type($post_id)); ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-medium leading-5 text-slate-700"><?php echo esc_html($generator_name !== '' ? $generator_name : ('Gerador #' . $generator_id_row)); ?></div>
                                                <div class="mt-1 text-[11px] text-slate-500"><?php echo esc_html($source_type !== '' ? $source_type : '-'); ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php if ($source_external_link !== ''): ?>
                                                    <a href="<?php echo esc_url($source_external_link); ?>" target="_blank" rel="noopener noreferrer" class="block max-w-md text-sm font-medium leading-5 text-indigo-700 underline decoration-indigo-300 underline-offset-2 hover:text-indigo-600">
                                                        <?php echo esc_html(self::truncate_text($source_label !== '' ? $source_label : '-', 120)); ?>
                                                    </a>
                                                    <a href="<?php echo esc_url($source_external_link); ?>" target="_blank" rel="noopener noreferrer" class="mt-1 block max-w-md break-all text-[11px] leading-4 text-slate-500 hover:text-slate-700">
                                                        <?php echo esc_html(self::truncate_text($source_external_link, 140)); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <div class="max-w-md text-sm leading-5 text-slate-700"><?php echo esc_html(self::truncate_text($source_label !== '' ? $source_label : '-', 120)); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?php echo wp_kses_post(self::render_post_status_badge(get_post_status($post_id))); ?>
                                            </td>
                                            <td class="px-6 py-4 text-[13px] leading-5 text-slate-600"><?php echo esc_html(get_the_date('Y-m-d H:i', $post_id)); ?></td>
                                            <td class="whitespace-nowrap px-6 py-4">
                                                <div class="flex flex-nowrap items-center gap-2 whitespace-nowrap">
                                                    <?php if ($view_link !== ''): ?>
                                                        <a href="<?php echo esc_url($view_link); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-950" aria-label="Visualizar" title="Visualizar">
                                                            <span class="dashicons dashicons-visibility text-[16px] leading-none"></span>
                                                            <span class="sr-only">Visualizar</span>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($edit_link !== ''): ?>
                                                        <a href="<?php echo esc_url($edit_link); ?>" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-950" aria-label="Editar" title="Editar">
                                                            <span class="dashicons dashicons-edit text-[16px] leading-none"></span>
                                                            <span class="sr-only">Editar</span>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($can_regenerate): ?>
                                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="m-0 inline-flex shrink-0" data-swal-confirm="Regerar este post com o prompt atual do gerador?">
                                                            <?php wp_nonce_field('arc_regenerate_generated_post', 'arc_regenerate_nonce'); ?>
                                                            <input type="hidden" name="action" value="arc_regenerate_generated_post" />
                                                            <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>" />
                                                            <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-300 bg-white text-indigo-600 shadow-sm transition hover:bg-slate-50 hover:text-indigo-700" aria-label="Regerar" title="Regerar">
                                                                <span class="dashicons dashicons-update text-[16px] leading-none"></span>
                                                                <span class="sr-only">Regerar</span>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 text-slate-400 shadow-sm" title="Nao foi possivel identificar o gerador original" aria-label="Regerar indisponivel">
                                                            <span class="dashicons dashicons-update text-[16px] leading-none"></span>
                                                            <span class="sr-only">Regerar indisponivel</span>
                                                        </span>
                                                    <?php endif; ?>
                                                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'alpha-rss-ai-link-suggestions', 'post_id' => $post_id), admin_url('admin.php'))); ?>" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-50 hover:text-slate-950" aria-label="Lincagem automática" title="Lincagem automática">
                                                        <span class="dashicons dashicons-admin-links text-[16px] leading-none"></span>
                                                        <span class="sr-only">Lincagem automática</span>
                                                    </a>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="m-0 inline-flex shrink-0" data-swal-confirm="Excluir este post gerado?">
                                                        <?php wp_nonce_field('arc_delete_generated_post', 'arc_delete_generated_post_nonce'); ?>
                                                        <input type="hidden" name="action" value="arc_delete_generated_post" />
                                                        <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>" />
                                                        <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-rose-200 bg-white text-rose-600 shadow-sm transition hover:bg-rose-50 hover:text-rose-700" aria-label="Excluir" title="Excluir">
                                                            <span class="dashicons dashicons-trash text-[16px] leading-none"></span>
                                                            <span class="sr-only">Excluir</span>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php wp_reset_postdata(); ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-12 text-center text-sm text-slate-500">Nenhum post gerado encontrado.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="flex items-center justify-between gap-4 border-t border-slate-200 px-6 py-4">
                            <div class="text-sm text-slate-500">Página <?php echo esc_html($paged); ?> de <?php echo esc_html($total_pages); ?></div>
                            <div class="pagination-links">
                                <?php
                                echo wp_kses_post(paginate_links(array(
                                    'base' => add_query_arg(array(
                                        'page' => self::PAGE_SLUG,
                                        'paged' => '%#%',
                                        's' => $search,
                                        'generator_id' => $generator_id,
                                    ), admin_url('admin.php')),
                                    'format' => '',
                                    'current' => $paged,
                                    'total' => $total_pages,
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                )));
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
            <?php
        }
    }
}
