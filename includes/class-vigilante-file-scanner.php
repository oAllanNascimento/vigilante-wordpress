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
     * Alcance da varredura que gerou a linha de base guardada.
     *
     * Quando o alcance muda, todo arquivo que a versão anterior não enxergava
     * apareceria como NOVO de uma vez (na Belavista foram mais de 19 mil). Isso
     * não é achado, é a régua tendo mudado: a linha de base se refaz calada e o
     * alerta volta a valer na rodada seguinte. Trocar esta constante é o jeito
     * de dizer "o que se vigia mudou".
     */
    const ESCOPO_OPTION = 'vigilante_snapshot_escopo';
    const ESCOPO_ATUAL  = 'executavel-em-qualquer-profundidade-v1';

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
     * Extensões acompanhadas em QUALQUER profundidade: o que o servidor executa,
     * mais o .htaccess, que decide o que é executado.
     *
     * Incidente de 05/08/2026 na Faculdade Belavista: o backdoor estava em
     * wp-content/plugins/customizacao-belavista/assets/js/settings-functions.php,
     * três pastas abaixo da raiz do plugin, e a varredura parava na segunda.
     * Ficou invisível por dois dias, com 18.806 arquivos PHP daquele site fora do
     * alcance do scanner. Limite de profundidade continua valendo pro resto (js e
     * afins, que são muitos e servem de contexto), nunca pro que executa.
     */
    private const SEMPRE_VIGIADO = '/\.(php|php[345678]?|phtml|phar|inc|shtml|htaccess)$/i';

    /**
     * Trava de segurança da recursão: nenhuma árvore legítima de WordPress chega
     * perto disso, e ela impede que estrutura patológica (ou link simbólico que
     * escapou da checagem) faça a varredura horária rodar sem fim.
     */
    private const PROFUNDIDADE_LIMITE = 16;

    /**
     * Diretórios monitorados com a profundidade até a qual se monitora TODA
     * extensão da lista. Abaixo dela a varredura continua, só que restrita ao
     * que é executável (ver SEMPRE_VIGIADO).
     *
     * A ordem importa: os diretórios específicos vêm primeiro e a raiz vem por
     * último, porque uma pasta já varrida não se varre de novo. Assim a raiz
     * pega o que sobrou (pasta solta de backup, staging, painel de terceiro) sem
     * refazer o trabalho de wp-admin, wp-includes e wp-content.
     */
    private static function get_monitored_dirs() {
        $dirs = [
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

        // o resto de wp-content (cache, languages, upgrade, pastas de plugin
        // fora do padrão) nunca foi varrido, e é onde implante gosta de morar
        $dirs[WP_CONTENT_DIR . '/'] = 1;

        // raiz por último: arquivos soltos com toda extensão, e daí pra baixo
        // só executável, no que ainda não foi visitado acima
        $dirs[ABSPATH] = 0;

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
        $snapshot = self::varre_tudo();

        update_option(self::SNAPSHOT_OPTION, $snapshot, false);
        update_option(self::ESCOPO_OPTION, self::ESCOPO_ATUAL, false);
        return $snapshot;
    }

    /**
     * Hash de uma entrada do snapshot, no formato novo (string) ou no antigo
     * (array com size/modified/hash). Linha de base velha não deveria chegar
     * aqui, porque a marca de alcance a refaz antes, mas comparar formato
     * diferente sem checar acusaria o site inteiro como modificado.
     */
    private static function hash_de($entrada) {
        return is_array($entrada) ? ($entrada['hash'] ?? '') : (string) $entrada;
    }

    /**
     * Uma passada completa por todos os diretórios monitorados.
     *
     * A lista de exclusões e o conjunto de diretórios já visitados são
     * calculados uma vez por passada e atravessam a recursão inteira: sem isso,
     * a raiz varreria de novo tudo que os diretórios específicos já cobriram.
     */
    private static function varre_tudo() {
        $snapshot  = [];
        $exclusoes = self::get_exclusoes();
        $visitados = [];

        foreach (self::get_monitored_dirs() as $dir => $max_depth) {
            if (!is_dir($dir)) continue;
            self::scan_directory($dir, $snapshot, 0, $max_depth, $exclusoes, $visitados);
        }

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

        // linha de base feita por uma varredura de alcance menor não se compara
        // com esta: refaz e volta a alertar na próxima rodada
        if (get_option(self::ESCOPO_OPTION, '') !== self::ESCOPO_ATUAL) {
            self::take_snapshot();
            return null;
        }

        $new_snapshot = self::varre_tudo();

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
            if (isset($old_snapshot[$path]) && self::hash_de($old_snapshot[$path]) !== self::hash_de($info)) {
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
     *
     * A base de uploads é resolvida uma vez por processo: com a varredura
     * descendo a árvore inteira, um realpath() por diretório vira milhares de
     * chamadas ao disco sem mudar resposta nenhuma.
     */
    private static function is_uploads_dir($dir) {
        static $bases = null;

        if ($bases === null) {
            $bases = [];
            $upload_dir = wp_upload_dir();
            if (!empty($upload_dir['basedir'])) {
                $bases[] = rtrim($upload_dir['basedir'], '/');
                $real = realpath($upload_dir['basedir']);
                if ($real && !in_array($real, $bases, true)) $bases[] = $real;
            }
        }

        if (!$bases) return false;

        $dir = rtrim($dir, '/');
        foreach ($bases as $base) {
            if ($dir === $base || str_starts_with($dir, $base . '/')) return true;
        }

        return false;
    }

    /**
     * O caminho canônico está dentro da árvore do site (ou de uploads, que em
     * algumas instalações mora fora dela)?
     */
    private static function dentro_do_site($canonico) {
        static $raizes = null;

        if ($raizes === null) {
            $raizes = [rtrim(realpath(ABSPATH) ?: ABSPATH, '/')];
            $upload_dir = wp_upload_dir();
            if (!empty($upload_dir['basedir'])) {
                $base = rtrim(realpath($upload_dir['basedir']) ?: $upload_dir['basedir'], '/');
                if (!in_array($base, $raizes, true)) $raizes[] = $base;
            }
        }

        $canonico = rtrim($canonico, '/');
        foreach ($raizes as $raiz) {
            if ($raiz !== '' && ($canonico === $raiz || str_starts_with($canonico, $raiz . '/'))) return true;
        }

        return false;
    }

    /**
     * Escaneia um diretório recursivamente.
     *
     * Em uploads, só monitora extensões perigosas (executáveis server-side).
     * Nos demais diretórios, monitora todas as extensões configuradas até
     * $max_depth, e daí pra baixo continua descendo só atrás de executável
     * (SEMPRE_VIGIADO). Foi essa parada em $max_depth que escondeu o backdoor da
     * Belavista em 05/08/2026, três pastas dentro de um plugin.
     *
     * @param array $visitados Diretórios já varridos nesta passada, por caminho
     *                         canônico. Evita trabalho repetido entre as raízes e
     *                         fecha a porta pra laço de link simbólico.
     */
    private static function scan_directory($dir, &$snapshot, $depth = 0, $max_depth = 2, $exclusoes = null, &$visitados = null) {
        if ($depth > self::PROFUNDIDADE_LIMITE) return;
        if (!is_readable($dir)) return;

        if ($exclusoes === null) $exclusoes = self::get_exclusoes();
        if ($visitados === null) $visitados = [];

        $canonico = realpath($dir) ?: rtrim($dir, '/');
        if (isset($visitados[$canonico])) return;

        // link simbólico é seguido, mas nunca pra fora do site: senão um link
        // apontando pra / faria a varredura horária caminhar o servidor inteiro
        if ($depth > 0 && !self::dentro_do_site($canonico)) return;

        $visitados[$canonico] = true;

        $items = @scandir($dir);
        if (!$items) return;

        $in_uploads = self::is_uploads_dir($dir);
        $pattern = $in_uploads ? self::DANGEROUS_IN_UPLOADS : self::MONITORED_EXTENSIONS;

        // passado o limite de profundidade, só o que executa continua vigiado
        if ($depth > $max_depth) $pattern = self::SEMPRE_VIGIADO;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = rtrim($dir, '/') . '/' . $item;

            if (self::esta_excluido($path, $exclusoes)) continue;

            if (is_file($path) && preg_match($pattern, $item)) {
                // só o hash: tamanho e data eram guardados e nunca lidos, e com a
                // varredura enxergando 7x mais arquivo cada campo extra é linha de
                // wp_options e memória de cron. A comparação sempre foi por hash.
                $snapshot[$path] = md5_file($path);
            }

            if (is_dir($path)) {
                self::scan_directory($path, $snapshot, $depth + 1, $max_depth, $exclusoes, $visitados);
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
