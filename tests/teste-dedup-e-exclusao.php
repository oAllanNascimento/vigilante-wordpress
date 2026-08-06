<?php
/**
 * Teste do dedup e da exclusao por caminho do Vigilante 1.2.0, sem WordPress.
 * Stubs minimos das funcoes do WP que o scanner usa, e um caso RUIM em cada teste
 * (o proprio arquivo que motivou a mudanca, e um caminho que NAO pode ser excluido).
 */

define('ABSPATH', '/var/www/site/');
define('DAY_IN_SECONDS', 86400);
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');

$GLOBALS['opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function wp_upload_dir() { return array('basedir' => ABSPATH . 'wp-content/uploads'); }

require_once __DIR__ . '/class-vigilante-file-scanner.php';

$falhas = 0;
function checa($nome, $ok) {
    global $falhas;
    if (!$ok) { $falhas++; }
    echo ($ok ? '  ok   ' : '  FALHA') . ' ' . $nome . "\n";
}

// Se o conserto nao estiver na classe, o teste tem que REPROVAR, e nao morrer de
// fatal: rodar este arquivo contra a versao antiga e a prova de que ele verifica.
$tem_exclusao = method_exists('Vigilante_File_Scanner', 'get_exclusoes')
    && (new ReflectionClass('Vigilante_File_Scanner'))->hasMethod('esta_excluido');
$tem_dedup = method_exists('Vigilante_File_Scanner', 'separa_repetidos');

$excluido = null;
if ($tem_exclusao) {
    $excluido = new ReflectionMethod('Vigilante_File_Scanner', 'esta_excluido');
    $excluido->setAccessible(true);
}
function excl($path, $lista) {
    global $excluido;
    return $excluido ? $excluido->invoke(null, $path, $lista) : false;
}

echo "1) exclusao por caminho\n";
checa('a classe TEM exclusao por caminho', $tem_exclusao);
$GLOBALS['opts']['vigilante_settings'] = array('file_exclusions' =>
    "wp-content/plugins/all-in-one-wp-migration/storage\n" .
    "# comentario ignorado\n" .
    "  /wp-content/cache/*.php  \n" .
    "../../etc/passwd\n"
);
$lista = $tem_exclusao ? Vigilante_File_Scanner::get_exclusoes() : array();
checa('comentario e vazio saem da lista', count($lista) === 2);
checa('caminho com .. e recusado', !in_array('../../etc/passwd', $lista, true));
checa('barra inicial e espaco sao normalizados', in_array('wp-content/cache/*.php', $lista, true));

checa('o arquivo do caso real casa',
    excl(ABSPATH . 'wp-content/plugins/all-in-one-wp-migration/storage/.htaccess', $lista));
checa('diretorio excluido leva o que esta dentro',
    excl(ABSPATH . 'wp-content/plugins/all-in-one-wp-migration/storage/fundo/x.php', $lista));
checa('curinga casa',
    excl(ABSPATH . 'wp-content/cache/malandro.php', $lista));

// CASO RUIM: nada perto pode ser excluido junto
checa('irmao NAO e excluido',
    !excl(ABSPATH . 'wp-content/plugins/all-in-one-wp-migration/lib/x.php', $lista));
checa('prefixo parecido NAO e excluido',
    !excl(ABSPATH . 'wp-content/plugins/all-in-one-wp-migration/storage-falso/x.php', $lista));
checa('mu-plugins NAO e excluido',
    !excl(ABSPATH . 'wp-content/mu-plugins/it.php', $lista));
checa('lista vazia nao exclui nada', !excl(ABSPATH . 'wp-content/mu-plugins/it.php', array()));

echo "2) dedup de achado repetido\n";
checa('a classe TEM dedup de repetido', $tem_dedup);
if (!$tem_dedup) { echo "\n$falhas FALHA(S)\n"; exit(1); }
$GLOBALS['opts']['vigilante_file_alert_state'] = array();
$achado = array(array('action' => 'NOVO', 'path' => 'wp-content/plugins/aiowpm/storage/.htaccess'));

list($novos, $rep) = Vigilante_File_Scanner::separa_repetidos($achado);
checa('1a vez alerta', count($novos) === 1 && count($rep) === 0);

list($novos, $rep) = Vigilante_File_Scanner::separa_repetidos($achado);
checa('2a vez na mesma janela nao alerta', count($novos) === 0 && count($rep) === 1);
checa('a 2a vez sai contada', ($rep[0]['vezes'] ?? 0) === 2);

// o mesmo caminho voltando com acao diferente (some e volta) tambem e repeticao
list($novos, $rep) = Vigilante_File_Scanner::separa_repetidos(
    array(array('action' => 'REMOVIDO', 'path' => 'wp-content/plugins/aiowpm/storage/.htaccess'))
);
checa('mesmo caminho com outra acao continua repetido', count($novos) === 0 && count($rep) === 1);

// CASO RUIM: achado DIFERENTE nao pode ser silenciado junto
list($novos, $rep) = Vigilante_File_Scanner::separa_repetidos(
    array(array('action' => 'NOVO', 'path' => 'wp-content/mu-plugins/it.php'))
);
checa('arquivo novo em mu-plugins alerta na hora', count($novos) === 1 && count($rep) === 0);

// passada a janela, o mesmo caminho volta a alertar
$GLOBALS['opts']['vigilante_file_alert_state']['wp-content/plugins/aiowpm/storage/.htaccess']['ultimo'] = time() - 90000;
list($novos, $rep) = Vigilante_File_Scanner::separa_repetidos($achado);
checa('passadas 24h o mesmo achado volta a alertar', count($novos) === 1);

echo ($falhas ? "\n$falhas FALHA(S)\n" : "\nTodos passaram\n");
exit($falhas ? 1 : 0);
