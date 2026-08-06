<?php
/**
 * Vigilante_File_Scanner
 *
 * Monitoramento de integridade de arquivos, snapshots e detecção de alterações.
 */

if (!defined('ABSPATH')) exit;

class Vigilante_File_Scanner {

    const SNAPSHOT_OPTION = 'vigilante_file_snapshot';

    /**
     * Estado de repetição: caminho => ['ultimo' => timestamp, 'vezes' => n].
     * Serve pra não repetir o mesmo achado num alerta por dia (ver separa_repetidos()).
     */
    const REPETICAO_OPTION = 'vigilante_file_alert_state';

    /**
     * Janela de silêncio pra achado idêntico, em segundos.
     * Um arquivo que legitimamente some e volta (o caso clássico é plugin com
     * faxina agendada na própria pasta) alerta uma vez por dia, não a cada hora.
     */
    const JANELA_REPETICAO = DAY_IN_SECONDS;

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

        // uploads é vetor comum de ataque, monitorar com profundidade
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['basedir'])) {
            $dirs[$upload_dir['basedir'] . '/'] = 3;
        }

        return $dirs;
    }

    /**
     * Caminhos que a vigilância ignora, um por linha na configuração.
     *
     * O padrão é relativo à raiz do site (ex.: wp-content/plugins/foo/storage)
     * e aceita curinga `*`. Um diretório excluído leva junto o que está dentro.
     *
     * Existe porque plugin que mexe na própria pasta por conta própria gera
     * alerta verdadeiro e inútil todo dia, e alerta que ninguém lê mais é pior
     * que alerta nenhum. Excluir caminho é decisão consciente: a lista aparece
     * no painel e no relatório periódico, justamente pra não virar ponto cego
     * esquecido.
     */
    public static function get_exclusoes() {
        $settings = get_option('vigilante_settings', []);
        $bruto    = isset($settings['file_exclusions']) ? (string) $settings['file_exclusions'] : '';
        $linhas   = preg_split('/\r\n|\r|\n/', $bruto);
        $limpas   = [];

        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#')) continue;
            $linha = ltrim(str_replace('\\', '/', $linha), '/');
            if ($linha === '' || str_contains($linha, '..')) continue;  // nunca sair da raiz
            $limpas[] = rtrim($linha, '/');
        }

        return array_values(array_unique($limpas));
    }

    /**
     * Um caminho absoluto casa com alguma exclusão configurada?
     */
    private static function esta_excluido($path, array $exclusoes) {
        if (!$exclusoes) return false;

        $rel = ltrim(str_replace('\\', '/', str_replace(ABSPATH, '', $path)), '/');

        foreach ($exclusoes as $padrao) {
            if ($rel === $padrao) return true;
            if (str_starts_with($rel, $padrao . '/')) return true;          // diretório excluído
            if (str_contains($padrao, '*') && fnmatch($padrao, $rel)) return true;
        }

        return false;
    }

    /**
     * Separa os achados que ainda não foram avisados dos que já foram, dentro da
     * janela de repetição, e atualiza o estado. Sempre devolve os dois lados: o
     * repetido não vira e-mail, mas continua no log e no relatório periódico,
     * porque silenciar sem deixar rastro é como perder o achado.
     *
     * @return array{0: array, 1: array} [novos, repetidos]
     */
    public static function separa_repetidos(array $changes) {
        $estado = (array) get_option(self::REPETICAO_OPTION, []);
        $agora  = time();
        $novos  = [];
        $repet  = [];

        foreach ($changes as $c) {
            $chave = $c['path'];
            $visto = isset($estado[$chave]) ? (int) $estado[$chave]['ultimo'] : 0;

            if ($visto && ($agora - $visto) < self::JANELA_REPETICAO) {
                $estado[$chave]['vezes'] = (int) $estado[$chave]['vezes'] + 1;
                $c['vezes'] = $estado[$chave]['vezes'];
                $repet[] = $c;
                continue;
            }

            $estado[$chave] = ['ultimo' => $agora, 'vezes' => 1];
            $novos[] = $c;
        }

        // poda: caminho que parou de mudar sai do estado e volta a alertar do zero
        foreach ($estado as $chave => $info) {
            if (($agora - (int) $info['ultimo']) > (self::JANELA_REPETICAO * 3)) {
                unset($estado[$chave]);
            }
        }

        update_option(self::REPETICAO_OPTION, $estado, false);

        return [$novos, $repet];
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
    private static function scan_directory($dir, &$snapshot, $depth = 0, $max_depth = 2, $exclusoes = null) {
        if ($depth > $max_depth) return;
        if (!is_readable($dir)) return;

        if ($exclusoes === null) $exclusoes = self::get_exclusoes();

        $items = @scandir($dir);
        if (!$items) return;

        $in_uploads = self::is_uploads_dir($dir);
        $pattern = $in_uploads ? self::DANGEROUS_IN_UPLOADS : self::MONITORED_EXTENSIONS;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = rtrim($dir, '/') . '/' . $item;

            if (self::esta_excluido($path, $exclusoes)) continue;

            if (is_file($path) && preg_match($pattern, $item)) {
                $snapshot[$path] = [
                    'size'     => filesize($path),
                    'modified' => filemtime($path),
                    'hash'     => md5_file($path),
                ];
            }

            if (is_dir($path) && $depth < $max_depth) {
                self::scan_directory($path, $snapshot, $depth + 1, $max_depth, $exclusoes);
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
            'exclusoes'   => self::get_exclusoes(),
        ];
    }
}
