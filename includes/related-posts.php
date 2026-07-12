<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Alpha_RSS_AI_Related_Posts')) {
    final class Alpha_RSS_AI_Related_Posts
    {
        public const OPTION_KEY = 'alpha_rss_ai_related_posts_settings';

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 20);
            add_action('admin_post_arc_save_related_posts_settings', array($this, 'handle_save_settings'));
            add_filter('the_content', array($this, 'filter_the_content'), 8);
        }

        public static function get_default_settings()
        {
            return array(
                'related_posts_enabled' => 0,
                'related_posts_position' => 'end',
                'related_posts_interval' => 4,
                'related_posts_min_h2' => 1,
                'related_posts_links_per_block' => 2,
                'related_posts_same_category_only' => 1,
                'related_posts_allow_fallback' => 1,
                'related_posts_style' => 'list',
                'related_posts_phrases' => Alpha_RSS_AI_Generator::get_default_related_posts_phrases(),
            );
        }

        public static function normalize_settings($settings)
        {
            $defaults = self::get_default_settings();
            if (!is_array($settings)) {
                $settings = array();
            }
            $settings = array_merge($defaults, $settings);

            $settings['related_posts_enabled'] = !empty($settings['related_posts_enabled']) ? 1 : 0;

            $position = isset($settings['related_posts_position']) ? sanitize_key((string) $settings['related_posts_position']) : 'end';
            if (!in_array($position, array('end', 'paragraphs', 'words'), true)) {
                $position = 'end';
            }
            $settings['related_posts_position'] = $position;

            $settings['related_posts_interval'] = max(1, intval($settings['related_posts_interval']));
            $settings['related_posts_min_h2'] = max(0, intval($settings['related_posts_min_h2']));
            $settings['related_posts_links_per_block'] = max(1, intval($settings['related_posts_links_per_block']));
            $settings['related_posts_same_category_only'] = !empty($settings['related_posts_same_category_only']) ? 1 : 0;
            $settings['related_posts_allow_fallback'] = !empty($settings['related_posts_allow_fallback']) ? 1 : 0;

            $style = isset($settings['related_posts_style']) ? sanitize_key((string) $settings['related_posts_style']) : 'list';
            if (!in_array($style, array('inline', 'list', 'cards'), true)) {
                $style = 'list';
            }
            $settings['related_posts_style'] = $style;
            $settings['related_posts_phrases'] = isset($settings['related_posts_phrases']) ? sanitize_textarea_field((string) $settings['related_posts_phrases']) : '';

            return $settings;
        }

        public static function get_settings()
        {
            $settings = get_option(self::OPTION_KEY, array());
            return self::normalize_settings($settings);
        }

        public function admin_menu()
        {
            add_submenu_page(
                'alpha-rss-ai-generator',
                'Sugestões de posts',
                'Sugestões de posts',
                'manage_options',
                'alpha-rss-ai-related-posts',
                array($this, 'render_page')
            );
        }

        public function handle_save_settings()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            check_admin_referer('arc_save_related_posts_settings', 'arc_related_posts_nonce');

            $raw = isset($_POST) ? wp_unslash($_POST) : array();
            $settings = self::normalize_settings($raw);
            update_option(self::OPTION_KEY, $settings, false);

            wp_safe_redirect(add_query_arg(array(
                'page' => 'alpha-rss-ai-related-posts',
                'arc_notice' => 'saved',
            ), admin_url('admin.php')));
            exit;
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            if (function_exists('nocache_headers')) {
                nocache_headers();
            }

            $settings = self::get_settings();
            $saved = isset($_GET['arc_notice']) && sanitize_key(wp_unslash($_GET['arc_notice'])) === 'saved';
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
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Sugestões de posts</h1>
                        <p class="mt-2 max-w-3xl text-sm text-slate-600">Configure frases, posição e estilo para inserir posts relacionados no conteúdo gerado.</p>
                    </div>
                </div>

                <?php if ($saved): ?>
                    <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow-soft">Configurações salvas com sucesso.</div>
                <?php endif; ?>

                <div class="max-w-4xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="border-b border-slate-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-slate-950">Configuração global</h2>
                        <p class="mt-1 text-sm text-slate-500">Essas regras valem para os conteúdos gerados pelo plugin e não dependem do gerador individual.</p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="space-y-6 px-6 py-6">
                        <?php wp_nonce_field('arc_save_related_posts_settings', 'arc_related_posts_nonce'); ?>
                        <input type="hidden" name="action" value="arc_save_related_posts_settings" />

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Ativar sugestões</label>
                                <select name="related_posts_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                    <option value="1" <?php selected(!empty($settings['related_posts_enabled'])); ?>>Sim</option>
                                    <option value="0" <?php selected(empty($settings['related_posts_enabled'])); ?>>Não</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Posição</label>
                                <select name="related_posts_position" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                    <option value="end" <?php selected(($settings['related_posts_position'] ?? 'end') === 'end'); ?>>No final do conteúdo</option>
                                    <option value="paragraphs" <?php selected(($settings['related_posts_position'] ?? 'end') === 'paragraphs'); ?>>A cada X parágrafos</option>
                                    <option value="words" <?php selected(($settings['related_posts_position'] ?? 'end') === 'words'); ?>>A cada X palavras</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Intervalo</label>
                                <input type="number" min="1" name="related_posts_interval" value="<?php echo esc_attr(isset($settings['related_posts_interval']) ? $settings['related_posts_interval'] : 4); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Mínimo de H2</label>
                                <input type="number" min="0" name="related_posts_min_h2" value="<?php echo esc_attr(isset($settings['related_posts_min_h2']) ? $settings['related_posts_min_h2'] : 1); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Quantidade por bloco</label>
                                <input type="number" min="1" name="related_posts_links_per_block" value="<?php echo esc_attr(isset($settings['related_posts_links_per_block']) ? $settings['related_posts_links_per_block'] : 2); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Estilo</label>
                                <select name="related_posts_style" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                    <option value="list" <?php selected(($settings['related_posts_style'] ?? 'list') === 'list'); ?>>Lista</option>
                                    <option value="inline" <?php selected(($settings['related_posts_style'] ?? 'list') === 'inline'); ?>>Inline</option>
                                    <option value="cards" <?php selected(($settings['related_posts_style'] ?? 'list') === 'cards'); ?>>Cards</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Apenas mesma categoria</label>
                                <select name="related_posts_same_category_only" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                    <option value="1" <?php selected(!empty($settings['related_posts_same_category_only'])); ?>>Sim</option>
                                    <option value="0" <?php selected(empty($settings['related_posts_same_category_only'])); ?>>Não</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Permitir fallback</label>
                                <select name="related_posts_allow_fallback" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                    <option value="1" <?php selected(!empty($settings['related_posts_allow_fallback'])); ?>>Sim</option>
                                    <option value="0" <?php selected(empty($settings['related_posts_allow_fallback'])); ?>>Não</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Frases do marcador</label>
                            <textarea name="related_posts_phrases" rows="5" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Você também pode gostar de:\nLeia também:\nVeja também:"><?php echo esc_textarea(isset($settings['related_posts_phrases']) ? $settings['related_posts_phrases'] : Alpha_RSS_AI_Generator::get_default_related_posts_phrases()); ?></textarea>
                            <p class="mt-1 text-xs text-slate-500">Uma frase por linha. O sistema escolhe uma delas em cada bloco de sugestão.</p>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Salvar configurações</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php
        }

        public function filter_the_content($content)
        {
        if (is_admin() || wp_doing_ajax() || wp_is_json_request()) {
            return $content;
        }

        if (doing_filter('get_the_excerpt') || doing_filter('wp_trim_excerpt')) {
            return $content;
        }

        if (!is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

            $settings = self::get_settings();
            if (empty($settings['related_posts_enabled'])) {
                return $content;
            }

            $post = get_post();
            if (!($post instanceof WP_Post)) {
                return $content;
            }

            $filtered_content = Alpha_RSS_AI_Generator_Helper::inject_related_posts_into_content($content, intval($post->ID), $settings);
            return $filtered_content !== '' ? $filtered_content : $content;
        }
    }
}
