'use client';
import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { Sparkles } from 'lucide-react';
import { api, qstr } from '@/lib/api';
import { daysAgo, MODE_LABEL, monthStart, num, ymd } from '@/lib/format';
import { useAuth } from '@/components/auth';
import { Card, Input, PageHeader, PageLoader, Segmented, Stat, useToast } from '@/components/ui';
import { DayBars, fillDays, ShareBars } from '@/components/charts';

const PRESETS = {
  today: () => [ymd(), ymd()],
  '7': () => [daysAgo(6), ymd()],
  '30': () => [daysAgo(29), ymd()],
  month: () => [monthStart(), ymd()],
};

export default function ReportsPage() {
  const { money, store } = useAuth();
  const toast = useToast();
  const [preset, setPreset] = useState('30');
  const [custom, setCustom] = useState({ from: daysAgo(29), to: ymd() });
  const [data, setData] = useState(null);

  const [from, to] = preset === 'custom' ? [custom.from, custom.to] : PRESETS[preset]();

  useEffect(() => {
    if (from > to) return;
    setData(null);
    api('/reports' + qstr({ from, to })).then(setData).catch((e) => toast(e.message, 'err'));
  }, [from, to, toast, store]);

  const days = useMemo(() => (data ? fillDays(data.report.by_day, data.report.from, data.report.to) : []), [data]);
  const r = data && data.report;
  const margin = r && r.revenue > 0 ? Math.round((r.gross_profit / r.revenue) * 100) : 0;

  return (
    <div>
      <PageHeader title="Reports" sub="How your shop is really doing" right={<Link href="/assistant/" className="flex items-center gap-1.5 rounded-full bg-brand-50 px-3.5 py-2 text-sm font-medium text-brand-700"><Sparkles className="h-4 w-4" />Ask AI</Link>} />

      <Segmented className="mb-3" value={preset} onChange={setPreset} options={[{ value: 'today', label: 'Today' }, { value: '7', label: '7d' }, { value: '30', label: '30d' }, { value: 'month', label: 'Month' }, { value: 'custom', label: 'Custom' }]} />
      {preset === 'custom' && (
        <div className="mb-3 grid grid-cols-2 gap-3">
          <Input type="date" value={custom.from} max={custom.to} onChange={(e) => setCustom({ ...custom, from: e.target.value })} aria-label="From" />
          <Input type="date" value={custom.to} min={custom.from} onChange={(e) => setCustom({ ...custom, to: e.target.value })} aria-label="To" />
        </div>
      )}

      {!data ? <PageLoader /> : (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <Stat label="Sales" value={money(r.revenue)} sub={`${r.sales_count} sale${r.sales_count === 1 ? '' : 's'} · avg ${money(r.avg_sale)}`} />
            <Stat label="Gross profit" value={money(r.gross_profit)} tone="green" sub={`${margin}% margin`} />
            <Stat label="Expenses" value={money(r.expenses)} />
            <Stat label="Net profit" value={money(r.net_profit)} tone={r.net_profit < 0 ? 'red' : 'green'} sub="Gross profit − expenses" />
          </div>

          <Card className="p-4">
            <h2 className="mb-3 font-semibold">Sales & profit by day</h2>
            {r.sales_count === 0 ? <p className="py-6 text-center text-sm text-slate-400">No sales in this period.</p> : <DayBars data={days} money={money} />}
          </Card>

          <div className="grid grid-cols-3 gap-3 text-center">
            <Card className="p-3"><p className="text-xs text-slate-400">Items sold</p><p className="text-lg font-semibold tabular-nums">{r.items_sold}</p></Card>
            <Card className="p-3"><p className="text-xs text-slate-400">Cost of goods</p><p className="text-lg font-semibold tabular-nums">{money(r.cogs)}</p></Card>
            <Card className="p-3"><p className="text-xs text-slate-400">Collected</p><p className="text-lg font-semibold tabular-nums">{money(r.collected)}</p></Card>
          </div>

          {r.by_payment.length > 0 && (
            <Card className="p-4">
              <h2 className="mb-3 font-semibold">Payment modes</h2>
              <ShareBars rows={r.by_payment.map((p) => ({ label: `${MODE_LABEL[p.mode]} (${p.sales})`, total: p.total }))} money={money} />
            </Card>
          )}

          {r.top_products.length > 0 && (
            <Card className="p-4">
              <h2 className="mb-2 font-semibold">Top products</h2>
              <div className="divide-y divide-slate-100">
                {r.top_products.map((p, i) => (
                  <div key={p.id} className="flex items-center gap-3 py-2.5">
                    <span className="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-slate-100 text-xs font-semibold text-slate-500">{i + 1}</span>
                    <div className="min-w-0 flex-1"><p className="truncate text-sm font-medium">{p.name}</p><p className="text-xs text-slate-400">{p.qty} sold</p></div>
                    <div className="text-right text-sm"><p className="font-semibold tabular-nums">{money(p.revenue)}</p><p className="text-xs text-brand-700">+{money(p.profit)}</p></div>
                  </div>
                ))}
              </div>
            </Card>
          )}

          {r.expenses_by_category.length > 0 && (
            <Card className="p-4">
              <h2 className="mb-3 font-semibold">Where expenses went</h2>
              <ShareBars rows={r.expenses_by_category.map((e) => ({ label: e.category, total: e.total }))} money={money} />
            </Card>
          )}

          <Card className="p-4">
            <h2 className="mb-3 font-semibold">Right now</h2>
            <dl className="grid grid-cols-2 gap-y-3 text-sm">
              <dt className="text-slate-400">Stock at cost</dt><dd className="text-right font-medium tabular-nums">{money(data.snapshot.inventory.cost_value)}</dd>
              <dt className="text-slate-400">Stock at retail</dt><dd className="text-right font-medium tabular-nums">{money(data.snapshot.inventory.retail_value)}</dd>
              <dt className="text-slate-400">Owed to you</dt><dd className="text-right font-medium tabular-nums text-brand-700">{money(data.snapshot.debts.owed_to_me)}</dd>
              <dt className="text-slate-400">You owe</dt><dd className="text-right font-medium tabular-nums text-red-600">{money(data.snapshot.debts.i_owe)}</dd>
              <dt className="text-slate-400">Low-stock products</dt><dd className="text-right font-medium tabular-nums">{data.snapshot.low_stock.length}</dd>
            </dl>
          </Card>
        </div>
      )}
    </div>
  );
}
