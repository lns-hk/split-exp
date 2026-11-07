import qrcode from 'qrcode-generator';

const SVGNS = 'http://www.w3.org/2000/svg';

/** 在本機產生 QR code，回傳 SVG 元素（不經任何外部服務） */
export function qrSvg(text, size = 184) {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  const n = qr.getModuleCount();
  const pad = 3;
  const dim = n + pad * 2;
  const svg = document.createElementNS(SVGNS, 'svg');
  svg.setAttribute('viewBox', `0 0 ${dim} ${dim}`);
  svg.setAttribute('width', String(size));
  svg.setAttribute('height', String(size));
  svg.setAttribute('role', 'img');
  svg.setAttribute('shape-rendering', 'crispEdges');
  svg.setAttribute('class', 'qr');
  const bg = document.createElementNS(SVGNS, 'rect');
  bg.setAttribute('width', String(dim)); bg.setAttribute('height', String(dim)); bg.setAttribute('fill', '#fff');
  svg.append(bg);
  let d = '';
  for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) d += `M${c + pad} ${r + pad}h1v1h-1z`;
  const p = document.createElementNS(SVGNS, 'path');
  p.setAttribute('d', d); p.setAttribute('fill', '#000');
  svg.append(p);
  return svg;
}
