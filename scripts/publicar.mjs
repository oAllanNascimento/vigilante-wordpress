#!/usr/bin/env node
/**
 * Publica uma versão do Vigilante de WordPress no canal próprio de atualização.
 *
 * Gera dois arquivos na pasta pública do site:
 *   vigilante-de-wordpress-<versao>.zip   pacote instalável
 *   atualizacao.json                      manifesto lido pelo plugin
 *
 * Uso:
 *   node scripts/publicar.mjs [--destino <pasta>]
 *
 * Sem dependência externa de propósito: o zip é escrito byte a byte aqui. O
 * Compress-Archive do PowerShell grava separador de Windows dentro do arquivo,
 * e o pacote é descompactado por um servidor Linux.
 */

import { createHash } from 'node:crypto';
import { deflateRawSync, inflateRawSync } from 'node:zlib';
import { readFileSync, writeFileSync, readdirSync, statSync, mkdirSync, existsSync } from 'node:fs';
import { join, dirname, posix } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = dirname(dirname(fileURLToPath(import.meta.url)));
const SLUG = 'vigilante-de-wordpress';
const NOME = 'Vigilante de WordPress';
const BASE_URL = 'https://allannascimento.com.br/vigilante';
const DESTINO_PADRAO = 'C:/Users/Allan/Documents/nascimento-estudio/clientes/allannascimento/ia-aplicada/site/public/vigilante';

// Fora do pacote: material de desenvolvimento, testes e o próprio publicador.
// Quem compra recebe só o que o WordPress executa.
const EXCLUIDOS = new Set(['.git', '.claude', '.gitignore', 'scripts', 'tests', 'GRILL-ME.md', 'README.md']);

/* ---------------------------------------------------------------- versão --- */

function leVersao() {
  const principal = readFileSync(join(RAIZ, `${SLUG}.php`), 'utf8');
  const readme = readFileSync(join(RAIZ, 'readme.txt'), 'utf8');

  const header = principal.match(/^\s*\*\s*Version:\s*(.+)$/m)?.[1].trim();
  const constante = principal.match(/define\(\s*'VIGILANTE_VERSION'\s*,\s*'([^']+)'\s*\)/)?.[1];
  const estavel = readme.match(/^Stable tag:\s*(.+)$/m)?.[1].trim();

  // Os três valores vivem em arquivos diferentes e é fácil subir um e esquecer
  // outro. Divergência aqui publicaria um manifesto que não bate com o zip.
  if (!header || !constante || !estavel) {
    throw new Error(`versão não encontrada (header=${header}, constante=${constante}, stable tag=${estavel})`);
  }
  if (header !== constante || header !== estavel) {
    throw new Error(`versões divergentes: header=${header}, VIGILANTE_VERSION=${constante}, Stable tag=${estavel}`);
  }

  return {
    versao: header,
    requires: principal.match(/^\s*\*\s*Requires at least:\s*(.+)$/m)?.[1].trim() || '',
    requiresPhp: principal.match(/^\s*\*\s*Requires PHP:\s*(.+)$/m)?.[1].trim() || '',
    tested: readme.match(/^Tested up to:\s*(.+)$/m)?.[1].trim() || '',
    readme,
  };
}

/* ---------------------------------------------------------------- readme --- */

function escapaHtml(texto) {
  return texto.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function inline(texto) {
  return escapaHtml(texto)
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code>$1</code>');
}

/** Recorta uma seção do readme.txt pelo título entre sinais de igual. */
function secaoDoReadme(readme, titulo) {
  const partes = readme.split(/^==\s*(.+?)\s*==\s*$/m);
  for (let i = 1; i < partes.length; i += 2) {
    if (partes[i].toLowerCase() === titulo.toLowerCase()) return partes[i + 1].trim();
  }
  return '';
}

/** Converte o texto do readme em HTML simples, do subconjunto que o wp_kses_post aceita. */
function paraHtml(texto) {
  const saida = [];
  let lista = null;

  const fechaLista = () => {
    if (lista) {
      saida.push(`<ul>${lista.map((item) => `<li>${item}</li>`).join('')}</ul>`);
      lista = null;
    }
  };

  for (const linha of texto.split(/\r?\n/)) {
    const bruta = linha.trim();
    if (!bruta) {
      fechaLista();
      continue;
    }
    const titulo = bruta.match(/^=\s*(.+?)\s*=$/);
    if (titulo) {
      fechaLista();
      saida.push(`<h4>${inline(titulo[1])}</h4>`);
      continue;
    }
    const item = bruta.match(/^\*\s+(.+)$/);
    if (item) {
      lista = lista || [];
      lista.push(inline(item[1]));
      continue;
    }
    fechaLista();
    saida.push(`<p>${inline(bruta)}</p>`);
  }
  fechaLista();

  return saida.join('\n');
}

/* ------------------------------------------------------------------- zip --- */

const TABELA_CRC = (() => {
  const tabela = new Int32Array(256);
  for (let i = 0; i < 256; i++) {
    let c = i;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    tabela[i] = c;
  }
  return tabela;
})();

function crc32(buffer) {
  let c = -1;
  for (let i = 0; i < buffer.length; i++) c = TABELA_CRC[(c ^ buffer[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

function horaDos(data) {
  return ((data.getHours() << 11) | (data.getMinutes() << 5) | (data.getSeconds() >> 1)) & 0xffff;
}

function dataDos(data) {
  return (((data.getFullYear() - 1980) << 9) | ((data.getMonth() + 1) << 5) | data.getDate()) & 0xffff;
}

/** Lista os arquivos que entram no pacote, em ordem estável. */
function listaArquivos(pasta = RAIZ, prefixo = '') {
  const encontrados = [];
  for (const nome of readdirSync(pasta).sort()) {
    if (EXCLUIDOS.has(nome)) continue;
    const caminho = join(pasta, nome);
    const relativo = prefixo ? posix.join(prefixo, nome) : nome;
    if (statSync(caminho).isDirectory()) {
      encontrados.push(...listaArquivos(caminho, relativo));
    } else {
      encontrados.push({ caminho, interno: posix.join(SLUG, relativo) });
    }
  }
  return encontrados;
}

function montaZip(arquivos) {
  const locais = [];
  const centrais = [];
  let offset = 0;

  for (const arquivo of arquivos) {
    const conteudo = readFileSync(arquivo.caminho);
    const comprimido = deflateRawSync(conteudo, { level: 9 });
    const nome = Buffer.from(arquivo.interno, 'utf8');
    const mtime = statSync(arquivo.caminho).mtime;
    const crc = crc32(conteudo);

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4); // versão mínima para extrair
    local.writeUInt16LE(0, 6); // sem flags: nome em ASCII, tamanhos conhecidos
    local.writeUInt16LE(8, 8); // deflate
    local.writeUInt16LE(horaDos(mtime), 10);
    local.writeUInt16LE(dataDos(mtime), 12);
    local.writeUInt32LE(crc, 14);
    local.writeUInt32LE(comprimido.length, 18);
    local.writeUInt32LE(conteudo.length, 22);
    local.writeUInt16LE(nome.length, 26);
    local.writeUInt16LE(0, 28);
    locais.push(local, nome, comprimido);

    const central = Buffer.alloc(46);
    central.writeUInt32LE(0x02014b50, 0);
    central.writeUInt16LE(0x031e, 4); // criado em unix, para valer a permissão abaixo
    central.writeUInt16LE(20, 6);
    central.writeUInt16LE(0, 8);
    central.writeUInt16LE(8, 10);
    central.writeUInt16LE(horaDos(mtime), 12);
    central.writeUInt16LE(dataDos(mtime), 14);
    central.writeUInt32LE(crc, 16);
    central.writeUInt32LE(comprimido.length, 20);
    central.writeUInt32LE(conteudo.length, 24);
    central.writeUInt16LE(nome.length, 28);
    central.writeUInt16LE(0, 30);
    central.writeUInt16LE(0, 32);
    central.writeUInt16LE(0, 34);
    central.writeUInt16LE(0, 36);
    central.writeUInt32LE(0o100644 * 0x10000, 38); // arquivo comum, 644, no destino
    central.writeUInt32LE(offset, 42);
    centrais.push(central, nome);

    offset += local.length + nome.length + comprimido.length;
  }

  const diretorio = Buffer.concat(centrais);
  const fim = Buffer.alloc(22);
  fim.writeUInt32LE(0x06054b50, 0);
  fim.writeUInt16LE(0, 4);
  fim.writeUInt16LE(0, 6);
  fim.writeUInt16LE(arquivos.length, 8);
  fim.writeUInt16LE(arquivos.length, 10);
  fim.writeUInt32LE(diretorio.length, 12);
  fim.writeUInt32LE(offset, 16);
  fim.writeUInt16LE(0, 20);

  return Buffer.concat([...locais, diretorio, fim]);
}

/**
 * Relê o zip escrito e descomprime cada entrada, conferindo o CRC.
 *
 * O escritor acima é artesanal: sem esta conferência, um deslocamento errado de
 * cabeçalho só apareceria no site de quem instalasse a atualização.
 */
function conferiZip(zip) {
  const fim = zip.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
  if (fim < 0) throw new Error('zip sem marca de fim de diretório central');

  const total = zip.readUInt16LE(fim + 10);
  let ponteiro = zip.readUInt32LE(fim + 16);
  const nomes = [];

  for (let i = 0; i < total; i++) {
    if (zip.readUInt32LE(ponteiro) !== 0x02014b50) throw new Error(`entrada ${i} sem cabeçalho central`);
    const crc = zip.readUInt32LE(ponteiro + 16);
    const tamanhoComprimido = zip.readUInt32LE(ponteiro + 20);
    const tamanhoOriginal = zip.readUInt32LE(ponteiro + 24);
    const tamanhoNome = zip.readUInt16LE(ponteiro + 28);
    const offsetLocal = zip.readUInt32LE(ponteiro + 42);
    const nome = zip.subarray(ponteiro + 46, ponteiro + 46 + tamanhoNome).toString('utf8');

    if (zip.readUInt32LE(offsetLocal) !== 0x04034b50) throw new Error(`${nome}: cabeçalho local inválido`);
    const inicio = offsetLocal + 30 + zip.readUInt16LE(offsetLocal + 26) + zip.readUInt16LE(offsetLocal + 28);
    const conteudo = inflateRawSync(zip.subarray(inicio, inicio + tamanhoComprimido));

    if (conteudo.length !== tamanhoOriginal) throw new Error(`${nome}: tamanho descomprimido diverge`);
    if (crc32(conteudo) !== crc) throw new Error(`${nome}: CRC diverge`);
    if (!nome.startsWith(`${SLUG}/`)) throw new Error(`${nome}: fora da pasta ${SLUG}/`);

    nomes.push(nome);
    ponteiro += 46 + tamanhoNome + zip.readUInt16LE(ponteiro + 30) + zip.readUInt16LE(ponteiro + 32);
  }

  if (!nomes.includes(`${SLUG}/${SLUG}.php`)) throw new Error('o arquivo principal do plugin não está no pacote');
  return nomes;
}

/* ------------------------------------------------------------------ main --- */

function argumento(nome, padrao) {
  const i = process.argv.indexOf(nome);
  return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : padrao;
}

function main() {
  const destino = argumento('--destino', process.env.VIGILANTE_SITE_PUBLICO || DESTINO_PADRAO).replace(/\\/g, '/');
  const info = leVersao();

  const arquivos = listaArquivos();
  const zip = montaZip(arquivos);
  const nomes = conferiZip(zip);

  const nomeZip = `${SLUG}-${info.versao}.zip`;
  const manifesto = {
    name: NOME,
    slug: SLUG,
    version: info.versao,
    download_url: `${BASE_URL}/${nomeZip}`,
    homepage: `${BASE_URL}/`,
    author: 'Allan Nascimento',
    requires: info.requires,
    requires_php: info.requiresPhp,
    tested: info.tested,
    last_updated: new Date().toISOString().replace('T', ' ').slice(0, 19),
    sections: {
      description: paraHtml(secaoDoReadme(info.readme, 'Description')),
      installation: paraHtml(secaoDoReadme(info.readme, 'Installation')),
      changelog: paraHtml(secaoDoReadme(info.readme, 'Changelog')),
    },
  };

  if (!existsSync(destino)) mkdirSync(destino, { recursive: true });
  writeFileSync(join(destino, nomeZip), zip);
  writeFileSync(join(destino, 'atualizacao.json'), JSON.stringify(manifesto, null, 2) + '\n');

  const sha = createHash('sha256').update(zip).digest('hex');
  console.log(`versão ${info.versao}`);
  console.log(`${nomes.length} arquivos, ${(zip.length / 1024).toFixed(1)} KB`);
  console.log(`sha256 ${sha}`);
  console.log('');
  for (const nome of nomes) console.log(`  ${nome}`);
  console.log('');
  console.log(`escrito em ${destino}:`);
  console.log(`  ${nomeZip}`);
  console.log('  atualizacao.json');

  const antigos = readdirSync(destino).filter((n) => n.endsWith('.zip') && n !== nomeZip);
  if (antigos.length) console.log(`\nversões anteriores mantidas na pasta: ${antigos.join(', ')}`);
}

try {
  main();
} catch (erro) {
  console.error(`falhou: ${erro.message}`);
  process.exit(1);
}
