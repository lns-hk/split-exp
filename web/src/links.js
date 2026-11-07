import { T } from './strings.js';
import { h, copyText } from './util.js';
import { qrSvg } from './qr.js';

export function tripUrl(token) { return new URL('t/' + token, document.baseURI).href; }
export const LS_LINKS = id => `split-exp:links:${id}`;

function linkRow(label, note, url) {
  const btn = h('button', { type: 'button', class: 'btn ghost sm', text: T.copy });
  btn.addEventListener('click', async () => {
    const ok = await copyText(url);
    btn.textContent = ok ? T.copied : T.copyFail;
    setTimeout(() => { btn.textContent = T.copy; }, 1800);
  });
  return h('div', { class: 'linkbox' },
    h('div', { class: 'linkhead' }, h('strong', { text: label }), btn),
    note ? h('p', { class: 'hint', text: note }) : null,
    h('input', { type: 'text', readonly: true, class: 'linkinput num', value: url, onfocus: e => e.target.select() }),
    h('div', { class: 'qrwrap' }, qrSvg(url)),
  );
}

/** 兩條連結 + QR；未知的連結（null）顯示說明 */
export function linksBlock(editUrl, viewUrl) {
  return h('div', { class: 'links' },
    h('div', { class: 'banner warn' }, h('span', { text: T.keyWarning })),
    editUrl ? linkRow(T.editLink, T.shareEditNote, editUrl) : null,
    viewUrl ? linkRow(T.viewLink, T.shareViewNote, viewUrl) : h('p', { class: 'hint', text: T.viewUnknown }),
  );
}
