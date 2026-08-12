# Vigilante de WordPress

Monitor de segurança para WordPress: vigia usuários, arquivos, logins, plugins e temas, e avisa por e-mail. O que o plugin faz, e como se instala, está no `readme.txt`, que é o texto que o usuário lê.

## Canal de atualização

O plugin não consulta repositório de código. Ele lê um manifesto estático publicado no site do Allan:

- manifesto: `https://allannascimento.com.br/vigilante/atualizacao.json`
- pacote: `https://allannascimento.com.br/vigilante/vigilante-de-wordpress-<versao>.zip`

Nenhuma credencial vive dentro do plugin. Ele é vendido, então qualquer chave embutida aqui acabaria na máquina de quem compra e em site que pode ser invadido. Falha de rede, JSON inválido ou campo faltando não geram erro no painel: o site fica na versão instalada e tenta de novo depois.

O código do canal está em `includes/class-vigilante-updater.php`.

## Publicar uma versão

Suba a versão nos três lugares (`Version:` do header, `VIGILANTE_VERSION` e `Stable tag:` do `readme.txt`), acrescente o changelog no `readme.txt` e rode:

```
node scripts/publicar.mjs
```

O script confere que as três versões batem, gera o zip (pasta interna `vigilante-de-wordpress/`, sem `scripts/`, `tests/` e material de desenvolvimento), descomprime o que acabou de escrever para conferir o CRC de cada arquivo, monta o `atualizacao.json` a partir do `readme.txt` e grava os dois na pasta pública do site. Depois é só commitar e publicar o site.

Destino padrão: `.../ia-aplicada/site/public/vigilante`. Para outro lugar, `--destino <pasta>` ou a variável `VIGILANTE_SITE_PUBLICO`.
