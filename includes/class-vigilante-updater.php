<?php
/**
 * Vigilante_Updater
 *
 * Verifica atualizações no repositório GitHub público e integra
 * com o sistema nativo de updates do WordPress.
 *
 * Para publicar uma atualização:
 * 1. Atualize a versão no header e constante VIGILANTE_VERSION
 * 2. Crie uma Release no GitHub com tag (ex: v0.2.0)
 * 3. O plugin detectará automaticamente em até 6 horas (ou ao clicar "Verificar novamente")
 */

if (!defined('ABSPATH')) exit;

class Vigilante_Updater {

    const GITHUB_OWNER = 'oAllanNascimento';
    const GITHUB_REPO  = 'vigilante-wordpress';
    const CACHE_KEY    = 'vigilante_github_update';
    const CACHE_TTL    = 6 * HOUR_IN_SECONDS;

    /**
     * Registra os hooks do WordPress para update.
     */
    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_directory_name'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 2);
    }

    /**
     * Consulta a API do GitHub para a última release.
     * Resultado cacheado por CACHE_TTL.
     */
    private static function get_remote_release() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_OWNER,
            self::GITHUB_REPO
        );

        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_KEY, null, 5 * MINUTE_IN_SECONDS);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['tag_name'])) {
            return null;
        }

        $release = [
            'version'     => ltrim($body['tag_name'], 'vV'),
            'tag'         => $body['tag_name'],
            'url'         => $body['html_url'] ?? '',
            'body'        => $body['body'] ?? '',
            'published'   => $body['published_at'] ?? '',
            'zipball_url' => $body['zipball_url'] ?? '',
        ];

        // Preferir asset .zip se existir (mais confiável que zipball)
        if (!empty($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (str_ends_with($asset['name'], '.zip')) {
                    $release['zip_url'] = $asset['browser_download_url'];
                    break;
                }
            }
        }

        set_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    /**
     * Retorna a URL do ZIP para download.
     */
    private static function get_download_url($release) {
        return $release['zip_url'] ?? $release['zipball_url'] ?? '';
    }

    /**
     * Hook: verifica se há update disponível.
     */
    public static function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = self::get_remote_release();
        if (empty($release['version'])) {
            return $transient;
        }

        $plugin_slug = plugin_basename(VIGILANTE_PLUGIN_FILE);

        if (version_compare(VIGILANTE_VERSION, $release['version'], '<')) {
            $transient->response[$plugin_slug] = (object) [
                'slug'        => dirname($plugin_slug),
                'plugin'      => $plugin_slug,
                'new_version' => $release['version'],
                'url'         => $release['url'],
                'package'     => self::get_download_url($release),
                'icons'       => [],
                'banners'     => [],
            ];
        } else {
            $transient->no_update[$plugin_slug] = (object) [
                'slug'        => dirname($plugin_slug),
                'plugin'      => $plugin_slug,
                'new_version' => VIGILANTE_VERSION,
                'url'         => '',
                'package'     => '',
            ];
        }

        return $transient;
    }

    /**
     * Hook: fornece informações do plugin para a tela de detalhes.
     */
    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $plugin_slug = dirname(plugin_basename(VIGILANTE_PLUGIN_FILE));
        if (!isset($args->slug) || $args->slug !== $plugin_slug) {
            return $result;
        }

        $release = self::get_remote_release();
        if (empty($release)) {
            return $result;
        }

        return (object) [
            'name'          => 'Vigilante de WordPress',
            'slug'          => $plugin_slug,
            'version'       => $release['version'],
            'author'        => '<a href="mailto:nascimento.allang@gmail.com">Allan Nascimento</a>',
            'homepage'      => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
            'download_link' => self::get_download_url($release),
            'requires'      => '5.6',
            'requires_php'  => '7.4',
            'tested'        => get_bloginfo('version'),
            'last_updated'  => $release['published'],
            'sections'      => [
                'description'  => 'Monitor de segurança para WordPress com SMTP integrado, alertas em tempo real e relatório diário.',
                'changelog'    => nl2br(esc_html($release['body'])),
            ],
        ];
    }

    /**
     * Hook: corrige o nome do diretório após extração do ZIP.
     *
     * O GitHub gera ZIPs com nome "owner-repo-hash/". O WordPress espera
     * que o diretório corresponda ao slug do plugin.
     */
    public static function fix_directory_name($source, $remote_source, $upgrader, $hook_extra) {
        if (!isset($hook_extra['plugin'])) {
            return $source;
        }

        $plugin_slug = plugin_basename(VIGILANTE_PLUGIN_FILE);
        if ($hook_extra['plugin'] !== $plugin_slug) {
            return $source;
        }

        $expected_dir = trailingslashit($remote_source) . dirname($plugin_slug);
        if ($source === $expected_dir) {
            return $source;
        }

        // Renomear para o nome esperado
        $new_source = trailingslashit($remote_source) . dirname($plugin_slug) . '/';
        if (@rename($source, $new_source)) {
            return $new_source;
        }

        return $source;
    }

    /**
     * Hook: limpa o cache após atualização.
     */
    public static function clear_cache($upgrader, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient(self::CACHE_KEY);
        }
    }

    /**
     * Força verificação de atualização (limpa cache).
     */
    public static function force_check() {
        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
    }
}
