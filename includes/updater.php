<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Content_Rank_Generator_Updater')) {
    final class Content_Rank_Generator_Updater
    {
        private $plugin_file;
        private $plugin_basename;
        private $cache_key_prefix = 'content_rank_update_manifest_';

        public function __construct($plugin_file)
        {
            $this->plugin_file = (string) $plugin_file;
            $this->plugin_basename = plugin_basename($this->plugin_file);

            add_filter('site_transient_update_plugins', array($this, 'inject_update_data'));
            add_filter('plugins_api', array($this, 'plugins_api'), 20, 3);
            add_filter('upgrader_source_selection', array($this, 'normalize_package_source'), 10, 4);
            add_filter('upgrader_source_selection', array($this, 'recover_package_validation'), 99, 4);
        }

        /**
         * GitHub archive files use the repository name as their root folder.
         * WordPress must receive the package under the installed plugin slug.
         */
        public function normalize_package_source($source, $remote_source, $upgrader, $hook_extra)
        {
            if (!$this->is_enabled() || !is_array($hook_extra)) {
                return $source;
            }

            $requested_plugin = !empty($hook_extra['plugin']) ? (string) $hook_extra['plugin'] : '';
            if ($requested_plugin !== $this->plugin_basename && basename($requested_plugin) !== basename($this->plugin_basename)) {
                return $source;
            }

            $source = untrailingslashit((string) $source);
            if ($source === '') {
                return $source;
            }

            // Use the folder where the plugin is already installed. This
            // works whether GitHub returns content-rank-main or another root.
            $installed_folder = basename(dirname($this->plugin_basename));
            if ($installed_folder === '' || $installed_folder === '.' || $installed_folder === DIRECTORY_SEPARATOR) {
                $installed_folder = 'content-rank';
            }
            if (basename($source) === $installed_folder) {
                return $source;
            }

            $target = trailingslashit(dirname($source)) . $installed_folder;
            global $wp_filesystem;
            if (is_object($wp_filesystem) && $wp_filesystem->is_dir($target)) {
                $wp_filesystem->delete($target, true);
            }
            if (is_object($wp_filesystem) && $wp_filesystem->move($source, $target, true)) {
                return $target;
            }

            return new WP_Error(
                'content_rank_update_folder',
                'Não foi possível preparar a pasta do pacote Content Rank para atualização.'
            );
        }

        public function recover_package_validation($source, $remote_source, $upgrader, $hook_extra)
        {
            if (!$this->is_target_update($hook_extra) || !is_wp_error($source) || $source->get_error_code() !== 'incompatible_archive_no_plugins') {
                return $source;
            }

            global $wp_filesystem;
            if (!is_object($wp_filesystem)) {
                return $source;
            }

            $remote_source = untrailingslashit((string) $remote_source);
            $entries = $wp_filesystem->dirlist($remote_source);
            if (!is_array($entries)) {
                return $source;
            }

            foreach ($entries as $entry_name => $entry) {
                if (!is_array($entry) || (isset($entry['type']) && $entry['type'] !== 'd')) {
                    continue;
                }

                $candidate = trailingslashit($remote_source) . trim((string) $entry_name, '/');
                $working_directory = str_replace(
                    $wp_filesystem->wp_content_dir(),
                    trailingslashit(WP_CONTENT_DIR),
                    trailingslashit($candidate)
                );
                $plugin_files = glob($working_directory . '*.php');
                if (!is_array($plugin_files)) {
                    continue;
                }

                foreach ($plugin_files as $plugin_file) {
                    $plugin_data = get_plugin_data($plugin_file, false, false);
                    if (empty($plugin_data['Name'])) {
                        continue;
                    }

                    return trailingslashit($candidate);
                }
            }

            return $source;
        }

        private function is_target_update($hook_extra)
        {
            if (!is_array($hook_extra)) {
                return false;
            }

            $requested_plugin = !empty($hook_extra['plugin']) ? (string) $hook_extra['plugin'] : '';
            return $requested_plugin !== '' && (
                $requested_plugin === $this->plugin_basename ||
                basename($requested_plugin) === basename($this->plugin_basename)
            );
        }

        private function is_enabled()
        {
            if (defined('CONTENT_RANK_GENERATOR_UPDATE_ENABLED')) {
                return (bool) CONTENT_RANK_GENERATOR_UPDATE_ENABLED;
            }

            return true;
        }

        private function get_manifest_url()
        {
            if (defined('CONTENT_RANK_GENERATOR_UPDATE_MANIFEST_URL')) {
                $manifest_url = (string) CONTENT_RANK_GENERATOR_UPDATE_MANIFEST_URL;
            } else {
                $manifest_url = '';
            }

            return esc_url_raw($manifest_url);
        }

        private function get_cache_key($manifest_url)
        {
            return $this->cache_key_prefix . md5((string) $manifest_url);
        }

        private function normalize_manifest($manifest)
        {
            $manifest = is_array($manifest) ? $manifest : array();
            $sections = array();
            if (!empty($manifest['sections']) && is_array($manifest['sections'])) {
                $sections = $manifest['sections'];
            } else {
                foreach (array('description', 'installation', 'changelog', 'faq') as $section_key) {
                    if (!empty($manifest[$section_key])) {
                        $sections[$section_key] = (string) $manifest[$section_key];
                    }
                }
            }

            return array(
                'name' => !empty($manifest['name']) ? sanitize_text_field((string) $manifest['name']) : 'Content Rank',
                'slug' => !empty($manifest['slug']) ? sanitize_key((string) $manifest['slug']) : 'content-rank',
                'version' => !empty($manifest['version']) ? sanitize_text_field((string) $manifest['version']) : '',
                'homepage' => !empty($manifest['homepage']) ? esc_url_raw((string) $manifest['homepage']) : '',
                'download_url' => !empty($manifest['download_url']) ? esc_url_raw((string) $manifest['download_url']) : (!empty($manifest['package']) ? esc_url_raw((string) $manifest['package']) : ''),
                'requires' => !empty($manifest['requires']) ? sanitize_text_field((string) $manifest['requires']) : '',
                'tested' => !empty($manifest['tested']) ? sanitize_text_field((string) $manifest['tested']) : '',
                'requires_php' => !empty($manifest['requires_php']) ? sanitize_text_field((string) $manifest['requires_php']) : '',
                'author' => !empty($manifest['author']) ? sanitize_text_field((string) $manifest['author']) : '',
                'author_url' => !empty($manifest['author_url']) ? esc_url_raw((string) $manifest['author_url']) : '',
                'last_updated' => !empty($manifest['last_updated']) ? sanitize_text_field((string) $manifest['last_updated']) : '',
                'sections' => $sections,
                'banners' => !empty($manifest['banners']) && is_array($manifest['banners']) ? $manifest['banners'] : array(),
                'icons' => !empty($manifest['icons']) && is_array($manifest['icons']) ? $manifest['icons'] : array(),
                'upgrade_notice' => !empty($manifest['upgrade_notice']) ? sanitize_text_field((string) $manifest['upgrade_notice']) : '',
            );
        }

        private function fetch_manifest($force = false)
        {
            if (!$this->is_enabled()) {
                return array();
            }

            if (isset($_GET['force-check']) && $_GET['force-check'] !== '') {
                $force = true;
            }

            $manifest_url = $this->get_manifest_url();
            if ($manifest_url === '') {
                return array();
            }

            $cache_key = $this->get_cache_key($manifest_url);
            if (!$force) {
                $cached = get_transient($cache_key);
                if (is_array($cached) && !empty($cached)) {
                    return $cached;
                }
            }

            $response = wp_remote_get($manifest_url, array(
                'timeout' => 10,
                'redirection' => 3,
                'user-agent' => 'Content-Rank/' . (class_exists('Content_Rank_Generator') ? Content_Rank_Generator::VERSION : '1.0.0') . '; ' . home_url('/'),
                'headers' => array(
                    'Accept' => 'application/json',
                    'Cache-Control' => 'no-cache, no-store, max-age=0',
                    'Pragma' => 'no-cache',
                ),
            ));

            if (is_wp_error($response)) {
                set_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
                return array();
            }

            $status_code = intval(wp_remote_retrieve_response_code($response));
            $body = trim((string) wp_remote_retrieve_body($response));
            if ($status_code !== 200 || $body === '') {
                set_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
                return array();
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                set_transient($cache_key, array(), 15 * MINUTE_IN_SECONDS);
                return array();
            }

            $manifest = $this->normalize_manifest($decoded);
            set_transient($cache_key, $manifest, MINUTE_IN_SECONDS);
            return $manifest;
        }

        public function inject_update_data($transient)
        {
            if (!is_object($transient) || empty($transient->checked) || !is_array($transient->checked)) {
                return $transient;
            }

            if (!isset($transient->response) || !is_array($transient->response)) {
                $transient->response = array();
            }

            if (!$this->is_enabled()) {
                return $transient;
            }

            $manifest = $this->fetch_manifest(false);
            if (empty($manifest['version']) || empty($manifest['download_url'])) {
                return $transient;
            }

            $current_version = isset($transient->checked[$this->plugin_basename]) ? (string) $transient->checked[$this->plugin_basename] : (class_exists('Content_Rank_Generator') ? Content_Rank_Generator::VERSION : '');
            if ($current_version === '' || version_compare($manifest['version'], $current_version, '<=')) {
                if (isset($transient->response[$this->plugin_basename])) {
                    unset($transient->response[$this->plugin_basename]);
                }
                return $transient;
            }

            $update = array(
                'slug' => $manifest['slug'],
                'plugin' => $this->plugin_basename,
                'new_version' => $manifest['version'],
                'url' => !empty($manifest['homepage']) ? $manifest['homepage'] : home_url('/'),
                'package' => $manifest['download_url'],
                'tested' => $manifest['tested'],
                'requires' => $manifest['requires'],
                'requires_php' => $manifest['requires_php'],
                'icons' => $manifest['icons'],
                'banners' => $manifest['banners'],
                'upgrade_notice' => $manifest['upgrade_notice'],
            );

            $transient->response[$this->plugin_basename] = (object) $update;
            return $transient;
        }

        public function plugins_api($result, $action, $args)
        {
            if ($action !== 'plugin_information') {
                return $result;
            }

            if (!is_object($args) || empty($args->slug) || sanitize_key((string) $args->slug) !== 'content-rank') {
                return $result;
            }

            $manifest = $this->fetch_manifest(false);
            if (empty($manifest)) {
                return $result;
            }

            return (object) array(
                'name' => $manifest['name'],
                'slug' => $manifest['slug'],
                'version' => $manifest['version'],
                'author' => $manifest['author'],
                'author_profile' => $manifest['author_url'],
                'homepage' => $manifest['homepage'],
                'download_link' => $manifest['download_url'],
                'requires' => $manifest['requires'],
                'tested' => $manifest['tested'],
                'requires_php' => $manifest['requires_php'],
                'last_updated' => $manifest['last_updated'],
                'sections' => $manifest['sections'],
                'banners' => $manifest['banners'],
                'icons' => $manifest['icons'],
                'upgrade_notice' => $manifest['upgrade_notice'],
                'external' => true,
            );
        }
    }
}
