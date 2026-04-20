<?php
/**
 * Template da página de administração do Vigilante de WordPress.
 *
 * Variáveis disponíveis: $settings, $logs, $email_logs, $diag, $snapshot
 */

if (!defined('ABSPATH')) exit;
?>
<div class="wrap vigilante-wrap">
    <h1>Vigilante de WordPress <span style="font-size: 12px; background: #d63638; color: #fff; padding: 2px 8px; border-radius: 3px; vertical-align: middle;">BETA</span></h1>

    <div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 10px 14px; margin: 10px 0 16px;">
        Este plugin está em <strong>fase Beta</strong>.
        Para dúvidas, sugestões ou reportar problemas, entre em contato:
        <strong><a href="mailto:nascimento.allang@gmail.com">nascimento.allang@gmail.com</a></strong>
    </div>

    <!-- Abas -->
    <div class="vigilante-tabs">
        <a href="#" class="vigilante-tab active" data-tab="config">Configurações</a>
        <a href="#" class="vigilante-tab" data-tab="logs">Eventos (<?php echo count($logs); ?>)</a>
        <a href="#" class="vigilante-tab" data-tab="email-diag">Diagnóstico de E-mail</a>
    </div>

    <!-- ═══ ABA: CONFIGURAÇÕES ═══ -->
    <div id="tab-config" class="vigilante-panel active">
        <form method="post">
            <?php wp_nonce_field('vigilante_settings', '_wpnonce_settings'); ?>
            <table class="form-table">
                <tr>
                    <th>E-mail para alertas</th>
                    <td>
                        <input type="email" name="vigilante_email"
                               value="<?php echo esc_attr($settings['email'] ?? get_option('admin_email')); ?>"
                               class="regular-text" />
                        <p class="description">Endereço que receberá alertas e relatórios.</p>
                    </td>
                </tr>
                <tr>
                    <th>Alertas imediatos</th>
                    <td>
                        <label>
                            <input type="checkbox" name="vigilante_alert_admin"
                                   <?php checked($settings['alert_new_admin'] ?? true); ?> />
                            Novo administrador criado
                        </label><br>
                        <label>
                            <input type="checkbox" name="vigilante_alert_files"
                                   <?php checked($settings['alert_file_changes'] ?? true); ?> />
                            Alterações em arquivos
                        </label><br>
                        <label>
                            <input type="checkbox" name="vigilante_alert_login"
                                   <?php checked($settings['alert_login_failed'] ?? true); ?> />
                            Tentativas de login falhas (brute force)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Limite brute force</th>
                    <td>
                        <input type="number" name="vigilante_max_failed"
                               value="<?php echo intval($settings['max_failed_logins'] ?? 5); ?>"
                               min="3" max="50" style="width: 70px;" />
                        <span>tentativas por hora para disparar alerta</span>
                    </td>
                </tr>
            </table>

            <h3>Configuração SMTP</h3>
            <p class="description">Configure o SMTP para garantir que os e-mails de alerta sejam entregues. Sem SMTP, os e-mails podem não chegar.</p>

            <table class="form-table">
                <tr>
                    <th>Ativar SMTP</th>
                    <td>
                        <label>
                            <input type="checkbox" name="vigilante_smtp_enabled" id="vigilante_smtp_enabled"
                                   <?php checked($settings['smtp_enabled'] ?? false); ?> />
                            Enviar e-mails via SMTP (recomendado)
                        </label>
                    </td>
                </tr>
                <tr class="vigilante-smtp-field">
                    <th>Servidor SMTP</th>
                    <td>
                        <input type="text" name="vigilante_smtp_host"
                               value="<?php echo esc_attr($settings['smtp_host'] ?? ''); ?>"
                               class="regular-text" placeholder="ex: smtp.gmail.com" />
                    </td>
                </tr>
                <tr class="vigilante-smtp-field">
                    <th>Porta</th>
                    <td>
                        <input type="number" name="vigilante_smtp_port"
                               value="<?php echo intval($settings['smtp_port'] ?? 587); ?>"
                               min="1" max="65535" style="width: 80px;" />
                        <span class="description">587 (TLS) ou 465 (SSL)</span>
                    </td>
                </tr>
                <tr class="vigilante-smtp-field">
                    <th>Criptografia</th>
                    <td>
                        <?php $enc = $settings['smtp_encryption'] ?? 'tls'; ?>
                        <label><input type="radio" name="vigilante_smtp_encryption" value="tls" <?php checked($enc, 'tls'); ?> /> TLS (recomendado)</label>&nbsp;&nbsp;
                        <label><input type="radio" name="vigilante_smtp_encryption" value="ssl" <?php checked($enc, 'ssl'); ?> /> SSL</label>&nbsp;&nbsp;
                        <label><input type="radio" name="vigilante_smtp_encryption" value="none" <?php checked($enc, 'none'); ?> /> Nenhuma</label>
                    </td>
                </tr>
                <tr class="vigilante-smtp-field">
                    <th>Usuário SMTP</th>
                    <td>
                        <input type="text" name="vigilante_smtp_user"
                               value="<?php echo esc_attr($settings['smtp_user'] ?? ''); ?>"
                               class="regular-text" placeholder="seu-email@gmail.com" autocomplete="off" />
                    </td>
                </tr>
                <tr class="vigilante-smtp-field">
                    <th>Senha SMTP</th>
                    <td>
                        <input type="password" name="vigilante_smtp_pass"
                               value="" class="regular-text"
                               placeholder="<?php echo !empty($settings['smtp_pass']) ? '••••••••  (salva)' : ''; ?>"
                               autocomplete="new-password" />
                        <p class="description">
                            <?php if (!empty($settings['smtp_pass'])): ?>
                                Senha já salva (criptografada). Deixe em branco para manter a atual.
                            <?php else: ?>
                                A senha será armazenada de forma criptografada.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr class="vigilante-smtp-field">
                    <th>E-mail remetente</th>
                    <td>
                        <input type="email" name="vigilante_smtp_from"
                               value="<?php echo esc_attr($settings['smtp_from'] ?? ''); ?>"
                               class="regular-text" placeholder="alerta@seudominio.com.br" />
                        <p class="description">Endereço que aparecerá como remetente. Geralmente deve ser o mesmo do usuário SMTP.</p>
                    </td>
                </tr>
                <tr class="vigilante-smtp-field">
                    <th>Nome do remetente</th>
                    <td>
                        <input type="text" name="vigilante_smtp_from_name"
                               value="<?php echo esc_attr($settings['smtp_from_name'] ?? 'Vigilante WP'); ?>"
                               class="regular-text" />
                    </td>
                </tr>
            </table>

            <!-- Guia rápido inline -->
            <div class="vigilante-smtp-field" id="vigilante-smtp-help" style="background: #f0f6fc; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; margin: 10px 0 20px;">
                <strong>Dados SMTP dos provedores mais comuns:</strong>
                <table class="widefat striped" style="margin-top: 8px; max-width: 600px;">
                    <thead>
                        <tr><th>Provedor</th><th>Servidor</th><th>Porta</th><th>Criptografia</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Gmail</td><td><code>smtp.gmail.com</code></td><td>587</td><td>TLS</td></tr>
                        <tr><td>Outlook / Hotmail</td><td><code>smtp.office365.com</code></td><td>587</td><td>TLS</td></tr>
                        <tr><td>Yahoo</td><td><code>smtp.mail.yahoo.com</code></td><td>587</td><td>TLS</td></tr>
                        <tr><td>Locaweb</td><td><code>email-ssl.com.br</code></td><td>465</td><td>SSL</td></tr>
                        <tr><td>Hostgator</td><td><code>mail.seudominio.com.br</code></td><td>587</td><td>TLS</td></tr>
                        <tr><td>UOL Host</td><td><code>smtps.uhserver.com</code></td><td>465</td><td>SSL</td></tr>
                    </tbody>
                </table>
                <p style="margin: 8px 0 0;">
                    <strong>Gmail:</strong> Ative a verificação em duas etapas e crie uma
                    <em>Senha de App</em> em <code>myaccount.google.com → Segurança → Senhas de app</code>.
                    Use essa senha no campo acima (não a senha normal da conta).
                </p>
            </div>

            <p class="submit">
                <input type="submit" name="vigilante_save_settings" class="button-primary" value="Salvar Configurações" />
                <input type="submit" name="vigilante_test_email" class="button-secondary" value="Testar E-mail" />
                <input type="submit" name="vigilante_send_report" class="button-secondary" value="Enviar Relatório Agora" />
                <input type="submit" name="vigilante_reset_snapshot" class="button-secondary" value="Atualizar Snapshot" />
            </p>
        </form>

        <h3>Informações do Sistema</h3>
        <table class="widefat" style="max-width: 500px;">
            <tr>
                <td><strong>Versão</strong></td>
                <td><?php echo esc_html(VIGILANTE_VERSION); ?></td>
            </tr>
            <tr>
                <td><strong>Método de e-mail</strong></td>
                <td><?php echo esc_html($diag['method']); ?></td>
            </tr>
            <tr>
                <td><strong>Arquivos monitorados</strong></td>
                <td><?php echo $snapshot['exists'] ? intval($snapshot['total_files']) . ' arquivos' : 'Nenhum snapshot'; ?></td>
            </tr>
            <tr>
                <td><strong>E-mails enviados</strong></td>
                <td>
                    <?php echo intval($diag['emails_sucesso']); ?> ok
                    <?php if ($diag['emails_falha'] > 0): ?>
                        / <span class="vigilante-status-fail"><?php echo intval($diag['emails_falha']); ?> falhas</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- ═══ ABA: LOGS DE EVENTOS ═══ -->
    <div id="tab-logs" class="vigilante-panel">
        <form method="post" style="margin-bottom: 15px;">
            <?php wp_nonce_field('vigilante_clear_logs', '_wpnonce_clear_logs'); ?>
            <input type="submit" name="vigilante_clear_logs" class="button-secondary"
                   value="Limpar Logs" onclick="return confirm('Tem certeza que deseja limpar todos os logs?');" />
        </form>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width: 160px;">Data/Hora</th>
                    <th style="width: 160px;">Tipo</th>
                    <th>Evento</th>
                    <th style="width: 130px;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="4">Nenhum evento registrado.</td></tr>
                <?php else: ?>
                    <?php foreach (array_slice($logs, 0, 200) as $log): ?>
                        <tr class="<?php echo $log['critical'] ? 'vigilante-critical-row' : ''; ?>">
                            <td><?php echo esc_html($log['time']); ?></td>
                            <td>
                                <strong><?php echo esc_html($log['type']); ?></strong>
                                <?php echo $log['critical'] ? ' <span title="Evento crítico">&#9888;</span>' : ''; ?>
                            </td>
                            <td><?php echo esc_html($log['message']); ?></td>
                            <td><?php echo esc_html($log['ip']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ═══ ABA: DIAGNÓSTICO DE E-MAIL ═══ -->
    <div id="tab-email-diag" class="vigilante-panel">
        <h3>Diagnóstico do Sistema de E-mail</h3>
        <p>Use estas informações para identificar problemas de envio de e-mail.</p>

        <div class="vigilante-diag">
            <dl>
                <dt>Método de envio detectado</dt>
                <dd>
                    <?php
                    $method = $diag['method'];
                    $is_native = str_contains($method, 'mail() nativa');
                    $is_none = str_contains($method, 'Nenhum');
                    ?>
                    <span class="<?php echo $is_none ? 'vigilante-status-fail' : ($is_native ? 'vigilante-status-warn' : 'vigilante-status-ok'); ?>">
                        <?php echo esc_html($method); ?>
                    </span>
                    <?php if ($is_native): ?>
                        <br><small><strong>Atenção:</strong> Com mail() nativa, o WordPress pode reportar "enviado com sucesso" mesmo quando o e-mail
                        <strong>não chega ao destinatário</strong>. Isso acontece porque o servidor aceita o pedido mas não consegue entregar de fato.
                        Instale um plugin SMTP (veja o guia abaixo) para garantir a entrega.</small>
                    <?php endif; ?>
                    <?php if ($is_none): ?>
                        <br><small>Nenhum método de envio disponível. Instale um plugin SMTP para que os e-mails funcionem.</small>
                    <?php endif; ?>
                </dd>

                <dt>PHP mail() disponível</dt>
                <dd class="<?php echo $diag['php_mail'] ? 'vigilante-status-ok' : 'vigilante-status-fail'; ?>">
                    <?php echo $diag['php_mail'] ? 'Sim' : 'Não — a função mail() está desabilitada neste servidor'; ?>
                </dd>

                <dt>SMTP ativo</dt>
                <dd>
                    <?php
                    $external_smtp = array_filter($diag['smtp_plugins'], fn($p) => $p !== 'Vigilante SMTP');
                    $own_smtp = $diag['own_smtp'] ?? false;
                    ?>
                    <?php if (!empty($diag['smtp_plugins'])): ?>
                        <span class="vigilante-status-ok"><?php echo esc_html(implode(', ', $diag['smtp_plugins'])); ?></span>
                        <?php if ($own_smtp && !empty($external_smtp)): ?>
                            <br><small><strong>Nota:</strong> O SMTP do Vigilante está habilitado, mas foi detectado um plugin SMTP externo
                            (<strong><?php echo esc_html(implode(', ', $external_smtp)); ?></strong>).
                            O plugin externo terá prioridade — o Vigilante não irá interferir na configuração existente.</small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="vigilante-status-warn">Nenhum SMTP configurado</span>
                        <br><small>Configure o SMTP na aba <strong>Configurações</strong> para garantir que os e-mails sejam entregues.</small>
                    <?php endif; ?>
                </dd>

                <dt>Configuração PHP de e-mail</dt>
                <dd>
                    SMTP: <code><?php echo esc_html($diag['smtp_host']); ?></code>
                    &nbsp;|&nbsp; Porta: <code><?php echo esc_html($diag['smtp_port']); ?></code>
                    &nbsp;|&nbsp; Sendmail: <code><?php echo esc_html($diag['sendmail_path']); ?></code>
                </dd>

                <dt>E-mail de destino</dt>
                <dd><code><?php echo esc_html($diag['email_destino']); ?></code></dd>

                <dt>Estatísticas de envio</dt>
                <dd>
                    Total: <?php echo intval($diag['emails_enviados']); ?>
                    &nbsp;|&nbsp; <span class="vigilante-status-ok">Sucesso: <?php echo intval($diag['emails_sucesso']); ?></span>
                    &nbsp;|&nbsp; <span class="<?php echo $diag['emails_falha'] > 0 ? 'vigilante-status-fail' : ''; ?>">Falhas: <?php echo intval($diag['emails_falha']); ?></span>
                </dd>

                <?php if ($diag['last_error']): ?>
                <dt>Último erro de envio</dt>
                <dd class="vigilante-status-fail">
                    [<?php echo esc_html($diag['last_error']['time']); ?>]
                    Para: <?php echo esc_html($diag['last_error']['to']); ?><br>
                    Assunto: <?php echo esc_html($diag['last_error']['subject']); ?><br>
                    Erro: <?php echo esc_html($diag['last_error']['error']); ?>
                </dd>
                <?php endif; ?>
            </dl>
        </div>

        <?php if ($is_native || $is_none): ?>
        <div style="background: #fff8e5; border-left: 4px solid #dba617; padding: 12px 16px; margin: 15px 0;">
            <strong>Recomendação:</strong> Configure o SMTP na aba <strong>Configurações</strong> deste plugin para garantir entrega de e-mails.
            Basta marcar <strong>"Ativar SMTP"</strong> e preencher os dados do seu provedor de e-mail.
        </div>
        <?php endif; ?>

        <!-- Guia de configuração para leigos -->
        <?php
        $show_guide = $is_native || $is_none || $diag['emails_falha'] > 0;
        ?>
        <?php if ($show_guide): ?>
        <div class="vigilante-guia-completo">
            <h3>&#128218; Guia de Configuração de E-mail</h3>
            <p>Siga este passo a passo para que o Vigilante consiga enviar e-mails de alerta.</p>

            <h4>Passo 1 — Ativar o SMTP</h4>
            <ol>
                <li>Vá na aba <strong>Configurações</strong> desta página</li>
                <li>Na seção <strong>Configuração SMTP</strong>, marque <strong>"Ativar SMTP"</strong></li>
                <li>Os campos de configuração aparecerão automaticamente</li>
            </ol>

            <h4>Passo 2 — Preencher os dados do seu provedor</h4>
            <p>Escolha abaixo o provedor de e-mail que você usa e preencha os campos correspondentes:</p>

            <div class="guia-provedor">
                <h5>&#128231; Gmail (Google)</h5>
                <ol>
                    <li>Servidor SMTP: <code>smtp.gmail.com</code></li>
                    <li>Porta: <code>587</code></li>
                    <li>Criptografia: <code>TLS</code></li>
                    <li>Usuário: <em>seu endereço Gmail completo</em> (ex: seunome@gmail.com)</li>
                    <li>Senha: use uma <strong>Senha de App</strong> (veja abaixo como criar)</li>
                    <li>E-mail remetente: <em>o mesmo endereço Gmail</em></li>
                </ol>
                <p style="margin-top:6px;"><strong>Como criar a Senha de App do Gmail:</strong></p>
                <ol>
                    <li>Acesse sua conta Google e vá em <strong>Segurança</strong></li>
                    <li>Ative a <strong>Verificação em duas etapas</strong> (se ainda não ativou)</li>
                    <li>Depois, acesse <strong>Senhas de app</strong> (pesquise por "senhas de app" na barra de busca da conta)</li>
                    <li>Crie uma nova senha de app com o nome "Vigilante WP"</li>
                    <li>Copie a senha gerada (16 caracteres) e cole no campo <strong>Senha SMTP</strong></li>
                </ol>
            </div>

            <div class="guia-provedor">
                <h5>&#128231; Outlook / Hotmail / Microsoft 365</h5>
                <ol>
                    <li>Servidor SMTP: <code>smtp.office365.com</code></li>
                    <li>Porta: <code>587</code></li>
                    <li>Criptografia: <code>TLS</code></li>
                    <li>Usuário: <em>seu e-mail completo</em></li>
                    <li>Senha: <em>sua senha do e-mail</em></li>
                </ol>
            </div>

            <div class="guia-provedor">
                <h5>&#128231; Outro provedor (Locaweb, Hostgator, UOL, etc.)</h5>
                <ol>
                    <li>Servidor SMTP: geralmente algo como <code>mail.seudominio.com.br</code></li>
                    <li>Porta: geralmente <code>587</code> (TLS) ou <code>465</code> (SSL)</li>
                    <li>Criptografia: <code>TLS</code> ou <code>SSL</code> conforme a porta</li>
                    <li>Usuário: <em>seu e-mail completo</em></li>
                    <li>Senha: <em>a senha do e-mail</em></li>
                </ol>
                <p>Se não souber esses dados, entre em contato com o suporte da sua hospedagem e pergunte:
                <em>"Quais são os dados SMTP para enviar e-mails pelo meu domínio?"</em></p>
            </div>

            <h4>Passo 3 — Salvar e testar</h4>
            <ol>
                <li>Clique em <strong>Salvar Configurações</strong></li>
                <li>Clique em <strong>Testar E-mail</strong></li>
                <li>Se aparecer uma mensagem verde de sucesso, verifique sua caixa de entrada</li>
                <li>Verifique também a pasta de <strong>Spam / Lixo Eletrônico</strong></li>
            </ol>

            <div class="guia-dica">
                <strong>&#128161; Não está funcionando?</strong> Verifique:
                <ul style="margin: 6px 0 0 20px;">
                    <li>O endereço de <strong>"E-mail de destino"</strong> (acima) está correto?</li>
                    <li>O e-mail foi para a pasta de <strong>Spam / Lixo Eletrônico</strong>?</li>
                    <li>O usuário e a senha do SMTP estão corretos? (atenção a espaços extras)</li>
                    <li>Se usa Gmail, está usando a <strong>Senha de App</strong> e não a senha normal?</li>
                    <li>Sua hospedagem permite envio por SMTP? (pergunte ao suporte)</li>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <h3>Histórico de Envios</h3>
        <form method="post" style="margin-bottom: 15px;">
            <?php wp_nonce_field('vigilante_clear_email_logs', '_wpnonce_clear_email_logs'); ?>
            <input type="submit" name="vigilante_clear_email_logs" class="button-secondary" value="Limpar Histórico" />
        </form>

        <div class="vigilante-email-log">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width: 160px;">Data/Hora</th>
                        <th style="width: 200px;">Destinatário</th>
                        <th>Assunto</th>
                        <th style="width: 80px;">Status</th>
                        <th>Erro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($email_logs)): ?>
                        <tr><td colspan="5">Nenhum envio registrado.</td></tr>
                    <?php else: ?>
                        <?php foreach (array_slice($email_logs, 0, 50) as $elog): ?>
                            <tr>
                                <td><?php echo esc_html($elog['time']); ?></td>
                                <td><?php echo esc_html($elog['to']); ?></td>
                                <td><?php echo esc_html($elog['subject']); ?></td>
                                <td>
                                    <?php if ($elog['success']): ?>
                                        <span class="vigilante-status-ok">OK</span>
                                    <?php else: ?>
                                        <span class="vigilante-status-fail">FALHA</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($elog['error']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Abas
    var tabs = document.querySelectorAll('.vigilante-tab');
    var panels = document.querySelectorAll('.vigilante-panel');

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var target = this.getAttribute('data-tab');

            tabs.forEach(function(t) { t.classList.remove('active'); });
            panels.forEach(function(p) { p.classList.remove('active'); });

            this.classList.add('active');
            document.getElementById('tab-' + target).classList.add('active');
        });
    });

    // Mostrar/ocultar campos SMTP
    var smtpToggle = document.getElementById('vigilante_smtp_enabled');
    if (smtpToggle) {
        function toggleSmtpFields() {
            var fields = document.querySelectorAll('.vigilante-smtp-field');
            fields.forEach(function(field) {
                field.style.display = smtpToggle.checked ? '' : 'none';
            });
        }
        smtpToggle.addEventListener('change', toggleSmtpFields);
        toggleSmtpFields();
    }
});
</script>
