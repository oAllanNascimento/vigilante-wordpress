<?php
/**
 * Teste da profundidade da varredura do Vigilante 1.4.0, sem WordPress.
 *
 * Monta uma arvore de site de mentira num diretorio temporario e exige que todo
 * arquivo EXECUTAVEL seja visto, em qualquer profundidade. O caso que da nome ao
 * teste e o backdoor real da Faculdade Belavista (05/08/2026), que morava em
 * wp-content/plugins/<plugin>/assets/js/settings-functions.php e ficou invisivel
 * por dois dias porque a varredura parava dois niveis acima.
 *
 * Rodar contra a versao ANTIGA (que e a prova de que o teste verifica algo):
 *   git show <commit-antigo>:includes/class-vigilante-file-scanner.php > tests/class-vigilante-file-scanner.php
 *   php tests/teste-profundidade-executavel.php     # tem que REPROVAR
 *   rm tests/class-vigilante-file-scanner.php
 *   php tests/teste-profundidade-executavel.php     # tem que passar
 */

$raiz = rtrim(sys_get_temp_dir(), '/\\') . '/vigilante-teste-' . getmypid() . '/';

define('ABSPATH', $raiz);
define('DAY_IN_SECONDS', 86400);
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');

$GLOBALS['opts'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['opts'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['opts'][$k]); return true; }
function wp_upload_dir() { return array('basedir' => ABSPATH . 'wp-content/uploads'); }

$classe = file_exists(__DIR__ . '/class-vigilante-file-scanner.php')
    ? __DIR__ . '/class-vigilante-file-scanner.php'
    : __DIR__ . '/../includes/class-vigilante-file-scanner.php';
require_once $classe;

$falhas = 0;
function checa($nome, $ok) {
    global $falhas;
    if (!$ok) { $falhas++; }
    echo ($ok ? '  ok   ' : '  FALHA') . ' ' . $nome . "\n";
}

function escreve($rel, $conteudo = "<?php // arquivo de teste\n") {
    $path = ABSPATH . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $conteudo);
}

function apaga_arvore($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $i) {
        if ($i === '.' || $i === '..') continue;
        $p = $dir . '/' . $i;
        is_dir($p) ? apaga_arvore($p) : @unlink($p);
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------- arvore falsa
escreve('index.php');                                                     // raiz
escreve('wp-admin/admin.php');
escreve('wp-includes/functions.php');
escreve('wp-content/plugins/customizacao-falsa/plugin.php');              // raso, o antigo ja via
escreve('wp-content/plugins/customizacao-falsa/inc/carrega.php');
escreve('wp-content/plugins/customizacao-falsa/assets/js/settings-functions.php'); // O CASO REAL
escreve('wp-content/plugins/customizacao-falsa/assets/js/app.js');        // js fundo: fica de fora por custo
escreve('wp-content/plugins/fundo/a/b/c/d/e/f/g/oculto.phtml');           // bem mais fundo
escreve('wp-content/themes/tema/parts/mod/bloco.php');
escreve('wp-content/uploads/2026/07/pasta/subpasta/shell.php');           // uploads fundo
escreve('wp-content/cache/gerado/backdoor.php');                          // wp-content fora de plugins/themes
escreve('backup-antigo/velho/porta.php');                                 // pasta solta na raiz
escreve('wp-content/plugins/all-in-one-wp-migration/storage/fundo/x.php'); // tem que continuar excluido
escreve('wp-content/uploads/2026/07/foto.jpg', 'jpg');                    // midia nunca entra

$GLOBALS['opts']['vigilante_settings'] = array(
    'file_exclusions' => "wp-content/plugins/all-in-one-wp-migration/storage\n"
);

$snap = Vigilante_File_Scanner::take_snapshot();
$vistos = array();
foreach (array_keys($snap) as $p) $vistos[] = ltrim(substr($p, strlen(ABSPATH)), '/');

function viu($rel) {
    global $vistos;
    return in_array($rel, $vistos, true);
}

echo "1) executavel em qualquer profundidade\n";
checa('o backdoor real (plugin/assets/js/*.php) e visto',
    viu('wp-content/plugins/customizacao-falsa/assets/js/settings-functions.php'));
checa('phtml oito pastas abaixo e visto',
    viu('wp-content/plugins/fundo/a/b/c/d/e/f/g/oculto.phtml'));
checa('php fundo no tema e visto',
    viu('wp-content/themes/tema/parts/mod/bloco.php'));
checa('php fundo em uploads e visto',
    viu('wp-content/uploads/2026/07/pasta/subpasta/shell.php'));
checa('php em wp-content/cache e visto',
    viu('wp-content/cache/gerado/backdoor.php'));
checa('php em pasta solta na raiz e visto',
    viu('backup-antigo/velho/porta.php'));

echo "2) o que ja funcionava continua funcionando\n";
checa('arquivo raso do plugin continua visto', viu('wp-content/plugins/customizacao-falsa/plugin.php'));
checa('index.php da raiz continua visto', viu('index.php'));
checa('wp-admin continua visto', viu('wp-admin/admin.php'));
checa('wp-includes continua visto', viu('wp-includes/functions.php'));

echo "3) casos ruins: o que NAO pode entrar\n";
checa('caminho excluido continua fora, mesmo fundo',
    !viu('wp-content/plugins/all-in-one-wp-migration/storage/fundo/x.php'));
checa('midia nao entra', !viu('wp-content/uploads/2026/07/foto.jpg'));
checa('js fundo fica fora (limite de profundidade segue valendo pro nao executavel)',
    !viu('wp-content/plugins/customizacao-falsa/assets/js/app.js'));
checa('nada fora da raiz do site entra',
    count(array_filter($vistos, fn($r) => str_starts_with($r, '..'))) === 0);

echo "4) linha de base de alcance antigo nao vira enxurrada de NOVO\n";
$tem_escopo = defined('Vigilante_File_Scanner::ESCOPO_ATUAL');
checa('a classe marca o alcance da linha de base', $tem_escopo);
if ($tem_escopo) {
    // simula o que existe num site que acabou de atualizar: base antiga, rasa
    $GLOBALS['opts'][Vigilante_File_Scanner::SNAPSHOT_OPTION] = array(
        ABSPATH . 'index.php' => array('size' => 1, 'modified' => 1, 'hash' => md5_file(ABSPATH . 'index.php')),
    );
    delete_option(Vigilante_File_Scanner::ESCOPO_OPTION);

    $mudancas = Vigilante_File_Scanner::check_changes();
    checa('a 1a rodada apos a mudanca de alcance nao alerta', $mudancas === null);
    checa('e refaz a linha de base completa',
        count(get_option(Vigilante_File_Scanner::SNAPSHOT_OPTION, array())) > 10);

    // CASO RUIM: com o alcance ja marcado, mudanca de verdade TEM que alertar
    escreve('wp-content/plugins/customizacao-falsa/assets/js/segundo-backdoor.php');
    $mudancas = Vigilante_File_Scanner::check_changes();
    $achou = is_array($mudancas) && count(array_filter(
        $mudancas,
        fn($c) => $c['path'] === 'wp-content/plugins/customizacao-falsa/assets/js/segundo-backdoor.php'
                  && $c['action'] === 'NOVO'
    )) === 1;
    checa('backdoor novo no nivel 3 alerta na rodada seguinte', $achou);
}

apaga_arvore(rtrim($raiz, '/'));

echo ($falhas ? "\n$falhas FALHA(S)\n" : "\nTodos passaram\n");
exit($falhas ? 1 : 0);
