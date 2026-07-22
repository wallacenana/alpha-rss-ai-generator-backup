<?php

if (!defined('ABSPATH')) {
    exit;
}

class Alpha_RSS_AI_Prompt_Settings
{
    public function __construct()
    {
        add_action('admin_menu', array($this, 'admin_menu'), 998);
        add_action('admin_post_arc_save_prompt_settings', array($this, 'handle_save_prompt_settings'));
        add_action('admin_post_arc_reset_prompt_settings', array($this, 'handle_reset_prompt_settings'));
    }

    public static function maybe_migrate_prompt_settings()
    {
        if (!class_exists('Alpha_RSS_AI_Generator')) {
            return;
        }

        $storage = Alpha_RSS_AI_Generator::get_prompt_models_storage();
        if (!empty($storage['prompt_models_initialized'])) {
            return;
        }

        $prompt_models = array();
        $source_generator_id = 0;

        global $wpdb;
        $table_generators = !empty(Alpha_RSS_AI_Generator::$table_generators) ? Alpha_RSS_AI_Generator::$table_generators : '';
        if ($table_generators !== '') {
            $row = $wpdb->get_row(
                "SELECT id, prompt_models_json FROM {$table_generators} WHERE prompt_models_json IS NOT NULL AND prompt_models_json <> '' ORDER BY created_at ASC LIMIT 1",
                ARRAY_A
            );

            if (is_array($row) && !empty($row['prompt_models_json'])) {
                $decoded = json_decode((string) $row['prompt_models_json'], true);
                if (is_array($decoded)) {
                    $prompt_models = Alpha_RSS_AI_Generator::normalize_prompt_models($decoded);
                    $source_generator_id = !empty($row['id']) ? intval($row['id']) : 0;
                }
            }
        }

        if (empty($prompt_models)) {
            $prompt_models = Alpha_RSS_AI_Generator::get_default_prompt_models();
        }

        Alpha_RSS_AI_Generator::save_prompt_models_storage($prompt_models, $source_generator_id);
    }

    public function admin_menu()
    {
        add_submenu_page(
            'alpha-rss-ai-generator',
            'Prompts',
            'Prompts',
            'manage_options',
            'alpha-rss-ai-prompts',
            array($this, 'render_prompt_settings_page')
        );
    }

    public static function get_prompt_models()
    {
        $storage = class_exists('Alpha_RSS_AI_Generator') ? Alpha_RSS_AI_Generator::get_prompt_models_storage() : array();
        $prompt_models = array();

        if (!empty($storage['prompt_models_json'])) {
            $decoded = json_decode((string) $storage['prompt_models_json'], true);
            if (is_array($decoded)) {
                $prompt_models = $decoded;
            }
        }

        if (empty($prompt_models) && class_exists('Alpha_RSS_AI_Generator')) {
            $settings = Alpha_RSS_AI_Generator::get_settings();
            if (!empty($settings['prompt_models_json'])) {
                $decoded = json_decode((string) $settings['prompt_models_json'], true);
                if (is_array($decoded)) {
                    $prompt_models = $decoded;
                }
            }
        }

        $prompt_models = class_exists('Alpha_RSS_AI_Generator')
            ? Alpha_RSS_AI_Generator::normalize_prompt_models($prompt_models)
            : array();

        if (empty($prompt_models) && class_exists('Alpha_RSS_AI_Generator')) {
            $prompt_models = Alpha_RSS_AI_Generator::get_default_prompt_models();
        }

        return $prompt_models;
    }

    public static function sanitize_prompt_models_from_request($raw_models)
    {
        if (!is_array($raw_models)) {
            $raw_models = array();
        }

        $models = array();
        foreach ($raw_models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $models[] = array(
                'key' => isset($model['key']) ? sanitize_key((string) $model['key']) : '',
                'name' => isset($model['name']) ? sanitize_text_field(wp_unslash($model['name'])) : '',
                'description' => isset($model['description']) ? sanitize_textarea_field(wp_unslash($model['description'])) : '',
                'outline_model_key' => isset($model['outline_model_key']) ? sanitize_key((string) $model['outline_model_key']) : '',
                'outline_prompt_template' => isset($model['outline_prompt_template']) ? wp_kses_post(wp_unslash($model['outline_prompt_template'])) : '',
                'seo_prompt_template' => isset($model['seo_prompt_template']) ? wp_kses_post(wp_unslash($model['seo_prompt_template'])) : '',
                'content_prompt_template' => isset($model['content_prompt_template']) ? wp_kses_post(wp_unslash($model['content_prompt_template'])) : '',
            );
        }

        return class_exists('Alpha_RSS_AI_Generator')
            ? Alpha_RSS_AI_Generator::normalize_prompt_models($models)
            : $models;
    }

    public static function save_prompt_settings($raw)
    {
        if (!class_exists('Alpha_RSS_AI_Generator')) {
            return new WP_Error('arc_prompt_settings_unavailable', 'O gerador ainda nao esta carregado.');
        }

        $storage = Alpha_RSS_AI_Generator::get_prompt_models_storage();
        $raw_models = array();
        if (isset($raw['prompt_models_json']) && is_string($raw['prompt_models_json']) && trim((string) $raw['prompt_models_json']) !== '') {
            $decoded = json_decode(wp_unslash((string) $raw['prompt_models_json']), true);
            if (is_array($decoded)) {
                $raw_models = $decoded;
            }
        } elseif (isset($raw['prompt_models']) && is_array($raw['prompt_models'])) {
            $raw_models = wp_unslash($raw['prompt_models']);
        }

        $models = self::sanitize_prompt_models_from_request($raw_models);
        if (empty($models)) {
            $models = Alpha_RSS_AI_Generator::get_default_prompt_models();
        }

        $migrated_from_generator_id = isset($raw['prompt_models_migrated_from_generator_id']) ? max(0, intval($raw['prompt_models_migrated_from_generator_id'])) : 0;
        if ($migrated_from_generator_id <= 0 && !empty($storage['prompt_models_migrated_from_generator_id'])) {
            $migrated_from_generator_id = intval($storage['prompt_models_migrated_from_generator_id']);
        }

        Alpha_RSS_AI_Generator::save_prompt_models_storage($models, $migrated_from_generator_id);

        return $models;
    }

    public function handle_save_prompt_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        check_admin_referer('arc_save_prompt_settings', 'arc_prompt_settings_nonce');
        $result = self::save_prompt_settings($_POST);
        if (is_wp_error($result)) {
            Alpha_RSS_AI_Generator::redirect_with_notice($result->get_error_message(), 'error', array(
                'page' => 'alpha-rss-ai-prompts',
            ));
        }

        Alpha_RSS_AI_Generator::redirect_with_notice('Prompts salvos com sucesso.', 'success', array(
            'page' => 'alpha-rss-ai-prompts',
        ));
    }

    public function handle_reset_prompt_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        check_admin_referer('arc_reset_prompt_settings', 'arc_prompt_settings_nonce');
        if (!class_exists('Alpha_RSS_AI_Generator')) {
            wp_die('O gerador ainda nao esta carregado.');
        }

        Alpha_RSS_AI_Generator::save_prompt_models_storage(Alpha_RSS_AI_Generator::get_default_prompt_models(), 0);

        Alpha_RSS_AI_Generator::redirect_with_notice('Prompts restaurados para o padrão.', 'success', array(
            'page' => 'alpha-rss-ai-prompts',
        ));
    }

    public function render_prompt_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        self::maybe_migrate_prompt_settings();

        $storage = class_exists('Alpha_RSS_AI_Generator') ? Alpha_RSS_AI_Generator::get_prompt_models_storage() : array();
        $prompt_models = self::get_prompt_models();
        $outline_models = class_exists('Alpha_RSS_AI_Generator') ? Alpha_RSS_AI_Generator::get_outline_models() : array();

        ob_start();
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
                <div class="text-xs font-semibold text-indigo-600">Alpha RSS AI</div>
                <h1 class="mt-2 text-lg font-semibold tracking-tight text-slate-950">Prompts</h1>
                <p class="mt-2 text-sm text-slate-600">Edite aqui os prompts padrão usados por todos os geradores. Eles são migrados uma vez a partir de qualquer gerador existente e depois passam a valer globalmente.</p>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white shadow-soft">
                <div class="border-b border-slate-200 px-6 py-4">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Modelos de prompt</h2>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('arc_reset_prompt_settings', 'arc_prompt_settings_nonce'); ?>
                            <input type="hidden" name="action" value="arc_reset_prompt_settings" />
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Restaurar padrões</button>
                        </form>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="arc-prompt-settings-form p-6">
                    <?php wp_nonce_field('arc_save_prompt_settings', 'arc_prompt_settings_nonce'); ?>
                    <input type="hidden" name="action" value="arc_save_prompt_settings" />
                    <input type="hidden" name="prompt_models_migrated_from_generator_id" value="<?php echo esc_attr(isset($storage['prompt_models_migrated_from_generator_id']) ? intval($storage['prompt_models_migrated_from_generator_id']) : 0); ?>" />
                    <textarea name="prompt_models_json" data-prompt-models-json class="hidden" aria-hidden="true" tabindex="-1"></textarea>

                    <div class="space-y-4">
                        <?php foreach ($prompt_models as $prompt_model): ?>
                            <?php
                            $prompt_model_key = isset($prompt_model['key']) ? (string) $prompt_model['key'] : '';
                            $prompt_model_name = isset($prompt_model['name']) ? (string) $prompt_model['name'] : '';
                            $prompt_model_outline_key = isset($prompt_model['outline_model_key']) ? (string) $prompt_model['outline_model_key'] : '';
                            ?>
                            <details
                                class="group rounded-2xl border border-slate-200 bg-slate-50"
                                data-prompt-model-card
                                data-prompt-model-key="<?php echo esc_attr($prompt_model_key); ?>"
                                data-prompt-model-name="<?php echo esc_attr($prompt_model_name); ?>"
                                data-prompt-model-description="<?php echo esc_attr(isset($prompt_model['description']) ? (string) $prompt_model['description'] : ''); ?>"
                                data-prompt-outline-key="<?php echo esc_attr($prompt_model_outline_key); ?>"
                                <?php echo $prompt_model_key === 'lista' ? 'open' : ''; ?>
                            >
                                <summary class="flex cursor-pointer list-none items-center justify-between p-4 gap-4 font-medium text-slate-800">
                                    <span><?php echo esc_html($prompt_model_name); ?></span>
                                    <span class="text-slate-400 transition group-open:rotate-180">⌄</span>
                                </summary>
                                <div class="mt-4 grid grid-cols-1 gap-4  p-4 pt-0">
                                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Prompt SEO</label>
                                            <textarea data-prompt-seo-template name="prompt_models[<?php echo esc_attr($prompt_model_key); ?>][seo_prompt_template]" rows="20" class="w-full rounded-2xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"><?php echo esc_textarea(isset($prompt_model['seo_prompt_template']) ? $prompt_model['seo_prompt_template'] : ''); ?></textarea>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Prompt do conteúdo</label>
                                            <textarea data-prompt-content-template name="prompt_models[<?php echo esc_attr($prompt_model_key); ?>][content_prompt_template]" rows="20" class="w-full rounded-2xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"><?php echo esc_textarea(isset($prompt_model['content_prompt_template']) ? $prompt_model['content_prompt_template'] : ''); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-6 flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">Esses prompts passam a valer para todos os geradores. O sistema continua usando o modelo escolhido por cada gerador.</p>
                        <div class="flex items-center gap-3">
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Salvar prompts</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var form = document.querySelector('form.arc-prompt-settings-form');
                var serializedField = document.querySelector('[data-prompt-models-json]');
                document.querySelectorAll('[data-outline-block]').forEach(function(block) {
                    var toggleButton = block.querySelector('[data-outline-toggle]');
                    var editButton = block.querySelector('[data-outline-edit]');
                    var panel = block.querySelector('[data-outline-panel]');
                    var textarea = block.querySelector('[data-outline-textarea]');
                    if (!toggleButton || !editButton || !panel || !textarea) {
                        return;
                    }

                    toggleButton.addEventListener('click', function() {
                        var isHidden = panel.classList.contains('hidden');
                        if (isHidden) {
                            panel.classList.remove('hidden');
                            toggleButton.textContent = 'Ocultar outline';
                            editButton.textContent = textarea.hasAttribute('readonly') ? 'Editar outline' : 'Bloquear outline';
                        } else {
                            panel.classList.add('hidden');
                            toggleButton.textContent = 'Ver outline';
                            textarea.setAttribute('readonly', 'readonly');
                            textarea.classList.remove('bg-white');
                            textarea.classList.add('bg-slate-50');
                            editButton.textContent = 'Editar outline';
                        }
                    });

                    editButton.addEventListener('click', function() {
                        var editable = textarea.hasAttribute('readonly');
                        if (editable) {
                            textarea.removeAttribute('readonly');
                            textarea.classList.remove('bg-slate-50');
                            textarea.classList.add('bg-white');
                            editButton.textContent = 'Bloquear outline';
                            textarea.focus();
                            textarea.select();
                        } else {
                            textarea.setAttribute('readonly', 'readonly');
                            textarea.classList.remove('bg-white');
                            textarea.classList.add('bg-slate-50');
                            editButton.textContent = 'Editar outline';
                        }
                    });
                });

                if (form && serializedField) {
                    form.addEventListener('submit', function() {
                        var models = [];
                        document.querySelectorAll('details[data-prompt-model-card]').forEach(function(card) {
                            var keyInput = card.getAttribute('data-prompt-model-key') || '';
                            var nameInput = card.getAttribute('data-prompt-model-name') || '';
                            var descriptionInput = card.getAttribute('data-prompt-model-description') || '';
                            var outlineKeyInput = card.getAttribute('data-prompt-outline-key') || '';
                            var seoTextarea = card.querySelector('[data-prompt-seo-template]');
                            var contentTextarea = card.querySelector('[data-prompt-content-template]');

                            if (keyInput === '' || nameInput === '' || outlineKeyInput === '' || !seoTextarea || !contentTextarea) {
                                return;
                            }

                            models.push({
                                key: keyInput,
                                name: nameInput,
                                description: descriptionInput,
                                outline_model_key: outlineKeyInput,
                                outline_prompt_template: '',
                                seo_prompt_template: seoTextarea.value || '',
                                content_prompt_template: contentTextarea.value || ''
                            });
                        });

                        serializedField.value = models.length ? JSON.stringify(models) : '';
                    });
                }
            });
        </script>
<?php

        echo ob_get_clean();
    }
}
