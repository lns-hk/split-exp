// DOM 與格式化工具。只以 textContent 放入用家內容，不使用 innerHTML。
export const $ = id => document.getElementById(id);

export function h(tag, attrs, ...kids) {
  const el = document.createElement(tag);
  if (attrs) for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'text') el.textContent = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (v === true) el.setAttribute(k, '');
    else el.setAttribute(k, v);
  }
  for (const c of kids.flat(Infinity)) if (c !== null && c !== undefined && c !== false) el.append(c);
  return el;
}

export function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }

/** 清空後放入子元素（可傳入巢狀陣列） */
export function fill(el, ...kids) {
  clear(el);
  for (const c of kids.flat(Infinity)) if (c !== null && c !== undefined && c !== false) el.append(c);
  return el;
}

export function lsGet(k) { try { return localStorage.getItem(k); } catch { return null; } }
export function lsSet(k, v) { try { localStorage.setItem(k, v); } catch {} }
export function lsJson(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch { return null; } }

/** 最小單位整數 → 顯示字串（千位逗號），例如 -123456, 2 → "−1,234.56" */
export function fmtMinor(v, dp, { plain = false, sign = false } = {}) {
  const neg = v < 0;
  let s = String(Math.abs(v));
  let frac = '';
  if (dp > 0) { s = s.padStart(dp + 1, '0'); frac = s.slice(-dp); s = s.slice(0, -dp); }
  if (!plain) s = s.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const body = s + (dp > 0 ? '.' + frac : '');
  if (neg) return (plain ? '-' : '−') + body;
  return (sign && v > 0 ? '+' : '') + body;
}

/** 十進制字串 → BigInt 最小單位；格式不符回傳 null（只作預覽，伺服器會再驗證） */
export function parseMinor(str, dp) {
  const m = /^(-?)(\d{1,15})(?:\.(\d+))?$/.exec(String(str).trim().replace(/,/g, ''));
  if (!m) return null;
  const frac = (m[3] || '').replace(/0+$/, '');
  if (frac.length > dp) return null;
  const v = BigInt(m[2] + frac.padEnd(dp, '0'));
  return m[1] ? -v : v;
}

/** 與伺服器相同的換算公式（BigInt，四捨五入），只用作輸入預覽 */
export function previewConvert(amountMinor, rateE8, dpSrc, dpBase) {
  const num = amountMinor * rateE8 * 10n ** BigInt(dpBase);
  const den = 100000000n * 10n ** BigInt(dpSrc);
  const neg = num < 0n;
  const a = neg ? -num : num;
  let q = a / den;
  if ((a % den) * 2n >= den) q += 1n;
  return Number(neg ? -q : q);
}

export function todayStr() {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

export async function copyText(text) {
  try { await navigator.clipboard.writeText(text); return true; } catch {}
  const ta = h('textarea', { readonly: true });
  ta.value = text;
  ta.className = 'offscreen';
  document.body.append(ta);
  ta.select();
  let ok = false;
  try { ok = document.execCommand('copy'); } catch {}
  ta.remove();
  return ok;
}

export function download(name, text, type) {
  const a = h('a', { download: name });
  a.href = URL.createObjectURL(new Blob([text], { type }));
  document.body.append(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}

// 線條圖示（Lucide 風格，24×24、stroke=currentColor）。用 createElementNS 建立，不經 innerHTML。
const ICONS = {
  switch: [['path', { d: 'M8 3 4 7l4 4' }], ['path', { d: 'M4 7h16' }], ['path', { d: 'm16 21 4-4-4-4' }], ['path', { d: 'M20 17H4' }]],
  share: [['circle', { cx: 18, cy: 5, r: 3 }], ['circle', { cx: 6, cy: 12, r: 3 }], ['circle', { cx: 18, cy: 19, r: 3 }],
    ['line', { x1: 8.59, y1: 13.51, x2: 15.42, y2: 17.49 }], ['line', { x1: 15.41, y1: 6.51, x2: 8.59, y2: 10.49 }]],
  settings: [['path', { d: 'M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z' }], ['circle', { cx: 12, cy: 12, r: 3 }]],
  lock: [['rect', { x: 3, y: 11, width: 18, height: 11, rx: 2, ry: 2 }], ['path', { d: 'M7 11V7a5 5 0 0 1 10 0v4' }]],
};

export function icon(name, size = 20) {
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  for (const [k, v] of Object.entries({ viewBox: '0 0 24 24', width: size, height: size, fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' })) svg.setAttribute(k, v);
  for (const [tag, attrs] of ICONS[name]) {
    const el = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
    svg.append(el);
  }
  return svg;
}

/** 只有圖示的按鈕；文字放 aria-label / title */
export function iconBtn(name, label, onclick, { pressed } = {}) {
  return h('button', { type: 'button', class: 'iconbtn' + (pressed ? ' on' : ''), 'aria-label': label, title: label, 'aria-pressed': pressed === undefined ? null : String(pressed), onclick }, icon(name));
}
