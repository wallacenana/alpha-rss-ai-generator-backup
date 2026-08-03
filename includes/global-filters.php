<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Content_Rank_Global_Filters
{
    public static function get_entries()
    {
        $settings = Content_Rank_Generator::get_settings();
        $raw = isset($settings['blacklist_json']) ? $settings['blacklist_json'] : '[]';
        return self::sanitize_entries($raw);
    }

    public static function sanitize_entries($raw)
    {
        if (is_string($raw)) {
            $decoded = json_decode(wp_unslash($raw), true);
            $raw = is_array($decoded) ? $decoded : preg_split('/\r\n|\r|\n/', $raw);
        }

        if (!is_array($raw)) {
            return array();
        }

        $entries = array();
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $entry = array('type' => 'term', 'value' => $entry);
            }
            if (!is_array($entry)) {
                continue;
            }

            $type = isset($entry['type']) ? sanitize_key((string) $entry['type']) : 'term';
            if (!in_array($type, array('term', 'source'), true)) {
                $type = 'term';
            }

            $value = isset($entry['value']) ? sanitize_text_field(wp_unslash((string) $entry['value'])) : '';
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $normalized = self::normalize_value($value);
            if ($normalized === '') {
                continue;
            }

            $entries[] = array(
                'type' => $type,
                'value' => $value,
                'normalized' => $normalized,
                'automatic' => !empty($entry['automatic']) ? 1 : 0,
                'status_code' => !empty($entry['status_code']) ? intval($entry['status_code']) : 0,
                'reason' => isset($entry['reason']) ? sanitize_text_field(wp_unslash((string) $entry['reason'])) : '',
            );
        }

        $unique = array();
        foreach ($entries as $entry) {
            $key = $entry['type'] . '|' . $entry['normalized'];
            if (!isset($unique[$key])) {
                $unique[$key] = $entry;
            }
        }

        return array_values($unique);
    }

    public static function sanitize_textarea_entries($raw, $existing = array())
    {
        $entries = self::sanitize_entries($raw);
        $existing_entries = self::sanitize_entries($existing);
        $existing_by_value = array();
        foreach ($existing_entries as $existing_entry) {
            $existing_by_value[$existing_entry['normalized']] = $existing_entry;
        }

        foreach ($entries as $index => $entry) {
            if (isset($existing_by_value[$entry['normalized']])) {
                $previous = $existing_by_value[$entry['normalized']];
                $entries[$index]['type'] = !empty($previous['type']) ? $previous['type'] : $entry['type'];
                $entries[$index]['automatic'] = !empty($previous['automatic']) ? 1 : 0;
                $entries[$index]['status_code'] = !empty($previous['status_code']) ? intval($previous['status_code']) : 0;
                $entries[$index]['reason'] = !empty($previous['reason']) ? (string) $previous['reason'] : '';
            }
        }

        return self::sanitize_entries($entries);
    }

    public static function item_is_blocked($item)
    {
        if (!is_array($item)) {
            return false;
        }

        $terms = array();
        foreach (array('title', 'item_title', 'keyword', 'source_title', 'feed_title', 'excerpt', 'content') as $key) {
            if (!empty($item[$key])) {
                $terms[] = wp_strip_all_tags((string) $item[$key]);
            }
        }

        $text = self::normalize_value(implode(' ', $terms));
        $urls = array();
        foreach (array('permalink', 'source_url', 'item_permalink', 'feed_url') as $key) {
            if (!empty($item[$key])) {
                $urls[] = self::normalize_value((string) $item[$key]);
            }
        }

        foreach (self::get_entries() as $entry) {
            if (self::term_matches($text, $entry['normalized'])) {
                return true;
            }
            foreach ($urls as $url) {
                if (self::source_matches($url, $entry['normalized'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function url_is_blocked($url)
    {
        $url = self::normalize_value($url);
        if ($url === '') {
            return false;
        }

        foreach (self::get_entries() as $entry) {
            if (self::source_matches($url, $entry['normalized'])) {
                return true;
            }
        }

        return false;
    }

    public static function add_source_from_http_status($url, $status_code)
    {
        $status_code = intval($status_code);
        if (!in_array($status_code, array(402, 403), true)) {
            return false;
        }

        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        $host = self::normalize_value($host);
        if ($host === '') {
            return false;
        }
        $host = preg_replace('/^www\./', '', $host);

        $entries = self::get_entries();
        foreach ($entries as $entry) {
            if ($entry['type'] === 'source' && self::source_matches($host, $entry['normalized'])) {
                return false;
            }
        }

        $entries[] = array(
            'type' => 'source',
            'value' => $host,
            'automatic' => 1,
            'status_code' => $status_code,
            'reason' => 'Bloqueado automaticamente após HTTP ' . $status_code,
        );
        $entries = self::sanitize_entries($entries);

        $settings = Content_Rank_Generator::get_settings();
        $settings['blacklist_json'] = wp_json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        update_option(Content_Rank_Generator::OPTION_KEY, $settings, false);
        return true;
    }

    private static function normalize_value($value)
    {
        $value = strtolower(trim(wp_strip_all_tags((string) $value)));
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private static function term_matches($text, $term)
    {
        if ($text === '' || $term === '') {
            return false;
        }
        if (strpos($term, ' ') !== false) {
            return strpos($text, $term) !== false;
        }

        return (bool) preg_match('/(^|[^a-z0-9])' . preg_quote($term, '/') . '([^a-z0-9]|$)/i', $text);
    }

    private static function source_matches($url, $source)
    {
        $url = self::normalize_value($url);
        $source = trim(self::normalize_value($source), " /\t\n\r\0\x0B");
        if ($url === '' || $source === '') {
            return false;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        $host = self::normalize_value($host);
        $host = preg_replace('/^www\./', '', $host);
        if ($host !== '' && ($host === $source || substr($host, -strlen('.' . $source)) === '.' . $source)) {
            return true;
        }

        return strpos($url, $source) !== false;
    }
}
