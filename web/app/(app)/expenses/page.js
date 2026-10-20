'use client';
import { useCallback, useEffect, useState } from 'react';
import { Plus, Receipt, Trash2 } from 'lucide-react';
import { api, qstr } from '@/lib/api';
import { monthStart, shortDate, ymd } from '@/lib/format';
import { useAuth } from '@/components/auth';
import { Badge, Button, Card, Empty, Field, Input, MoneyInput, PageHeader, PageLoader, Segmented, Sheet, Stat, useToast } from '@/components/ui';

const CATS = ['Rent', 'Transport', 'Salaries', 'Electricity', 'Supplies', 'Packaging', 'Other'];
const blank = () => ({ category: 'Transport', amount: '', spent_at: ymd(), note: '', payment_mode: 'cash' });

export default function ExpensesPage() {
  const { money } = useAuth();
  const toast = useToast();
  const [range, setRange] = useState('month');
  const [data, setData] = useState(null);
  const [form, setForm] = useState(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    const from = range === 'today' ? ymd() : range === 'month' ? monthStart() : '2000-01-01';
    api('/expenses' + qstr({ from, to: ymd() })).then(setData).catch((e) => toast(e.message, 'err'));
  }, [range, toast]);
  useEffect(() => { setData(null); load(); }, [load]);

  async function save() {
    setBusy(true);
    try { await api('/expenses', { method: 'POST', body: form }); toast('Expense saved'); setForm(null); load(); } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); }
  }
  async function remove(id) {
    if (!confirm('Delete this expense?')) return;
    try { await api(`/expenses/${id}`, { method: 'DELETE' }); load(); } catch (e) { toast(e.message, 'err'); }
  }

  return (
    <div>
      <PageHeader title="Expenses" sub="Rent, transport, wages… so profit is real" right={<Button size="sm" onClick={() => setForm(blank())}><Plus className="h-4 w-4" />Add</Button>} />
      <Segmented className="mb-3" value={range} onChange={setRange} options={[{ value: 'today', label: 'Today' }, { value: 'month', label: 'This month' }, { value: 'all', label: 'All time' }]} />
      {data && <div className="mb-3"><Stat label="Total spent" value={money(data.total)} tone={data.total > 0 ? 'red' : undefined} /></div>}

      {!data ? <PageLoader /> : data.expenses.length === 0 ? (
        <Card><Empty icon={Receipt} title="No expenses recorded" text="Log business costs to see your true net profit in Reports." /></Card>
      ) : (
        <Card className="divide-y divide-slate-100">
          {data.expenses.map((e) => (
            <div key={e.id} className="flex items-center justify-between gap-3 p-3.5">
              <div className="min-w-0">
                <p className="font-medium">{e.category}</p>
                <p className="truncate text-xs text-slate-400">{shortDate(e.spent_at)}{e.note ? ` · ${e.note}` : ''}</p>
              </div>
              <div className="flex items-center gap-3">
                <div className="text-right"><p className="font-semibold tabular-nums">{money(e.amount)}</p><Badge className="capitalize">{e.payment_mode}</Badge></div>
                <button onClick={() => remove(e.id)} className="text-slate-300 hover:text-red-500" aria-label="Delete"><Trash2 className="h-4 w-4" /></button>
              </div>
            </div>
          ))}
        </Card>
      )}

      <Sheet open={!!form} onClose={() => setForm(null)} title="Add expense"
        footer={<Button size="lg" className="w-full" loading={busy} disabled={!form || !form.category.trim() || !Number(form.amount)} onClick={save}>Save expense</Button>}>
        {form && (
          <div className="space-y-4 pb-2">
            <div className="flex flex-wrap gap-2">
              {CATS.map((c) => (
                <button key={c} type="button" onClick={() => setForm({ ...form, category: c })} className={`rounded-full px-3.5 py-1.5 text-sm font-medium ${form.category === c ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600'}`}>{c}</button>
              ))}
            </div>
            <Field label="Category"><Input value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Amount"><MoneyInput autoFocus value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} placeholder="0" /></Field>
              <Field label="Date"><Input type="date" value={form.spent_at} onChange={(e) => setForm({ ...form, spent_at: e.target.value })} /></Field>
            </div>
            <Segmented value={form.payment_mode} onChange={(v) => setForm({ ...form, payment_mode: v })} options={[{ value: 'cash', label: 'Cash' }, { value: 'card', label: 'Card' }, { value: 'transfer', label: 'Transfer' }]} />
            <Field label="Note (optional)"><Input value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} /></Field>
          </div>
        )}
      </Sheet>
    </div>
  );
}
