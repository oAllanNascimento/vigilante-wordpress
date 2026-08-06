<?php
/**
 * Desinstalação do Vigilante de WordPress.
 *
 * Executado pelo WordPress quando o plugin é removido (não apenas desativado).
 * Remove todas as opções, transients e tarefas agendadas criadas pelo plugin,
 * sem deixar resíduo no banco de dados.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove todos os dados do plugin em um único site.
 */
function vigilante_uninstall_cleanup() {
    // Opções do plugin.
    $options = [
        'vigilante_settings',
        'vigilante_security_log',
        'vigilante_email_log',
        'vigilante_file_snapshot',
        'vigilante_file_alert_state',
        'vigilante_migrou_semanal',
        'vigilante_caixa',
        'vigilante_crypto_key',
        // Opções legadas da versão em arquivo único.
        'osb_security_log',
        'osb_monitor_settings',
        'osb_file_snapshot',
    ];
    foreach ($options as $option) {
        delete_option($option);
    }

    // Transients (cache e locks).
    delete_transient('vigilante_lock_security_log');
    delete_transient('vigilante_lock_email_log');
    delete_transient('vigilante_lock_caixa');
    delete_transient('vigilante_github_update');

    // Tarefas agendadas.
    wp_clear_scheduled_hook('vigilante_daily_report');
    wp_clear_scheduled_hook('vigilante_hourly_file_check');
}

// Em multisite, limpa cada site da rede.
if (is_multisite()) {
    $site_ids = get_sites(['fields' => 'ids']);
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        vigilante_uninstall_cleanup();
        restore_current_blog();
    }
} else {
    vigilante_uninstall_cleanup();
}
