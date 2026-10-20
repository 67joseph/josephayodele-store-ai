'use client';
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, BASE_PATH, session } from '@/lib/api';
import { makeMoney } from '@/lib/format';

const Ctx = createContext(null);
export const useAuth = () => useContext(Ctx);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [stores, setStores] = useState([]);
  const [storeId, setStoreId] = useState(null);
  const [ready, setReady] = useState(false);

  const applyStores = useCallback((list) => {
    setStores(list);
    const saved = Number(session.storeId());
    const pick = list.find((s) => s.id === saved) || list[0];
    if (pick) { session.setStoreId(pick.id); setStoreId(pick.id); }
  }, []);

  useEffect(() => {
    if (!session.token()) { setReady(true); return; }
    api('/auth/me')
      .then((d) => { setUser(d.user); applyStores(d.stores); })
      .catch(() => session.clear())
      .finally(() => setReady(true));
  }, [applyStores]);

  const enter = useCallback((d) => {
    session.setToken(d.token);
    session.setStoreId(null);
    setUser(d.user);
    applyStores(d.stores);
  }, [applyStores]);

  const logout = useCallback(() => {
    session.clear();
    window.location.href = BASE_PATH + '/login/';
  }, []);

  const switchStore = useCallback((id) => {
    session.setStoreId(id);
    window.location.href = BASE_PATH + '/';
  }, []);

  const store = stores.find((s) => s.id === storeId) || null;
  const money = useMemo(() => makeMoney(store ? store.currency : 'NGN'), [store]);

  const value = { user, stores, store, ready, money, enter, logout, switchStore, applyStores };
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}
