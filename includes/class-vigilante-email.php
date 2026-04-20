<?php
/**
 * Vigilante_Email
 *
 * Gerencia envio de emails com diagnóstico, captura de falhas e logging.
 */

if (!defined('ABSPATH')) exit;

class Vigilante_Email {

    private static $last_error = '';

    /**
     * Inicializa captura de erros e configuração SMTP.
     */
    public static function init() {
        add_action('wp_mail_failed', [__CLASS__, 'on_mail_failed']);
        add_action('phpmailer_init', [__CLASS__, 'configure_smtp']);
    }

    /**
     * Configura o PHPMailer com SMTP se as configurações existirem.
     *
     * Só atua se:
     * 1. O SMTP do Vigilante estiver explicitamente habilitado
     * 2. NÃO houver outro plugin SMTP já configurando o PHPMailer
     *
     * Isso garante que nunca sobrescreve configurações de plugins como
     * WP Mail SMTP, FluentSMTP, Easy WP SMTP, Post SMTP, etc.
     */
    public static function configure_smtp($phpmailer) {
        $settings = get_option('vigilante_settings', []);

        // Não habilitado pelo usuário — não faz nada
        if (empty($settings['smtp_enabled']) || empty($settings['smtp_host'])) {
            return;
        }

        // Se outro plugin SMTP já configurou o PHPMailer para SMTP, não interfere
        if (self::has_external_smtp_plugin()) {
            return;
        }

        // Se o PHPMailer já foi configurado como SMTP por outro meio, não sobrescreve
        if ($phpmailer->Mailer === 'smtp' && !empty($phpmailer->Host) && $phpmailer->Host !== 'localhost') {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = $settings['smtp_host'];
        $phpmailer->Port       = intval($settings['smtp_port'] ?? 587);
        $phpmailer->SMTPSecure = ($settings['smtp_encryption'] ?? 'tls') === 'none' ? '' : ($settings['smtp_encryption'] ?? 'tls');

        if (!empty($settings['smtp_user'])) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $settings['smtp_user'];
            $phpmailer->Password = self::decrypt_password($settings['smtp_pass'] ?? '');
        } else {
            $phpmailer->SMTPAuth = false;
        }

        // Remetente configurado
        if (!empty($settings['smtp_from'])) {
            $phpmailer->From     = $settings['smtp_from'];
            $phpmailer->FromName = $settings['smtp_from_name'] ?? 'Vigilante WP';
        }
    }

    /**
     * Verifica se há um plugin SMTP externo ativo.
     */
    private static function has_external_smtp_plugin() {
        return class_exists('WPMailSMTP\Core')
            || class_exists('Easy_WP_SMTP')
            || class_exists('FluentMail\App\Module')
            || class_exists('PostmanOptions')
            || defined('JEsuspended_EMAIL');
    }

    /**
     * Criptografa a senha SMTP para armazenamento.
     * Usa AUTH_KEY do WordPress como chave (disponível em toda instalação).
     */
    public static function encrypt_password($plain) {
        if (empty($plain)) return '';
        $key = self::get_encryption_key();
        $iv  = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plain, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    /**
     * Descriptografa a senha SMTP.
     */
    private static function decrypt_password($stored) {
        if (empty($stored)) return '';
        $key  = self::get_encryption_key();
        $data = base64_decode($stored);
        if ($data === false || !str_contains($data, '::')) {
            return $stored; // fallback: pode ser texto plano de migração
        }
        [$iv, $encrypted] = explode('::', $data, 2);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Retorna chave de criptografia derivada do AUTH_KEY do WordPress.
     */
    private static function get_encryption_key() {
        return hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : 'vigilante-fallback-key'), true);
    }

    /**
     * Captura erros do wp_mail.
     */
    public static function on_mail_failed($wp_error) {
        if (is_wp_error($wp_error)) {
            self::$last_error = $wp_error->get_error_message();
        }
    }

    /**
     * Envia email com logging automático.
     *
     * @param string $to      Destinatário.
     * @param string $subject Assunto.
     * @param string $body    Corpo do email.
     * @param array  $headers Headers adicionais.
     * @return bool Se o envio foi aceito pelo servidor.
     */
    public static function send($to, $subject, $body, $headers = []) {
        self::$last_error = '';

        // Headers padrão
        $default_headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: Vigilante WP <' . self::get_from_email() . '>',
        ];
        $headers = array_merge($default_headers, $headers);

        $sent = wp_mail($to, $subject, $body, $headers);

        // Registrar tentativa
        Vigilante_Logger::log_email(
            $to,
            $subject,
            $sent,
            $sent ? '' : (self::$last_error ?: 'wp_mail retornou false sem erro específico')
        );

        return $sent;
    }

    /**
     * Retorna o email remetente apropriado.
     */
    private static function get_from_email() {
        $sitename = wp_parse_url(network_home_url(), PHP_URL_HOST);
        if ($sitename) {
            $sitename = strtolower($sitename);
            if (str_starts_with($sitename, 'www.')) {
                $sitename = substr($sitename, 4);
            }
        }
        return 'wordpress@' . ($sitename ?: 'localhost');
    }

    /**
     * Envia alerta crítico imediato.
     */
    public static function send_critical_alert($type, $message) {
        $settings = get_option('vigilante_settings', []);
        $email = $settings['email'] ?? get_option('admin_email');
        $site  = get_bloginfo('name');
        $ip    = Vigilante_Logger::get_ip();
        $time  = current_time('d/m/Y H:i:s');

        $subject = "[ALERTA] {$site} — {$type}";

        $body  = "══════════════════════════════════════\n";
        $body .= "  ALERTA DE SEGURANÇA\n";
        $body .= "══════════════════════════════════════\n\n";
        $body .= "Site:       {$site}\n";
        $body .= "Data/Hora:  {$time}\n";
        $body .= "IP origem:  {$ip}\n";
        $body .= "Tipo:       {$type}\n\n";
        $body .= "Detalhes:\n{$message}\n\n";
        $body .= "→ Acesse o painel do WordPress para verificar.\n";

        return self::send($email, $subject, $body);
    }

    /**
     * Envia relatório diário.
     */
    public static function send_daily_report() {
        $settings = get_option('vigilante_settings', []);
        $email = $settings['email'] ?? get_option('admin_email');
        $site  = get_bloginfo('name');
        $logs  = Vigilante_Logger::get_recent_logs(24);

        $admin_count = count(get_users(['role' => 'administrator']));

        // Contagem por tipo
        $counts = [];
        foreach ($logs as $log) {
            $counts[$log['type']] = ($counts[$log['type']] ?? 0) + 1;
        }

        $subject = "[Relatório Diário] {$site} — Segurança";

        $body  = "══════════════════════════════════════\n";
        $body .= "  RELATÓRIO DIÁRIO DE SEGURANÇA\n";
        $body .= "══════════════════════════════════════\n\n";
        $body .= "Site:       {$site}\n";
        $body .= "Período:    Últimas 24 horas\n";
        $body .= "Data:       " . current_time('d/m/Y H:i') . "\n";
        $body .= "Admins:     {$admin_count}\n\n";

        if (empty($logs)) {
            $body .= "Nenhuma atividade registrada nas últimas 24 horas.\n";
        } else {
            $type_labels = [
                'usuario_criado'     => 'Usuários criados',
                'perfil_alterado'    => 'Perfis alterados',
                'usuario_removido'   => 'Usuários removidos',
                'login'              => 'Logins realizados',
                'login_falhou'       => 'Tentativas de login falhas',
                'ataque_bruta_forca' => 'Alertas de força bruta',
                'plugin_ativado'     => 'Plugins ativados',
                'plugin_desativado'  => 'Plugins desativados',
                'tema_alterado'      => 'Temas alterados',
                'atualizacao'        => 'Atualizações',
                'arquivo_alterado'   => 'Alterações em arquivos',
            ];

            $body .= "── RESUMO ──────────────────────────\n";
            foreach ($counts as $type => $count) {
                $label = $type_labels[$type] ?? $type;
                $body .= "  {$label}: {$count}\n";
            }

            $body .= "\n── EVENTOS DETALHADOS ──────────────\n";
            foreach ($logs as $log) {
                $flag = $log['critical'] ? ' [CRÍTICO]' : '';
                $body .= "\n[{$log['time']}]{$flag} ({$log['ip']})\n";
                $body .= "  {$log['message']}\n";
            }
        }

        $body .= "\n── ADMINISTRADORES ATUAIS ──────────\n";
        $admins = get_users(['role' => 'administrator']);
        foreach ($admins as $admin) {
            $since = date('d/m/Y', strtotime($admin->user_registered));
            $body .= "  • {$admin->user_login} ({$admin->user_email}) — desde {$since}\n";
        }

        return self::send($email, $subject, $body);
    }

    /**
     * Envia email de teste.
     */
    public static function send_test() {
        $settings = get_option('vigilante_settings', []);
        $email = $settings['email'] ?? get_option('admin_email');
        $site  = get_bloginfo('name');
        $time  = current_time('d/m/Y H:i:s');

        $subject = "[TESTE] Vigilante de WordPress — {$site}";

        $body  = "TESTE DE ENVIO DE E-MAIL\n\n";
        $body .= "Site:          {$site}\n";
        $body .= "Data/Hora:     {$time}\n";
        $body .= "Destinatário:  {$email}\n";
        $body .= "Método:        " . self::detect_mail_method() . "\n\n";
        $body .= "Se você recebeu este e-mail, o sistema de alertas está funcionando.\n\n";
        $body .= "O Vigilante irá enviar:\n";
        $body .= "  • Alertas imediatos para eventos críticos\n";
        $body .= "  • Relatório diário com resumo de atividades\n";

        return self::send($email, $subject, $body);
    }

    /**
     * Verifica se o SMTP do Vigilante está configurado e habilitado.
     */
    public static function has_own_smtp() {
        $settings = get_option('vigilante_settings', []);
        return !empty($settings['smtp_enabled']) && !empty($settings['smtp_host']);
    }

    /**
     * Detecta o método de envio de email configurado.
     */
    public static function detect_mail_method() {
        global $phpmailer;

        // Verificar SMTP do próprio Vigilante
        if (self::has_own_smtp()) {
            $settings = get_option('vigilante_settings', []);
            return 'SMTP Vigilante (' . esc_html($settings['smtp_host']) . ':' . intval($settings['smtp_port'] ?? 587) . ')';
        }

        // Verificar se há plugin SMTP ativo
        if (class_exists('WPMailSMTP\Core')) {
            return 'WP Mail SMTP (plugin)';
        }
        if (class_exists('Easy_WP_SMTP')) {
            return 'Easy WP SMTP (plugin)';
        }
        if (class_exists('FluentMail\App\Module')) {
            return 'FluentSMTP (plugin)';
        }
        if (defined('JEsuspended_EMAIL')) {
            return 'Plugin SMTP detectado';
        }

        // Verificar constantes de SMTP definidas no wp-config.php
        if (defined('SMTP_HOST') || defined('WPMS_ON')) {
            return 'SMTP via constantes wp-config.php';
        }

        // Verificar se phpmailer está configurado para SMTP
        if (isset($phpmailer) && is_object($phpmailer) && property_exists($phpmailer, 'Mailer')) {
            return 'PHPMailer: ' . $phpmailer->Mailer;
        }

        // Verificar função mail() do PHP
        if (function_exists('mail')) {
            return 'PHP mail() nativa (pode não funcionar em todos os hosts)';
        }

        return 'Nenhum método detectado — emails provavelmente NÃO serão enviados';
    }

    /**
     * Retorna diagnóstico completo do sistema de email.
     */
    public static function get_diagnostics() {
        $diag = [];

        // Método de envio
        $diag['method'] = self::detect_mail_method();

        // PHP mail()
        $diag['php_mail'] = function_exists('mail');

        // Configuração do servidor
        $diag['smtp_host'] = ini_get('SMTP') ?: '(não definido)';
        $diag['smtp_port'] = ini_get('smtp_port') ?: '(não definido)';
        $diag['sendmail_path'] = ini_get('sendmail_path') ?: '(não definido)';

        // Email do admin
        $settings = get_option('vigilante_settings', []);
        $diag['email_destino'] = $settings['email'] ?? get_option('admin_email');

        // Último erro
        $email_logs = Vigilante_Logger::get_email_logs();
        $last_failed = null;
        foreach (array_reverse($email_logs) as $log) {
            if (!$log['success']) {
                $last_failed = $log;
                break;
            }
        }
        $diag['last_error'] = $last_failed;

        // Estatísticas
        $total = count($email_logs);
        $success = count(array_filter($email_logs, fn($l) => $l['success']));
        $diag['emails_enviados'] = $total;
        $diag['emails_sucesso']  = $success;
        $diag['emails_falha']    = $total - $success;

        // SMTP próprio do Vigilante
        $diag['own_smtp'] = self::has_own_smtp();

        // Plugins SMTP conhecidos
        $diag['smtp_plugins'] = [];
        if (self::has_own_smtp())                    $diag['smtp_plugins'][] = 'Vigilante SMTP';
        if (class_exists('WPMailSMTP\Core'))         $diag['smtp_plugins'][] = 'WP Mail SMTP';
        if (class_exists('Easy_WP_SMTP'))            $diag['smtp_plugins'][] = 'Easy WP SMTP';
        if (class_exists('FluentMail\App\Module'))   $diag['smtp_plugins'][] = 'FluentSMTP';
        if (class_exists('PostmanOptions'))           $diag['smtp_plugins'][] = 'Post SMTP';

        return $diag;
    }

    /**
     * Retorna o último erro registrado.
     */
    public static function get_last_error() {
        return self::$last_error;
    }
}
