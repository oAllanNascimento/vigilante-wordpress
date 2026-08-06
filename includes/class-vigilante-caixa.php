<?php
/**
 * Vigilante_Caixa
 *
 * Caixa de saída dos alertas críticos: o site DEPOSITA, quem BUSCA é de fora.
 *
 * Por que existe (05/08/2026, depois da invasão da Faculdade Bela Vista): o alerta
 * do Vigilante saía só por e-mail, e e-mail é a perna frágil do aviso. No droplet
 * daquele site a DigitalOcean bloqueia SMTP de saída, e num site invadido quem
 * manda no WordPress manda também no destinatário configurado aqui. Um alerta que
 * depende de uma única via, entregue pela máquina comprometida, é um alerta que
 * some justamente no dia em que importa.
 *
 * O que este arquivo deliberadamente NÃO faz: falar com uma API nossa. Token nosso
 * guardado no servidor do cliente é token na mão de quem tomar o servidor do
 * cliente. Aqui o alerta fica parado numa opção do próprio site, e uma máquina
 * nossa passa de fora para recolher (sentinela.py publica o veredito, health-check.py
 * lê e avisa no Boring Chat). Nada com poder nenhum mora aqui dentro.
 *
 * Não substitui o e-mail: soma. As duas vias saem do mesmo ponto, em
 * Vigilante_Email::send_critical_alert().
 */

if (!defined('ABSPATH')) exit;

class Vigilante_Caixa {

    const OPCAO = 'vigilante_caixa';

    /** Teto de entradas guardadas. Caixa é aviso pendente, não histórico: o
     *  histórico completo é o log de segurança. */
    const MAX_ENTRADAS = 50;

    /** Entrada mais velha que isso já foi lida (ou perdida) faz tempo. */
    const VALIDADE_HORAS = 48;

    /** A mensagem inteira já está no log de segurança. Aqui só precisa caber o
     *  suficiente para a pessoa entender o que houve pelo WhatsApp: um alerta de
     *  arquivo alterado traz até 50 caminhos, e 50 desses estouraria a opção. */
    const MAX_CARACTERES = 500;

    /**
     * Deposita um alerta crítico na caixa.
     *
     * @param string $tipo     Tipo do evento (mesmo vocabulário do log).
     * @param string $mensagem Descrição do evento.
     */
    public static function registrar($tipo, $mensagem) {
        Vigilante_Logger::acquire_lock('caixa');

        try {
            $entradas = get_option(self::OPCAO, []);
            if (!is_array($entradas)) $entradas = [];

            $entradas[] = [
                'quando'   => time(),
                'tipo'     => (string) $tipo,
                'mensagem' => mb_substr((string) $mensagem, 0, self::MAX_CARACTERES),
            ];

            $corte = time() - (self::VALIDADE_HORAS * HOUR_IN_SECONDS);
            $entradas = array_values(array_filter($entradas, function ($e) use ($corte) {
                return ($e['quando'] ?? 0) >= $corte;
            }));

            if (count($entradas) > self::MAX_ENTRADAS) {
                $entradas = array_slice($entradas, -self::MAX_ENTRADAS);
            }

            update_option(self::OPCAO, $entradas, false);

            // Medido na Bela Vista em 05/08/2026: o PRIMEIRO depósito da caixa não
            // apareceu para o processo seguinte (o e-mail do mesmo alerta saiu
            // normalmente, então a função rodou até o fim), e as cinco tentativas
            // seguintes funcionaram. A explicação que sobra é a lista de opções
            // inexistentes que o cache de objeto persistente (Redis, ativo neste
            // site) guarda: ela continua dizendo que a caixa não existe depois de a
            // caixa ter sido criada. Não é certeza, é a hipótese que sobreviveu, e
            // apagar essa lista custa uma consulta a mais numa função que só roda em
            // alerta crítico. Perder o primeiro alerta de um site é caro demais para
            // deixar por conta de uma hipótese não descartada.
            wp_cache_delete('notoptions', 'options');
        } finally {
            Vigilante_Logger::release_lock('caixa');
        }
    }

    /**
     * Alertas depositados nos últimos N minutos.
     *
     * Quem consome de verdade é o sentinela.py, que lê a opção por WP-CLI de fora
     * do PHP. Este método existe para conferir a caixa pelo `wp eval` sem abrir o
     * banco na mão.
     */
    public static function pendentes($minutos = 90) {
        $corte = time() - ($minutos * MINUTE_IN_SECONDS);
        $entradas = get_option(self::OPCAO, []);
        if (!is_array($entradas)) return [];

        return array_values(array_filter($entradas, function ($e) use ($corte) {
            return ($e['quando'] ?? 0) >= $corte;
        }));
    }
}
