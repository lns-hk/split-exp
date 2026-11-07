// 「連線中斷」全畫面提示：只計算連線層錯誤（網絡不通，或非 API JSON 的非 2xx 回應）
import { T } from './strings.js';
import { h } from './util.js';

let toastEl = null, toastT = null;
export function toast(msg) {
  if (!toastEl) { toastEl = h('div', { class: 'toast', role: 'status' }); document.body.append(toastEl); }
  toastEl.textContent = msg;
  toastEl.classList.add('show');
  clearTimeout(toastT);
  toastT = setTimeout(() => toastEl.classList.remove('show'), 2200);
}

export function lostOverlay({ onRetry, who }) {
  const msg = h('p', { id: 'lostMsg' });
  const detail = h('div', { class: 'detail', id: 'lostDetail' });
  const cnt = h('p', { class: 'cnt', id: 'lostCount' });
  const el = h('div', { class: 'lost hidden', id: 'lostOverlay', role: 'alertdialog', 'aria-live': 'assertive', 'aria-labelledby': 'lostTitle' },
    h('div', { class: 'box' },
      h('h2', { id: 'lostTitle', text: T.lostTitle }), msg, detail,
      h('div', { class: 'row' },
        h('button', { type: 'button', class: 'btn', id: 'lostRetry', text: T.lostRetry, onclick: () => retry() }),
        h('button', { type: 'button', class: 'btn ghost', id: 'lostReload', text: T.lostReload, onclick: () => location.reload() }),
        h('button', { type: 'button', class: 'btn ghost', id: 'lostClose', text: T.lostClose, onclick: () => { hide(); muteUntil = Date.now() + 60000; } })),   // 關閉後 60 秒內不再彈出
      cnt));
  document.body.append(el);

  let fails = 0, shown = false, muteUntil = 0, lastErr = '';
  const count = () => { cnt.textContent = T.lostCount(fails, new Date().toLocaleTimeString('zh-HK', { hour12: false })); };
  function show(e) {
    count();
    if (shown || Date.now() < muteUntil) return;
    const st = e && e.status;
    msg.textContent = st === 400 ? T.lost400 : st >= 500 ? T.lost5xx(st) : T.lostNet;
    const w = who ? who() : '';
    detail.textContent = (w ? w + '　' : '') + T.lostLast(lastErr || (e && e.message) || '—');
    shown = true;
    el.classList.remove('hidden');
  }
  function hide() { shown = false; el.classList.add('hidden'); }
  function retry() { hide(); fails = 0; muteUntil = 0; toast(T.reconnecting); onRetry && onRetry(); }

  return {
    /** 回傳 true 表示屬連線層錯誤並已計算 */
    fail(e, { immediate = false } = {}) {
      if (!e || e.app) return false;
      fails++; lastErr = e.message || '';
      if (immediate || fails >= 3) show(e); else if (shown) count();
      return true;
    },
    ok() { if (fails) { fails = 0; if (shown) { hide(); toast(T.reconnected); } } },
    offline() { fails = Math.max(fails, 3); lastErr = T.offline; show({ status: 0 }); },
    reset() { fails = 0; muteUntil = 0; hide(); },
    get shown() { return shown; },
  };
}
