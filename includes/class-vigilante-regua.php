<?php
/**
 * Vigilante_Regua
 *
 * A régua do alerta (1.6.0): alerta só para mudança de TERCEIRO ou sinal de
 * MALWARE. Update do WordPress, de plugin, de tema e de tradução, a faxina que
 * um plugin faz na própria pasta e a ativação feita pelo servidor (WP-CLI) ou
 * pelo próprio update ficam no log, sem e-mail e sem caixa.
 *
 * Funções puras de decisão: recebem o que precisam e não leem opção nenhuma,
 * para serem testadas sem WordPress (tests/teste-regua-terceiro-ou-malware.php).
 */

if (!defined('ABSPATH')) exit;

class Vigilante_Regua {

    /** Quanto tempo depois de um update o arquivo mexido ainda é "do update". */
    const JANELA_UPDATE = 3 * 3600;   // a varredura de arquivos roda de hora em hora

    /** Pasta temporária que o All-in-One WP Migration esvazia e reprotege sozinho. */
    const PASTAS_FAXINA = ['wp-content/plugins/all-in-one-wp-migration/storage/'];
    const NOMES_FAXINA  = ['.htaccess', 'web.config', 'index.php', 'index.html'];

    /**
     * A alteração de arquivo tem explicação medida (update recente ou faxina)?
     *
     * @param array $change  ['action' => NOVO|MODIFICADO|REMOVIDO, 'path' => relativo a ABSPATH]
     * @param array $updates [['tipo' => core|plugin|theme|translation, 'itens' => [...], 'quando' => ts], ...]
     * @param int   $agora
     */
    public static function mudanca_explicada(array $change, array $updates, $agora) {
        $path = ltrim((string) ($change['path'] ?? ''), '/');

        foreach ($updates as $u) {
            if (($agora - (int) ($u['quando'] ?? 0)) > self::JANELA_UPDATE) {
                continue;
            }
            foreach (self::pastas_do_update($u) as $pasta) {
                if ($pasta === '' ? strpos($path, '/') === false : strpos($path, $pasta) === 0) {
                    return true;
                }
            }
        }

        return self::faxina_inofensiva($path);
    }

    /** Pastas que um update reescreve. '' = arquivos soltos na raiz do site (core). */
    private static function pastas_do_update(array $u) {
        $itens = array_filter((array) ($u['itens'] ?? []));
        switch ($u['tipo'] ?? '') {
            case 'core':
                return ['wp-admin/', 'wp-includes/', ''];
            case 'translation':
                return ['wp-content/languages/'];
            case 'plugin':
                // O upgrader informa 'worker/init.php'; plugin de arquivo único
                // ('hello.php') não tem pasta própria e não explica nada.
                $pastas = [];
                foreach ($itens as $i) {
                    $slug = strpos($i, '/') !== false ? dirname($i) : '';
                    if ($slug !== '' && $slug !== '.') $pastas[] = 'wp-content/plugins/' . $slug . '/';
                }
                return $pastas;
            case 'theme':
                return array_map(fn($t) => 'wp-content/themes/' . $t . '/', $itens);
        }
        return [];
    }

    /** Arquivo de proteção de pasta que o plugin regrava sozinho, medido no disco. */
    private static function faxina_inofensiva($path) {
        $pasta = null;
        foreach (self::PASTAS_FAXINA as $p) {
            if (strpos($path, $p) === 0) $pasta = $p;
        }
        if ($pasta === null) return false;

        $nome = substr($path, strlen($pasta));
        if (!in_array($nome, self::NOMES_FAXINA, true)) return false;

        $abs = ABSPATH . $path;
        if (!file_exists($abs)) return true;   // apagou na faxina; o plugin recria

        $txt = (string) @file_get_contents($abs, false, null, 0, 4096);
        $low = strtolower($txt);
        if ($nome === 'index.php') {
            return (bool) preg_match('~^<\?php\s*(//[^\n]*\s*|/\*.*?\*/\s*)*$~s', trim($txt));
        }
        if ($nome === 'index.html') {
            return strpos($low, '<script') === false && strpos($low, '<?php') === false;
        }
        $nega   = strpos($low, 'deny') !== false || strpos($low, 'denied') !== false;
        $ligaphp = (bool) preg_match('~(addhandler|sethandler|x-httpd-php|application/x-php|php_value|php_flag|auto_prepend|auto_append|addtype\s+\S*php)~', $low);
        return $nega && !$ligaphp;
    }

    /** O relatório periódico tem o que ler? Só evento crítico conta. */
    public static function relatorio_relevante(array $logs) {
        foreach ($logs as $l) {
            if (!empty($l['critical'])) return true;
        }
        return false;
    }

    /**
     * Ativação de plugin vira alerta?
     *
     * @param bool $via_cli       ativada pelo WP-CLI (shell do servidor: quem tem acesso a ele é nosso)
     * @param bool $durante_update ativada pelo próprio processo de update
     */
    public static function ativacao_alerta($via_cli, $durante_update) {
        return !$via_cli && !$durante_update;
    }
}
