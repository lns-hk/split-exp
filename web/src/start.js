import { T } from './strings.js';
import { h, clear, fill, lsSet, icon } from './util.js';
import { call } from './api.js';
import { linksBlock, LS_LINKS } from './links.js';
import { lostOverlay } from './lost.js';

const DEFAULT_CURS = ['HKD', 'JPY', 'TWD', 'KRW', 'CNY', 'THB', 'SGD', 'MYR', 'USD', 'EUR', 'GBP', 'AUD', 'VND'];

export async function startPage(root) {
  let cfg = { turnstile_sitekey: '', currencies: {} };
  try { cfg = (await call('GET', 'config')).data; } catch {}
  const curs = Object.keys(cfg.currencies || {}).length ? Object.keys(cfg.currencies) : DEFAULT_CURS;
  const members = [];
  let tsToken = '';
  let tsWidget = null;

  const err = h('div', { class: 'banner err hidden' }, h('span'));
  // 重新連線：只確認伺服器可達（Turnstile token 只能用一次，需由用家重新按「建立行程」）
  const lost = lostOverlay({ onRetry: () => call('GET', 'config').then(() => lost.ok(), e => lost.fail(e, { immediate: true })) });
  const showErr = m => { err.firstChild.textContent = m; err.classList.remove('hidden'); };
  const hideErr = () => err.classList.add('hidden');

  const name = h('input', { type: 'text', id: 'tripName', maxlength: '60', placeholder: T.fTripNamePh, autocomplete: 'off' });
  const base = h('select', { id: 'tripBase' }, curs.map(c => h('option', { value: c, text: c })));
  base.value = 'HKD';
  const mInput = h('input', { type: 'text', id: 'memberName', maxlength: '30', placeholder: T.fMemberPh, autocomplete: 'off' });
  const chips = h('div', { class: 'chips' });
  const renderChips = () => {
    clear(chips);
    members.forEach((m, i) => chips.append(h('span', { class: 'chip on removable' }, m,
      h('button', { type: 'button', class: 'x', 'aria-label': T.remove + ' ' + m, text: '×', onclick: () => { members.splice(i, 1); renderChips(); } }))));
  };
  const addMember = () => {
    const v = mInput.value.trim();
    if (!v) return true;
    if (members.includes(v)) { showErr(T.dupMember(v)); return false; }
    if (members.length >= 50) return false;
    hideErr(); members.push(v); mInput.value = ''; renderChips(); mInput.focus();
    return true;
  };
  // 中文輸入法選字時的 Enter 不算「加入」
  mInput.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.isComposing && e.keyCode !== 229) { e.preventDefault(); addMember(); } });

  const tsBox = h('div', { id: 'turnstile', class: 'turnstile' });
  const createBtn = h('button', { type: 'submit', class: 'btn full', text: T.create });

  const form = h('form', { class: 'card', autocomplete: 'off' },
    h('h2', { text: T.startTitle }),
    h('p', { class: 'hint lead', text: T.startLead }),
    h('label', { class: 'lbl', for: 'tripName', text: T.fTripName }), name,
    h('label', { class: 'lbl', for: 'tripBase', text: T.fBase }), base,
    h('label', { class: 'lbl', for: 'memberName', text: T.fMembers }),
    h('div', { class: 'row' }, mInput, h('button', { type: 'button', class: 'btn ghost fix', text: T.add, onclick: addMember })),
    chips,
    tsBox,
    h('div', { class: 'mt' }, createBtn),
  );

  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    if (mInput.value.trim() && !addMember()) return;
    if (!name.value.trim()) { showErr(T.needName); name.focus(); return; }
    if (!members.length) { showErr(T.needMembers); mInput.focus(); return; }
    if (cfg.turnstile_sitekey && !tsToken) { showErr(T.needTurnstile); return; }
    hideErr();
    createBtn.disabled = true; createBtn.textContent = T.creating;
    try {
      const { data } = await call('POST', 'trips', { body: { name: name.value.trim(), base_currency: base.value, members, turnstile_token: tsToken } });
      lsSet(LS_LINKS(data.trip_id), JSON.stringify({ edit: data.edit_url, view: data.view_url }));
      showResult(data);
      lost.ok();
    } catch (e) {
      showErr(e.message);
      lost.fail(e, { immediate: true });   // 建立失敗＝未儲存，立即提示
    } finally {
      // Turnstile token 只能使用一次：請求完成後一律重設，重試時取得新 token
      if (tsWidget !== null && window.turnstile) { try { window.turnstile.reset(tsWidget); } catch {} }
      tsToken = '';
      createBtn.disabled = false; createBtn.textContent = T.create;
    }
  });

  function showResult(d) {
    fill(root, 
      h('header', null, h('h1', { text: T.appName })),
      h('section', { class: 'card' },
        h('h2', { text: T.createdTitle }),
        linksBlock(d.edit_url, d.view_url),
        h('a', { class: 'btn full mt', href: d.edit_url, text: T.enterTrip }),
      ),
      footer(),
    );
  }

  const adminLink = h('a', { class: 'adminlink', href: new URL('admin/', document.baseURI).href, title: T.adminLogin }, icon('lock', 16), h('span', { text: T.adminLogin }));
  fill(root, h('header', null, h('h1', { text: T.appName }), adminLink), err, form, footer());
  name.focus();

  if (cfg.turnstile_sitekey) {
    window.__tsReady = () => {
      tsWidget = window.turnstile.render(tsBox, {
        sitekey: cfg.turnstile_sitekey,
        action: 'create_trip',      // 與伺服器 TURNSTILE_ACTION 一致
        language: 'zh-tw',
        callback: t => { tsToken = t; },
        'expired-callback': () => { tsToken = ''; },
        'error-callback': () => { tsToken = ''; },
      });
    };
    const s = document.createElement('script');
    s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=__tsReady';
    s.async = true; s.defer = true;
    document.head.append(s);
  }
}

export function footer(extra) {
  return h('footer', null, extra || null,
    h('a', { href: '/', text: '← 首頁' }), ' · ',
    h('a', { href: new URL('privacy.html', document.baseURI).href, text: T.privacy }), ' · ',
    h('a', { href: new URL('terms.html', document.baseURI).href, text: T.terms }));
}
