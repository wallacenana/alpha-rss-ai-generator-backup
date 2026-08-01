<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Alpha_RSS_AI_Pexels_Media')) {
    final class Alpha_RSS_AI_Pexels_Media
    {
        public function __construct()
        {
            add_action('wp_ajax_alpha_rss_ai_pexels_search', array($this, 'search'));
            add_action('wp_ajax_alpha_rss_ai_pexels_set_featured', array($this, 'set_featured'));
        }

        public function search()
        {
            check_ajax_referer('alpha_rss_ai_pexels_media', 'nonce');

            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
                wp_send_json_error(array('message' => 'Permissao negada.'), 403);
            }
            if ($post_id <= 0 && !current_user_can('edit_posts')) {
                wp_send_json_error(array('message' => 'Permissao negada.'), 403);
            }

            $settings = Alpha_RSS_AI_Generator::get_settings();
            $api_key = !empty($settings['pexels_api_key']) ? trim((string) $settings['pexels_api_key']) : '';
            if ($api_key === '') {
                wp_send_json_error(array('message' => 'Configure a chave do Pexels antes de pesquisar.'), 400);
            }

            $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
            $page = isset($_POST['page']) ? max(1, min(10, absint($_POST['page']))) : 1;
            if ($query === '') {
                wp_send_json_error(array('message' => 'Digite uma busca para pesquisar no Pexels.'), 400);
            }

            $response = wp_remote_get(add_query_arg(array(
                'query' => $query,
                'page' => $page,
                'per_page' => 6,
                'orientation' => 'landscape',
            ), 'https://api.pexels.com/v1/search'), array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => $api_key,
                ),
            ));

            if (is_wp_error($response)) {
                wp_send_json_error(array('message' => $response->get_error_message()), 502);
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                wp_send_json_error(array('message' => 'O Pexels respondeu com o status ' . intval($status) . '.'), $status >= 400 ? $status : 502);
            }

            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            $images = array();
            if (!empty($data['photos']) && is_array($data['photos'])) {
                foreach ($data['photos'] as $photo) {
                    if (!is_array($photo) || empty($photo['src']) || !is_array($photo['src'])) {
                        continue;
                    }

                    $preview_url = !empty($photo['src']['medium'])
                        ? (string) $photo['src']['medium']
                        : (!empty($photo['src']['small']) ? (string) $photo['src']['small'] : '');
                    $image_url = !empty($photo['src']['large'])
                        ? (string) $photo['src']['large']
                        : (!empty($photo['src']['original']) ? (string) $photo['src']['original'] : '');
                    if ($preview_url === '' || $image_url === '') {
                        continue;
                    }

                    $images[] = array(
                        'id' => !empty($photo['id']) ? absint($photo['id']) : 0,
                        'preview' => esc_url_raw($preview_url),
                        'url' => esc_url_raw($image_url),
                        'alt' => !empty($photo['alt']) ? sanitize_text_field((string) $photo['alt']) : $query,
                        'photographer' => !empty($photo['photographer']) ? sanitize_text_field((string) $photo['photographer']) : '',
                        'photographer_url' => !empty($photo['photographer_url']) ? esc_url_raw((string) $photo['photographer_url']) : '',
                    );
                }
            }

            wp_send_json_success(array(
                'images' => $images,
                'page' => $page,
                'has_more' => !empty($data['next_page']),
            ));
        }

        public function set_featured()
        {
            check_ajax_referer('alpha_rss_ai_pexels_media', 'nonce');

            $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
            if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
                wp_send_json_error(array('message' => 'Salve o post e verifique suas permissoes antes de escolher a imagem.'), 403);
            }

            $image_url = isset($_POST['image_url']) ? esc_url_raw(wp_unslash($_POST['image_url'])) : '';
            $host = strtolower((string) wp_parse_url($image_url, PHP_URL_HOST));
            if ($image_url === '' || $host !== 'images.pexels.com') {
                wp_send_json_error(array('message' => 'A imagem selecionada nao pertence ao CDN do Pexels.'), 400);
            }

            $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : 'Pexels';
            $photographer = isset($_POST['photographer']) ? sanitize_text_field(wp_unslash($_POST['photographer'])) : '';
            $title = get_the_title($post_id);
            if ($title === '') {
                $title = 'Imagem do Pexels';
            }

            $attachment_id = Alpha_RSS_AI_Generator::download_and_set_featured_image_from_url(
                $post_id,
                $image_url,
                $title,
                'pexels',
                $query,
                $photographer
            );
            if (is_wp_error($attachment_id)) {
                wp_send_json_error(array('message' => $attachment_id->get_error_message()), 500);
            }
            if (intval($attachment_id) <= 0) {
                wp_send_json_error(array('message' => 'Nao foi possivel baixar a imagem selecionada.'), 502);
            }

            wp_send_json_success(array(
                'attachment_id' => intval($attachment_id),
                'image_url' => get_the_post_thumbnail_url($post_id, 'full'),
            ));
        }
    }
}
