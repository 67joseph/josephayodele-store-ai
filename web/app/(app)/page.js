'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, Camera, HandCoins, Plus, Receipt, ShoppingCart, Sparkles, Boxes } from 'lucide-react';
import { api } from '@/lib/api';
import { daysAgo, dateTime, MODE_LABEL, num, ymd } from '@/lib/format';
import { useAuth } from '@/components/auth';
import { Badge, Card, PageLoader, Stat, useToast } from '@/components/ui';
import { DayBars, fillDays } from '@/components/charts';

const ACTIONS = [
  { href: '/sales/', label: 'New sale', icon: ShoppingCart },
  { href: '/inventory/', label: 'Add stock', icon: Plus },
  { href: '/inventory/scan/', label: 'Scan photo', icon: Camera },
  { href: '/expenses/', label: 'Expense', icon: Receipt },
];

export default function Dashboard() {
  const { store, money, user } = useAuth();
  const toast = useToast();
  const [d, setD] = useState(null);

  useEffect(() => { api('/dashboard').then(setD).catch((e) => toast(e.message, 'err')); }, [toast, store]);
  if (!d) return <PageLoader />;

  const hour = new Date().getHours();
  const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const days = fillDays(d.week, daysAgo(6), ymd());
  const { snapshot: s } = d;
  const empty = s.inventory.products === 0;

  return (
    <div className="space-y-5">
      <div>
        <p className="text-sm text-slate-400">{greet}, {user.name.split(' ')[0]}</p>
        <h1 className="text-2xl font-semibold tracking-tight">Today at {store.name}</h1>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <Stat label="Sales today" value={money(d.today.revenue)} sub={`${d.today.sales_count} sale${d.today.sales_count === 1 ? '' : 's'}`} />
        <Stat label="Profit today" value={money(d.today.gross_profit)} tone="green" sub={`${d.today.items_sold} items sold`} />
        <Stat label="This month" value={money(d.month.revenue)} sub={`Gross profit ${money(d.month.gross_profit)}`} />
        <Stat label="Net profit (month)" value={money(d.month.net_profit)} tone={d.month.net_profit < 0 ? 'red' : 'green'} sub={`After ${money(d.month.expenses)} expenses`} />
      </div>

      <div className="grid grid-cols-4 gap-2">
        {ACTIONS.map((a) => (
          <Link key={a.href + a.label} href={a.href} className="flex flex-col items-center gap-2 rounded-2xl border border-slate-100 bg-white px-1 py-3.5 text-center text-xs font-medium text-slate-600 shadow-[0_1px_2px_rgba(15,23,42,0.04)] active:bg-slate-50">
            <span className="grid h-10 w-10 place-items-center rounded-xl bg-brand-50 text-brand-700"><a.icon className="h-5 w-5" /></span>
            {a.label}
          </Link>
        ))}
      </div>

      {empty && (
        <Card className="border-brand-100 bg-brand-50/60 p-4">
          <p className="font-medium text-brand-800">Let’s stock your shelves</p>
          <p className="mt-1 text-sm text-brand-700/80">Add your products manually, or snap a photo and let AI name and count them for you.</p>
          <div className="mt-3 flex gap-2 text-sm font-medium">
            <Link href="/inventory/" className="rounded-lg bg-white px-3 py-2 text-brand-700 shadow-sm">Add products</Link>
            <Link href="/inventory/scan/" className="rounded-lg bg-brand-600 px-3 py-2 text-white">Scan a photo</Link>
          </div>
        </Card>
      )}

      {(s.low_stock.length > 0 || s.debts.overdue_count > 0) && (
        <div className="space-y-2">
          {s.low_stock.length > 0 && (
            <Link href="/inventory/?low=1" className="flex items-center gap-3 rounded-2xl bg-amber-50 p-3.5 text-amber-800">
              <AlertTriangle className="h-5 w-5 shrink-0" />
              <span className="text-sm"><b>{s.low_stock.length} product{s.low_stock.length === 1 ? '' : 's'} low on stock</b> — {s.low_stock.slice(0, 3).map((p) => p.name).join(', ')}{s.low_stock.length > 3 ? '…' : ''}</span>
            </Link>
          )}
          {s.debts.overdue_count > 0 && (
            <Link href="/debts/" className="flex items-center gap-3 rounded-2xl bg-red-50 p-3.5 text-red-700">
              <HandCoins className="h-5 w-5 shrink-0" />
              <span className="text-sm"><b>{s.debts.overdue_count} overdue debt{s.debts.overdue_count === 1 ? '' : 's'}</b> need attention</span>
            </Link>
          )}
        </div>
      )}

      <Card className="p-4">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="font-semibold">Last 7 days</h2>
          <Link href="/reports/" className="text-sm font-medium text-brand-700">Full report</Link>
        </div>
        <DayBars data={days} money={money} />
      </Card>

      <div className="grid grid-cols-2 gap-3">
        <Link href="/debts/"><Stat label="Owed to you" value={money(s.debts.owed_to_me)} tone="green" /></Link>
        <Link href="/debts/"><Stat label="You owe" value={money(s.debts.i_owe)} tone={s.debts.i_owe > 0 ? 'red' : undefined} /></Link>
        <Link href="/inventory/"><Stat label="Stock value (cost)" value={money(s.inventory.cost_value)} sub={`${s.inventory.units} units`} /></Link>
        <Link href="/inventory/"><Stat label="Stock value (retail)" value={money(s.inventory.retail_value)} sub={`${s.inventory.products} products`} /></Link>
      </div>

      <Link href="/assistant/" className="flex items-center gap-3 rounded-2xl bg-gradient-to-r from-brand-600 to-emerald-500 p-4 text-white shadow-lg shadow-brand-600/20">
        <Sparkles className="h-6 w-6 shrink-0" />
        <span><b className="block">Ask your AI analyst</b><span className="text-sm text-white/85">“What sold best this week?” “Who owes me?”</span></span>
      </Link>

      <section>
        <h2 className="mb-2 font-semibold">Recent sales</h2>
        {d.recent.length === 0 ? (
          <Card className="p-6 text-center text-sm text-slate-400">No sales yet. Tap “New sale” to record your first one.</Card>
        ) : (
          <Card className="divide-y divide-slate-100">
            {d.recent.map((s) => (
              <div key={s.id} className="flex items-center justify-between gap-3 p-3.5">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium">{s.items.map((i) => `${i.quantity}× ${i.name}`).join(', ')}</p>
                  <p className="text-xs text-slate-400">{dateTime(s.sold_at)} · <Badge tone={s.payment_mode === 'credit' ? 'amber' : 'slate'}>{MODE_LABEL[s.payment_mode]}</Badge></p>
                </div>
                <div className="text-right"><p className="font-semibold tabular-nums">{money(s.total_amount)}</p><p className="text-xs text-brand-700">+{money(s.profit)}</p></div>
              </div>
            ))}
          </Card>
        )}
      </section>
    </div>
  );
}
