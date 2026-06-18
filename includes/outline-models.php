<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Alpha_RSS_AI_Outline_Models')) {
    final class Alpha_RSS_AI_Outline_Models
    {
        public const OPTION_KEY = 'alpha_rss_ai_outline_models';

        public function __construct()
        {
            add_action('admin_menu', array($this, 'admin_menu'), 21);
            add_action('admin_post_arc_save_outline_model', array($this, 'handle_save_model'));
            add_action('admin_post_arc_delete_outline_model', array($this, 'handle_delete_model'));
        }

        public static function get_default_models()
        {
            return Alpha_RSS_AI_Generator::get_default_outline_models();
        }

        public static function get_models()
        {
            $models = get_option(self::OPTION_KEY, array());
            if (!is_array($models) || empty($models)) {
                $models = self::get_default_models();
            }

            return Alpha_RSS_AI_Generator::normalize_outline_models($models);
        }

        public function admin_menu()
        {
            add_submenu_page(
                'alpha-rss-ai-generator',
                'Modelos de outline',
                'Modelos de outline',
                'manage_options',
                'alpha-rss-ai-outline-models',
                array($this, 'render_page')
            );
        }

        protected static function unique_key($base_key, $existing_keys, $original_key = '')
        {
            $base_key = sanitize_key((string) $base_key);
            if ($base_key === '') {
                $base_key = 'outline-model';
            }

            if ($original_key !== '' && $base_key === $original_key) {
                return $base_key;
            }

            $candidate = $base_key;
            $suffix = 2;
            while (in_array($candidate, $existing_keys, true) && $candidate !== $original_key) {
                $candidate = $base_key . '-' . $suffix;
                $suffix++;
            }

            return $candidate;
        }

        protected static function parse_blocks_from_raw($raw_blocks)
        {
            if (is_array($raw_blocks)) {
                return $raw_blocks;
            }

            $raw_blocks = trim((string) $raw_blocks);
            if ($raw_blocks === '') {
                return array();
            }

            $decoded = json_decode($raw_blocks, true);
            return is_array($decoded) ? $decoded : array();
        }

        public function handle_save_model()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            check_admin_referer('arc_save_outline_model', 'arc_outline_model_nonce');

            $raw = isset($_POST) ? wp_unslash($_POST) : array();
            $models = self::get_models();
            $existing_keys = array();
            foreach ($models as $model) {
                if (!empty($model['key'])) {
                    $existing_keys[] = (string) $model['key'];
                }
            }

            $original_key = isset($raw['original_key']) ? sanitize_key((string) $raw['original_key']) : '';
            $model_key = isset($raw['model_key']) ? sanitize_key((string) $raw['model_key']) : '';
            $model_name = isset($raw['model_name']) ? sanitize_text_field((string) $raw['model_name']) : '';
            if ($model_key === '') {
                $model_key = sanitize_title($model_name);
            }
            $model_key = self::unique_key($model_key, $existing_keys, $original_key);

            $model = array(
                'key' => $model_key,
                'name' => $model_name !== '' ? $model_name : ucwords(str_replace(array('-', '_'), ' ', $model_key)),
                'description' => isset($raw['model_description']) ? sanitize_textarea_field((string) $raw['model_description']) : '',
                'target_h2_min' => isset($raw['target_h2_min']) ? max(1, intval($raw['target_h2_min'])) : 3,
                'target_h2_max' => isset($raw['target_h2_max']) ? max(1, intval($raw['target_h2_max'])) : 3,
                'blocks' => array(),
            );

            if ($model['target_h2_min'] <= 0 && $model['target_h2_max'] <= 0 && isset($raw['target_h2_count'])) {
                $count = max(1, intval($raw['target_h2_count']));
                $model['target_h2_min'] = $count;
                $model['target_h2_max'] = $count;
            }

            $blocks_raw = isset($raw['blocks_json']) ? (string) $raw['blocks_json'] : '[]';
            $blocks = self::parse_blocks_from_raw($blocks_raw);
            if (!empty($blocks)) {
                foreach ($blocks as $block) {
                    $model['blocks'][] = Alpha_RSS_AI_Generator::normalize_outline_block_definition($block);
                }
            }

            if (empty($model['blocks'])) {
                $model['blocks'][] = array(
                    'type' => 'intro_without_h2',
                    'label' => 'Introducao sem H2',
                    'notes' => '',
                    'quantity_min' => 1,
                    'quantity_max' => 1,
                );
                $model['blocks'][] = array(
                    'type' => 'h2',
                    'label' => 'H2 principal',
                    'notes' => '',
                    'quantity_min' => 1,
                    'quantity_max' => 1,
                );
                $model['blocks'][] = array(
                    'type' => 'paragraph',
                    'label' => 'Paragrafo',
                    'notes' => '',
                    'quantity_min' => 1,
                    'quantity_max' => 2,
                );
                $model['blocks'][] = array(
                    'type' => 'conclusion',
                    'label' => 'Conclusao',
                    'notes' => '',
                    'quantity_min' => 1,
                    'quantity_max' => 1,
                );
            }

            $models_by_key = array();
            foreach ($models as $existing_model) {
                if (empty($existing_model['key'])) {
                    continue;
                }
                $models_by_key[(string) $existing_model['key']] = $existing_model;
            }

            if ($original_key !== '' && isset($models_by_key[$original_key]) && $original_key !== $model_key) {
                unset($models_by_key[$original_key]);
            }

            $models_by_key[$model_key] = Alpha_RSS_AI_Generator::normalize_outline_model_definition($model);
            update_option(self::OPTION_KEY, array_values($models_by_key), false);

            wp_safe_redirect(add_query_arg(array(
                'page' => 'alpha-rss-ai-outline-models',
                'arc_notice' => 'saved',
            ), admin_url('admin.php')));
            exit;
        }

        public function handle_delete_model()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            check_admin_referer('arc_delete_outline_model', 'arc_outline_model_nonce');

            $model_key = isset($_POST['model_key']) ? sanitize_key((string) wp_unslash($_POST['model_key'])) : '';
            if ($model_key === '') {
                wp_safe_redirect(add_query_arg(array(
                    'page' => 'alpha-rss-ai-outline-models',
                    'arc_notice' => 'invalid',
                ), admin_url('admin.php')));
                exit;
            }

            $models = self::get_models();
            $updated = array();
            foreach ($models as $model) {
                if (!empty($model['key']) && (string) $model['key'] === $model_key) {
                    continue;
                }
                $updated[] = $model;
            }

            update_option(self::OPTION_KEY, $updated, false);

            wp_safe_redirect(add_query_arg(array(
                'page' => 'alpha-rss-ai-outline-models',
                'arc_notice' => 'deleted',
            ), admin_url('admin.php')));
            exit;
        }

        protected function render_notice()
        {
            $notice = isset($_GET['arc_notice']) ? sanitize_key((string) wp_unslash($_GET['arc_notice'])) : '';
            if ($notice === '') {
                return;
            }

            $messages = array(
                'saved' => array('success', 'Modelo salvo com sucesso.'),
                'deleted' => array('success', 'Modelo removido com sucesso.'),
                'invalid' => array('error', 'Nao foi possivel executar a acao solicitada.'),
            );

            if (!isset($messages[$notice])) {
                return;
            }

            list($type, $message) = $messages[$notice];
            $classes = 'mb-6 rounded-2xl border px-4 py-3 text-sm';
            $classes .= $type === 'success' ? ' border-emerald-200 bg-emerald-50 text-emerald-700' : ' border-rose-200 bg-rose-50 text-rose-700';
            echo '<div class="' . esc_attr($classes) . '">' . esc_html($message) . '</div>';
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            $models = self::get_models();
            $models_json = wp_json_encode(array_values($models));
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
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Modelos de outline</h1>
                        <p class="mt-2 max-w-3xl text-sm text-slate-600">Crie e organize modelos visuais de estrutura para usar na geracao de conteudo por etapas.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=alpha-rss-ai-generator')); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50">Ir para geradores</a>
                        <button type="button" id="arc-outline-open-new" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Novo modelo</button>
                    </div>
                </div>

                <?php $this->render_notice(); ?>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Modelos salvos</h2>
                            <p class="mt-1 text-sm text-slate-500">Edite a ordem dos blocos, a quantidade alvo de H2 e a descricao de cada modelo.</p>
                        </div>
                        <div class="text-sm text-slate-500"><?php echo esc_html(count($models)); ?> modelo(s)</div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th class="px-6 py-3">Nome</th>
                                    <th class="px-6 py-3">Chave</th>
                                    <th class="px-6 py-3">H2 alvo</th>
                                    <th class="px-6 py-3">Blocos</th>
                                    <th class="px-6 py-3">Descricao</th>
                                    <th class="px-6 py-3">Acoes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php if (empty($models)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-10 text-center text-sm text-slate-500">Nenhum modelo criado ainda.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($models as $model): ?>
                                        <tr class="align-top">
                                            <td class="px-6 py-4">
                                                <div class="font-semibold text-slate-950"><?php echo esc_html(isset($model['name']) ? $model['name'] : ''); ?></div>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(isset($model['key']) ? $model['key'] : ''); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700">
                                                <?php
                                                $target_h2_range = Alpha_RSS_AI_Generator::get_outline_model_target_h2_range($model);
                                                echo esc_html($target_h2_range['min'] === $target_h2_range['max'] ? (string) $target_h2_range['min'] : ($target_h2_range['min'] . '-' . $target_h2_range['max']));
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html((isset($model['blocks']) && is_array($model['blocks'])) ? count($model['blocks']) : 0); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-600"><?php echo esc_html(isset($model['description']) ? $model['description'] : ''); ?></td>
                                            <td class="px-6 py-4">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" data-outline-edit-model="<?php echo esc_attr(isset($model['key']) ? $model['key'] : ''); ?>" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Editar</button>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-swal-confirm="Excluir este modelo?">
                                                        <?php wp_nonce_field('arc_delete_outline_model', 'arc_outline_model_nonce'); ?>
                                                        <input type="hidden" name="action" value="arc_delete_outline_model" />
                                                        <input type="hidden" name="model_key" value="<?php echo esc_attr(isset($model['key']) ? $model['key'] : ''); ?>" />
                                                        <button type="submit" class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100">Excluir</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="arc-outline-modal" class="fixed inset-0 z-50 hidden">
                    <div id="arc-outline-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                    <div class="relative mx-auto flex min-h-full max-w-7xl items-start px-4 py-8 sm:px-6 lg:px-8">
                        <div class="absolute right-8 top-8 z-10">
                            <button type="button" id="arc-outline-close" class="rounded-full bg-white/90 p-2 text-slate-500 shadow-soft transition hover:bg-white hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <section class="w-full max-h-[calc(100vh-4rem)] overflow-y-auto overscroll-contain rounded-2xl border border-slate-200 bg-white shadow-soft">
                            <div class="border-b border-slate-200 px-6 py-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h2 id="arc-outline-title" class="text-lg font-semibold text-slate-950">Novo modelo</h2>
                                        <p class="mt-1 text-sm text-slate-500">Monte a estrutura visual do conteudo e salve como preset.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid gap-0 lg:grid-cols-[1fr_1fr]">
                                <form id="arc-outline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="border-b border-slate-200 p-6 lg:border-b-0 lg:border-r">
                                    <?php wp_nonce_field('arc_save_outline_model', 'arc_outline_model_nonce'); ?>
                                    <input type="hidden" name="action" value="arc_save_outline_model" />
                                    <input type="hidden" name="original_key" value="" />
                                    <input type="hidden" name="blocks_json" value="" />

                                    <div class="grid gap-4">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Nome</label>
                                            <input type="text" name="model_name" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Lista / ranking" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Chave</label>
                                            <input type="text" name="model_key" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="lista-ranking" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Descricao</label>
                                            <textarea name="model_description" rows="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Explique quando usar este modelo."></textarea>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Quantidade alvo de H2</label>
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Mín.</label>
                                                    <input type="number" min="1" name="target_h2_min" value="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Máx.</label>
                                                    <input type="number" min="1" name="target_h2_max" value="3" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                                </div>
                                            </div>
                                            <p class="mt-2 text-xs text-slate-500">Ex.: 2-4 para variar automaticamente por post.</p>
                                        </div>
                                    </div>

                                    <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <h3 class="text-sm font-semibold text-slate-950">Blocos disponiveis</h3>
                                                <p class="mt-1 text-xs text-slate-500">Clique para adicionar blocos ao modelo.</p>
                                            </div>
                                        </div>
                                        <div id="arc-outline-palette" class="mt-4 flex flex-wrap gap-2"></div>
                                    </div>

                                    <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <h3 class="text-sm font-semibold text-slate-950">Blocos do modelo</h3>
                                                <p class="mt-1 text-xs text-slate-500">Reordene e ajuste cada bloco do outline.</p>
                                            </div>
                                            <button type="button" id="arc-outline-add-block" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Adicionar bloco</button>
                                        </div>
                                        <div id="arc-outline-blocks" class="mt-4 space-y-3"></div>
                                    </div>

                                    <div class="mt-6 flex items-center gap-3 border-t border-slate-200 pt-5">
                                        <button type="button" id="arc-outline-cancel" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</button>
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Salvar modelo</button>
                                    </div>
                                </form>

                                <div class="p-6">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <h3 class="text-sm font-semibold text-slate-950">Preview do outline</h3>
                                        <p class="mt-1 text-xs text-slate-500">A previsualizacao ajuda a conferir a estrutura antes de salvar.</p>
                                        <pre id="arc-outline-preview" class="mt-4 whitespace-pre-wrap break-words rounded-xl border border-slate-200 bg-white p-4 text-xs leading-6 text-slate-700"></pre>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>

            <script>
                (function() {
                    var models = <?php echo $models_json ? $models_json : '[]'; ?>;
                    var blockTypes = [
                        { value: 'intro', label: 'Introducao' },
                        { value: 'intro_with_title', label: 'Introducao com titulo' },
                        { value: 'intro_without_h2', label: 'Introducao sem H2' },
                        { value: 'h2', label: 'H2' },
                        { value: 'paragraph', label: 'Paragrafo' },
                        { value: 'list', label: 'Lista' },
                        { value: 'bullet', label: 'Bullet' },
                        { value: 'table', label: 'Tabela' },
                        { value: 'image', label: 'Imagem' },
                        { value: 'button', label: 'Botao' },
                        { value: 'conclusion', label: 'Conclusao' }
                    ];
                    var modal = document.getElementById('arc-outline-modal');
                    var backdrop = document.getElementById('arc-outline-backdrop');
                    var titleEl = document.getElementById('arc-outline-title');
                    var openNewButton = document.getElementById('arc-outline-open-new');
                    var closeButton = document.getElementById('arc-outline-close');
                    var cancelButton = document.getElementById('arc-outline-cancel');
                    var form = document.getElementById('arc-outline-form');
                    var palette = document.getElementById('arc-outline-palette');
                    var blocksWrapper = document.getElementById('arc-outline-blocks');
                    var preview = document.getElementById('arc-outline-preview');
                    var currentBlocks = [];

                    function openModal() {
                        modal.classList.remove('hidden');
                        document.body.classList.add('overflow-hidden');
                    }

                    function closeModal() {
                        modal.classList.add('hidden');
                        document.body.classList.remove('overflow-hidden');
                    }

                    function byName(name) {
                        return form.querySelector('[name="' + name + '"]');
                    }

                    function defaultBlock(type) {
                        var found = blockTypes.find(function(item) {
                            return item.value === type;
                        }) || blockTypes[0];
                        return {
                            type: found.value,
                            label: found.label,
                            notes: '',
                            quantity_min: 1,
                            quantity_max: 1
                        };
                    }

                    function syncBlocksField() {
                        var field = byName('blocks_json');
                        if (field) {
                            field.value = JSON.stringify(currentBlocks);
                        }
                    }

                    function renderPreview() {
                        var lines = [];
                        var modelName = (byName('model_name') && byName('model_name').value) ? byName('model_name').value : 'Novo modelo';
                        var description = (byName('model_description') && byName('model_description').value) ? byName('model_description').value : '';
                        var targetH2Min = parseInt((byName('target_h2_min') && byName('target_h2_min').value) ? byName('target_h2_min').value : '0', 10) || 0;
                        var targetH2Max = parseInt((byName('target_h2_max') && byName('target_h2_max').value) ? byName('target_h2_max').value : '0', 10) || 0;
                        if (!targetH2Min && targetH2Max) {
                            targetH2Min = targetH2Max;
                        }
                        if (!targetH2Max && targetH2Min) {
                            targetH2Max = targetH2Min;
                        }
                        if (targetH2Max < targetH2Min) {
                            var tempH2 = targetH2Min;
                            targetH2Min = targetH2Max;
                            targetH2Max = tempH2;
                        }
                        var targetH2Label = targetH2Min === targetH2Max ? String(Math.max(1, targetH2Min || 1)) : (Math.max(1, targetH2Min || 1) + '-' + Math.max(1, targetH2Max || 1));
                        lines.push('Nome do modelo: ' + modelName);
                        if (description) {
                            lines.push('Descricao: ' + description);
                        }
                        lines.push('Quantidade alvo de H2: ' + targetH2Label);
                        lines.push('Estrutura do outline:');
                        if (!currentBlocks.length) {
                            lines.push('1. Introducao sem H2');
                            lines.push('2. H2 (repetir ' + Math.max(1, targetH2Min || 1) + 'x)');
                            lines.push('3. Paragrafo (quantidade 1x)');
                        } else {
                            currentBlocks.forEach(function(block, index) {
                                var prefix = (index + 1) + '. ' + (block.label || block.type || 'Bloco');
                                if (block.notes) {
                                    prefix += ' - ' + block.notes;
                                }
                                if (block.type === 'h2') {
                                    prefix += ' (repetir ' + Math.max(1, targetH2Min || 1) + 'x)';
                                } else {
                                    var quantityMin = parseInt(block.quantity_min || '1', 10) || 1;
                                    var quantityMax = parseInt(block.quantity_max || '1', 10) || 1;
                                    if (quantityMax < quantityMin) {
                                        var quantityTemp = quantityMin;
                                        quantityMin = quantityMax;
                                        quantityMax = quantityTemp;
                                    }
                                    if (quantityMin > 1 || quantityMax > 1) {
                                        var quantityLabel = quantityMin === quantityMax ? String(quantityMin) : (quantityMin + '-' + quantityMax);
                                        prefix += ' (quantidade ' + quantityLabel + 'x)';
                                    }
                                }
                                lines.push(prefix);
                            });
                        }
                        if (preview) {
                            preview.textContent = lines.join('\n');
                        }
                    }

                    function renderBlocks() {
                        if (!blocksWrapper) {
                            return;
                        }
                        blocksWrapper.innerHTML = '';
                        currentBlocks.forEach(function(block, index) {
                            var row = document.createElement('div');
                            row.className = 'rounded-2xl border border-slate-200 bg-white p-4 shadow-sm';
                            row.innerHTML = [
                                '<div class="grid gap-3 md:grid-cols-[180px_1fr]">',
                                '  <div>',
                                '    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Tipo</label>',
                                '    <select data-block-field="type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>',
                                '  </div>',
                            '  <div>',
                            '    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Titulo</label>',
                            '    <input data-block-field="label" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />',
                            '  </div>',
                            '  <div class="grid gap-3 md:col-span-2 md:grid-cols-2">',
                            '    <div>',
                            '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Qtd mín.</label>',
                            '      <input data-block-field="quantity_min" type="number" min="1" value="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />',
                            '    </div>',
                            '    <div>',
                            '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Qtd máx.</label>',
                            '      <input data-block-field="quantity_max" type="number" min="1" value="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />',
                            '    </div>',
                            '  </div>',
                            '  <p class="md:col-span-2 text-xs text-slate-500">Use para repetir este bloco, por exemplo 2-4 parágrafos.</p>',
                            '  <div class="md:col-span-2">',
                            '    <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Notas</label>',
                            '    <input data-block-field="notes" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />',
                            '  </div>',
                            '</div>',
                                '<div class="mt-3 flex flex-wrap items-center gap-2">',
                                '  <button type="button" data-block-action="up" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Subir</button>',
                                '  <button type="button" data-block-action="down" class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Descer</button>',
                                '  <button type="button" data-block-action="remove" class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100">Remover</button>',
                                '</div>'
                            ].join('');

                            var typeSelect = row.querySelector('[data-block-field="type"]');
                            var labelInput = row.querySelector('[data-block-field="label"]');
                            var quantityMinInput = row.querySelector('[data-block-field="quantity_min"]');
                            var quantityMaxInput = row.querySelector('[data-block-field="quantity_max"]');
                            var notesInput = row.querySelector('[data-block-field="notes"]');

                            blockTypes.forEach(function(option) {
                                var opt = document.createElement('option');
                                opt.value = option.value;
                                opt.textContent = option.label;
                                if (option.value === block.type) {
                                    opt.selected = true;
                                }
                                typeSelect.appendChild(opt);
                            });

                            typeSelect.addEventListener('change', function() {
                                currentBlocks[index].type = typeSelect.value;
                                if (!currentBlocks[index].label) {
                                    currentBlocks[index].label = blockTypes.find(function(item) {
                                        return item.value === typeSelect.value;
                                    }).label;
                                    labelInput.value = currentBlocks[index].label;
                                }
                                syncBlocksField();
                                renderPreview();
                            });
                            labelInput.addEventListener('input', function() {
                                currentBlocks[index].label = labelInput.value;
                                syncBlocksField();
                                renderPreview();
                            });
                            quantityMinInput.addEventListener('input', function() {
                                currentBlocks[index].quantity_min = quantityMinInput.value;
                                syncBlocksField();
                                renderPreview();
                            });
                            quantityMaxInput.addEventListener('input', function() {
                                currentBlocks[index].quantity_max = quantityMaxInput.value;
                                syncBlocksField();
                                renderPreview();
                            });
                            notesInput.addEventListener('input', function() {
                                currentBlocks[index].notes = notesInput.value;
                                syncBlocksField();
                                renderPreview();
                            });

                            row.querySelector('[data-block-action="up"]').addEventListener('click', function() {
                                if (index <= 0) {
                                    return;
                                }
                                var item = currentBlocks.splice(index, 1)[0];
                                currentBlocks.splice(index - 1, 0, item);
                                renderBlocks();
                                syncBlocksField();
                                renderPreview();
                            });

                            row.querySelector('[data-block-action="down"]').addEventListener('click', function() {
                                if (index >= currentBlocks.length - 1) {
                                    return;
                                }
                                var item = currentBlocks.splice(index, 1)[0];
                                currentBlocks.splice(index + 1, 0, item);
                                renderBlocks();
                                syncBlocksField();
                                renderPreview();
                            });

                            row.querySelector('[data-block-action="remove"]').addEventListener('click', function() {
                                currentBlocks.splice(index, 1);
                                renderBlocks();
                                syncBlocksField();
                                renderPreview();
                            });

                            typeSelect.value = block.type || 'paragraph';
                            labelInput.value = block.label || '';
                            quantityMinInput.value = block.quantity_min || 1;
                            quantityMaxInput.value = block.quantity_max || 1;
                            notesInput.value = block.notes || '';

                            blocksWrapper.appendChild(row);
                        });

                        if (!currentBlocks.length) {
                            var empty = document.createElement('div');
                            empty.className = 'rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500';
                            empty.textContent = 'Nenhum bloco adicionado ainda.';
                            blocksWrapper.appendChild(empty);
                        }
                    }

                    function renderPalette() {
                        if (!palette) {
                            return;
                        }
                        palette.innerHTML = '';
                        blockTypes.forEach(function(type) {
                            var button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50';
                            button.textContent = type.label;
                            button.addEventListener('click', function() {
                                currentBlocks.push(defaultBlock(type.value));
                                renderBlocks();
                                syncBlocksField();
                                renderPreview();
                            });
                            palette.appendChild(button);
                        });
                    }

                    function resetForm() {
                        form.reset();
                        byName('original_key').value = '';
                        currentBlocks = [
                            defaultBlock('intro_without_h2'),
                            defaultBlock('h2'),
                            defaultBlock('paragraph')
                        ];
                        renderBlocks();
                        syncBlocksField();
                        renderPreview();
                        titleEl.textContent = 'Novo modelo';
                    }

                    function loadModel(modelKey) {
                        var model = models.find(function(item) {
                            return String(item.key) === String(modelKey);
                        });
                        if (!model) {
                            return;
                        }

                        byName('original_key').value = model.key || '';
                        byName('model_name').value = model.name || '';
                        byName('model_key').value = model.key || '';
                        byName('model_description').value = model.description || '';
                        byName('target_h2_min').value = model.target_h2_min || model.target_h2_count || 3;
                        byName('target_h2_max').value = model.target_h2_max || model.target_h2_count || 3;
                        currentBlocks = Array.isArray(model.blocks) ? model.blocks.map(function(block) {
                            return {
                                type: block.type || 'paragraph',
                                label: block.label || '',
                                notes: block.notes || '',
                                quantity_min: block.quantity_min || 1,
                                quantity_max: block.quantity_max || 1
                            };
                        }) : [];
                        renderBlocks();
                        syncBlocksField();
                        renderPreview();
                        titleEl.textContent = 'Editar modelo';
                    }

                    document.getElementById('arc-outline-open-new').addEventListener('click', function() {
                        resetForm();
                        openModal();
                    });

                    document.querySelectorAll('[data-outline-edit-model]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            resetForm();
                            loadModel(button.getAttribute('data-outline-edit-model'));
                            openModal();
                        });
                    });

                    document.getElementById('arc-outline-add-block').addEventListener('click', function() {
                        currentBlocks.push(defaultBlock('paragraph'));
                        renderBlocks();
                        syncBlocksField();
                        renderPreview();
                    });

                    [byName('model_name'), byName('model_key'), byName('model_description'), byName('target_h2_min'), byName('target_h2_max')].forEach(function(field) {
                        if (!field) {
                            return;
                        }
                        field.addEventListener('input', renderPreview);
                        field.addEventListener('change', renderPreview);
                    });

                    closeButton.addEventListener('click', closeModal);
                    cancelButton.addEventListener('click', closeModal);
                    backdrop.addEventListener('click', closeModal);
                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                            closeModal();
                        }
                    });

                    renderPalette();
                    resetForm();
                })();
            </script>
            <?php
        }
    }
}
