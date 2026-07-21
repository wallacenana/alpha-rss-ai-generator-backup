<?php

if (!defined('ABSPATH')) {
    exit;
}

class Alpha_RSS_AI_Generator_Helper
{
    public static function fetch_source_page_html_result($url, $cache_ttl = 5, $log_prefix = 'page_context')
    {
        $url = esc_url_raw(trim((string) $url));
        if ($url === '') {
            return array(
                'html' => '',
                'status_code' => 0,
                'blocked' => false,
                'error_code' => '',
                'error_message' => '',
            );
        }

        $cache_ttl = max(1, intval($cache_ttl));
        $cache_key = 'arc_source_html_' . md5($url);
        $day_cache_key = 'arc_source_html_day_' . md5($url);
        $blocked_key = 'arc_source_html_blocked_' . md5($url);

        $blocked_until = get_transient($blocked_key);
        if (!empty($blocked_until) && intval($blocked_until) > time()) {
            return array(
                'html' => '',
                'status_code' => 403,
                'blocked' => true,
                'error_code' => 'arc_source_forbidden',
                'error_message' => 'A fonte retornou 403 e o acesso ficou bloqueado temporariamente.',
            );
        }

        $cached_day_html = get_transient($day_cache_key);
        if (is_string($cached_day_html) && $cached_day_html !== '') {
            return array(
                'html' => $cached_day_html,
                'status_code' => 200,
                'blocked' => false,
                'error_code' => '',
                'error_message' => '',
            );
        }

        $cached_html = get_transient($cache_key);
        if (is_string($cached_html) && $cached_html !== '') {
            return array(
                'html' => $cached_html,
                'status_code' => 200,
                'blocked' => false,
                'error_code' => '',
                'error_message' => '',
            );
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
            return array(
                'html' => '',
                'status_code' => 0,
                'blocked' => false,
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
            );
        }

        if ($code < 200 || $code >= 300) {
            if ($code === 403) {
                set_transient($blocked_key, time() + 300, 300);
            }
            return array(
                'html' => '',
                'status_code' => $code,
                'blocked' => $code === 403,
                'error_code' => $code === 403 ? 'arc_source_forbidden' : 'arc_source_http_error',
                'error_message' => $code === 403 ? 'A fonte retornou 403 e o acesso ficou bloqueado temporariamente.' : 'A fonte retornou um status HTTP inesperado.',
            );
        }

        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return array(
                'html' => '',
                'status_code' => $code,
                'blocked' => false,
                'error_code' => 'arc_source_empty',
                'error_message' => 'A fonte respondeu sem conteÃºdo.',
            );
        }

        set_transient($cache_key, $html, $cache_ttl);
        set_transient($day_cache_key, $html, DAY_IN_SECONDS);

        return array(
            'html' => $html,
            'status_code' => $code,
            'blocked' => false,
            'error_code' => '',
            'error_message' => '',
        );
    }

    public static function fetch_source_page_html($url, $cache_ttl = 5, $log_prefix = 'page_context')
    {
        $result = self::fetch_source_page_html_result($url, $cache_ttl, $log_prefix);
        return is_array($result) && isset($result['html']) ? (string) $result['html'] : '';
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

    public static function strip_source_page_noise_from_html($html, $content_selector = '')
    {
        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previous_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="arc-source-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        if (!$loaded) {
            $patterns = array(
                '~<!--.*?-->~is',
                '~<head\b[^>]*>.*?</head>~is',
                '~<script\b[^>]*>.*?</script>~is',
                '~<style\b[^>]*>.*?</style>~is',
                '~<header\b[^>]*>.*?</header>~is',
                '~<footer\b[^>]*>.*?</footer>~is',
                '~<nav\b[^>]*>.*?</nav>~is',
                '~<aside\b[^>]*>.*?</aside>~is',
                '~<meta\b[^>]*>~is',
                '~<link\b[^>]*>~is',
            );

            return trim(preg_replace($patterns, '', $html));
        }

        $xpath = new DOMXPath($dom);
        $remove_queries = array(
            '//comment()',
            '//head',
            '//script',
            '//style',
            '//header',
            '//footer',
            '//nav',
            '//aside',
            '//meta',
            '//link',
        );
        foreach ($remove_queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            $to_remove = array();
            foreach ($nodes as $node) {
                if ($node instanceof DOMNode) {
                    $to_remove[] = $node;
                }
            }

            foreach (array_reverse($to_remove) as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $root = $dom->getElementById('arc-source-root');
        if (!$root) {
            return trim($dom->saveHTML());
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    public static function normalize_prompt_context_html($html, $content_selector = '')
    {
        $html = self::strip_source_page_noise_from_html($html, $content_selector);
        $html = preg_replace('~\s+~u', ' ', (string) $html);
        $html = trim((string) $html);
        return $html;
    }

    public static function ensure_content_starts_with_paragraph_html($content)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return '';
        }

        if (strpos($content, '<!-- wp:') !== false && function_exists('parse_blocks') && function_exists('serialize_blocks')) {
            $blocks = parse_blocks($content);
            if (empty($blocks)) {
                return $content;
            }

            $paragraph_index = -1;
            foreach ($blocks as $index => $block) {
                if (!empty($block['blockName']) && $block['blockName'] === 'core/paragraph') {
                    $paragraph_index = intval($index);
                    break;
                }
            }

            if ($paragraph_index <= 0) {
                return $content;
            }

            $paragraph_block = $blocks[$paragraph_index];
            array_splice($blocks, $paragraph_index, 1);
            array_unshift($blocks, $paragraph_block);

            return trim(serialize_blocks($blocks));
        }

        $previous_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = @$dom->loadHTML('<?xml encoding="UTF-8"><div id="arc-content-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        if (!$loaded) {
            return $content;
        }

        $root = $dom->getElementById('arc-content-root');
        if (!$root || !$root->hasChildNodes()) {
            return '';
        }

        $paragraph_node = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower((string) $child->nodeName) === 'p') {
                $paragraph_node = $child;
                break;
            }
        }

        if (!$paragraph_node) {
            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }

            return trim($output);
        }

        if ($root->firstChild === $paragraph_node) {
            $output = '';
            foreach ($root->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }

            return trim($output);
        }

        $root->insertBefore($paragraph_node, $root->firstChild);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return trim($output);
    }

    public static function normalize_plain_text($text)
    {
        return self::clean_source_text($text);
    }

    public static function limit_plain_text_words($text, $max_words = 120)
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

    public static function build_xpath_content_selector_queries($selector)
    {
        $selector = trim(preg_replace('/\s+/', ' ', (string) $selector));
        if ($selector === '') {
            return array();
        }

        $parts = preg_split('/\s*,\s*/', $selector);
        if (empty($parts)) {
            $parts = array($selector);
        }

        $queries = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }

            if ($part[0] === '#') {
                $id_value = trim(substr($part, 1));
                $id_value = preg_replace('/[\"\']+/', '', $id_value);
                if ($id_value !== '') {
                    $queries[] = '//*[@id="' . $id_value . '"]';
                }
                continue;
            }

            if ($part[0] === '.') {
                $class_value = trim(substr($part, 1));
                $class_value = preg_replace('/[\"\']+/', '', $class_value);
                if ($class_value !== '') {
                    $class_condition = self::build_xpath_class_condition($class_value);
                    if ($class_condition !== '') {
                        $queries[] = '//*[' . $class_condition . ']';
                    }
                }
                continue;
            }

            $raw_value = preg_replace('/[\"\']+/', '', $part);
            if ($raw_value === '') {
                continue;
            }

            $queries[] = '//*[@id="' . $raw_value . '"]';
            $class_condition = self::build_xpath_class_condition($raw_value);
            if ($class_condition !== '') {
                $queries[] = '//*[' . $class_condition . ']';
            }
        }

        return array_values(array_unique($queries));
    }

    public static function extract_text_from_html_using_selector($html, $selector)
    {
        $html = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        $selector = trim((string) $selector);
        if ($html === '' || $selector === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $queries = self::build_xpath_content_selector_queries($selector);
        if (empty($queries)) {
            return '';
        }

        $best = '';
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
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
            if ($candidate !== '' && strlen($candidate) > strlen($best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    public static function extract_html_from_html_using_selector($html, $selector)
    {
        $html = self::strip_source_page_noise_from_html($html, $selector);
        $selector = trim((string) $selector);
        if ($html === '' || $selector === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $queries = self::build_xpath_content_selector_queries($selector);
        if (empty($queries)) {
            return '';
        }

        $best_html = '';
        $best_length = 0;
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            for ($i = 0; $i < min(2, $nodes->length); $i++) {
                $node = $nodes->item($i);
                if (!($node instanceof DOMNode)) {
                    continue;
                }

                $candidate = $dom->saveHTML($node);
                $candidate = is_string($candidate) ? trim($candidate) : '';
                if ($candidate === '') {
                    continue;
                }

                $candidate_length = strlen($candidate);
                if ($candidate_length > $best_length) {
                    $best_length = $candidate_length;
                    $best_html = $candidate;
                }
            }
        }

        return $best_html;
    }

    public static function extract_html_from_html_with_fallbacks($html, $selector = '')
    {
        $html = self::strip_source_page_noise_from_html($html, $selector);
        if ($html === '') {
            return '';
        }

        $selector = trim((string) $selector);
        if ($selector !== '') {
            $selected_html = self::extract_html_from_html_using_selector($html, $selector);
            if ($selected_html !== '') {
                return $selected_html;
            }
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $html)) {
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

        $best_html = '';
        $best_length = 0;
        foreach ($selectors as $scope_selector) {
            $nodes = $xpath->query($scope_selector);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            for ($i = 0; $i < min(2, $nodes->length); $i++) {
                $node = $nodes->item($i);
                if (!($node instanceof DOMNode)) {
                    continue;
                }

                $candidate = $dom->saveHTML($node);
                $candidate = is_string($candidate) ? trim($candidate) : '';
                if ($candidate === '') {
                    continue;
                }

                $candidate_length = strlen($candidate);
                if ($candidate_length > $best_length) {
                    $best_length = $candidate_length;
                    $best_html = $candidate;
                }
            }
        }

        if ($best_html !== '') {
            return $best_html;
        }

        return $html;
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
        $html = self::strip_source_page_noise_from_html($html);
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

        $image_selector_class = trim((string) $image_selector_class);
        if ($image_selector_class !== '') {
            $selector_candidate = self::extract_selector_media_candidate_from_html($html, $base_url, $image_selector_class, 'image');
            if (!empty($selector_candidate['image_url'])) {
                $media['image_url'] = !empty($selector_candidate['image_url']) ? $selector_candidate['image_url'] : '';
                $media['image_source'] = !empty($selector_candidate['image_source']) ? $selector_candidate['image_source'] : 'selector:' . $image_selector_class;
                $media['image_class'] = !empty($selector_candidate['image_class']) ? $selector_candidate['image_class'] : $image_selector_class;
                $media['image_attr'] = !empty($selector_candidate['image_attr']) ? $selector_candidate['image_attr'] : '';
                $media['image_tag'] = !empty($selector_candidate['image_tag']) ? $selector_candidate['image_tag'] : 'selector';
            }
        }

        if ($media['image_url'] === '' && $image_selector_class === '') {
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
        }

        if ($link_selector_class !== '') {
            $link_candidate = self::extract_selector_media_candidate_from_html($html, $base_url, $link_selector_class, 'link');
            if (!empty($link_candidate['link_url'])) {
                $media['link_url'] = $link_candidate['link_url'];
                $media['link_text'] = !empty($link_candidate['link_text']) ? $link_candidate['link_text'] : '';
                $media['link_source'] = !empty($link_candidate['link_source']) ? $link_candidate['link_source'] : '';
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

        $html = self::strip_source_page_noise_from_html(self::fetch_source_page_html($url, 5, 'source_page_media'));
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

    public static function extract_page_outline_from_html($html, $base_url = '', $max_sections = 6, $max_links_per_section = 5, $max_images_per_section = 3, $image_selector_class = '', $link_selector_class = '', $content_selector = '')
    {
        $html = self::strip_source_page_noise_from_html($html, $content_selector);
        if ($html === '') {
            return array();
        }

        $content_selector = trim((string) $content_selector);
        if ($content_selector !== '') {
            $selected_html = self::extract_html_from_html_with_fallbacks($html, $content_selector);
            if ($selected_html !== '') {
                $html = $selected_html;
            }
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
        $h3_nodes = $xpath->query('//h3');
        $h2_count = ($h2_nodes && $h2_nodes->length > 0) ? $h2_nodes->length : 0;
        $h3_count = ($h3_nodes && $h3_nodes->length > 0) ? $h3_nodes->length : 0;
        if ($h2_count === 0 && $h3_count === 0) {
            return array();
        }

        $use_h3 = $h3_count >= $h2_count && $h3_count > 0;
        $heading_nodes = $use_h3 ? $h3_nodes : $h2_nodes;
        if (!$heading_nodes || $heading_nodes->length === 0) {
            $heading_nodes = $use_h3 ? $h2_nodes : $h3_nodes;
        }

        $outline = array();
        for ($i = 0; $i < $heading_nodes->length && count($outline) < $max_sections; $i++) {
            $heading = $heading_nodes->item($i);
            if (!($heading instanceof DOMElement)) {
                continue;
            }

            $title = self::clean_source_text($heading->textContent);
            if ($title === '') {
                continue;
            }

            $heading_tag = strtolower((string) $heading->nodeName);
            $heading_level = in_array($heading_tag, array('h2', 'h3'), true) ? intval(substr($heading_tag, 1)) : 2;
            $section_links = array();
            $section_images = array();
            $seen_links = array();
            $seen_images = array();
            $section_text_parts = array();

            $cursor = $heading->nextSibling;
            while ($cursor) {
                if ($cursor->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower((string) $cursor->nodeName);
                    if ($tag === 'h1' || $tag === 'h2' || $tag === 'h3') {
                        $cursor_level = intval(substr($tag, 1));
                        if ($cursor_level <= $heading_level) {
                            break;
                        }
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

            $outline_item = array(
                'h2' => $title,
                'heading_level' => $heading_level,
                'text' => trim(mb_substr(implode(' ', array_slice($section_text_parts, 0, 4)), 0, 600)),
                'image_selector_class' => $image_selector_class,
                'link_selector_class' => $link_selector_class,
                'links' => $section_links,
                'images' => $section_images,
            );
            $outline[] = $outline_item;
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

        if ($max_images > 0 && count($images) < $max_images && ($image_selector_class === '' || $image_scope_active || $matches_image_selector)) {
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

            $heading_level = isset($section['heading_level']) ? intval($section['heading_level']) : 2;
            $heading_label = 'H' . ($heading_level > 0 ? $heading_level : 2);
            $lines[] = $heading_label . ' ' . ($section_index + 1) . ': ' . $title;

            $section_text = isset($section['text']) ? self::clean_source_text($section['text']) : '';
            if ($section_text !== '') {
                $lines[] = 'Texto: ' . wp_trim_words($section_text, 30);
            }

            $image_selector_class = isset($section['image_selector_class']) ? self::clean_source_text($section['image_selector_class']) : '';

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
            $pattern = '~' . preg_quote($rating_label, '~') . '\s*(?:[:\-\â€“]?\s*)?(\d{1,2}(?:[.,]\d+)?)\s*(?:/\s*10)?~i';
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

    public static function normalize_tavily_image_candidates($context)
    {
        $context = is_array($context) ? $context : array();
        $candidates = array();
        $seen = array();

        $append_candidate = function ($candidate, $source_label = '') use (&$candidates, &$seen) {
            if (is_string($candidate)) {
                $candidate = array('url' => $candidate);
            }
            if (!is_array($candidate)) {
                return;
            }

            $url = '';
            foreach (array('url', 'image_url', 'source_url', 'thumbnail_url', 'src', 'link') as $key) {
                if (!empty($candidate[$key])) {
                    $url = trim((string) $candidate[$key]);
                    break;
                }
            }
            $url = esc_url_raw($url);
            if ($url === '' || !Alpha_RSS_AI_Generator::url_looks_like_image($url)) {
                return;
            }
            if (isset($seen[$url])) {
                return;
            }

            $seen[$url] = true;
            $description = '';
            foreach (array('description', 'alt', 'text', 'summary', 'content') as $key) {
                if (!empty($candidate[$key])) {
                    $description = self::normalize_plain_text((string) $candidate[$key]);
                    if ($description !== '') {
                        break;
                    }
                }
            }

            $candidates[] = array(
                'url' => $url,
                'title' => !empty($candidate['title']) ? self::normalize_plain_text((string) $candidate['title']) : '',
                'description' => $description,
                'score' => isset($candidate['score']) ? floatval($candidate['score']) : (isset($candidate['relevance_score']) ? floatval($candidate['relevance_score']) : 0),
                'source' => $source_label !== '' ? sanitize_key((string) $source_label) : (!empty($candidate['source']) ? sanitize_key((string) $candidate['source']) : ''),
            );
        };

        $append_candidates = function ($list, $source_label = '') use (&$append_candidate) {
            if (!is_array($list)) {
                return;
            }
            foreach ($list as $candidate) {
                $append_candidate($candidate, $source_label);
            }
        };

        if (!empty($context['images']) && is_array($context['images'])) {
            $append_candidates($context['images'], 'tavily');
        }

        if (!empty($context['results']) && is_array($context['results'])) {
            foreach ($context['results'] as $result) {
                if (!is_array($result)) {
                    continue;
                }
                if (!empty($result['images']) && is_array($result['images'])) {
                    $append_candidates($result['images'], 'tavily_result');
                }
            }
        }

        $looks_like_candidate_list = !empty($context) && array_keys($context) === range(0, count($context) - 1);
        if ($looks_like_candidate_list) {
            $append_candidates($context, 'tavily');
        }

        return $candidates;
    }

    public static function fetch_tavily_search_context($query, $max_results = 3, $include_answer = true, $include_images = true)
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
            'include_answer' => !empty($include_answer),
            'include_images' => !empty($include_images),
            'include_image_descriptions' => !empty($include_images),
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

                $normalized_result = array(
                    'title' => !empty($result['title']) ? self::normalize_plain_text((string) $result['title']) : '',
                    'url' => !empty($result['url']) ? esc_url_raw((string) $result['url']) : '',
                    'content' => !empty($result['content']) ? self::normalize_plain_text((string) $result['content']) : '',
                    'score' => isset($result['score']) ? floatval($result['score']) : 0.0,
                );
                if (!empty($result['images']) && is_array($result['images'])) {
                    $normalized_result['images'] = self::normalize_tavily_image_candidates(array('images' => $result['images']));
                }
                $results[] = $normalized_result;
            }
        }

        $context = array(
            'query' => $query,
            'answer' => !empty($raw['answer']) ? self::normalize_plain_text((string) $raw['answer']) : '',
            'results' => $results,
        );
        if (!empty($include_images)) {
            $context['images'] = self::normalize_tavily_image_candidates($raw);
        }

        return $context;
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

    public static function build_outline_section_image_html($section, $post_id = 0, $image_size = 'medium', $existing_image_map = array(), $section_index = -1, $fallback_image_candidates = array())
    {
        if (!is_array($section)) {
            return '';
        }

        $section_title = isset($section['h2']) ? self::clean_source_text($section['h2']) : '';
        $post_id = intval($post_id);
        $image_size = Alpha_RSS_AI_Generator::normalize_image_display_size($image_size);
        $existing_image_map = is_array($existing_image_map) ? $existing_image_map : array();
        $existing_attachment_id = self::find_existing_outline_section_image_attachment_id($section_title, $existing_image_map);

        if ($existing_attachment_id > 0) {
            $image_html = Alpha_RSS_AI_Generator::build_attachment_image_figure_html($existing_attachment_id, $image_size, $section_title, 'alignnone');
            if ($image_html !== '') {
                return $image_html;
            }
        }

        $images = isset($section['images']) && is_array($section['images']) ? array_values($section['images']) : array();
        if (empty($images) && !empty($fallback_image_candidates) && is_array($fallback_image_candidates)) {
            $fallback_image_candidates = array_values(array_filter($fallback_image_candidates, function ($candidate) {
                if (is_string($candidate)) {
                    $candidate = array('url' => $candidate);
                }
                return is_array($candidate) && !empty($candidate['url']);
            }));
            if (!empty($fallback_image_candidates)) {
                if ($section_index >= 0 && count($fallback_image_candidates) > 1) {
                    $offset = $section_index % count($fallback_image_candidates);
                    if ($offset > 0) {
                        $fallback_image_candidates = array_merge(
                            array_slice($fallback_image_candidates, $offset),
                            array_slice($fallback_image_candidates, 0, $offset)
                        );
                    }
                }
                $images = $fallback_image_candidates;
            }
        }
        if (!empty($images)) {
            foreach ($images as $image_index => $image) {
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
            }
        }

        return '';
    }

    public static function outline_section_has_existing_image($section, $existing_image_map = array(), $section_index = -1)
    {
        if (!is_array($section)) {
            return false;
        }

        $existing_image_map = is_array($existing_image_map) ? $existing_image_map : array();
        if (empty($existing_image_map)) {
            return false;
        }

        $section_title = isset($section['h2']) ? self::clean_source_text($section['h2']) : '';
        if ($section_title === '') {
            return false;
        }

        $existing_attachment_id = self::find_existing_outline_section_image_attachment_id($section_title, $existing_image_map);
        if ($existing_attachment_id > 0) {
            return true;
        }

        return false;
    }

    public static function extract_first_outline_section_image_url($outline_sections)
    {
        if (!is_array($outline_sections) || empty($outline_sections)) {
            return '';
        }

        foreach ($outline_sections as $section) {
            if (!is_array($section) || empty($section['images']) || !is_array($section['images'])) {
                continue;
            }

            foreach ($section['images'] as $image) {
                if (!is_array($image) || empty($image['url'])) {
                    continue;
                }

                $image_url = trim((string) $image['url']);
                if ($image_url !== '' && !Alpha_RSS_AI_Generator::is_probably_bad_featured_image_url($image_url, 'outline')) {
                    return $image_url;
                }
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

        foreach ($links as $link_index => $link) {
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

    protected static function normalize_outline_section_match_text($text)
    {
        $text = self::normalize_prompt_context_text($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*/u', '', $text);
        $text = preg_replace('/\s*\((?:19|20)\d{2}(?:\s*[\-â€“]\s*(?:19|20)\d{2})?\)\s*$/u', '', $text);
        $text = remove_accents($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    protected static function score_outline_section_title_match($needle, $haystack)
    {
        $needle = self::normalize_outline_section_match_text($needle);
        $haystack = self::normalize_outline_section_match_text($haystack);
        if ($needle === '' || $haystack === '') {
            return 0;
        }

        if ($needle === $haystack) {
            return 100;
        }

        $score = 0;
        if (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false || mb_stripos($needle, $haystack, 0, 'UTF-8') !== false) {
            $score += 40;
        }

        $similarity = 0;
        similar_text($needle, $haystack, $similarity);
        $score += min(45, (int) round($similarity / 2));

        $needle_tokens = array_values(array_filter(preg_split('/\s+/', $needle)));
        $haystack_tokens = array_values(array_filter(preg_split('/\s+/', $haystack)));
        if (!empty($needle_tokens) && !empty($haystack_tokens)) {
            $common_tokens = array_intersect($needle_tokens, $haystack_tokens);
            $score += min(15, count($common_tokens) * 5);
        }

        return min(100, $score);
    }

    protected static function normalize_outline_title_match_text($text)
    {
        $text = self::extract_outline_core_title_text($text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*/u', '', $text);
        $text = preg_replace('/\s*\((?=[^)]*(?:netflix|dispon|available|country|countries|pais|paÃ­s))[^)]*\)\s*$/iu', '', $text);
        $text = preg_replace('/\s*[\-â€“â€”]\s*(?=[^\-â€“â€”]*?(?:netflix|dispon|available|country|countries|pais|paÃ­s)).*$/iu', '', $text);
        $text = preg_replace('/\s*\([^)]*(?:19|20)\d{2}[^)]*\)\s*$/u', '', $text);
        $text = preg_replace('/\s*-\s*(?:19|20)\d{2}\s*$/u', '', $text);
        $text = preg_replace('/\s*â€“\s*(?:19|20)\d{2}\s*$/u', '', $text);
        $text = remove_accents($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $text);
    }

    protected static function extract_outline_core_title_text($text)
    {
        $original_text = self::normalize_prompt_context_text($text);
        if ($original_text === '') {
            return '';
        }

        $text = $original_text;

        if (preg_match('/[\'"](.+?)[\'"]/u', $text, $matches)) {
            $text = trim((string) $matches[1]);
        } elseif (preg_match('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*(.+?)\s*(?:\(|$)/u', $text, $matches)) {
            $text = trim((string) $matches[1]);
        }

        if ($text === '') {
            $text = $original_text;
        }

        $text = preg_replace('/^\s*\d{1,3}\s*[\.\)\-:\/]*\s*/u', '', $text);
        $text = preg_replace('/\s*\([^)]*\)\s*$/u', '', $text);
        $text = trim((string) $text);

        if ($text === '') {
            return $original_text;
        }

        return $text;
    }

    protected static function score_outline_title_match($needle, $haystack)
    {
        $needle = self::normalize_outline_title_match_text($needle);
        $haystack = self::normalize_outline_title_match_text($haystack);
        if ($needle === '' || $haystack === '') {
            return 0;
        }

        if ($needle === $haystack) {
            return 100;
        }

        $score = 0;
        if (mb_stripos($haystack, $needle, 0, 'UTF-8') !== false || mb_stripos($needle, $haystack, 0, 'UTF-8') !== false) {
            $score += 55;
        }

        $similarity = 0;
        similar_text($needle, $haystack, $similarity);
        $score += min(30, (int) round($similarity / 2));

        $needle_tokens = array_values(array_filter(preg_split('/\s+/', $needle)));
        $haystack_tokens = array_values(array_filter(preg_split('/\s+/', $haystack)));
        if (!empty($needle_tokens) && !empty($haystack_tokens)) {
            $common_tokens = array_intersect($needle_tokens, $haystack_tokens);
            $score += min(20, count($common_tokens) * 6);
        }

        return min(100, $score);
    }

    protected static function build_outline_section_semantic_text($section)
    {
        if (!is_array($section)) {
            return '';
        }

        $parts = array();
        if (!empty($section['h2'])) {
            $parts[] = self::clean_source_text($section['h2']);
        } elseif (!empty($section['title'])) {
            $parts[] = self::clean_source_text($section['title']);
        }
        if (!empty($section['text'])) {
            $parts[] = self::clean_source_text($section['text']);
        }

        $semantic_text = trim(implode(' ', array_filter($parts)));
        if ($semantic_text === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($semantic_text, 0, 700, 'UTF-8');
        }

        return substr($semantic_text, 0, 700);
    }

    protected static function find_best_outline_section_semantic_match($title, $outline_sections, $exclude_indexes = array())
    {
        if (!class_exists('Alpha_RSS_AI_Generator')) {
            return null;
        }

        $title = self::normalize_prompt_context_text($title);
        if ($title === '') {
            return null;
        }

        $settings = Alpha_RSS_AI_Generator::get_settings();
        if (empty($settings['semantic_dedup_enabled'])) {
            return null;
        }

        $threshold = isset($settings['semantic_dedup_threshold']) ? max(0.0, min(0.82, floatval($settings['semantic_dedup_threshold']))) : 0.72;

        $exclude_lookup = array();
        foreach ((array) $exclude_indexes as $exclude_index) {
            $exclude_lookup[intval($exclude_index)] = true;
        }

        $candidates = array();
        foreach (array_values((array) $outline_sections) as $index => $section) {
            if (isset($exclude_lookup[$index]) || !is_array($section)) {
                continue;
            }

            $semantic_text = self::build_outline_section_semantic_text($section);
            if ($semantic_text === '') {
                continue;
            }

            $candidates[] = array(
                'index' => $index,
                'section' => $section,
                'semantic_text' => $semantic_text,
            );
        }

        if (empty($candidates)) {
            return null;
        }

        $best_score = 0.0;
        $best_candidate = null;

        foreach ($candidates as $candidate) {
            $semantic_score = Alpha_RSS_AI_Generator::calculate_semantic_title_fallback_score($title, $candidate['semantic_text']);
            if ($semantic_score > $best_score) {
                $best_score = $semantic_score;
                $best_candidate = $candidate;
            }
        }

        if ($best_candidate === null || $best_score < $threshold) {
            return null;
        }

        return array(
            'index' => intval($best_candidate['index']),
            'score' => $best_score,
            'section' => $best_candidate['section'],
            'mode' => 'text',
        );
    }

    protected static function find_existing_outline_section_image_attachment_id($section_title, $existing_image_map = array())
    {
        if (!class_exists('Alpha_RSS_AI_Generator')) {
            return 0;
        }

        $section_title = self::normalize_prompt_context_text($section_title);
        if ($section_title === '' || !is_array($existing_image_map) || empty($existing_image_map)) {
            return 0;
        }

        $normalized_section_title = self::normalize_outline_section_match_text($section_title);
        foreach ($existing_image_map as $existing_key => $existing_value) {
            $existing_key_normalized = self::normalize_outline_section_match_text((string) $existing_key);
            if ($existing_key_normalized !== '' && $existing_key_normalized === $normalized_section_title && intval($existing_value) > 0) {
                return intval($existing_value);
            }
        }

        return 0;
    }

    protected static function build_outline_section_match_candidates($outline_sections, $exclude_indexes = array())
    {
        $candidates = array();
        if (!is_array($outline_sections) || empty($outline_sections)) {
            return $candidates;
        }

        $exclude_lookup = array();
        foreach ((array) $exclude_indexes as $exclude_index) {
            $exclude_lookup[intval($exclude_index)] = true;
        }

        foreach (array_values($outline_sections) as $index => $section) {
            if (isset($exclude_lookup[$index])) {
                continue;
            }
            if (!is_array($section)) {
                continue;
            }

            $candidate_title = '';
            if (!empty($section['h2'])) {
                $candidate_title = self::clean_source_text($section['h2']);
            } elseif (!empty($section['title'])) {
                $candidate_title = self::clean_source_text($section['title']);
            }

            $candidate_text = self::source_context_section_haystack($section);
            if ($candidate_title === '' && $candidate_text === '') {
                continue;
            }

            $candidates[] = array(
                'index' => $index,
                'title' => $candidate_title,
                'text' => $candidate_text,
            );
        }

        return $candidates;
    }

    protected static function choose_outline_section_match_via_ai($title, $outline_sections, $exclude_indexes = array(), $generator = array(), $context = array())
    {
        if (!class_exists('Alpha_RSS_AI_Generator')) {
            return null;
        }

        $candidates = self::build_outline_section_match_candidates($outline_sections, $exclude_indexes);
        if (empty($candidates)) {
            return null;
        }

        $prompt_lines = array(
            'Voce deve escolher a melhor secao de origem para um titulo editorial gerado.',
            'O titulo pode ter numeracao, anos, intervalos de anos e frases extras de disponibilidade.',
            'Ignore ruido editorial. Encontre o melhor mapeamento sem exigir igualdade exata.',
            'Retorne apenas JSON valido com: matched_index, confidence, reason.',
            'Se nao houver correspondencia confiavel, use matched_index = -1.',
            'Titulo gerado: ' . self::normalize_prompt_context_text($title),
            'Candidatos:'
        );

        foreach ($candidates as $candidate) {
            $snippet = isset($candidate['text']) ? (string) $candidate['text'] : '';
            if (function_exists('mb_substr')) {
                $snippet = mb_substr($snippet, 0, 240, 'UTF-8');
            } else {
                $snippet = substr($snippet, 0, 240);
            }
            $prompt_lines[] = '- index=' . intval($candidate['index']) . ' | title=' . self::normalize_prompt_context_text($candidate['title']) . ' | text=' . self::normalize_prompt_context_text($snippet);
        }

        $prompt = implode("\n", $prompt_lines);
        $response = Alpha_RSS_AI_Generator::request_openai_json($generator, $prompt, array(
            'stage' => 'outline_media_match',
            'post_id' => !empty($context['post_id']) ? intval($context['post_id']) : 0,
            'item_guid' => !empty($context['item_guid']) ? (string) $context['item_guid'] : '',
            'allow_missing_content_html' => 1,
            'preserve_extra_fields' => 1,
        ));

        if (is_wp_error($response) || !is_array($response)) {
            return null;
        }

        $matched_index = isset($response['matched_index']) ? intval($response['matched_index']) : (isset($response['index']) ? intval($response['index']) : -1);
        if ($matched_index < 0) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (intval($candidate['index']) !== $matched_index) {
                continue;
            }

            return array(
                'index' => $matched_index,
                'score' => isset($response['confidence']) ? intval($response['confidence']) : 80,
                'section' => $outline_sections[$matched_index],
                'mode' => 'ai',
            );
        }

        return null;
    }

    protected static function extract_block_heading_text($block)
    {
        if (!is_array($block)) {
            return '';
        }

        $text = '';
        if (!empty($block['innerHTML'])) {
            $text = self::clean_source_text($block['innerHTML']);
        }
        if ($text === '' && !empty($block['innerContent']) && is_array($block['innerContent'])) {
            $text = self::clean_source_text(implode('', array_map('strval', $block['innerContent'])));
        }

        return self::normalize_prompt_context_text($text);
    }

    protected static function find_best_outline_section_match($outline_sections, $title, $exclude_indexes = array(), $generator = array(), $context = array())
    {
        if (!is_array($outline_sections) || empty($outline_sections)) {
            return array(
                'index' => -1,
                'score' => 0,
                'section' => null,
            );
        }

        $raw_title = self::normalize_prompt_context_text($title);
        $title = self::normalize_outline_title_match_text($title);
        if ($title === '') {
            return array(
                'index' => -1,
                'score' => 0,
                'section' => null,
            );
        }

        foreach (array_values($outline_sections) as $exact_index => $exact_section) {
            if (!is_array($exact_section)) {
                continue;
            }

            $exact_parts = array();
            if (!empty($exact_section['h2'])) {
                $exact_parts[] = self::clean_source_text($exact_section['h2']);
            }
            if (!empty($exact_section['title'])) {
                $exact_parts[] = self::clean_source_text($exact_section['title']);
            }

            $exact_candidate_title = self::normalize_outline_title_match_text(implode(' ', array_filter($exact_parts)));
            if ($exact_candidate_title !== '' && $exact_candidate_title === $title) {
                return array(
                    'index' => $exact_index,
                    'score' => 100,
                    'section' => $exact_section,
                    'mode' => 'exact',
                );
            }
        }

        $exclude_lookup = array();
        foreach ((array) $exclude_indexes as $exclude_index) {
            $exclude_lookup[intval($exclude_index)] = true;
        }

        $best = array(
            'index' => -1,
            'score' => 0,
            'section' => null,
        );

        foreach (array_values($outline_sections) as $index => $section) {
            if (isset($exclude_lookup[$index])) {
                continue;
            }
            if (!is_array($section)) {
                continue;
            }

            $candidate_parts = array();
            if (!empty($section['h2'])) {
                $candidate_parts[] = self::clean_source_text($section['h2']);
            }
            if (!empty($section['title'])) {
                $candidate_parts[] = self::clean_source_text($section['title']);
            }

            $candidate_title = self::normalize_outline_title_match_text(implode(' ', array_filter($candidate_parts)));
            $candidate_haystack = self::source_context_section_haystack($section);
            if ($candidate_title === '' && $candidate_haystack === '') {
                continue;
            }

            $score = 0;
            if ($candidate_title !== '') {
                if ($title === $candidate_title) {
                    $score = 100;
                } elseif (mb_stripos($candidate_title, $title, 0, 'UTF-8') !== false || mb_stripos($title, $candidate_title, 0, 'UTF-8') !== false) {
                    $score = 95;
                }
            }
            if ($score > $best['score']) {
                $best = array(
                    'index' => $index,
                    'score' => $score,
                    'section' => $section,
                );
            }
        }
        return $best;
    }

    protected static function find_next_unused_outline_section_index($outline_sections, $exclude_indexes = array())
    {
        if (!is_array($outline_sections) || empty($outline_sections)) {
            return -1;
        }

        $exclude_lookup = array();
        foreach ((array) $exclude_indexes as $exclude_index) {
            $exclude_lookup[intval($exclude_index)] = true;
        }

        foreach (array_values($outline_sections) as $index => $section) {
            if (isset($exclude_lookup[$index])) {
                continue;
            }
            if (!is_array($section)) {
                continue;
            }
            return $index;
        }

        return -1;
    }

    public static function build_outline_section_media_html($section, $post_id = 0, $image_size = 'medium', $link_phrases = array(), $use_images = true, $use_links = true, $existing_image_map = array(), $section_index = -1)
    {
        $html_parts = array();
        $section_title = is_array($section) && !empty($section['h2']) ? self::clean_source_text($section['h2']) : '';
        if (!empty($use_images)) {
            $image_html = self::build_outline_section_image_html($section, $post_id, $image_size, $existing_image_map, $section_index, array());
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

    public static function inject_outline_section_media_into_content($content, $outline_sections, $post_id = 0, $image_size = 'medium', $link_phrases = array(), $use_images = true, $use_links = true, $generator = array(), $context = array(), $existing_image_map = array(), $fallback_image_candidates = array())
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

        $outline_section_count = count($outline_sections);
        $outline_count_hint = !empty($context['outline_target_h2_count_hint']) ? intval($context['outline_target_h2_count_hint']) : 0;
        $allow_sequence_fallback = $outline_section_count > 0 && $outline_section_count <= 10 && ($outline_count_hint <= 0 || $outline_count_hint <= 10);

        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return $content;
        }

        $result_blocks = array();
        $section_index = -1;
        $pending_link_html = '';
        $inserted_any = false;
        $used_section_indexes = array();

        foreach ($blocks as $block_index => $block) {
            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            $is_heading_level_2 = false;
            $is_heading_level_3 = false;
            if ($block_name === 'core/heading') {
                $level = 2;
                if (is_array($block) && isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                $is_heading_level_2 = ($level === 2);
                $is_heading_level_3 = ($level === 3);
            }

            if ($is_heading_level_2 || $is_heading_level_3) {
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
                $heading_title = self::extract_block_heading_text($block);
                $matched_section = null;
                $matched_index = -1;
                $match_mode = 'exact';
                $match_score = 0;

                if ($heading_title !== '') {
                    $match = self::find_best_outline_section_match($outline_sections, $heading_title, $used_section_indexes, $generator, $context);
                    $match_score = !empty($match['score']) ? intval($match['score']) : 0;
                    if (!empty($match['section']) && $match_score >= 100) {
                        $matched_section = $match['section'];
                        $matched_index = intval($match['index']);
                        if (!empty($match['mode'])) {
                            $match_mode = sanitize_key((string) $match['mode']);
                        }
                    }
                }

                if ($matched_section === null && $allow_sequence_fallback) {
                    if (isset($outline_sections[$section_index]) && is_array($outline_sections[$section_index]) && !in_array($section_index, $used_section_indexes, true)) {
                        $matched_section = $outline_sections[$section_index];
                        $matched_index = $section_index;
                        $match_mode = 'sequence';
                        $match_score = 0;
                    } else {
                        $fallback_index = self::find_next_unused_outline_section_index($outline_sections, $used_section_indexes);
                        if ($fallback_index >= 0 && isset($outline_sections[$fallback_index]) && is_array($outline_sections[$fallback_index])) {
                            $matched_section = $outline_sections[$fallback_index];
                            $matched_index = intval($fallback_index);
                            $match_mode = 'sequence';
                            $match_score = 0;
                        }
                    }
                }

                if ($matched_section !== null && $matched_index >= 0) {
                    if (!in_array($matched_index, $used_section_indexes, true)) {
                        $used_section_indexes[] = $matched_index;
                    }
                    $section_has_markup_image = self::outline_section_contains_image_markup($blocks, $block_index);
                    if ($use_images && $is_heading_level_2 && !$section_has_markup_image && !self::outline_section_has_existing_image($matched_section, $existing_image_map, $matched_index)) {
                        $section_image_html = self::build_outline_section_image_html($matched_section, $post_id, $image_size, $existing_image_map, $matched_index, $fallback_image_candidates);
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
                    $pending_link_html = $use_links ? self::build_outline_section_link_html($matched_section, $link_phrases) : '';
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

    public static function outline_section_contains_image_markup($blocks, $start_index)
    {
        $blocks = is_array($blocks) ? array_values($blocks) : array();
        $start_index = max(0, intval($start_index));

        if (empty($blocks) || !isset($blocks[$start_index])) {
            return false;
        }

        for ($index = $start_index + 1; $index < count($blocks); $index++) {
            $block = $blocks[$index];
            if (!is_array($block)) {
                continue;
            }

            $block_name = !empty($block['blockName']) ? (string) $block['blockName'] : '';
            if ($block_name === 'core/heading') {
                $level = 2;
                if (isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                if ($level === 2 || $level === 3) {
                    break;
                }
            }

            if (self::block_contains_image_markup($block)) {
                return true;
            }
        }

        return false;
    }

    public static function block_contains_image_markup($block)
    {
        if (!is_array($block)) {
            return false;
        }

        $block_name = !empty($block['blockName']) ? (string) $block['blockName'] : '';
        if (in_array($block_name, array('core/image', 'core/gallery', 'core/media-text', 'core/cover'), true)) {
            return true;
        }

        $html = '';
        if (!empty($block['innerHTML'])) {
            $html = (string) $block['innerHTML'];
        } elseif (!empty($block['innerContent']) && is_array($block['innerContent'])) {
            $html = implode('', array_map('strval', $block['innerContent']));
        }

        if ($html === '') {
            return false;
        }

        return (bool) preg_match('~<img\b|wp-block-image|wp-image-\d+|<figure\b[^>]*class=["\'][^"\']*\bwp-block-image\b~i', $html);
    }

    public static function extract_outline_section_image_map_from_content($content)
    {
        $content = trim((string) $content);
        if ($content === '' || !function_exists('parse_blocks')) {
            return array();
        }

        $blocks = parse_blocks($content);
        if (empty($blocks) || !is_array($blocks)) {
            return array();
        }

        $map = array();
        $current_section_title = '';

        foreach ($blocks as $block) {
            $block_name = is_array($block) && !empty($block['blockName']) ? (string) $block['blockName'] : '';
            if ($block_name === 'core/heading') {
                $level = 2;
                if (is_array($block) && isset($block['attrs']['level'])) {
                    $level = intval($block['attrs']['level']);
                }
                if ($level === 2 || $level === 3) {
                    $current_section_title = '';
                    if (!empty($block['innerHTML'])) {
                        $current_section_title = self::clean_source_text($block['innerHTML']);
                    } elseif (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                        $current_section_title = self::clean_source_text(implode('', array_map('strval', $block['innerContent'])));
                    }
                } else {
                    $current_section_title = '';
                }
                continue;
            }

            if ($current_section_title === '') {
                continue;
            }

            $html = '';
            if (is_array($block) && !empty($block['innerHTML'])) {
                $html = (string) $block['innerHTML'];
            } elseif (is_array($block) && !empty($block['innerContent']) && is_array($block['innerContent'])) {
                $html = implode('', array_map('strval', $block['innerContent']));
            }

            if ($html === '') {
                continue;
            }

            if (!preg_match('/wp-image-(\d+)/', $html, $matches)) {
                continue;
            }

            $attachment_id = intval($matches[1]);
            $normalized_section_title = self::normalize_outline_section_match_text($current_section_title);
            if ($attachment_id > 0 && $normalized_section_title !== '' && !isset($map[$normalized_section_title])) {
                $map[$normalized_section_title] = $attachment_id;
            }
        }

        return $map;
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

        $source_title = '';
        foreach (array('source_title', 'title', 'feed_title', 'source_page_title') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_title = self::normalize_plain_text((string) $item[$candidate_key]);
                break;
            }
        }
        $source_url = isset($item['source_url']) ? trim((string) $item['source_url']) : '';
        if ($source_url === '') {
            $source_url = isset($item['permalink']) ? trim((string) $item['permalink']) : '';
        }
        $source_excerpt = '';
        if (!empty($item['source_page_excerpt'])) {
            $source_excerpt = self::normalize_plain_text((string) $item['source_page_excerpt']);
        } elseif (!empty($item['excerpt'])) {
            $source_excerpt = self::normalize_plain_text((string) $item['excerpt']);
        }
        $source_page_content_html = isset($item['source_page_content_html']) ? self::normalize_prompt_context_html((string) $item['source_page_content_html']) : '';
        $source_page_outline_titles = self::build_source_outline_titles_for_prompt($item, 10);
        $generation_language = !empty($generator['generation_language'])
            ? Alpha_RSS_AI_Generator::normalize_generation_language_value($generator['generation_language'])
            : Alpha_RSS_AI_Generator::get_default_generation_language();

        if ($source_title !== '') {
            $lines[] = 'Titulo da fonte: ' . $source_title;
        }
        if ($source_url !== '') {
            $lines[] = 'URL de origem: ' . $source_url;
        }
        if ($source_excerpt !== '') {
            $lines[] = 'Resumo da fonte: ' . self::limit_plain_text_words($source_excerpt, 100);
        }
        if ($source_page_outline_titles !== '') {
            $lines[] = 'Estrutura da pagina de origem:';
            $lines[] = $source_page_outline_titles;
        }
        if ($source_page_content_html !== '') {
            $lines[] = 'Conteudo em HTML limpo da pagina de origem: ' . $source_page_content_html;
        }
        if ($generation_language !== '') {
            $lines[] = 'Idioma final: ' . $generation_language;
        }

        return implode("\n", $lines);
    }

    public static function build_source_outline_titles_for_prompt($item, $max_items = 10)
    {
        $item = is_array($item) ? $item : array();
        $max_items = max(1, intval($max_items));

        $titles = array();
        if (!empty($item['source_page_outline_sections']) && is_array($item['source_page_outline_sections'])) {
            foreach ($item['source_page_outline_sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }

                $title = '';
                if (!empty($section['h2'])) {
                    $title = self::normalize_prompt_context_text($section['h2']);
                } elseif (!empty($section['title'])) {
                    $title = self::normalize_prompt_context_text($section['title']);
                }

                if ($title !== '') {
                    $titles[] = $title;
                }

                if (count($titles) >= $max_items) {
                    break;
                }
            }
        }

        if (empty($titles) && !empty($item['source_page_outline'])) {
            $outline_text = (string) $item['source_page_outline'];
            $lines = preg_split('/\R/u', $outline_text);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim(wp_strip_all_tags((string) $line));
                    if ($line === '') {
                        continue;
                    }

                    if (preg_match('/^H\d+\s+\d+\s*:\s*(.+)$/i', $line, $matches)) {
                        $title = trim((string) $matches[1]);
                        if ($title !== '') {
                            $titles[] = self::normalize_prompt_context_text($title);
                        }
                    } elseif (preg_match('/^\d+\s*[\.\)\-:]\s*(.+)$/u', $line, $matches)) {
                        $title = trim((string) $matches[1]);
                        if ($title !== '') {
                            $titles[] = self::normalize_prompt_context_text($title);
                        }
                    }

                    if (count($titles) >= $max_items) {
                        break;
                    }
                }
            }
        }

        if (empty($titles)) {
            return '';
        }

        $formatted = array();
        foreach ($titles as $index => $title) {
            $formatted[] = sprintf('%02d. %s', $index + 1, $title);
        }

        return implode("\n", $formatted);
    }

    public static function build_outline_context_base($generator)
    {
        $generator = is_array($generator) ? $generator : array();
        $content_length_class = !empty($generator['content_length_class']) ? Alpha_RSS_AI_Generator::normalize_content_length_class($generator['content_length_class']) : Alpha_RSS_AI_Generator::get_default_content_length_class();
        $content_length_range = Alpha_RSS_AI_Generator::get_content_length_range($content_length_class);
        $outline_model = Alpha_RSS_AI_Generator::get_generator_outline_model($generator);
        $outline_model_hint_key = !empty($generator['outline_model_key']) ? sanitize_key((string) $generator['outline_model_key']) : '';
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
            'outline_model_hint_key' => $outline_model_hint_key,
            'outline_target_h2_min' => !empty($outline_model['target_h2_min']) ? intval($outline_model['target_h2_min']) : 0,
            'outline_target_h2_max' => !empty($outline_model['target_h2_max']) ? intval($outline_model['target_h2_max']) : 0,
            'outline_target_h2_count' => !empty($outline_model['target_h2_count']) ? intval($outline_model['target_h2_count']) : 0,
        );
    }
    public static function infer_outline_model_key_from_source_context($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $generator = is_array($generator) ? $generator : array();
        $item = is_array($item) ? $item : array();
        $seo_article = is_array($seo_article) ? $seo_article : array();
        $outline_context = is_array($outline_context) ? $outline_context : array();

        $source_bits = array();
        foreach (array('source_page_content_html', 'source_page_html', 'source_page_content', 'content_html', 'content', 'source_page_excerpt', 'excerpt', 'source_page_outline', 'source_page_outline_sections') as $key) {
            if (!empty($item[$key])) {
                $source_bits[] = is_string($item[$key]) ? (string) $item[$key] : wp_json_encode($item[$key]);
            }
        }

        $source_page_outline_titles = self::build_source_outline_titles_for_prompt($item, 10);
        if ($source_page_outline_titles !== '') {
            $source_bits[] = $source_page_outline_titles;
        }
        if (!empty($seo_article['excerpt'])) {
            $source_bits[] = (string) $seo_article['excerpt'];
        }

        $source_blob = strtolower(implode("\n\n", $source_bits));
        $source_text = self::normalize_prompt_context_text($source_blob);
        $combined = strtolower(trim($source_text));
        $content_length = strlen($source_text);

        $has_guide_markers = (bool) preg_match('/\b(?:como|guia|tutorial|passo a passo|dicas|aprenda|entenda|saiba|por que|melhor(es)?|manual)\b/i', $combined);
        $has_news_markers = (bool) preg_match('/\b(?:revela|anuncia|confirma|chega|estreia|lan[a?]a|pol[e?]mica|esc[a?]ndalo|investiga[c?][a?]o|morte|pris[a?]o|denuncia|caso|trailer|nova?\s+temporada|this\s+week|coming\s+soon|what(?:[\'’]s)\s+coming|coming\s+to\s+netflix|new\s+on\s+netflix)\b/ui', $combined);

        $outline_title_count = 0;
        if ($source_page_outline_titles !== '') {
            $outline_title_lines = preg_split('/\R/u', $source_page_outline_titles);
            if (is_array($outline_title_lines)) {
                foreach ($outline_title_lines as $outline_title_line) {
                    if (trim((string) $outline_title_line) !== '') {
                        $outline_title_count++;
                    }
                }
            }
        }

        $has_list_structure = $outline_title_count >= 3
            || (bool) preg_match('/<li\b|<\/li>/i', $source_blob)
            || (bool) preg_match('/^\s*(?:[-*?]|\d+[.)])\s+/m', $source_blob)
            || (bool) preg_match('/\b(?:lista|ranking|top\s+\d+|melhores|best\s+\d+|sele[c?][a?]o|recomenda[c?][a?]o)\b/ui', $combined);

        if ($has_guide_markers) {
            return 'guide_long';
        }

        if ($has_list_structure) {
            return 'list_article';
        }

        if ($has_news_markers) {
            return 'news_short';
        }

        return $content_length <= 2600 ? 'news_short' : 'guide_long';
    }

    public static function format_outline_analysis_for_prompt($outline_context)
    {
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $lines = array();
        $content_type = !empty($outline_context['content_type']) ? sanitize_text_field((string) $outline_context['content_type']) : '';
        $funnel_level = !empty($outline_context['funnel_level']) ? sanitize_text_field((string) $outline_context['funnel_level']) : '';
        $tone = !empty($outline_context['tone']) ? sanitize_text_field((string) $outline_context['tone']) : '';
        $primary_pain = !empty($outline_context['primary_pain']) ? sanitize_textarea_field((string) $outline_context['primary_pain']) : '';
        $focus_keyword = !empty($outline_context['focus_keyword']) ? sanitize_text_field((string) $outline_context['focus_keyword']) : '';
        $recommended_outline_model_key = !empty($outline_context['recommended_outline_model_key']) ? sanitize_key((string) $outline_context['recommended_outline_model_key']) : '';
        $recommended_prompt_model_key = !empty($outline_context['recommended_prompt_model_key']) ? sanitize_key((string) $outline_context['recommended_prompt_model_key']) : '';
        if ($content_type !== '') {
            $lines[] = 'Tipo de conteudo: ' . $content_type;
        }
        if ($funnel_level !== '') {
            $lines[] = 'Nivel de funil: ' . $funnel_level;
        }
        if ($tone !== '') {
            $lines[] = 'Tom: ' . $tone;
        }
        if ($primary_pain !== '') {
            $lines[] = 'Dor principal: ' . $primary_pain;
        }
        if ($focus_keyword !== '') {
            $lines[] = 'Keyword sugerida: ' . $focus_keyword;
        }
        if ($recommended_outline_model_key !== '') {
            $lines[] = 'Modelo recomendado: ' . $recommended_outline_model_key;
        }
        if ($recommended_prompt_model_key !== '') {
            $lines[] = 'Modelo de prompt recomendado: ' . $recommended_prompt_model_key;
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

        $source_title = isset($item['source_title']) ? self::normalize_prompt_context_text($item['source_title']) : '';
        $source_content_html = '';
        foreach (array('source_page_content_html', 'content_html', 'source_page_html') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_content_html = self::normalize_prompt_context_html(preg_replace('/<title[^>]*>.*?<\/title>/is', '', (string) $item[$candidate_key]));
                break;
            }
        }
        $available_prompt_models = Alpha_RSS_AI_Generator::get_prompt_models($generator);
        $available_prompt_model_keys = array();
        $available_prompt_models_text = array();
        foreach ($available_prompt_models as $available_prompt_model) {
            if (!is_array($available_prompt_model)) {
                continue;
            }
            if (!empty($available_prompt_model['key'])) {
                $available_prompt_model_keys[] = (string) $available_prompt_model['key'];
            }
            $available_prompt_models_text[] = Alpha_RSS_AI_Generator::format_prompt_model_for_prompt($available_prompt_model);
        }
        $available_prompt_models_text = implode("\n\n---\n\n", $available_prompt_models_text);
        $selected_tags = Alpha_RSS_AI_Generator::get_generator_selected_tags($generator);

        $prompt = array(
            'Voce e um planejador editorial interno.',
            'Analise o conteudo de referencia e escolha apenas 1 modelo de prompt da lista disponivel.',
            'Considere apenas a estrutura principal do conteudo. Ignore rodape, sidebar, widgets, listas de navegacao e blocos auxiliares.',
            'Retorne apenas JSON valido com estas chaves: content_type, funnel_level, tone, primary_pain, focus_keyword, recommended_prompt_model_key.',
            'Escolha recommended_prompt_model_key usando somente uma das chaves validas do modelo base abaixo.',
            'Fonte em HTML filtrado:',
            $source_content_html !== '' ? $source_content_html : '[sem html de referencia]',
            'Lista de modelos:',
            $available_prompt_models_text,
        );

        return implode("\n", $prompt);
    }

    public static function normalize_outline_analysis_context($analysis, $outline_context = array())
    {
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $analysis = is_array($analysis) ? $analysis : array();

        $outline_context['content_type'] = !empty($analysis['content_type']) ? sanitize_key((string) $analysis['content_type']) : (!empty($outline_context['content_type']) ? sanitize_key((string) $outline_context['content_type']) : 'article');
        $outline_context['funnel_level'] = !empty($analysis['funnel_level']) ? sanitize_key((string) $analysis['funnel_level']) : (!empty($outline_context['funnel_level']) ? sanitize_key((string) $outline_context['funnel_level']) : 'mid');
        $outline_context['tone'] = !empty($analysis['tone']) ? sanitize_text_field((string) $analysis['tone']) : (!empty($outline_context['tone']) ? sanitize_text_field((string) $outline_context['tone']) : '');
        $outline_context['primary_pain'] = !empty($analysis['primary_pain']) ? sanitize_textarea_field((string) $analysis['primary_pain']) : (!empty($outline_context['primary_pain']) ? sanitize_textarea_field((string) $outline_context['primary_pain']) : '');
        $outline_context['focus_keyword'] = !empty($analysis['focus_keyword']) ? sanitize_text_field((string) $analysis['focus_keyword']) : (!empty($outline_context['focus_keyword']) ? sanitize_text_field((string) $outline_context['focus_keyword']) : '');
        $outline_context['recommended_outline_model_key'] = !empty($analysis['recommended_outline_model_key']) ? sanitize_key((string) $analysis['recommended_outline_model_key']) : (!empty($outline_context['recommended_outline_model_key']) ? sanitize_key((string) $outline_context['recommended_outline_model_key']) : '');
        $outline_context['recommended_prompt_model_key'] = !empty($analysis['recommended_prompt_model_key']) ? sanitize_key((string) $analysis['recommended_prompt_model_key']) : (!empty($outline_context['recommended_prompt_model_key']) ? sanitize_key((string) $outline_context['recommended_prompt_model_key']) : '');
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
        $outline_model_hint_key = self::infer_outline_model_key_from_source_context($generator, $item, $seo_article, $outline_context);
        if (empty($outline_context['outline_model_hint_key'])) {
            $outline_context['outline_model_hint_key'] = $outline_model_hint_key;
        }
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

        if (!empty($outline_model_hint_key) && $outline_model_hint_key === 'news_short') {
            $outline_context['content_type'] = 'news_short';
            $outline_context['recommended_outline_model_key'] = 'news_short';
            $outline_context['recommended_prompt_model_key'] = 'noticia';
        }

        $prompt_models = Alpha_RSS_AI_Generator::get_prompt_models($generator);
        $available_prompt_model_keys = array();
        foreach ($prompt_models as $prompt_model) {
            if (!empty($prompt_model['key'])) {
                $available_prompt_model_keys[] = (string) $prompt_model['key'];
            }
        }

        if (!empty($outline_context['recommended_prompt_model_key']) && !empty($available_prompt_model_keys) && !in_array($outline_context['recommended_prompt_model_key'], $available_prompt_model_keys, true)) {
            $outline_context['recommended_prompt_model_key'] = '';
        }

        if (!empty($outline_context['recommended_prompt_model_key'])) {
            $prompt_model = Alpha_RSS_AI_Generator::get_prompt_model($outline_context['recommended_prompt_model_key'], $generator);
            if (!empty($prompt_model['outline_model_key'])) {
                $outline_context['recommended_outline_model_key'] = (string) $prompt_model['outline_model_key'];
            }
        } else {
            $candidate_outline_model_key = !empty($outline_context['recommended_outline_model_key']) ? sanitize_key((string) $outline_context['recommended_outline_model_key']) : $outline_model_hint_key;
            if ($candidate_outline_model_key === '') {
                $candidate_prompt_model_key = Alpha_RSS_AI_Generator::get_prompt_model_key_for_content_type(
                    !empty($outline_context['content_type']) ? $outline_context['content_type'] : '',
                    $outline_context,
                    $generator
                );
                if ($candidate_prompt_model_key !== '') {
                    $prompt_model = Alpha_RSS_AI_Generator::get_prompt_model($candidate_prompt_model_key, $generator);
                    if (!empty($prompt_model)) {
                        $outline_context['recommended_prompt_model_key'] = $candidate_prompt_model_key;
                        if (!empty($prompt_model['outline_model_key'])) {
                            $outline_context['recommended_outline_model_key'] = (string) $prompt_model['outline_model_key'];
                        }
                    }
                }
            } else {
                foreach ($prompt_models as $prompt_model) {
                    if (!is_array($prompt_model)) {
                        continue;
                    }
                    if (!empty($prompt_model['outline_model_key']) && $prompt_model['outline_model_key'] === $candidate_outline_model_key && !empty($prompt_model['key'])) {
                        $outline_context['recommended_prompt_model_key'] = (string) $prompt_model['key'];
                        break;
                    }
                }
            }
        }

        $outline_context = Alpha_RSS_AI_Generator::apply_outline_model_context($generator, $outline_context);
        return $outline_context;
    }
    public static function build_prompt($generator, $item, $outline_context = array())
    {
        $outline_context = is_array($outline_context) ? $outline_context : array();
        $prompt_model = Alpha_RSS_AI_Generator::get_generator_prompt_model($generator, $outline_context);
        $template = !empty($prompt_model['seo_prompt_template']) ? trim((string) $prompt_model['seo_prompt_template']) : trim((string) $generator['prompt_template']);
        $source_type = isset($generator['source_type']) ? sanitize_key($generator['source_type']) : 'rss';
        $keyword_list_mode = isset($generator['keyword_list_mode']) ? sanitize_key($generator['keyword_list_mode']) : Alpha_RSS_AI_Generator::get_default_keyword_list_mode();
        if ($template === '') {
            $template = Alpha_RSS_AI_Generator::normalize_prompt_template_for_source_type($source_type, $template, $keyword_list_mode);
        }
        if ($template === '') {
            $template = ($source_type === 'keyword_list' && $keyword_list_mode !== 'url_reference') ? Alpha_RSS_AI_Generator::get_default_keyword_prompt_template() : Alpha_RSS_AI_Generator::get_default_prompt_template();
        }

        $row_data = isset($item['row_data']) && is_array($item['row_data']) ? wp_json_encode($item['row_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        $source_title = '';
        $source_page_html_source = '';
        foreach (array('source_title', 'title', 'feed_title', 'source_page_title') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_title = self::normalize_plain_text((string) $item[$candidate_key]);
                break;
            }
        }
        $source_url = isset($item['source_url']) ? $item['source_url'] : '';
        if ($source_url === '' && isset($item['permalink'])) {
            $source_url = $item['permalink'];
        }
        $source_page_html = '';
        foreach (array('source_page_content_html', 'source_page_html', 'content_html') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_page_html_source = $candidate_key;
                $source_page_html = self::normalize_prompt_context_html(preg_replace('/<title[^>]*>.*?<\/title>/is', '', (string) $item[$candidate_key]));
                break;
            }
        }
        $source_excerpt_summary = '';
        if (!empty($item['source_page_excerpt'])) {
            $source_excerpt_summary = self::normalize_plain_text((string) $item['source_page_excerpt']);
        } elseif (!empty($item['excerpt'])) {
            $source_excerpt_summary = self::normalize_plain_text((string) $item['excerpt']);
        }
        $source_excerpt_summary = self::limit_plain_text_words($source_excerpt_summary, 100);

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

        error_log('[alpha-rss-ai-generator] content_generation_start | ' . wp_json_encode(array(
            'generator_id' => !empty($generator['id']) ? intval($generator['id']) : 0,
            'item_guid' => !empty($item['guid']) ? (string) $item['guid'] : '',
            'source_title' => $source_title,
            'source_page_html_source' => $source_page_html_source,
            'source_page_html_length' => strlen($source_page_html),
            'source_page_html_preview' => $source_page_html !== '' ? mb_substr($source_page_html, 0, 1200) : '',
            'source_excerpt_length' => strlen($source_excerpt_summary),
            'source_excerpt_preview' => $source_excerpt_summary !== '' ? mb_substr($source_excerpt_summary, 0, 500) : '',
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

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
            '{{source_excerpt}}' => $source_excerpt_summary,
            '{{source_content}}' => $source_excerpt_summary,
            '{{source_page_html}}' => $source_page_html,
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
            '{{prompt_model_name}}' => !empty($prompt_model['name']) ? $prompt_model['name'] : '',
            '{{prompt_model_key}}' => !empty($prompt_model['key']) ? $prompt_model['key'] : '',
            '{{prompt_model_outline_key}}' => !empty($prompt_model['outline_model_key']) ? $prompt_model['outline_model_key'] : '',
        );

        if (strpos($template, '{{selected_tags}}') !== false) {
            $template = preg_replace('/^.*\{\{selected_tags\}\}.*(?:\r?\n|$)/m', '', $template);
            $template = trim((string) $template);
        }

        $source_context_block = self::build_source_context_block($generator, $item);
        $prompt = strtr($template, $replacements);
        $prompt .= "\n\n";
        $prompt .= "\n\n" . Alpha_RSS_AI_Generator::get_prompt_output_suffix();
        $prompt .= trim($source_context_block);

        $prompt_preview = preg_replace('/\s+/', ' ', wp_strip_all_tags($prompt));
        $prompt_preview = function_exists('mb_substr') ? mb_substr($prompt_preview, 0, 1400) : substr($prompt_preview, 0, 1400);

        return $prompt;
    }

    public static function build_content_prompt($generator, $item, $seo_article = array(), $outline_context = array())
    {
        $prompt_model = Alpha_RSS_AI_Generator::get_generator_prompt_model($generator, $outline_context);
        $visible_template = !empty($prompt_model['content_prompt_template']) ? trim((string) $prompt_model['content_prompt_template']) : (isset($generator['content_prompt_template']) ? trim((string) $generator['content_prompt_template']) : '');
        if ($visible_template === '') {
            $visible_template = Alpha_RSS_AI_Generator::get_default_content_prompt_template_visible();
        }

        $source_title = isset($item['source_title']) ? $item['source_title'] : '';
        $source_url = isset($item['source_url']) ? $item['source_url'] : '';
        if ($source_url === '' && isset($item['permalink'])) {
            $source_url = $item['permalink'];
        }
        $source_page_html = '';
        foreach (array('source_page_content_html', 'source_page_html', 'content_html') as $candidate_key) {
            if (!empty($item[$candidate_key])) {
                $source_page_html = self::normalize_prompt_context_html(preg_replace('/<title[^>]*>.*?<\/title>/is', '', (string) $item[$candidate_key]));
                break;
            }
        }
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
        $prompt_model_name = !empty($prompt_model['name']) ? (string) $prompt_model['name'] : '';
        $prompt_model_key = !empty($prompt_model['key']) ? (string) $prompt_model['key'] : '';

        error_log('[alpha-rss-ai-generator] content_prompt_start | ' . wp_json_encode(array(
            'generator_id' => !empty($generator['id']) ? intval($generator['id']) : 0,
            'item_guid' => !empty($item['guid']) ? (string) $item['guid'] : '',
            'source_title' => $source_title,
            'source_page_html_length' => strlen($source_page_html),
            'source_page_html_preview' => $source_page_html !== '' ? mb_substr($source_page_html, 0, 1200) : '',
            'generated_title' => $generated_title,
            'generated_slug' => $generated_slug,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $hidden_context = array(
            'Contexto interno:',
            'TÃ­tulo gerado: {{generated_title}}',
            'kw: {{generated_focus_keyword}}',
            'Meta description: {{generated_meta_description}}',
            'MÃ­dias e CTAs da fonte: o sistema vai inserir a imagem baixada e o link localmente; n??o escreva imagens, figures ou CTAs manuais no texto.',

            'Slug final: {{generated_slug}}',
            'Modelo de prompt: {{prompt_model_name}}',
            'Chave do modelo de prompt: {{prompt_model_key}}',
            'Outline interno gerado pelo backend:',
            '{{outline_text}}',
            'Idioma final: {{generation_language}}',
            'ConteÃºdo HTML filtrado da fonte: {{source_content}}',
        );

        $template = $visible_template . "

" . implode("
", $hidden_context);

        $replacements = array(
            '{{feed_title}}' => $item['feed_title'],
            '{{source_title}}' => $source_title,
            '{{keyword}}' => isset($item['keyword']) ? $item['keyword'] : '',
            '{{source_url}}' => $source_url,
            '{{source_permalink}}' => $item['permalink'],
            '{{source_page_title}}' => isset($item['source_page_title']) ? $item['source_page_title'] : '',
            '{{source_page_excerpt}}' => isset($item['source_page_excerpt']) ? $item['source_page_excerpt'] : '',
            '{{source_page_content}}' => isset($item['source_page_content']) ? $item['source_page_content'] : '',
            '{{source_page_html}}' => $source_page_html,
            '{{source_page_outline}}' => isset($item['source_page_outline']) ? $item['source_page_outline'] : '',
            '{{source_excerpt}}' => $item['excerpt'],
            '{{source_content}}' => $source_page_html !== '' ? $source_page_html : $item['content'],
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
            '{{prompt_model_name}}' => $prompt_model_name,
            '{{prompt_model_key}}' => $prompt_model_key,
            '{{outline_model_text}}' => $outline_model_text,
            '{{outline_text}}' => $outline_text,
        );

        $prompt = strtr($template, $replacements);
        $prompt = Alpha_RSS_AI_Generator::append_content_prompt_output_suffix($prompt);
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

    public static function call_openai($generator, $item)
    {
        $outline_base_context = self::build_outline_context_base($generator);
        $outline_context = self::build_outline_context_from_source($generator, $item, array(), $outline_base_context);
        $seo_prompt = self::build_prompt($generator, $item, $outline_context);
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

        $generated_title_outline_count = self::extract_outline_target_h2_count_from_title(
            !empty($seo_article['title']) ? $seo_article['title'] : '',
            !empty($item['source_title']) ? $item['source_title'] : (!empty($item['title']) ? $item['title'] : '')
        );
        if ($generated_title_outline_count > 0) {
            $outline_context['force_exact_h2_count'] = 1;
            $outline_context['outline_target_h2_min'] = $generated_title_outline_count;
            $outline_context['outline_target_h2_max'] = $generated_title_outline_count;
            $outline_context['outline_target_h2_count'] = $generated_title_outline_count;
        }
        if (empty($seo_article['focus_keyword']) && !empty($outline_context['focus_keyword'])) {
            $seo_article['focus_keyword'] = $outline_context['focus_keyword'];
        } elseif (empty($seo_article['focus_keyword']) && !empty($item['keyword'])) {
            $seo_article['focus_keyword'] = sanitize_text_field((string) $item['keyword']);
        }
        if (empty($seo_article['meta_description'])) {
            if (!empty($seo_article['excerpt'])) {
                $seo_article['meta_description'] = wp_trim_words(wp_strip_all_tags((string) $seo_article['excerpt']), 28);
            } elseif (!empty($item['excerpt'])) {
                $seo_article['meta_description'] = wp_trim_words(wp_strip_all_tags((string) $item['excerpt']), 28);
            } elseif (!empty($outline_context['outline_notes'])) {
                $seo_article['meta_description'] = wp_trim_words(wp_strip_all_tags((string) $outline_context['outline_notes']), 28);
            }
        }
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

    public static function parse_internal_link_rules($rules)
    {
        if (is_string($rules)) {
            $rules = json_decode($rules, true);
        }

        if (!is_array($rules) || empty($rules)) {
            return array();
        }

        $normalized = array();
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $phrase = '';
            foreach (array('phrase', 'word', 'keyword', 'anchor_text') as $candidate_key) {
                if (!empty($rule[$candidate_key])) {
                    $phrase = sanitize_text_field((string) $rule[$candidate_key]);
                    break;
                }
            }
            $phrase = trim($phrase);

            $url = '';
            foreach (array('url', 'link', 'target_url') as $candidate_key) {
                if (!empty($rule[$candidate_key])) {
                    $url = esc_url_raw(trim((string) $rule[$candidate_key]));
                    break;
                }
            }

            if ($phrase === '' || $url === '') {
                continue;
            }

            $normalized[] = array(
                'quantity' => max(1, intval(isset($rule['quantity']) ? $rule['quantity'] : 1)),
                'phrase' => $phrase,
                'url' => $url,
                'target_blank' => !empty($rule['target_blank']) ? 1 : 0,
                'nofollow' => !empty($rule['nofollow']) ? 1 : 0,
                'sponsored' => !empty($rule['sponsored']) ? 1 : 0,
                'ugc' => !empty($rule['ugc']) ? 1 : 0,
            );
        }

        return array_values($normalized);
    }

    protected static function build_internal_link_match_pattern($phrase)
    {
        $phrase = trim((string) $phrase);
        if ($phrase === '') {
            return '';
        }

        return '~(?<![\p{L}\p{N}])(' . preg_quote($phrase, '~') . ')(?![\p{L}\p{N}])~iu';
    }

    protected static function normalize_internal_link_text($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($text)) {
            $text = trim((string) $text);
        }

        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    protected static function build_internal_link_attributes($rule)
    {
        $rule = is_array($rule) ? $rule : array();
        $attrs = array(
            'href' => !empty($rule['url']) ? esc_url($rule['url']) : '',
        );
        if (!empty($rule['target_blank'])) {
            $attrs['target'] = '_blank';
        }

        $rel = array();
        if (!empty($rule['nofollow'])) {
            $rel[] = 'nofollow';
        }
        if (!empty($rule['sponsored'])) {
            $rel[] = 'sponsored';
        }
        if (!empty($rule['ugc'])) {
            $rel[] = 'ugc';
        }
        if (!empty($rule['target_blank'])) {
            $rel[] = 'noopener';
            $rel[] = 'noreferrer';
        }

        $rel = array_values(array_unique(array_filter($rel)));
        if (!empty($rel)) {
            $attrs['rel'] = implode(' ', $rel);
        }

        return $attrs;
    }

    protected static function apply_internal_link_rules_to_dom($dom, $xpath, $root, $rules, &$applied_count, $remaining_total_links = null)
    {
        $rules = is_array($rules) ? $rules : array();
        if (empty($rules) || !$xpath || !$root) {
            return;
        }

        $text_nodes_query = './/p//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//li//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//blockquote//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//td//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//th//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//figcaption//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//summary//text()[normalize-space(.) != "" and not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]';
        $fallback_elements_query = './/p[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//li[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//blockquote[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//td[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//th[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//figcaption[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]'
            . ' | .//summary[not(ancestor::a) and not(ancestor::script) and not(ancestor::style) and not(ancestor::pre) and not(ancestor::code)]';

        foreach ($rules as $rule) {
            if ($remaining_total_links !== null && $remaining_total_links <= 0) {
                break;
            }

            $remaining = isset($rule['quantity']) ? max(1, intval($rule['quantity'])) : 1;
            $phrase = isset($rule['phrase']) ? (string) $rule['phrase'] : '';
            $pattern = self::build_internal_link_match_pattern($phrase);

            if ($pattern === '') {
                continue;
            }

            $text_nodes = $xpath->query($text_nodes_query, $root);
            if (!$text_nodes || $text_nodes->length === 0) {
                continue;
            }

            $nodes = array();
            for ($i = 0; $i < $text_nodes->length; $i++) {
                $nodes[] = $text_nodes->item($i);
            }
            $nodes = array_reverse($nodes);

            foreach ($nodes as $node) {
                if ($remaining <= 0 || !is_object($node) || !property_exists($node, 'nodeValue')) {
                    continue;
                }

                $node_text = (string) $node->nodeValue;
                if ($node_text === '') {
                    continue;
                }

                if (!preg_match_all($pattern, $node_text, $match_data, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $matches = array();
                if (!empty($match_data[1]) && is_array($match_data[1])) {
                    foreach ($match_data[1] as $match_item) {
                        if (!is_array($match_item) || !isset($match_item[0])) {
                            continue;
                        }
                        $matches[] = array(
                            'index' => count($matches),
                            'text' => (string) $match_item[0],
                            'offset' => isset($match_item[1]) ? intval($match_item[1]) : 0,
                        );
                    }
                }

                if (empty($matches)) {
                    continue;
                }

                $node_replacements = min($remaining, count($matches));
                if ($remaining_total_links !== null) {
                    $node_replacements = min($node_replacements, $remaining_total_links);
                }
                if ($node_replacements <= 0) {
                    continue;
                }

                $selected_matches = array_slice($matches, -$node_replacements);
                $selected_matches = array_reverse($selected_matches);

                $cursor = strlen($node_text);
                $chunks = array();

                foreach ($selected_matches as $match) {
                    $match_text = isset($match['text']) ? (string) $match['text'] : '';
                    $match_offset = isset($match['offset']) ? intval($match['offset']) : 0;
                    $match_length = strlen($match_text);
                    if ($match_text === '' || $match_length <= 0) {
                        continue;
                    }

                    $suffix = substr($node_text, $match_offset + $match_length, $cursor - ($match_offset + $match_length));
                    if ($suffix !== '') {
                        $chunks[] = esc_html($suffix);
                    }

                    $attrs = self::build_internal_link_attributes($rule);
                    $attr_parts = array();
                    foreach ($attrs as $attr_name => $attr_value) {
                        if ($attr_value === '') {
                            continue;
                        }
                        $attr_parts[] = $attr_name . '="' . esc_attr($attr_value) . '"';
                    }

                    $chunks[] = '<a ' . implode(' ', $attr_parts) . '>' . esc_html($match_text) . '</a>';
                    $cursor = $match_offset;
                }

                $prefix = substr($node_text, 0, $cursor);
                if ($prefix !== '') {
                    $chunks[] = esc_html($prefix);
                }

                if (empty($chunks)) {
                    continue;
                }

                $chunks = array_reverse($chunks);
                $new_html = implode('', $chunks);

                $fragment = $dom->createDocumentFragment();
                if (!$fragment->appendXML($new_html)) {
                    continue;
                }

                $node->parentNode->replaceChild($fragment, $node);
                $applied_count += $node_replacements;
                $remaining -= $node_replacements;
                if ($remaining_total_links !== null) {
                    $remaining_total_links -= $node_replacements;
                    if ($remaining_total_links <= 0) {
                        break 2;
                    }
                }
            }

            if ($remaining <= 0) {
                continue;
            }

            $fallback_elements = $xpath->query($fallback_elements_query, $root);
            if (!$fallback_elements || $fallback_elements->length === 0) {
                continue;
            }

            $fallback_nodes = array();
            for ($i = 0; $i < $fallback_elements->length; $i++) {
                $fallback_nodes[] = $fallback_elements->item($i);
            }
            $fallback_nodes = array_reverse($fallback_nodes);

            foreach ($fallback_nodes as $element) {
                if ($remaining <= 0 || !is_object($element) || !property_exists($element, 'textContent')) {
                    continue;
                }

                $element_text = (string) $element->textContent;
                if ($element_text === '') {
                    continue;
                }

                $pattern = self::build_internal_link_match_pattern($phrase);
                if ($pattern === '') {
                    continue;
                }

                if (!preg_match($pattern, $element_text, $match_data, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $match_text = !empty($match_data[1][0]) ? (string) $match_data[1][0] : '';
                $match_offset = !empty($match_data[1][1]) ? intval($match_data[1][1]) : -1;
                if ($match_text === '' || $match_offset < 0) {
                    continue;
                }

                $match_length = strlen($match_text);
                if ($match_length <= 0) {
                    continue;
                }

                $prefix = substr($element_text, 0, $match_offset);
                $suffix = substr($element_text, $match_offset + $match_length);
                $attrs = self::build_internal_link_attributes($rule);
                $attr_parts = array();
                foreach ($attrs as $attr_name => $attr_value) {
                    if ($attr_value === '') {
                        continue;
                    }
                    $attr_parts[] = $attr_name . '="' . esc_attr($attr_value) . '"';
                }

                $new_html = '';
                if ($prefix !== '') {
                    $new_html .= esc_html($prefix);
                }
                $new_html .= '<a ' . implode(' ', $attr_parts) . '>' . esc_html($match_text) . '</a>';
                if ($suffix !== '') {
                    $new_html .= esc_html($suffix);
                }

                while ($element->firstChild) {
                    $element->removeChild($element->firstChild);
                }

                $fragment = $dom->createDocumentFragment();
                if (!$fragment->appendXML($new_html)) {
                    continue;
                }

                $element->appendChild($fragment);
                $applied_count++;
                $remaining--;
                if ($remaining_total_links !== null) {
                    $remaining_total_links--;
                    if ($remaining_total_links <= 0) {
                        break 2;
                    }
                }
            }
        }
    }

    public static function apply_internal_links_to_content($content, $generator, $context = array())
    {
        $content = (string) $content;
        $generator = is_array($generator) ? $generator : array();
        $post_id = !empty($context['post_id']) ? intval($context['post_id']) : 0;
        $raw_rules = isset($generator['internal_links_json']) ? $generator['internal_links_json'] : '';
        $rules = self::parse_internal_link_rules($raw_rules);
        $global_rules = array();
        if (class_exists('Alpha_RSS_AI_Generator')) {
            $settings = Alpha_RSS_AI_Generator::get_settings();
            if (!empty($settings['global_internal_links_json'])) {
                $global_rules = self::parse_internal_link_rules($settings['global_internal_links_json']);
            }
        }
        $max_total_links = isset($generator['internal_links_count']) ? max(0, intval($generator['internal_links_count'])) : 0;

        if ($content === '') {
            return $content;
        }

        if (empty($rules) && empty($global_rules)) {
            return $content;
        }

        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return $content;
        }

        $previous_libxml_state = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="arc-internal-links-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_libxml_state);

        if (!$loaded) {
            return $content;
        }

        $xpath = new DOMXPath($dom);
        $root = $dom->getElementById('arc-internal-links-root');
        if (!$root) {
            return $content;
        }

        $applied_count = 0;

        if ($max_total_links > 0 && !empty($rules)) {
            self::apply_internal_link_rules_to_dom($dom, $xpath, $root, $rules, $applied_count, $max_total_links);
        }
        self::apply_internal_link_rules_to_dom($dom, $xpath, $root, $global_rules, $applied_count, null);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        return $output !== '' ? $output : $content;
    }

    public static function inject_content_plan_links_into_content($content, $links, $plan_type = 'pillar', $intro_label = '')
    {
        $content = (string) $content;
        $links = is_array($links) ? $links : array();
        $plan_type = sanitize_key((string) $plan_type);
        $intro_label = sanitize_text_field((string) $intro_label);

        if ($content === '' || empty($links)) {
            return $content;
        }

        $rules = array();
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = '';
            foreach (array('url', 'link', 'target_url') as $candidate_key) {
                if (!empty($link[$candidate_key])) {
                    $url = esc_url_raw(trim((string) $link[$candidate_key]));
                    break;
                }
            }

            $phrase = '';
            foreach (array('anchor_phrase', 'phrase', 'title', 'anchor_text') as $candidate_key) {
                if (!empty($link[$candidate_key])) {
                    $phrase = self::clean_source_text((string) $link[$candidate_key]);
                    break;
                }
            }

            if ($url === '' || $phrase === '') {
                continue;
            }

            $rules[] = array(
                'quantity' => 1,
                'phrase' => $phrase,
                'url' => $url,
                'target_blank' => 0,
                'nofollow' => 0,
            );
        }

        if (empty($rules)) {
            return $content;
        }

        $generator = array(
            'internal_links_json' => wp_json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
        $context = array(
            'plan_type' => $plan_type,
            'intro_label' => $intro_label,
        );

        return self::apply_internal_links_to_content($content, $generator, $context);
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

        $phrases = !empty($settings['phrases']) ? $settings['phrases'] : array('VocÃª tambÃ©m pode gostar de:');
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
            $html .= '<div class="arc-related-posts__inline-links">' . implode('<span class="arc-related-posts__separator">â€¢</span>', $links) . '</div>';
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
