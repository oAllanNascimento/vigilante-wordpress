=== Vigilante de WordPress ===
Contributors: allannascimento
Tags: seguranca, security, monitoramento, alertas, malware
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor de segurança do WordPress: vigia usuários, arquivos, logins e plugins, e avisa por e-mail quando algo suspeito acontece.

== Description ==

O Vigilante de WordPress fica de olho no seu site e avisa por e-mail quando detecta atividade suspeita. É leve, funciona com o envio de e-mail do próprio site (wp_mail) e não depende de nenhum serviço externo.

O que ele monitora:

* Criação de novo usuário administrador
* Alteração de perfil de usuário para administrador
* Remoção de usuários
* Logins realizados e tentativas de login que falharam (brute force)
* Ativação e desativação de plugins
* Troca de tema
* Atualizações de plugins, temas e do núcleo
* Alterações em arquivos PHP e outros arquivos sensíveis (novos, modificados ou removidos), inclusive dentro da pasta de uploads, que é vetor comum de invasão

Como ele avisa:

* Alerta imediato por e-mail para eventos críticos (novo admin, alteração de arquivo, brute force)
* Relatório periódico (diário ou semanal, você escolhe) com o resumo das atividades e a lista de administradores atuais
* Registro de todos os eventos no painel, com data, tipo, descrição e IP de origem

Envio de e-mail:

O plugin usa o sistema de e-mail do próprio WordPress (wp_mail). Se o seu servidor entrega e-mails normalmente, funciona sem configurar nada. Se preferir mais confiabilidade na entrega, há uma configuração de SMTP embutida (compatível com Gmail, Outlook, Locaweb, Hostgator e outros). O plugin também respeita plugins de SMTP já instalados (WP Mail SMTP, FluentSMTP, etc.) e não interfere na configuração deles.

Nenhum dado sai do seu site. Não há telemetria, licença online nem dependência de servidores de terceiros.

== Installation ==

1. No painel do WordPress, vá em Plugins, Adicionar novo, Enviar plugin.
2. Selecione o arquivo vigilante-de-wordpress.zip e clique em Instalar agora.
3. Ative o plugin.
4. Acesse o menu Vigilante WP na barra lateral.
5. Confira o e-mail de destino (por padrão é o e-mail do administrador do site) e a frequência do relatório.
6. Opcional: configure o SMTP se quiser garantir a entrega dos e-mails.
7. Clique em Testar E-mail para confirmar que os avisos chegam na sua caixa.

Na ativação, o plugin cria um snapshot inicial dos arquivos monitorados. A partir daí, qualquer arquivo novo, modificado ou removido é detectado nas verificações seguintes.

== Frequently Asked Questions ==

= Preciso configurar SMTP? =

Não necessariamente. Se o seu servidor já entrega e-mails, o plugin funciona direto. Configure o SMTP apenas se os e-mails de teste não estiverem chegando.

= Recebi um aviso de alteração de arquivo depois de atualizar um plugin. É normal? =

Sim. Atualizar plugins, temas ou o WordPress altera arquivos, e o Vigilante detecta isso. Se a mudança foi você quem fez, pode ignorar. Se você não fez nada, vale investigar.

= Como o plugin evita falso alarme logo na ativação? =

Ele tira um snapshot dos arquivos no momento da ativação e passa a comparar a partir daí. Você também pode clicar em Atualizar Snapshot depois de uma atualização grande para redefinir a referência.

= O plugin envia dados para algum servidor externo? =

Não. Todo o monitoramento e o registro ficam no seu próprio site. Os e-mails saem pelo sistema de e-mail do seu WordPress.

= Funciona em PHP 7.4? =

Não. O plugin exige PHP 8.0 ou superior.

= Ao desinstalar, ele deixa lixo no banco de dados? =

Não. Ao remover o plugin, todas as opções, registros e tarefas agendadas são apagadas.

== Changelog ==

= 1.2.1 =
* A migração para relatório semanal passa a rodar no `init`, e não só no painel: site que ninguém abre no wp-admin continuaria mandando relatório diário para sempre.

= 1.2.0 =
* O relatório periódico passa a ser SEMANAL, inclusive em quem já tinha o plugin (migração de uma vez só, por versão). O que é urgente continua saindo na hora, como alerta próprio.
* Achado repetido de arquivo não gera e-mail novo dentro de 24 horas. Ele continua no log e aparece resumido no próximo alerta, com a contagem de vezes.
* Lista de caminhos ignorados na vigilância de arquivos, configurável no painel, com curinga. Cada caminho ignorado aparece no relatório periódico, pra não virar ponto cego esquecido.
* Atualizar o snapshot pelo painel também zera o estado de repetição.
* Motivo das duas mudanças: no Hospital da Plástica, o All-in-One WP Migration apagava e recriava o próprio `storage/.htaccess` uma vez por dia, e o Vigilante alertava certo, todo dia, por semanas. Monitor que repete achado conhecido treina quem lê a ignorar o canal.

= 1.0.0 =
* Primeira versão estável.
* Monitoramento de usuários, arquivos, logins, plugins e temas.
* Alertas imediatos por e-mail para eventos críticos.
* Relatório periódico com frequência configurável (diário ou semanal).
* Configuração de SMTP embutida, com detecção de plugins de SMTP externos.
* Diagnóstico de e-mail e histórico de envios no painel.
* Desinstalação limpa (uninstall.php remove todas as opções e tarefas agendadas).
