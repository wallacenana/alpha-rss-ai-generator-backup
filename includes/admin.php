<?php

if (!defined('ABSPATH')) {
    exit;
}

class Alpha_RSS_AI_Generator_Admin
{
    public function __construct()
    {
        add_action('admin_notices', array(__CLASS__, 'render_notice'));
    }

    public function admin_menu()
    {
        add_menu_page(
            'Gerador Alpha RSS AI',
            'Gerador Alpha RSS AI',
            'manage_options',
            'alpha-rss-ai-generator',
            array($this, 'render_admin_page'),
            'dashicons-rss',
            31
        );
        remove_submenu_page('alpha-rss-ai-generator', 'alpha-rss-ai-generator');
        add_submenu_page(
            'alpha-rss-ai-generator',
            'Geradores',
            'Geradores',
            'manage_options',
            'alpha-rss-ai-generator',
            array($this, 'render_admin_page')
        );
        add_submenu_page(
            'alpha-rss-ai-generator',
            'Importação',
            'Importação',
            'manage_options',
            'alpha-rss-ai-keyword-lists',
            array($this, 'render_keyword_lists_page')
        );
    }

    public function admin_menu_late()
    {
        add_submenu_page(
            'alpha-rss-ai-generator',
            'Configurações',
            'Configurações',
            'manage_options',
            'alpha-rss-ai-global-settings',
            array($this, 'render_global_settings_page'),
            999
        );
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $settings = Alpha_RSS_AI_Generator::get_settings();
        $generators = Alpha_RSS_AI_Generator::get_generators(200);
        $keyword_lists = Alpha_RSS_AI_Generator::get_keyword_lists(200);
        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $editing_generator = $edit_id > 0 ? Alpha_RSS_AI_Generator::get_generator($edit_id) : array();

        $users = Alpha_RSS_AI_Generator::get_content_author_users();
        $categories = get_categories(array('hide_empty' => false));
        $log_rows = Alpha_RSS_AI_Generator::get_recent_runs(30);

        ob_start();

?>
        <style>
        </style>
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
            <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between w-3xl">
                <div class="w-3xl">
                    <div class="text-xs font-semibold text-indigo-600">Alpha RSS AI</div>
                    <h1 class="mt-2 text-lg font-semibold tracking-tight text-slate-950">Configurações globais</h1>
                </div>
                <div class="flex flex-wrap items-center gap-3 w-3xl">
                    <button type="button" data-open-generator-import-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50" aria-label="Importar gerador" title="Importar gerador">
                        <span class="dashicons dashicons-download text-[18px] leading-none"></span>
                        <span class="sr-only">Importar gerador</span>
                    </button>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mb-0">
                        <?php wp_nonce_field('arc_export_generators', 'arc_export_generators_nonce'); ?>
                        <input type="hidden" name="action" value="arc_export_generators" />
                        <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50" aria-label="Exportar geradores" title="Exportar geradores">
                            <span class="dashicons dashicons-upload text-[18px] leading-none"></span>
                            <span class="sr-only">Exportar geradores</span>
                        </button>
                    </form>
                    <button type="button" data-open-generator-modal class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Adicionar gerador</button>
                </div>
            </div>

            <div class="space-y-6">
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Tabela de geradores</h2>
                        </div>
                        <div class="text-sm text-slate-500">
                            <?php echo esc_html(count($generators)); ?> gerador(es)
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th class="px-6 py-3">Nome</th>
                                    <th class="px-6 py-3">Status</th>
                                    <th class="px-6 py-3">Categoria</th>
                                    <th class="px-6 py-3">Agendamento</th>
                                    <th class="px-6 py-3">Próxima execução</th>
                                    <th class="px-6 py-3">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php if (empty($generators)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-10 text-center text-sm text-slate-500">Nenhum gerador criado ainda.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($generators as $generator): ?>
                                        <?php
                                        $generator_status_label = Alpha_RSS_AI_Generator::get_generator_status_label($generator['status']);
                                        $schedule_label = Alpha_RSS_AI_Generator::get_schedule_type_label($generator['schedule_type']);
                                        $generation_mode_label = Alpha_RSS_AI_Generator::get_generation_mode_label(isset($generator['generation_mode']) ? $generator['generation_mode'] : Alpha_RSS_AI_Generator::get_default_generation_mode());
                                        $category_label = '-';
                                        $generator_category_ids = array();
                                        if (isset($generator['category_ids'])) {
                                            $decoded_category_ids = json_decode((string) $generator['category_ids'], true);
                                            $generator_category_ids = is_array($decoded_category_ids) ? array_values(array_filter(array_map('intval', $decoded_category_ids))) : array();
                                        }
                                        if (!empty($generator_category_ids)) {
                                            $category_names = array();
                                            foreach ($generator_category_ids as $category_id) {
                                                $category = get_term($category_id, 'category');
                                                if ($category && !is_wp_error($category)) {
                                                    $category_names[] = $category->name;
                                                }
                                            }
                                            if (!empty($category_names)) {
                                                $category_label = implode(', ', $category_names);
                                            }
                                        }

                                        ?>
                                        <tr class="align-top">
                                            <td class="px-6 py-4">
                                                <div class="font-semibold text-slate-950"><?php echo esc_html($generator['name']); ?></div>
                                                <div class="mt-1 break-all text-sm text-slate-500">
                                                    <?php if (!empty($generator['source_type']) && $generator['source_type'] === 'keyword_list'): ?>
                                                        <?php
                                                        $linked_list = null;
                                                        foreach ($keyword_lists as $candidate_list) {
                                                            if (intval($candidate_list['id']) === intval($generator['list_id'])) {
                                                                $linked_list = $candidate_list;
                                                                break;
                                                            }
                                                        }
                                                        ?>
                                                        <?php echo esc_html($linked_list ? $linked_list['list_name'] : 'Lista de palavras-chave'); ?>
                                                    <?php else: ?>
                                                        <?php echo esc_html($generator['feed_url']); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mt-1 text-xs text-slate-400">Modo: <?php echo esc_html($generation_mode_label); ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $generator['status'] === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'; ?>">
                                                    <?php echo esc_html($generator_status_label); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html($category_label); ?></td>
                                            <td class="px-6 py-4">
                                                <div class="text-sm font-medium text-slate-700"><?php echo esc_html($schedule_label); ?></div>
                                                <div class="mt-1 text-xs text-slate-500">
                                                    A cada <?php echo esc_html($generator['interval_minutes']); ?> min + variação <?php echo esc_html($generator['jitter_minutes']); ?> min
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-600"><?php echo esc_html($generator['next_run_at'] ?: '-'); ?></td>
                                            <td class="px-6 py-4">
                                                <div class="arc-generator-actions flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        data-edit-generator-id="<?php echo esc_attr($generator['id']); ?>"
                                                        class="arc-generator-action-btn inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                                                        Editar
                                                    </button>
                                                    <button
                                                        type="button"
                                                        data-open-manual-run-modal
                                                        data-generator-id="<?php echo esc_attr($generator['id']); ?>"
                                                        data-generator-name="<?php echo esc_attr($generator['name']); ?>"
                                                        class="arc-generator-action-btn arc-generator-action-btn--primary inline-flex items-center justify-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-indigo-500">
                                                        Escolher item
                                                    </button>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                        <?php wp_nonce_field('arc_export_generator', 'arc_export_generator_nonce'); ?>
                                                        <input type="hidden" name="action" value="arc_export_generator" />
                                                        <input type="hidden" name="generator_id" value="<?php echo esc_attr($generator['id']); ?>" />
                                                        <button type="submit" class="arc-generator-action-btn inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Exportar</button>
                                                    </form>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                        <?php wp_nonce_field('arc_duplicate_generator', 'arc_duplicate_nonce'); ?>
                                                        <input type="hidden" name="action" value="arc_duplicate_generator" />
                                                        <input type="hidden" name="generator_id" value="<?php echo esc_attr($generator['id']); ?>" />
                                                        <button type="submit" class="arc-generator-action-btn inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Duplicar</button>
                                                    </form>
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-swal-confirm="Excluir este gerador?">
                                                        <?php wp_nonce_field('arc_delete_generator', 'arc_delete_nonce'); ?>
                                                        <input type="hidden" name="action" value="arc_delete_generator" />
                                                        <input type="hidden" name="generator_id" value="<?php echo esc_attr($generator['id']); ?>" />
                                                        <button type="submit" class="arc-generator-action-btn inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100">Excluir</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div id="arc-settings-modal" class="fixed inset-0 z-50 hidden">
                <div id="arc-settings-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-3xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">Configurações globais</h2>
                                <p class="mt-1 text-sm text-slate-500">Ajuste as credenciais e padrões usados por todos os geradores.</p>
                            </div>
                            <button type="button" data-close-settings-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="max-h-[calc(90vh-82px)] overflow-y-auto p-6">
                            <?php wp_nonce_field('arc_save_settings', 'arc_settings_nonce'); ?>
                            <input type="hidden" name="action" value="arc_save_settings" />
                            <div class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API da OpenAI</label>
                                    <input type="password" name="openai_api_key" value="<?php echo esc_attr($settings['openai_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API do Pexels</label>
                                    <input type="password" name="pexels_api_key" value="<?php echo esc_attr($settings['pexels_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Modelo padrão</label>
                                    <input type="text" name="default_model" value="<?php echo esc_attr($settings['default_model']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Temperatura padrão</label>
                                        <input type="number" step="0.1" min="0" max="2" name="default_temperature" value="<?php echo esc_attr($settings['default_temperature']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Máximo de tokens</label>
                                        <input type="number" min="256" name="default_max_tokens" value="<?php echo esc_attr($settings['default_max_tokens']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                </div>
                            </div>
                            <div class="mt-6 flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-slate-500">Esses valores viram padrão ao criar ou duplicar geradores.</p>
                                <div class="flex items-center gap-3">
                                    <button type="button" data-close-settings-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</button>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Salvar configurações</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div id="arc-runs-modal" class="fixed inset-0 z-50 hidden">
                <div id="arc-runs-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-4xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">Execuções recentes</h2>
                                <p class="mt-1 text-sm text-slate-500">Histórico das últimas execuções do sistema.</p>
                            </div>
                            <button type="button" data-close-runs-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <div class="max-h-[calc(90vh-82px)] overflow-y-auto p-6">
                            <div class="overflow-hidden rounded-2xl border border-slate-200">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-slate-200">
                                        <thead class="bg-slate-50">
                                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                <th class="px-5 py-3">Horário</th>
                                                <th class="px-5 py-3">Status</th>
                                                <th class="px-5 py-3">Mensagem</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 bg-white">
                                            <?php if (empty($log_rows)): ?>
                                                <tr>
                                                    <td colspan="3" class="px-5 py-8 text-center text-sm text-slate-500">Nenhuma execução registrada ainda.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($log_rows as $row): ?>
                                                    <tr class="align-top">
                                                        <td class="px-5 py-4 text-sm text-slate-600"><?php echo esc_html($row['created_at']); ?></td>
                                                        <td class="px-5 py-4">
                                                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $row['status'] === 'error' ? 'bg-rose-100 text-rose-700' : (($row['status'] === 'warning' || $row['status'] === 'info') ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'); ?>">
                                                                <?php echo esc_html(Alpha_RSS_AI_Generator::get_run_status_label($row['status'])); ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-5 py-4 text-sm text-slate-700">
                                                            <div><?php echo esc_html($row['message']); ?></div>
                                                            <?php $run_summary = Alpha_RSS_AI_Generator::format_run_log_summary($row); ?>
                                                            <?php if ($run_summary !== ''): ?>
                                                                <div class="mt-1 text-xs leading-5 text-slate-500"><?php echo esc_html($run_summary); ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="arc-manual-run-modal" class="fixed inset-0 z-50 hidden">
                <div id="arc-manual-run-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-5xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="arc-manual-run-title" class="text-xl font-semibold text-slate-950">Escolher item</h2>
                                <p id="arc-manual-run-subtitle" class="mt-1 text-sm text-slate-500">Escolha um item disponível para gerar um post único.</p>
                            </div>
                            <button type="button" data-close-manual-run-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-3">
                            <div class="text-sm text-slate-600">Itens disponíveis: <span id="arc-manual-run-count" class="font-semibold text-slate-950">0</span></div>
                            <button type="button" id="arc-manual-run-refresh" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Atualizar itens</button>
                        </div>
                        <div class="max-h-[calc(90vh-140px)] overflow-y-auto p-6">
                            <div id="arc-manual-run-status" class="hidden mb-4 rounded-xl border px-4 py-3 text-sm"></div>
                            <div id="arc-manual-run-loading" class="flex items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-10 text-sm text-slate-500">Carregando itens...</div>
                            <div id="arc-manual-run-empty" class="hidden rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-10 text-center text-sm text-slate-500">Nenhum item disponível. Todos os itens já foram processados.</div>
                            <div id="arc-manual-run-list" class="space-y-4"></div>
                            <form id="arc-manual-run-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="hidden">
                                <?php wp_nonce_field('arc_run_generator', 'arc_run_nonce'); ?>
                                <input type="hidden" name="action" value="arc_run_generator" />
                                <input type="hidden" name="generator_id" value="" />
                                <input type="hidden" name="item_guid" value="" />
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div id="arc-generator-import-modal" class="fixed inset-0 z-50 hidden">
                <div id="arc-generator-import-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-3xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">Importar gerador</h2>
                                <p class="mt-1 text-sm text-slate-500">Envie um JSON exportado de um gerador. O arquivo pode conter um item único ou uma lista de itens.</p>
                            </div>
                            <button type="button" data-close-generator-import-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="max-h-[calc(90vh-82px)] overflow-y-auto p-6" >
                            <?php wp_nonce_field('arc_import_generator', 'arc_import_generator_nonce'); ?>
                            <input type="hidden" name="action" value="arc_import_generators" />
                            <div class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Arquivo JSON</label>
                                    <input type="file" name="generator_json_file" accept=".json,application/json" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-white file:transition hover:file:bg-slate-800" />
                                </div>
                            </div>
                            <div class="mt-6 flex flex-col gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-sm text-slate-500">Envie um arquivo JSON exportado de um gerador. O import reaproveita os mesmos campos do formulário do gerador, incluindo prompts, outline e taxonomias.</p>
                                <div class="flex items-center gap-3">
                                    <button type="button" data-close-generator-import-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</button>
                                    <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Importar JSON</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div id="arc-generator-modal" class="fixed inset-0 z-50 hidden">
                <div id="arc-generator-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-7xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="arc-generator-modal-title" class="text-xl font-semibold text-slate-950">Adicionar gerador</h2>
                                <p class="mt-1 text-sm text-slate-500">Configure tudo aqui e salve sem sair da tabela.</p>
                            </div>
                            <button type="button" data-close-generator-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <form id="arc-generator-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="max-h-[calc(80vh-82px)] overflow-y-auto p-6">
                            <?php wp_nonce_field('arc_save_generator', 'arc_generator_nonce'); ?>
                            <input type="hidden" name="action" value="arc_save_generator" />
                            <input type="hidden" name="generator_id" value="<?php echo esc_attr(isset($editing_generator['id']) ? $editing_generator['id'] : ''); ?>" />

                            <div class="arc-generator-fields grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Nome</label>
                                    <input type="text" name="name" required value="<?php echo esc_attr(isset($editing_generator['name']) ? $editing_generator['name'] : ''); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Tipo de geração</label>
                                    <select name="generation_mode" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="pillar" <?php selected(isset($editing_generator['generation_mode']) ? $editing_generator['generation_mode'] : '', 'pillar'); ?>>Pilar</option>
                                        <option value="satellite" <?php selected(isset($editing_generator['generation_mode']) ? $editing_generator['generation_mode'] : '', 'satellite'); ?>>Satélite</option>
                                    </select>
                                </div>
                                <div <?php echo (!empty($editing_generator['generation_mode']) && Alpha_RSS_AI_Generator::normalize_generation_mode((string) $editing_generator['generation_mode']) === 'satellite') ? 'class="hidden"' : ''; ?>>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Fonte do gerador</label>
                                    <select name="source_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="keyword_list" <?php selected(isset($editing_generator['source_type']) ? $editing_generator['source_type'] : '', 'keyword_list'); ?>>Palavras-chave importadas</option>
                                        <option value="rss" <?php selected(isset($editing_generator['source_type']) ? $editing_generator['source_type'] : '', 'rss'); ?>>RSS</option>
                                    </select>
                                </div>
                                <div data-feed-url-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">URL do feed / fonte</label>
                                    <input type="url" name="feed_url" value="<?php echo esc_attr(isset($editing_generator['feed_url']) ? $editing_generator['feed_url'] : ''); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div data-list-id-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Lista de palavras-chave</label>
                                    <select name="list_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0" <?php selected(isset($editing_generator['list_id']) ? intval($editing_generator['list_id']) : 0, 0); ?>>Selecione uma lista</option>
                                        <?php foreach ($keyword_lists as $keyword_list): ?>
                                            <option value="<?php echo esc_attr($keyword_list['id']); ?>" <?php selected(isset($editing_generator['list_id']) ? intval($editing_generator['list_id']) : 0, intval($keyword_list['id'])); ?>><?php echo esc_html($keyword_list['list_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div data-keyword-list-mode-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Modo da lista</label>
                                    <select name="keyword_list_mode" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="keywords" <?php selected(isset($editing_generator['keyword_list_mode']) ? $editing_generator['keyword_list_mode'] : '', 'keywords'); ?>>Só palavras-chave</option>
                                        <option value="url_reference" <?php selected(isset($editing_generator['keyword_list_mode']) ? $editing_generator['keyword_list_mode'] : '', 'url_reference'); ?>>Palavra-chave + URL de referência</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Status do gerador</label>
                                    <select name="status" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="active" <?php selected(isset($editing_generator['status']) ? $editing_generator['status'] : '', 'active'); ?>>Ativo</option>
                                        <option value="inactive" <?php selected(isset($editing_generator['status']) ? $editing_generator['status'] : '', 'inactive'); ?>>Inativo</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Status do post</label>
                                    <select name="post_status" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <?php foreach (array('draft', 'publish', 'pending', 'private', 'future') as $status): ?>
                                            <option value="<?php echo esc_attr($status); ?>" <?php selected(isset($editing_generator['post_status']) ? $editing_generator['post_status'] : '', $status); ?>><?php echo esc_html(self::get_post_status_label($status)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Autor</label>
                                    <select name="author_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0" <?php selected(isset($editing_generator['author_id']) ? intval($editing_generator['author_id']) : 0, 0); ?>>Usuário atual</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo esc_attr($user->ID); ?>" <?php selected(isset($editing_generator['author_id']) ? intval($editing_generator['author_id']) : 0, intval($user->ID)); ?>><?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Posts por execução</label>
                                    <input type="number" min="1" name="posts_per_run" value="<?php echo esc_attr(isset($editing_generator['posts_per_run']) && $editing_generator['posts_per_run'] !== '' ? $editing_generator['posts_per_run'] : 1); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Tipo de agendamento</label>
                                    <select name="schedule_type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="interval">Intervalo + variação</option>
                                        <option value="daily_random">Janela diária aleatória</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Minutos de intervalo</label>
                                    <input type="number" min="1" name="interval_minutes" value="180" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Minutos de variação</label>
                                    <input type="number" min="0" name="jitter_minutes" value="30" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Início diário</label>
                                    <input type="text" name="daily_start" value="<?php echo esc_attr(isset($editing_generator['daily_start']) ? $editing_generator['daily_start'] : ''); ?>" placeholder="HH:MM" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Fim diário</label>
                                    <input type="text" name="daily_end" value="<?php echo esc_attr(isset($editing_generator['daily_end']) ? $editing_generator['daily_end'] : ''); ?>" placeholder="HH:MM" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Fonte da imagem</label>
                                    <select name="image_source_mode" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="rss">Fonte do RSS</option>
                                        <option value="rss_or_pexels">Fonte do RSS ou Pexels</option>
                                        <option value="rss_or_dalle">Fonte do RSS ou Dall-e</option>
                                        <option value="pexels">Pexels</option>
                                        <option value="dalle">Dall-e</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Consulta no Pexels</label>
                                    <input type="text" name="pexels_query" value="<?php echo esc_attr(Alpha_RSS_AI_Generator::get_default_pexels_query()); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Usar vídeo da fonte</label>
                                    <select name="source_video_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0" selected>Não</option>
                                        <option value="1">Sim</option>
                                    </select>
                                </div>
                                <div class="grid gap-4 md:col-span-2 md:grid-cols-2" data-rss-source-media-toggle-field>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Usar imagens da fonte</label>
                                        <select name="source_content_images_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                            <option value="1" selected>Sim</option>
                                            <option value="0">Não</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Usar links da fonte</label>
                                        <select name="source_content_links_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                            <option value="1" selected>Sim</option>
                                            <option value="0">Não</option>
                                        </select>
                                    </div>
                                </div>
                                <div data-rss-video-selector-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Classe do wrapper do vídeo</label>
                                    <input type="text" name="video_selector_class" placeholder="slide-key image-holder" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="grid gap-4 md:col-span-2 md:grid-cols-2" data-rss-source-selectors-field>
                                    <div data-rss-image-selector-field>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Classe da imagem da fonte</label>
                                        <input type="text" name="image_selector_class" placeholder="responsive-img img-article-square" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                    <div data-rss-link-selector-field>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Classe do link da fonte</label>
                                        <input type="text" name="link_selector_class" placeholder="affiliate-single" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Seletor do conteúdo da página</label>
                                    <input type="text" name="content_selector" placeholder="article-body, #article-body" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div data-rss-image-size-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Tamanho das imagens no conteúdo</label>
                                    <select name="content_image_size" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="thumbnail">Thumbnail</option>
                                        <option value="medium" selected>Médio</option>
                                        <option value="medium_large">Médio grande</option>
                                        <option value="large">Grande</option>
                                        <option value="full">Original</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2" data-rss-link-phrases-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Frases do link da fonte</label>
                                    <textarea name="source_link_phrases" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Assista na plataforma&#10;Veja no catálogo&#10;Confira a fonte"><?php echo esc_textarea(Alpha_RSS_AI_Generator::get_default_source_link_cta_phrases()); ?></textarea>
                                </div>
                                <div class="md:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4" data-rss-source-filters-field>
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-800">Filtros da fonte</label>
                                        </div>
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                                        <div class="md:col-span-2">
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Frases para excluir</label>
                                            <textarea name="source_context_exclude_phrases" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="IMDb - 4.8/10&#10;4.8/10&#10;Watch on Netflix"><?php echo esc_textarea(isset($editing_generator['source_context_exclude_phrases']) ? $editing_generator['source_context_exclude_phrases'] : ''); ?></textarea>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Rótulo da nota</label>
                                            <input type="text" name="source_context_rating_label" value="<?php echo esc_attr(isset($editing_generator['source_context_rating_label']) && $editing_generator['source_context_rating_label'] !== '' ? $editing_generator['source_context_rating_label'] : 'IMDb'); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Nota mínima</label>
                                            <input type="number" step="0.1" min="0" max="10" name="source_context_min_rating" value="<?php echo esc_attr(isset($editing_generator['source_context_min_rating']) && $editing_generator['source_context_min_rating'] !== '' ? $editing_generator['source_context_min_rating'] : '0'); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Manter sem nota</label>
                                            <select name="source_context_keep_unrated" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="1" <?php selected(isset($editing_generator['source_context_keep_unrated']) ? intval($editing_generator['source_context_keep_unrated']) : 0, 1); ?>>Sim</option>
                                                <option value="0" <?php selected(isset($editing_generator['source_context_keep_unrated']) ? intval($editing_generator['source_context_keep_unrated']) : 0, 0); ?>>Não</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">SEO ativado</label>
                                    <select name="seo_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="1">Sim</option>
                                        <option value="0">Não</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Linguagem final de geração</label>
                                    <input type="text" name="generation_language" value="<?php echo esc_attr(Alpha_RSS_AI_Generator::get_default_generation_language()); ?>" placeholder="Português do Brasil" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="hidden md:col-span-2 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-800">Sugestões de posts</label>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Ativar sugestões</label>
                                            <select name="related_posts_enabled" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 sm:w-44">
                                                <option value="1">Sim</option>
                                                <option value="0">Não</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Posição</label>
                                            <select name="related_posts_position" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="end">No final do conteúdo</option>
                                                <option value="paragraphs">A cada X parágrafos</option>
                                                <option value="words">A cada X palavras</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Intervalo</label>
                                            <input type="number" min="1" name="related_posts_interval" value="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Mínimo de H2</label>
                                            <input type="number" min="0" name="related_posts_min_h2" value="1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Quantidade por bloco</label>
                                            <input type="number" min="1" name="related_posts_links_per_block" value="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Apenas mesma categoria</label>
                                            <select name="related_posts_same_category_only" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="1">Sim</option>
                                                <option value="0">Não</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Permitir fallback</label>
                                            <select name="related_posts_allow_fallback" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="1">Sim</option>
                                                <option value="0">Não</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Estilo</label>
                                            <select name="related_posts_style" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                <option value="list">Lista</option>
                                                <option value="inline">Inline</option>
                                                <option value="cards">Cards</option>
                                            </select>
                                        </div>
                                        <div class="md:col-span-2">
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Frases do marcador</label>
                                            <textarea name="related_posts_phrases" rows="4" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Você também pode gostar de:\nLeia também:\nVeja também:"><?php echo esc_textarea(Alpha_RSS_AI_Generator::get_default_related_posts_phrases()); ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="md:col-span-2 grid gap-5 md:grid-cols-2 arc-generator-tax-grid essanao">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Categorias do WordPress</label>
                                        <div class="max-h-64 overflow-auto rounded-xl border border-slate-300 bg-white p-3" data-category-checkbox-list>
                                            <?php if (!empty($categories)): ?>
                                                <div class="space-y-2">
                                                    <?php foreach ($categories as $category): ?>
                                                        <label class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1 text-sm text-slate-700 transition hover:bg-slate-50">
                                                            <input type="checkbox" name="category_ids[]" value="<?php echo esc_attr($category->term_id); ?>" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                                            <span><?php echo esc_html($category->name); ?></span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-sm text-slate-500">Nenhuma categoria encontrada</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Tags do WordPress</label>
                                        <input type="text" name="tags_default" value="<?php echo esc_attr(isset($editing_generator['tags_default']) ? implode(', ', (array) json_decode((string) $editing_generator['tags_default'], true)) : ''); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="tag 1, tag 2, tag 3" />
                                    </div>
                                </div>
                                <div class="hidden md:col-span-2" data-default-category-field>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Categoria padrão</label>
                                    <select name="default_category_id" class="w-full max-w-sm rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                        <option value="0">Selecione uma categoria marcada</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2 w-full rounded-2xl essanao border border-slate-200 bg-slate-50 p-4" data-internal-links-field>
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <label class="block text-sm font-semibold text-slate-800">Links internos manuais</label>
                                        </div>
                                        <button type="button" data-add-internal-link class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Adicionar link</button>
                                    </div>
                                    <div class="mt-4 w-full space-y-3" data-internal-links-rows></div>
                                    <input type="hidden" name="internal_links_count" value="<?php echo esc_attr(isset($editing_generator['internal_links_count']) ? intval($editing_generator['internal_links_count']) : 0); ?>" data-internal-links-count />
                                    <textarea name="internal_links_json" class="hidden" data-internal-links-json></textarea>
                                </div>
                                <div class="essanao mt-6 grid w-full gap-4 border-t border-slate-200 pt-5 lg:grid-cols-[1fr_auto] lg:items-center">
                                    <div class="flex w-full items-center gap-3 lg:w-auto lg:justify-end">
                                        <button type="button" data-close-generator-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</button>
                                        <button id="arc-generator-submit" type="submit" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Salvar gerador</button>
                                    </div>
                                </div>
                        </form>
                    </div>
                </div>
            </div>

            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

            <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

            <script>
                (function() {
                    var generators = <?php echo wp_json_encode(array_values($generators)); ?>;
                    var defaults = <?php echo wp_json_encode(array(
                                        'generator_id' => '',
                                        'name' => '',
                                        'feed_url' => '',
                                        'source_type' => 'keyword_list',
                                        'generation_mode' => 'pillar',
                                        'list_id' => '0',
                                        'keyword_list_mode' => 'keywords',
                                        'status' => 'active',
                                        'post_type' => 'post',
                                        'post_status' => 'draft',
                                        'author_id' => '0',
                                        'posts_per_run' => '1',
                                        'schedule_type' => 'interval',
                                        'interval_minutes' => '180',
                                        'jitter_minutes' => '30',
                                        'daily_start' => '',
                                        'daily_end' => '',
                                        'image_source_mode' => '',
                                        'pexels_query' => Alpha_RSS_AI_Generator::get_default_pexels_query(),
                                        'source_video_enabled' => '0',
                                        'source_content_images_enabled' => '1',
                                        'source_content_links_enabled' => '1',
                                        'video_selector_class' => '',
                                        'image_selector_class' => '',
                                        'link_selector_class' => '',
                                        'content_selector' => '',
                                        'content_image_size' => 'medium',
                                        'source_link_phrases' => Alpha_RSS_AI_Generator::get_default_source_link_cta_phrases(),
                                        'source_context_exclude_phrases' => '',
                                        'source_context_rating_label' => 'IMDb',
                                        'source_context_min_rating' => '0',
                                        'source_context_keep_unrated' => '0',
                                        'seo_enabled' => '1',
                                        'generation_language' => Alpha_RSS_AI_Generator::get_default_generation_language(),
                                        'category_ids' => array(),
                                        'default_category_id' => '0',
                                        'tags_default' => array(),
                                        'prompt_template' => Alpha_RSS_AI_Generator::get_default_prompt_template(),
                                        'content_prompt_template' => Alpha_RSS_AI_Generator::get_default_content_prompt_template_visible(),
                                        'keyword_prompt_template' => Alpha_RSS_AI_Generator::get_default_keyword_prompt_template(),
                                        'related_posts_enabled' => '0',
                                        'related_posts_position' => 'end',
                                        'related_posts_interval' => '4',
                                        'related_posts_min_h2' => '1',
                                        'related_posts_links_per_block' => '2',
                                        'related_posts_same_category_only' => '1',
                                        'related_posts_allow_fallback' => '1',
                                        'related_posts_style' => 'list',
                                        'related_posts_phrases' => Alpha_RSS_AI_Generator::get_default_related_posts_phrases(),
                                        'internal_links_count' => '0',
                                        'internal_links_json' => '[]',
                                    )); ?>;
                    var editId = <?php echo intval($edit_id); ?>;
                    var settingsModal = document.getElementById('arc-settings-modal');
                    var settingsBackdrop = document.getElementById('arc-settings-backdrop');
                    var runsModal = document.getElementById('arc-runs-modal');
                    var runsBackdrop = document.getElementById('arc-runs-backdrop');
                    var manualRunModal = document.getElementById('arc-manual-run-modal');
                    var manualRunBackdrop = document.getElementById('arc-manual-run-backdrop');
                    var manualRunTitle = document.getElementById('arc-manual-run-title');
                    var manualRunSubtitle = document.getElementById('arc-manual-run-subtitle');
                    var manualRunCount = document.getElementById('arc-manual-run-count');
                    var manualRunRefresh = document.getElementById('arc-manual-run-refresh');
                    var manualRunStatus = document.getElementById('arc-manual-run-status');
                    var manualRunLoading = document.getElementById('arc-manual-run-loading');
                    var manualRunEmpty = document.getElementById('arc-manual-run-empty');
                    var manualRunList = document.getElementById('arc-manual-run-list');
                    var manualRunForm = document.getElementById('arc-manual-run-form');
                    var modal = document.getElementById('arc-generator-modal');
                    var backdrop = document.getElementById('arc-generator-backdrop');
                    var form = document.getElementById('arc-generator-form');
                    var titleEl = document.getElementById('arc-generator-modal-title');
                    var submitEl = document.getElementById('arc-generator-submit');
                    var internalLinksField = form.querySelector('[data-internal-links-field]');
                    var internalLinksRows = form.querySelector('[data-internal-links-rows]');
                    var internalLinksJson = form.querySelector('[data-internal-links-json]');
                    var internalLinksCount = form.querySelector('[data-internal-links-count]');
                    var internalLinksAddButton = form.querySelector('[data-add-internal-link]');
                    var feedUrlField = form.querySelector('[data-feed-url-field]');
                    var listIdField = form.querySelector('[data-list-id-field]');
                    var keywordListModeField = form.querySelector('[data-keyword-list-mode-field]');
                    var videoSelectorField = form.querySelector('[data-rss-video-selector-field]');
                    var apiBase = <?php echo wp_json_encode(rest_url('alpha-rss-ai-generator/v1')); ?>;
                    var restNonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
                    window.AlphaRssAiGenerator = window.AlphaRssAiGenerator || {};
                    window.AlphaRssAiGenerator.generators = generators;
                    window.AlphaRssAiGenerator.defaults = defaults;
                    window.AlphaRssAiGenerator.editId = editId;
                    window.AlphaRssAiGenerator.apiBase = apiBase;
                    window.AlphaRssAiGenerator.restNonce = restNonce;
                    var openModalCount = 0;
                    var manualRunCurrentGeneratorId = '';
                    var manualRunCurrentGeneratorName = '';
                    var manualRunLoadingRequest = null;
                    var manualRunRefreshTimer = null;
                    var manualRunRefreshCooldownSeconds = 12;

                    function hideFieldByName(name) {
                        var el = byName(name);
                        if (el && el.parentElement) {
                            el.parentElement.classList.add('hidden');
                        }
                    }

                    function byName(name) {
                        return form.querySelector('[name="' + name + '"]');
                    }

                    hideFieldByName('pexels_query');
                    hideFieldByName('source_context_rating_label');
                    hideFieldByName('source_context_min_rating');
                    hideFieldByName('source_context_keep_unrated');
                    hideFieldByName('seo_enabled');

                    function setValue(name, value) {
                        var el = byName(name);
                        if (el) {
                            el.value = value !== undefined && value !== null ? value : '';
                            if (typeof Event === 'function') {
                                el.dispatchEvent(new Event('change', {
                                    bubbles: true
                                }));
                            } else if (document.createEvent) {
                                var changeEvent = document.createEvent('Event');
                                changeEvent.initEvent('change', true, false);
                                el.dispatchEvent(changeEvent);
                            }
                        }
                    }

                    function promptLooksLikeRss(text) {
                        var value = String(text || '');
                        return value.indexOf('Você é um editor jornalístico especializado em reescrever conteúdo de RSS.') !== -1 ||
                            value.indexOf('Você é um jornalista de portal focado em SEO e no estilo GEO') !== -1 ||
                            value.indexOf('[DIRETRIZES DE ESCRITA E ESTILO (GEO)]') !== -1;
                    }

                    function promptLooksLikeKeyword(text) {
                        return String(text || '').indexOf('Você é um editor de conteúdo especializado em criar artigos originais a partir de planilhas e palavras-chave.') !== -1;
                    }

                    function getDefaultImageSourceModeForType(sourceType, keywordListMode) {
                        if (sourceType === 'keyword_list') {
                            return String(keywordListMode || 'keywords') === 'url_reference' ? 'rss_or_pexels' : 'pexels';
                        }
                        return 'rss_or_pexels';
                    }

                    function normalizeImageSourceModeForType(sourceType, keywordListMode, value) {
                        var mode = String(value || '').trim();
                        var allowed = ['rss', 'rss_or_pexels', 'rss_or_dalle', 'pexels', 'dalle'];
                        if (allowed.indexOf(mode) === -1) {
                            return getDefaultImageSourceModeForType(sourceType, keywordListMode);
                        }
                        if (sourceType === 'keyword_list' && String(keywordListMode || 'keywords') !== 'url_reference') {
                            if (mode === 'rss' || mode === 'rss_or_pexels') {
                                return 'pexels';
                            }
                            if (mode === 'rss_or_dalle') {
                                return 'dalle';
                            }
                        }
                        return mode;
                    }

                    function normalizePromptForSourceType(sourceType, keywordListMode, value) {
                        var current = String(value || '').trim();
                        if (!current) {
                            if (sourceType === 'keyword_list') {
                                return String(keywordListMode || 'keywords') === 'url_reference' ? defaults.prompt_template : defaults.keyword_prompt_template;
                            }
                            return defaults.prompt_template;
                        }
                        if (sourceType === 'keyword_list') {
                            if (String(keywordListMode || 'keywords') === 'url_reference') {
                                if (current === defaults.keyword_prompt_template) {
                                    return defaults.prompt_template;
                                }
                                return current;
                            }
                            if (current === defaults.prompt_template) {
                                return defaults.keyword_prompt_template;
                            }
                            return current;
                        }
                        if (current === defaults.keyword_prompt_template) {
                            return defaults.prompt_template;
                        }
                        return current;
                    }

                    function setMultiSelect(name, values) {
                        var el = byName(name);
                        if (!el) {
                            return;
                        }
                        var lookup = {};
                        (values || []).forEach(function(value) {
                            lookup[String(value)] = true;
                        });
                        Array.prototype.forEach.call(el.options, function(option) {
                            option.selected = !!lookup[String(option.value)];
                        });
                        if (typeof Event === 'function') {
                            el.dispatchEvent(new Event('change', {
                                bubbles: true
                            }));
                        } else if (document.createEvent) {
                            var changeEvent = document.createEvent('Event');
                            changeEvent.initEvent('change', true, false);
                            el.dispatchEvent(changeEvent);
                        }
                    }

                    function setCheckboxGroup(name, values) {
                        var lookup = {};
                        (values || []).forEach(function(value) {
                            lookup[String(value)] = true;
                        });
                        form.querySelectorAll('input[name="' + name + '"]').forEach(function(input) {
                            input.checked = !!lookup[String(input.value)];
                        });
                    }

                    function getCheckedValues(name) {
                        var values = [];
                        form.querySelectorAll('input[name="' + name + '"]').forEach(function(input) {
                            if (input.checked) {
                                values.push(String(input.value));
                            }
                        });
                        return values;
                    }

                    function listToText(value) {
                        if (Array.isArray(value)) {
                            return value.filter(function(item) {
                                return String(item || '').trim() !== '';
                            }).join(', ');
                        }
                        return String(value || '');
                    }

                    function syncDefaultCategoryField() {
                        var defaultCategoryField = form.querySelector('[data-default-category-field]');
                        var defaultCategoryEl = byName('default_category_id');
                        var selectedCategoryInputs = form.querySelectorAll('input[name="category_ids[]"]:checked');
                        var selectedCategoryValues = [];
                        selectedCategoryInputs.forEach(function(input) {
                            selectedCategoryValues.push(String(input.value));
                        });
                        var showField = selectedCategoryValues.length > 1;

                        if (defaultCategoryField) {
                            defaultCategoryField.classList.toggle('hidden', !showField);
                        }

                        if (!defaultCategoryEl) {
                            return;
                        }

                        defaultCategoryEl.innerHTML = '';
                        if (!showField) {
                            defaultCategoryEl.value = '0';
                            return;
                        }

                        selectedCategoryInputs.forEach(function(input) {
                            var option = document.createElement('option');
                            option.value = String(input.value);
                            option.textContent = input.closest('label') ? input.closest('label').textContent.replace(/\s+/g, ' ').trim() : String(input.value);
                            defaultCategoryEl.appendChild(option);
                        });

                        var currentValue = String(defaultCategoryEl.value || '0');
                        var currentExists = selectedCategoryValues.indexOf(currentValue) !== -1;
                        if (currentValue === '0' || !currentExists) {
                            defaultCategoryEl.value = selectedCategoryValues.length ? selectedCategoryValues[0] : '0';
                        }
                    }

                    function initSelect2Fields() {
                        var $ = window.jQuery;
                        if (!$ || !$.fn || !$.fn.select2) {
                            return;
                        }
                    }

                    function syncSourceFields() {
                        var generationModeEl = byName('generation_mode');
                        var generationMode = generationModeEl ? generationModeEl.value : 'pillar';
                        var sourceTypeEl = byName('source_type');
                        var sourceType = sourceTypeEl ? sourceTypeEl.value : 'keyword_list';
                        var keywordListModeEl = byName('keyword_list_mode');
                        var keywordListMode = keywordListModeEl ? keywordListModeEl.value : 'keywords';
                        var imageSourceModeEl = byName('image_source_mode');
                        var isSatelliteMode = generationMode === 'satellite';

                        if (sourceTypeEl && sourceTypeEl.parentElement) {
                            sourceTypeEl.parentElement.classList.toggle('hidden', isSatelliteMode);
                        }

                        if (feedUrlField) {
                            feedUrlField.classList.toggle('hidden', isSatelliteMode || sourceType === 'keyword_list');
                        }
                        if (listIdField) {
                            listIdField.classList.toggle('hidden', isSatelliteMode || sourceType !== 'keyword_list');
                        }
                        if (keywordListModeField) {
                            keywordListModeField.classList.toggle('hidden', isSatelliteMode || sourceType !== 'keyword_list');
                        }
                        if (videoSelectorField) {
                            var showVideoSelector = !isSatelliteMode && (sourceType === 'rss' || (sourceType === 'keyword_list' && keywordListMode === 'url_reference'));
                            videoSelectorField.classList.toggle('hidden', !showVideoSelector);
                        }
                        if (imageSourceModeEl) {
                            imageSourceModeEl.value = normalizeImageSourceModeForType(sourceType, keywordListMode, imageSourceModeEl.value);
                        }

                        var promptEl = byName('prompt_template');
                        if (promptEl && !isSatelliteMode) {
                            promptEl.value = normalizePromptForSourceType(sourceType, keywordListMode, promptEl.value);
                        }
                    }

                    function parseListValue(value) {
                        if (Array.isArray(value)) {
                            return value;
                        }
                        if (typeof value === 'string' && value !== '') {
                            try {
                                var parsed = JSON.parse(value);
                                if (Array.isArray(parsed)) {
                                    return parsed;
                                }
                            } catch (e) {}
                            return value.split(',').map(function(part) {
                                return part.trim();
                            }).filter(Boolean);
                        }
                        return [];
                    }

                    function parseObjectValue(value) {
                        if (value && typeof value === 'object' && !Array.isArray(value)) {
                            return value;
                        }
                        if (typeof value === 'string' && value !== '') {
                            try {
                                var parsed = JSON.parse(value);
                                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                                    return parsed;
                                }
                            } catch (e) {}
                        }
                        return {};
                    }

                    function parseInternalLinkRules(value) {
                        if (Array.isArray(value)) {
                            return value;
                        }
                        if (typeof value === 'string' && value !== '') {
                            try {
                                var parsed = JSON.parse(value);
                                if (Array.isArray(parsed)) {
                                    return parsed;
                                }
                            } catch (e) {}
                        }
                        return [];
                    }

                    function normalizeInternalLinkRule(rule) {
                        rule = rule || {};

                        function toFlag(value) {
                            if (value === true || value === 1 || value === '1' || value === 'true' || value === 'on') {
                                return '1';
                            }
                            return '0';
                        }
                        return {
                            quantity: Math.max(1, parseInt(rule.quantity, 10) || 1),
                            phrase: String(rule.phrase || rule.word || rule.keyword || rule.anchor_text || '').trim(),
                            url: String(rule.url || rule.link || rule.target_url || '').trim(),
                            target_blank: toFlag(rule.target_blank),
                            nofollow: toFlag(rule.nofollow),
                            sponsored: toFlag(rule.sponsored),
                            ugc: toFlag(rule.ugc)
                        };
                    }

                    function collectInternalLinkRules() {
                        if (!internalLinksRows) {
                            return [];
                        }

                        var rules = [];
                        internalLinksRows.querySelectorAll('[data-internal-link-row]').forEach(function(row) {
                            var quantityEl = row.querySelector('[data-internal-link-quantity]');
                            var phraseEl = row.querySelector('[data-internal-link-phrase]');
                            var urlEl = row.querySelector('[data-internal-link-url]');
                            var targetBlankEl = row.querySelector('[data-internal-link-target-blank]');
                            var nofollowEl = row.querySelector('[data-internal-link-nofollow]');
                            var sponsoredEl = row.querySelector('[data-internal-link-sponsored]');
                            var ugcEl = row.querySelector('[data-internal-link-ugc]');

                            var rule = normalizeInternalLinkRule({
                                quantity: quantityEl ? quantityEl.value : 1,
                                phrase: phraseEl ? phraseEl.value : '',
                                url: urlEl ? urlEl.value : '',
                                target_blank: targetBlankEl && targetBlankEl.checked ? 1 : 0,
                                nofollow: nofollowEl && nofollowEl.checked ? 1 : 0,
                                sponsored: sponsoredEl && sponsoredEl.checked ? 1 : 0,
                                ugc: ugcEl && ugcEl.checked ? 1 : 0
                            });

                            if (!rule.phrase && !rule.url && rule.quantity === 1 && rule.target_blank === '0' && rule.nofollow === '0' && rule.sponsored === '0' && rule.ugc === '0') {
                                return;
                            }

                            rules.push(rule);
                        });

                        return rules;
                    }

                    function calculateInternalLinksCount(rules) {
                        var total = 0;
                        (rules || []).forEach(function(rule) {
                            var normalized = normalizeInternalLinkRule(rule);
                            total += Math.max(1, parseInt(normalized.quantity, 10) || 1);
                        });
                        return total;
                    }

                    function syncInternalLinksField() {
                        if (!internalLinksJson) {
                            return;
                        }
                        var rules = collectInternalLinkRules();
                        internalLinksJson.value = JSON.stringify(rules);
                        if (internalLinksCount) {
                            internalLinksCount.value = String(calculateInternalLinksCount(rules));
                        }
                    }

                    function buildInternalLinkRowMarkup(rule) {
                        rule = normalizeInternalLinkRule(rule);
                        return [
                            '<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-internal-link-row>',
                            '  <div class="grid gap-3 md:grid-cols-12">',
                            '    <div class="md:col-span-2">',
                                '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Quantidade</label>',
                                '      <input type="number" min="1" value="' + escapeHtml(rule.quantity) + '" data-internal-link-quantity class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />',
                            '    </div>',
                            '    <div class="md:col-span-5">',
                            '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Palavra</label>',
                            '      <input type="text" value="' + escapeHtml(rule.phrase) + '" data-internal-link-phrase class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Netflix" />',
                            '    </div>',
                            '    <div class="md:col-span-5">',
                            '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Link</label>',
                            '      <input type="url" value="' + escapeHtml(rule.url) + '" data-internal-link-url class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="https://seusite.com/exemplo" />',
                            '    </div>',
                            '    <div class="md:col-span-2">',
                            '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Atributos</label>',
                            '      <div class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">',
                            '        <label class="flex items-center gap-2"><input type="checkbox" data-internal-link-target-blank ' + (rule.target_blank === '1' ? 'checked' : '') + ' /> target blank</label>',
                            '        <label class="flex items-center gap-2"><input type="checkbox" data-internal-link-nofollow ' + (rule.nofollow === '1' ? 'checked' : '') + ' /> nofollow</label>',
                            '        <label class="flex items-center gap-2"><input type="checkbox" data-internal-link-sponsored ' + (rule.sponsored === '1' ? 'checked' : '') + ' /> sponsored</label>',
                            '        <label class="flex items-center gap-2"><input type="checkbox" data-internal-link-ugc ' + (rule.ugc === '1' ? 'checked' : '') + ' /> ugc</label>',
                            '      </div>',
                            '    </div>',
                            '  </div>',
                            '  <div class="mt-3 flex justify-end">',
                            '    <button type="button" data-remove-internal-link class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100">Remover</button>',
                            '  </div>',
                            '</div>'
                        ].join('');
                    }

                    function renderInternalLinkRows(rules) {
                        if (!internalLinksRows) {
                            return;
                        }

                        var normalizedRules = [];
                        if (Array.isArray(rules)) {
                            normalizedRules = rules.map(function(rule) {
                                return normalizeInternalLinkRule(rule);
                            });
                        }
                        if (!normalizedRules.length) {
                            normalizedRules = [normalizeInternalLinkRule({})];
                        }

                        internalLinksRows.innerHTML = normalizedRules.map(function(rule) {
                            return buildInternalLinkRowMarkup(rule);
                        }).join('');
                        syncInternalLinksField();
                    }

                    function objectToLines(objectValue) {
                        var lines = [];
                        Object.keys(objectValue || {}).forEach(function(key) {
                            var value = objectValue[key];
                            if (Array.isArray(value)) {
                                value = value.join(',');
                            }
                            lines.push(key + '=' + value);
                        });
                        return lines.join('\n');
                    }

                    function escapeHtml(value) {
                        return String(value === undefined || value === null ? '' : value)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    }

                    function parseJsonPayload(text) {
                        var value = text === undefined || text === null ? '' : String(text);
                        value = value.replace(/^\uFEFF/, '').trim();
                        if (!value) {
                            return null;
                        }
                        try {
                            return JSON.parse(value);
                        } catch (error) {
                            var jsonStart = value.search(/[\{\[]/);
                            if (jsonStart > 0) {
                                try {
                                    return JSON.parse(value.slice(jsonStart));
                                } catch (fallbackError) {}
                            }
                            return {
                                success: false,
                                message: value || 'Resposta invalida'
                            };
                        }
                    }

                    function api(path, options) {
                        var fetchOptions = options || {};
                        fetchOptions.credentials = 'same-origin';
                        fetchOptions.headers = fetchOptions.headers || {};
                        fetchOptions.headers['X-WP-Nonce'] = restNonce;
                        return fetch(apiBase + path, fetchOptions).then(function(response) {
                            return response.text().then(function(text) {
                                var payload = parseJsonPayload(text);
                                return {
                                    ok: response.ok,
                                    status: response.status,
                                    payload: payload
                                };
                            });
                        });
                    }

                    function setManualRunStatus(message, type) {
                        if (!manualRunStatus) {
                            return;
                        }
                        if (!message) {
                            manualRunStatus.className = 'hidden mb-4 rounded-xl border px-4 py-3 text-sm';
                            manualRunStatus.textContent = '';
                            return;
                        }
                        var classes = 'mb-4 rounded-xl border px-4 py-3 text-sm';
                        if (type === 'error') {
                            classes += ' border-rose-200 bg-rose-50 text-rose-700';
                        } else if (type === 'success') {
                            classes += ' border-emerald-200 bg-emerald-50 text-emerald-700';
                        } else {
                            classes += ' border-slate-200 bg-slate-50 text-slate-600';
                        }
                        manualRunStatus.className = classes;
                        manualRunStatus.textContent = message;
                    }

                    function setManualRunLoading(isLoading) {
                        if (manualRunLoading) {
                            manualRunLoading.classList.toggle('hidden', !isLoading);
                        }
                        if (manualRunList) {
                            manualRunList.classList.toggle('hidden', isLoading);
                        }
                        if (manualRunEmpty && !isLoading) {
                            manualRunEmpty.classList.add('hidden');
                        }
                    }

                    function clearManualRunRefreshCooldown() {
                        if (manualRunRefreshTimer) {
                            clearTimeout(manualRunRefreshTimer);
                            manualRunRefreshTimer = null;
                        }
                        if (manualRunRefresh) {
                            manualRunRefresh.disabled = false;
                            manualRunRefresh.textContent = 'Atualizar itens';
                        }
                    }

                    function startManualRunRefreshCooldown(generatorId) {
                        if (!generatorId) {
                            return;
                        }
                        clearManualRunRefreshCooldown();
                        var seconds = Math.max(1, parseInt(manualRunRefreshCooldownSeconds, 10) || 12);
                        if (manualRunRefresh) {
                            manualRunRefresh.disabled = true;
                            manualRunRefresh.textContent = 'Aguarde ' + seconds + 's';
                        }
                        setManualRunStatus('Aguarde ' + seconds + ' segundos para nao ser bloqueado como bot.', 'warning');
                        manualRunRefreshTimer = window.setTimeout(function() {
                            manualRunRefreshTimer = null;
                            if (manualRunRefresh) {
                                manualRunRefresh.disabled = false;
                                manualRunRefresh.textContent = 'Atualizar itens';
                            }
                            if (manualRunCurrentGeneratorId) {
                                loadManualRunItems(manualRunCurrentGeneratorId);
                            }
                        }, seconds * 1000);
                    }

                    function setManualRunItems(items) {
                        if (!manualRunList) {
                            return;
                        }

                        manualRunList.innerHTML = '';
                        if (manualRunEmpty) {
                            manualRunEmpty.classList.add('hidden');
                        }
                        if (manualRunCount) {
                            manualRunCount.textContent = String(items.length);
                        }

                        if (!items.length) {
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.remove('hidden');
                            }
                            return;
                        }

                        items.forEach(function(item) {
                            var excerpt = item.excerpt ? escapeHtml(item.excerpt) : '';
                            var permalink = item.permalink ? escapeHtml(item.permalink) : '';
                            var date = item.date_label ? escapeHtml(item.date_label) : (item.date ? escapeHtml(item.date) : '');
                            var card = document.createElement('article');
                            card.className = 'rounded-2xl border border-slate-200 bg-slate-50 p-5 shadow-sm';
                            card.innerHTML = [
                                '<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">',
                                '  <div class="min-w-0 flex-1">',
                                '    <div class="flex flex-wrap items-center gap-2">',
                                '      <h3 class="text-base font-semibold text-slate-950">' + escapeHtml(item.title || '(Sem título)') + '</h3>',
                                '      ' + (date ? '<span class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200">' + date + '</span>' : ''),
                                '    </div>',
                                excerpt ? '    <p class="mt-2 text-sm leading-6 text-slate-600">' + excerpt + '</p>' : '',
                                permalink ? '    <a href="' + permalink + '" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex break-all text-sm text-indigo-600 hover:text-indigo-500">' + permalink + '</a>' : '',
                                '  </div>',
                                '  <div class="flex-shrink-0">',
                                '    <button type="button" data-run-item-guid="' + escapeHtml(item.guid || '') + '" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Gerar este item</button>',
                                '  </div>',
                                '</div>'
                            ].join('');
                            manualRunList.appendChild(card);
                        });
                    }

                    function submitManualRunItem(itemGuid) {
                        if (!manualRunForm) {
                            return;
                        }
                        if (window.AlphaRssAiGeneratorManualRunInFlight) {
                            return;
                        }
                        var generatorIdField = manualRunForm.querySelector('[name="generator_id"]');
                        var itemGuidField = manualRunForm.querySelector('[name="item_guid"]');
                        if (generatorIdField) {
                            generatorIdField.value = manualRunCurrentGeneratorId || '';
                        }
                        if (itemGuidField) {
                            itemGuidField.value = itemGuid || '';
                        }
                        window.AlphaRssAiGeneratorManualRunInFlight = true;
                        manualRunForm.submit();
                    }

                    function loadManualRunItems(generatorId) {
                        if (!generatorId) {
                            return;
                        }
                        manualRunCurrentGeneratorId = String(generatorId);
                        setManualRunStatus('', '');
                        if (manualRunTitle) {
                            manualRunTitle.textContent = 'Escolher item';
                        }
                        if (manualRunSubtitle) {
                            manualRunSubtitle.textContent = 'Escolha um item disponível para gerar um post único.';
                        }
                        setManualRunLoading(true);

                        if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                            manualRunLoadingRequest.abort();
                        }
                        manualRunLoadingRequest = typeof AbortController !== 'undefined' ? new AbortController() : null;

                        api('/generators/' + encodeURIComponent(generatorId) + '/items?limit=30', {
                            method: 'GET',
                            signal: manualRunLoadingRequest ? manualRunLoadingRequest.signal : undefined
                        }).then(function(result) {
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível carregar os itens do feed.');
                            }
                            var payload = result.payload;
                            manualRunCurrentGeneratorName = payload.generator && payload.generator.name ? String(payload.generator.name) : '';
                            if (manualRunTitle) {
                                manualRunTitle.textContent = manualRunCurrentGeneratorName ? ('Escolher item: ' + manualRunCurrentGeneratorName) : 'Escolher item';
                            }
                            if (manualRunSubtitle) {
                                manualRunSubtitle.textContent = 'Escolha um item disponível para gerar um post único.';
                            }
                            setManualRunItems(payload.items || []);
                            if (!payload.items || !payload.items.length) {
                                if (manualRunEmpty) {
                                    manualRunEmpty.classList.remove('hidden');
                                }
                            }
                            if (manualRunCount) {
                                manualRunCount.textContent = String((payload.items || []).length);
                            }
                            setManualRunStatus('', '');
                        }).catch(function(error) {
                            if (error && error.name === 'AbortError') {
                                return;
                            }
                            if (manualRunList) {
                                manualRunList.innerHTML = '';
                            }
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.add('hidden');
                            }
                            setManualRunStatus(error.message || 'Falha ao carregar os itens do feed.', 'error');
                        }).finally(function() {
                            setManualRunLoading(false);
                        });
                    }

                    function applyDefaults() {
                        setValue('generator_id', defaults.generator_id);
                        setValue('name', defaults.name);
                        setValue('feed_url', defaults.feed_url);
                        setValue('generation_mode', defaults.generation_mode);
                        setValue('source_type', defaults.source_type);
                        setValue('list_id', defaults.list_id);
                        setValue('keyword_list_mode', defaults.keyword_list_mode);
                        setValue('status', defaults.status);
                        setValue('post_type', defaults.post_type);
                        setValue('post_status', defaults.post_status);
                        setValue('author_id', defaults.author_id);
                        setValue('posts_per_run', defaults.posts_per_run);
                        setValue('schedule_type', defaults.schedule_type);
                        setValue('interval_minutes', defaults.interval_minutes);
                        setValue('jitter_minutes', defaults.jitter_minutes);
                        setValue('daily_start', defaults.daily_start);
                        setValue('daily_end', defaults.daily_end);
                        setValue('image_source_mode', normalizeImageSourceModeForType(defaults.source_type, defaults.keyword_list_mode, defaults.image_source_mode || getDefaultImageSourceModeForType(defaults.source_type, defaults.keyword_list_mode)));
                        setValue('pexels_query', defaults.pexels_query);
                        setValue('source_video_enabled', defaults.source_video_enabled);
                        setValue('video_selector_class', defaults.video_selector_class);
                        setValue('content_selector', defaults.content_selector);
                        setValue('content_image_size', defaults.content_image_size);
                        setValue('source_link_phrases', defaults.source_link_phrases);
                        setValue('seo_enabled', defaults.seo_enabled);
                        setValue('generation_language', defaults.generation_language);
                        setValue('related_posts_enabled', defaults.related_posts_enabled);
                        setValue('related_posts_position', defaults.related_posts_position);
                        setValue('related_posts_interval', defaults.related_posts_interval);
                        setValue('related_posts_min_h2', defaults.related_posts_min_h2);
                        setValue('related_posts_links_per_block', defaults.related_posts_links_per_block);
                        setValue('related_posts_same_category_only', defaults.related_posts_same_category_only);
                        setValue('related_posts_allow_fallback', defaults.related_posts_allow_fallback);
                        setValue('related_posts_style', defaults.related_posts_style);
                        setValue('related_posts_phrases', defaults.related_posts_phrases);
                        setValue('internal_links_json', defaults.internal_links_json);
                        setValue('default_category_id', defaults.default_category_id);
                        setCheckboxGroup('category_ids[]', []);
                        setValue('tags_default', listToText(defaults.tags_default));
                        syncDefaultCategoryField();
                        renderInternalLinkRows(parseInternalLinkRules(defaults.internal_links_json));
                        syncSourceFields();
                        if (titleEl) {
                            titleEl.textContent = 'Adicionar gerador';
                        }
                        if (submitEl) {
                            submitEl.textContent = 'Salvar gerador';
                        }
                    }

                    function fillForm(generator) {
                        applyDefaults();
                        if (!generator) {
                            return;
                        }

                        setValue('generator_id', generator.id);
                        setValue('name', generator.name);
                        setValue('feed_url', generator.feed_url);
                        setValue('generation_mode', generator.generation_mode || defaults.generation_mode);
                        setValue('source_type', generator.source_type || defaults.source_type);
                        setValue('list_id', typeof generator.list_id !== 'undefined' ? String(generator.list_id) : defaults.list_id);
                        setValue('keyword_list_mode', generator.keyword_list_mode || defaults.keyword_list_mode);
                        setValue('status', generator.status);
                        setValue('post_type', generator.post_type);
                        setValue('post_status', generator.post_status);
                        setValue('author_id', generator.author_id);
                        setValue('posts_per_run', generator.posts_per_run);
                        setValue('schedule_type', generator.schedule_type);
                        setValue('interval_minutes', generator.interval_minutes);
                        setValue('jitter_minutes', generator.jitter_minutes);
                        setValue('daily_start', generator.daily_start);
                        setValue('daily_end', generator.daily_end);
                        setValue('image_source_mode', normalizeImageSourceModeForType(generator.source_type || defaults.source_type, generator.keyword_list_mode || defaults.keyword_list_mode, generator.image_source_mode || (typeof generator.pexels_enabled !== 'undefined' ? (String(generator.pexels_enabled) === '1' ? 'rss_or_pexels' : 'rss') : defaults.image_source_mode)));
                        setValue('pexels_query', generator.pexels_query || defaults.pexels_query);
                        setValue('source_video_enabled', String(typeof generator.source_video_enabled !== 'undefined' ? generator.source_video_enabled : defaults.source_video_enabled));
                        setValue('video_selector_class', generator.video_selector_class || defaults.video_selector_class);
                        setValue('content_selector', generator.content_selector || defaults.content_selector);
                        setValue('content_image_size', generator.content_image_size || defaults.content_image_size);
                        setValue('source_link_phrases', generator.source_link_phrases || defaults.source_link_phrases);
                        setValue('seo_enabled', String(typeof generator.seo_enabled !== 'undefined' ? generator.seo_enabled : defaults.seo_enabled));
                        setValue('generation_language', generator.generation_language || defaults.generation_language);
                        setValue('related_posts_enabled', String(typeof generator.related_posts_enabled !== 'undefined' ? generator.related_posts_enabled : defaults.related_posts_enabled));
                        setValue('related_posts_position', generator.related_posts_position || defaults.related_posts_position);
                        setValue('related_posts_interval', typeof generator.related_posts_interval !== 'undefined' ? generator.related_posts_interval : defaults.related_posts_interval);
                        setValue('related_posts_min_h2', typeof generator.related_posts_min_h2 !== 'undefined' ? generator.related_posts_min_h2 : defaults.related_posts_min_h2);
                        setValue('related_posts_links_per_block', typeof generator.related_posts_links_per_block !== 'undefined' ? generator.related_posts_links_per_block : defaults.related_posts_links_per_block);
                        setValue('related_posts_same_category_only', String(typeof generator.related_posts_same_category_only !== 'undefined' ? generator.related_posts_same_category_only : defaults.related_posts_same_category_only));
                        setValue('related_posts_allow_fallback', String(typeof generator.related_posts_allow_fallback !== 'undefined' ? generator.related_posts_allow_fallback : defaults.related_posts_allow_fallback));
                        setValue('related_posts_style', generator.related_posts_style || defaults.related_posts_style);
                        setValue('related_posts_phrases', generator.related_posts_phrases || defaults.related_posts_phrases);
                        setValue('internal_links_json', generator.internal_links_json || defaults.internal_links_json);
                        setCheckboxGroup('category_ids[]', parseListValue(generator.category_ids));
                        setValue('default_category_id', typeof generator.default_category_id !== 'undefined' ? String(generator.default_category_id) : defaults.default_category_id);
                        setValue('tags_default', listToText(parseListValue(generator.tags_default)));
                        syncDefaultCategoryField();
                        renderInternalLinkRows(parseInternalLinkRules(generator.internal_links_json || defaults.internal_links_json));
                        syncSourceFields();

                        if (titleEl) {
                            titleEl.textContent = 'Editar gerador';
                        }
                        if (submitEl) {
                            submitEl.textContent = 'Atualizar gerador';
                        }
                    }

                    var sourceTypeEl = byName('source_type');
                    if (sourceTypeEl) {
                        sourceTypeEl.addEventListener('change', syncSourceFields);
                    }
                    var generationModeEl = byName('generation_mode');
                    if (generationModeEl) {
                        generationModeEl.addEventListener('change', syncSourceFields);
                    }
                    var keywordListModeEl = byName('keyword_list_mode');
                    if (keywordListModeEl) {
                        keywordListModeEl.addEventListener('change', syncSourceFields);
                    }
                    form.querySelectorAll('input[name="category_ids[]"]').forEach(function(input) {
                        input.addEventListener('change', syncDefaultCategoryField);
                    });
                    var defaultCategoryEl = byName('default_category_id');
                    if (defaultCategoryEl) {
                        defaultCategoryEl.addEventListener('change', syncDefaultCategoryField);
                    }

                    if (internalLinksRows) {
                        internalLinksRows.addEventListener('input', syncInternalLinksField);
                        internalLinksRows.addEventListener('change', syncInternalLinksField);
                        internalLinksRows.addEventListener('click', function(event) {
                            var button = event.target && event.target.closest ? event.target.closest('[data-remove-internal-link]') : null;
                            if (!button) {
                                return;
                            }
                            var row = button.closest('[data-internal-link-row]');
                            if (row) {
                                row.remove();
                                syncInternalLinksField();
                            }
                        });
                    }

                    if (internalLinksAddButton) {
                        internalLinksAddButton.addEventListener('click', function() {
                            var currentRules = collectInternalLinkRules();
                            if (!currentRules.length && internalLinksRows) {
                                var rowCount = internalLinksRows.querySelectorAll('[data-internal-link-row]').length;
                                for (var i = 0; i < rowCount; i++) {
                                    currentRules.push(normalizeInternalLinkRule({}));
                                }
                            }
                            currentRules.push(normalizeInternalLinkRule({}));
                            renderInternalLinkRows(currentRules);
                        });
                    }

                    if (form) {
                        form.addEventListener('submit', function() {
                            syncInternalLinksField();
                        });
                    }

                    initSelect2Fields();

                    function syncBodyLock() {
                        document.body.classList.toggle('overflow-hidden', openModalCount > 0);
                    }

                    function openModal(targetModal) {
                        if (!targetModal || !targetModal.classList.contains('hidden')) {
                            return;
                        }
                        targetModal.classList.remove('hidden');
                        openModalCount++;
                        syncBodyLock();
                    }

                    function closeModal(targetModal) {
                        if (!targetModal || targetModal.classList.contains('hidden')) {
                            return;
                        }
                        targetModal.classList.add('hidden');
                        openModalCount = Math.max(0, openModalCount - 1);
                        syncBodyLock();
                    }

                    function resetGeneratorForm() {
                        form.reset();
                        applyDefaults();
                    }

                    document.querySelectorAll('[data-open-settings-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openModal(settingsModal);
                        });
                    });

                    document.querySelectorAll('[data-open-runs-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openModal(runsModal);
                        });
                    });

                    document.querySelectorAll('[data-open-manual-run-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            var generatorId = String(button.getAttribute('data-generator-id') || '');
                            var generatorName = String(button.getAttribute('data-generator-name') || '');
                            manualRunCurrentGeneratorId = generatorId;
                            manualRunCurrentGeneratorName = generatorName;
                            if (manualRunSubtitle) {
                                manualRunSubtitle.textContent = generatorName ? ('Carregando itens do gerador "' + generatorName + '"...') : 'Carregando itens disponíveis...';
                            }
                            if (manualRunTitle) {
                                manualRunTitle.textContent = 'Escolher item';
                            }
                            setManualRunStatus('', '');
                            setManualRunLoading(true);
                            if (manualRunList) {
                                manualRunList.innerHTML = '';
                            }
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.add('hidden');
                            }
                            openModal(manualRunModal);
                            loadManualRunItems(generatorId);
                        });
                    });

                    document.querySelectorAll('[data-open-generator-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            fillForm(null);
                            openModal(modal);
                        });
                    });

                    document.querySelectorAll('[data-edit-generator-id]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            if (button.tagName && button.tagName.toLowerCase() === 'a' && button.getAttribute('href')) {
                                window.location.href = button.getAttribute('href');
                                return;
                            }
                            var id = String(button.getAttribute('data-edit-generator-id') || '');
                            var generator = generators.find(function(item) {
                                return String(item.id) === id;
                            });
                            fillForm(generator || null);
                            openModal(modal);
                        });
                    });

                    document.querySelectorAll('[data-close-generator-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(modal);
                            resetGeneratorForm();
                        });
                    });

                    document.querySelectorAll('[data-close-settings-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(settingsModal);
                        });
                    });

                    document.querySelectorAll('[data-close-runs-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(runsModal);
                        });
                    });

                    document.querySelectorAll('[data-close-manual-run-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(manualRunModal);
                            clearManualRunRefreshCooldown();
                            setManualRunStatus('', '');
                            if (manualRunList) {
                                manualRunList.innerHTML = '';
                            }
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.add('hidden');
                            }
                            if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                                manualRunLoadingRequest.abort();
                            }
                        });
                    });

                    if (backdrop) {
                        backdrop.addEventListener('click', function() {
                            closeModal(modal);
                            resetGeneratorForm();
                        });
                    }

                    if (settingsBackdrop) {
                        settingsBackdrop.addEventListener('click', function() {
                            closeModal(settingsModal);
                        });
                    }

                    if (runsBackdrop) {
                        runsBackdrop.addEventListener('click', function() {
                            closeModal(runsModal);
                        });
                    }

                    if (manualRunBackdrop) {
                        manualRunBackdrop.addEventListener('click', function() {
                            closeModal(manualRunModal);
                            clearManualRunRefreshCooldown();
                            setManualRunStatus('', '');
                            if (manualRunList) {
                                manualRunList.innerHTML = '';
                            }
                            if (manualRunEmpty) {
                                manualRunEmpty.classList.add('hidden');
                            }
                            if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                                manualRunLoadingRequest.abort();
                            }
                        });
                    }

                    if (manualRunRefresh) {
                        manualRunRefresh.addEventListener('click', function() {
                            if (manualRunCurrentGeneratorId) {
                                startManualRunRefreshCooldown(manualRunCurrentGeneratorId);
                            }
                        });
                    }

                    if (manualRunList) {
                        manualRunList.addEventListener('click', function(event) {
                            var button = event.target && event.target.closest ? event.target.closest('[data-run-item-guid]') : null;
                            if (!button) {
                                return;
                            }
                            var itemGuid = String(button.getAttribute('data-run-item-guid') || '');
                            if (itemGuid !== '') {
                                submitManualRunItem(itemGuid);
                            }
                        });
                    }

                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape') {
                            if (modal && !modal.classList.contains('hidden')) {
                                closeModal(modal);
                                resetGeneratorForm();
                            }
                            if (settingsModal && !settingsModal.classList.contains('hidden')) {
                                closeModal(settingsModal);
                            }
                            if (runsModal && !runsModal.classList.contains('hidden')) {
                                closeModal(runsModal);
                            }
                            if (manualRunModal && !manualRunModal.classList.contains('hidden')) {
                                closeModal(manualRunModal);
                                clearManualRunRefreshCooldown();
                                setManualRunStatus('', '');
                                if (manualRunList) {
                                    manualRunList.innerHTML = '';
                                }
                                if (manualRunEmpty) {
                                    manualRunEmpty.classList.add('hidden');
                                }
                                if (manualRunLoadingRequest && manualRunLoadingRequest.abort) {
                                    manualRunLoadingRequest.abort();
                                }
                            }
                        }
                    });

                    if (editId > 0) {
                        var initialGenerator = generators.find(function(item) {
                            return String(item.id) === String(editId);
                        });
                        if (initialGenerator) {
                            fillForm(initialGenerator);
                            openModal(modal);
                        }
                    } else {
                        applyDefaults();
                    }
                })();
            </script>
        </div>
    <?php

        echo ob_get_clean();
    }

    public function render_global_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $settings = Alpha_RSS_AI_Generator::get_settings();

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
        <div class="wrap arc-wrap min-h-screen bg-slate-100 text-slate-900 flex flex-col items-stretch">
            <h1 class="screen-reader-text">Alpha RSS AI</h1>
            <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-xs font-semibold text-indigo-600">Alpha RSS AI</div>
                    <h1 class="mt-2 text-lg font-semibold tracking-tight text-slate-950">Configurações globais</h1>
                    <p class="mt-2 max-w-3xl text-sm text-slate-600">Ajuste as credenciais e padrões usados por todos os geradores.</p>
                </div>
            </div>

            <section class="w-full max-w-3xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-soft">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-slate-950">Credenciais e padrões</h2>
                    <p class="mt-1 text-sm text-slate-500">Esses valores viram padrão ao criar ou duplicar geradores.</p>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="p-6">
                    <?php wp_nonce_field('arc_save_settings', 'arc_settings_nonce'); ?>
                    <input type="hidden" name="action" value="arc_save_settings" />
                    <div class="space-y-4">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API da OpenAI</label>
                                <input type="password" name="openai_api_key" value="<?php echo esc_attr($settings['openai_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-slate-700">Modelo padrão</label>
                                <input type="text" name="default_model" value="<?php echo esc_attr($settings['default_model']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Temperatura padrão</label>
                                    <input type="number" step="0.1" min="0" max="2" name="default_temperature" value="<?php echo esc_attr($settings['default_temperature']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Máximo de tokens</label>
                                    <input type="number" min="256" name="default_max_tokens" value="<?php echo esc_attr($settings['default_max_tokens']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API do Pexels</label>
                            <input type="password" name="pexels_api_key" value="<?php echo esc_attr($settings['pexels_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="mb-3">
                                <h3 class="text-sm font-semibold text-slate-900">Tavily</h3>
                                <p class="mt-1 text-xs text-slate-500">Busca externa opcional para enriquecer o planejamento com dados recentes.</p>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700">Chave da API do Tavily</label>
                                    <input type="password" name="tavily_api_key" value="<?php echo esc_attr($settings['tavily_api_key']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                </div>
                                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Modo Tavily</label>
                                        <select name="tavily_search_depth" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                            <option value="basic" <?php selected($settings['tavily_search_depth'], 'basic'); ?>>Basic</option>
                                            <option value="advanced" <?php selected($settings['tavily_search_depth'], 'advanced'); ?>>Advanced</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Máximo de resultados</label>
                                        <input type="number" min="1" max="10" name="tavily_max_results" value="<?php echo esc_attr($settings['tavily_max_results']); ?>" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none ring-0 transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                    </div>
                                </div>
                                <label class="flex items-center gap-3 text-sm text-slate-700">
                                    <input type="checkbox" name="tavily_enabled" value="1" <?php checked(!empty($settings['tavily_enabled'])); ?> class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                    Ativar pesquisa Tavily
                                </label>
                                <label class="flex items-center gap-3 text-sm text-slate-700">
                                    <input type="checkbox" name="tavily_include_answer" value="1" <?php checked(!empty($settings['tavily_include_answer'])); ?> class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                    Incluir resposta resumida no prompt
                                </label>
                            </div>
                        </div>
                        <div id="arc-global-links-section" class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-900">Links globais</h3>
                                    <p class="mt-1 text-xs text-slate-500">Cadastre frases e URLs que podem ser aplicadas automaticamente em qualquer geração.</p>
                                </div>
                            </div>
                            <div class="mt-4 space-y-3" data-global-internal-links-rows></div>
                            <textarea name="global_internal_links_json" class="hidden" data-global-internal-links-json><?php echo esc_textarea(isset($settings['global_internal_links_json']) ? $settings['global_internal_links_json'] : '[]'); ?></textarea>
                            <div class="mt-4 flex justify-end">
                                <button type="button" data-add-global-internal-link class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Adicionar link</button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">Esses valores viram padrão ao criar ou duplicar geradores.</p>
                        <div class="flex items-center gap-3">
                            <a href="<?php echo esc_url(admin_url('admin.php?page=alpha-rss-ai-generator')); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Cancelar</a>
                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Salvar configurações</button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
        <script>
            (function() {
                var rowsRoot = document.querySelector('[data-global-internal-links-rows]');
                var jsonField = document.querySelector('[data-global-internal-links-json]');
                var addButton = document.querySelector('[data-add-global-internal-link]');

                if (!rowsRoot || !jsonField) {
                    return;
                }

                function escapeHtml(value) {
                    return String(value === undefined || value === null ? '' : value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');
                }

                function toFlag(value) {
                    if (value === true || value === 1 || value === '1' || value === 'true' || value === 'on') {
                        return '1';
                    }
                    return '0';
                }

                function normalizeRule(rule) {
                    rule = rule || {};
                    return {
                        quantity: Math.max(1, parseInt(rule.quantity, 10) || 1),
                        phrase: String(rule.phrase || rule.word || rule.keyword || rule.anchor_text || '').trim(),
                        url: String(rule.url || rule.link || rule.target_url || '').trim(),
                        target_blank: toFlag(rule.target_blank),
                        nofollow: toFlag(rule.nofollow),
                        sponsored: toFlag(rule.sponsored),
                        ugc: toFlag(rule.ugc)
                    };
                }

                function buildRow(rule) {
                    rule = normalizeRule(rule);
                    return [
                        '<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" data-global-internal-link-row>',
                        '  <div class="grid gap-3 md:grid-cols-12">',
                        '    <div class="md:col-span-2">',
                        '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Quantidade</label>',
                        '      <input type="number" min="1" value="' + escapeHtml(rule.quantity) + '" data-global-internal-link-quantity class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />',
                        '    </div>',
                        '    <div class="md:col-span-4">',
                        '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Palavra</label>',
                        '      <input type="text" value="' + escapeHtml(rule.phrase) + '" data-global-internal-link-phrase class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Disney" />',
                        '    </div>',
                        '    <div class="md:col-span-4">',
                        '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Link</label>',
                        '      <input type="url" value="' + escapeHtml(rule.url) + '" data-global-internal-link-url class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="https://seusite.com/exemplo" />',
                        '    </div>',
                        '    <div class="md:col-span-2">',
                        '      <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Atributos</label>',
                        '      <div class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">',
                        '        <label class="flex items-center gap-2"><input type="checkbox" data-global-internal-link-target-blank ' + (rule.target_blank === '1' ? 'checked' : '') + ' /> target blank</label>',
                        '        <label class="flex items-center gap-2"><input type="checkbox" data-global-internal-link-nofollow ' + (rule.nofollow === '1' ? 'checked' : '') + ' /> nofollow</label>',
                        '        <label class="flex items-center gap-2"><input type="checkbox" data-global-internal-link-sponsored ' + (rule.sponsored === '1' ? 'checked' : '') + ' /> sponsored</label>',
                        '        <label class="flex items-center gap-2"><input type="checkbox" data-global-internal-link-ugc ' + (rule.ugc === '1' ? 'checked' : '') + ' /> ugc</label>',
                        '      </div>',
                        '    </div>',
                        '  </div>',
                        '  <div class="mt-3 flex justify-end">',
                        '    <button type="button" data-remove-global-internal-link class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100">Remover</button>',
                        '  </div>',
                        '</div>'
                    ].join('');
                }

                function collectRules() {
                    var rules = [];
                    rowsRoot.querySelectorAll('[data-global-internal-link-row]').forEach(function(row) {
                        var quantityEl = row.querySelector('[data-global-internal-link-quantity]');
                        var phraseEl = row.querySelector('[data-global-internal-link-phrase]');
                        var urlEl = row.querySelector('[data-global-internal-link-url]');
                        var targetBlankEl = row.querySelector('[data-global-internal-link-target-blank]');
                        var nofollowEl = row.querySelector('[data-global-internal-link-nofollow]');
                        var sponsoredEl = row.querySelector('[data-global-internal-link-sponsored]');
                        var ugcEl = row.querySelector('[data-global-internal-link-ugc]');

                        var rule = normalizeRule({
                            quantity: quantityEl ? quantityEl.value : 1,
                            phrase: phraseEl ? phraseEl.value : '',
                            url: urlEl ? urlEl.value : '',
                            target_blank: targetBlankEl && targetBlankEl.checked ? 1 : 0,
                            nofollow: nofollowEl && nofollowEl.checked ? 1 : 0,
                            sponsored: sponsoredEl && sponsoredEl.checked ? 1 : 0,
                            ugc: ugcEl && ugcEl.checked ? 1 : 0
                        });

                        if (!rule.phrase && !rule.url && rule.quantity === 1 && rule.target_blank === '0' && rule.nofollow === '0' && rule.sponsored === '0' && rule.ugc === '0') {
                            return;
                        }

                        rules.push(rule);
                    });
                    return rules;
                }

                function syncField() {
                    jsonField.value = JSON.stringify(collectRules());
                }

                function renderRows(rules) {
                    var normalized = [];
                    if (Array.isArray(rules)) {
                        normalized = rules.map(function(rule) {
                            return normalizeRule(rule);
                        });
                    }
                    if (!normalized.length) {
                        normalized = [normalizeRule({})];
                    }

                    rowsRoot.innerHTML = normalized.map(buildRow).join('');
                    syncField();
                }

                function appendGlobalInternalLinkRow() {
                    rowsRoot.insertAdjacentHTML('beforeend', buildRow(normalizeRule({})));
                    syncField();
                }

                rowsRoot.addEventListener('input', syncField);
                rowsRoot.addEventListener('change', syncField);
                rowsRoot.addEventListener('click', function(event) {
                    var button = event.target && event.target.closest ? event.target.closest('[data-remove-global-internal-link]') : null;
                    if (!button) {
                        return;
                    }
                    var row = button.closest('[data-global-internal-link-row]');
                    if (row) {
                        row.remove();
                        syncField();
                    }
                });

                if (addButton) {
                    addButton.addEventListener('click', function() {
                        appendGlobalInternalLinkRow();
                    });
                }

                try {
                    renderRows(JSON.parse(jsonField.value || '[]'));
                } catch (error) {
                    renderRows([]);
                }
            })();
        </script>
    <?php

        echo ob_get_clean();
    }

    public function render_keyword_lists_page()
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $global_settings = Alpha_RSS_AI_Generator::get_settings();
        $keyword_lists = Alpha_RSS_AI_Generator::get_keyword_lists(200);
        $summary = array(
            'lists' => count($keyword_lists),
            'rows' => 0,
            'pending' => 0,
            'generated' => 0,
            'invalid' => 0,
        );

        foreach ($keyword_lists as &$keyword_list) {
            $keyword_list['counts'] = Alpha_RSS_AI_Generator::bulk_get_list_counts(intval($keyword_list['id']));
            $summary['rows'] += intval($keyword_list['counts']['total_rows']);
            $summary['pending'] += intval($keyword_list['counts']['pending_rows']);
            $summary['generated'] += intval($keyword_list['counts']['generated_rows']);
            $summary['invalid'] += intval($keyword_list['counts']['invalid_rows']);
        }
        unset($keyword_list);

        $post_types = get_post_types(array('public' => true), 'objects');
        $users = Alpha_RSS_AI_Generator::get_content_author_users();
        $categories = get_categories(array('hide_empty' => false));
        $tags = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));
        if (is_wp_error($tags)) {
            $tags = array();
        }
        $public_taxonomies = get_taxonomies(array('public' => true), 'objects');
        if (!is_array($public_taxonomies)) {
            $public_taxonomies = array();
        }
        $api_base = rest_url('alpha-rss-ai-generator/v1');
        $rest_nonce = wp_create_nonce('wp_rest');

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
            <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-xs font-semibold text-indigo-600">Alpha RSS AI</div>
                    <h1 class="mt-2 text-lg font-semibold tracking-tight text-slate-950">Planilhas e palavras-chave</h1>
                    <p class="mt-2 max-w-3xl text-sm text-slate-600">Importe CSV, XLS ou XLSX, escolha a coluna da palavra-chave e a coluna da slug final, e depois use essas listas nos geradores.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" data-open-keyword-import-modal class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Adicionar / analisar lista</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=alpha-rss-ai-generator')); ?>" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-soft transition hover:bg-slate-50">Ir para geradores</a>
                </div>
            </div>

            <div class="space-y-6">
                <div id="arc-keyword-import-modal" class="fixed inset-0 z-50 hidden">
                    <div id="arc-keyword-import-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                    <div class="relative mx-auto flex min-h-full max-w-7xl items-start px-4 pt-16 pb-8 sm:px-6 sm:pt-20 sm:pb-10 lg:px-8">
                        <div class="absolute right-8 top-8 z-10">
                            <button type="button" data-close-keyword-import-modal class="rounded-full bg-white/90 p-2 text-slate-500 shadow-soft transition hover:bg-white hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <section class="w-full max-h-[calc(100vh-4rem)] overflow-y-auto overscroll-contain rounded-2xl border border-slate-200 bg-white shadow-soft">
                            <div class="border-b border-slate-200 px-6 py-4">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h2 class="text-lg font-semibold text-slate-950">Importar planilha</h2>
                                        <p class="mt-1 text-sm text-slate-500">Etapa 1: analise o arquivo e selecione as colunas antes de gravar a lista. Se existir a coluna <strong>Timestamp</strong>, ela será usada como data de publicação no WordPress.</p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3 text-sm text-slate-500 sm:grid-cols-4">
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Listas</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['lists']); ?></div>
                                        </div>
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Linhas</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['rows']); ?></div>
                                        </div>
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Pendentes</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['pending']); ?></div>
                                        </div>
                                        <div class="rounded-xl bg-slate-50 px-3 py-2">
                                            <div class="text-xs uppercase tracking-wide text-slate-400">Geradas</div>
                                            <div class="font-semibold text-slate-900"><?php echo esc_html($summary['generated']); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="p-6">
                                <div class="grid gap-4 md:grid-cols-[1fr_220px]">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Nome da lista</label>
                                        <input id="arc-keyword-list-name" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Ex.: Semrush - Vestibulares" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-slate-700">Arquivo</label>
                                        <input id="arc-keyword-file" type="file" accept=".csv,.xls,.xlsx" class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-white file:transition hover:file:bg-slate-800" />
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-wrap items-center gap-3">
                                    <button type="button" id="arc-keyword-analyze-btn" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-soft transition hover:bg-indigo-500">Analisar planilha</button>
                                    <button type="button" id="arc-keyword-clear-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Limpar</button>
                                    <div id="arc-keyword-upload-status" class="text-sm text-slate-500"></div>
                                </div>

                                <div id="arc-keyword-preview-panel" class="hidden mt-6 rounded-2xl border border-slate-200 bg-slate-50">
                                    <div class="border-b border-slate-200 px-5 py-4">
                                        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                                            <div>
                                                <h3 class="text-base font-semibold text-slate-950">Mapeamento das colunas</h3>
                                                <p class="mt-1 text-sm text-slate-500">Escolha aqui a coluna da palavra-chave, da URL/slug e os campos extras da planilha.</p>
                                            </div>
                                            <div id="arc-keyword-preview-summary" class="text-sm text-slate-500"></div>
                                        </div>
                                    </div>

                                    <div class="grid gap-4 border-b border-slate-200 px-5 py-5 md:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna da keyword</label>
                                            <select id="arc-keyword-column-keyword" class="arc-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna do título</label>
                                            <select id="arc-keyword-column-title" class="arc-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna da URL</label>
                                            <select id="arc-keyword-column-url" class="arc-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna da slug final</label>
                                            <select id="arc-keyword-column-slug" class="arc-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna de conteúdo</label>
                                            <select id="arc-keyword-column-content" class="arc-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Coluna de tags</label>
                                            <select id="arc-keyword-column-tags" class="arc-keyword-column-select w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"></select>
                                        </div>
                                    </div>

                                    <div class="px-5 py-4">
                                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <button type="button" id="arc-keyword-import-btn" class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Importar lista</button>
                                            </div>
                                        </div>
                                        <div id="arc-keyword-preview-table" class="overflow-hidden rounded-2xl border border-slate-200 bg-white"></div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-soft">
                    <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Listas importadas</h2>
                            <p class="mt-1 text-sm text-slate-500">Abra uma lista para ajustar colunas ou revisar a prévia das linhas.</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="arc-keyword-refresh-btn" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Atualizar</button>
                            <div class="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-500"><?php echo esc_html(count($keyword_lists)); ?> lista(s)</div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <th class="px-6 py-3">Nome</th>
                                    <th class="px-6 py-3">Arquivo</th>
                                    <th class="px-6 py-3">Linhas</th>
                                    <th class="px-6 py-3">Pendentes</th>
                                    <th class="px-6 py-3">Geradas</th>
                                    <th class="px-6 py-3">Atualizado</th>
                                    <th class="px-6 py-3">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <?php if (empty($keyword_lists)): ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-10 text-center text-sm text-slate-500">Nenhuma lista importada ainda.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($keyword_lists as $keyword_list): ?>
                                        <tr class="align-top">
                                            <td class="px-6 py-4">
                                                <div class="font-semibold text-slate-950"><?php echo esc_html($keyword_list['list_name']); ?></div>
                                                <div class="mt-1 text-xs text-slate-500"><?php echo esc_html($keyword_list['original_filename']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(strtoupper($keyword_list['file_type'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['total_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['pending_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-700"><?php echo esc_html(intval($keyword_list['counts']['generated_rows'])); ?></td>
                                            <td class="px-6 py-4 text-sm text-slate-600"><?php echo esc_html($keyword_list['updated_at'] ?: '-'); ?></td>
                                            <td class="px-6 py-4">
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" data-open-keyword-list-modal data-list-id="<?php echo esc_attr($keyword_list['id']); ?>" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Abrir</button>
                                                    <button type="button" data-open-keyword-generate-modal data-list-id="<?php echo esc_attr($keyword_list['id']); ?>" class="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-emerald-500">Gerar</button>
                                                    <button type="button" data-delete-keyword-list-id="<?php echo esc_attr($keyword_list['id']); ?>" class="inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100">Excluir</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div id="arc-keyword-list-modal" class="fixed inset-0 z-50 hidden">
                <div id="arc-keyword-list-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-7xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[90vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="arc-keyword-list-modal-title" class="text-xl font-semibold text-slate-950">Detalhe da lista</h2>
                                <p id="arc-keyword-list-modal-subtitle" class="mt-1 text-sm text-slate-500">Carregando detalhes...</p>
                            </div>
                            <button type="button" data-close-keyword-list-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>
                        <div class="max-h-[calc(90vh-82px)] overflow-y-auto p-6">
                            <div id="arc-keyword-list-modal-status" class="hidden mb-4 rounded-xl border px-4 py-3 text-sm"></div>

                            <div id="arc-keyword-list-modal-counts" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"></div>

                            <div class="mt-6 grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
                                <div class="space-y-6">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                        <div class="border-b border-slate-200 px-4 py-3">
                                            <h3 class="text-sm font-semibold text-slate-950">Mapeamento de colunas</h3>
                                        </div>
                                        <div id="arc-keyword-list-modal-mapping" class="grid gap-4 px-4 py-4 sm:grid-cols-2"></div>
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                        <div class="border-b border-slate-200 px-4 py-3">
                                            <h3 class="text-sm font-semibold text-slate-950">Informações do arquivo</h3>
                                        </div>
                                        <div id="arc-keyword-list-modal-info" class="space-y-2 px-4 py-4 text-sm text-slate-600"></div>
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                    <div class="border-b border-slate-200 px-4 py-3">
                                        <h3 class="text-sm font-semibold text-slate-950">Prévia das linhas</h3>
                                    </div>
                                    <div id="arc-keyword-list-modal-preview" class="overflow-x-auto"></div>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <button type="button" id="arc-keyword-delete-current-list" class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-medium text-rose-700 transition hover:bg-rose-100">Excluir lista</button>
                            <div class="flex items-center gap-3">
                                <button type="button" id="arc-keyword-open-generate-btn" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-500">Gerar em lote</button>
                                <button type="button" data-close-keyword-list-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Fechar</button>
                                <button type="button" id="arc-keyword-save-map-btn" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Salvar mapeamento</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="arc-keyword-generate-modal" class="fixed inset-0 z-50 hidden">
                <div id="arc-keyword-generate-backdrop" class="absolute inset-0 bg-slate-950/60"></div>
                <div class="relative mx-auto flex min-h-full max-w-7xl items-center px-4 py-8 sm:px-6 lg:px-8">
                    <div class="max-h-[92vh] w-full overflow-hidden rounded-3xl bg-white shadow-2xl ring-1 ring-slate-200">
                        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4">
                            <div>
                                <h2 id="arc-keyword-generate-title" class="text-xl font-semibold text-slate-950">Gerar em lote</h2>
                                <p id="arc-keyword-generate-subtitle" class="mt-1 text-sm text-slate-500">Escolha a quantidade, aplique filtros e configure a criação do WordPress.</p>
                            </div>
                            <button type="button" data-close-keyword-generate-modal class="rounded-full p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900" aria-label="Fechar modal">&times;</button>
                        </div>

                        <div class="max-h-[calc(92vh-82px)] overflow-y-auto p-6">
                            <div class="grid gap-6 xl:grid-cols-[0.92fr_1.08fr]">
                                <div class="space-y-6">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <div class="text-xs uppercase tracking-wide text-slate-400">Lista selecionada</div>
                                                <div id="arc-keyword-generate-list-name" class="mt-1 text-base font-semibold text-slate-950">-</div>
                                            </div>
                                            <button type="button" id="arc-keyword-generate-refresh-count" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Atualizar quantidade</button>
                                        </div>
                                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                                <div class="text-xs uppercase tracking-wide text-slate-400">Disponíveis agora</div>
                                                <div id="arc-keyword-generate-available-count" class="mt-1 text-2xl font-semibold text-slate-950">0</div>
                                            </div>
                                            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                                <div class="text-xs uppercase tracking-wide text-slate-400">Serão gerados</div>
                                                <div id="arc-keyword-generate-target-count" class="mt-1 text-2xl font-semibold text-slate-950">0</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <div class="mb-4">
                                            <h3 class="text-sm font-semibold text-slate-950">Quantidade</h3>
                                            <p class="mt-1 text-sm text-slate-500">Informe quantos itens quer gerar agora.</p>
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-slate-700">Gerar quantos?</label>
                                            <input id="arc-keyword-generate-requested" type="number" min="1" value="1" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                        </div>
                                        <p id="arc-keyword-generate-count-msg" class="mt-3 text-sm text-slate-500">Os filtros são aplicados em conjunto. Clique em atualizar para ver quantos itens batem.</p>
                                    </div>

                                    <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div>
                                                <h3 class="text-sm font-semibold text-indigo-950">Pronto para gerar</h3>
                                                <p class="mt-1 text-sm text-indigo-700">Quando a quantidade estiver correta, clique para iniciar a geração dos itens da planilha.</p>
                                            </div>
                                            <button type="button" id="arc-keyword-generate-run-cta" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Iniciar geração</button>
                                        </div>
                                    </div>

                                    <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                                            <div>
                                                <h3 class="text-sm font-semibold text-slate-950">Filtros</h3>
                                                <p class="mt-1 text-xs text-slate-500">Todos os filtros são combinados com AND.</p>
                                            </div>
                                            <button type="button" id="arc-keyword-add-filter" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Adicionar filtro</button>
                                        </div>
                                        <div id="arc-keyword-generate-filters" class="space-y-3 px-4 py-4"></div>
                                    </div>
                                </div>

                                <div class="space-y-6">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                        <div class="border-b border-slate-200 px-4 py-3">
                                            <h3 class="text-sm font-semibold text-slate-950">Opções de criação do WordPress</h3>
                                        </div>
                                        <div class="grid gap-4 px-4 py-4 md:grid-cols-2">
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Tipo de post</label>
                                                <select id="arc-keyword-generate-post-type" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <?php foreach ($post_types as $pt): ?>
                                                        <option value="<?php echo esc_attr($pt->name); ?>"><?php echo esc_html($pt->labels->singular_name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Status do post</label>
                                                <select id="arc-keyword-generate-post-status" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <?php foreach (array('draft', 'publish', 'pending', 'private', 'future') as $status): ?>
                                                        <option value="<?php echo esc_attr($status); ?>"><?php echo esc_html(self::get_post_status_label($status)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Autor</label>
                                                <select id="arc-keyword-generate-author" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <option value="0">Usuário atual</option>
                                                    <?php foreach ($users as $user): ?>
                                                        <option value="<?php echo esc_attr($user->ID); ?>"><?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Linguagem final</label>
                                                <input id="arc-keyword-generate-language" type="text" value="<?php echo esc_attr(Alpha_RSS_AI_Generator::get_default_generation_language()); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Modelo</label>
                                                <input id="arc-keyword-generate-model" type="text" value="<?php echo esc_attr($global_settings['default_model']); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Temperatura</label>
                                                <input id="arc-keyword-generate-temperature" type="number" step="0.1" min="0" max="2" value="<?php echo esc_attr($global_settings['default_temperature']); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Máximo de tokens</label>
                                                <input id="arc-keyword-generate-max-tokens" type="number" min="256" value="<?php echo esc_attr($global_settings['default_max_tokens']); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-sm font-medium text-slate-700">Consulta no Pexels</label>
                                                <input id="arc-keyword-generate-pexels-query" type="text" value="<?php echo esc_attr(Alpha_RSS_AI_Generator::get_default_pexels_query()); ?>" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" />
                                            </div>
                                            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 md:col-span-2">
                                                <div class="text-sm font-medium text-amber-900">Pexels obrigatório</div>
                                                <p class="mt-1 text-xs text-amber-700">Listas por planilha sempre usam imagens do Pexels. Imagens do site de origem são ignoradas.</p>
                                            </div>
                                            <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                                                <input id="arc-keyword-generate-source-video-enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                                <div>
                                                    <label for="arc-keyword-generate-source-video-enabled" class="block text-sm font-medium text-slate-700">Usar vídeo da fonte</label>
                                                    <p class="text-xs text-slate-500">Se houver vídeo na origem, ele entra no post.</p>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
                                                <input id="arc-keyword-generate-seo-enabled" type="checkbox" checked class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                                <div>
                                                    <label for="arc-keyword-generate-seo-enabled" class="block text-sm font-medium text-slate-700">Ativar SEO</label>
                                                    <p class="text-xs text-slate-500">Preenche Yoast, Rank Math, SmartCrawl e AIOSEO quando disponíveis.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid gap-6 md:grid-cols-2">
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <h3 class="text-sm font-semibold text-slate-950">Categorias</h3>
                                            </div>
                                            <div class="px-4 py-4">
                                                <select id="arc-keyword-generate-categories" multiple size="8" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <?php foreach ($categories as $category): ?>
                                                        <option value="<?php echo esc_attr($category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <h3 class="text-sm font-semibold text-slate-950">Tags</h3>
                                            </div>
                                            <div class="px-4 py-4">
                                                <select id="arc-keyword-generate-tags" multiple size="8" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200">
                                                    <?php foreach ($tags as $tag): ?>
                                                        <option value="<?php echo esc_attr($tag->name); ?>"><?php echo esc_html($tag->name); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid gap-6 md:grid-cols-2">
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <h3 class="text-sm font-semibold text-slate-950">Taxonomias personalizadas</h3>
                                            </div>
                                            <div class="px-4 py-4">
                                                <textarea id="arc-keyword-generate-taxonomies" rows="5" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="taxonomia=term1,term2"></textarea>
                                                <p class="mt-2 text-xs text-slate-500">Use uma linha por taxonomia. Ex.: `series=principal,secundaria`.</p>
                                                <?php
                                                $public_taxonomy_labels = array();
                                                foreach ($public_taxonomies as $public_taxonomy) {
                                                    $public_taxonomy_labels[] = !empty($public_taxonomy->labels->name) ? $public_taxonomy->labels->name : $public_taxonomy->name;
                                                }
                                                ?>
                                                <p class="mt-2 text-xs text-slate-500">Taxonomias públicas detectadas: <?php echo esc_html(!empty($public_taxonomy_labels) ? implode(', ', $public_taxonomy_labels) : '-'); ?></p>
                                            </div>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50">
                                            <div class="border-b border-slate-200 px-4 py-3">
                                                <h3 class="text-sm font-semibold text-slate-950">Metadados personalizados</h3>
                                            </div>
                                            <div class="px-4 py-4">
                                                <textarea id="arc-keyword-generate-meta" rows="5" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="meta_key=valor"></textarea>
                                                <p class="mt-2 text-xs text-slate-500">Use uma linha por meta. Ex.: `_seo_title=Meu título`.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-3 border-t border-slate-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="text-sm text-slate-500">Clique em atualizar quantidade após aplicar filtros para ver o total elegível.</div>
                            <div class="flex items-center gap-3">
                                <button type="button" data-close-keyword-generate-modal class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Fechar</button>
                                <button type="button" id="arc-keyword-generate-run-btn" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500">Iniciar geração</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                (function() {
                    var apiBase = <?php echo wp_json_encode($api_base); ?>;
                    var restNonce = <?php echo wp_json_encode($rest_nonce); ?>;
                    var keywordLists = <?php echo wp_json_encode(array_values($keyword_lists)); ?>;
                    var currentPreview = null;
                    var currentUploadFile = null;
                    var currentDetailList = null;
                    var openModalCount = 0;

                    var fileInput = document.getElementById('arc-keyword-file');
                    var listNameInput = document.getElementById('arc-keyword-list-name');
                    var analyzeButton = document.getElementById('arc-keyword-analyze-btn');
                    var clearButton = document.getElementById('arc-keyword-clear-btn');
                    var uploadStatus = document.getElementById('arc-keyword-upload-status');
                    var importModal = document.getElementById('arc-keyword-import-modal');
                    var importBackdrop = document.getElementById('arc-keyword-import-backdrop');
                    var openImportButtons = document.querySelectorAll('[data-open-keyword-import-modal]');
                    var closeImportButtons = document.querySelectorAll('[data-close-keyword-import-modal]');
                    var importLogsPanel = document.getElementById('arc-keyword-import-logs-panel');
                    var importLogsTable = document.getElementById('arc-keyword-import-logs');
                    var clearImportLogsButton = document.getElementById('arc-keyword-clear-import-logs');
                    var previewPanel = document.getElementById('arc-keyword-preview-panel');
                    var previewSummary = document.getElementById('arc-keyword-preview-summary');
                    var previewTable = document.getElementById('arc-keyword-preview-table');
                    var importButton = document.getElementById('arc-keyword-import-btn');
                    var resetPreviewButton = document.getElementById('arc-keyword-reset-preview');
                    var refreshButton = document.getElementById('arc-keyword-refresh-btn');

                    var listModal = document.getElementById('arc-keyword-list-modal');
                    var listBackdrop = document.getElementById('arc-keyword-list-backdrop');
                    var listModalTitle = document.getElementById('arc-keyword-list-modal-title');
                    var listModalSubtitle = document.getElementById('arc-keyword-list-modal-subtitle');
                    var listModalStatus = document.getElementById('arc-keyword-list-modal-status');
                    var listModalCounts = document.getElementById('arc-keyword-list-modal-counts');
                    var listModalMapping = document.getElementById('arc-keyword-list-modal-mapping');
                    var listModalInfo = document.getElementById('arc-keyword-list-modal-info');
                    var listModalPreview = document.getElementById('arc-keyword-list-modal-preview');
                    var saveMapButton = document.getElementById('arc-keyword-save-map-btn');
                    var deleteCurrentListButton = document.getElementById('arc-keyword-delete-current-list');
                    var openGenerateFromListButton = document.getElementById('arc-keyword-open-generate-btn');

                    var generateModal = document.getElementById('arc-keyword-generate-modal');
                    var generateBackdrop = document.getElementById('arc-keyword-generate-backdrop');
                    var generateModalTitle = document.getElementById('arc-keyword-generate-title');
                    var generateModalSubtitle = document.getElementById('arc-keyword-generate-subtitle');
                    var generateListName = document.getElementById('arc-keyword-generate-list-name');
                    var generateAvailableCount = document.getElementById('arc-keyword-generate-available-count');
                    var generateTargetCount = document.getElementById('arc-keyword-generate-target-count');
                    var generateRequestedInput = document.getElementById('arc-keyword-generate-requested');
                    var generateRefreshCountButton = document.getElementById('arc-keyword-generate-refresh-count');
                    var generateCountMessage = document.getElementById('arc-keyword-generate-count-msg');
                    var generateFiltersContainer = document.getElementById('arc-keyword-generate-filters');
                    var generateAddFilterButton = document.getElementById('arc-keyword-add-filter');
                    var generateRunButton = document.getElementById('arc-keyword-generate-run-btn');
                    var generateRunCtaButton = document.getElementById('arc-keyword-generate-run-cta');
                    var generateCancelButtons = document.querySelectorAll('[data-close-keyword-generate-modal]');
                    var generatePostTypeSelect = document.getElementById('arc-keyword-generate-post-type');
                    var generatePostStatusSelect = document.getElementById('arc-keyword-generate-post-status');
                    var generateAuthorSelect = document.getElementById('arc-keyword-generate-author');
                    var generateLanguageInput = document.getElementById('arc-keyword-generate-language');
                    var generateModelInput = document.getElementById('arc-keyword-generate-model');
                    var generateTemperatureInput = document.getElementById('arc-keyword-generate-temperature');
                    var generateMaxTokensInput = document.getElementById('arc-keyword-generate-max-tokens');
                    var generatePexelsQueryInput = document.getElementById('arc-keyword-generate-pexels-query');
                    var generateSourceVideoEnabledInput = document.getElementById('arc-keyword-generate-source-video-enabled');
                    var generateSeoEnabledInput = document.getElementById('arc-keyword-generate-seo-enabled');
                    var generateCategoriesSelect = document.getElementById('arc-keyword-generate-categories');
                    var generateTagsSelect = document.getElementById('arc-keyword-generate-tags');
                    var generateTaxonomiesTextarea = document.getElementById('arc-keyword-generate-taxonomies');
                    var generateMetaTextarea = document.getElementById('arc-keyword-generate-meta');

                    [
                        generateModelInput,
                        generateTemperatureInput,
                        generateMaxTokensInput,
                        generatePexelsQueryInput,
                        generateSeoEnabledInput
                    ].forEach(function(el) {
                        if (el && el.parentElement) {
                            el.parentElement.classList.add('hidden');
                        }
                    });
                    var currentGenerateList = null;
                    var currentGenerateAvailableCount = null;
                    var currentGenerateCountReady = false;
                    var currentGenerateRunToken = 0;
                    var generateCountRequestTimer = null;
                    var generateFilterCounter = 0;

                    function syncBodyLock() {
                        document.body.classList.toggle('overflow-hidden', openModalCount > 0);
                    }

                    function openModal(modal) {
                        if (!modal || !modal.classList.contains('hidden')) {
                            return;
                        }
                        modal.classList.remove('hidden');
                        openModalCount++;
                        syncBodyLock();
                    }

                    function closeModal(modal) {
                        if (!modal || modal.classList.contains('hidden')) {
                            return;
                        }
                        modal.classList.add('hidden');
                        openModalCount = Math.max(0, openModalCount - 1);
                        syncBodyLock();
                    }

                    function setStatus(target, message, type) {
                        if (!target) {
                            return;
                        }
                        if (!message) {
                            target.className = 'hidden mb-4 rounded-xl border px-4 py-3 text-sm';
                            target.textContent = '';
                            return;
                        }
                        var classes = 'mb-4 rounded-xl border px-4 py-3 text-sm';
                        if (type === 'error') {
                            classes += ' border-rose-200 bg-rose-50 text-rose-700';
                        } else if (type === 'success') {
                            classes += ' border-emerald-200 bg-emerald-50 text-emerald-700';
                        } else {
                            classes += ' border-amber-200 bg-amber-50 text-amber-700';
                        }
                        target.className = classes;
                        target.textContent = message;
                    }

                    function parseJsonPayload(text) {
                        var value = text === undefined || text === null ? '' : String(text);
                        value = value.replace(/^\uFEFF/, '').trim();
                        if (!value) {
                            return null;
                        }
                        try {
                            return JSON.parse(value);
                        } catch (error) {
                            var jsonStart = value.search(/[\{\[]/);
                            if (jsonStart > 0) {
                                try {
                                    return JSON.parse(value.slice(jsonStart));
                                } catch (fallbackError) {}
                            }
                            return {
                                success: false,
                                message: value || 'Resposta invalida'
                            };
                        }
                    }

                    function api(path, options) {
                        var fetchOptions = options || {};
                        fetchOptions.credentials = 'same-origin';
                        fetchOptions.headers = fetchOptions.headers || {};
                        fetchOptions.headers['X-WP-Nonce'] = restNonce;
                        return fetch(apiBase + path, fetchOptions).then(function(response) {
                            return response.text().then(function(text) {
                                var payload = parseJsonPayload(text);
                                return {
                                    ok: response.ok,
                                    status: response.status,
                                    payload: payload
                                };
                            });
                        });
                    }

                    function escapeHtml(value) {
                        return String(value === undefined || value === null ? '' : value)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;')
                            .replace(/"/g, '&quot;')
                            .replace(/'/g, '&#039;');
                    }

                    function buildSelectOptions(headers, selected) {
                        var options = ['<option value="">Selecione...</option>'];
                        (headers || []).forEach(function(header) {
                            var escaped = escapeHtml(header);
                            var isSelected = String(header) === String(selected) ? ' selected' : '';
                            options.push('<option value="' + escaped + '"' + isSelected + '>' + escaped + '</option>');
                        });
                        return options.join('');
                    }

                    var generateFilterOperators = [{
                            value: 'contains',
                            label: 'Contém'
                        },
                        {
                            value: 'equals',
                            label: 'Igual a'
                        },
                        {
                            value: 'not_equals',
                            label: 'Diferente de'
                        },
                        {
                            value: 'greater',
                            label: 'Maior que'
                        },
                        {
                            value: 'greater_or_equal',
                            label: 'Maior ou igual'
                        },
                        {
                            value: 'less',
                            label: 'Menor que'
                        },
                        {
                            value: 'less_or_equal',
                            label: 'Menor ou igual'
                        },
                        {
                            value: 'empty',
                            label: 'Vazio'
                        },
                        {
                            value: 'not_empty',
                            label: 'Não vazio'
                        }
                    ];

                    function buildGenerateOperatorOptions(selected) {
                        return generateFilterOperators.map(function(item) {
                            var isSelected = String(item.value) === String(selected) ? ' selected' : '';
                            return '<option value="' + escapeHtml(item.value) + '"' + isSelected + '>' + escapeHtml(item.label) + '</option>';
                        }).join('');
                    }

                    function getGenerateHeaders() {
                        if (currentGenerateList && currentGenerateList.headers) {
                            return currentGenerateList.headers;
                        }
                        if (currentDetailList && currentDetailList.headers) {
                            return currentDetailList.headers;
                        }
                        return [];
                    }

                    function renderGenerateFilterRow(filter) {
                        var headers = getGenerateHeaders();
                        var item = filter || {};
                        var rowId = 'filter-' + (++generateFilterCounter);
                        return [
                            '<div data-generate-filter-row data-filter-id="' + escapeHtml(rowId) + '" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-3 md:grid-cols-[1.1fr_0.8fr_1fr_auto]">',
                            '<div>',
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Coluna</label>',
                            '<select data-filter-column class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, item.column || '') + '</select>',
                            '</div>',
                            '<div>',
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Condição</label>',
                            '<select data-filter-operator class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildGenerateOperatorOptions(item.operator || 'contains') + '</select>',
                            '</div>',
                            '<div>',
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Valor</label>',
                            '<input data-filter-value type="text" value="' + escapeHtml(item.value || '') + '" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200" placeholder="Digite o valor" />',
                            '</div>',
                            '<div class="flex items-end">',
                            '<button type="button" data-remove-filter class="inline-flex h-[42px] items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">Remover</button>',
                            '</div>',
                            '</div>'
                        ].join('');
                    }

                    function renderGenerateFilters(filters) {
                        if (!generateFiltersContainer) {
                            return;
                        }
                        var list = Array.isArray(filters) && filters.length ? filters : [{}];
                        generateFiltersContainer.innerHTML = list.map(function(filter) {
                            return renderGenerateFilterRow(filter);
                        }).join('');
                        updateGenerateTargetSummary();
                    }

                    function getGenerateFilters() {
                        var filters = [];
                        if (!generateFiltersContainer) {
                            return filters;
                        }

                        generateFiltersContainer.querySelectorAll('[data-generate-filter-row]').forEach(function(row) {
                            var columnSelect = row.querySelector('[data-filter-column]');
                            var operatorSelect = row.querySelector('[data-filter-operator]');
                            var valueInput = row.querySelector('[data-filter-value]');
                            var column = columnSelect ? columnSelect.value : '';
                            var operator = operatorSelect ? operatorSelect.value : 'contains';
                            var value = valueInput ? valueInput.value : '';

                            if (!column) {
                                return;
                            }
                            if (!operator) {
                                operator = 'contains';
                            }
                            if (operator !== 'empty' && operator !== 'not_empty' && String(value).trim() === '') {
                                return;
                            }

                            filters.push({
                                column: column,
                                operator: operator,
                                value: value
                            });
                        });

                        return filters;
                    }

                    function getSelectMultiValues(selectEl) {
                        if (!selectEl || !selectEl.options) {
                            return [];
                        }
                        return Array.prototype.slice.call(selectEl.options).filter(function(option) {
                            return option.selected;
                        }).map(function(option) {
                            return option.value;
                        });
                    }

                    function gatherGenerateSettings() {
                        return {
                            post_type: generatePostTypeSelect ? generatePostTypeSelect.value : 'post',
                            post_status: generatePostStatusSelect ? generatePostStatusSelect.value : 'draft',
                            author_id: generateAuthorSelect ? generateAuthorSelect.value : '0',
                            generation_language: generateLanguageInput ? generateLanguageInput.value : '',
                            pexels_enabled: 1,
                            source_video_enabled: generateSourceVideoEnabledInput && generateSourceVideoEnabledInput.checked ? 1 : 0,
                            category_ids: getSelectMultiValues(generateCategoriesSelect),
                            tags_default: getSelectMultiValues(generateTagsSelect),
                            custom_taxonomies: generateTaxonomiesTextarea ? generateTaxonomiesTextarea.value : '',
                            custom_meta: generateMetaTextarea ? generateMetaTextarea.value : ''
                        };
                    }

                    function updateGenerateTargetSummary() {
                        if (!generateAvailableCount || !generateTargetCount) {
                            return;
                        }

                        var available = currentGenerateAvailableCount === null ? 0 : parseInt(currentGenerateAvailableCount, 10) || 0;
                        var requested = 1;
                        if (generateRequestedInput) {
                            requested = Math.max(1, parseInt(generateRequestedInput.value, 10) || 1);
                        }
                        var target = Math.min(requested, available);

                        generateAvailableCount.textContent = String(available);
                        generateTargetCount.textContent = String(target);

                        if (generateCountMessage) {
                            if (!currentGenerateCountReady) {
                                generateCountMessage.textContent = 'Contagem inicial da lista. Clique em atualizar quantidade para recalcular com os filtros.';
                            } else if (available <= 0) {
                                var totalRows = currentGenerateList && currentGenerateList.counts ? (parseInt(currentGenerateList.counts.total_rows || 0, 10) || 0) : 0;
                                if (totalRows <= 0) {
                                    generateCountMessage.textContent = 'Esta lista não possui linhas válidas para gerar. A importação removeu as linhas inválidas ou a planilha não tinha URLs elegíveis.';
                                } else {
                                    generateCountMessage.textContent = 'Nenhum item elegível com estes filtros.';
                                }
                            } else {
                                generateCountMessage.textContent = 'Itens elegíveis: ' + available + '. A geração vai parar quando atingir a quantidade solicitada ou quando acabar a lista.';
                            }
                        }
                    }

                    function scheduleGenerateCountRefresh() {
                        if (generateCountRequestTimer) {
                            window.clearTimeout(generateCountRequestTimer);
                        }
                        currentGenerateCountReady = false;
                        generateCountRequestTimer = window.setTimeout(function() {
                            refreshGenerateAvailability();
                        }, 450);
                    }

                    async function refreshGenerateAvailability() {
                        if (!currentGenerateList || !currentGenerateList.id) {
                            return 0;
                        }

                        var filters = getGenerateFilters();
                        if (generateCountMessage) {
                            generateCountMessage.textContent = 'Calculando itens elegíveis...';
                        }

                        try {
                            var result = await api('/keyword-lists/' + currentGenerateList.id + '/generate', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    preview: true,
                                    filters: filters
                                })
                            });

                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível calcular a quantidade');
                            }

                            currentGenerateAvailableCount = parseInt(result.payload.available_count || 0, 10) || 0;
                            currentGenerateCountReady = true;
                            updateGenerateTargetSummary();
                            return currentGenerateAvailableCount;
                        } catch (error) {
                            currentGenerateAvailableCount = currentGenerateAvailableCount === null ? 0 : currentGenerateAvailableCount;
                            currentGenerateCountReady = true;
                            updateGenerateTargetSummary();
                            window.alert(error.message || 'Erro ao calcular a quantidade.');
                            return currentGenerateAvailableCount;
                        }
                    }

                    function openGenerateModalWithList(listData) {
                        if (!listData) {
                            return;
                        }

                        currentGenerateList = listData;
                        currentGenerateAvailableCount = currentGenerateList && currentGenerateList.counts ? parseInt(currentGenerateList.counts.pending_rows || 0, 10) || 0 : 0;
                        currentGenerateCountReady = false;
                        if (generateModalTitle) {
                            generateModalTitle.textContent = 'Gerar em lote';
                        }
                        if (generateModalSubtitle) {
                            generateModalSubtitle.textContent = (currentGenerateList.list_name || '-') + ' · ' + (currentGenerateList.original_filename || '-');
                        }
                        if (generateListName) {
                            generateListName.textContent = currentGenerateList.list_name || '-';
                        }

                        renderGenerateFilters([{}]);
                        updateGenerateTargetSummary();
                        if (generateCountMessage) {
                            var totalRows = currentGenerateList && currentGenerateList.counts ? (parseInt(currentGenerateList.counts.total_rows || 0, 10) || 0) : 0;
                            if (totalRows <= 0) {
                                generateCountMessage.textContent = 'Esta lista ficou vazia após a limpeza de linhas inválidas. Reimporte um arquivo com URLs/slugs elegíveis para gerar.';
                            }
                        }
                        openModal(generateModal);
                        window.setTimeout(function() {
                            if (generateRequestedInput) {
                                generateRequestedInput.focus();
                            }
                        }, 100);
                        refreshGenerateAvailability();
                    }

                    async function openGenerateModal(listId) {
                        if (!listId) {
                            return;
                        }

                        if (listModal && !listModal.classList.contains('hidden')) {
                            closeModal(listModal);
                        }

                        var existing = null;
                        if (currentDetailList && String(currentDetailList.id) === String(listId)) {
                            existing = currentDetailList;
                        }

                        if (existing) {
                            openGenerateModalWithList(existing);
                            return;
                        }

                        try {
                            var result = await api('/keyword-lists/' + listId, {
                                method: 'GET'
                            });
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível carregar a lista');
                            }
                            openGenerateModalWithList(result.payload.list || null);
                        } catch (error) {
                            window.alert(error.message || 'Erro ao carregar a lista.');
                        }
                    }

                    async function runGenerateBatch() {
                        if (!currentGenerateList || !currentGenerateList.id) {
                            return;
                        }

                        var requested = 1;
                        if (generateRequestedInput) {
                            requested = Math.max(1, parseInt(generateRequestedInput.value, 10) || 1);
                        }

                        var filters = getGenerateFilters();
                        var settings = gatherGenerateSettings();

                        if (!currentGenerateCountReady || currentGenerateAvailableCount === null) {
                            await refreshGenerateAvailability();
                        }

                        var available = Math.max(0, parseInt(currentGenerateAvailableCount, 10) || 0);
                        var target = Math.min(requested, available);

                        if (target <= 0) {
                            window.alert('Nenhum item elegível para gerar com os filtros atuais.');
                            return;
                        }

                        var generated = 0;
                        var runLabel = generateRunButton ? generateRunButton.textContent : 'Gerar agora';
                        currentGenerateRunToken++;
                        var runToken = currentGenerateRunToken;

                        if (generateRunButton) {
                            generateRunButton.disabled = true;
                            generateRunButton.textContent = 'Gerando...';
                        }

                        try {
                            while (generated < target) {
                                if (runToken !== currentGenerateRunToken) {
                                    break;
                                }

                                var result = await api('/keyword-lists/' + currentGenerateList.id + '/generate', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify({
                                        filters: filters,
                                        settings: settings
                                    })
                                });

                                if (!result.ok || !result.payload || !result.payload.success) {
                                    throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Falha ao gerar o item');
                                }

                                if (result.payload.done) {
                                    break;
                                }

                                var generatedResult = result.payload.result || {};
                                generated++;
                                currentGenerateAvailableCount = Math.max(0, (currentGenerateAvailableCount || 0) - 1);
                                updateGenerateTargetSummary();
                            }

                            if (generated <= 0 && target > 0) {
                                window.alert('Nenhum item foi gerado.');
                            }
                        } catch (error) {
                            window.alert(error.message || 'Erro ao gerar em lote.');
                        } finally {
                            if (generateRunButton) {
                                generateRunButton.disabled = false;
                                generateRunButton.textContent = runLabel || 'Gerar agora';
                            }
                        }
                    }

                    function renderPreviewTable(headers, rows) {
                        var html = [];
                        html.push('<table class="min-w-full divide-y divide-slate-200 text-sm">');
                        html.push('<thead class="bg-slate-50"><tr>');
                        (headers || []).forEach(function(header) {
                            html.push('<th class="px-4 py-3 text-left font-semibold text-slate-600">' + escapeHtml(header) + '</th>');
                        });
                        html.push('</tr></thead>');
                        html.push('<tbody class="divide-y divide-slate-100 bg-white">');
                        if (!rows || !rows.length) {
                            html.push('<tr><td colspan="' + Math.max(1, (headers || []).length) + '" class="px-4 py-6 text-center text-slate-500">Nenhuma linha disponivel.</td></tr>');
                        } else {
                            rows.forEach(function(row) {
                                html.push('<tr class="align-top">');
                                (headers || []).forEach(function(header) {
                                    html.push('<td class="max-w-[220px] px-4 py-3 text-slate-700"><div class="truncate">' + escapeHtml(row && row[header] ? row[header] : '') + '</div></td>');
                                });
                                html.push('</tr>');
                            });
                        }
                        html.push('</tbody></table>');
                        return html.join('');
                    }

                    function renderImportLogs(logs) {
                        if (!importLogsPanel || !importLogsTable) {
                            return;
                        }

                        var items = Array.isArray(logs) ? logs : [];
                        if (!items.length) {
                            importLogsTable.innerHTML = '<div class="rounded-xl border border-dashed border-rose-200 bg-white px-4 py-6 text-sm text-rose-700">Nenhum log de erro para exibir.</div>';
                            importLogsPanel.classList.add('hidden');
                            return;
                        }

                        var html = [];
                        html.push('<table class="min-w-full divide-y divide-rose-200 text-sm">');
                        html.push('<thead class="bg-rose-100/70"><tr class="text-left text-xs font-semibold uppercase tracking-wide text-rose-700">');
                        html.push('<th class="px-3 py-2">Linha</th>');
                        html.push('<th class="px-3 py-2">Código</th>');
                        html.push('<th class="px-3 py-2">Mensagem</th>');
                        html.push('</tr></thead><tbody class="divide-y divide-rose-100 bg-white">');
                        items.forEach(function(log) {
                            html.push('<tr class="align-top">');
                            html.push('<td class="px-3 py-2 text-rose-900">' + escapeHtml(log.row_number || '-') + '</td>');
                            html.push('<td class="px-3 py-2 text-rose-700">' + escapeHtml(log.code || '-') + '</td>');
                            var message = escapeHtml(log.message || '-');
                            var details = [];
                            if (log.keyword) {
                                details.push('keyword: ' + escapeHtml(log.keyword));
                            }
                            if (log.source_url) {
                                details.push('url: ' + escapeHtml(log.source_url));
                            }
                            if (log.final_slug) {
                                details.push('slug: ' + escapeHtml(log.final_slug));
                            }
                            if (details.length) {
                                message += '<div class="mt-1 text-xs text-rose-600">' + details.join(' · ') + '</div>';
                            }
                            html.push('<td class="px-3 py-2 text-rose-900">' + message + '</td>');
                            html.push('</tr>');
                        });
                        html.push('</tbody></table>');

                        importLogsTable.innerHTML = html.join('');
                        importLogsPanel.classList.remove('hidden');
                    }

                    function getColumnMapValues() {
                        return {
                            keyword_column: document.getElementById('arc-keyword-column-keyword') ? document.getElementById('arc-keyword-column-keyword').value : '',
                            source_title_column: document.getElementById('arc-keyword-column-title') ? document.getElementById('arc-keyword-column-title').value : '',
                            source_url_column: document.getElementById('arc-keyword-column-url') ? document.getElementById('arc-keyword-column-url').value : '',
                            slug_column: document.getElementById('arc-keyword-column-slug') ? document.getElementById('arc-keyword-column-slug').value : '',
                            content_column: document.getElementById('arc-keyword-column-content') ? document.getElementById('arc-keyword-column-content').value : '',
                            tags_column: document.getElementById('arc-keyword-column-tags') ? document.getElementById('arc-keyword-column-tags').value : ''
                        };
                    }

                    function setColumnMapValues(columnMap, headers) {
                        var map = columnMap || {};
                        document.getElementById('arc-keyword-column-keyword').innerHTML = buildSelectOptions(headers, map.keyword_column || '');
                        document.getElementById('arc-keyword-column-title').innerHTML = buildSelectOptions(headers, map.source_title_column || '');
                        document.getElementById('arc-keyword-column-url').innerHTML = buildSelectOptions(headers, map.source_url_column || '');
                        document.getElementById('arc-keyword-column-slug').innerHTML = buildSelectOptions(headers, map.slug_column || '');
                        document.getElementById('arc-keyword-column-content').innerHTML = buildSelectOptions(headers, map.content_column || '');
                        document.getElementById('arc-keyword-column-tags').innerHTML = buildSelectOptions(headers, map.tags_column || '');
                    }

                    function getCurrentHeaders() {
                        if (currentPreview && currentPreview.headers) {
                            return currentPreview.headers;
                        }
                        if (currentDetailList && currentDetailList.headers) {
                            return currentDetailList.headers;
                        }
                        return [];
                    }

                    function renderPreview(payload) {
                        currentPreview = payload;
                        if (!payload) {
                            previewPanel.classList.add('hidden');
                            currentUploadFile = null;
                            return;
                        }

                        var headers = payload.headers || [];
                        setColumnMapValues(payload.detected_column_map || {}, headers);
                        previewSummary.textContent = (payload.file && payload.file.name ? payload.file.name + ' · ' : '') + (payload.row_count || 0) + ' linha(s) lida(s)';
                        previewTable.innerHTML = renderPreviewTable(headers, payload.rows || []);
                        previewPanel.classList.remove('hidden');
                        openModalCount = Math.max(openModalCount, 0);
                    }

                    async function analyzeFile() {
                        var file = fileInput && fileInput.files ? fileInput.files[0] : null;
                        var listName = listNameInput ? listNameInput.value.trim() : '';

                        if (!file) {
                            setStatus(uploadStatus, 'Selecione um arquivo CSV, XLS ou XLSX.', 'error');
                            return;
                        }

                        var formData = new FormData();
                        formData.append('file', file);
                        formData.append('list_name', listName);

                        if (analyzeButton) {
                            analyzeButton.disabled = true;
                            analyzeButton.textContent = 'Analisando...';
                        }
                        setStatus(uploadStatus, 'Lendo planilha e detectando colunas...', 'warning');

                        try {
                            var result = await api('/keyword-lists/preview', {
                                method: 'POST',
                                body: formData
                            });

                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Falha ao analisar a planilha');
                            }

                            currentUploadFile = file;
                            renderPreview(result.payload);
                            setStatus(uploadStatus, 'Planilha analisada com sucesso. Ajuste os campos e importe quando estiver pronto.', 'success');
                        } catch (error) {
                            setStatus(uploadStatus, error.message || 'Erro ao analisar a planilha.', 'error');
                            previewPanel.classList.add('hidden');
                            currentPreview = null;
                            currentUploadFile = null;
                        } finally {
                            if (analyzeButton) {
                                analyzeButton.disabled = false;
                                analyzeButton.textContent = 'Analisar planilha';
                            }
                        }
                    }

                    async function importList() {
                        if (!currentUploadFile) {
                            setStatus(uploadStatus, 'Analise a planilha antes de importar.', 'error');
                            return;
                        }

                        var columnMap = getColumnMapValues();
                        var formData = new FormData();
                        formData.append('file', currentUploadFile);
                        formData.append('list_name', listNameInput ? listNameInput.value.trim() : '');
                        formData.append('column_map', JSON.stringify(columnMap));

                        if (importButton) {
                            importButton.disabled = true;
                            importButton.textContent = 'Importando...';
                        }
                        setStatus(uploadStatus, 'Importando lista...', 'warning');

                        try {
                            var result = await api('/keyword-lists', {
                                method: 'POST',
                                body: formData
                            });
                            var payload = result.payload || {};
                            var payloadLogs = Array.isArray(payload.logs) ? payload.logs : [];

                            if (payloadLogs.length) {
                                renderImportLogs(payloadLogs);
                            }

                            if (!result.ok || !payload.success) {
                                if (payloadLogs.length) {
                                    renderImportLogs(payloadLogs);
                                }
                                throw new Error(payload.message ? payload.message : 'Falha ao importar a lista');
                            }

                            var imported = payload.list || {};
                            var importedRows = imported.inserted_rows || 0;
                            var invalidRows = imported.invalid_rows || 0;
                            var duplicateRows = imported.duplicate_rows || 0;
                            var parts = [];
                            if (importedRows) {
                                parts.push(importedRows + ' linha(s) importada(s)');
                            }
                            if (invalidRows) {
                                parts.push(invalidRows + ' invalida(s) ignorada(s)');
                            }
                            if (duplicateRows) {
                                parts.push(duplicateRows + ' duplicada(s) ignorada(s)');
                            }
                            setStatus(uploadStatus, parts.length ? ('Lista importada com sucesso. ' + parts.join(', ') + '.') : 'Lista importada com sucesso.', 'success');
                            if (payloadLogs.length) {
                                renderImportLogs(payloadLogs);
                            }
                            window.setTimeout(function() {
                                window.location.reload();
                            }, 600);
                        } catch (error) {
                            setStatus(uploadStatus, error.message || 'Erro ao importar a lista.', 'error');
                        } finally {
                            if (importButton) {
                                importButton.disabled = false;
                                importButton.textContent = 'Importar lista';
                            }
                        }
                    }

                    function clearPreview() {
                        currentPreview = null;
                        currentUploadFile = null;
                        if (fileInput) {
                            fileInput.value = '';
                        }
                        if (previewPanel) {
                            previewPanel.classList.add('hidden');
                        }
                        if (previewSummary) {
                            previewSummary.textContent = '';
                        }
                        if (previewTable) {
                            previewTable.innerHTML = '';
                        }
                        setStatus(uploadStatus, '', '');
                        renderImportLogs([]);
                    }

                    function renderCounts(counts) {
                        return [
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Total</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.total_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Pendentes</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.pending_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Geradas</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.generated_rows || 0) + '</div></div>',
                            '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3"><div class="text-xs uppercase tracking-wide text-slate-400">Inválidas</div><div class="mt-1 text-lg font-semibold text-slate-950">' + escapeHtml(counts.invalid_rows || 0) + '</div></div>'
                        ].join('');
                    }

                    function renderListMapping(headers, columnMap) {
                        var map = columnMap || {};
                        var parts = [];
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Keyword</label><select id="arc-keyword-list-map-keyword" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.keyword_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Título</label><select id="arc-keyword-list-map-title" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.source_title_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">URL</label><select id="arc-keyword-list-map-url" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.source_url_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Slug final</label><select id="arc-keyword-list-map-slug" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.slug_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Conteúdo</label><select id="arc-keyword-list-map-content" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.content_column || '') + '</select></div>');
                        parts.push('<div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Tags</label><select id="arc-keyword-list-map-tags" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm">' + buildSelectOptions(headers, map.tags_column || '') + '</select></div>');
                        return parts.join('');
                    }

                    function readListMap() {
                        return {
                            keyword_column: document.getElementById('arc-keyword-list-map-keyword') ? document.getElementById('arc-keyword-list-map-keyword').value : '',
                            source_title_column: document.getElementById('arc-keyword-list-map-title') ? document.getElementById('arc-keyword-list-map-title').value : '',
                            source_url_column: document.getElementById('arc-keyword-list-map-url') ? document.getElementById('arc-keyword-list-map-url').value : '',
                            slug_column: document.getElementById('arc-keyword-list-map-slug') ? document.getElementById('arc-keyword-list-map-slug').value : '',
                            content_column: document.getElementById('arc-keyword-list-map-content') ? document.getElementById('arc-keyword-list-map-content').value : '',
                            tags_column: document.getElementById('arc-keyword-list-map-tags') ? document.getElementById('arc-keyword-list-map-tags').value : ''
                        };
                    }

                    function renderListPreviewRows(headers, rows) {
                        var preparedRows = [];
                        (rows || []).forEach(function(row) {
                            if (row && row.row_data && typeof row.row_data === 'object') {
                                preparedRows.push(row.row_data);
                            } else {
                                preparedRows.push(row);
                            }
                        });
                        return renderPreviewTable(headers, preparedRows);
                    }

                    async function openListDetail(listId) {
                        if (!listId) {
                            return;
                        }

                        setStatus(listModalStatus, 'Carregando detalhes da lista...', 'warning');
                        openModal(listModal);

                        try {
                            var result = await api('/keyword-lists/' + listId, {
                                method: 'GET'
                            });
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível carregar a lista');
                            }

                            currentDetailList = result.payload.list || null;
                            var headers = currentDetailList && currentDetailList.headers ? currentDetailList.headers : [];
                            var columnMap = currentDetailList && currentDetailList.column_map ? currentDetailList.column_map : {};
                            var counts = currentDetailList && currentDetailList.counts ? currentDetailList.counts : {};
                            var rows = result.payload.rows || [];

                            if (listModalTitle) {
                                listModalTitle.textContent = currentDetailList ? currentDetailList.list_name : 'Detalhe da lista';
                            }
                            if (listModalSubtitle) {
                                listModalSubtitle.textContent = currentDetailList ? (currentDetailList.original_filename + ' · ' + (currentDetailList.file_type || '').toUpperCase()) : '';
                            }
                            if (listModalCounts) {
                                listModalCounts.innerHTML = renderCounts(counts);
                            }
                            if (listModalMapping) {
                                listModalMapping.innerHTML = renderListMapping(headers, columnMap);
                            }
                            if (listModalInfo) {
                                listModalInfo.innerHTML = [
                                    '<div><span class="font-medium text-slate-900">Arquivo original:</span> ' + escapeHtml(currentDetailList.original_filename || '-') + '</div>',
                                    '<div><span class="font-medium text-slate-900">Tipo:</span> ' + escapeHtml((currentDetailList.file_type || '-').toUpperCase()) + '</div>',
                                    '<div><span class="font-medium text-slate-900">Criada em:</span> ' + escapeHtml(currentDetailList.created_at || '-') + '</div>',
                                    '<div><span class="font-medium text-slate-900">Atualizada em:</span> ' + escapeHtml(currentDetailList.updated_at || '-') + '</div>',
                                    '<div><span class="font-medium text-slate-900">Colunas detectadas:</span> ' + escapeHtml((headers || []).length) + '</div>',
                                    '<div><span class="font-medium text-slate-900">Linhas na prévia:</span> ' + escapeHtml(rows.length) + '</div>'
                                ].join('');
                            }
                            if (listModalPreview) {
                                listModalPreview.innerHTML = renderListPreviewRows(headers, rows);
                            }
                            setStatus(listModalStatus, '', '');
                            openModal(listModal);
                        } catch (error) {
                            setStatus(listModalStatus, error.message || 'Erro ao carregar lista.', 'error');
                        }
                    }

                    async function saveCurrentListMap() {
                        if (!currentDetailList) {
                            return;
                        }

                        var columnMap = readListMap();
                        setStatus(listModalStatus, 'Salvando mapeamento...', 'warning');

                        try {
                            var result = await api('/keyword-lists/' + currentDetailList.id + '/columns', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    column_map: columnMap
                                })
                            });

                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível salvar o mapeamento');
                            }

                            setStatus(listModalStatus, 'Mapeamento salvo com sucesso.', 'success');
                            window.setTimeout(function() {
                                window.location.reload();
                            }, 600);
                        } catch (error) {
                            setStatus(listModalStatus, error.message || 'Erro ao salvar o mapeamento.', 'error');
                        }
                    }

                    async function deleteListById(listId, statusTarget) {
                        if (!listId) {
                            return;
                        }
                        if (window.AlphaRssAiGeneratorSwal && typeof window.AlphaRssAiGeneratorSwal.confirm === 'function') {
                            var confirmed = await window.AlphaRssAiGeneratorSwal.confirm('Excluir esta lista e todas as linhas importadas?', {
                                title: 'Confirmacao'
                            });
                            if (!confirmed) {
                                return;
                            }
                        } else if (!window.confirm('Excluir esta lista e todas as linhas importadas?')) {
                            return;
                        }

                        if (statusTarget) {
                            setStatus(statusTarget, 'Excluindo lista...', 'warning');
                        }

                        try {
                            var result = await api('/keyword-lists/' + listId, {
                                method: 'DELETE'
                            });
                            if (!result.ok || !result.payload || !result.payload.success) {
                                throw new Error((result.payload && result.payload.message) ? result.payload.message : 'Não foi possível excluir a lista');
                            }
                            window.location.reload();
                        } catch (error) {
                            if (statusTarget) {
                                setStatus(statusTarget, error.message || 'Erro ao excluir a lista.', 'error');
                            } else {
                                if (window.AlphaRssAiGeneratorSwal && typeof window.AlphaRssAiGeneratorSwal.error === 'function') {
                                    window.AlphaRssAiGeneratorSwal.error(error.message || 'Erro ao excluir a lista.', 'Erro');
                                } else {
                                    window.alert(error.message || 'Erro ao excluir a lista.');
                                }
                            }
                        }
                    }

                    if (analyzeButton) {
                        analyzeButton.addEventListener('click', analyzeFile);
                    }

                    if (importButton) {
                        importButton.addEventListener('click', importList);
                    }

                    if (clearButton) {
                        clearButton.addEventListener('click', clearPreview);
                    }

                    if (clearImportLogsButton) {
                        clearImportLogsButton.addEventListener('click', function() {
                            renderImportLogs([]);
                            setStatus(uploadStatus, '', '');
                        });
                    }

                    if (resetPreviewButton) {
                        resetPreviewButton.addEventListener('click', clearPreview);
                    }

                    if (refreshButton) {
                        refreshButton.addEventListener('click', function() {
                            window.location.reload();
                        });
                    }

                    document.querySelectorAll('[data-open-keyword-list-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openListDetail(button.getAttribute('data-list-id'));
                        });
                    });

                    openImportButtons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            openModal(importModal);
                        });
                    });

                    document.querySelectorAll('[data-open-keyword-generate-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            openGenerateModal(button.getAttribute('data-list-id'));
                        });
                    });

                    document.querySelectorAll('[data-close-keyword-list-modal]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(listModal);
                        });
                    });

                    closeImportButtons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(importModal);
                        });
                    });

                    if (listBackdrop) {
                        listBackdrop.addEventListener('click', function() {
                            closeModal(listModal);
                        });
                    }

                    if (importBackdrop) {
                        importBackdrop.addEventListener('click', function() {
                            closeModal(importModal);
                        });
                    }

                    if (saveMapButton) {
                        saveMapButton.addEventListener('click', saveCurrentListMap);
                    }

                    if (deleteCurrentListButton) {
                        deleteCurrentListButton.addEventListener('click', function() {
                            if (currentDetailList) {
                                deleteListById(currentDetailList.id, listModalStatus);
                            }
                        });
                    }

                    if (openGenerateFromListButton) {
                        openGenerateFromListButton.addEventListener('click', function() {
                            if (currentDetailList) {
                                closeModal(listModal);
                                openGenerateModal(currentDetailList.id);
                            }
                        });
                    }

                    if (generateBackdrop) {
                        generateBackdrop.addEventListener('click', function() {
                            closeModal(generateModal);
                        });
                    }

                    generateCancelButtons.forEach(function(button) {
                        button.addEventListener('click', function() {
                            closeModal(generateModal);
                        });
                    });

                    if (generateAddFilterButton) {
                        generateAddFilterButton.addEventListener('click', function() {
                            if (!generateFiltersContainer) {
                                return;
                            }
                            generateFiltersContainer.insertAdjacentHTML('beforeend', renderGenerateFilterRow({}));
                            updateGenerateTargetSummary();
                            scheduleGenerateCountRefresh();
                        });
                    }

                    if (generateFiltersContainer) {
                        generateFiltersContainer.addEventListener('click', function(event) {
                            var button = event.target.closest('[data-remove-filter]');
                            if (!button) {
                                return;
                            }
                            var row = button.closest('[data-generate-filter-row]');
                            if (row) {
                                row.remove();
                            }
                            if (generateFiltersContainer.querySelectorAll('[data-generate-filter-row]').length === 0) {
                                generateFiltersContainer.innerHTML = renderGenerateFilterRow({});
                            }
                            updateGenerateTargetSummary();
                            scheduleGenerateCountRefresh();
                        });
                        generateFiltersContainer.addEventListener('input', function() {
                            updateGenerateTargetSummary();
                            scheduleGenerateCountRefresh();
                        });
                        generateFiltersContainer.addEventListener('change', function() {
                            updateGenerateTargetSummary();
                            scheduleGenerateCountRefresh();
                        });
                    }

                    if (generateRequestedInput) {
                        generateRequestedInput.addEventListener('input', function() {
                            updateGenerateTargetSummary();
                        });
                        generateRequestedInput.addEventListener('change', function() {
                            updateGenerateTargetSummary();
                        });
                    }

                    if (generateRefreshCountButton) {
                        generateRefreshCountButton.addEventListener('click', function() {
                            refreshGenerateAvailability();
                        });
                    }

                    if (generateRunButton) {
                        generateRunButton.addEventListener('click', runGenerateBatch);
                    }

                    if (generateRunCtaButton) {
                        generateRunCtaButton.addEventListener('click', runGenerateBatch);
                    }

                    document.querySelectorAll('[data-delete-keyword-list-id]').forEach(function(button) {
                        button.addEventListener('click', function() {
                            deleteListById(button.getAttribute('data-delete-keyword-list-id'));
                        });
                    });

                    if (fileInput) {
                        fileInput.addEventListener('change', function() {
                            currentPreview = null;
                            currentUploadFile = null;
                            if (previewPanel) {
                                previewPanel.classList.add('hidden');
                            }
                            if (previewSummary) {
                                previewSummary.textContent = '';
                            }
                            if (previewTable) {
                                previewTable.innerHTML = '';
                            }
                            setStatus(uploadStatus, '', '');
                        });
                    }

                    document.addEventListener('keydown', function(event) {
                        if (event.key === 'Escape') {
                            if (importModal && !importModal.classList.contains('hidden')) {
                                closeModal(importModal);
                                return;
                            }
                            if (generateModal && !generateModal.classList.contains('hidden')) {
                                closeModal(generateModal);
                                return;
                            }
                            if (listModal && !listModal.classList.contains('hidden')) {
                                closeModal(listModal);
                            }
                        }
                    });
                })();
            </script>
        </div>
<?php
    }

    public static function render_notice()
    {
        if (empty($_GET['arc_notice'])) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (in_array($page, array('alpha-rss-ai-generated-posts', 'alpha-rss-ai-link-suggestions', 'alpha-rss-ai-content-plans'), true)) {
            return;
        }

        $type = isset($_GET['arc_notice_type']) ? sanitize_key(wp_unslash($_GET['arc_notice_type'])) : 'success';
        $class = 'notice notice-' . ($type === 'error' ? 'error' : 'success');
        $message = sanitize_text_field(wp_unslash($_GET['arc_notice']));
        $link = isset($_GET['arc_notice_link']) ? esc_url_raw(wp_unslash($_GET['arc_notice_link'])) : '';

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message);
        if ($link !== '' && $type !== 'error') {
            echo ' <a href="' . esc_url($link) . '" target="_blank" rel="noopener noreferrer" class="ml-2 inline-flex items-center rounded-md border border-current/20 px-2 py-0.5 text-xs font-semibold text-inherit no-underline">Abrir conteúdo</a>';
        }
        echo '</p></div>';
    }

    public static function get_post_status_label($status)
    {
        $map = array(
            'draft' => 'Rascunho',
            'publish' => 'Publicado',
            'pending' => 'Pendente',
            'private' => 'Privado',
            'future' => 'Agendado',
        );
        return isset($map[$status]) ? $map[$status] : ucfirst((string) $status);
    }
}

