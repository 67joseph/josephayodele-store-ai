'use client';
import { useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft, Camera, ImagePlus, Plus, Sparkles, Trash2, X } from 'lucide-react';
import { api, assetUrl } from '@/lib/api';
import { num } from '@/lib/format';
import { Badge, Button, Card, Input, IntInput, MoneyInput, Spinner, useToast } from '@/components/ui';

// Shrink phone photos before upload: faster, cheaper, and under the 5MB limit.
function compress(file, max = 1600, quality = 0.82) {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      const scale = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * scale);
      c.height = Math.round(img.height * scale);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob((b) => { URL.revokeObjectURL(url); resolve(b ? new File([b], 'photo.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', quality);
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
    img.src = url;
  });
}

let rowKey = 0;
const newKey = () => `r${++rowKey}`;

export default function ScanPage() {
  const toast = useToast();
  const router = useRouter();
  const camRef = useRef(null);
  const galRef = useRef(null);
  const [files, setFiles] = useState([]);     // {file, preview}
  const [stage, setStage] = useState('pick'); // pick | scanning | review
  const [rows, setRows] = useState([]);       // editable detected rows
  const [images, setImages] = useState([]);
  const [busy, setBusy] = useState(false);

  async function addFiles(list) {
    const picked = Array.from(list || []).filter((f) => f.type.startsWith('image/'));
    if (!picked.length) return;
    if (files.length + picked.length > 6) toast('You can scan up to 6 photos at a time', 'err');
    const next = [];
    for (const f of picked.slice(0, 6 - files.length)) next.push({ file: await compress(f), preview: URL.createObjectURL(f) });
    setFiles((cur) => [...cur, ...next]);
  }

  async function scan() {
    setStage('scanning');
    try {
      const form = new FormData();
      files.forEach((f) => form.append('images[]', f.file, 'photo.jpg'));
      const d = await api('/ai/scan', { method: 'POST', form });
      const out = [];
      d.uploads.forEach((u) => {
        u.items.forEach((it) => out.push({
          key: newKey(), id: it.id, image: u.image, name: it.name, quantity: String(it.quantity),
          cost: it.match ? String(num(it.match.cost_price) || '') : '', price: it.match ? String(num(it.match.sale_price) || '') : '',
          match: it.match,
        }));
      });
      setImages(d.uploads.map((u) => u.image));
      setRows(out);
      setStage('review');
      if (!out.length) toast('No products found — try a clearer, closer photo, or add rows manually.', 'err');
    } catch (e) { toast(e.message, 'err'); setStage('pick'); }
  }

  const upd = (key, patch) => setRows((r) => r.map((x) => (x.key === key ? { ...x, ...patch } : x)));

  async function confirm() {
    const items = rows.map((r) => ({ id: r.id, name: r.name.trim(), quantity: parseInt(r.quantity, 10) || 0, cost_price: r.cost || 0, sale_price: r.price || 0 }));
    if (!items.length) return toast('Nothing to add', 'err');
    if (items.some((i) => !i.name || i.quantity < 1)) return toast('Every item needs a name and a quantity of at least 1', 'err');
    setBusy(true);
    try {
      const d = await api('/ai/confirm', { method: 'POST', body: { items } });
      toast(`${d.created} new, ${d.restocked} restocked`);
      router.push('/inventory/');
    } catch (e) { toast(e.message, 'err'); setBusy(false); }
  }

  return (
    <div>
      <Link href="/inventory/" className="mb-3 inline-flex items-center gap-1 text-sm font-medium text-slate-500"><ArrowLeft className="h-4 w-4" />Stock</Link>
      <div className="mb-4">
        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight sm:text-2xl"><Sparkles className="h-5 w-5 text-brand-600" />Add stock from photos</h1>
        <p className="text-sm text-slate-400">Photograph your shelf or delivery. AI names the products and counts them — you review and fix before saving.</p>
      </div>

      {stage !== 'review' && (
        <>
          <input ref={camRef} type="file" accept="image/*" capture="environment" hidden onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }} />
          <input ref={galRef} type="file" accept="image/*" multiple hidden onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }} />

          <div className="grid grid-cols-2 gap-3">
            <button onClick={() => camRef.current.click()} disabled={stage === 'scanning'} className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-brand-200 bg-brand-50/50 py-8 text-brand-700 active:bg-brand-50"><Camera className="h-7 w-7" /><span className="text-sm font-medium">Take photo</span></button>
            <button onClick={() => galRef.current.click()} disabled={stage === 'scanning'} className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-slate-200 bg-white py-8 text-slate-600 active:bg-slate-50"><ImagePlus className="h-7 w-7" /><span className="text-sm font-medium">Choose from gallery</span></button>
          </div>

          {files.length > 0 && (
            <div className="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4">
              {files.map((f, i) => (
                <div key={f.preview} className="relative aspect-square overflow-hidden rounded-xl bg-slate-100">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={f.preview} alt="" className="h-full w-full object-cover" />
                  {stage !== 'scanning' && <button onClick={() => setFiles(files.filter((_, j) => j !== i))} className="absolute right-1 top-1 grid h-6 w-6 place-items-center rounded-full bg-slate-900/70 text-white" aria-label="Remove"><X className="h-3.5 w-3.5" /></button>}
                </div>
              ))}
            </div>
          )}

          {stage === 'scanning' ? (
            <Card className="mt-5 flex items-center gap-3 p-4"><Spinner /><div><p className="font-medium">AI is looking at your photos…</p><p className="text-sm text-slate-400">This usually takes 5–20 seconds.</p></div></Card>
          ) : (
            <Button size="lg" className="mt-5 w-full" disabled={!files.length} onClick={scan}><Sparkles className="h-5 w-5" />Identify products{files.length ? ` (${files.length} photo${files.length > 1 ? 's' : ''})` : ''}</Button>
          )}
        </>
      )}

      {stage === 'review' && (
        <>
          <div className="mb-3 flex gap-2 overflow-x-auto pb-1">
            {images.map((src) => (
              // eslint-disable-next-line @next/next/no-img-element
              <img key={src} src={assetUrl(src)} alt="" className="h-14 w-14 shrink-0 rounded-lg object-cover" />
            ))}
          </div>
          <p className="mb-3 text-sm text-slate-500">AI found <b>{rows.length}</b> item{rows.length === 1 ? '' : 's'}. Edit names and counts, then set prices.</p>

          <div className="space-y-3">
            {rows.map((r) => (
              <Card key={r.key} className="p-3.5">
                <div className="flex items-start gap-2">
                  <div className="min-w-0 flex-1">
                    <Input value={r.name} onChange={(e) => upd(r.key, { name: e.target.value })} aria-label="Product name" className="font-medium" />
                    {r.match && <Badge tone="blue" className="mt-1.5">Already in stock ({r.match.quantity}) — this will be added</Badge>}
                  </div>
                  <button onClick={() => setRows(rows.filter((x) => x.key !== r.key))} className="grid h-11 w-9 shrink-0 place-items-center text-slate-300 hover:text-red-500" aria-label="Remove item"><Trash2 className="h-5 w-5" /></button>
                </div>
                <div className="mt-2.5 grid grid-cols-3 gap-2">
                  <label className="block"><span className="mb-1 block text-xs text-slate-400">Qty</span><IntInput value={r.quantity} onChange={(e) => upd(r.key, { quantity: e.target.value })} /></label>
                  <label className="block"><span className="mb-1 block text-xs text-slate-400">Cost price</span><MoneyInput value={r.cost} onChange={(e) => upd(r.key, { cost: e.target.value })} placeholder="0" /></label>
                  <label className="block"><span className="mb-1 block text-xs text-slate-400">Sell price</span><MoneyInput value={r.price} onChange={(e) => upd(r.key, { price: e.target.value })} placeholder="0" /></label>
                </div>
              </Card>
            ))}
          </div>

          <Button variant="ghost" className="mt-3 w-full" onClick={() => setRows([...rows, { key: newKey(), id: null, name: '', quantity: '1', cost: '', price: '' }])}><Plus className="h-4 w-4" />Add a missed item</Button>

          <div className="sticky bottom-20 mt-5 flex gap-2 lg:bottom-4">
            <Button variant="ghost" size="lg" onClick={() => { setStage('pick'); setRows([]); }}>Rescan</Button>
            <Button size="lg" className="flex-1" loading={busy} disabled={!rows.length} onClick={confirm}>Add {rows.length} item{rows.length === 1 ? '' : 's'} to stock</Button>
          </div>
        </>
      )}
    </div>
  );
}
