// 後台頁面的小工具：本機產生 QR、複製、確認對話框（CSP 只允許 'self' 腳本，所以不寫 inline）
import { qrSvg } from './qr.js';

document.querySelectorAll('[data-qr]').forEach(el => el.append(qrSvg(el.dataset.qr, 200)));
document.querySelectorAll('[data-select]').forEach(el => el.addEventListener('focus', () => el.select()));
document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', async () => {
  const label = b.textContent;
  let ok = false;
  try { await navigator.clipboard.writeText(b.dataset.copy); ok = true; } catch {}
  b.textContent = ok ? '已複製' : '請手動複製';
  setTimeout(() => { b.textContent = label; }, 1800);
}));
document.querySelectorAll('[data-confirm]').forEach(b => b.addEventListener('click', e => {
  if (!confirm(b.dataset.confirm)) e.preventDefault();
}));
