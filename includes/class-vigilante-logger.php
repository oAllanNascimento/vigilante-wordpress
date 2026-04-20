<?php
/**
 * Vigilante_Logger
 *
 * Sistema de logs do plugin — armazena eventos de segurança e tentativas de email.
 */

if (!defined('ABSPATH')) exit;

class Vigilante_Logger {

    const LOG_OPTION   = 'vigilante_security_log';
    const EMAIL_LOG    = 'vigilante_email_log';
    const MAX_ENTRIES  = 500;
    const MAX_EMAIL_ENTRIES = 100;

    /**
     * Adquire um lock via transient para operações atômicas.
     *
     * @param string $name Nome do lock.
     * @param int    $timeout Tempo máximo de espera em segundos.
     * @return bool Se o lock foi adquirido.
     */
    private static function acquire_lock($name, $timeout = 5) {
        $lock_key = 'vigilante_lock_' . $name;
        $start = time();

        while (time() - $start < $timeout) {
            if (false === get_transient($lock_key)) {
                set_transient($lock_key, getmypid(), 10);
                return true;
            }
            usleep(50000); // 50ms
        }

        // Timeout — forçar liberação (lock possivelmente órfão)
        delete_transient($lock_key);
        set_transient($lock_key, getmypid(), 10);
        return true;
    }

    /**
     * Libera um lock.
     */
    private static function release_lock($name) {
        delete_transient('vigilante_lock_' . $name);
    }

    /**
     * Registra um evento de segurança com locking para concorrência.
     *
     * @param string $type    Tipo do evento.
     * @param string $message Descrição do evento.
     * @param bool   $critical Se é um evento crítico.
     * @return array O registro criado.
     */
    public static function log_event($type, $message, $critical = false) {
        self::acquire_lock('security_log');

        try {
            $logs = get_option(self::LOG_OPTION, []);

            $entry = [
                'time'     => current_time('mysql'),
                'type'     => $type,
                'message'  => $message,
                'ip'       => self::get_ip(),
                'critical' => $critical,
            ];

            $logs[] = $entry;

            if (count($logs) > self::MAX_ENTRIES) {
                $logs = array_slice($logs, -self::MAX_ENTRIES);
            }

            update_option(self::LOG_OPTION, $logs, false);
        } finally {
            self::release_lock('security_log');
        }

        return $entry;
    }

    /**
     * Registra uma tentativa de envio de email com locking para concorrência.
     */
    public static function log_email($to, $subject, $success, $error = '') {
        self::acquire_lock('email_log');

        try {
            $logs = get_option(self::EMAIL_LOG, []);

            $logs[] = [
                'time'    => current_time('mysql'),
                'to'      => $to,
                'subject' => $subject,
                'success' => $success,
                'error'   => $error,
            ];

            if (count($logs) > self::MAX_EMAIL_ENTRIES) {
                $logs = array_slice($logs, -self::MAX_EMAIL_ENTRIES);
            }

            update_option(self::EMAIL_LOG, $logs, false);
        } finally {
            self::release_lock('email_log');
        }
    }

    /**
     * Retorna todos os logs de segurança.
     */
    public static function get_logs() {
        return get_option(self::LOG_OPTION, []);
    }

    /**
     * Retorna logs de email.
     */
    public static function get_email_logs() {
        return get_option(self::EMAIL_LOG, []);
    }

    /**
     * Retorna logs das últimas N horas.
     */
    public static function get_recent_logs($hours = 24) {
        $logs = self::get_logs();
        $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));

        return array_filter($logs, function ($log) use ($since) {
            return $log['time'] >= $since;
        });
    }

    /**
     * Limpa todos os logs de segurança.
     */
    public static function clear_logs() {
        update_option(self::LOG_OPTION, [], false);
    }

    /**
     * Limpa logs de email.
     */
    public static function clear_email_logs() {
        update_option(self::EMAIL_LOG, [], false);
    }

    /**
     * Obtém o IP do visitante.
     *
     * Usa apenas REMOTE_ADDR por padrão (seguro, não falsificável).
     * X-Forwarded-For é ignorado pois pode ser spoofado por qualquer cliente.
     */
    public static function get_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';

        // Validar que é um IP real
        if ($ip !== 'desconhecido' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            return 'inválido';
        }

        return sanitize_text_field($ip);
    }
}
