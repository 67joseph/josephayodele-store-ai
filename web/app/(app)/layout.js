'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { BarChart3, Boxes, HandCoins, Home, Menu, MessageCircle, Receipt, ShoppingCart, Sparkles, LogOut, Plus, Settings, Store } from 'lucide-react';
import { api } from '@/lib/api';
import { CURRENCY_OPTIONS } from '@/lib/format';
import { useAuth } from '@/components/auth';
import { Button, cn, Field, Input, PageLoader, Select, Sheet, useToast } from '@/components/ui';

const NAV = [
  { href: '/', label: 'Home', icon: Home },
  { href: '/inventory/', label: 'Stock', icon: Boxes },
  { href: '/sales/', label: 'Sell', icon: ShoppingCart, primary: true },
  { href: '/debts/', label: 'Debts', icon: HandCoins },
  { href: '/reports/', label: 'Reports', icon: BarChart3 },
];
const EXTRA = [
  { href: '/expenses/', label: 'Expenses', icon: Receipt },
  { href: '/assistant/', label: 'Ask AI', icon: Sparkles },
  { href: '/whatsapp/', label: 'WhatsApp', icon: MessageCircle },
];

const active = (path, href) => (href === '/' ? path === '/' : path.startsWith(href.replace(/\/$/, '')));

function StoreSheet({ mode, open, onClose }) {
  const { store, applyStores, switchStore } = useAuth();
  const toast = useToast();
  const [types, setTypes] = useState([]);
  const [f, setF] = useState({ name: '', store_type_id: '', currency: 'NGN' });
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!open) return;
    api('/store-types').then((d) => setTypes(d.types)).catch(() => {});
    setF(mode === 'edit' && store
      ? { name: store.name, store_type_id: String(store.store_type_id), currency: store.currency }
      : { name: '', store_type_id: '', currency: store ? store.currency : 'NGN' });
  }, [open, mode, store]);

  async function save() {
    setBusy(true);
    try {
      const body = { ...f, store_type_id: Number(f.store_type_id) };
      if (mode === 'edit') {
        const d = await api('/stores/current', { method: 'PUT', body });
        applyStores(d.stores);
        toast('Store updated');
        onClose();
      } else {
        const d = await api('/stores', { method: 'POST', body });
        switchStore(d.id);
      }
    } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); }
  }

  return (
    <Sheet open={open} onClose={onClose} title={mode === 'edit' ? 'Store settings' : 'Add another store'}
      footer={<Button className="w-full" size="lg" loading={busy} onClick={save} disabled={!f.name || !f.store_type_id}>{mode === 'edit' ? 'Save changes' : 'Create store'}</Button>}>
      <div className="space-y-4 pb-2">
        <Field label="Store name"><Input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="Store type">
          <Select value={f.store_type_id} onChange={(e) => setF({ ...f, store_type_id: e.target.value })}>
            <option value="">Choose…</option>
            {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </Select>
        </Field>
        <Field label="Currency">
          <Select value={f.currency} onChange={(e) => setF({ ...f, currency: e.target.value })}>{CURRENCY_OPTIONS.map(([c, l]) => <option key={c} value={c}>{l}</option>)}</Select>
        </Field>
      </div>
    </Sheet>
  );
}

export default function AppLayout({ children }) {
  const { user, ready, store, stores, logout, switchStore } = useAuth();
  const path = usePathname();
  const router = useRouter();
  const [menu, setMenu] = useState(false);
  const [storeSheet, setStoreSheet] = useState(null);

  useEffect(() => { if (ready && !user) router.replace('/login/'); }, [ready, user, router]);
  if (!ready || !user || !store) return <PageLoader />;

  return (
    <div className="min-h-dvh lg:flex">
      {/* Desktop sidebar */}
      <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col border-r border-slate-100 bg-white p-4 lg:flex">
        <div className="mb-6 flex items-center gap-3 px-2">
          <div className="grid h-10 w-10 place-items-center rounded-xl bg-brand-600 font-bold text-white">S</div>
          <div className="min-w-0"><p className="truncate font-semibold leading-tight">{store.name}</p><p className="truncate text-xs text-slate-400">{store.type_name}</p></div>
        </div>
        <nav className="space-y-1">
          {[...NAV, ...EXTRA].map((n) => (
            <Link key={n.href} href={n.href} className={cn('flex items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-medium', active(path, n.href) ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50')}>
              <n.icon className="h-5 w-5" />{n.label}
            </Link>
          ))}
        </nav>
        <div className="mt-auto space-y-1">
          <button onClick={() => setStoreSheet('edit')} className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-medium text-slate-600 hover:bg-slate-50"><Settings className="h-5 w-5" />Store settings</button>
          <button onClick={logout} className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-[15px] font-medium text-slate-600 hover:bg-slate-50"><LogOut className="h-5 w-5" />Sign out</button>
        </div>
      </aside>

      <div className="min-w-0 flex-1">
        {/* Mobile top bar */}
        <header className="sticky top-0 z-30 flex items-center justify-between border-b border-slate-100 bg-white/90 px-4 py-2.5 backdrop-blur lg:hidden">
          <button onClick={() => setMenu(true)} className="flex min-w-0 items-center gap-2.5 text-left">
            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-600 text-sm font-bold text-white">{store.name.charAt(0).toUpperCase()}</span>
            <span className="min-w-0"><span className="block truncate text-[15px] font-semibold leading-tight">{store.name}</span><span className="block truncate text-xs text-slate-400">{store.type_name}</span></span>
          </button>
          <div className="flex items-center gap-1">
            <Link href="/assistant/" aria-label="Ask AI" className="grid h-10 w-10 place-items-center rounded-full text-brand-700 hover:bg-brand-50"><Sparkles className="h-5 w-5" /></Link>
            <button onClick={() => setMenu(true)} aria-label="Menu" className="grid h-10 w-10 place-items-center rounded-full text-slate-500 hover:bg-slate-100"><Menu className="h-5 w-5" /></button>
          </div>
        </header>

        <main className="mx-auto w-full max-w-3xl px-4 pb-28 pt-5 lg:max-w-4xl lg:px-8 lg:pb-12 lg:pt-8">{children}</main>
      </div>

      {/* Mobile bottom nav */}
      <nav className="fixed inset-x-0 bottom-0 z-40 border-t border-slate-100 bg-white/95 pb-safe backdrop-blur lg:hidden">
        <ul className="mx-auto grid max-w-md grid-cols-5">
          {NAV.map((n) => {
            const on = active(path, n.href);
            return (
              <li key={n.href}>
                <Link href={n.href} className={cn('flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium', on ? 'text-brand-700' : 'text-slate-400')}>
                  {n.primary ? (
                    <span className={cn('-mt-5 grid h-12 w-12 place-items-center rounded-full shadow-lg', on ? 'bg-brand-700' : 'bg-brand-600', 'text-white shadow-brand-600/30')}><n.icon className="h-5 w-5" /></span>
                  ) : (
                    <n.icon className="h-[22px] w-[22px]" />
                  )}
                  {n.label}
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>

      <Sheet open={menu} onClose={() => setMenu(false)} title={store.name}>
        <div className="space-y-1 pb-3">
          <p className="pb-2 text-sm text-slate-400">Signed in as {user.name}</p>
          {EXTRA.map((n) => (
            <Link key={n.href} href={n.href} onClick={() => setMenu(false)} className="flex items-center gap-3 rounded-xl px-3 py-3 font-medium text-slate-700 hover:bg-slate-50"><n.icon className="h-5 w-5 text-slate-400" />{n.label}</Link>
          ))}
          <button onClick={() => { setMenu(false); setStoreSheet('edit'); }} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 font-medium text-slate-700 hover:bg-slate-50"><Settings className="h-5 w-5 text-slate-400" />Store settings</button>
          {stores.length > 1 && (
            <div className="py-2">
              <p className="px-3 pb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Switch store</p>
              {stores.map((s) => (
                <button key={s.id} onClick={() => switchStore(s.id)} className={cn('flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left', s.id === store.id ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-700 hover:bg-slate-50')}><Store className="h-5 w-5" />{s.name}</button>
              ))}
            </div>
          )}
          <button onClick={() => { setMenu(false); setStoreSheet('new'); }} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 font-medium text-slate-700 hover:bg-slate-50"><Plus className="h-5 w-5 text-slate-400" />Add another store</button>
          <button onClick={logout} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 font-medium text-red-600 hover:bg-red-50"><LogOut className="h-5 w-5" />Sign out</button>
        </div>
      </Sheet>
      <StoreSheet mode={storeSheet || 'edit'} open={!!storeSheet} onClose={() => setStoreSheet(null)} />
    </div>
  );
}
