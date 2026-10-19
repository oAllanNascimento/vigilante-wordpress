<?php
/**
 * Vigilante_Monitor
 *
 * Monitoramento de eventos de segurança, usuários, plugins, temas, logins.
 */

if (!defined('ABSPATH')) exit;

class Vigilante_Monitor {

    /**
     * Registra todos os hooks de monitoramento.
     */
    public static function init() {
        // Usuários
        add_action('user_register',  [__CLASS__, 'on_user_created']);
        add_action('set_user_role',  [__CLASS__, 'on_role_changed'], 10, 3);
        add_action('delete_user',    [__CLASS__, 'on_user_deleted']);
        add_action('wp_login',       [__CLASS__, 'on_user_login'], 10, 2);
        add_action('wp_login_failed',[__CLASS__, 'on_login_failed']);

        // Plugins e temas
        add_action('activated_plugin',   [__CLASS__, 'on_plugin_activated']);
        add_action('deactivated_plugin', [__CLASS__, 'on_plugin_deactivated']);
        add_action('switch_theme',       [__CLASS__, 'on_theme_switched']);
        add_action('upgrader_process_complete', [__CLASS__, 'on_update_complete'], 10, 2);
    }

    // ─── HELPERS DE CONFIGURAÇÃO ───

    /**
     * Retorna as configurações do plugin.
     */
    private static function get_settings() {
        return get_option('vigilante_settings', []);
    }

    /**
     * Verifica se um tipo de alerta está habilitado nas configurações.
     */
    private static function is_alert_enabled($alert_key) {
        $settings = self::get_settings();
        return !empty($settings[$alert_key]);
    }

    // ─── USUÁRIOS ───

    public static function on_user_created($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $roles = implode(', ', $user->roles);
        $msg = "Novo usuário criado: {$user->user_login} ({$user->user_email}), Perfil: {$roles}";

        $is_admin = in_array('administrator', $user->roles);
        $should_alert = $is_admin && self::is_alert_enabled('alert_new_admin');
        self::log_and_alert('usuario_criado', $msg, $should_alert);
    }

    public static function on_role_changed($user_id, $new_role, $old_roles) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $old = implode(', ', $old_roles);
        $msg = "Perfil alterado: {$user->user_login}, de [{$old}] para [{$new_role}]";

        $should_alert = $new_role === 'administrator' && self::is_alert_enabled('alert_new_admin');
        self::log_and_alert('perfil_alterado', $msg, $should_alert);
    }

    public static function on_user_deleted($user_id) {
        $user = get_userdata($user_id);
        $name = $user ? $user->user_login : "ID {$user_id}";

        self::log_and_alert('usuario_removido', "Usuário removido: {$name}");
    }

    public static function on_user_login($user_login, $user) {
        $roles = implode(', ', $user->roles);
        Vigilante_Logger::log_event('login', "Login realizado: {$user_login} ({$roles})");
    }

    public static function on_login_failed($username) {
        $safe_username = sanitize_user($username);
        Vigilante_Logger::log_event('login_falhou', "Tentativa de login falhou para: {$safe_username}");

        // Verificar brute force apenas se alerta estiver habilitado
        if (!self::is_alert_enabled('alert_login_failed')) return;

        $settings = self::get_settings();
        $max = $settings['max_failed_logins'] ?? 5;
        $recent_logs = Vigilante_Logger::get_recent_logs(1);
        $recent_fails = 0;

        foreach ($recent_logs as $log) {
            if ($log['type'] === 'login_falhou') {
                $recent_fails++;
            }
        }

        if ($recent_fails >= $max) {
            self::log_and_alert(
                'ataque_bruta_forca',
                "{$recent_fails} tentativas de login falhas na última hora",
                true
            );
        }
    }

    // ─── PLUGINS E TEMAS ───

    public static function on_plugin_activated($plugin) {
        // 1.6.0: ativação pelo shell do servidor (WP-CLI) ou pelo agendador, durante
        // update, é nossa ou automática: fica no log, sem alerta.
        $via_cli = defined('WP_CLI') && WP_CLI;
        $durante_update = (function_exists('wp_doing_cron') && wp_doing_cron())
            || did_action('upgrader_process_complete') > 0;
        $alerta = Vigilante_Regua::ativacao_alerta($via_cli, $durante_update);
        $origem = $via_cli ? ' (WP-CLI)' : ($durante_update ? ' (durante atualização)' : '');
        self::log_and_alert('plugin_ativado', "Plugin ativado: {$plugin}{$origem}", $alerta);
    }

    public static function on_plugin_deactivated($plugin) {
        Vigilante_Logger::log_event('plugin_desativado', "Plugin desativado: {$plugin}");
    }

    public static function on_theme_switched($new_name) {
        self::log_and_alert('tema_alterado', "Tema alterado para: {$new_name}", true);
    }

    public static function on_update_complete($upgrader, $options) {
        $type   = $options['type'] ?? 'desconhecido';
        $action = $options['action'] ?? '';
        Vigilante_Logger::log_event('atualizacao', "Atualização realizada: {$type} ({$action})");

        // Guarda o que foi atualizado, para a varredura de arquivos da próxima hora
        // saber que aqueles arquivos mudaram por update (Vigilante_Regua).
        $itens = [];
        if ($type === 'plugin') {
            $itens = (array) ($options['plugins'] ?? (isset($options['plugin']) ? [$options['plugin']] : []));
        } elseif ($type === 'theme') {
            $itens = (array) ($options['themes'] ?? (isset($options['theme']) ? [$options['theme']] : []));
        }
        $lista   = self::updates_recentes();
        $lista[] = ['tipo' => $type, 'itens' => array_values($itens), 'quando' => time()];
        update_option(self::UPDATES_OPTION, array_slice($lista, -50), false);
    }

    const UPDATES_OPTION = 'vigilante_updates_recentes';

    /** Updates das últimas 24 horas (o resto é podado na leitura). */
    public static function updates_recentes() {
        $lista = (array) get_option(self::UPDATES_OPTION, []);
        return array_values(array_filter($lista, fn($u) => (time() - (int) ($u['quando'] ?? 0)) < DAY_IN_SECONDS));
    }

    // ─── HELPER ───

    private static function log_and_alert($type, $message, $critical = false) {
        Vigilante_Logger::log_event($type, $message, $critical);

        if ($critical) {
            Vigilante_Email::send_critical_alert($type, $message);
        }
    }
}
