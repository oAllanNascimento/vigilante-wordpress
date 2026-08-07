<?php
/**
 * Plugin Name: Vigilante de WordPress
 * Description: Monitor de segurança do WordPress (usuários, arquivos, logins, plugins) com SMTP integrado, relatórios por e-mail e diagnóstico.
 * Version: 1.3.0
 * Author: Allan Nascimento
 * Author URI: mailto:nascimento.allang@gmail.com
 * Text Domain: vigilante-de-wordpress
 * Requires at least: 5.6
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

// Constantes do plugin
define('VIGILANTE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIGILANTE_PLUGIN_FILE', __FILE__);
define('VIGILANTE_VERSION', '1.3.0');

// Carregar classes
require_once VIGILANTE_PLUGIN_DIR . 'includes/class-vigilante-logger.php';
require_once VIGILANTE_PLUGIN_DIR . 'includes/class-vigilante-caixa.php';
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

    if (empty($changes)) {
        return;
    }

    // Achado repetido dentro da janela não vira e-mail de novo, mas continua no
    // log. Sem isso, um arquivo que legitimamente some e volta todo dia gera um
    // alerta por dia pra sempre, e o canal vira ruído que ninguém abre.
    list($novos, $repetidos) = Vigilante_File_Scanner::separa_repetidos($changes);

    $linhas = array_map(
        fn($c) => "{$c['action']}: {$c['path']}",
        array_slice($novos, 0, 50)
    );

    $msg = count($novos) . " alteração(ões) detectada(s) nos arquivos:\n" . implode("\n", $linhas);

    if (!empty($repetidos)) {
        $rep = array_map(
            fn($c) => "{$c['action']}: {$c['path']} (" . ($c['vezes'] ?? 2) . "ª vez em 24h)",
            array_slice($repetidos, 0, 20)
        );
        $msg .= "\n\nRepetidos, já avisados nas últimas 24h (sem e-mail novo):\n" . implode("\n", $rep);
    }

    $settings     = get_option('vigilante_settings', []);
    $should_alert = !empty($settings['alert_file_changes']) && !empty($novos);

    Vigilante_Logger::log_event('arquivo_alterado', $msg, $should_alert);

    if ($should_alert) {
        Vigilante_Email::send_critical_alert('arquivo_alterado', $msg);
    }
});

/**
 * (Re)agenda o relatório periódico conforme a frequência escolhida.
 *
 * @param string $frequency 'daily' ou 'weekly'.
 */
function vigilante_schedule_report($frequency = 'daily') {
    $recurrence = ($frequency === 'weekly') ? 'weekly' : 'daily';
    wp_clear_scheduled_hook('vigilante_daily_report');
    wp_schedule_event(time() + HOUR_IN_SECONDS, $recurrence, 'vigilante_daily_report');
}

/**
 * Ativação do plugin.
 */
function vigilante_activate() {
    // Configurações padrão.
    // O relatório periódico nasce SEMANAL: o que é urgente já sai na hora, como
    // alerta próprio, e um resumo por dia só treina quem lê a arquivar sem abrir.
    if (!get_option('vigilante_settings')) {
        update_option('vigilante_settings', [
            'email'              => get_option('admin_email'),
            'alert_new_admin'    => true,
            'alert_file_changes' => true,
            'alert_login_failed' => true,
            'email_critical_alerts' => true,
            'max_failed_logins'  => 5,
            'report_frequency'   => 'weekly',
        ]);
    }

    // Carregar classes necessárias para ativação
    if (!class_exists('Vigilante_File_Scanner')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-vigilante-file-scanner.php';
    }

    // Snapshot inicial dos arquivos
    Vigilante_File_Scanner::take_snapshot();

    // Agendar crons
    $settings = get_option('vigilante_settings', []);
    vigilante_schedule_report($settings['report_frequency'] ?? 'daily');

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

    // 1.2.0: o relatório passa a ser semanal em quem já estava instalado, e não
    // só em instalação nova.
    //
    // A marca é uma opção PRÓPRIA, e não a versão do plugin: marcada por versão,
    // a troca voltaria a acontecer a cada atualização e desfaria, calada, a
    // escolha de quem preferiu diário no painel. Migração de uma vez é de uma
    // vez pra sempre.
    if (!get_option('vigilante_migrou_semanal')) {
        $settings = get_option('vigilante_settings', []);
        if (($settings['report_frequency'] ?? 'daily') === 'daily') {
            $settings['report_frequency'] = 'weekly';
            update_option('vigilante_settings', $settings);
            vigilante_schedule_report('weekly');
        }
        update_option('vigilante_migrou_semanal', 1);
    }
}
// 'init' e não 'admin_init': a migração de 1.2.0 muda o agendamento do relatório,
// e site que ninguém abre no painel (o caso comum nos que a gente só monitora)
// ficaria na configuração antiga pra sempre. No 'init' ela pega qualquer request,
// inclusive o do cron, e custa uma leitura de option já autoloaded.
add_action('init', 'vigilante_maybe_migrate');
