<?php

if (!defined('ABSPATH')) {
    exit;
}

class Alpha_RSS_AI_Generator_Helper
{
    public static function fetch_source_page_html($url, $cache_ttl = 5, $log_prefix = 'page_context')
    {
        $url = esc_url_raw(trim((string) $url));
        if ($url === '') {
            return '';
        }

        $cache_ttl = max(1, intval($cache_ttl));
        $cache_key = 'arc_source_html_' . md5($url);
        $day_cache_key = 'arc_source_html_day_' . md5($url);
        $blocked_key = 'arc_source_html_blocked_' . md5($url);

        $blocked_until = get_transient($blocked_key);
        if (!empty($blocked_until) && intval($blocked_until) > time()) {
            return '';
        }

        $cached_day_html = get_transient($day_cache_key);
        if (is_string($cached_day_html) && $cached_day_html !== '') {
            return $cached_day_html;
        }

        $cached_html = get_transient($cache_key);
        if (is_string($cached_html) && $cached_html !== '') {
            return $cached_html;
        }

        $request_args = array(
            'timeout' => 25,
            'redirection' => 4,
            'httpversion' => '1.1',
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Upgrade-Insecure-Requests' => '1',
                'Referer' => $url,
            ),
        );

        $response = wp_remote_get($url, $request_args);
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if ($code === 403) {
            $request_args['headers']['User-Agent'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';
            $request_args['headers']['Accept-Encoding'] = 'identity';
            $response = wp_remote_get($url, $request_args);
            $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        }

        if (is_wp_error($response)) {
            return '';
        }

        if ($code < 200 || $code >= 300) {
            if ($code === 403) {
                set_transient($blocked_key, time() + 300, 300);
            }
            return '';
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return '';
        }

        set_transient($cache_key, $html, $cache_ttl);
        set_transient($day_cache_key, $html, DAY_IN_SECONDS);

        $html_log = substr($html, 0, 3000);
        $html_log = str_replace(array("\r", "\n"), array('\\r', '\\n'), $html_log);

        return $html;
    }

    public static function resolve_url_against_base($url, $base_url = '')
    {
        if (class_exists('Alpha_RSS_AI_Generator') && method_exists('Alpha_RSS_AI_Generator', 'resolve_url_against_base')) {
            return Alpha_RSS_AI_Generator::resolve_url_against_base($url, $base_url);
        }

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

    public static function clean_source_text($text)
    {
        if (class_exists('Alpha_RSS_AI_Generator') && method_exists('Alpha_RSS_AI_Generator', 'clean_source_text')) {
            return Alpha_RSS_AI_Generator::clean_source_text($text);
        }

        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $text = wp_strip_all_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    public static function normalize_plain_text($value)
    {
        return self::normalize_prompt_context_text($value);
    }

    public static function bulk_parse_spreadsheet_file($file_path)
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new Exception('Biblioteca de planilhas nao carregada');
        }

        $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        if ($file_extension === 'xlsx' && !class_exists('ZipArchive')) {
            throw new Exception('Arquivos XLSX exigem a extensao PHP zip (ZipArchive) habilitada no servidor');
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file_path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv && method_exists($reader, 'setDelimiter')) {
            $reader->setDelimiter(Alpha_RSS_AI_Generator::bulk_detect_csv_delimiter($file_path));
        }

        $spreadsheet = $reader->load($file_path);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet_rows = $sheet->toArray('', true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $normalized_rows = array();
        foreach ($sheet_rows as $raw_row) {
            $clean_row = array_map(array('Alpha_RSS_AI_Generator', 'bulk_sanitize_cell'), is_array($raw_row) ? $raw_row : array());
            $has_value = false;
            foreach ($clean_row as $cell) {
                if ($cell !== '') {
                    $has_value = true;
                    break;
                }
            }

            if ($has_value) {
                $normalized_rows[] = $clean_row;
            }
        }

        if (empty($normalized_rows)) {
            throw new Exception('A planilha esta vazia');
        }

        $header_row = array_shift($normalized_rows);
        $max_columns = count($header_row);
        foreach ($normalized_rows as $row_values) {
            $max_columns = max($max_columns, count($row_values));
        }

        $used_columns = array();
        for ($index = 0; $index < $max_columns; $index++) {
            $header_value = isset($header_row[$index]) ? trim((string) $header_row[$index]) : '';
            $has_content = ($header_value !== '');

            if (!$has_content) {
                foreach ($normalized_rows as $row_values) {
                    if (isset($row_values[$index]) && trim((string) $row_values[$index]) !== '') {
                        $has_content = true;
                        break;
                    }
                }
            }

            if ($has_content) {
                $used_columns[] = $index;
            }
        }

        $headers = array();
        foreach ($used_columns as $index) {
            $header_value = isset($header_row[$index]) ? trim((string) $header_row[$index]) : '';
            if ($header_value === '') {
                $header_value = 'Column ' . ($index + 1);
            }
            $headers[] = Alpha_RSS_AI_Generator::bulk_make_unique_header($header_value, $headers);
        }

        $rows = array();
        foreach ($normalized_rows as $row_values) {
            $filtered_row = array();
            foreach ($used_columns as $index) {
                $filtered_row[] = isset($row_values[$index]) ? $row_values[$index] : '';
            }
            $rows[] = $filtered_row;
        }

        if (empty($headers)) {
            throw new Exception('Nao foi possivel identificar o cabecalho da planilha');
        }

        return array(
            'headers' => $headers,
            'rows' => $rows,
        );
    }


    public static function bulk_resolve_keyword_row($row_data, $column_map)
    {
        $keyword_column = isset($column_map['keyword_column']) ? $column_map['keyword_column'] : '';
        $source_title_column = isset($column_map['source_title_column']) ? $column_map['source_title_column'] : '';
        $source_url_column = isset($column_map['source_url_column']) ? $column_map['source_url_column'] : '';
        $slug_column = isset($column_map['slug_column']) ? $column_map['slug_column'] : '';

        $keyword = Alpha_RSS_AI_Generator::bulk_find_row_value($row_data, $keyword_column);
        $source_title = Alpha_RSS_AI_Generator::bulk_find_row_value($row_data, $source_title_column);
        $source_url_candidate = Alpha_RSS_AI_Generator::bulk_find_row_value($row_data, $source_url_column);
        $slug_candidate = Alpha_RSS_AI_Generator::bulk_find_row_value($row_data, $slug_column);

        if ($slug_candidate === '' && $source_url_candidate !== '') {
            $slug_candidate = $source_url_candidate;
        }

        $slug_info = Alpha_RSS_AI_Generator::bulk_resolve_slug_info($slug_candidate);
        $canonical_source_url = Alpha_RSS_AI_Generator::bulk_normalize_url_for_dedupe($source_url_candidate);
        $source_url = !empty($slug_info['source_url']) ? $slug_info['source_url'] : $source_url_candidate;
        $error_message = '';
        $row_status = 'pending';
        $slug_is_valid = 1;

        if ($keyword === '') {
            $row_status = 'invalid_slug';
            $slug_is_valid = 0;
            $error_message = 'Keyword vazia';
        } elseif (empty($slug_info['valid'])) {
            $row_status = 'invalid_slug';
            $slug_is_valid = 0;
            $error_message = !empty($slug_info['extension'])
                ? 'Slug final com extensao bloqueada: ' . $slug_info['extension']
                : 'Nao foi possivel extrair slug final';
        }

        return array(
            'keyword' => $keyword,
            'source_title' => $source_title,
            'source_url' => $source_url,
            'source_url_candidate' => $source_url_candidate,
            'final_slug' => !empty($slug_info['slug']) ? $slug_info['slug'] : '',
            'slug_extension' => !empty($slug_info['extension']) ? $slug_info['extension'] : '',
            'slug_is_valid' => $slug_is_valid,
            'row_status' => $row_status,
            'error_message' => $error_message,
            'canonical_source_url' => $canonical_source_url,
            'slug_key' => !empty($slug_info['valid']) ? sanitize_title($slug_info['slug']) : '',
        );
    }

    public static function build_xpath_class_condition($selector_class)
    {
        $tokens = self::normalize_selector_class_tokens($selector_class);
        if (empty($tokens)) {
            return '';
        }

        $parts = array();
        foreach ($tokens as $token) {
            $parts[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")';
        }

        return implode(' and ', $parts);
    }

    public static function extract_selector_media_candidate_from_html($html, $base_url = '', $selector_class = '', $kind = 'image')
    {
        $result = array(
            'image_url' => '',
            'image_source' => '',
            'image_class' => '',
            'image_attr' => '',
            'image_tag' => '',
            'link_url' => '',
            'link_text' => '',
            'link_source' => '',
        );

        $selector_class = trim((string) $selector_class);
        $kind = sanitize_key((string) $kind);
        if ($selector_class === '' || $html === '' || !in_array($kind, array('image', 'link'), true)) {
            return $result;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return $result;
        }

        $xpath = new DOMXPath($dom);
        $condition = self::build_xpath_class_condition($selector_class);
        if ($condition === '') {
            return $result;
        }

        $nodes = $xpath->query('//*[' . $condition . ']');
        if (!$nodes || $nodes->length === 0) {
            return $result;
        }

        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }

            $links = array();
            $images = array();
            $seen_links = array();
            $seen_images = array();
            self::collect_page_outline_media_from_node($node, $base_url, $links, $images, $seen_links, $seen_images, 1, 1, '', '', true, true);

            if ($kind === 'image' && !empty($images) && !empty($images[0]['url'])) {
                $candidate = array(
                    'image_url' => !empty($images[0]['url']) ? $images[0]['url'] : '',
                    'image_source' => 'selector:' . $selector_class,
                    'image_class' => $selector_class,
                    'image_attr' => !empty($images[0]['attr']) ? $images[0]['attr'] : '',
                    'image_tag' => !empty($images[0]['source']) ? $images[0]['source'] : '',
                );
                return $candidate;
            }

            if ($kind === 'link' && !empty($links) && !empty($links[0]['url'])) {
                $candidate = array(
                    'link_url' => !empty($links[0]['url']) ? $links[0]['url'] : '',
                    'link_text' => !empty($links[0]['text']) ? $links[0]['text'] : '',
                    'link_source' => 'selector:' . $selector_class,
                );
                return $candidate;
            }
        }

        return $result;
    }

    public static function extract_media_from_html($html, $base_url = '', $video_selector_class = '', $image_selector_class = '', $link_selector_class = '')
    {
        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $media = array(
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

        if ($html === '') {
            return $media;
        }

        foreach (array('og:image', 'og:image:url', 'twitter:image', 'twitter:image:src', 'thumbnailUrl', 'image') as $key) {
            if ($media['image_url'] !== '') {
                break;
            }
            if (!preg_match_all('/<meta[^>]+(?:property|name|itemprop)=["\']' . preg_quote($key, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                continue;
            }

            foreach ((array) $matches[1] as $candidate_url) {
                $candidate = Alpha_RSS_AI_Generator::resolve_url_against_base(html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                if ($candidate === '' || Alpha_RSS_AI_Generator::is_probably_bad_featured_image_url($candidate, $base_url)) {
                    continue;
                }
                $media['image_url'] = $candidate;
                $media['image_source'] = $key;
                $media['image_tag'] = 'meta';
                $media['image_attr'] = 'content';
                break 2;
            }
        }

        if ($link_selector_class !== '') {
            $link_candidate = self::extract_selector_media_candidate_from_html($html, $base_url, $link_selector_class, 'link');
            if (!empty($link_candidate['link_url'])) {
                $media['link_url'] = $link_candidate['link_url'];
                $media['link_text'] = !empty($link_candidate['link_text']) ? $link_candidate['link_text'] : '';
                $media['link_source'] = !empty($link_candidate['link_source']) ? $link_candidate['link_source'] : '';
            }
        }

        if ($media['image_url'] === '') {
            $image_attributes = array('data-img-url', 'data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-full', 'data-large');
            foreach ($image_attributes as $attribute) {
                if (!preg_match_all('/<' . '[^>]+\\b' . preg_quote($attribute, '/') . '=["\']([^"\']+)["\']/i', $html, $matches)) {
                    continue;
                }

                foreach ((array) $matches[1] as $candidate_url) {
                    $candidate_url = trim((string) $candidate_url);
                    if ($candidate_url === '') {
                        continue;
                    }
                    $candidate_url = Alpha_RSS_AI_Generator::resolve_url_against_base(html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                    if ($candidate_url === '' || Alpha_RSS_AI_Generator::is_probably_bad_featured_image_url($candidate_url, $base_url)) {
                        continue;
                    }
                    $media['image_url'] = $candidate_url;
                    $media['image_source'] = $attribute;
                    $media['image_tag'] = 'attr';
                    $media['image_attr'] = $attribute;
                    break 2;
                }
            }
        }

        if ($media['image_url'] === '') {
            foreach (array('srcset', 'data-srcset') as $attribute) {
                if (!preg_match_all('/<' . '[^>]+\\b' . preg_quote($attribute, '/') . '=["\']([^"\']+)["\']/i', $html, $matches)) {
                    continue;
                }

                foreach ((array) $matches[1] as $candidate_set) {
                    $candidate_url = self::pick_best_srcset_url((string) $candidate_set);
                    $candidate_url = Alpha_RSS_AI_Generator::resolve_url_against_base(html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                    if ($candidate_url === '' || Alpha_RSS_AI_Generator::is_probably_bad_featured_image_url($candidate_url, $base_url)) {
                        continue;
                    }
                    $media['image_url'] = $candidate_url;
                    $media['image_source'] = $attribute;
                    $media['image_tag'] = 'source';
                    $media['image_attr'] = $attribute;
                    break 2;
                }
            }
        }

        if ($media['image_url'] === '') {
            if (preg_match_all('/<img\b[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
                foreach ((array) $matches[1] as $candidate_url) {
                    $candidate_url = Alpha_RSS_AI_Generator::resolve_url_against_base(html_entity_decode($candidate_url, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                    if ($candidate_url === '' || Alpha_RSS_AI_Generator::is_probably_bad_featured_image_url($candidate_url, $base_url)) {
                        continue;
                    }
                    $media['image_url'] = $candidate_url;
                    $media['image_source'] = 'img_tag_match';
                    $media['image_tag'] = 'img';
                    $media['image_attr'] = 'src';
                    break;
                }
            }
        }

        if ($media['link_url'] === '') {
            $link_candidate = self::extract_primary_external_link_from_html($html, $base_url);
            if (!empty($link_candidate['link_url'])) {
                $media['link_url'] = $link_candidate['link_url'];
                $media['link_text'] = !empty($link_candidate['link_text']) ? $link_candidate['link_text'] : '';
                $media['link_source'] = !empty($link_candidate['link_source']) ? $link_candidate['link_source'] : '';
            }
        }

        $video_candidate = Alpha_RSS_AI_Generator::extract_video_candidate_from_html($html, $base_url, $video_selector_class);
        if (!empty($video_candidate['video_url'])) {
            $media['video_url'] = $video_candidate['video_url'];
        }
        if (!empty($video_candidate['video_embed_html'])) {
            $media['video_embed_html'] = $video_candidate['video_embed_html'];
        }
        if (!empty($video_candidate['video_source'])) {
            $media['video_source'] = $video_candidate['video_source'];
        }

        return $media;
    }

    public static function extract_media_from_source_page($url,  $video_selector_class = '', $image_selector_class = '', $link_selector_class = '')
    {
        $empty_media = array(
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

        $url = esc_url_raw(trim((string) $url));
        if ($url === '') {
            return $empty_media;
        }

        $html = self::fetch_source_page_html($url, 5, 'source_page_media');
        if ($html === '') {
            return $empty_media;
        }

        $media = self::extract_media_from_html($html, $url, $video_selector_class, $image_selector_class, $link_selector_class);
        if ($video_selector_class === '' && $media['video_url'] === '') {
            foreach (array('og:video', 'og:video:url', 'twitter:player:stream') as $key) {
                if (preg_match('/<meta[^>]+(?:property|name|itemprop)=["\']' . preg_quote($key, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
                    $candidate = Alpha_RSS_AI_Generator::resolve_url_against_base(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $url);
                    if ($candidate !== '') {
                        $media['video_url'] = $candidate;
                        break;
                    }
                }
            }
        }

        $media = wp_parse_args($media, $empty_media);
        return $media;
    }

    public static function normalize_selector_class_tokens($selector_class)
    {
        $selector_class = trim(preg_replace('/\s+/', ' ', (string) $selector_class));
        if ($selector_class === '') {
            return array();
        }

        $tokens = preg_split('/\s+/', $selector_class);
        if (empty($tokens)) {
            return array();
        }

        $clean_tokens = array();
        foreach ($tokens as $token) {
            $token = sanitize_html_class(trim((string) $token));
            if ($token !== '') {
                $clean_tokens[] = $token;
            }
        }

        return array_values(array_unique($clean_tokens));
    }

    public static function node_matches_class_selector($node, $selector_class)
    {
        if (!($node instanceof DOMElement)) {
            return false;
        }

        $selector_tokens = self::normalize_selector_class_tokens($selector_class);
        if (empty($selector_tokens)) {
            return false;
        }

        if (!$node->hasAttribute('class')) {
            return false;
        }

        $node_tokens = preg_split('/\s+/', trim((string) $node->getAttribute('class')));
        if (empty($node_tokens)) {
            return false;
        }

        $normalized_node_tokens = array();
        foreach ($node_tokens as $token) {
            $token = sanitize_html_class(trim((string) $token));
            if ($token !== '') {
                $normalized_node_tokens[] = $token;
            }
        }

        if (empty($normalized_node_tokens)) {
            return false;
        }

        foreach ($selector_tokens as $selector_token) {
            if (!in_array($selector_token, $normalized_node_tokens, true)) {
                return false;
            }
        }

        return true;
    }

    public static function extract_page_outline_from_html($html, $base_url = '', $max_sections = 6, $max_links_per_section = 5, $max_images_per_section = 3, $image_selector_class = '', $link_selector_class = '')
    {
        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        if ($html === '') {
            return array();
        }

        $max_sections = max(1, intval($max_sections));
        $max_links_per_section = max(0, intval($max_links_per_section));
        $max_images_per_section = max(0, intval($max_images_per_section));

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return array();
        }

        $xpath = new DOMXPath($dom);
        $h2_nodes = $xpath->query('//h2');
        if (!$h2_nodes || $h2_nodes->length === 0) {
            return array();
        }

        $outline = array();
        for ($i = 0; $i < $h2_nodes->length && count($outline) < $max_sections; $i++) {
            $h2 = $h2_nodes->item($i);
            if (!($h2 instanceof DOMElement)) {
                continue;
            }

            $title = self::clean_source_text($h2->textContent);
            if ($title === '') {
                continue;
            }

            $section_links = array();
            $section_images = array();
            $seen_links = array();
            $seen_images = array();
            $section_text_parts = array();

            $cursor = $h2->nextSibling;
            while ($cursor) {
                if ($cursor->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower((string) $cursor->nodeName);
                    if ($tag === 'h1' || $tag === 'h2') {
                        break;
                    }
                    $cursor_text = self::clean_source_text($cursor->textContent);
                    if ($cursor_text !== '') {
                        $section_text_parts[] = $cursor_text;
                    }
                    self::collect_page_outline_media_from_node(
                        $cursor,
                        $base_url,
                        $section_links,
                        $section_images,
                        $seen_links,
                        $seen_images,
                        $max_links_per_section,
                        $max_images_per_section,
                        $image_selector_class,
                        $link_selector_class
                    );
                    if (($max_links_per_section > 0 && count($section_links) >= $max_links_per_section) && ($max_images_per_section > 0 && count($section_images) >= $max_images_per_section)) {
                        break;
                    }
                }

                $cursor = $cursor->nextSibling;
            }

            $outline[] = array(
                'h2' => $title,
                'text' => trim(mb_substr(implode(' ', array_slice($section_text_parts, 0, 4)), 0, 600)),
                'image_selector_class' => $image_selector_class,
                'link_selector_class' => $link_selector_class,
                'links' => $section_links,
                'images' => $section_images,
            );
        }

        return $outline;
    }

    public static function collect_page_outline_media_from_node($node, $base_url, array &$links, array &$images, array &$seen_links, array &$seen_images, $max_links = 5, $max_images = 3, $image_selector_class = '', $link_selector_class = '', $image_scope_active = false, $link_scope_active = false)
    {
        if (!($node instanceof DOMElement)) {
            return;
        }

        $tag = strtolower((string) $node->nodeName);
        if (in_array($tag, array('script', 'style', 'noscript'), true)) {
            return;
        }

        $matches_image_selector = $image_selector_class !== '' && self::node_matches_class_selector($node, $image_selector_class);
        $matches_link_selector = $link_selector_class !== '' && self::node_matches_class_selector($node, $link_selector_class);
        $image_scope_active = $image_scope_active || $matches_image_selector;
        $link_scope_active = $link_scope_active || $matches_link_selector;

        if ($max_links > 0 && count($links) < $max_links && $node->hasAttribute('href')) {
            if ($link_selector_class === '' || $link_scope_active || $matches_link_selector) {
                $href = html_entity_decode(trim((string) $node->getAttribute('href')), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
                if ($href !== '' && !preg_match('~^(javascript:|mailto:|tel:|#)~i', $href)) {
                    $resolved = self::resolve_url_against_base($href, $base_url);
                    if ($resolved !== '' && preg_match('~^https?://~i', $resolved) && !isset($seen_links[$resolved])) {
                        $seen_links[$resolved] = true;
                        $links[] = array(
                            'text' => self::clean_source_text($node->textContent),
                            'url' => $resolved,
                            'source' => $tag,
                        );
                    }
                }
            }
        }

        if ($max_images > 0 && count($images) < $max_images) {
            if ($image_selector_class === '' || $image_scope_active || $matches_image_selector) {
                $image_url = '';
                $image_attr = '';
                $candidate_attrs = array('data-img-url', 'data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-full', 'data-large', 'src', 'srcset', 'data-srcset');
                foreach ($candidate_attrs as $attribute) {
                    if (!$node->hasAttribute($attribute)) {
                        continue;
                    }

                    $value = trim((string) $node->getAttribute($attribute));
                    if ($value === '') {
                        continue;
                    }

                    if ($attribute === 'srcset' || $attribute === 'data-srcset') {
                        $value = self::pick_best_srcset_url($value);
                    }

                    $candidate = self::resolve_url_against_base(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')), $base_url);
                    if ($candidate === '' || Alpha_RSS_AI_Generator::is_probably_bad_featured_image_url($candidate, $base_url)) {
                        continue;
                    }

                    $image_url = $candidate;
                    $image_attr = $attribute;
                    break;
                }

                if ($image_url !== '' && !isset($seen_images[$image_url])) {
                    $seen_images[$image_url] = true;
                    $images[] = array(
                        'url' => $image_url,
                        'attr' => $image_attr,
                        'source' => $tag,
                    );
                }
            }
        }

        foreach ($node->childNodes as $child) {
            if (($max_links > 0 && count($links) >= $max_links) && ($max_images > 0 && count($images) >= $max_images)) {
                break;
            }
            self::collect_page_outline_media_from_node(
                $child,
                $base_url,
                $links,
                $images,
                $seen_links,
                $seen_images,
                $max_links,
                $max_images,
                $image_selector_class,
                $link_selector_class,
                $image_scope_active,
                $link_scope_active
            );
        }
    }

    public static function format_page_outline_for_prompt($outline)
    {
        if (!is_array($outline) || empty($outline)) {
            return '';
        }

        $lines = array();
        foreach ($outline as $section_index => $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = isset($section['h2']) ? self::clean_source_text($section['h2']) : '';
            if ($title === '') {
                continue;
            }

            $lines[] = 'H2 ' . ($section_index + 1) . ': ' . $title;

            $section_text = isset($section['text']) ? self::clean_source_text($section['text']) : '';
            if ($section_text !== '') {
                $lines[] = 'Texto: ' . wp_trim_words($section_text, 30);
            }

            $image_selector_class = isset($section['image_selector_class']) ? self::clean_source_text($section['image_selector_class']) : '';
            $link_selector_class = isset($section['link_selector_class']) ? self::clean_source_text($section['link_selector_class']) : '';

            $links = isset($section['links']) && is_array($section['links']) ? $section['links'] : array();
            if (!empty($links)) {
                $link_parts = array();
                foreach ($links as $link) {
                    if (!is_array($link) || empty($link['url'])) {
                        continue;
                    }
                    $link_text = isset($link['text']) ? self::clean_source_text($link['text']) : '';
                    $link_url = trim((string) $link['url']);
                    $link_parts[] = $link_text !== '' ? ($link_text . ' -> ' . $link_url) : $link_url;
                }
                if (!empty($link_parts)) {
                    $label = $link_selector_class !== '' ? 'Links da classe ' . $link_selector_class : 'Links neste H2';
                    $lines[] = $label . ': ' . implode(' | ', array_slice($link_parts, 0, 10));
                }
            }

            $images = isset($section['images']) && is_array($section['images']) ? $section['images'] : array();
            if (!empty($images)) {
                $image_parts = array();
                foreach ($images as $image) {
                    if (!is_array($image) || empty($image['url'])) {
                        continue;
                    }
                    $image_parts[] = trim((string) $image['url']);
                }
                if (!empty($image_parts)) {
                    $label = $image_selector_class !== '' ? 'Imagens da classe ' . $image_selector_class : 'Imagens neste H2';
                    $lines[] = $label . ': ' . implode(' | ', array_slice($image_parts, 0, 10));
                }
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    public static function parse_source_link_cta_phrases($phrases)
    {
        if (is_array($phrases)) {
            $phrases = implode("\n", $phrases);
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $phrases);
        $items = array();
        foreach ((array) $lines as $line) {
            $line = sanitize_text_field(trim((string) $line));
            if ($line !== '') {
                $items[] = $line;
            }
        }

        $items = array_values(array_unique($items));
        if (empty($items) && class_exists('Alpha_RSS_AI_Generator')) {
            $items = preg_split('/\r\n|\r|\n/', Alpha_RSS_AI_Generator::get_default_source_link_cta_phrases());
            $items = array_values(array_filter(array_map('sanitize_text_field', (array) $items)));
        }

        return $items;
    }

    public static function parse_source_context_filter_phrases($phrases)
    {
        if (is_array($phrases)) {
            $phrases = implode("\n", $phrases);
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $phrases);
        $items = array();
        foreach ((array) $lines as $line) {
            $line = sanitize_text_field(trim((string) $line));
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return array_values(array_unique($items));
    }

    public static function normalize_source_context_filters($filters)
    {
        if (is_string($filters)) {
            $filters = trim($filters);
            if ($filters === '') {
                $filters = array();
            } else {
                $decoded = json_decode($filters, true);
                $filters = is_array($decoded) ? $decoded : array();
            }
        }

        if (!is_array($filters)) {
            $filters = array();
        }

        $exclude_phrases = array();
        if (!empty($filters['exclude_phrases'])) {
            $exclude_phrases = self::parse_source_context_filter_phrases($filters['exclude_phrases']);
        } elseif (!empty($filters['exclude'])) {
            $exclude_phrases = self::parse_source_context_filter_phrases($filters['exclude']);
        }

        $rating_label = '';
        if (isset($filters['rating_label'])) {
            $rating_label = sanitize_text_field((string) $filters['rating_label']);
        }
        if ($rating_label === '') {
            $rating_label = 'IMDb';
        }

        $min_rating = 0.0;
        if (isset($filters['min_rating']) && $filters['min_rating'] !== '') {
            $min_rating = floatval(str_replace(',', '.', (string) $filters['min_rating']));
        }

        $keep_unrated = !empty($filters['keep_unrated']) ? 1 : 0;

        return array(
            'exclude_phrases' => $exclude_phrases,
            'rating_label' => $rating_label,
            'min_rating' => max(0, $min_rating),
            'keep_unrated' => $keep_unrated,
        );
    }

    public static function extract_source_context_rating_from_text($text, $rating_label = '')
    {
        $text = self::clean_source_text($text);
        if ($text === '') {
            return null;
        }

        $rating_label = trim((string) $rating_label);
        if ($rating_label !== '') {
            $pattern = '~' . preg_quote($rating_label, '~') . '\s*(?:[:\-–]?\s*)?(\d{1,2}(?:[.,]\d+)?)\s*(?:/\s*10)?~i';
            if (preg_match($pattern, $text, $matches) && isset($matches[1])) {
                return floatval(str_replace(',', '.', $matches[1]));
            }
        }

        if (preg_match('~(\d{1,2}(?:[.,]\d+)?)\s*/\s*10~i', $text, $matches) && isset($matches[1])) {
            return floatval(str_replace(',', '.', $matches[1]));
        }

        return null;
    }

    protected static function source_context_section_haystack($section)
    {
        if (!is_array($section)) {
            return '';
        }

        $parts = array();
        if (!empty($section['h2'])) {
            $parts[] = self::clean_source_text($section['h2']);
        }
        if (!empty($section['text'])) {
            $parts[] = self::clean_source_text($section['text']);
        }

        if (!empty($section['links']) && is_array($section['links'])) {
            foreach ($section['links'] as $link) {
                if (!is_array($link) || empty($link['url'])) {
                    continue;
                }
                $link_text = !empty($link['text']) ? self::clean_source_text($link['text']) : '';
                $link_url = trim((string) $link['url']);
                if ($link_text !== '') {
                    $parts[] = $link_text;
                }
                if ($link_url !== '') {
                    $parts[] = $link_url;
                }
            }
        }

        if (!empty($section['images']) && is_array($section['images'])) {
            foreach ($section['images'] as $image) {
                if (!is_array($image) || empty($image['url'])) {
                    continue;
                }
                $parts[] = trim((string) $image['url']);
            }
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts)))), 'UTF-8');
    }

    public static function source_context_section_matches_filters($section, $filters)
    {
        $section = is_array($section) ? $section : array();
        $filters = self::normalize_source_context_filters($filters);

        $haystack = self::source_context_section_haystack($section);
        if ($haystack === '') {
            return true;
        }

        foreach ($filters['exclude_phrases'] as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase === '') {
                continue;
            }
            if (mb_stripos($haystack, mb_strtolower($phrase, 'UTF-8'), 0, 'UTF-8') !== false) {
                return false;
            }
        }

        $min_rating = isset($filters['min_rating']) ? floatval($filters['min_rating']) : 0.0;
        if ($min_rating > 0) {
            $rating_label = isset($filters['rating_label']) ? (string) $filters['rating_label'] : '';
            $rating_text = trim((string) (isset($section['h2']) ? $section['h2'] : '') . ' ' . (isset($section['text']) ? $section['text'] : ''));
            $rating = self::extract_source_context_rating_from_text($rating_text, $rating_label);
            if ($rating === null) {
                if (empty($filters['keep_unrated'])) {
                    return false;
                }

                $confidence_text = self::clean_source_text($rating_text);
                $confidence_length = function_exists('mb_strlen') ? mb_strlen($confidence_text, 'UTF-8') : strlen($confidence_text);
                $word_count = 0;
                if ($confidence_text !== '') {
                    $words = preg_split('/\s+/u', $confidence_text);
                    if (is_array($words)) {
                        foreach ($words as $word) {
                            if (trim((string) $word) !== '') {
                                $word_count++;
                            }
                        }
                    }
                }

                if ($word_count <= 3 || $confidence_length < 80) {
                    return false;
                }

                return true;
            }
            if ($rating < $min_rating) {
                return false;
            }
        }

        return true;
    }

    public static function source_context_item_matches_filters($item, $filters)
    {
        $item = is_array($item) ? $item : array();
        $filters = self::normalize_source_context_filters($filters);

        $headline_parts = array();
        foreach (array('source_title', 'title', 'keyword', 'feed_title') as $key) {
            if (!empty($item[$key])) {
                $headline_parts[] = self::clean_source_text($item[$key]);
            }
        }

        $text_parts = array();
        foreach (array('source_page_excerpt', 'source_page_content', 'excerpt', 'content', 'source_link_text') as $key) {
            if (!empty($item[$key])) {
                $text_parts[] = self::clean_source_text($item[$key]);
            }
        }

        if (!empty($item['row_data'])) {
            if (is_array($item['row_data']) || is_object($item['row_data'])) {
                $encoded_row_data = wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded_row_data !== false && $encoded_row_data !== '') {
                    $text_parts[] = self::clean_source_text($encoded_row_data);
                }
            } else {
                $text_parts[] = self::clean_source_text($item['row_data']);
            }
        }

        $section = array(
            'h2' => !empty($headline_parts) ? implode(' | ', array_filter($headline_parts)) : '',
            'text' => !empty($text_parts) ? implode("\n\n", array_filter($text_parts)) : '',
            'links' => array(),
            'images' => array(),
        );

        foreach (array('source_link_url', 'source_url', 'permalink') as $key) {
            if (empty($item[$key])) {
                continue;
            }
            $section['links'][] = array(
                'url' => trim((string) $item[$key]),
                'text' => !empty($item['source_link_text']) ? self::clean_source_text($item['source_link_text']) : '',
            );
        }

        if (!empty($item['source_image_url'])) {
            $section['images'][] = array(
                'url' => trim((string) $item['source_image_url']),
            );
        }

        return self::source_context_section_matches_filters($section, $filters);
    }

    public static function filter_source_outline_sections($outline, $filters)
    {
        if (!is_array($outline) || empty($outline)) {
            return array();
        }

        $filters = self::normalize_source_context_filters($filters);
        if (empty($filters['exclude_phrases']) && empty($filters['min_rating'])) {
            return $outline;
        }

        $filtered = array();
        foreach ($outline as $section) {
            if (!is_array($section)) {
                continue;
            }
            if (self::source_context_section_matches_filters($section, $filters)) {
                $filtered[] = $section;
            }
        }

        return $filtered;
    }

    public static function filter_source_page_content($content, $filters)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return '';
        }

        $filters = self::normalize_source_context_filters($filters);
        if (empty($filters['exclude_phrases']) && empty($filters['min_rating'])) {
            return $content;
        }

        $chunks = preg_split('/\n{2,}/', $content);
        if (empty($chunks)) {
            return $content;
        }

        $filtered = array();
        foreach ((array) $chunks as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }

            $chunk_section = array(
                'h2' => '',
                'text' => $chunk,
                'links' => array(),
                'images' => array(),
            );
            if (self::source_context_section_matches_filters($chunk_section, $filters)) {
                $filtered[] = $chunk;
            }
        }

        if (empty($filtered)) {
            return $content;
        }

        return trim(implode("\n\n", $filtered));
    }

    public static function apply_source_context_filters_to_page_context($page_context, $filters)
    {
        if (!is_array($page_context) || empty($page_context)) {
            return $page_context;
        }

        $filters = self::normalize_source_context_filters($filters);
        if (empty($filters['exclude_phrases']) && empty($filters['min_rating'])) {
            return $page_context;
        }

        if (!empty($page_context['outline']) && is_array($page_context['outline'])) {
            $filtered_outline = self::filter_source_outline_sections($page_context['outline'], $filters);
            $page_context['outline'] = $filtered_outline;
            $page_context['outline_sections'] = $filtered_outline;
            $page_context['outline_text'] = self::format_page_outline_for_prompt($filtered_outline);
        }

        if (!empty($page_context['content'])) {
            $filtered_content = self::filter_source_page_content($page_context['content'], $filters);
            $page_context['content'] = $filtered_content;
            $page_context['excerpt'] = $filtered_content !== '' ? wp_trim_words($filtered_content, 24) : '';
        }

        return $page_context;
    }

    protected static function pick_random_text_variant($items, $fallback = '')
    {
        $items = array_values(array_filter(array_map('trim', (array) $items)));
        if (!empty($items)) {
            $random_key = array_rand($items);
            return (string) $items[$random_key];
        }

        return trim((string) $fallback);
    }

    public static function build_outline_section_image_html($section, $post_id = 0, $image_size = 'medium')
    {
        if (!is_array($section)) {
            return '';
        }

        $section_title = isset($section['h2']) ? self::clean_source_text($section['h2']) : '';
        $post_id = intval($post_id);
        $image_size = Alpha_RSS_AI_Generator::normalize_image_display_size($image_size);

        $images = isset($section['images']) && is_array($section['images']) ? array_values($section['images']) : array();
        if (!empty($images)) {
            foreach ($images as $image) {
                if (!is_array($image) || empty($image['url'])) {
                    continue;
                }
                $image_url = trim((string) $image['url']);
                if ($image_url === '') {
                    continue;
                }

                $alt_text = $section_title !== '' ? $section_title : 'Imagem relacionada';
                $attachment_id = Alpha_RSS_AI_Generator::download_image_attachment_from_url($post_id, $image_url, $alt_text, 'content');
                if ($attachment_id > 0) {
                    return Alpha_RSS_AI_Generator::build_attachment_image_figure_html($attachment_id, $image_size, $alt_text, 'alignnone');
                }
                break;
            }
        }

        return '';
    }

    public static function build_outline_section_link_html($section, $link_phrases = array())
    {
        if (!is_array($section)) {
            return '';
        }

        $section_title = isset($section['h2']) ? self::clean_source_text($section['h2']) : '';
        $links = isset($section['links']) && is_array($section['links']) ? array_values($section['links']) : array();
        if (empty($links)) {
            return '';
        }

        foreach ($links as $link) {
            if (!is_array($link) || empty($link['url'])) {
                continue;
            }
            $link_url = trim((string) $link['url']);
            if ($link_url === '') {
                continue;
            }

            $link_text_options = self::parse_source_link_cta_phrases($link_phrases);
            $link_text = self::pick_random_text_variant($link_text_options, $section_title !== '' ? $section_title : __('Leia mais', 'alpha-rss-ai-generator'));

            return '<p class="arc-source-link"><a href="' . esc_url($link_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($link_text) . '</a></p>';
        }

        return '';
    }

    public static function build_outline_section_media_html($section, $post_id = 0, $image_size = 'medium', $link_phrases = array(), $use_images = true, $use_links = true)
    {
        $html_parts = array();
        if (!empty($use_images)) {
            $image_html = self::build_outline_section_image_html($section, $post_id, $image_size);
            if ($image_html !== '') {
                $html_parts[] = $image_html;
            }
        }

        if (!empty($use_links)) {
            $link_html = self::build_outline_section_link_html($section, $link_phrases);
            if ($link_html !== '') {
                $html_parts[] = $link_html;
            }
        }

        return trim(implode("\n", $html_parts));
    }

    public static function content_has_image_markup($content)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return false;
        }

        if (preg_match('~<figure\b[^>]*class=["\'][^"\']*\bwp-block-image\b[^"\']*["\'][^>]*>.*?</figure>~is', $content)) {
            return true;
        }

        if (preg_match('~<img\b[^>]*>~is', $content)) {
            return true;
        }

        return false;
    }

    public static function inject_attachment_image_into_content($content, $attachment_id, $post_id = 0, $image_size = 'medium', $preferred_h2_index = 0)
    {
        $content = trim((string) $content);
        $attachment_id = intval($attachment_id);
        if ($attachment_id <= 0) {
            return $content;
        }

        $image_html = Alpha_RSS_AI_Generator::build_attachment_image_figure_html($attachment_id, $image_size, '', 'alignnone');
        if ($image_html === '') {
            return $content;
        }

        if ($content === '') {
            return $image_html;
        }

        if (!function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $content;
        }

        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return trim($content . "\n\n" . $image_html);
        }

        $heading_positions = array();
        foreach ($blocks as $index => $block) {
            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            if ($block_name !== 'core/heading') {
                continue;
            }

            $level = 2;
            if (is_array($block) && isset($block['attrs']['level'])) {
                $level = intval($block['attrs']['level']);
            }
            if ($level === 2) {
                $heading_positions[] = $index;
            }
        }

        $insert_index = count($blocks);
        if (!empty($heading_positions)) {
            $target_h2_index = intval($preferred_h2_index);
            if ($target_h2_index <= 0) {
                $target_h2_index = (int) ceil(count($heading_positions) / 2);
            }
            $target_h2_index = max(1, min($target_h2_index, count($heading_positions)));
            $target_block_index = $heading_positions[$target_h2_index - 1];
            $insert_index = $target_block_index + 1;
        } elseif (!empty($blocks)) {
            $insert_index = 1;
        }

        $image_block = array(
            'blockName' => 'core/html',
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => $image_html,
            'innerContent' => array($image_html),
        );

        array_splice($blocks, $insert_index, 0, array($image_block));

        return serialize_blocks($blocks);
    }

    public static function inject_outline_section_media_into_content($content, $outline_sections, $post_id = 0, $image_size = 'medium', $link_phrases = array(), $use_images = true, $use_links = true)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return $content;
        }

        $use_images = !empty($use_images);
        $use_links = !empty($use_links);
        if (!$use_images && !$use_links) {
            return $content;
        }

        if (!is_array($outline_sections) || empty($outline_sections) || !function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $content;
        }

        $outline_sections = array_values(array_filter($outline_sections, function ($section) {
            return is_array($section);
        }));
        if (empty($outline_sections)) {
            return $content;
        }

        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return $content;
        }

        $result_blocks = array();
        $section_index = -1;
        $pending_link_html = '';
        $inserted_any = false;

        foreach ($blocks as $block) {
            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            $is_heading_level_2 = false;
            if ($block_name === 'core/heading') {
                $level = 2;
                if (is_array($block) && isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                $is_heading_level_2 = ($level === 2);
            }

            if ($is_heading_level_2) {
                if ($pending_link_html !== '') {
                    $result_blocks[] = array(
                        'blockName' => 'core/html',
                        'attrs' => array(),
                        'innerBlocks' => array(),
                        'innerContent' => array($pending_link_html),
                    );
                    $inserted_any = true;
                    $pending_link_html = '';
                }

                $result_blocks[] = $block;
                $section_index++;
                if (isset($outline_sections[$section_index])) {
                    if ($use_images) {
                        $section_image_html = self::build_outline_section_image_html($outline_sections[$section_index], $post_id, $image_size);
                        if ($section_image_html !== '') {
                            $result_blocks[] = array(
                                'blockName' => 'core/html',
                                'attrs' => array(),
                                'innerBlocks' => array(),
                                'innerContent' => array($section_image_html),
                            );
                            $inserted_any = true;
                        }
                    }
                    $pending_link_html = $use_links ? self::build_outline_section_link_html($outline_sections[$section_index], $link_phrases) : '';
                }
                continue;
            }

            $result_blocks[] = $block;
        }

        if ($pending_link_html !== '') {
            $result_blocks[] = array(
                'blockName' => 'core/html',
                'attrs' => array(),
                'innerBlocks' => array(),
                'innerContent' => array($pending_link_html),
            );
            $inserted_any = true;
        }

        return $inserted_any ? serialize_blocks($result_blocks) : $content;
    }

    public static function pick_best_srcset_url($srcset)
    {
        $srcset = trim((string) $srcset);
        if ($srcset === '') {
            return '';
        }

        $candidates = array();
        $entries = array_map('trim', explode(',', $srcset));
        foreach ($entries as $entry) {
            if ($entry === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $entry);
            if (empty($parts[0])) {
                continue;
            }

            $candidate_url = trim((string) $parts[0]);
            $weight = count($candidates);
            if (!empty($parts[1]) && preg_match('/(\d+)(w|x)/i', $parts[1], $matches)) {
                $weight = intval($matches[1]);
                if (strtolower($matches[2]) === 'x') {
                    $weight *= 1000;
                }
            }

            $candidates[] = array(
                'url' => $candidate_url,
                'weight' => $weight,
            );
        }

        if (empty($candidates)) {
            return '';
        }

        usort($candidates, function ($a, $b) {
            $a_weight = isset($a['weight']) ? intval($a['weight']) : 0;
            $b_weight = isset($b['weight']) ? intval($b['weight']) : 0;
            if ($a_weight === $b_weight) {
                return 0;
            }
            return ($a_weight < $b_weight) ? -1 : 1;
        });

        $best = end($candidates);
        return !empty($best['url']) ? (string) $best['url'] : '';
    }

    public static function extract_primary_external_link_from_html($html, $base_url = '')
    {
        $result = array(
            'link_url' => '',
            'link_text' => '',
            'link_source' => '',
        );

        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        if ($html === '') {
            return $result;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        if (!$loaded) {
            return $result;
        }

        $xpath = new DOMXPath($dom);
        $selectors = array(
            '//article//a[@href]',
            '//*[@role="main"]//a[@href]',
            '//main//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " article-body ")]//a[@href]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " story-body ")]//a[@href]',
            '//a[@href]',
        );

        $base_host = '';
        if ($base_url !== '') {
            $base_host = strtolower((string) wp_parse_url($base_url, PHP_URL_HOST));
        }

        $best = array(
            'score' => -1000,
            'link_url' => '',
            'link_text' => '',
            'link_source' => '',
        );
        $seen = array();

        foreach ($selectors as $selector) {
            $nodes = $xpath->query($selector);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            foreach ($nodes as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }

                if (!$node->hasAttribute('href')) {
                    continue;
                }

                $href = html_entity_decode(trim((string) $node->getAttribute('href')), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
                if ($href === '' || preg_match('~^(javascript:|mailto:|tel:|#)~i', $href)) {
                    continue;
                }

                $resolved = self::resolve_url_against_base($href, $base_url);
                if ($resolved === '' || !preg_match('~^https?://~i', $resolved)) {
                    continue;
                }

                if (isset($seen[$resolved])) {
                    continue;
                }
                $seen[$resolved] = true;

                $resolved_host = strtolower((string) wp_parse_url($resolved, PHP_URL_HOST));
                if ($base_host !== '' && $resolved_host !== '' && $resolved_host === $base_host) {
                    continue;
                }

                $text = self::clean_source_text($node->textContent);
                $class = strtolower(trim((string) $node->getAttribute('class')));
                $rel = strtolower(trim((string) $node->getAttribute('rel')));
                $target = strtolower(trim((string) $node->getAttribute('target')));
                $blob = $class . ' ' . $rel . ' ' . $target . ' ' . $resolved . ' ' . $text;

                $score = 0;
                if ($resolved_host !== '' && $base_host !== '' && $resolved_host !== $base_host) {
                    $score += 20;
                }
                if ($target === '_blank') {
                    $score += 10;
                }
                if ($rel !== '' && (strpos($rel, 'noopener') !== false || strpos($rel, 'noreferrer') !== false)) {
                    $score += 5;
                }
                if (preg_match('/affiliate|affiliate-single|cta|button|watch|stream|where-to-watch|where to watch|play|rent|buy|external|single-link|link/i', $blob)) {
                    $score += 35;
                }
                if (preg_match('/watch|assistir|ver|stream|play|rent|buy|onde assistir|onde ver|read more|view more|go to/i', $text)) {
                    $score += 30;
                }
                if (preg_match('/netflix|amazon|prime|hulu|disney|max|apple|paramount|peacock|youtube|vimeo/i', $resolved)) {
                    $score += 15;
                }
                if ($text === '') {
                    $score -= 5;
                }

                if ($score > $best['score']) {
                    $best = array(
                        'score' => $score,
                        'link_url' => $resolved,
                        'link_text' => $text,
                        'link_source' => 'content_anchor',
                    );
                }
            }
        }

        if (!empty($best['link_url'])) {
            $result['link_url'] = $best['link_url'];
            $result['link_text'] = $best['link_text'];
            $result['link_source'] = $best['link_source'];
        }

        return $result;
    }

    public static function normalize_generated_title($title, $source_title = '')
    {
        $title = sanitize_text_field(trim((string) $title));
        if ($title === '') {
            return '';
        }

        $title = preg_replace('/\s+/u', ' ', $title);

        return trim($title);
    }

    public static function extract_outline_target_h2_count_from_title($title, $reference_title = '')
    {
        $candidates = array(
            self::normalize_prompt_context_text($title),
            self::normalize_prompt_context_text($reference_title),
        );

        $count_keywords = '(?:top|best|melhor(?:es)?|maior(?:es)?|pior(?:es)?|filme(?:s)?|movie(?:s)?|serie(?:s)?|s?erie(?:s)?|show(?:s)?|drama(?:s)?|thriller(?:s)?|romance(?:s)?|comedia(?:s)?|aventura(?:s)?|episodio(?:s)?|livro(?:s)?|book(?:s)?|coisa(?:s)?|motivo(?:s)?|dica(?:s)?|opcao(?:oes)?|item(?:s)?|personagem(?:ns)?|produto(?:s)?|lugar(?:es)?|maneira(?:s)?|forma(?:s)?|documentario(?:s)?|anime(?:s)?|terror|horror|ranking|lista|things|ways|reasons|facts|tips|tricks|ideas|examples|trailer(?:s)?)';

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $normalized = mb_strtolower(remove_accents($candidate), 'UTF-8');
            if ($normalized === '') {
                continue;
            }

            if (!preg_match_all('/\b(\d{1,3})\b/u', $normalized, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $match) {
                $count = intval($match[0]);
                if ($count <= 0 || $count > 100) {
                    continue;
                }

                $offset = isset($match[1]) ? intval($match[1]) : 0;
                $window_start = max(0, $offset - 48);
                $window = substr($normalized, $window_start, 96);
                if ($window === '') {
                    continue;
                }

                if (preg_match('/' . $count_keywords . '/u', $window)) {
                    return $count;
                }
            }
        }

        return 0;
    }

    public static function normalize_prompt_context_text($value)
    {
        $value = is_scalar($value) ? (string) $value : '';
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    public static function strip_generated_image_markup_from_html($html)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $html = preg_replace('~<!--\s*wp:image\b.*?<!--\s*/wp:image\s*-->~is', '', $html);
        $html = preg_replace('~<figure\b[^>]*class=["\'][^"\']*\bwp-block-image\b[^"\']*["\'][^>]*>.*?</figure>~is', '', $html);
        $html = preg_replace('~<picture\b[^>]*>.*?</picture>~is', '', $html);
        $html = preg_replace('~<img\b[^>]*>~is', '', $html);
        $html = preg_replace('~<p[^>]*>\s*</p>~is', '', $html);
        $html = preg_replace('~\n{3,}~', "\n\n", $html);

        return trim($html);
    }

    public static function build_source_context_block($generator, $item)
    {
        $lines = array('## DADOS DA FONTE');

        $feed_title = isset($item['feed_title']) ? self::normalize_prompt_context_text($item['feed_title']) : '';
        $source_title = isset($item['source_title']) ? self::normalize_prompt_context_text($item['source_title']) : '';
        $source_url = isset($item['source_url']) ? trim((string) $item['source_url']) : '';
        if ($source_url === '') {
            $source_url = isset($item['permalink']) ? trim((string) $item['permalink']) : '';
        }
        $source_page_outline = isset($item['source_page_outline']) ? trim((string) $item['source_page_outline']) : '';
        $source_excerpt = isset($item['excerpt']) ? self::normalize_prompt_context_text($item['excerpt']) : '';
        $source_content = isset($item['content']) ? self::normalize_prompt_context_text($item['content']) : '';
        $source_page_title = isset($item['source_page_title']) ? self::normalize_prompt_context_text($item['source_page_title']) : '';
        $source_page_excerpt = isset($item['source_page_excerpt']) ? self::normalize_prompt_context_text($item['source_page_excerpt']) : '';
        $source_page_content = isset($item['source_page_content']) ? self::normalize_prompt_context_text($item['source_page_content']) : '';
        $site_name = self::normalize_prompt_context_text(get_bloginfo('name'));
        $generation_language = !empty($generator['generation_language'])
            ? Alpha_RSS_AI_Generator::normalize_generation_language_value($generator['generation_language'])
            : Alpha_RSS_AI_Generator::get_default_generation_language();

        if ($feed_title !== '') {
            $lines[] = 'Titulo do feed: ' . $feed_title;
        }
        if ($source_title !== '') {
            $lines[] = 'Titulo do item: ' . $source_title;
        }
        if ($source_page_title !== '') {
            $lines[] = 'Titulo da pagina de origem: ' . $source_page_title;
        }
        if ($source_page_outline !== '') {
            $lines[] = 'Estrutura da pagina de origem:';
            $lines[] = $source_page_outline;
        }
        if ($source_excerpt !== '') {
            $lines[] = 'Resumo da fonte: ' . $source_excerpt;
        }
        if ($source_content !== '') {
            $lines[] = 'Conteudo da fonte: ' . $source_content;
        }
        if ($source_page_excerpt !== '') {
            $lines[] = 'Resumo da pagina de origem: ' . $source_page_excerpt;
        }
        if ($source_page_content !== '') {
            $lines[] = 'Conteudo da pagina de origem: ' . $source_page_content;
        }
        if ($site_name !== '') {
            $lines[] = 'Site: ' . $site_name;
        }
        if ($generation_language !== '') {
            $lines[] = 'Idioma final: ' . $generation_language;
        }

        return implode("\n", $lines);
    }

    public static function build_outline_context_base($generator)
    {
        $generator = is_array($generator) ? $generator : array();
        $content_length_class = !empty($generator['content_length_class']) ? Alpha_RSS_AI_Generator::normalize_content_length_class($generator['content_length_class']) : Alpha_RSS_AI_Generator::get_default_content_length_class();
        $content_length_range = Alpha_RSS_AI_Generator::get_content_length_range($content_length_class);
        $outline_model = Alpha_RSS_AI_Generator::get_generator_outline_model($generator);
        $outline_model_text = Alpha_RSS_AI_Generator::format_outline_model_for_prompt($outline_model, array(
            'content_length_class' => $content_length_class,
            'outline_target_h2_min' => !empty($outline_model['target_h2_min']) ? intval($outline_model['target_h2_min']) : 0,
            'outline_target_h2_max' => !empty($outline_model['target_h2_max']) ? intval($outline_model['target_h2_max']) : 0,
            'outline_target_h2_count' => !empty($outline_model['target_h2_count']) ? intval($outline_model['target_h2_count']) : 0,
        ));

        return array(
            'content_length_class' => $content_length_class,
            'content_length_range' => $content_length_range,
            'outline_model' => $outline_model,
            'outline_model_key' => !empty($outline_model['key']) ? (string) $outline_model['key'] : '',
            'outline_model_name' => !empty($outline_model['name']) ? (string) $outline_model['name'] : '',
            'outline_model_text' => $outline_model_text,
            'outline_target_h2_min' => !empty($outline_model['target_h2_min']) ? intval($outline_model['target_h2_min']) : 0,
            'outline_target_h2_max' => !empty($outline_model['target_h2_max']) ? intval($outline_model['target_h2_max']) : 0,
            'outline_target_h2_count' => !empty($outline_model['target_h2_count']) ? intval($outline_model['target_h2_count']) : 0,
        );
    }

    public static function format_outline_analysis_for_prompt($outline_context)
    {
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $lines = array();
        $content_type = !empty($outline_context['content_type']) ? sanitize_text_field((string) $outline_context['content_type']) : '';
        $funnel_level = !empty($outline_context['funnel_level']) ? sanitize_text_field((string) $outline_context['funnel_level']) : '';
        $tone = !empty($outline_context['tone']) ? sanitize_text_field((string) $outline_context['tone']) : '';
        if ($content_type !== '') {
            $lines[] = 'Tipo de conteudo: ' . $content_type;
        }
        if ($funnel_level !== '') {
            $lines[] = 'Nivel de funil: ' . $funnel_level;
        }
        if ($tone !== '') {
            $lines[] = 'Tom: ' . $tone;
        }

        if (!empty($outline_context['outline_sections']) && is_array($outline_context['outline_sections'])) {
            $lines[] = 'Estrutura sugerida:';
            $index = 1;
            foreach ($outline_context['outline_sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $title = !empty($section['h2']) ? sanitize_text_field((string) $section['h2']) : (!empty($section['title']) ? sanitize_text_field((string) $section['title']) : '');
                if ($title === '') {
                    $title = 'Secao ' . $index;
                }
                $purpose = !empty($section['purpose']) ? sanitize_text_field((string) $section['purpose']) : '';
                $word_budget = isset($section['word_budget']) ? intval($section['word_budget']) : 0;
                $block_type = !empty($section['type']) ? sanitize_key((string) $section['type']) : '';
                $line = $index . '. ' . $title;
                if ($block_type !== '') {
                    $line .= ' [' . $block_type . ']';
                }
                if ($word_budget > 0) {
                    $line .= ' ~' . $word_budget . ' palavras';
                }
                if ($purpose !== '') {
                    $line .= ' - ' . $purpose;
                }
                $lines[] = $line;
                $index++;
            }
        }

        if (!empty($outline_context['outline_notes'])) {
            $lines[] = 'Notas: ' . sanitize_textarea_field((string) $outline_context['outline_notes']);
        }

        return implode("\n", $lines);
    }

    public static function build_outline_analysis_prompt($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $seo_article = is_array($seo_article) ? $seo_article : array();
        $outline_context = is_array($outline_context) ? $outline_context : self::build_outline_context_base($generator);

        $source_excerpt = isset($item['excerpt']) ? self::normalize_prompt_context_text($item['excerpt']) : '';
        $source_content = isset($item['content']) ? self::normalize_prompt_context_text($item['content']) : '';
        if (strlen($source_excerpt) > 2000) {
            $source_excerpt = substr($source_excerpt, 0, 2000);
        }
        if (strlen($source_content) > 5000) {
            $source_content = substr($source_content, 0, 5000);
        }
        $generated_title = isset($seo_article['title']) ? self::normalize_prompt_context_text($seo_article['title']) : '';
        $generated_focus_keyword = isset($seo_article['focus_keyword']) ? self::normalize_prompt_context_text($seo_article['focus_keyword']) : '';
        $generated_meta_description = isset($seo_article['meta_description']) ? self::normalize_prompt_context_text($seo_article['meta_description']) : '';
        $generation_language = !empty($generator['generation_language'])
            ? Alpha_RSS_AI_Generator::normalize_generation_language_value($generator['generation_language'])
            : Alpha_RSS_AI_Generator::get_default_generation_language();
        $outline_model_text = !empty($outline_context['outline_model_text']) ? (string) $outline_context['outline_model_text'] : Alpha_RSS_AI_Generator::format_outline_model_for_prompt(Alpha_RSS_AI_Generator::get_generator_outline_model($generator), $outline_context);
        $selected_tags = Alpha_RSS_AI_Generator::get_generator_selected_tags($generator);
        $selected_tags_csv = !empty($selected_tags) ? implode(', ', $selected_tags) : '';

        $prompt = array(
            'Voce e um editor estrutural interno.',
            'Analise a fonte e devolva somente JSON valido com um outline editorial que sirva de guia para a geracao do conteudo.',
            'Nao crie o corpo do artigo aqui.',
            'Nao force uma quantidade fixa de H2; ajuste a estrutura conforme o conteudo e a densidade do assunto.',
            'Se o titulo ou a fonte sugerirem outro ritmo, ajuste a estrutura sem repetir secoes sem necessidade.',
            'Escreva em ' . $generation_language . '.',
            'Retorne apenas JSON valido com estas chaves: content_type, funnel_level, tone, outline_notes, outline_sections.',
            'outline_sections deve ser um array de objetos com: type, h2, purpose, notes.',
            'Se precisar, pode incluir blocos de intro, h2, h3, paragraph, list, bullet, table, image, button e conclusion.',
            'O objetivo e preservar os fatos e reorganizar a materia em uma estrutura mais clara e fluida.',
            'Você deve gerar o outline, ou seja, a estrutura do post, então todos os itens devem ter h2, que vai servir principalmente de base para a estrutura do conteúdo',
            'Crie títulos agressivos, fora da caixa, mas que falem o que o conteúdo quer dizer',
            'Cada h2 precisa falar com o tipo do conteúdo, por exemplo "guia completo", não casa com noticia curta, não tem como colocar um negócio desses no nosso conteúdo',
            'Seja inteligente, proibido copiar ordem ou estrutura da fonte, acredito que você tenha potencial para criar algo melhor, por exemplo, a nossa fonte está repetindo 3 formas de consultar algo, não tem pq ter 3 h3 pra isso',
            'Lembre-se do mais importante, a quantidade de headers, vai depender exclusivamente do tipo do conteúdo, uma noticia, por exemplo, não precisa ter 10 h2, apenas 2 a 4 seriam suficientes',
            'Não repita títulos, cada informação deve ser nova, o h1 tbm faz parte do conteúdo, então não repita o que ele diz',
            'A conclusão é "um proximo passo" que a pessoa tem que dar, ou seja, a atitude que a pessoa tem que tomar, pense nisso para gerar um título de conclusão incrível',
            'Não confunda outline com conteúdo, você deve gerar bons h2, nunca com sensionalismo, garantia, marketeiro, cara de propaganda, "Fique atento" é uma merda de um sensacionalismo marketeiro, quero títulos jornalisticos, nunca nada disso em nenhum h2 ou h3',
            'Poibido título com cara de CTA: confirma, saiba, veja, entenda',
            'Conteudo da fonte: ' . $source_content,
            'Resumo da fonte: ' . $source_excerpt,
            'Titulo gerado: ' . $generated_title,
            'Focus keyword: ' . $generated_focus_keyword,
            'Meta description: ' . $generated_meta_description,
            'Modelo de outline base:',
            $outline_model_text,
            'Palavras-chave selecionadas: ' . $selected_tags_csv,
            'Site: ' . get_bloginfo('name'),
        );

        $prompt = implode("\n", $prompt);

        return $prompt;
    }

    public static function normalize_outline_analysis_context($analysis, $outline_context = array())
    {
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $analysis = is_array($analysis) ? $analysis : array();

        $outline_context['content_type'] = !empty($analysis['content_type']) ? sanitize_key((string) $analysis['content_type']) : (!empty($outline_context['content_type']) ? sanitize_key((string) $outline_context['content_type']) : 'article');
        $outline_context['funnel_level'] = !empty($analysis['funnel_level']) ? sanitize_key((string) $analysis['funnel_level']) : (!empty($outline_context['funnel_level']) ? sanitize_key((string) $outline_context['funnel_level']) : 'mid');
        $outline_context['tone'] = !empty($analysis['tone']) ? sanitize_text_field((string) $analysis['tone']) : (!empty($outline_context['tone']) ? sanitize_text_field((string) $outline_context['tone']) : '');
        $outline_context['outline_notes'] = !empty($analysis['outline_notes']) ? sanitize_textarea_field((string) $analysis['outline_notes']) : '';
        $sections = array();
        $raw_sections = array();
        if (!empty($analysis['outline_sections']) && is_array($analysis['outline_sections'])) {
            $raw_sections = $analysis['outline_sections'];
        } elseif (!empty($analysis['sections']) && is_array($analysis['sections'])) {
            $raw_sections = $analysis['sections'];
        }

        foreach ($raw_sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $sections[] = array(
                'type' => !empty($section['type']) ? sanitize_key((string) $section['type']) : 'paragraph',
                'h2' => !empty($section['h2']) ? sanitize_text_field((string) $section['h2']) : (!empty($section['title']) ? sanitize_text_field((string) $section['title']) : ''),
                'purpose' => !empty($section['purpose']) ? sanitize_text_field((string) $section['purpose']) : '',
                'word_budget' => isset($section['word_budget']) ? intval($section['word_budget']) : 0,
                'notes' => !empty($section['notes']) ? sanitize_text_field((string) $section['notes']) : '',
            );
        }

        $outline_context['outline_sections'] = $sections;
        $outline_context['outline_text'] = self::format_outline_analysis_for_prompt($outline_context);

        return $outline_context;
    }

    public static function build_outline_context_from_source($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $outline_context = is_array($outline_context) && !empty($outline_context) ? $outline_context : self::build_outline_context_base($generator);
        $outline_prompt = self::build_outline_analysis_prompt($generator, $item, $seo_article, $outline_context);
        $outline_response = Alpha_RSS_AI_Generator::request_openai_json($generator, $outline_prompt, array(
            'stage' => 'outline',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($item['source_title']) ? $item['source_title'] : '',
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'allow_missing_content_html' => 1,
            'preserve_extra_fields' => 1,
        ));

        if (is_wp_error($outline_response)) {
            $outline_context['outline_error'] = $outline_response->get_error_message();
            $outline_context['outline_text'] = self::format_outline_analysis_for_prompt($outline_context);
            return $outline_context;
        }

        $outline_context = self::normalize_outline_analysis_context($outline_response, $outline_context);

        return $outline_context;
    }

    public static function build_prompt($generator, $item, $outline_context = array())
    {
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $template = trim((string) $generator['prompt_template']);
        $source_type = isset($generator['source_type']) ? sanitize_key($generator['source_type']) : 'rss';
        $keyword_list_mode = isset($generator['keyword_list_mode']) ? sanitize_key($generator['keyword_list_mode']) : Alpha_RSS_AI_Generator::get_default_keyword_list_mode();
        $template = Alpha_RSS_AI_Generator::normalize_prompt_template_for_source_type($source_type, $template, $keyword_list_mode);
        if ($template === '') {
            $template = ($source_type === 'keyword_list' && $keyword_list_mode !== 'url_reference') ? Alpha_RSS_AI_Generator::get_default_keyword_prompt_template() : Alpha_RSS_AI_Generator::get_default_prompt_template();
        }

        $row_data = isset($item['row_data']) && is_array($item['row_data']) ? wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $source_title = isset($item['source_title']) ? $item['source_title'] : '';
        $source_url = isset($item['source_url']) ? $item['source_url'] : '';
        if ($source_url === '' && isset($item['permalink'])) {
            $source_url = $item['permalink'];
        }
        $source_image_url = isset($item['source_image_url']) ? $item['source_image_url'] : '';
        $source_link_url = isset($item['source_link_url']) ? $item['source_link_url'] : '';
        $source_link_text = isset($item['source_link_text']) ? $item['source_link_text'] : '';
        $source_page_outline = isset($item['source_page_outline']) ? $item['source_page_outline'] : '';
        $image_selector_class = !empty($generator['image_selector_class']) ? $generator['image_selector_class'] : '';
        $link_selector_class = !empty($generator['link_selector_class']) ? $generator['link_selector_class'] : '';
        $final_slug = isset($item['final_slug']) ? $item['final_slug'] : '';
        $selected_tags = Alpha_RSS_AI_Generator::get_generator_selected_tags($generator);
        $selected_tags_csv = !empty($selected_tags) ? implode(', ', $selected_tags) : '';
        $content_length_class = !empty($outline_context['content_length_class']) ? Alpha_RSS_AI_Generator::normalize_content_length_class($outline_context['content_length_class']) : Alpha_RSS_AI_Generator::get_default_content_length_class();
        $content_length_range = Alpha_RSS_AI_Generator::get_content_length_range($content_length_class);
        $content_length_label = !empty($content_length_range['label']) ? $content_length_range['label'] : ucfirst($content_length_class);
        $content_length_min_words = isset($content_length_range['min_words']) ? intval($content_length_range['min_words']) : 0;
        $content_length_max_words = isset($content_length_range['max_words']) ? intval($content_length_range['max_words']) : 0;

        $replacements = array(
            '{{feed_title}}' => $item['feed_title'],
            '{{source_title}}' => $source_title,
            '{{keyword}}' => isset($item['keyword']) ? $item['keyword'] : '',
            '{{source_url}}' => $source_url,
            '{{source_permalink}}' => $item['permalink'],
            '{{source_image_url}}' => $source_image_url,
            '{{source_link_url}}' => $source_link_url,
            '{{source_link_text}}' => $source_link_text,
            '{{image_selector_class}}' => $image_selector_class,
            '{{link_selector_class}}' => $link_selector_class,
            '{{source_page_outline}}' => $source_page_outline,
            '{{source_excerpt}}' => $item['excerpt'],
            '{{source_content}}' => $item['content'],
            '{{final_slug}}' => $final_slug,
            '{{row_data}}' => $row_data,
            '{{site_name}}' => get_bloginfo('name'),
            '{{generator_name}}' => $generator['name'],
            '{{generation_language}}' => !empty($generator['generation_language']) ? Alpha_RSS_AI_Generator::normalize_generation_language_value($generator['generation_language']) : Alpha_RSS_AI_Generator::get_default_generation_language(),
            '{{selected_tags}}' => $selected_tags_csv,
            '{{content_length_class}}' => $content_length_class,
            '{{content_length_label}}' => $content_length_label,
            '{{content_length_min_words}}' => $content_length_min_words,
            '{{content_length_max_words}}' => $content_length_max_words,
        );

        $prompt = strtr($template, $replacements);
        $prompt .= "\n\n" . self::build_source_context_block($generator, $item);

        $outline_context_block = array('## CONTEXTO DE ESTRUTURA');
        if (!empty($outline_context['outline_model_name'])) {
            $outline_context_block[] = 'Modelo de outline: ' . $outline_context['outline_model_name'];
        }
        if (!empty($outline_context['outline_model_text'])) {
            $outline_context_block[] = 'Referencia do modelo de outline:';
            $outline_context_block[] = $outline_context['outline_model_text'];
        }
        $prompt .= "\n\n" . implode("\n", $outline_context_block);
        
        $prompt_preview = preg_replace('/\s+/', ' ', wp_strip_all_tags($prompt));
        $prompt_preview = function_exists('mb_substr') ? mb_substr($prompt_preview, 0, 1400) : substr($prompt_preview, 0, 1400);

        return $prompt;
    }

    public static function build_content_prompt($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $visible_template = isset($generator['content_prompt_template']) ? trim((string) $generator['content_prompt_template']) : '';
        if ($visible_template === '') {
            $visible_template = Alpha_RSS_AI_Generator::get_default_content_prompt_template_visible();
        }

        $source_title = isset($item['source_title']) ? $item['source_title'] : '';
        $source_url = isset($item['source_url']) ? $item['source_url'] : '';
        if ($source_url === '' && isset($item['permalink'])) {
            $source_url = $item['permalink'];
        }
        $source_page_title = isset($item['source_page_title']) ? $item['source_page_title'] : '';
        $source_page_excerpt = isset($item['source_page_excerpt']) ? $item['source_page_excerpt'] : '';
        $source_page_content = isset($item['source_page_content']) ? $item['source_page_content'] : '';
        $selected_tags = Alpha_RSS_AI_Generator::get_generator_selected_tags($generator);
        $selected_tags_csv = !empty($selected_tags) ? implode(', ', $selected_tags) : '';

        $generated_title = isset($seo_article['title']) ? $seo_article['title'] : '';
        $generated_slug = isset($seo_article['slug']) ? $seo_article['slug'] : '';
        $generated_excerpt = isset($seo_article['excerpt']) ? $seo_article['excerpt'] : '';
        $generated_focus_keyword = isset($seo_article['focus_keyword']) ? $seo_article['focus_keyword'] : '';
        $generated_meta_description = isset($seo_article['meta_description']) ? $seo_article['meta_description'] : '';
        $generated_title_outline_count = self::extract_outline_target_h2_count_from_title($generated_title, $source_title);
        $outline_text = !empty($outline_context['outline_text']) ? (string) $outline_context['outline_text'] : '';
        $outline_model_text = !empty($outline_context['outline_model_text']) ? (string) $outline_context['outline_model_text'] : '';
        $outline_model_name = !empty($outline_context['outline_model_name']) ? (string) $outline_context['outline_model_name'] : '';

        $hidden_context = array(
            'Contexto interno:',
            'Titulo do feed: {{feed_title}}',
            'Título gerado: {{generated_title}}',
            'kw: {{generated_focus_keyword}}',
            'Meta description: {{generated_meta_description}}',
            'Título do item: {{source_title}}',
            'Mídias e CTAs da fonte: o sistema vai inserir a imagem baixada e o link localmente; não escreva imagens, figures ou CTAs manuais no texto.',
            'Titulo da pagina de origem: {{source_page_title}}',
            'Resumo da pagina de origem: {{source_page_excerpt}}',
            'Conteudo da pagina de origem: {{source_page_content}}',
            'Resumo da fonte: {{source_excerpt}}',
            'Conteúdo da fonte: {{source_content}}',
            'Slug final: {{generated_slug}}',
            'Palavras-chave selecionadas: {{selected_tags}}',
            'Numero sugerido no titulo (apenas indicio): {{generated_title_outline_count}}',
            'Modelo de outline: {{outline_model_name}}',
            'Outline interno gerado pelo backend:',
            '{{outline_text}}',
            'Site: {{site_name}}',
            'Idioma final: {{generation_language}}',
        );

        $template = $visible_template . "\n\n" . implode("\n", $hidden_context);

        $replacements = array(
            '{{feed_title}}' => $item['feed_title'],
            '{{source_title}}' => $source_title,
            '{{keyword}}' => isset($item['keyword']) ? $item['keyword'] : '',
            '{{source_url}}' => $source_url,
            '{{source_permalink}}' => $item['permalink'],
            '{{source_page_title}}' => isset($item['source_page_title']) ? $item['source_page_title'] : '',
            '{{source_page_excerpt}}' => isset($item['source_page_excerpt']) ? $item['source_page_excerpt'] : '',
            '{{source_page_content}}' => isset($item['source_page_content']) ? $item['source_page_content'] : '',
            '{{source_page_outline}}' => isset($item['source_page_outline']) ? $item['source_page_outline'] : '',
            '{{source_excerpt}}' => $item['excerpt'],
            '{{source_content}}' => $item['content'],
            '{{final_slug}}' => isset($item['final_slug']) ? $item['final_slug'] : '',
            '{{row_data}}' => isset($item['row_data']) && is_array($item['row_data']) ? wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            '{{site_name}}' => get_bloginfo('name'),
            '{{generator_name}}' => $generator['name'],
            '{{generation_language}}' => !empty($generator['generation_language']) ? Alpha_RSS_AI_Generator::normalize_generation_language_value($generator['generation_language']) : Alpha_RSS_AI_Generator::get_default_generation_language(),
            '{{selected_tags}}' => $selected_tags_csv,
            '{{generated_title}}' => $generated_title,
            '{{generated_slug}}' => $generated_slug,
            '{{generated_excerpt}}' => $generated_excerpt,
            '{{generated_focus_keyword}}' => $generated_focus_keyword,
            '{{generated_meta_description}}' => $generated_meta_description,
            '{{generated_title_outline_count}}' => $generated_title_outline_count,
            '{{outline_model_name}}' => $outline_model_name,
            '{{outline_model_text}}' => $outline_model_text,
            '{{outline_text}}' => $outline_text,
        );

        $prompt = strtr($template, $replacements);
        $prompt_preview = preg_replace('/\s+/', ' ', wp_strip_all_tags($prompt));
        $prompt_preview = function_exists('mb_substr') ? mb_substr($prompt_preview, 0, 1400) : substr($prompt_preview, 0, 1400);

        return $prompt;
    }

    public static function count_heading_level_in_html($html, $level = 2)
    {
        $html = (string) $html;
        $level = max(1, intval($level));
        if ($html === '') {
            return 0;
        }

        $pattern = '/<h' . $level . '\b[^>]*>/i';
        if (!preg_match_all($pattern, $html, $matches)) {
            return 0;
        }

        return count($matches[0]);
    }

    public static function repair_outline_h2_count_if_needed($generator, $item, $article, $outline_context = array())
    {
        if (!is_array($article) || empty($article['content_html'])) {
            return $article;
        }

        if (empty($outline_context['force_exact_h2_count'])) {
            return $article;
        }

        $target_h2_count = 0;
        if (isset($outline_context['outline_target_h2_count'])) {
            $target_h2_count = intval($outline_context['outline_target_h2_count']);
        } elseif (isset($item['outline_target_h2_count'])) {
            $target_h2_count = intval($item['outline_target_h2_count']);
        }

        $source_title = !empty($item['source_title']) ? (string) $item['source_title'] : '';
        $generated_title = !empty($article['title']) ? (string) $article['title'] : '';
        $title_h2_count = self::extract_outline_target_h2_count_from_title($generated_title, $source_title);
        if ($title_h2_count > 0) {
            $target_h2_count = $title_h2_count;
        }

        $outline_model_text = !empty($outline_context['outline_model_text']) ? (string) $outline_context['outline_model_text'] : '';
        $outline_model_name = !empty($outline_context['outline_model_name']) ? (string) $outline_context['outline_model_name'] : '';
        $outline_h2_min = isset($outline_context['outline_target_h2_min']) ? intval($outline_context['outline_target_h2_min']) : $target_h2_count;
        $outline_h2_max = isset($outline_context['outline_target_h2_max']) ? intval($outline_context['outline_target_h2_max']) : $target_h2_count;
        if ($title_h2_count > 0) {
            $outline_h2_min = $title_h2_count;
            $outline_h2_max = $title_h2_count;
            $outline_model_text = Alpha_RSS_AI_Generator::format_outline_model_for_prompt($outline_context['outline_model'] ?? Alpha_RSS_AI_Generator::get_generator_outline_model($generator), array_merge($outline_context, array(
                'outline_target_h2_min' => $title_h2_count,
                'outline_target_h2_max' => $title_h2_count,
                'outline_target_h2_count' => $title_h2_count,
            )));
        }

        $current_h2_count = self::count_heading_level_in_html($article['content_html'], 2);

        if ($target_h2_count <= 0) {
            return $article;
        }

        if ($current_h2_count === $target_h2_count) {
            return $article;
        }
        $generated_excerpt = !empty($article['excerpt']) ? (string) $article['excerpt'] : '';
        $generated_meta_description = !empty($article['meta_description']) ? (string) $article['meta_description'] : '';
        $generated_focus_keyword = !empty($article['focus_keyword']) ? (string) $article['focus_keyword'] : '';

        $prompt = implode("\n", array(
            'Você é um editor de conteúdo e precisa corrigir apenas a estrutura do HTML já gerado.',
            'A estrutura final deve conter EXATAMENTE ' . $target_h2_count . ' blocos <h2>.',
            'Se o HTML atual tiver mais ou menos do que isso, reescreva-o para atingir a quantidade exata sem inventar fatos novos.',
            'Mantenha título, slug, meta description, focus keyword e o sentido editorial.',
            'Não use Markdown.',
            'Use apenas HTML simples no campo content_html.',
            'Retorne apenas JSON válido com a chave content_html.',
            '',
            'Contexto do outline:',
            'Nome do modelo: ' . $outline_model_name,
            'Faixa do modelo de H2: ' . $outline_h2_min . '-' . $outline_h2_max,
            'Quantidade aproximada de H2: ' . $target_h2_count,
            'Quantidade aproximada da lista/ranking: ' . $target_h2_count,
            'Estrutura do outline: ' . $outline_model_text,
            '',
            'Dados do artigo:',
            'Título gerado: ' . $generated_title,
            'Quantidade identificada no título gerado: ' . $title_h2_count,
            'Título da fonte: ' . $source_title,
            'Focus keyword: ' . $generated_focus_keyword,
            'Meta description: ' . $generated_meta_description,
            'Excerpt: ' . $generated_excerpt,
            '',
            'HTML atual:',
            $article['content_html'],
        ));

        $repaired = Alpha_RSS_AI_Generator::request_openai_json($generator, $prompt, array(
            'stage' => 'outline_repair',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => $source_title,
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'allow_missing_content_html' => 0,
        ));

        if (is_wp_error($repaired)) {
            return $article;
        }

        if (!empty($repaired['content_html'])) {
            $repaired_h2_count = self::count_heading_level_in_html($repaired['content_html'], 2);
            if ($repaired_h2_count === $target_h2_count) {
                $article['content_html'] = $repaired['content_html'];
            }
        }

        return $article;
    }

    public static function call_openai($generator, $item)
    {
        $outline_base_context = self::build_outline_context_base($generator);
        $seo_prompt = self::build_prompt($generator, $item, $outline_base_context);
        $seo_article = Alpha_RSS_AI_Generator::request_openai_json($generator, $seo_prompt, array(
            'stage' => 'seo',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($item['source_title']) ? $item['source_title'] : '',
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'excerpt_length' => !empty($item['excerpt']) ? strlen((string) $item['excerpt']) : 0,
            'content_length' => !empty($item['content']) ? strlen((string) $item['content']) : 0,
            'source_context_enriched' => !empty($item['source_context_enriched']) ? 1 : 0,
            'allow_missing_content_html' => 1,
        ));
        if (is_wp_error($seo_article)) {
            return $seo_article;
        }

        $outline_context = self::build_outline_context_from_source($generator, $item, $seo_article, $outline_base_context);
        $content_prompt = self::build_content_prompt($generator, $item, $seo_article, $outline_context);
        $content_article = Alpha_RSS_AI_Generator::request_openai_json($generator, $content_prompt, array(
            'stage' => 'content',
            'item_guid' => !empty($item['guid']) ? $item['guid'] : '',
            'item_title' => !empty($item['source_title']) ? $item['source_title'] : '',
            'source_type' => !empty($generator['source_type']) ? $generator['source_type'] : 'rss',
            'excerpt_length' => !empty($item['excerpt']) ? strlen((string) $item['excerpt']) : 0,
            'content_length' => !empty($item['content']) ? strlen((string) $item['content']) : 0,
            'source_context_enriched' => !empty($item['source_context_enriched']) ? 1 : 0,
        ));
        if (is_wp_error($content_article)) {
            return $content_article;
        }

        $seo_article['content_html'] = !empty($content_article['content_html']) ? $content_article['content_html'] : (isset($seo_article['content_html']) ? $seo_article['content_html'] : '');
        if (!empty($seo_article['content_html'])) {
            $seo_article['content_html'] = self::strip_generated_image_markup_from_html($seo_article['content_html']);
        }
        if (empty($seo_article['excerpt']) && !empty($content_article['excerpt'])) {
            $seo_article['excerpt'] = $content_article['excerpt'];
        }
        if (!empty($outline_context) && is_array($outline_context)) {
            $seo_article['outline_context'] = $outline_context;
            $seo_article['outline_text'] = !empty($outline_context['outline_text']) ? $outline_context['outline_text'] : '';
            $seo_article['outline_sections'] = !empty($outline_context['outline_sections']) ? $outline_context['outline_sections'] : array();
            $seo_article['outline_target_h2_min'] = !empty($outline_context['outline_target_h2_min']) ? intval($outline_context['outline_target_h2_min']) : 0;
            $seo_article['outline_target_h2_max'] = !empty($outline_context['outline_target_h2_max']) ? intval($outline_context['outline_target_h2_max']) : 0;
            $seo_article['outline_target_h2_count'] = !empty($outline_context['outline_target_h2_count']) ? intval($outline_context['outline_target_h2_count']) : 0;
            $seo_article['outline_block_quantities'] = !empty($outline_context['outline_block_quantities']) ? $outline_context['outline_block_quantities'] : array();
        }

        return $seo_article;
    }

    public static function normalize_related_posts_settings($generator)
    {
        $position = isset($generator['related_posts_position']) ? sanitize_key((string) $generator['related_posts_position']) : 'end';
        if (!in_array($position, array('end', 'paragraphs', 'words'), true)) {
            $position = 'end';
        }

        $style = isset($generator['related_posts_style']) ? sanitize_key((string) $generator['related_posts_style']) : 'list';
        if (!in_array($style, array('inline', 'list', 'cards'), true)) {
            $style = 'list';
        }

        return array(
            'enabled' => !empty($generator['related_posts_enabled']),
            'position' => $position,
            'interval' => max(1, intval(isset($generator['related_posts_interval']) ? $generator['related_posts_interval'] : 4)),
            'min_h2' => max(0, intval(isset($generator['related_posts_min_h2']) ? $generator['related_posts_min_h2'] : 1)),
            'links_per_block' => max(1, intval(isset($generator['related_posts_links_per_block']) ? $generator['related_posts_links_per_block'] : 2)),
            'same_category_only' => !empty($generator['related_posts_same_category_only']),
            'allow_fallback' => !empty($generator['related_posts_allow_fallback']),
            'style' => $style,
            'phrases' => self::parse_related_posts_phrases(isset($generator['related_posts_phrases']) ? $generator['related_posts_phrases'] : ''),
        );
    }

    public static function parse_related_posts_phrases($phrases)
    {
        if (is_array($phrases)) {
            $phrases = implode("\n", $phrases);
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) $phrases);
        $items = array();
        foreach ((array) $lines as $line) {
            $line = sanitize_text_field(trim((string) $line));
            if ($line !== '') {
                $items[] = $line;
            }
        }

        $items = array_values(array_unique($items));
        if (empty($items)) {
            $items = preg_split('/\r\n|\r|\n/', Alpha_RSS_AI_Generator::get_default_related_posts_phrases());
            $items = array_values(array_filter(array_map('sanitize_text_field', (array) $items)));
        }

        return $items;
    }

    protected static function get_related_posts_block_html($post, $style)
    {
        if (!($post instanceof WP_Post)) {
            return '';
        }

        $title = trim((string) get_the_title($post));
        $url = esc_url(get_permalink($post));
        if ($title === '' || $url === '') {
            return '';
        }

        $excerpt = self::get_related_post_excerpt_text($post, 16);

        $title_html = '<a class="arc-related-posts__link" href="' . $url . '">' . esc_html($title) . '</a>';

        if ($style === 'cards') {
            $card_html = '<a class="arc-related-posts__card" href="' . $url . '">';
            $card_html .= '<span class="arc-related-posts__card-title">' . esc_html($title) . '</span>';
            if ($excerpt !== '') {
                $card_html .= '<span class="arc-related-posts__card-excerpt">' . esc_html($excerpt) . '</span>';
            }
            $card_html .= '</a>';
            return $card_html;
        }

        return $title_html;
    }

    protected static function get_related_post_excerpt_text($post, $word_limit = 16)
    {
        if (!($post instanceof WP_Post)) {
            return '';
        }

        $excerpt = trim((string) get_post_field('post_excerpt', $post->ID, 'raw'));
        if ($excerpt === '') {
            $content = trim((string) get_post_field('post_content', $post->ID, 'raw'));
            if ($content !== '') {
                $content = strip_shortcodes($content);
                $content = wp_strip_all_tags($content);
                $excerpt = trim($content);
            }
        }

        if ($excerpt === '') {
            return '';
        }

        if ($word_limit > 0) {
            $excerpt = wp_trim_words($excerpt, $word_limit);
        }

        return $excerpt;
    }

    public static function build_related_posts_markup($post_id, $generator, $related_posts = array())
    {
        $settings = self::normalize_related_posts_settings($generator);
        $related_posts = array_values(array_filter($related_posts, function ($post) {
            return $post instanceof WP_Post;
        }));
        if (empty($related_posts)) {
            return '';
        }

        $phrases = !empty($settings['phrases']) ? $settings['phrases'] : array('Você também pode gostar de:');
        $phrase = $phrases[array_rand($phrases)];
        $style = $settings['style'];

        $html = '<div class="arc-related-posts arc-related-posts--' . esc_attr($style) . '">';
        $html .= '<div class="arc-related-posts__phrase"><strong class="arc-related-posts__phrase-text">' . esc_html($phrase) . '</strong></div>';

        if ($style === 'inline') {
            $links = array();
            foreach ($related_posts as $post) {
                $item_html = self::get_related_posts_block_html($post, $style);
                if ($item_html !== '') {
                    $links[] = $item_html;
                }
            }
            if (empty($links)) {
                return '';
            }
            $html .= '<div class="arc-related-posts__inline-links">' . implode('<span class="arc-related-posts__separator">•</span>', $links) . '</div>';
        } elseif ($style === 'cards') {
            $cards = array();
            foreach ($related_posts as $post) {
                $card_html = self::get_related_posts_block_html($post, $style);
                if ($card_html !== '') {
                    $cards[] = $card_html;
                }
            }
            if (empty($cards)) {
                return '';
            }
            $html .= '<div class="arc-related-posts__cards">' . implode('', $cards) . '</div>';
        } else {
            $items = array();
            foreach ($related_posts as $post) {
                $item_html = self::get_related_posts_block_html($post, $style);
                if ($item_html !== '') {
                    $items[] = '<li class="arc-related-posts__item">' . $item_html . '</li>';
                }
            }
            if (empty($items)) {
                return '';
            }
            $html .= '<ul class="arc-related-posts__list">' . implode('', $items) . '</ul>';
        }

        $html .= '</div>';
        return $html;
    }

    public static function build_content_plan_markup($links = array(), $content_model_type = 'pillar', $phrase = 'Você também pode gostar de:')
    {
        $links = is_array($links) ? array_values($links) : array();
        $content_model_type = sanitize_key((string) $content_model_type);
        if ($content_model_type === '') {
            $content_model_type = 'pillar';
        }

        $items = array();
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = !empty($link['url']) ? esc_url((string) $link['url']) : '';
            if ($url === '') {
                continue;
            }

            $label = '';
            if (!empty($link['anchor_phrase'])) {
                $label = self::normalize_plain_text((string) $link['anchor_phrase']);
            }
            if ($label === '' && !empty($link['title'])) {
                $label = self::normalize_plain_text((string) $link['title']);
            }
            if ($label === '') {
                $label = $url;
            }

            $items[] = '<li class="arc-content-plan__item"><a class="arc-content-plan__link" href="' . $url . '" target="_blank" rel="noopener noreferrer" data-arc-content-plan-link="1">' . esc_html($label) . '</a></li>';
        }

        if (empty($items)) {
            return '';
        }

        $phrase = trim((string) $phrase);
        if ($phrase === '') {
            $phrase = 'Você também pode gostar de:';
        }

        $html = '<div class="arc-content-plan-links arc-content-plan-links--' . esc_attr($content_model_type) . '" data-arc-content-plan="1">';
        $html .= '<p class="arc-content-plan__phrase"><strong>' . esc_html($phrase) . '</strong></p>';
        $html .= '<ul class="arc-content-plan__list">' . implode('', $items) . '</ul>';
        $html .= '</div>';

        return $html;
    }

    public static function inject_content_plan_links_into_content($content, $links, $content_model_type = 'pillar', $phrase = 'Você também pode gostar de:')
    {
        $content = trim((string) $content);
        $links = is_array($links) ? array_values($links) : array();

        if ($content === '' || empty($links)) {
            return $content;
        }

        if (strpos($content, 'arc-content-plan') !== false) {
            return $content;
        }

        $injected = false;
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = !empty($link['url']) ? esc_url((string) $link['url']) : '';
            if ($url === '') {
                continue;
            }

            $needle = '';
            if (!empty($link['anchor_phrase'])) {
                $needle = self::normalize_plain_text((string) $link['anchor_phrase']);
            }
            if ($needle === '' && !empty($link['title'])) {
                $needle = self::normalize_plain_text((string) $link['title']);
            }
            if ($needle === '') {
                continue;
            }

            $replacement = '<a class="arc-content-plan__link" href="' . $url . '" target="_blank" rel="noopener noreferrer" data-arc-content-plan-link="1">' . esc_html($needle) . '</a>';
            $pattern = '/' . preg_quote($needle, '/') . '/u';
            $count = 0;
            $updated_content = preg_replace($pattern, $replacement, $content, 1, $count);
            if ($count > 0 && is_string($updated_content) && $updated_content !== '') {
                $content = $updated_content;
                $injected = true;
            }
        }

        if ($injected) {
            return $content;
        }

        $markup = self::build_content_plan_markup($links, $content_model_type, $phrase);
        if ($markup === '') {
            return $content;
        }

        return $content === '' ? $markup : $content . "\n\n" . $markup;
    }

    protected static function extract_block_html($block)
    {
        if (!is_array($block)) {
            return '';
        }
        if (!empty($block['innerHTML'])) {
            return (string) $block['innerHTML'];
        }
        if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
            return implode('', $block['innerContent']);
        }
        if (function_exists('serialize_block')) {
            return (string) serialize_block($block);
        }
        return '';
    }

    protected static function collect_related_posts_insertion_indices($blocks, $settings)
    {
        $blocks = is_array($blocks) ? $blocks : array();
        $position = isset($settings['position']) ? $settings['position'] : 'end';
        $min_h2 = isset($settings['min_h2']) ? max(0, intval($settings['min_h2'])) : 1;
        $interval = isset($settings['interval']) ? max(1, intval($settings['interval'])) : 4;
        $indices = array();
        $last_paragraph_index = -1;

        if ($position === 'end') {
            if (!empty($blocks)) {
                $indices[] = count($blocks) - 1;
            }
            return $indices;
        }

        $paragraph_count = 0;
        $word_count = 0;
        $heading_count = 0;

        foreach ($blocks as $index => $block) {
            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            if ($block_name === 'core/heading') {
                $level = 2;
                if (isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                if ($level === 2) {
                    $heading_count++;
                }
            }

            if ($heading_count < $min_h2) {
                continue;
            }

            if ($position === 'paragraphs' && $block_name === 'core/paragraph') {
                $paragraph_count++;
                $last_paragraph_index = $index;
                if ($paragraph_count > 0 && ($paragraph_count % $interval) === 0) {
                    $indices[] = $index;
                }
                continue;
            }

            if ($position === 'words') {
                $block_html = self::extract_block_html($block);
                $plain_text = trim(wp_strip_all_tags(html_entity_decode((string) $block_html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'))));
                if ($plain_text === '') {
                    continue;
                }
                $word_count += str_word_count($plain_text);
                if ($word_count >= $interval) {
                    $indices[] = $index;
                    $word_count = 0;
                }
            }
        }

        if ($position === 'paragraphs' && $last_paragraph_index >= 0) {
            $indices[] = $last_paragraph_index;
        }

        if (empty($indices) && !empty($blocks)) {
            $indices[] = count($blocks) - 1;
        }

        return array_values(array_unique(array_filter(array_map('intval', $indices), function ($value) use ($blocks) {
            return $value >= 0 && $value < count($blocks);
        })));
    }

        // phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in, WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- related posts intentionally exclude the current post and constrain by category.
        public static function get_related_posts_candidates($post_id, $generator, $needed_total = 0)
        {
        $post_id = intval($post_id);
        $needed_total = max(1, intval($needed_total));
        $settings = self::normalize_related_posts_settings($generator);
        $post_type = !empty($generator['post_type']) && post_type_exists($generator['post_type']) ? $generator['post_type'] : get_post_type($post_id);
        if (!$post_type) {
            $post_type = 'post';
        }

        $category_ids = array();
        if (taxonomy_exists('category')) {
            $terms = wp_get_post_terms($post_id, 'category', array('fields' => 'ids'));
            if (!is_wp_error($terms) && is_array($terms)) {
                $category_ids = array_values(array_filter(array_map('intval', $terms)));
            }
        }

        $collect = static function ($posts, array &$results, array &$seen, $post_id, $needed_total) {
            foreach ((array) $posts as $post) {
                if (!($post instanceof WP_Post)) {
                    continue;
                }
                $candidate_id = intval($post->ID);
                if ($candidate_id <= 0 || $candidate_id === $post_id || isset($seen[$candidate_id])) {
                    continue;
                }
                $seen[$candidate_id] = true;
                $results[] = $post;
                if (count($results) >= $needed_total) {
                    return true;
                }
            }
            return false;
        };

        $results = array();
        $seen = array();
        $query_limit = max(12, $needed_total * 4);

        if (!empty($category_ids)) {
            $same_category_args = array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => $query_limit,
                'orderby' => 'rand',
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'post__not_in' => array($post_id),
                'tax_query' => array(
                    array(
                        'taxonomy' => 'category',
                        'field' => 'term_id',
                        'terms' => $category_ids,
                        'operator' => 'IN',
                    ),
                ),
            );
            $same_category_posts = get_posts($same_category_args);
            if ($collect($same_category_posts, $results, $seen, $post_id, $needed_total)) {
                return array_slice($results, 0, $needed_total);
            }
        }

        if (!empty($results)) {
            if ($settings['same_category_only']) {
                return array_slice($results, 0, $needed_total);
            }
            if (count($results) >= $needed_total) {
                return array_slice($results, 0, $needed_total);
            }
        }

        if (!$settings['allow_fallback'] && empty($results)) {
            return array();
        }

        if ($settings['allow_fallback']) {
            $fallback_args = array(
                'post_type' => $post_type,
                'post_status' => 'publish',
                'posts_per_page' => $query_limit,
                'orderby' => 'rand',
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'post__not_in' => array($post_id),
            );
            $fallback_posts = get_posts($fallback_args);
            $collect($fallback_posts, $results, $seen, $post_id, $needed_total);
        }

            return array_slice($results, 0, $needed_total);
        }
        // phpcs:enable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in, WordPress.DB.SlowDBQuery.slow_db_query_tax_query

        public static function inject_related_posts_into_content($content, $post_id, $generator)
    {
        $settings = self::normalize_related_posts_settings($generator);
        if (empty($settings['enabled'])) {
            return $content;
        }

        $content = trim((string) $content);
        if ($content === '' || strpos($content, 'arc-related-posts') !== false) {
            return $content;
        }

        if (!function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $content;
        }

        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return $content;
        }

        $insertion_indices = self::collect_related_posts_insertion_indices($blocks, $settings);
        if (empty($insertion_indices)) {
            return $content;
        }

        $related_posts = self::get_related_posts_candidates($post_id, $generator, count($insertion_indices) * $settings['links_per_block']);
        if (empty($related_posts)) {
            return $content;
        }

        $result_blocks = array();
        $candidate_offset = 0;
        $insertion_lookup = array_fill_keys($insertion_indices, true);

        foreach ($blocks as $index => $block) {
            $result_blocks[] = $block;
            if (!isset($insertion_lookup[$index])) {
                continue;
            }

            $slice = array_slice($related_posts, $candidate_offset, $settings['links_per_block']);
            if (empty($slice)) {
                continue;
            }

            $html = self::build_related_posts_markup($post_id, $generator, $slice);
            if ($html === '') {
                continue;
            }

            $result_blocks[] = array(
                'blockName' => 'core/html',
                'attrs' => array(),
                'innerBlocks' => array(),
                'innerContent' => array($html),
            );
            $candidate_offset += count($slice);
        }

        if ($candidate_offset === 0) {
            return $content;
        }

        return serialize_blocks($result_blocks);
    }
}
