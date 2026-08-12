<?php
/**
 * Vigilante_Updater
 *
 * Integra o plugin ao sistema nativo de atualização do WordPress lendo um
 * manifesto estático publicado no site do autor.
 *
 * O canal é um JSON público servido por HTTPS, sem chave nem token. O plugin é
 * distribuído a terceiros, então qualquer credencial embutida aqui viajaria
 * dentro do zip para máquinas e sites que não controlamos.
 *
 * Para publicar uma versão nova, ver scripts/publicar.mjs no repositório.
 */

if (!defined('ABSPATH')) exit;

class Vigilante_Updater {

    /** Manifesto público com a versão corrente e a URL do zip. */
    const MANIFESTO_URL = 'https://allannascimento.com.br/vigilante/atualizacao.json';

    /** Página do produto, usada como homepage na tela de detalhes. */
    const PAGINA_URL = 'https://allannascimento.com.br/vigilante/';

    const CACHE_KEY       = 'vigilante_atualizacao';
    const CACHE_TTL       = 6 * HOUR_IN_SECONDS;
    const CACHE_TTL_FALHA = 15 * MINUTE_IN_SECONDS;

    /**
     * Registra os hooks do WordPress para update.
     */
    public static function init() {
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_update']);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_directory_name'], 10, 4);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 2);

        // Link "Verificar atualizações" na lista de plugins
        $plugin_slug = plugin_basename(VIGILANTE_PLUGIN_FILE);
        add_filter('plugin_action_links_' . $plugin_slug, [__CLASS__, 'add_check_update_link']);
        add_action('admin_init', [__CLASS__, 'handle_force_check']);
        add_action('admin_notices', [__CLASS__, 'show_check_notice']);
    }

    /**
     * Lê o manifesto do canal de atualização. Resultado cacheado por CACHE_TTL.
     *
     * Devolve null em qualquer problema (rede, HTTP, JSON inválido, campo
     * faltando). O painel de quem usa o plugin nunca pode quebrar nem exibir
     * erro por causa do canal: sem manifesto válido, o site simplesmente segue
     * na versão instalada.
     */
    private static function get_manifesto() {
        $cache = get_transient(self::CACHE_KEY);
        if (is_array($cache)) {
            // A falha também é cacheada, como array sem 'version'. Sem isso, um
            // canal fora do ar seria consultado a cada carregamento do painel.
            return empty($cache['version']) ? null : $cache;
        }

        $resposta = wp_remote_get(self::MANIFESTO_URL, [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($resposta) || wp_remote_retrieve_response_code($resposta) !== 200) {
            return self::cacheia_falha();
        }

        $dados = json_decode(wp_remote_retrieve_body($resposta), true);
        if (!is_array($dados)) {
            return self::cacheia_falha();
        }

        $manifesto = self::normaliza($dados);
        if ($manifesto === null) {
            return self::cacheia_falha();
        }

        set_transient(self::CACHE_KEY, $manifesto, self::CACHE_TTL);
        return $manifesto;
    }

    /**
     * Marca a falha no cache por uma janela curta e devolve null.
     */
    private static function cacheia_falha() {
        set_transient(self::CACHE_KEY, ['falhou' => 1], self::CACHE_TTL_FALHA);
        return null;
    }

    /**
     * Valida e normaliza o manifesto recebido.
     *
     * @return array|null Manifesto utilizável, ou null se faltar o essencial.
     */
    private static function normaliza($dados) {
        $version  = self::texto($dados['version'] ?? '');
        $download = self::url($dados['download_url'] ?? '');

        // Versão e zip são os dois campos sem os quais não existe atualização
        // possível. Manifesto incompleto vale como "não há nada novo".
        if (!preg_match('/^\d+(\.\d+){0,3}([-+][0-9A-Za-z.]+)?$/', $version)) {
            return null;
        }
        if (!self::download_valido($download)) {
            return null;
        }

        $secoes = [];
        if (!empty($dados['sections']) && is_array($dados['sections'])) {
            foreach ($dados['sections'] as $chave => $valor) {
                if (is_string($chave) && is_string($valor)) {
                    $secoes[sanitize_key($chave)] = $valor;
                }
            }
        }

        return [
            'version'      => $version,
            'download_url' => $download,
            'requires'     => self::texto($dados['requires'] ?? ''),
            'requires_php' => self::texto($dados['requires_php'] ?? ''),
            'tested'       => self::texto($dados['tested'] ?? ''),
            'last_updated' => self::texto($dados['last_updated'] ?? ''),
            'homepage'     => self::url($dados['homepage'] ?? '') ?: self::PAGINA_URL,
            'sections'     => $secoes,
        ];
    }

    /**
     * O zip só é aceito por HTTPS: o WordPress instala o que baixar daqui sem
     * pedir confirmação, então um endereço em texto claro é código executável
     * entregue por um canal que qualquer intermediário pode trocar.
     */
    private static function download_valido($url) {
        if ($url === '') {
            return false;
        }
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }
        return (bool) wp_http_validate_url($url);
    }

    private static function texto($valor) {
        return is_string($valor) ? sanitize_text_field($valor) : '';
    }

    private static function url($valor) {
        return is_string($valor) ? esc_url_raw(trim($valor)) : '';
    }

    /**
     * Hook: verifica se há update disponível.
     */
    public static function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $manifesto = self::get_manifesto();
        if (empty($manifesto['version'])) {
            return $transient;
        }

        $plugin_slug = plugin_basename(VIGILANTE_PLUGIN_FILE);

        if (version_compare(VIGILANTE_VERSION, $manifesto['version'], '<')) {
            $transient->response[$plugin_slug] = (object) [
                'id'           => $plugin_slug,
                'slug'         => dirname($plugin_slug),
                'plugin'       => $plugin_slug,
                'new_version'  => $manifesto['version'],
                'url'          => $manifesto['homepage'],
                'package'      => $manifesto['download_url'],
                'requires'     => $manifesto['requires'],
                'requires_php' => $manifesto['requires_php'],
                'tested'       => $manifesto['tested'],
                'icons'        => [],
                'banners'      => [],
            ];
        } else {
            $transient->no_update[$plugin_slug] = (object) [
                'id'          => $plugin_slug,
                'slug'        => dirname($plugin_slug),
                'plugin'      => $plugin_slug,
                'new_version' => VIGILANTE_VERSION,
                'url'         => $manifesto['homepage'],
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

        $manifesto = self::get_manifesto();
        if (empty($manifesto['version'])) {
            return $result;
        }

        // As seções chegam como HTML pronto do manifesto e vão para dentro do
        // painel: passam por wp_kses_post para que o canal não consiga injetar
        // script na tela de quem administra o site.
        $secoes = [];
        foreach ($manifesto['sections'] as $chave => $valor) {
            $secoes[$chave] = wp_kses_post($valor);
        }
        if (empty($secoes)) {
            $secoes['description'] = 'Monitor de segurança para WordPress com SMTP integrado, alertas em tempo real e relatório periódico.';
        }

        return (object) [
            'name'          => 'Vigilante de WordPress',
            'slug'          => $plugin_slug,
            'version'       => $manifesto['version'],
            'author'        => '<a href="mailto:nascimento.allang@gmail.com">Allan Nascimento</a>',
            'homepage'      => $manifesto['homepage'],
            'download_link' => $manifesto['download_url'],
            'requires'      => $manifesto['requires'] ?: '5.6',
            'requires_php'  => $manifesto['requires_php'] ?: '8.0',
            'tested'        => $manifesto['tested'] ?: get_bloginfo('version'),
            'last_updated'  => $manifesto['last_updated'],
            'sections'      => $secoes,
        ];
    }

    /**
     * Hook: corrige o nome do diretório após extração do ZIP.
     *
     * O WordPress instala o plugin na pasta com o nome que veio dentro do zip,
     * e o plugin só continua sendo o mesmo (opções, agendamentos, ativação) se
     * essa pasta for exatamente o slug atual. Vale como rede de proteção para
     * zip gerado por outra ferramenta, que empacota com nome próprio.
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
        if (untrailingslashit($source) === $expected_dir) {
            return $source;
        }

        // Renomear para o nome esperado
        $new_source = trailingslashit($expected_dir);
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
     * Exibe notice após verificação forçada.
     */
    public static function show_check_notice() {
        if (empty($_GET['vigilante_update_checked'])) {
            return;
        }

        $plugin_slug = plugin_basename(VIGILANTE_PLUGIN_FILE);
        $update_plugins = get_site_transient('update_plugins');
        $has_update = isset($update_plugins->response[$plugin_slug]);

        if ($has_update) {
            $new_version = $update_plugins->response[$plugin_slug]->new_version;
            echo '<div class="notice notice-warning"><p><strong>Vigilante WP:</strong> Nova versão disponível: <strong>' . esc_html($new_version) . '</strong> (atual: ' . esc_html(VIGILANTE_VERSION) . ')</p></div>';
        } else {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Vigilante WP:</strong> Você está usando a versão mais recente (' . esc_html(VIGILANTE_VERSION) . ').</p></div>';
        }
    }

    /**
     * Adiciona link "Verificar atualizações" na linha do plugin.
     */
    public static function add_check_update_link($links) {
        $url = wp_nonce_url(
            admin_url('plugins.php?vigilante_force_update_check=1'),
            'vigilante_force_update_check'
        );
        $links[] = '<a href="' . esc_url($url) . '">Verificar atualizações</a>';
        return $links;
    }

    /**
     * Processa o clique no link "Verificar atualizações".
     */
    public static function handle_force_check() {
        if (empty($_GET['vigilante_force_update_check'])) {
            return;
        }
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!current_user_can('update_plugins') || !wp_verify_nonce($nonce, 'vigilante_force_update_check')) {
            return;
        }

        self::force_check();

        // Dispara verificação imediata do WordPress
        wp_update_plugins();

        // Redireciona de volta sem os parâmetros na URL
        wp_safe_redirect(admin_url('plugins.php?vigilante_update_checked=1'));
        exit;
    }

    /**
     * Força verificação de atualização (limpa cache).
     */
    public static function force_check() {
        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
    }
}
