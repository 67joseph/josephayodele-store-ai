'use client';
import { useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { Check, Minus, Plus, Search, ShoppingBag, ShoppingCart, Trash2 } from 'lucide-react';
import { api, qstr } from '@/lib/api';
import { daysAgo, dateTime, MODE_LABEL, monthStart, num, ymd } from '@/lib/format';
import { useAuth } from '@/components/auth';
import { Thumb } from '@/components/thumb';
import { Badge, Button, Card, cn, Empty, Field, Input, MoneyInput, PageHeader, PageLoader, Segmented, Sheet, useToast } from '@/components/ui';

const MODES = [
  { value: 'cash', label: 'Cash' },
  { value: 'card', label: 'Card' },
  { value: 'transfer', label: 'Transfer' },
  { value: 'credit', label: 'Credit' },
];

function NewSale({ onDone }) {
  const { money } = useAuth();
  const toast = useToast();
  const [products, setProducts] = useState(null);
  const [q, setQ] = useState('');
  const [cart, setCart] = useState([]); // {product, quantity, price}
  const [open, setOpen] = useState(false);
  const [mode, setMode] = useState('cash');
  const [part, setPart] = useState(false);
  const [paid, setPaid] = useState('');
  const [customer, setCustomer] = useState('');
  const [phone, setPhone] = useState('');
  const [due, setDue] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => api('/products').then((d) => setProducts(d.products)).catch((e) => toast(e.message, 'err')), [toast]);
  useEffect(() => { load(); }, [load]);

  const total = cart.reduce((s, l) => s + l.quantity * num(l.price), 0);
  const count = cart.reduce((s, l) => s + l.quantity, 0);
  const inCart = (id) => cart.find((l) => l.product.id === id);
  const paidNow = mode === 'credit' ? num(paid) : part ? num(paid) : total;
  const balance = Math.max(0, total - paidNow);

  const shown = useMemo(() => {
    const t = q.trim().toLowerCase();
    return (products || []).filter((p) => !t || p.name.toLowerCase().includes(t) || (p.sku || '').toLowerCase().includes(t));
  }, [products, q]);

  function add(p) {
    const line = inCart(p.id);
    if (line) return setQty(p.id, line.quantity + 1);
    if (p.quantity < 1) return toast(`${p.name} is out of stock`, 'err');
    setCart([...cart, { product: p, quantity: 1, price: String(num(p.sale_price)) }]);
  }
  function setQty(id, qty) {
    setCart((c) => c.flatMap((l) => {
      if (l.product.id !== id) return [l];
      if (qty < 1) return [];
      if (qty > l.product.quantity) { toast(`Only ${l.product.quantity} of ${l.product.name} in stock`, 'err'); return [{ ...l, quantity: l.product.quantity }]; }
      return [{ ...l, quantity: qty }];
    }));
  }
  const setPrice = (id, price) => setCart((c) => c.map((l) => (l.product.id === id ? { ...l, price } : l)));

  function reset() {
    setCart([]); setOpen(false); setMode('cash'); setPart(false); setPaid(''); setCustomer(''); setPhone(''); setDue('');
  }

  async function submit() {
    if (balance > 0 && !customer.trim()) return toast('Enter the customer’s name for the unpaid balance', 'err');
    setBusy(true);
    try {
      const body = {
        items: cart.map((l) => ({ product_id: l.product.id, quantity: l.quantity, unit_price: l.price === '' ? 0 : num(l.price) })),
        payment_mode: mode, customer_name: customer, customer_phone: phone, due_date: due,
      };
      if (mode === 'credit' || part) body.amount_paid = paidNow;
      const d = await api('/sales', { method: 'POST', body });
      toast(`Sale recorded · profit ${money(d.sale.profit)}`);
      reset();
      load();
      onDone && onDone();
    } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); }
  }

  if (!products) return <PageLoader />;

  return (
    <div className="pb-20">
      <div className="relative mb-3">
        <Search className="pointer-events-none absolute left-3 top-3 h-5 w-5 text-slate-400" />
        <Input className="pl-10" placeholder="Search products to sell" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      {products.length === 0 ? (
        <Card><Empty icon={ShoppingBag} title="Add products first" text="You need products in stock before you can record a sale." action={<Link href="/inventory/"><Button>Go to Stock</Button></Link>} /></Card>
      ) : shown.length === 0 ? (
        <Card><Empty title="No match" text="Try a different search." /></Card>
      ) : (
        <Card className="divide-y divide-slate-100">
          {shown.map((p) => {
            const line = inCart(p.id);
            const out = p.quantity < 1;
            return (
              <div key={p.id} className={cn('flex items-center gap-3 p-3', out && 'opacity-50')}>
                <button onClick={() => add(p)} disabled={out && !line} className="flex min-w-0 flex-1 items-center gap-3 text-left">
                  <Thumb p={p} size="h-11 w-11" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{p.name}</span>
                    <span className="block text-xs text-slate-400">{money(p.sale_price)} · {out ? 'Out of stock' : `${p.quantity} left`}</span>
                  </span>
                </button>
                {line ? (
                  <div className="flex items-center gap-1 rounded-xl bg-brand-50 p-1">
                    <button onClick={() => setQty(p.id, line.quantity - 1)} className="grid h-8 w-8 place-items-center rounded-lg text-brand-700 active:bg-brand-100" aria-label="Less"><Minus className="h-4 w-4" /></button>
                    <span className="w-6 text-center text-sm font-semibold tabular-nums text-brand-800">{line.quantity}</span>
                    <button onClick={() => setQty(p.id, line.quantity + 1)} className="grid h-8 w-8 place-items-center rounded-lg text-brand-700 active:bg-brand-100" aria-label="More"><Plus className="h-4 w-4" /></button>
                  </div>
                ) : (
                  <button onClick={() => add(p)} disabled={out} className="grid h-10 w-10 place-items-center rounded-xl bg-slate-100 text-slate-600 active:bg-brand-100 active:text-brand-700" aria-label={`Add ${p.name}`}><Plus className="h-5 w-5" /></button>
                )}
              </div>
            );
          })}
        </Card>
      )}

      {cart.length > 0 && (
        <div className="fixed inset-x-0 bottom-[68px] z-30 px-4 lg:static lg:mt-4 lg:px-0">
          <button onClick={() => setOpen(true)} className="mx-auto flex w-full max-w-3xl items-center justify-between rounded-2xl bg-brand-600 px-5 py-3.5 text-white shadow-xl shadow-brand-700/30 active:bg-brand-700 lg:max-w-none">
            <span className="flex items-center gap-2 font-medium"><ShoppingCart className="h-5 w-5" />{count} item{count === 1 ? '' : 's'}</span>
            <span className="font-semibold">Checkout · {money(total)}</span>
          </button>
        </div>
      )}

      <Sheet open={open} onClose={() => setOpen(false)} title="Checkout"
        footer={
          <div>
            <div className="mb-2 flex items-baseline justify-between"><span className="text-slate-500">Total</span><span className="text-2xl font-semibold tabular-nums">{money(total)}</span></div>
            <Button size="lg" className="w-full" loading={busy} onClick={submit}><Check className="h-5 w-5" />Record sale</Button>
          </div>
        }>
        <div className="space-y-4 pb-2">
          <div className="divide-y divide-slate-100 rounded-xl border border-slate-100">
            {cart.map((l) => (
              <div key={l.product.id} className="p-3">
                <div className="flex items-start justify-between gap-2">
                  <p className="min-w-0 truncate font-medium">{l.product.name}</p>
                  <button onClick={() => setQty(l.product.id, 0)} className="text-slate-300 hover:text-red-500" aria-label="Remove"><Trash2 className="h-4 w-4" /></button>
                </div>
                <div className="mt-2 flex items-center gap-3">
                  <div className="flex items-center gap-1 rounded-xl bg-slate-100 p-1">
                    <button onClick={() => setQty(l.product.id, l.quantity - 1)} className="grid h-8 w-8 place-items-center rounded-lg active:bg-white" aria-label="Less"><Minus className="h-4 w-4" /></button>
                    <span className="w-7 text-center font-semibold tabular-nums">{l.quantity}</span>
                    <button onClick={() => setQty(l.product.id, l.quantity + 1)} className="grid h-8 w-8 place-items-center rounded-lg active:bg-white" aria-label="More"><Plus className="h-4 w-4" /></button>
                  </div>
                  <span className="text-slate-400">×</span>
                  <MoneyInput value={l.price} onChange={(e) => setPrice(l.product.id, e.target.value)} className="h-10 w-28" aria-label="Unit price" />
                  <span className="ml-auto font-semibold tabular-nums">{money(l.quantity * num(l.price))}</span>
                </div>
              </div>
            ))}
          </div>

          <div>
            <p className="mb-1.5 text-sm font-medium text-slate-600">Payment mode</p>
            <Segmented value={mode} onChange={(m) => { setMode(m); setPart(false); setPaid(''); }} options={MODES} />
            {mode === 'credit' && <p className="mt-1.5 text-xs text-slate-400">Customer pays later (or part now). The balance is saved under “Debts”.</p>}
          </div>

          {mode !== 'credit' && (
            <label className="flex items-center gap-2.5 text-sm text-slate-600">
              <input type="checkbox" checked={part} onChange={(e) => setPart(e.target.checked)} className="h-4 w-4 rounded accent-emerald-600" />
              Customer is paying only part now
            </label>
          )}
          {(mode === 'credit' || part) && (
            <Field label={`Amount paid now (of ${money(total)})`}><MoneyInput value={paid} onChange={(e) => setPaid(e.target.value)} placeholder="0" /></Field>
          )}
          {balance > 0 && (
            <div className="space-y-3 rounded-xl bg-amber-50 p-3.5">
              <p className="text-sm font-medium text-amber-800">Customer owes {money(balance)}</p>
              <Field label="Customer name"><Input value={customer} onChange={(e) => setCustomer(e.target.value)} placeholder="Required" /></Field>
              <div className="grid grid-cols-2 gap-2">
                <Field label="Phone (optional)"><Input type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} /></Field>
                <Field label="Pay-by date"><Input type="date" value={due} onChange={(e) => setDue(e.target.value)} /></Field>
              </div>
            </div>
          )}
          {balance === 0 && (
            <Field label="Customer name (optional)"><Input value={customer} onChange={(e) => setCustomer(e.target.value)} /></Field>
          )}
        </div>
      </Sheet>
    </div>
  );
}

function History({ refreshKey }) {
  const { money } = useAuth();
  const toast = useToast();
  const [range, setRange] = useState('today');
  const [list, setList] = useState(null);

  const load = useCallback(() => {
    const from = range === 'today' ? ymd() : range === '7' ? daysAgo(6) : range === '30' ? daysAgo(29) : monthStart();
    setList(null);
    api('/sales' + qstr({ from, to: ymd(), limit: 100 })).then((d) => setList(d.sales)).catch((e) => toast(e.message, 'err'));
  }, [range, toast]);
  useEffect(() => { load(); }, [load, refreshKey]);

  async function voidSale(s) {
    if (!confirm('Void this sale? The items go back into stock and any unpaid balance is removed.')) return;
    try { await api(`/sales/${s.id}`, { method: 'DELETE' }); toast('Sale voided'); load(); } catch (e) { toast(e.message, 'err'); }
  }

  const sum = (list || []).reduce((a, s) => ({ rev: a.rev + num(s.total_amount), profit: a.profit + num(s.profit) }), { rev: 0, profit: 0 });

  return (
    <div>
      <Segmented className="mb-3" value={range} onChange={setRange} options={[{ value: 'today', label: 'Today' }, { value: '7', label: '7 days' }, { value: '30', label: '30 days' }, { value: 'month', label: 'Month' }]} />
      {!list ? <PageLoader /> : list.length === 0 ? (
        <Card><Empty icon={ShoppingBag} title="No sales in this period" /></Card>
      ) : (
        <>
          <div className="mb-3 flex justify-between rounded-xl bg-white px-4 py-3 text-sm shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
            <span className="text-slate-500">{list.length} sale{list.length === 1 ? '' : 's'}</span>
            <span><b className="tabular-nums">{money(sum.rev)}</b> <span className="text-brand-700">(+{money(sum.profit)})</span></span>
          </div>
          <div className="space-y-2.5">
            {list.map((s) => {
              const owing = num(s.total_amount) - num(s.amount_paid);
              return (
                <Card key={s.id} className="p-3.5">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-xs text-slate-400">{dateTime(s.sold_at)}{s.customer_name ? ` · ${s.customer_name}` : ''}</p>
                      <ul className="mt-1 space-y-0.5 text-sm">
                        {s.items.map((i, k) => <li key={k} className="truncate">{i.quantity}× {i.name} <span className="text-slate-400">@ {money(i.unit_price)}</span></li>)}
                      </ul>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className="font-semibold tabular-nums">{money(s.total_amount)}</p>
                      <p className="text-xs text-brand-700">+{money(s.profit)}</p>
                    </div>
                  </div>
                  <div className="mt-2.5 flex items-center justify-between">
                    <div className="flex gap-1.5">
                      <Badge tone={s.payment_mode === 'credit' ? 'amber' : 'slate'}>{MODE_LABEL[s.payment_mode]}</Badge>
                      {owing > 0.009 && <Badge tone="red">Owing {money(owing)}</Badge>}
                    </div>
                    <button onClick={() => voidSale(s)} className="text-xs font-medium text-slate-400 hover:text-red-600">Void</button>
                  </div>
                </Card>
              );
            })}
          </div>
        </>
      )}
    </div>
  );
}

export default function SalesPage() {
  const [tab, setTab] = useState('new');
  const [refresh, setRefresh] = useState(0);
  return (
    <div>
      <PageHeader title="Sales" sub="Record what you sell" />
      <Segmented className="mb-4" value={tab} onChange={setTab} options={[{ value: 'new', label: 'New sale' }, { value: 'history', label: 'History' }]} />
      {tab === 'new' ? <NewSale onDone={() => setRefresh((n) => n + 1)} /> : <History refreshKey={refresh} />}
    </div>
  );
}
