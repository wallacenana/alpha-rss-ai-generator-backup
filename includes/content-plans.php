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

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 22);
            add_action('admin_post_arc_generate_content_plan', array($this, 'handle_generate_plan'));
            add_action('admin_post_arc_generate_content_satellites', array($this, 'handle_generate_satellites'));
            add_action('admin_post_arc_clear_content_plan', array($this, 'handle_clear_plan'));
        }

        public function admin_menu()
        {
            add_submenu_page(
                'alpha-rss-ai-generator',
                'Planejamento de conteúdos',
                'Planejamento de conteúdos',
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

        private static function normalize_plain_text($text)
        {
            $text = trim(wp_strip_all_tags((string) $text));
            $text = preg_replace('/\s+/', ' ', $text);
            return trim((string) $text);
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
            if ($generator_id <= 0) {
                return new WP_Error('arc_content_plan_missing_generator', 'Este post nao possui um gerador vinculado.');
            }

            $generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
            if (empty($generator)) {
                return new WP_Error('arc_content_plan_generator_missing', 'Gerador original nao encontrado.');
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
                $content_model_type = class_exists('Alpha_RSS_AI_Generator') ? Alpha_RSS_AI_Generator::get_default_content_model_type() : 'pillar';
            }
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

            return array(
                'index' => intval($index),
                'title' => $title,
                'slug' => $slug,
                'focus_keyword' => !empty($satellite['focus_keyword']) ? sanitize_text_field((string) $satellite['focus_keyword']) : '',
                'anchor_phrase' => !empty($satellite['anchor_phrase']) ? sanitize_text_field((string) $satellite['anchor_phrase']) : '',
                'excerpt' => !empty($satellite['excerpt']) ? sanitize_textarea_field((string) $satellite['excerpt']) : '',
                'brief' => !empty($satellite['brief']) ? sanitize_textarea_field((string) $satellite['brief']) : '',
                'category_hint' => !empty($satellite['category_hint']) ? sanitize_text_field((string) $satellite['category_hint']) : '',
                'reason' => !empty($satellite['reason']) ? sanitize_textarea_field((string) $satellite['reason']) : '',
            );
        }

        private static function normalize_plan_response($plan, $satellite_count)
        {
            $satellite_count = max(1, intval($satellite_count));
            $normalized = array(
                'title' => !empty($plan['title']) ? sanitize_text_field((string) $plan['title']) : '',
                'slug' => !empty($plan['slug']) ? sanitize_title((string) $plan['slug']) : '',
                'excerpt' => !empty($plan['excerpt']) ? sanitize_textarea_field((string) $plan['excerpt']) : '',
                'pillar_summary' => !empty($plan['pillar_summary']) ? sanitize_textarea_field((string) $plan['pillar_summary']) : '',
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

        private static function build_plan_prompt($generator, $item, $satellite_count)
        {
            $satellite_count = max(1, intval($satellite_count));
            $outline_context = Alpha_RSS_AI_Generator_Helper::build_outline_context_base($generator);
            $outline_text = !empty($outline_context['outline_model_text']) ? (string) $outline_context['outline_model_text'] : '';
            $source_context = Alpha_RSS_AI_Generator_Helper::build_source_context_block($generator, $item);

            $pillar_title = !empty($item['title']) ? self::normalize_plain_text($item['title']) : '';
            $pillar_url = !empty($item['permalink']) ? esc_url_raw((string) $item['permalink']) : '';
            $pillar_excerpt = !empty($item['excerpt']) ? self::normalize_plain_text($item['excerpt']) : '';
            $pillar_content = !empty($item['content']) ? self::normalize_plain_text($item['content']) : '';
            $pillar_categories = !empty($item['categories']) && is_array($item['categories']) ? implode(', ', array_map('sanitize_text_field', $item['categories'])) : '';
            $pillar_tags = !empty($item['tags']) && is_array($item['tags']) ? implode(', ', array_map('sanitize_text_field', $item['tags'])) : '';
            $generation_language = !empty($generator['generation_language']) ? Alpha_RSS_AI_Generator::normalize_generation_language_value($generator['generation_language']) : Alpha_RSS_AI_Generator::get_default_generation_language();
            $content_model_type = !empty($item['content_model_type'])
                ? Alpha_RSS_AI_Generator::normalize_content_model_type($item['content_model_type'])
                : Alpha_RSS_AI_Generator::get_default_content_model_type();
            $content_model_label = Alpha_RSS_AI_Generator::get_content_model_label($content_model_type);

            $lines = array(
                'Voce e um estrategista editorial e arquiteto de links internos.',
                'Analise o post pilar abaixo e devolva somente JSON valido.',
                'Modelo editorial do conteudo: ' . $content_model_label,
                $content_model_type === 'pillar'
                    ? 'Trate este projeto como um post pilar: o plano precisa sustentar satelites, ancoras e uma estrutura central robusta.'
                    : 'Trate este projeto como um post satelite: o plano deve ser mais especifico e pronto para amarrar ao pilar.',
                'Crie exatamente ' . $satellite_count . ' posts satelites.',
                'A resposta deve trazer as chaves: title, slug, excerpt, pillar_summary, satellites.',
                'satellites deve ser um array com exatamente ' . $satellite_count . ' objetos.',
                'Cada objeto deve ter: title, slug, focus_keyword, anchor_phrase, excerpt, brief, category_hint, reason.',
                'Cada anchor_phrase precisa ser uma frase natural que possa receber um link no post pilar.',
                'Nao repita a mesma anchor_phrase em satelites diferentes.',
                'Nao invente fatos fora do post pilar e do contexto fornecido.',
                'Se o post pilar nao sustentar todos os satelites, reduza apenas o recorte dos temas, nunca crie assuntos aleatorios.',
                'Use o mesmo idioma final do gerador: ' . $generation_language . '.',
                'Titulo do post pilar: ' . $pillar_title,
                'URL do post pilar: ' . $pillar_url,
                'Resumo do post pilar: ' . $pillar_excerpt,
                'Conteudo do post pilar: ' . $pillar_content,
            );

            if ($pillar_categories !== '') {
                $lines[] = 'Categorias do post pilar: ' . $pillar_categories;
            }
            if ($pillar_tags !== '') {
                $lines[] = 'Tags do post pilar: ' . $pillar_tags;
            }
            if ($outline_text !== '') {
                $lines[] = 'Modelo de outline do gerador:';
                $lines[] = $outline_text;
            }

            $lines[] = $source_context;

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
                $message = self::get_request_param('arc_message', 'Nao foi possivel gerar os satelites.');
                $class = 'notice-error';
            } elseif ($notice === 'plan_error') {
                $message = self::get_request_param('arc_message', 'Nao foi possivel gerar o plano editorial.');
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
                wp_die('Permissao negada.');
            }

            check_admin_referer('arc_generate_content_plan', 'arc_content_plan_nonce');

            $post_id = isset($_POST['pillar_post_id']) ? intval($_POST['pillar_post_id']) : 0;
            $satellite_count = isset($_POST['satellite_count']) ? intval($_POST['satellite_count']) : 5;
            $satellite_count = max(1, min(12, $satellite_count));

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
            $content_model_type = isset($_POST['content_model_type'])
                ? Alpha_RSS_AI_Generator::normalize_content_model_type(sanitize_key(wp_unslash($_POST['content_model_type'])))
                : (!empty($item['content_model_type']) ? Alpha_RSS_AI_Generator::normalize_content_model_type($item['content_model_type']) : Alpha_RSS_AI_Generator::get_default_content_model_type());
            $generator['content_model_type'] = $content_model_type;
            $item['content_model_type'] = $content_model_type;

            $prompt = self::build_plan_prompt($generator, $item, $satellite_count);
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
            $normalized_plan['content_model_type'] = !empty($item['content_model_type']) ? Alpha_RSS_AI_Generator::normalize_content_model_type($item['content_model_type']) : Alpha_RSS_AI_Generator::get_default_content_model_type();
            $normalized_plan['content_model_label'] = Alpha_RSS_AI_Generator::get_content_model_label($normalized_plan['content_model_type']);

            update_post_meta($post_id, self::META_PLAN_JSON, wp_json_encode($normalized_plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            update_post_meta($post_id, self::META_PLAN_GENERATED_AT, current_time('mysql'));
            update_post_meta($post_id, self::META_PLAN_GENERATOR_ID, intval($generator['id']));
            update_post_meta($post_id, self::META_PLAN_PILLAR_POST_ID, $post_id);
            update_post_meta($post_id, self::META_PLAN_SATELLITE_COUNT, $satellite_count);
            update_post_meta($post_id, self::META_PLAN_CONTENT_MODEL_TYPE, $normalized_plan['content_model_type']);
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
                wp_die('Permissao negada.');
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

            $pillar_excerpt = '';
            if (!empty($context['item']) && is_array($context['item']) && !empty($context['item']['excerpt'])) {
                $pillar_excerpt = self::normalize_plain_text((string) $context['item']['excerpt']);
            } elseif ($post instanceof WP_Post) {
                $pillar_excerpt = self::get_post_excerpt_text($post);
            }

            $pillar_content = '';
            if (!empty($context['item']) && is_array($context['item']) && !empty($context['item']['content'])) {
                $pillar_content = self::normalize_plain_text((string) $context['item']['content']);
            } elseif ($post instanceof WP_Post) {
                $pillar_content = self::normalize_plain_text((string) $post->post_content);
            }

            $brief = !empty($satellite['brief']) ? self::normalize_plain_text((string) $satellite['brief']) : '';
            $reason = !empty($satellite['reason']) ? self::normalize_plain_text((string) $satellite['reason']) : '';
            $anchor_phrase = !empty($satellite['anchor_phrase']) ? self::normalize_plain_text((string) $satellite['anchor_phrase']) : '';
            $source_content = implode("\n", array_filter(array(
                'Pilar: ' . $pillar_title,
                $pillar_excerpt !== '' ? 'Resumo do pilar: ' . $pillar_excerpt : '',
                $pillar_content !== '' ? 'Conteudo do pilar: ' . $pillar_content : '',
                $brief !== '' ? 'Resumo do satelite: ' . $brief : '',
                $reason !== '' ? 'Motivo do satelite: ' . $reason : '',
                $anchor_phrase !== '' ? 'Ancora planejada: ' . $anchor_phrase : '',
            )));

            return array(
                'guid' => 'content-plan:' . $pillar_post_id . ':' . intval($satellite['index']),
                'title' => $satellite['title'],
                'source_title' => $satellite['title'],
                'keyword' => !empty($satellite['focus_keyword']) ? $satellite['focus_keyword'] : $satellite['title'],
                'permalink' => '',
                'source_url' => $pillar_url,
                'excerpt' => !empty($satellite['excerpt']) ? $satellite['excerpt'] : $brief,
                'content' => $source_content,
                'feed_title' => !empty($generator['name']) ? (string) $generator['name'] : get_bloginfo('name'),
                'date' => current_time('mysql'),
                'categories' => !empty($context['item']['categories']) && is_array($context['item']['categories']) ? $context['item']['categories'] : array(),
                'tags' => !empty($context['item']['tags']) && is_array($context['item']['tags']) ? $context['item']['tags'] : array(),
                'source_page_title' => $pillar_title,
                'source_page_excerpt' => $pillar_excerpt,
                'source_page_content' => $pillar_content,
                'source_page_outline' => !empty($plan['pillar_summary']) ? self::normalize_plain_text((string) $plan['pillar_summary']) : '',
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
                'content_plan_satellite_excerpt' => !empty($satellite['excerpt']) ? $satellite['excerpt'] : '',
                'content_plan_satellite_brief' => $brief,
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
                wp_die('Permissao negada.');
            }

            check_admin_referer('arc_generate_content_satellites', 'arc_content_satellites_nonce');

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
                    'arc_message' => 'Nao existe plano salvo com satelites para gerar.',
                ), admin_url('admin.php'));
                wp_safe_redirect($redirect);
                exit;
            }

            $generator = $context['generator'];
            $satellite_generator = $generator;
            $satellite_generator['source_type'] = 'rss';
            $satellite_generator['content_model_type'] = 'satellite';
            $satellite_generator['use_final_slug'] = 1;

            $generated_posts = array();
            $errors = array();

            foreach ($satellites as $satellite) {
                $normalized_satellite = self::normalize_satellite_item($satellite, isset($satellite['index']) ? intval($satellite['index']) : (count($generated_posts) + 1));
                $item = self::build_satellite_generation_item($context, $plan, $normalized_satellite);
                $post_result = Alpha_RSS_AI_Generator::create_post_from_generator_item($satellite_generator, $item);
                if (is_wp_error($post_result)) {
                    $errors[] = $post_result->get_error_message();
                    continue;
                }

                $generated_posts[] = array(
                    'post_id' => intval($post_result),
                    'title' => $normalized_satellite['title'],
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
                $redirect_args['arc_message'] = !empty($errors) ? implode(' | ', array_slice($errors, 0, 3)) : 'Nao foi possivel gerar os satelites.';
            }

            $redirect = add_query_arg($redirect_args, admin_url('admin.php'));
            wp_safe_redirect($redirect);
            exit;
        }

        private static function render_posts_selector($selected_post_id = 0)
        {
            $posts = self::get_generated_posts(40, 'pillar');
            echo '<select name="pillar_post_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">';
            echo '<option value="0">Selecione um post pilar</option>';
            foreach ($posts as $post) {
                $post_id = intval($post->ID);
                $title = get_the_title($post_id);
                $generator_id = intval(get_post_meta($post_id, '_arc_generator_id', true));
                $generator_name = '';
                if ($generator_id > 0 && class_exists('Alpha_RSS_AI_Generator')) {
                    $generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
                    if (!empty($generator['name'])) {
                        $generator_name = (string) $generator['name'];
                    }
                }
                $label = $title;
                if ($generator_name !== '') {
                    $label .= ' - ' . $generator_name;
                }
                echo '<option value="' . esc_attr($post_id) . '" ' . selected($selected_post_id, $post_id, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
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
            echo '<h3 class="text-lg font-semibold text-slate-950">Plano gerado</h3>';
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
            if (!empty($plan['pillar_summary'])) {
                echo '<p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-700">' . esc_html($plan['pillar_summary']) . '</p>';
            }
            echo '</div>';

            echo '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">';
            echo '<table class="min-w-full divide-y divide-slate-200">';
            echo '<thead class="bg-slate-50">';
            echo '<tr>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">#</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Título</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Âncora</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Foco</th>';
            echo '<th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Resumo</th>';
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
                echo '<td class="px-4 py-4 text-sm text-slate-700">' . esc_html(isset($satellite['excerpt']) ? $satellite['excerpt'] : '-') . '</td>';
                echo '</tr>';
            }
            if (empty($satellites)) {
                echo '<tr><td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">O plano nao trouxe satelites.</td></tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '</div>';
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Permissao negada.');
            }

            $selected_post_id = intval(self::get_request_param('post_id', 0));
            $selected_post = $selected_post_id > 0 ? get_post($selected_post_id) : null;
            $plan = $selected_post_id > 0 ? self::get_plan_meta($selected_post_id) : array();
            $generated_at = $selected_post_id > 0 ? (string) get_post_meta($selected_post_id, self::META_PLAN_GENERATED_AT, true) : '';
            if (!empty($generated_at)) {
                $plan['generated_at'] = $generated_at;
            }

            $current_generator = null;
            if ($selected_post_id > 0) {
                $generator_id = intval(get_post_meta($selected_post_id, '_arc_generator_id', true));
                if ($generator_id > 0 && class_exists('Alpha_RSS_AI_Generator')) {
                    $current_generator = Alpha_RSS_AI_Generator::get_generator($generator_id);
                }
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
            <div class="wrap">
                <div class="mb-6 rounded-3xl border border-slate-200 bg-slate-50 p-6 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.35em] text-indigo-500">Alpha RSS AI</p>
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <h1 class="text-3xl font-semibold tracking-tight text-slate-950">Planejamento de conteúdos</h1>
                            <p class="mt-2 max-w-3xl text-sm text-slate-600">Escolha um post pilar gerado, peça um plano de satélites e depois use esse mapa para criar os posts e conectar os links internos no lugar certo.</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=alpha-rss-ai-generated-posts')); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Posts gerados</a>
                        </div>
                    </div>
                </div>

                <?php self::render_notice(); ?>

                <div class="grid gap-6 xl:grid-cols-[1fr_1.2fr]">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-semibold text-slate-950">Escolher post pilar</h2>
                        <p class="mt-2 text-sm text-slate-500">Selecione um post já gerado para virar a peça central da malha de satélites.</p>

                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="mt-5 space-y-4">
                            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Post pilar</label>
                                <?php self::render_posts_selector($selected_post_id); ?>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Abrir post</button>
                            </div>
                        </form>

                        <?php if ($selected_post instanceof WP_Post): ?>
                            <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-lg font-semibold text-slate-950"><?php echo esc_html(get_the_title($selected_post)); ?></h3>
                                        <p class="mt-1 text-sm text-slate-500">ID <?php echo esc_html($selected_post->ID); ?> · <?php echo esc_html(get_post_type($selected_post)); ?></p>
                                    </div>
                                    <a href="<?php echo esc_url(get_permalink($selected_post)); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Visualizar</a>
                                </div>
                                <?php if ($current_generator && !empty($current_generator['name'])): ?>
                                    <p class="mt-4 text-sm text-slate-700"><strong>Gerador:</strong> <?php echo esc_html($current_generator['name']); ?></p>
                                <?php endif; ?>
                                <p class="mt-2 text-sm text-slate-700"><strong>Modelo editorial:</strong> <?php echo esc_html(Alpha_RSS_AI_Generator::get_content_model_label($selected_content_model_type)); ?></p>
                                <?php if (!empty($plan)): ?>
                                    <p class="mt-2 text-sm text-slate-700"><strong>Satélites planejados:</strong> <?php echo esc_html(isset($plan['satellite_count']) ? intval($plan['satellite_count']) : count(isset($plan['satellites']) && is_array($plan['satellites']) ? $plan['satellites'] : array())); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-xl font-semibold text-slate-950">Gerar planejamento</h2>
                        <p class="mt-2 text-sm text-slate-500">Este passo apenas monta o mapa editorial. A geração dos satélites acontece depois, em uma ação separada.</p>

                        <?php if ($selected_post instanceof WP_Post): ?>
                            <form id="arc-content-plan-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mt-5 space-y-4">
                                <?php wp_nonce_field('arc_generate_content_plan', 'arc_content_plan_nonce'); ?>
                                <input type="hidden" name="action" value="arc_generate_content_plan" />
                                <input type="hidden" name="pillar_post_id" value="<?php echo esc_attr($selected_post->ID); ?>" />
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Modelo editorial</label>
                                    <select name="content_model_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="pillar" <?php selected($selected_content_model_type, 'pillar'); ?>>Pilar</option>
                                        <option value="satellite" <?php selected($selected_content_model_type, 'satellite'); ?>>Satélite</option>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">Pilar cria a base central. Satélite cria a peça de apoio para linkar ao pilar.</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Quantidade de satélites</label>
                                    <input type="number" min="1" max="12" name="satellite_count" value="<?php echo esc_attr(isset($plan['satellite_count']) ? intval($plan['satellite_count']) : 5); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="flex flex-wrap gap-3">
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-emerald-500">Gerar planejamento</button>
                                </div>
                            </form>

                            <?php if (!empty($plan)): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mt-4">
                                    <?php wp_nonce_field('arc_generate_content_satellites', 'arc_content_satellites_nonce'); ?>
                                    <input type="hidden" name="action" value="arc_generate_content_satellites" />
                                    <input type="hidden" name="pillar_post_id" value="<?php echo esc_attr($selected_post->ID); ?>" />
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-slate-800">Gerar satélites</button>
                                </form>
                            <?php endif; ?>

                            <?php if (!empty($plan)): ?>
                                <div class="mt-4 flex flex-wrap gap-3">
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-swal-confirm="Remover o plano salvo deste post pilar?">
                                        <?php wp_nonce_field('arc_clear_content_plan', 'arc_content_plan_nonce'); ?>
                                        <input type="hidden" name="action" value="arc_clear_content_plan" />
                                        <input type="hidden" name="pillar_post_id" value="<?php echo esc_attr($selected_post->ID); ?>" />
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-600 transition hover:bg-rose-100">Limpar plano</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="mt-5 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                                Escolha um post pilar acima para liberar o planejamento.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-6">
                    <?php self::render_plan_table($plan); ?>
                </div>

                <?php if ($selected_post instanceof WP_Post): ?>
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div>
                            <p class="text-sm font-medium text-slate-900">Ação rápida</p>
                            <p class="mt-1 text-sm text-slate-500">Esse botão repete o planejamento do topo sem precisar voltar na tela.</p>
                        </div>
                        <button type="submit" form="arc-content-plan-form" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-emerald-500">Gerar planejamento</button>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}
