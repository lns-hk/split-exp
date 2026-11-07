import { T } from './strings.js';
import { h, fill, lsGet, lsSet, lsJson, fmtMinor, parseMinor, previewConvert, todayStr, copyText, download, iconBtn } from './util.js';
import { call, ApiErr } from './api.js';
import { linksBlock, tripUrl, LS_LINKS } from './links.js';
import { footer } from './start.js';
import { lostOverlay } from './lost.js';

const POLL_MS = 10000;
const MAX_BACKOFF_MS = 60000;

export function tripPage(root, token) {
  let st = null;           // 伺服器回傳的完整行程
  let etag = null;
  let fails = 0;
  let timer = null;
  let inflight = false;
  let me = null;           // 本機選擇的成員 id
  let editing = null;      // 修改中的使費
  let payer = null;
  let sharers = new Set();
  let textShown = false;

  const ro = () => !st || st.trip.mode !== 'edit';
  const dpOf = cur => (st.currencies[cur] ?? 2);
  const baseDp = () => dpOf(st.trip.base_currency);
  const money = (v, opt) => fmtMinor(v, baseDp(), opt);
  const memberMap = () => new Map(st.members.map(m => [m.id, m]));
  const active = () => st.members.filter(m => !m.archived);
  const meKey = () => `split-exp:me:${st.trip.id}`;

  // ---------------------------------------------------------------- 版面
  const status = h('span', { class: 'status', text: T.stConnecting });
  const title = h('h1', { text: T.appName });
  const sub = h('p', { class: 'sub' });
  const errB = h('div', { class: 'banner err hidden' }, h('span'), h('button', { type: 'button', 'aria-label': T.close, text: '×', onclick: () => errB.classList.add('hidden') }));
  const warnB = h('div', { class: 'banner warn hidden' }, h('span'), h('button', { type: 'button', 'aria-label': T.close, text: '×', onclick: () => warnB.classList.add('hidden') }));
  const roB = h('div', { class: 'banner info hidden' }, h('span', { text: T.readOnly }));
  const sharePanel = h('section', { class: 'card hidden' });
  const settingsPanel = h('section', { class: 'card hidden' });
  const netCard = h('section', { class: 'card' });
  const formCard = h('section', { class: 'card hidden' });   // 載入行程（確定是否唯讀）後才顯示
  const listCard = h('section', { class: 'card' });
  const gate = h('div', { class: 'gate hidden' });
  const foot = h('div');

  const lost = lostOverlay({
    onRetry: () => { fails = 0; setStatus(''); sync(); },   // 用網址內的 token 直接重試，毋須重新選人
    who: () => st ? T.lostWho(st.trip.name, st.trip.mode) : '',
  });
  const showErr = m => { errB.firstChild.textContent = m; errB.classList.remove('hidden'); };
  const hideErr = () => errB.classList.add('hidden');
  const showWarn = m => { warnB.firstChild.textContent = m; warnB.classList.remove('hidden'); };
  const setStatus = (kind) => {
    status.className = 'status ' + kind;
    status.textContent = { synced: T.stSynced, saving: T.stSaving, offline: T.stOffline, '': T.stConnecting }[kind];
  };

  fill(root, h('header', null, title, status), sub, errB, warnB, roB, sharePanel, settingsPanel, netCard, formCard, listCard, foot, gate);

  // ---------------------------------------------------------------- 同步
  function schedule() {
    clearTimeout(timer);
    if (document.hidden) return;   // 背景時暫停，回到前景立即同步
    // 前 3 次失敗維持 10 秒（約 30 秒內彈出連線中斷提示），之後指數退避，上限 60 秒
    const delay = fails < 3 ? POLL_MS : Math.min(MAX_BACKOFF_MS, POLL_MS * 2 ** (fails - 2));
    timer = setTimeout(sync, delay);
  }

  async function sync() {
    if (inflight) return schedule();
    inflight = true;
    try {
      const r = await call('GET', 'trip', { token, etag: st ? etag : null });
      if (!r.notModified) apply(r.data, r.etag);
      if (fails) hideErr();   // 之前的連線錯誤橫額已過時
      fails = 0;
      lost.ok();
      setStatus('synced');
    } catch (e) {
      if (e instanceof ApiErr && e.app && e.code === 'not_found') { notFound(); inflight = false; return; }
      fails++;
      setStatus('offline');
      if (!st) showErr(e.message);
      // 首次載入失敗即彈出（否則沒有任何重試）；輪詢中連續 3 次才彈出
      lost.fail(e, { immediate: !st });
    } finally {
      inflight = false;
    }
    schedule();
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) clearTimeout(timer);
    else sync();
  });
  window.addEventListener('offline', () => { setStatus('offline'); lost.offline(); });
  window.addEventListener('online', () => { fails = 0; sync(); });

  async function write(method, path, body, opts = {}) {
    setStatus('saving');
    try {
      const r = await call(method, path, { token, body, ...opts });
      apply(r.data, r.etag);
      fails = 0;
      lost.ok();
      setStatus('synced');
      hideErr();
      return r.data;
    } catch (e) {
      setStatus(e.isLink ? 'offline' : 'synced');
      if (e.app && e.status === 409 && e.code === 'version_conflict') throw e;
      if (e.app && e.status === 404 && e.code === 'not_found') { notFound(); throw e; }
      showErr(e.message);
      lost.fail(e, { immediate: true });   // 儲存失敗＝資料未存，立即提示
      throw e;
    }
  }

  function notFound() {
    clearTimeout(timer);
    lost.reset();
    fill(root, h('header', null, h('h1', { text: T.appName })),
      h('div', { class: 'banner err' }, h('span', { text: T.notFound })),
      h('a', { class: 'btn', href: new URL('./', document.baseURI).href, text: T.newTrip }), footer());
  }

  // ---------------------------------------------------------------- 套用
  function apply(data, newEtag) {
    const first = !st;
    st = data;
    etag = newEtag || etag;
    if (first) {
      document.title = `${st.trip.name} · ${T.appName}`;
      const saved = Number(lsGet(meKey()));
      if (saved && st.members.some(m => m.id === saved)) me = saved;
    }
    if (editing) {
      const cur = st.expenses.find(e => e.id === editing.id);
      if (!cur) { showWarn(T.editingGone); resetForm(); }
    }
    if (payer && !st.members.some(m => m.id === payer)) payer = null;
    renderHeader();
    renderNet();
    renderForm(first);
    renderList();
    if (!settingsPanel.classList.contains('hidden')) renderSettings();
    if (!ro() && (!me || !st.members.some(m => m.id === me && !m.archived))) openGate();
  }

  // ---------------------------------------------------------------- 頁頂
  function renderHeader() {
    title.textContent = st.trip.name;
    roB.classList.toggle('hidden', !ro());
    const m = memberMap().get(me);
    fill(sub, 
      h('span', null, T.base + ' ', h('strong', { text: st.trip.base_currency })),
      h('span', null, T.total + ' ', h('strong', { class: 'num', text: money(st.total_minor) })),
      ro() ? null : h('span', { class: 'me' }, T.youAre + ' ', h('strong', { text: m ? m.display_name : '—' }), iconBtn('switch', T.switchMe, openGate)),
      h('span', { class: 'tools' },
        iconBtn('share', T.share, toggleShare, { pressed: !sharePanel.classList.contains('hidden') }),
        ro() ? null : iconBtn('settings', settingsPanel.classList.contains('hidden') ? T.settings : T.closeSettings, toggleSettings, { pressed: !settingsPanel.classList.contains('hidden') })),
    );
    const upd = new Date(st.trip.last_active_at);
    fill(foot, footer(h('span', null, `${T.updated} ${isNaN(upd) ? st.trip.last_active_at : upd.toLocaleString('zh-HK', { hour12: false })} · ${T.version} ${st.trip.version} · `)));
  }

  // ---------------------------------------------------------------- 你是哪一位
  function openGate() {
    if (ro()) return;
    fill(gate, h('div', { class: 'card' },
      h('h2', { text: T.pickTitle }),
      h('p', { class: 'hint', text: T.pickLead }),
      h('div', { class: 'chips' }, active().map(m => h('button', {
        type: 'button', class: 'chip' + (m.id === me ? ' on' : ''), text: m.display_name,
        onclick: () => { me = m.id; lsSet(meKey(), String(m.id)); gate.classList.add('hidden'); if (!editing) resetForm(); renderHeader(); },
      }))),
    ));
    gate.classList.remove('hidden');
  }

  // ---------------------------------------------------------------- 分享
  function toggleShare() {
    if (!sharePanel.classList.contains('hidden')) { closeShare(); return; }
    const known = lsJson(LS_LINKS(st.trip.id)) || {};
    const here = tripUrl(token);
    const editUrl = ro() ? null : here;
    const viewUrl = ro() ? here : (known.view || null);
    fill(sharePanel, 
      h('div', { class: 'cardhead' }, h('h2', { text: T.shareTitle }), h('button', { type: 'button', class: 'btn ghost sm', text: T.close, onclick: closeShare })),
      linksBlock(editUrl, viewUrl),
    );
    sharePanel.classList.remove('hidden');
    renderHeader();
    sharePanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function closeShare() { sharePanel.classList.add('hidden'); renderHeader(); }

  // ---------------------------------------------------------------- 淨額
  function renderNet() {
    const mm = memberMap();
    const rows = st.balances.filter(b => b.paid_minor || b.owed_minor || b.settled_minor || !mm.get(b.member_id).archived);
    fill(netCard, 
      h('div', { class: 'cardhead' }, h('h2', { text: T.netTitle }), h('span', { class: 'muted', text: T.netHint })),
      !st.expenses.length ? h('p', { class: 'empty', text: T.noExpenses }) :
        rows.map(b => {
          const m = mm.get(b.member_id);
          const cls = b.net_minor > 0 ? 'pos' : b.net_minor < 0 ? 'neg' : 'zero';
          return h('div', { class: 'person' + (m.archived ? ' archived' : '') },
            h('div', null, h('div', { class: 'name', text: m.display_name + (m.archived ? T.archivedTag : '') }),
              h('div', { class: 'detail num', text: T.netDetail(money(b.paid_minor), money(b.owed_minor)) })),
            h('div', { class: 'net num ' + cls, text: money(b.net_minor, { sign: true }) }));
        }),
      h('h3', { text: T.movesTitle }),
      !st.suggestions.length ? h('p', { class: 'empty', text: st.expenses.length ? T.noMoves : '—' }) :
        st.suggestions.map(s => h('div', { class: 'move' },
          h('span', { text: T.pays(mm.get(s.from_id).display_name, mm.get(s.to_id).display_name) }),
          h('strong', { class: 'num', text: money(s.amount_minor) + ' ' + st.trip.base_currency }))),
      copyButton(T.copySettlement, () => settlementText(false), 'btn ghost full mt'),
    );
  }

  function copyButton(label, textFn, cls) {
    const b = h('button', { type: 'button', class: cls, text: label });
    b.addEventListener('click', async () => {
      const ok = await copyText(textFn());
      b.textContent = ok ? T.copied : T.copyFail;
      setTimeout(() => { b.textContent = label; }, 1800);
    });
    return b;
  }

  // ---------------------------------------------------------------- 匯出
  function expenseLine(e, mm) {
    const B = st.trip.base_currency;
    const sh = e.shares.map(s => mm.get(s.member_id).display_name).join('、');
    const conv = e.currency === B ? '' : `（${T.ownRate(e.currency, e.rate, B)}，= ${money(e.base_minor)} ${B}）`;
    return `${e.spent_on}　${e.description}　${fmtMinor(e.amount_minor, dpOf(e.currency))} ${e.currency}${conv}　${(e.amount_minor < 0 ? T.heldBy : T.paidBy)(mm.get(e.payer_id).display_name)}　${T.splitAmong(e.shares.length)}${sh}`;
  }

  function settlementText(withRecords) {
    const mm = memberMap();
    const B = st.trip.base_currency;
    const L = [T.txtHeader(st.trip.name, B), T.txtTotal(money(st.total_minor), st.expenses.length), '', T.txtNet];
    for (const b of st.balances) {
      if (!b.paid_minor && !b.owed_minor && !b.settled_minor && mm.get(b.member_id).archived) continue;
      L.push(T.txtNetLine(mm.get(b.member_id).display_name, money(b.net_minor, { sign: true }), money(b.paid_minor), money(b.owed_minor)));
    }
    L.push('', T.txtMoves);
    if (!st.suggestions.length) L.push(T.noMoves);
    for (const s of st.suggestions) L.push(`${T.pays(mm.get(s.from_id).display_name, mm.get(s.to_id).display_name)} ${money(s.amount_minor)} ${B}`);
    if (withRecords) {
      L.push('', T.txtRecords);
      const list = [...st.expenses].sort((a, b) => a.spent_on < b.spent_on ? -1 : a.spent_on > b.spent_on ? 1 : a.id - b.id);
      for (const e of list) L.push(expenseLine(e, mm));
    }
    return L.join('\n');
  }

  function csvCell(v) {
    v = String(v ?? '');
    return /[",\r\n]/.test(v) || (/^[=+\-@]/.test(v) && isNaN(Number(v))) ? '"' + v.replace(/"/g, '""') + '"' : v;
  }

  function exportCsv() {
    const mm = memberMap();
    const B = st.trip.base_currency;
    const plain = v => fmtMinor(v, baseDp(), { plain: true });
    const rows = [['日期', '項目', '金額', '貨幣', '匯率', '折合 ' + B, '付款人', '分攤人', '分攤人數', ...st.members.map(m => m.display_name + ' 分攤 ' + B), '輸入人']];
    const list = [...st.expenses].sort((a, b) => a.spent_on < b.spent_on ? -1 : a.spent_on > b.spent_on ? 1 : a.id - b.id);
    for (const e of list) {
      const by = new Map(e.shares.map(s => [s.member_id, s.share_minor]));
      rows.push([e.spent_on, e.description, fmtMinor(e.amount_minor, dpOf(e.currency), { plain: true }), e.currency, e.rate, plain(e.base_minor),
        mm.get(e.payer_id).display_name, e.shares.map(s => mm.get(s.member_id).display_name).join('、'), e.shares.length,
        ...st.members.map(m => by.has(m.id) ? plain(by.get(m.id)) : ''), e.created_by ? (mm.get(e.created_by)?.display_name || '') : '']);
    }
    rows.push([], ['每人淨額（正數應收，負數應付）', '', '已墊支 ' + B, '應付 ' + B, '淨額 ' + B]);
    for (const b of st.balances) rows.push(['', mm.get(b.member_id).display_name, plain(b.paid_minor), plain(b.owed_minor), plain(b.net_minor)]);
    rows.push([], ['過數建議']);
    if (!st.suggestions.length) rows.push(['', T.noMoves]);
    for (const s of st.suggestions) rows.push(['', T.pays(mm.get(s.from_id).display_name, mm.get(s.to_id).display_name), plain(s.amount_minor), B]);
    rows.push([], ['總支出 ' + B, plain(st.total_minor), '筆數', st.expenses.length, '版本', st.trip.version]);
    download(`split-exp-${stamp()}.csv`, '﻿' + rows.map(r => r.map(csvCell).join(',')).join('\r\n'), 'text/csv;charset=utf-8');
  }

  const stamp = () => { const d = new Date(); return todayStr().replace(/-/g, '') + '-' + String(d.getHours()).padStart(2, '0') + String(d.getMinutes()).padStart(2, '0'); };

  // ---------------------------------------------------------------- 表單
  const f = {
    title: h('h2', { text: T.formAdd }),
    desc: h('input', { type: 'text', id: 'fDesc', maxlength: '100', placeholder: T.fDescPh }),
    amount: h('input', { type: 'text', id: 'fAmount', inputmode: 'decimal', class: 'num', placeholder: T.fAmountPh, autocomplete: 'off' }),
    cur: h('select', { id: 'fCur' }),
    rateWrap: h('div', { class: 'hidden' }),
    rateLbl: h('label', { class: 'lbl', for: 'fRate' }),
    rate: h('input', { type: 'text', id: 'fRate', inputmode: 'decimal', class: 'num', autocomplete: 'off' }),
    rateHint: h('p', { class: 'hint' }),
    fxBtn: h('button', { type: 'button', class: 'chip mini', text: T.fxBtn }),
    date: h('input', { type: 'date', id: 'fDate' }),
    payers: h('div', { class: 'chips' }),
    sharersEl: h('div', { class: 'chips' }),
    preview: h('div', { class: 'preview num' }),
    submit: h('button', { type: 'submit', class: 'btn', text: T.submitAdd }),
    cancel: h('button', { type: 'button', class: 'btn ghost hidden', text: T.cancelEdit }),
  };
  f.rateWrap.append(f.rateLbl, h('div', { class: 'row' }, f.rate, h('div', { class: 'fix' }, f.fxBtn)), f.rateHint);
  const form = h('form', { autocomplete: 'off' },
    h('label', { class: 'lbl', for: 'fDesc', text: T.fDesc }), f.desc,
    h('div', { class: 'row' },
      h('div', null, h('label', { class: 'lbl', for: 'fAmount', text: T.fAmount }), f.amount),
      h('div', { class: 'fix w120' }, h('label', { class: 'lbl', for: 'fCur', text: T.fCurrency }), f.cur)),
    f.rateWrap,
    h('p', { class: 'hint', text: T.amountHint }),
    h('label', { class: 'lbl', for: 'fDate', text: T.fDate }), f.date,
    h('span', { class: 'lbl', text: T.fPayer }), f.payers,
    h('span', { class: 'lbl' }, T.fSharers + ' ',
      h('button', { type: 'button', class: 'chip mini', text: T.all, onclick: () => { sharers = new Set(selectable().map(m => m.id)); renderChips(); updatePreview(); } }), ' ',
      h('button', { type: 'button', class: 'chip mini', text: T.none, onclick: () => { sharers = new Set(); renderChips(); updatePreview(); } })),
    f.sharersEl, f.preview,
    h('div', { class: 'row mt' }, f.submit, f.cancel),
  );
  formCard.append(f.title, form);
  f.amount.addEventListener('input', updatePreview);
  f.rate.addEventListener('input', () => { rateDirty = true; updatePreview(); });
  f.cur.addEventListener('change', () => { setRateField(f.cur.value, null); updatePreview(); if (!editing) applyTodayRate(f.cur.value, false); });
  f.fxBtn.addEventListener('click', () => applyTodayRate(f.cur.value, true));

  // ---------------------------------------------------------------- 今日匯率（伺服器向 Google Finance 取得並快取）
  const fxMem = new Map();   // currency → {rate, quoted_at, at}
  let rateDirty = false;
  const fxTime = iso => { const d = new Date(iso); return isNaN(d) ? iso : d.toLocaleString('zh-HK', { hour12: false, month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit' }); };
  async function fxToday(curs) {
    const need = curs.filter(c => { const m = fxMem.get(c); return !m || Date.now() - m.at > 10 * 60 * 1000; });
    if (need.length) {
      try {
        const r = await call('GET', 'fx?c=' + need.map(encodeURIComponent).join(','), { token });
        for (const [c, v] of Object.entries(r.data.rates || {})) fxMem.set(c, { ...v, at: Date.now() });
      } catch {}
    }
    const out = {};
    for (const c of curs) if (fxMem.has(c)) out[c] = fxMem.get(c);
    return out;
  }
  /** 新增使費選擇外幣時自動帶入；force = 用家按了按鈕（修改舊使費時亦可） */
  async function applyTodayRate(cur, force) {
    if (!st || cur === st.trip.base_currency) return;
    if (!force && rateDirty) return;
    f.rateHint.textContent = T.fxFetching;
    const got = (await fxToday([cur]))[cur];
    if (f.cur.value !== cur) return;          // 等待期間已改選其他貨幣
    if (!force && rateDirty) { f.rateHint.textContent = T.rateHint; return; }
    if (got) {
      f.rate.value = got.rate;
      rateDirty = false;
      f.rateHint.textContent = T.fxHint(fxTime(got.quoted_at));
    } else {
      f.rateHint.textContent = T.fxFail;
    }
    updatePreview();
  }
  f.cancel.addEventListener('click', () => { hideErr(); resetForm(); });

  /** 可選成員：在職成員；修改舊使費時另加該筆原有的封存成員 */
  function selectable() {
    const keep = new Set();
    if (editing) { keep.add(editing.payer_id); editing.shares.forEach(s => keep.add(s.member_id)); }
    return st.members.filter(m => !m.archived || keep.has(m.id));
  }
  const tripRate = cur => st.rates.find(r => r.currency === cur);

  function setRateField(cur, explicit) {
    const B = st.trip.base_currency;
    if (cur === B) { f.rateWrap.classList.add('hidden'); f.rate.value = ''; return; }
    f.rateWrap.classList.remove('hidden');
    f.rateLbl.textContent = T.fRate(cur, B);
    const tr = tripRate(cur);
    f.rate.value = explicit ?? (tr ? tr.rate : '');
    rateDirty = false;
    f.rateHint.textContent = explicit !== null || tr ? T.rateHint : T.rateMissing(cur);
  }

  function renderForm(first) {
    formCard.classList.toggle('hidden', ro());
    if (ro()) return;
    const prev = f.cur.value;
    const curs = [st.trip.base_currency, ...st.rates.map(r => r.currency).filter(c => c !== st.trip.base_currency)];
    const others = Object.keys(st.currencies).filter(c => !curs.includes(c));
    fill(f.cur, ...curs.map(c => h('option', { value: c, text: c })), ...(others.length ? [h('optgroup', { label: '—' }, others.map(c => h('option', { value: c, text: c })))] : []));
    if (first) { resetForm(); return; }
    f.cur.value = [...curs, ...others].includes(prev) ? prev : st.trip.base_currency;
    renderChips();
    updatePreview();
  }

  function resetForm() {
    editing = null;
    f.title.textContent = T.formAdd; f.submit.textContent = T.submitAdd; f.cancel.classList.add('hidden');
    f.desc.value = ''; f.amount.value = ''; f.date.value = todayStr();
    f.cur.value = st.trip.base_currency;
    setRateField(st.trip.base_currency, null);
    const act = active();
    payer = act.some(m => m.id === me) ? me : (act[0]?.id ?? null);
    sharers = new Set(act.map(m => m.id));
    renderChips(); updatePreview();
  }

  function startEdit(e) {
    editing = e;
    f.title.textContent = T.formEdit; f.submit.textContent = T.submitEdit; f.cancel.classList.remove('hidden');
    f.desc.value = e.description === T.fDescPh ? '' : e.description;
    f.amount.value = fmtMinor(e.amount_minor, dpOf(e.currency), { plain: true });
    f.date.value = e.spent_on;
    if (![...f.cur.options].some(o => o.value === e.currency)) f.cur.append(h('option', { value: e.currency, text: e.currency }));
    f.cur.value = e.currency;
    setRateField(e.currency, e.currency === st.trip.base_currency ? null : e.rate);
    payer = e.payer_id;
    sharers = new Set(e.shares.map(s => s.member_id));
    renderChips(); updatePreview();
    formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderChips() {
    const list = selectable();
    fill(f.payers, list.map(m => h('button', {
      type: 'button', class: 'chip' + (payer === m.id ? ' on' : '') + (m.archived ? ' archived' : ''), text: m.display_name, 'aria-pressed': String(payer === m.id),
      onclick: () => { payer = m.id; renderChips(); updatePreview(); },
    })));
    fill(f.sharersEl, list.map(m => h('button', {
      type: 'button', class: 'chip' + (sharers.has(m.id) ? ' on' : '') + (m.archived ? ' archived' : ''), text: m.display_name, 'aria-pressed': String(sharers.has(m.id)),
      onclick: () => { sharers.has(m.id) ? sharers.delete(m.id) : sharers.add(m.id); renderChips(); updatePreview(); },
    })));
  }

  /** 預覽只供參考；實際金額由伺服器計算 */
  function updatePreview() {
    const B = st.trip.base_currency;
    const cur = f.cur.value;
    const n = sharers.size;
    if (!f.amount.value.trim()) { f.preview.textContent = T.previewNeedAmount; return; }
    const amt = parseMinor(f.amount.value, dpOf(cur));
    if (amt === null || amt === 0n) { f.preview.textContent = T.previewBad; return; }
    if (!n) { f.preview.textContent = T.previewNeedSharers; return; }
    let base;
    if (cur === B) base = Number(amt);
    else {
      const r = parseMinor(f.rate.value, 8);
      if (r === null || r <= 0n) { f.preview.textContent = T.rateMissing(cur); return; }
      base = previewConvert(amt, r, dpOf(cur), baseDp());
    }
    const per = Math.trunc(base / n);
    const tot = cur !== B ? money(Math.abs(base)) : '';
    f.preview.textContent = base < 0 ? T.previewWin(n, money(-per), B, tot) : T.previewSplit(n, money(per), B, tot);
  }

  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    if (!payer) { showErr(T.needPayer); return; }
    if (!sharers.size) { showErr(T.needSharers); return; }
    const order = new Map(st.members.map(m => [m.id, m.sort_order]));
    const body = {
      description: f.desc.value.trim(),
      amount: f.amount.value.trim(),
      currency: f.cur.value,
      spent_on: f.date.value || todayStr(),
      payer_id: payer,
      sharers: [...sharers].sort((a, b) => order.get(a) - order.get(b)),
      split_mode: 'equal',
    };
    if (f.cur.value !== st.trip.base_currency) body.rate = f.rate.value.trim();
    f.submit.disabled = true;
    try {
      if (editing) {
        await write('PATCH', 'expenses/' + editing.id, body, { ifMatch: editing.version });
      } else {
        await write('POST', 'expenses', { ...body, created_by: me });
      }
      warnB.classList.add('hidden');
      resetForm();
    } catch (e) {
      if (e instanceof ApiErr && e.status === 409 && e.body.current) {
        showWarn(T.conflict);
        etag = null;
        await sync();
        startEdit(e.body.current);
      }
    } finally {
      f.submit.disabled = false;
    }
  });

  // ---------------------------------------------------------------- 紀錄
  const textBox = h('pre', { class: 'plaintext hidden' });
  function renderList() {
    const mm = memberMap();
    const B = st.trip.base_currency;
    const showBtn = h('button', { type: 'button', class: 'btn ghost', text: textShown ? T.hideText : T.showText });
    showBtn.addEventListener('click', () => {
      textShown = !textShown;
      textBox.textContent = settlementText(true);
      textBox.classList.toggle('hidden', !textShown);
      showBtn.textContent = textShown ? T.hideText : T.showText;
    });
    if (textShown) textBox.textContent = settlementText(true);
    const nameEl = id => { const m = mm.get(id); return h('span', { class: m.archived ? 'archived' : null, text: m.display_name + (m.archived ? T.archivedTag : '') }); };
    fill(listCard, 
      h('div', { class: 'cardhead' }, h('h2', { text: T.listTitle }), h('span', { class: 'muted', text: st.expenses.length ? T.count(st.expenses.length) : '' })),
      !st.expenses.length ? h('p', { class: 'empty', text: T.noExpenses }) : st.expenses.map(e => {
        const win = e.amount_minor < 0;
        const shareNames = [];
        e.shares.forEach((s, i) => { if (i) shareNames.push('、'); shareNames.push(nameEl(s.member_id)); });
        const meta = h('div', { class: 'meta num' },
          h('span', { class: 'base', text: e.currency === B ? `${e.spent_on}` : `= ${money(e.base_minor)} ${B}（${T.ownRate(e.currency, e.rate, B)}）· ${e.spent_on}` }),
          ' · ', (win ? T.heldByParts : T.paidByParts)[0], nameEl(e.payer_id), (win ? T.heldByParts : T.paidByParts)[1],
          ' · ', T.splitAmong(e.shares.length), ...shareNames,
          e.created_by && mm.get(e.created_by) ? h('span', null, ' · ', T.enteredBy(mm.get(e.created_by).display_name)) : null);
        const acts = ro() ? null : h('div', { class: 'acts' },
          h('button', { type: 'button', class: 'btn ghost sm', text: T.edit, onclick: () => { hideErr(); startEdit(e); } }),
          h('button', { type: 'button', class: 'btn danger sm', text: T.del, onclick: async ev => {
            if (!confirm(T.confirmDel(e.description, `${fmtMinor(e.amount_minor, dpOf(e.currency))} ${e.currency}`))) return;
            ev.target.disabled = true;
            try {
              await write('DELETE', 'expenses/' + e.id, undefined, { ifMatch: e.version });
              if (editing && editing.id === e.id) resetForm();
            } catch (err) {
              ev.target.disabled = false;
              if (err instanceof ApiErr && err.status === 409) { showWarn(err.message); etag = null; sync(); }
            }
          } }));
        return h('div', { class: 'exp' },
          h('div', { class: 'top' }, h('div', { class: 'desc', text: e.description }), h('div', { class: 'amt num', text: `${fmtMinor(e.amount_minor, dpOf(e.currency))} ${e.currency}` })),
          meta, acts);
      }),
      h('div', { class: 'row wrap mt' },
        copyButton(T.copyAll, () => settlementText(true), 'btn ghost'),
        h('button', { type: 'button', class: 'btn ghost', text: T.downloadTxt, onclick: () => download(`split-exp-${stamp()}.txt`, '﻿' + settlementText(true).replace(/\n/g, '\r\n'), 'text/plain;charset=utf-8') }),
        showBtn,
        h('button', { type: 'button', class: 'btn ghost', text: T.exportCsv, onclick: exportCsv })),
      textBox,
    );
  }

  // ---------------------------------------------------------------- 設定
  function toggleSettings() {
    const hidden = settingsPanel.classList.toggle('hidden');
    if (!hidden) { renderSettings(); settingsPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    renderHeader();
  }

  function renderSettings() {
    const B = st.trip.base_currency;
    const locked = st.expenses.length > 0;
    const tName = h('input', { type: 'text', maxlength: '60', value: st.trip.name });
    const tBase = h('select', { disabled: locked }, Object.keys(st.currencies).map(c => h('option', { value: c, text: c })));
    tBase.value = B;
    const saveTrip = h('button', { type: 'button', class: 'btn sm', text: T.save, onclick: async () => {
      const body = { name: tName.value.trim() };
      if (!locked && tBase.value !== B) body.base_currency = tBase.value;
      try { await write('PATCH', 'trip', body); } catch {}
    } });

    // 匯率草稿
    const draft = st.rates.map(r => ({ currency: r.currency, rate: r.rate }));
    const rateRows = h('div');
    const addSel = h('select');
    const renderRates = () => {
      fill(rateRows, draft.map((r, i) => {
        const inp = h('input', { type: 'text', inputmode: 'decimal', class: 'num', value: r.rate });
        inp.addEventListener('input', () => { draft[i].rate = inp.value; });
        return h('div', { class: 'settings-row' }, h('span', { class: 'cur', text: r.currency }), inp,
          h('button', { type: 'button', class: 'btn ghost sm', text: T.remove, onclick: () => { draft.splice(i, 1); renderRates(); } }));
      }));
      const used = new Set([B, ...draft.map(r => r.currency)]);
      fill(addSel, Object.keys(st.currencies).filter(c => !used.has(c)).map(c => h('option', { value: c, text: c })));
    };
    renderRates();
    const fxNote = h('p', { class: 'hint' });
    const fxFillBtn = h('button', { type: 'button', class: 'btn ghost sm', text: T.fxFill, onclick: async () => {
      const curs = draft.map(r => r.currency).filter(c => c !== B);
      if (!curs.length) return;
      fxFillBtn.disabled = true; fxNote.textContent = T.fxFetching;
      const got = await fxToday(curs);
      fxFillBtn.disabled = false;
      let n = 0, t = '';
      for (const r of draft) if (got[r.currency]) { r.rate = got[r.currency].rate; n++; t = fxTime(got[r.currency].quoted_at); }
      renderRates();
      const miss = curs.filter(c => !got[c]);
      fxNote.textContent = (n ? T.fxFilled(n, t) : '') + (miss.length ? (n ? ' ' : '') + T.fxSome(miss.join('、')) : '');
    } });
    const saveRates = h('button', { type: 'button', class: 'btn sm', text: T.saveRates, onclick: async () => {
      const rates = {};
      for (const r of draft) rates[r.currency] = String(r.rate).trim();
      try { await write('PUT', 'trip/rates', { rates }); } catch {}
    } });

    // 成員
    const mRows = st.members.map((m, i) => {
      const inp = h('input', { type: 'text', maxlength: '30', value: m.display_name });
      const swap = async (j) => {
        const o = st.members[j];
        if (!o) return;
        try {
          await write('PATCH', 'members/' + m.id, { sort_order: o.sort_order });
          await write('PATCH', 'members/' + o.id, { sort_order: m.sort_order });
        } catch {}
      };
      return h('div', { class: 'settings-row' + (m.archived ? ' archived' : '') }, inp,
        h('button', { type: 'button', class: 'btn ghost sm', text: T.save, onclick: async () => { try { await write('PATCH', 'members/' + m.id, { display_name: inp.value.trim() }); } catch {} } }),
        h('button', { type: 'button', class: 'btn ghost sm', 'aria-label': T.up, text: '↑', disabled: i === 0, onclick: () => swap(i - 1) }),
        h('button', { type: 'button', class: 'btn ghost sm', 'aria-label': T.down, text: '↓', disabled: i === st.members.length - 1, onclick: () => swap(i + 1) }),
        h('button', { type: 'button', class: 'btn ghost sm', text: m.archived ? T.unarchive : T.archive, onclick: async () => { try { await write('PATCH', 'members/' + m.id, { archived: !m.archived }); } catch {} } }));
    });
    const newM = h('input', { type: 'text', maxlength: '30', placeholder: T.addMember });
    const addM = async () => { const v = newM.value.trim(); if (!v) return; try { await write('POST', 'members', { display_name: v }); } catch {} };

    fill(settingsPanel, 
      h('div', { class: 'cardhead' }, h('h2', { text: T.settings }), h('button', { type: 'button', class: 'btn ghost sm', text: T.closeSettings, onclick: toggleSettings })),
      h('h3', { text: T.setTrip }),
      h('label', { class: 'lbl', text: T.fTripName }), tName,
      h('label', { class: 'lbl', text: T.base }), tBase,
      locked ? h('p', { class: 'hint', text: T.baseLocked }) : null,
      h('div', { class: 'mt' }, saveTrip),
      h('h3', { text: T.setRates(B) }),
      h('p', { class: 'hint', text: T.rateNote }),
      rateRows,
      h('div', { class: 'row mt' }, addSel, h('button', { type: 'button', class: 'btn ghost fix', text: T.addCurrency, onclick: () => { if (addSel.value) { draft.push({ currency: addSel.value, rate: '' }); renderRates(); } } })),
      h('div', { class: 'row wrap mt' }, saveRates, fxFillBtn),
      fxNote,
      h('h3', { text: T.setMembers }),
      h('p', { class: 'hint', text: T.archiveNote }),
      mRows,
      h('div', { class: 'row mt' }, newM, h('button', { type: 'button', class: 'btn ghost fix', text: T.add, onclick: addM })),
    );
  }

  setStatus('');
  sync();
}
