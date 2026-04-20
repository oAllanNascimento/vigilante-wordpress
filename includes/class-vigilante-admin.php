<?php
/**
 * Vigilante_Admin
 *
 * Página de administração do plugin — configurações, logs e diagnóstico.
 */

if (!defined('ABSPATH')) exit;

class Vigilante_Admin {

    const SETTINGS_OPTION = 'vigilante_settings';

    /**
     * Registra o menu admin.
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
    }

    /**
     * Adiciona menu no painel.
     */
    public static function add_menu() {
        add_menu_page(
            'Vigilante de WordPress',
            'Vigilante WP',
            'manage_options',
            'vigilante-wp',
            [__CLASS__, 'render_page'],
            'dashicons-shield',
            100
        );
    }

    /**
     * CSS inline para a página admin.
     */
    public static function enqueue_styles($hook) {
        if ($hook !== 'toplevel_page_vigilante-wp') return;

        wp_add_inline_style('wp-admin', '
            .vigilante-wrap { max-width: 960px; }
            .vigilante-tabs { display: flex; gap: 0; margin-bottom: 20px; border-bottom: 2px solid #2271b1; }
            .vigilante-tab { padding: 10px 20px; cursor: pointer; background: #f0f0f1; border: 1px solid #c3c4c7; border-bottom: none; margin-bottom: -2px; text-decoration: none; color: #1d2327; }
            .vigilante-tab.active { background: #fff; border-bottom: 2px solid #fff; font-weight: 600; }
            .vigilante-panel { display: none; }
            .vigilante-panel.active { display: block; }
            .vigilante-diag { background: #f6f7f7; border: 1px solid #c3c4c7; padding: 15px; margin: 10px 0; }
            .vigilante-diag dt { font-weight: 600; margin-top: 10px; }
            .vigilante-diag dd { margin-left: 0; padding: 2px 0; }
            .vigilante-status-ok { color: #00a32a; font-weight: 600; }
            .vigilante-status-warn { color: #dba617; font-weight: 600; }
            .vigilante-status-fail { color: #d63638; font-weight: 600; }
            .vigilante-email-log { max-height: 300px; overflow-y: auto; }
            .vigilante-critical-row { background: #FFF3E0 !important; }
            .vigilante-email-failure-notice h3 { color: #d63638; }
            .vigilante-guia-rapido { background: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; margin: 10px 0; }
            .vigilante-guia-rapido h4 { margin: 0 0 8px; }
            .vigilante-guia-rapido ol, .vigilante-guia-rapido ul { margin: 4px 0 8px 20px; }
            .vigilante-guia-rapido li { margin-bottom: 6px; }
            .vigilante-guia-completo { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 15px 0; }
            .vigilante-guia-completo h3 { margin-top: 0; border-bottom: 2px solid #2271b1; padding-bottom: 8px; }
            .vigilante-guia-completo h4 { color: #2271b1; margin: 18px 0 8px; }
            .vigilante-guia-completo ol { margin: 8px 0 8px 20px; }
            .vigilante-guia-completo ol li { margin-bottom: 8px; line-height: 1.6; }
            .vigilante-guia-completo .guia-provedor { background: #f6f7f7; border-left: 3px solid #2271b1; padding: 10px 14px; margin: 10px 0; }
            .vigilante-guia-completo .guia-provedor h5 { margin: 0 0 6px; }
            .vigilante-guia-completo .guia-dica { background: #fcf9e8; border-left: 3px solid #dba617; padding: 10px 14px; margin: 10px 0; }
        ');
    }

    /**
     * Processa ações POST e renderiza a página.
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        // Processar ações
        self::handle_actions();

        $settings   = get_option(self::SETTINGS_OPTION, []);
        $logs       = array_reverse(Vigilante_Logger::get_logs());
        $email_logs = array_reverse(Vigilante_Logger::get_email_logs());
        $diag       = Vigilante_Email::get_diagnostics();
        $snapshot   = Vigilante_File_Scanner::get_snapshot_info();

        include VIGILANTE_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Verifica o nonce para uma ação específica.
     */
    private static function verify_nonce($action) {
        $nonce_field = '_wpnonce_' . $action;
        return isset($_POST[$nonce_field]) && wp_verify_nonce($_POST[$nonce_field], 'vigilante_' . $action);
    }

    /**
     * Processa ações do formulário.
     * Cada ação tem seu próprio nonce para granularidade CSRF.
     */
    private static function handle_actions() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        if (isset($_POST['vigilante_save_settings']) && self::verify_nonce('settings')) {
            $old_settings = get_option(self::SETTINGS_OPTION, []);
            $settings = [
                'email'              => sanitize_email($_POST['vigilante_email'] ?? ''),
                'alert_new_admin'    => isset($_POST['vigilante_alert_admin']),
                'alert_file_changes' => isset($_POST['vigilante_alert_files']),
                'alert_login_failed' => isset($_POST['vigilante_alert_login']),
                'max_failed_logins'  => max(3, min(50, intval($_POST['vigilante_max_failed'] ?? 5))),
                // SMTP
                'smtp_enabled'       => isset($_POST['vigilante_smtp_enabled']),
                'smtp_host'          => sanitize_text_field($_POST['vigilante_smtp_host'] ?? ''),
                'smtp_port'          => max(1, min(65535, intval($_POST['vigilante_smtp_port'] ?? 587))),
                'smtp_encryption'    => in_array($_POST['vigilante_smtp_encryption'] ?? '', ['tls', 'ssl', 'none']) ? $_POST['vigilante_smtp_encryption'] : 'tls',
                'smtp_user'          => sanitize_text_field($_POST['vigilante_smtp_user'] ?? ''),
                'smtp_from'          => sanitize_email($_POST['vigilante_smtp_from'] ?? ''),
                'smtp_from_name'     => sanitize_text_field($_POST['vigilante_smtp_from_name'] ?? 'Vigilante WP'),
            ];

            // Senha SMTP: só atualiza se o campo foi preenchido (evita apagar ao salvar)
            $new_pass = $_POST['vigilante_smtp_pass'] ?? '';
            if ($new_pass !== '') {
                $settings['smtp_pass'] = Vigilante_Email::encrypt_password($new_pass);
            } else {
                $settings['smtp_pass'] = $old_settings['smtp_pass'] ?? '';
            }

            update_option(self::SETTINGS_OPTION, $settings);
            self::notice('Configurações salvas.');
        }

        if (isset($_POST['vigilante_test_email']) && self::verify_nonce('settings')) {
            $has_smtp = !empty(Vigilante_Email::get_diagnostics()['smtp_plugins']);
            if (!$has_smtp) {
                self::notice_no_smtp();
            } else {
                $sent = Vigilante_Email::send_test();
                if ($sent) {
                    $email = get_option(self::SETTINGS_OPTION, [])['email'] ?? get_option('admin_email');
                    self::notice("E-mail de teste enviado para <strong>" . esc_html($email) . "</strong>. Verifique sua caixa (e o spam).");
                } else {
                    $error = Vigilante_Email::get_last_error();
                    self::notice_email_failure($error);
                }
            }
        }

        if (isset($_POST['vigilante_send_report']) && self::verify_nonce('settings')) {
            $has_smtp = !empty(Vigilante_Email::get_diagnostics()['smtp_plugins']);
            if (!$has_smtp) {
                self::notice_no_smtp();
            } else {
                $sent = Vigilante_Email::send_daily_report();
                if ($sent) {
                    self::notice('Relatório enviado por e-mail.');
                } else {
                    $error = Vigilante_Email::get_last_error();
                    self::notice_email_failure($error);
                }
            }
        }

        if (isset($_POST['vigilante_reset_snapshot']) && self::verify_nonce('settings')) {
            Vigilante_File_Scanner::take_snapshot();
            self::notice('Snapshot dos arquivos atualizado.');
        }

        if (isset($_POST['vigilante_clear_logs']) && self::verify_nonce('clear_logs')) {
            Vigilante_Logger::clear_logs();
            self::notice('Logs de segurança limpos.');
        }

        if (isset($_POST['vigilante_clear_email_logs']) && self::verify_nonce('clear_email_logs')) {
            Vigilante_Logger::clear_email_logs();
            self::notice('Logs de email limpos.');
        }
    }

    /**
     * Exibe notice no admin.
     * $message é escapado por padrão — apenas tags seguras são permitidas.
     */
    private static function notice($message, $type = 'updated') {
        $allowed_html = [
            'strong' => [],
            'em'     => [],
            'code'   => [],
        ];
        echo '<div class="' . esc_attr($type) . '"><p>' . wp_kses($message, $allowed_html) . '</p></div>';
    }

    /**
     * Exibe erro quando não há SMTP configurado.
     * Bloqueia o envio — sem SMTP não tenta enviar.
     */
    private static function notice_no_smtp() {
        ?>
        <div class="error vigilante-email-failure-notice">
            <h3 style="margin-top:10px;">&#9888; SMTP não configurado — e-mail não enviado</h3>
            <p>Sem SMTP configurado, os e-mails <strong>não serão entregues</strong>.
            Configure o SMTP antes de testar o envio.</p>

            <div class="vigilante-guia-rapido">
                <h4>Como configurar:</h4>
                <ol>
                    <li>Nesta mesma página, na seção <strong>Configuração SMTP</strong>, marque <strong>"Ativar SMTP"</strong></li>
                    <li>Preencha os dados do seu provedor de e-mail (servidor, porta, usuário e senha)</li>
                    <li>Clique em <strong>Salvar Configurações</strong></li>
                    <li>Depois clique em <strong>Testar E-mail</strong> novamente</li>
                </ol>
                <p><em>Consulte a tabela de provedores na seção SMTP para ver os dados do seu e-mail.</em></p>
            </div>
        </div>
        <?php
    }

    /**
     * Exibe notice detalhado quando o envio de e-mail falha,
     * com guia resumido de configuração para leigos.
     */
    private static function notice_email_failure($error) {
        $error_msg = $error ? esc_html($error) : 'Erro desconhecido';
        $has_smtp  = !empty(Vigilante_Email::get_diagnostics()['smtp_plugins']);
        ?>
        <div class="error vigilante-email-failure-notice">
            <h3 style="margin-top:10px;">&#9888; Falha ao enviar e-mail de teste</h3>
            <p><strong>Erro:</strong> <?php echo $error_msg; ?></p>

            <?php if (!$has_smtp): ?>
            <div class="vigilante-guia-rapido">
                <h4>Como resolver (passo a passo):</h4>
                <ol>
                    <li>Nesta mesma página, na seção <strong>Configuração SMTP</strong>, marque <strong>"Ativar SMTP"</strong></li>
                    <li>Preencha os dados do seu provedor de e-mail (veja a tabela de referência)</li>
                    <li>Clique em <strong>Salvar Configurações</strong></li>
                    <li>Clique em <strong>Testar E-mail</strong> novamente</li>
                </ol>
            </div>
            <?php else: ?>
            <div class="vigilante-guia-rapido">
                <h4>Possíveis causas:</h4>
                <ul>
                    <li>O servidor SMTP, usuário ou senha podem estar incorretos</li>
                    <li>O endereço de e-mail de destino pode estar errado</li>
                    <li>Seu provedor de e-mail pode estar bloqueando a conexão</li>
                    <li>Se usa Gmail, verifique se está usando uma <strong>Senha de App</strong> (não a senha normal)</li>
                </ul>
                <p><em>Veja detalhes na aba <strong>Diagnóstico de E-mail</strong>.</em></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
