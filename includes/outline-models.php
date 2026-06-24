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
        }

        public static function get_default_models()
        {
            return array();
        }

        public static function get_models()
        {
            $models = get_option(self::OPTION_KEY, array());
            if (!is_array($models) || empty($models)) {
                return array();
            }

            return Alpha_RSS_AI_Generator::normalize_outline_models($models);
        }

        public function admin_menu()
        {
            if (empty(self::get_models())) {
                return;
            }

            add_submenu_page(
                'alpha-rss-ai-generator',
                'Modelos de outline',
                'Modelos de outline',
                'manage_options',
                'alpha-rss-ai-outline-models',
                array($this, 'render_page')
            );
        }

        public function render_page()
        {
            if (!current_user_can('manage_options')) {
                wp_die('Acesso negado.');
            }

            $models = self::get_models();
            ?>
            <div class="wrap">
                <h1>Modelos de outline</h1>
                <p>Nenhum modelo de outline padrao esta configurado. O outline passa a depender apenas da configuracao salva no gerador.</p>
                <?php if (empty($models)): ?>
                    <div class="notice notice-info inline">
                        <p>Nao ha modelos de outline salvos no momento.</p>
                    </div>
                <?php else: ?>
                <table class="widefat striped" style="max-width: 1000px;">
                    <thead>
                        <tr>
                            <th>Chave</th>
                            <th>Nome</th>
                            <th>Faixa de H2</th>
                            <th>Blocos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($models as $model): ?>
                            <tr>
                                <td><?php echo esc_html(isset($model['key']) ? $model['key'] : ''); ?></td>
                                <td><?php echo esc_html(isset($model['name']) ? $model['name'] : ''); ?></td>
                                <td><?php echo esc_html((isset($model['target_h2_min']) ? intval($model['target_h2_min']) : 0) . ' - ' . (isset($model['target_h2_max']) ? intval($model['target_h2_max']) : 0)); ?></td>
                                <td><?php echo esc_html(isset($model['blocks']) && is_array($model['blocks']) ? count($model['blocks']) : 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}
