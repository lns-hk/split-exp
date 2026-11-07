import { T } from './strings.js';

const API = new URL('api/', document.baseURI).href;

/**
 * status 0 = 網絡不通；app = 回應是 API 自己的 JSON {error}（業務錯誤，例如驗證失敗）。
 * app 為 false 的非 2xx（例如 Apache 的 HTML 400 頁）屬於連線層錯誤。
 */
export class ApiErr extends Error {
  constructor(status, code, msg, body, app) { super(msg); this.status = status; this.code = code; this.body = body || {}; this.app = !!app; }
  get isNet() { return this.status === 0; }
  get isLink() { return !this.app; }
}

/** token 只放在 X-Trip-Token header，API 網址本身不含 token */
export async function call(method, path, { token, body, etag, ifMatch } = {}) {
  const headers = {};
  if (token) headers['X-Trip-Token'] = token;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (etag) { headers['If-None-Match'] = etag; headers['X-If-None-Match'] = etag; }   // 後者避過反向代理移除條件標頭
  if (ifMatch !== undefined) headers['If-Match'] = String(ifMatch);
  let res;
  try {
    res = await fetch(API + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), cache: 'no-store', credentials: 'omit', referrerPolicy: 'no-referrer' });
  } catch {
    throw new ApiErr(0, 'network', T.netError, null, false);
  }
  if (res.status === 304) return { notModified: true, etag };
  // 先讀文字再嘗試 JSON：非 JSON 的錯誤頁（例如 HTML 400）不會變成 "Unexpected token <"
  let text = '';
  try { text = await res.text(); } catch {}
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch {}
  if (!res.ok) {
    if (data && data.error) {
      const msg = res.status === 429 ? T.rateLimited(data.retry_after || res.headers.get('Retry-After') || 60) : (data.message || T.serverError(res.status));
      throw new ApiErr(res.status, data.error, msg, data, true);
    }
    throw new ApiErr(res.status, 'http', T.httpPlain(res.status), null, false);
  }
  if (!data) throw new ApiErr(res.status, 'bad_json', T.badJson, null, false);
  return { data, etag: res.headers.get('ETag') };
}
