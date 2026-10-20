'use client';
import { useCallback, useEffect, useRef, useState } from 'react';
import { CheckCircle2, ExternalLink, MessageCircle, Smartphone, Trash2 } from 'lucide-react';
import { api } from '@/lib/api';
import { dateTime } from '@/lib/format';
import { Badge, Button, Card, PageHeader, PageLoader, useToast } from '@/components/ui';

const EXAMPLES = [
  ['Record a sale', 'sold 2 indomie and 1 peak milk, cash'],
  ['Sale on credit', 'sold 3 bread to Mrs Bola on credit, paid 1000'],
  ['Restock', 'received 20 cartons of peak milk, cost 650'],
  ['Expense', 'spent 2500 on transport'],
  ['Debts', 'Mrs Bola paid 500  ·  I owe Dangote 20k'],
  ['Photo', 'Send a photo of your shelf to add stock'],
  ['Ask anything', 'how much did I make today?'],
];

const pretty = (n) => (n ? `+${n}` : '');

export default function WhatsAppPage() {
  const toast = useToast();
  const [status, setStatus] = useState(null);
  const [code, setCode] = useState(null); // {code, url, business_number, expires_minutes}
  const [busy, setBusy] = useState(false);
  const countRef = useRef(0);

  const load = useCallback(() => api('/whatsapp/status').then(setStatus).catch((e) => toast(e.message, 'err')), [toast]);
  useEffect(() => { load(); }, [load]);

  // While a code is showing, watch for the number to connect.
  useEffect(() => {
    if (!code) return;
    countRef.current = status ? status.links.length : 0;
    const t = setInterval(async () => {
      try {
        const s = await api('/whatsapp/status');
        if (s.links.length > countRef.current) {
          setStatus(s);
          setCode(null);
          toast('WhatsApp connected 🎉');
        }
      } catch {}
    }, 3500);
    const expire = setTimeout(() => setCode(null), 15 * 60 * 1000);
    return () => { clearInterval(t); clearTimeout(expire); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code]);

  async function connect() {
    setBusy(true);
    try { setCode(await api('/whatsapp/link-code', { method: 'POST' })); } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); }
  }

  async function unlink(l) {
    if (!confirm(`Disconnect ${pretty(l.wa_number)}? It will no longer be able to use this store.`)) return;
    try { await api(`/whatsapp/links/${l.id}`, { method: 'DELETE' }); toast('Disconnected'); load(); } catch (e) { toast(e.message, 'err'); }
  }

  if (!status) return <PageLoader />;
  const biz = status.business_number;

  return (
    <div className="space-y-4">
      <PageHeader title="WhatsApp" sub="Run your store from a chat" />

      {!status.configured && (
        <Card className="border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
          <p className="font-medium">WhatsApp isn’t set up on the server yet.</p>
          <p className="mt-1">Add the WhatsApp keys to <code className="rounded bg-amber-100 px-1">api/config.local.php</code> (see the README), then come back here to connect your number.</p>
        </Card>
      )}

      <Card className="p-4">
        <div className="flex items-start gap-3">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><MessageCircle className="h-6 w-6" /></span>
          <div className="min-w-0">
            <h2 className="font-semibold">Connect your number</h2>
            <p className="text-sm text-slate-500">Link your WhatsApp (or a staff member’s) to <b>this store</b>. You can connect several numbers.</p>
          </div>
        </div>

        {code ? (
          <div className="mt-4 rounded-2xl bg-slate-50 p-4 text-center">
            <p className="text-sm text-slate-500">Send this message{biz ? <> to <b>{pretty(biz)}</b></> : ''} on WhatsApp:</p>
            <p className="my-2 select-all text-3xl font-bold tracking-[0.2em] text-slate-900">LINK {code.code}</p>
            <p className="text-xs text-slate-400">Valid for {code.expires_minutes} minutes · one use</p>
            {code.url && (
              <a href={code.url} target="_blank" rel="noreferrer" className="mt-3 inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 font-medium text-white hover:bg-emerald-700">
                <ExternalLink className="h-4 w-4" />Open WhatsApp
              </a>
            )}
            <p className="mt-3 text-xs text-slate-400">Waiting for your message…</p>
          </div>
        ) : (
          <Button className="mt-4 w-full" size="lg" loading={busy} onClick={connect} disabled={!status.configured}><Smartphone className="h-5 w-5" />Connect a number</Button>
        )}
      </Card>

      <section>
        <h2 className="mb-2 font-semibold">Connected numbers</h2>
        {status.links.length === 0 ? (
          <Card className="p-5 text-center text-sm text-slate-400">No numbers connected yet.</Card>
        ) : (
          <Card className="divide-y divide-slate-100">
            {status.links.map((l) => (
              <div key={l.id} className="flex items-center justify-between gap-3 p-3.5">
                <div className="min-w-0">
                  <p className="flex items-center gap-1.5 font-medium"><CheckCircle2 className="h-4 w-4 text-emerald-600" />{pretty(l.wa_number)}</p>
                  <p className="text-xs text-slate-400">Linked by {l.name} · {dateTime(l.created_at)}</p>
                </div>
                <button onClick={() => unlink(l)} className="text-slate-300 hover:text-red-500" aria-label="Disconnect"><Trash2 className="h-5 w-5" /></button>
              </div>
            ))}
          </Card>
        )}
        {status.links.length > 1 && <p className="mt-2 text-xs text-slate-400">Everyone connected can record sales and see reports for this store, so only add people you trust.</p>}
      </section>

      <Card className="p-4">
        <div className="mb-3 flex items-center gap-2"><h2 className="font-semibold">What you can say</h2><Badge tone="green">just type naturally</Badge></div>
        <ul className="space-y-2.5">
          {EXAMPLES.map(([label, ex]) => (
            <li key={label} className="text-sm"><span className="text-slate-400">{label}</span><br /><span className="font-medium text-slate-700">“{ex}”</span></li>
          ))}
        </ul>
        <p className="mt-3 rounded-xl bg-slate-50 p-3 text-xs text-slate-500">Before saving anything, the bot shows you a summary and waits for <b>YES</b>. Reply <b>NO</b> to cancel. Shortcuts: TODAY · STOCK · DEBTS · HELP.</p>
      </Card>
    </div>
  );
}
