<?php
/**
 * Plugin Name: Vigilante de WordPress
 * Description: Monitor de segurança — usuários, arquivos, logins, plugins — com SMTP integrado, relatórios por e-mail e diagnóstico.
 * Version: 0.1.0-beta
 * Author: Allan Nascimento
 * Author URI: mailto:nascimento.allang@gmail.com
 * Text Domain: vigilante-wp
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

// Constantes do plugin
define('VIGILANTE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIGILANTE_PLUGIN_FILE', __FILE__);
define('VIGILANTE_VERSION', '0.1.0-beta');

// Carregar classes
require_once VIGILANTE_PLUGIN_DIR . 'includes/class-vigilante-logger.php';
require_once VIGILANTE_PLUGIN_DIR . 'includes/class-vigilante-email.php';
require_once VIGILANTE_PLUGIN_DIR . 'includes/class-vigilante-file-scanner.php';
require_once VIGILANTE_PLUGIN_DIR . 'includes/class-vigilante-monitor.php';
require_once VIGILANTE_PLUGIN_DIR . 'includes/class-vigilante-admin.php';
require_once VIGILANTE_PLUGIN_DIR . 'includes/class-vigilante-updater.php';

/**
 * Inicialização do plugin.
 */
function vigilante_init() {
    Vigilante_Email::init();
    Vigilante_Monitor::init();

    if (is_admin()) {
        Vigilante_Admin::init();
        Vigilante_Updater::init();
    }
}
add_action('plugins_loaded', 'vigilante_init');

/**
 * Cron: relatório diário.
 */
add_action('vigilante_daily_report', function () {
    Vigilante_Email::send_daily_report();
});

/**
 * Cron: verificação horária de arquivos.
 */
add_action('vigilante_hourly_file_check', function () {
    $changes = Vigilante_File_Scanner::check_changes();

    if (!empty($changes)) {
        $lines = array_map(
            fn($c) => "{$c['action']}: {$c['path']}",
            array_slice($changes, 0, 50)
        );
        $count = count($changes);
        $msg = "{$count} alteração(ões) detectada(s) nos arquivos:\n" . implode("\n", $lines);

        $settings = get_option('vigilante_settings', []);
        $should_alert = !empty($settings['alert_file_changes']);

        Vigilante_Logger::log_event('arquivo_alterado', $msg, $should_alert);

        if ($should_alert) {
            Vigilante_Email::send_critical_alert('arquivo_alterado', $msg);
        }
    }
});

/**
 * Ativação do plugin.
 */
function vigilante_activate() {
    // Configurações padrão
    if (!get_option('vigilante_settings')) {
        update_option('vigilante_settings', [
            'email'              => get_option('admin_email'),
            'alert_new_admin'    => true,
            'alert_file_changes' => true,
            'alert_login_failed' => true,
            'max_failed_logins'  => 5,
        ]);
    }

    // Carregar classes necessárias para ativação
    if (!class_exists('Vigilante_File_Scanner')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-vigilante-file-scanner.php';
    }

    // Snapshot inicial dos arquivos
    Vigilante_File_Scanner::take_snapshot();

    // Agendar crons
    if (!wp_next_scheduled('vigilante_daily_report')) {
        wp_schedule_event(time(), 'daily', 'vigilante_daily_report');
    }
    if (!wp_next_scheduled('vigilante_hourly_file_check')) {
        wp_schedule_event(time(), 'hourly', 'vigilante_hourly_file_check');
    }
}
register_activation_hook(__FILE__, 'vigilante_activate');

/**
 * Desativação do plugin.
 */
function vigilante_deactivate() {
    wp_clear_scheduled_hook('vigilante_daily_report');
    wp_clear_scheduled_hook('vigilante_hourly_file_check');
}
register_deactivation_hook(__FILE__, 'vigilante_deactivate');

/**
 * Migração da versão anterior (arquivo único).
 * Transfere dados se existirem com os nomes antigos.
 */
function vigilante_maybe_migrate() {
    // Migrar logs antigos
    $old_logs = get_option('osb_security_log');
    if ($old_logs !== false && get_option(Vigilante_Logger::LOG_OPTION) === false) {
        update_option(Vigilante_Logger::LOG_OPTION, $old_logs, false);
        delete_option('osb_security_log');
    }

    // Migrar configurações antigas
    $old_settings = get_option('osb_monitor_settings');
    if ($old_settings !== false && get_option('vigilante_settings') === false) {
        update_option('vigilante_settings', $old_settings);
        delete_option('osb_monitor_settings');
    }

    // Migrar snapshot antigo
    $old_snapshot = get_option('osb_file_snapshot');
    if ($old_snapshot !== false && get_option(Vigilante_File_Scanner::SNAPSHOT_OPTION) === false) {
        update_option(Vigilante_File_Scanner::SNAPSHOT_OPTION, $old_snapshot, false);
        delete_option('osb_file_snapshot');
    }
}
add_action('admin_init', 'vigilante_maybe_migrate');
