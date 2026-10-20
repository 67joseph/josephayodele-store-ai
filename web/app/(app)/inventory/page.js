'use client';
import { Suspense, useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Boxes, Camera, MinusCircle, Plus, PlusCircle, Search, Trash2 } from 'lucide-react';
import { api, qstr } from '@/lib/api';
import { num } from '@/lib/format';
import { useAuth } from '@/components/auth';
import { Thumb } from '@/components/thumb';
import { Badge, Button, Card, Empty, Field, Input, IntInput, MoneyInput, PageHeader, PageLoader, Segmented, Sheet, Stat, useToast } from '@/components/ui';

const blank = { name: '', sku: '', cost_price: '', sale_price: '', quantity: '', reorder_level: '5' };

function Inventory() {
  const { money } = useAuth();
  const toast = useToast();
  const params = useSearchParams();
  const [list, setList] = useState(null);
  const [q, setQ] = useState('');
  const [low, setLow] = useState(params.get('low') === '1');
  const [form, setForm] = useState(null);   // product sheet (null = closed)
  const [stock, setStock] = useState(null); // stock sheet
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api('/products' + qstr({ q, low: low ? 1 : '' })).then((d) => setList(d.products)).catch((e) => toast(e.message, 'err'));
  }, [q, low, toast]);
  useEffect(() => { const t = setTimeout(load, q ? 250 : 0); return () => clearTimeout(t); }, [load, q]);

  const totals = (list || []).reduce((a, p) => ({ cost: a.cost + p.quantity * num(p.cost_price), retail: a.retail + p.quantity * num(p.sale_price) }), { cost: 0, retail: 0 });

  async function saveProduct() {
    setBusy(true);
    try {
      const body = { ...form };
      if (form.id) await api(`/products/${form.id}`, { method: 'PUT', body });
      else await api('/products', { method: 'POST', body });
      toast(form.id ? 'Product updated' : 'Product added');
      setForm(null);
      load();
    } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); }
  }

  async function removeProduct() {
    if (!confirm(`Remove “${form.name}” from your stock list? Past sales are kept.`)) return;
    try { await api(`/products/${form.id}`, { method: 'DELETE' }); toast('Product removed'); setForm(null); load(); } catch (e) { toast(e.message, 'err'); }
  }

  async function saveStock() {
    const qty = Math.abs(parseInt(stock.qty, 10) || 0);
    if (!qty) return toast('Enter a quantity', 'err');
    setBusy(true);
    try {
      await api(`/products/${stock.p.id}/stock`, { method: 'POST', body: { change: stock.mode === 'add' ? qty : -qty, cost_price: stock.mode === 'add' ? stock.cost : '', note: stock.mode === 'add' ? 'Restock' : 'Stock correction' } });
      toast('Stock updated');
      setStock(null);
      load();
    } catch (e) { toast(e.message, 'err'); } finally { setBusy(false); }
  }

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
  const margin = form && num(form.sale_price) > 0 ? Math.round(((num(form.sale_price) - num(form.cost_price)) / num(form.sale_price)) * 100) : null;

  return (
    <div>
      <PageHeader
        title="Stock"
        sub="Everything you sell"
        right={
          <div className="flex gap-2">
            <Link href="/inventory/scan/"><Button variant="soft" size="sm"><Camera className="h-4 w-4" />Scan</Button></Link>
            <Button size="sm" onClick={() => setForm({ ...blank })}><Plus className="h-4 w-4" />Add</Button>
          </div>
        }
      />

      <div className="mb-3 grid grid-cols-2 gap-3">
        <Stat label="Cost value" value={money(totals.cost)} />
        <Stat label="Retail value" value={money(totals.retail)} tone="green" />
      </div>

      <div className="mb-3 flex gap-2">
        <div className="relative flex-1">
          <Search className="pointer-events-none absolute left-3 top-3 h-5 w-5 text-slate-400" />
          <Input className="pl-10" placeholder="Search products" value={q} onChange={(e) => setQ(e.target.value)} />
        </div>
        <Button variant={low ? 'primary' : 'ghost'} onClick={() => setLow(!low)}>Low</Button>
      </div>

      {!list ? <PageLoader /> : list.length === 0 ? (
        <Card>
          <Empty icon={Boxes} title={q || low ? 'No matching products' : 'No products yet'} text={q || low ? 'Try a different search.' : 'Add products one by one, or scan a photo and let AI count them.'}
            action={!q && !low && <Button onClick={() => setForm({ ...blank })}><Plus className="h-4 w-4" />Add your first product</Button>} />
        </Card>
      ) : (
        <Card className="divide-y divide-slate-100">
          {list.map((p) => {
            const out = p.quantity <= 0;
            const isLow = !out && p.quantity <= p.reorder_level;
            return (
              <div key={p.id} className="flex items-center gap-3 p-3">
                <button className="flex min-w-0 flex-1 items-center gap-3 text-left" onClick={() => setForm({ ...p, cost_price: p.cost_price, sale_price: p.sale_price, quantity: '' })}>
                  <Thumb p={p} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate font-medium">{p.name}</span>
                    <span className="block text-xs text-slate-400">Cost {money(p.cost_price)} · Sells {money(p.sale_price)}</span>
                  </span>
                </button>
                <div className="flex flex-col items-end gap-1">
                  <Badge tone={out ? 'red' : isLow ? 'amber' : 'green'}>{out ? 'Out of stock' : `${p.quantity} in stock`}</Badge>
                  <button onClick={() => setStock({ p, mode: 'add', qty: '', cost: '' })} className="text-xs font-medium text-brand-700">Update stock</button>
                </div>
              </div>
            );
          })}
        </Card>
      )}

      {/* Add / edit product */}
      <Sheet open={!!form} onClose={() => setForm(null)} title={form && form.id ? 'Edit product' : 'Add product'}
        footer={
          <div className="flex gap-2">
            {form && form.id && <Button variant="danger" size="lg" onClick={removeProduct} aria-label="Delete"><Trash2 className="h-5 w-5" /></Button>}
            <Button size="lg" className="flex-1" loading={busy} disabled={!form || !form.name.trim()} onClick={saveProduct}>{form && form.id ? 'Save changes' : 'Add product'}</Button>
          </div>
        }>
        {form && (
          <div className="space-y-4 pb-2">
            <Field label="Product name"><Input autoFocus value={form.name} onChange={set('name')} placeholder="e.g. Peak Milk Tin 170g" /></Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Cost price"><MoneyInput value={form.cost_price} onChange={set('cost_price')} placeholder="0" /></Field>
              <Field label="Selling price"><MoneyInput value={form.sale_price} onChange={set('sale_price')} placeholder="0" /></Field>
            </div>
            {margin !== null && <p className={`-mt-2 text-xs ${margin < 0 ? 'text-red-600' : 'text-brand-700'}`}>Profit per unit {money(num(form.sale_price) - num(form.cost_price))} ({margin}% margin)</p>}
            <div className="grid grid-cols-2 gap-3">
              {!form.id && <Field label="Quantity in stock"><IntInput value={form.quantity} onChange={set('quantity')} placeholder="0" /></Field>}
              <Field label="Low-stock alert at"><IntInput value={form.reorder_level} onChange={set('reorder_level')} /></Field>
            </div>
            <Field label="SKU / barcode (optional)"><Input value={form.sku || ''} onChange={set('sku')} /></Field>
            {form.id && <p className="text-sm text-slate-400">In stock: <b className="text-slate-600">{list.find((x) => x.id === form.id)?.quantity}</b>. Use “Update stock” on the list to add or remove units.</p>}
          </div>
        )}
      </Sheet>

      {/* Update stock */}
      <Sheet open={!!stock} onClose={() => setStock(null)} title={stock ? stock.p.name : ''}
        footer={<Button size="lg" className="w-full" loading={busy} onClick={saveStock}>{stock && stock.mode === 'add' ? 'Add to stock' : 'Remove from stock'}</Button>}>
        {stock && (
          <div className="space-y-4 pb-2">
            <p className="text-sm text-slate-400">Currently <b className="text-slate-700">{stock.p.quantity}</b> in stock</p>
            <Segmented value={stock.mode} onChange={(mode) => setStock({ ...stock, mode })} options={[{ value: 'add', label: 'Add stock' }, { value: 'remove', label: 'Remove (damaged/lost)' }]} />
            <Field label="Quantity"><IntInput autoFocus value={stock.qty} onChange={(e) => setStock({ ...stock, qty: e.target.value })} placeholder="0" /></Field>
            {stock.mode === 'add' && <Field label="New cost price (optional)" hint="Only fill this if the supplier price changed"><MoneyInput value={stock.cost} onChange={(e) => setStock({ ...stock, cost: e.target.value })} placeholder={String(num(stock.p.cost_price))} /></Field>}
          </div>
        )}
      </Sheet>
    </div>
  );
}

export default function InventoryPage() {
  return <Suspense fallback={<PageLoader />}><Inventory /></Suspense>;
}
