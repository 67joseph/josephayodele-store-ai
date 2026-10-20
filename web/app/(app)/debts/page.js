'use client';
import { useCallback, useEffect, useState } from 'react';
import { HandCoins, Phone, Plus, Trash2 } from 'lucide-react';
import { api, qstr } from '@/lib/api';
import { dateTime, num, ymd } from '@/lib/format';
import { useAuth } from '@/components/auth';
import { Badge, Button, Card, Empty, Field, Input, MoneyInput, PageHeader, PageLoader, Segmented, Select, Sheet, Stat, useToast } from '@/components/ui';

export default function DebtsPage() {
  const { money } = useAuth();
  const toast = useToast();
  const [dir, setDir] = useState('owed_to_me');
  const [showSettled, setShowSettled] = useState(false);
  const [data, setData] = useState(null);
  const [add, setAdd] = useState(null);
  const [detail, setDetail] = useState(null); // debt with payments
  const [pay, setPay] = useState({ amount: '', payment_mode: 'cash' });
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api('/debts' + qstr({ direction: dir, status: showSettled ? '' : 'open' })).then(setData).catch((e) => toast(e.message, 'err'));
  }, [dir, showSettled, toast]);
  useEffect(() => { setData(null); load(); }, [load]);

  const mine = dir === 'owed_to_me';
  const openDetail = async (d) => {
    try { setDetail((await api(`/debts/${d.id}`)).debt); setPay({ amount: '', payment_mode: 'cash' }); } catch (e) { toast(e.message, 'err'); }
  };

  async function saveDebt() {
    setBusy(true);
    try { await api('/debts', { method: 'POST', body: add }); toast('Debt recorded'); setAdd(null); load(); } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); }
  }

  async function recordPayment() {
    setBusy(true);
    try {
      await api(`/debts/${detail.id}/payments`, { method: 'POST', body: pay });
      toast(mine ? 'Payment received' : 'Payment recorded');
      await openDetail(detail);
      load();
    } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); }
  }

  async function removeDebt() {
    if (!confirm('Delete this debt record and its payment history?')) return;
    try { await api(`/debts/${detail.id}`, { method: 'DELETE' }); setDetail(null); toast('Deleted'); load(); } catch (e) { toast(e.message, 'err'); }
  }

  const today = ymd();
  const remaining = detail ? num(detail.amount) - num(detail.amount_paid) : 0;

  return (
    <div>
      <PageHeader title="Debts" sub="Who owes you, and who you owe" right={<Button size="sm" onClick={() => setAdd({ direction: dir, party_name: '', party_phone: '', amount: '', due_date: '', note: '' })}><Plus className="h-4 w-4" />Add</Button>} />

      {data && (
        <div className="mb-3 grid grid-cols-2 gap-3">
          <Stat label="Owed to you" value={money(data.totals.owed_to_me)} tone="green" />
          <Stat label="You owe" value={money(data.totals.i_owe)} tone={data.totals.i_owe > 0 ? 'red' : undefined} />
        </div>
      )}

      <Segmented className="mb-3" value={dir} onChange={setDir} options={[{ value: 'owed_to_me', label: 'Owed to me' }, { value: 'i_owe', label: 'I owe' }]} />

      {!data ? <PageLoader /> : data.debts.length === 0 ? (
        <Card><Empty icon={HandCoins} title={mine ? 'Nobody owes you' : 'You owe nobody'} text={mine ? 'Credit sales show up here automatically. You can also add a debt manually.' : 'Log money you owe suppliers so you never forget.'} /></Card>
      ) : (
        <div className="space-y-2.5">
          {data.debts.map((d) => {
            const left = num(d.amount) - num(d.amount_paid);
            const overdue = d.status !== 'settled' && d.due_date && d.due_date < today;
            const pct = Math.min(100, (num(d.amount_paid) / num(d.amount)) * 100);
            return (
              <button key={d.id} onClick={() => openDetail(d)} className="block w-full text-left">
                <Card className="p-3.5 active:bg-slate-50">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="truncate font-medium">{d.party_name}</p>
                      <p className="truncate text-xs text-slate-400">{d.note || (d.sale_id ? `Sale #${d.sale_id}` : 'Manual entry')}</p>
                    </div>
                    <div className="text-right">
                      <p className="font-semibold tabular-nums">{d.status === 'settled' ? money(d.amount) : money(left)}</p>
                      <p className="text-xs text-slate-400">{d.status === 'settled' ? 'Settled' : `of ${money(d.amount)}`}</p>
                    </div>
                  </div>
                  <div className="mt-2.5 h-1.5 rounded-full bg-slate-100"><div className="h-1.5 rounded-full bg-brand-500" style={{ width: `${pct}%` }} /></div>
                  <div className="mt-2 flex items-center gap-1.5">
                    {d.status === 'settled' ? <Badge tone="green">Settled</Badge> : d.status === 'partial' ? <Badge tone="amber">Part-paid</Badge> : <Badge>Unpaid</Badge>}
                    {d.due_date && d.status !== 'settled' && <Badge tone={overdue ? 'red' : 'slate'}>{overdue ? 'Overdue · ' : 'Due '}{d.due_date}</Badge>}
                  </div>
                </Card>
              </button>
            );
          })}
        </div>
      )}

      <label className="mt-4 flex items-center justify-center gap-2 text-sm text-slate-500">
        <input type="checkbox" checked={showSettled} onChange={(e) => setShowSettled(e.target.checked)} className="h-4 w-4 rounded accent-emerald-600" />Show settled debts
      </label>

      {/* Add debt */}
      <Sheet open={!!add} onClose={() => setAdd(null)} title="Add a debt"
        footer={<Button size="lg" className="w-full" loading={busy} disabled={!add || !add.party_name.trim() || !num(add.amount)} onClick={saveDebt}>Save debt</Button>}>
        {add && (
          <div className="space-y-4 pb-2">
            <Segmented value={add.direction} onChange={(v) => setAdd({ ...add, direction: v })} options={[{ value: 'owed_to_me', label: 'Someone owes me' }, { value: 'i_owe', label: 'I owe someone' }]} />
            <Field label={add.direction === 'owed_to_me' ? 'Customer name' : 'Supplier / person owed'}><Input autoFocus value={add.party_name} onChange={(e) => setAdd({ ...add, party_name: e.target.value })} /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Amount"><MoneyInput value={add.amount} onChange={(e) => setAdd({ ...add, amount: e.target.value })} placeholder="0" /></Field>
              <Field label="Due date"><Input type="date" value={add.due_date} onChange={(e) => setAdd({ ...add, due_date: e.target.value })} /></Field>
            </div>
            <Field label="Phone (optional)"><Input type="tel" value={add.party_phone} onChange={(e) => setAdd({ ...add, party_phone: e.target.value })} /></Field>
            <Field label="Note (optional)"><Input value={add.note} onChange={(e) => setAdd({ ...add, note: e.target.value })} placeholder="e.g. 20 cartons of noodles" /></Field>
          </div>
        )}
      </Sheet>

      {/* Debt detail */}
      <Sheet open={!!detail} onClose={() => setDetail(null)} title={detail ? detail.party_name : ''}>
        {detail && (
          <div className="space-y-4 pb-3">
            <div className="grid grid-cols-3 gap-2 text-center">
              <div className="rounded-xl bg-slate-50 p-2.5"><p className="text-xs text-slate-400">Total</p><p className="font-semibold tabular-nums">{money(detail.amount)}</p></div>
              <div className="rounded-xl bg-slate-50 p-2.5"><p className="text-xs text-slate-400">Paid</p><p className="font-semibold tabular-nums text-brand-700">{money(detail.amount_paid)}</p></div>
              <div className="rounded-xl bg-slate-50 p-2.5"><p className="text-xs text-slate-400">Balance</p><p className="font-semibold tabular-nums">{money(remaining)}</p></div>
            </div>
            {detail.party_phone && <a href={`tel:${detail.party_phone}`} className="flex items-center gap-2 text-sm font-medium text-brand-700"><Phone className="h-4 w-4" />{detail.party_phone}</a>}
            {detail.note && <p className="text-sm text-slate-500">{detail.note}</p>}

            {remaining > 0.009 && (
              <div className="space-y-3 rounded-xl border border-slate-100 p-3.5">
                <p className="text-sm font-medium">{detail.direction === 'owed_to_me' ? 'Record a payment received' : 'Record a payment you made'}</p>
                <div className="grid grid-cols-2 gap-2">
                  <MoneyInput value={pay.amount} onChange={(e) => setPay({ ...pay, amount: e.target.value })} placeholder={`Up to ${remaining}`} aria-label="Amount" />
                  <Select value={pay.payment_mode} onChange={(e) => setPay({ ...pay, payment_mode: e.target.value })}><option value="cash">Cash</option><option value="card">Card</option><option value="transfer">Transfer</option></Select>
                </div>
                <div className="flex gap-2">
                  <Button variant="soft" size="sm" onClick={() => setPay({ ...pay, amount: String(remaining) })}>Full balance</Button>
                  <Button size="sm" className="flex-1" loading={busy} disabled={!num(pay.amount)} onClick={recordPayment}>Save payment</Button>
                </div>
              </div>
            )}

            <div>
              <p className="mb-1.5 text-sm font-medium text-slate-600">Payment history</p>
              {detail.payments.length === 0 ? <p className="text-sm text-slate-400">No payments yet.</p> : (
                <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">
                  {detail.payments.map((p) => (
                    <li key={p.id} className="flex justify-between px-3 py-2.5 text-sm"><span className="text-slate-500">{dateTime(p.paid_at)} · {p.payment_mode}</span><b className="tabular-nums">{money(p.amount)}</b></li>
                  ))}
                </ul>
              )}
            </div>
            <Button variant="danger" className="w-full" onClick={removeDebt}><Trash2 className="h-4 w-4" />Delete this debt</Button>
          </div>
        )}
      </Sheet>
    </div>
  );
}
