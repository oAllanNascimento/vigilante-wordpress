<?php
/**
 * Vigilante_File_Scanner
 *
 * Monitoramento de integridade de arquivos — snapshots e detecção de alterações.
 */

if (!defined('ABSPATH')) exit;

class Vigilante_File_Scanner {

    const SNAPSHOT_OPTION = 'vigilante_file_snapshot';

    /**
     * Extensões de arquivo potencialmente perigosas que devem ser monitoradas.
     */
    private const MONITORED_EXTENSIONS = '/\.(php|php[345678]?|phtml|phar|inc|js|htaccess|shtml)$/i';

    /**
     * Extensões que NUNCA deveriam existir em uploads (executáveis server-side).
     */
    private const DANGEROUS_IN_UPLOADS = '/\.(php|php[345678]?|phtml|phar|inc|shtml)$/i';

    /**
     * Diretórios monitorados com profundidade máxima de scan.
     */
    private static function get_monitored_dirs() {
        $dirs = [
            ABSPATH                        => 0,  // raiz: só nivel 0 (arquivos soltos)
            ABSPATH . 'wp-admin/'          => 2,
            ABSPATH . 'wp-includes/'       => 2,
            WP_CONTENT_DIR . '/plugins/'   => 2,
            WP_CONTENT_DIR . '/mu-plugins/'=> 2,
            WP_CONTENT_DIR . '/themes/'    => 2,
        ];

        // uploads é vetor comum de ataque — monitorar com profundidade
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['basedir'])) {
            $dirs[$upload_dir['basedir'] . '/'] = 3;
        }

        return $dirs;
    }

    /**
     * Cria um snapshot do estado atual dos arquivos.
     */
    public static function take_snapshot() {
        $snapshot = [];

        foreach (self::get_monitored_dirs() as $dir => $max_depth) {
            if (!is_dir($dir)) continue;
            self::scan_directory($dir, $snapshot, 0, $max_depth);
        }

        update_option(self::SNAPSHOT_OPTION, $snapshot, false);
        return $snapshot;
    }

    /**
     * Verifica alterações comparando com o snapshot anterior.
     *
     * @return array|null Lista de alterações, ou null se não havia snapshot anterior.
     */
    public static function check_changes() {
        $old_snapshot = get_option(self::SNAPSHOT_OPTION, []);

        if (empty($old_snapshot)) {
            self::take_snapshot();
            return null;
        }

        $new_snapshot = [];
        foreach (self::get_monitored_dirs() as $dir => $max_depth) {
            if (!is_dir($dir)) continue;
            self::scan_directory($dir, $new_snapshot, 0, $max_depth);
        }

        $changes = [];

        // Arquivos novos
        foreach ($new_snapshot as $path => $info) {
            if (!isset($old_snapshot[$path])) {
                $changes[] = [
                    'action' => 'NOVO',
                    'path'   => str_replace(ABSPATH, '', $path),
                ];
            }
        }

        // Arquivos modificados
        foreach ($new_snapshot as $path => $info) {
            if (isset($old_snapshot[$path]) && $old_snapshot[$path]['hash'] !== $info['hash']) {
                $changes[] = [
                    'action' => 'MODIFICADO',
                    'path'   => str_replace(ABSPATH, '', $path),
                ];
            }
        }

        // Arquivos removidos
        foreach ($old_snapshot as $path => $info) {
            if (!isset($new_snapshot[$path])) {
                $changes[] = [
                    'action' => 'REMOVIDO',
                    'path'   => str_replace(ABSPATH, '', $path),
                ];
            }
        }

        // Atualizar snapshot
        update_option(self::SNAPSHOT_OPTION, $new_snapshot, false);

        return $changes;
    }

    /**
     * Verifica se um diretório está dentro de uploads.
     */
    private static function is_uploads_dir($dir) {
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['basedir'])) return false;
        return str_starts_with(realpath($dir) ?: $dir, realpath($upload_dir['basedir']) ?: $upload_dir['basedir']);
    }

    /**
     * Escaneia um diretório recursivamente.
     *
     * Em uploads, só monitora extensões perigosas (executáveis server-side).
     * Nos demais diretórios, monitora todas as extensões configuradas.
     */
    private static function scan_directory($dir, &$snapshot, $depth = 0, $max_depth = 2) {
        if ($depth > $max_depth) return;
        if (!is_readable($dir)) return;

        $items = @scandir($dir);
        if (!$items) return;

        $in_uploads = self::is_uploads_dir($dir);
        $pattern = $in_uploads ? self::DANGEROUS_IN_UPLOADS : self::MONITORED_EXTENSIONS;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = rtrim($dir, '/') . '/' . $item;

            if (is_file($path) && preg_match($pattern, $item)) {
                $snapshot[$path] = [
                    'size'     => filesize($path),
                    'modified' => filemtime($path),
                    'hash'     => md5_file($path),
                ];
            }

            if (is_dir($path) && $depth < $max_depth) {
                self::scan_directory($path, $snapshot, $depth + 1, $max_depth);
            }
        }
    }

    /**
     * Retorna informações sobre o snapshot atual.
     */
    public static function get_snapshot_info() {
        $snapshot = get_option(self::SNAPSHOT_OPTION, []);
        return [
            'total_files' => count($snapshot),
            'exists'      => !empty($snapshot),
        ];
    }
}
