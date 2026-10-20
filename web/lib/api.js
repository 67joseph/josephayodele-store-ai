export const BASE_PATH = process.env.NEXT_PUBLIC_BASE_PATH || '';
const BASE = (process.env.NEXT_PUBLIC_API_URL || '/api').replace(/\/$/, '');

const ls = {
  get(k) { try { return localStorage.getItem(k); } catch { return null; } },
  set(k, v) { try { v == null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch {} },
};

export const session = {
  token: () => ls.get('sai_token'),
  setToken: (t) => ls.set('sai_token', t),
  storeId: () => ls.get('sai_store'),
  setStoreId: (id) => ls.set('sai_store', id == null ? null : String(id)),
  clear() { ls.set('sai_token', null); ls.set('sai_store', null); },
};

export const assetUrl = (p) => (p ? `${BASE}/${p}` : null);

export async function api(path, { method = 'GET', body, form } = {}) {
  const headers = {};
  const t = session.token();
  if (t) headers['X-Auth-Token'] = t;
  const sid = session.storeId();
  if (sid) headers['X-Store-Id'] = sid;

  let payload;
  if (form) payload = form;
  else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  let res;
  try {
    res = await fetch(BASE + path, { method, headers, body: payload });
  } catch {
    throw new Error('Cannot reach the server. Check your internet connection.');
  }
  let data = null;
  try { data = await res.json(); } catch {}
  if (!res.ok) {
    if (res.status === 401 && t && typeof window !== 'undefined') {
      session.clear();
      window.location.href = BASE_PATH + '/login/';
    }
    throw new Error((data && data.error) || `Request failed (${res.status})`);
  }
  return data;
}

export const qstr = (o) => {
  const p = new URLSearchParams();
  Object.entries(o).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && p.set(k, v));
  const s = p.toString();
  return s ? `?${s}` : '';
};
