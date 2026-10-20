'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api';
import { CURRENCY_OPTIONS } from '@/lib/format';
import { useAuth } from '@/components/auth';
import { Button, Card, Field, Input, Select, useToast } from '@/components/ui';


export default function RegisterPage() {
  const { enter, user, ready } = useAuth();
  const router = useRouter();
  const toast = useToast();
  const [types, setTypes] = useState([]);
  const [busy, setBusy] = useState(false);
  const [f, setF] = useState({ name: '', email: '', password: '', store_name: '', store_type_id: '', currency: 'NGN' });
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  useEffect(() => { if (ready && user) router.replace('/'); }, [ready, user, router]);
  useEffect(() => { api('/store-types').then((d) => setTypes(d.types)).catch((e) => toast(e.message, 'err')); }, [toast]);

  async function submit(e) {
    e.preventDefault();
    if (!f.store_type_id) return toast('Please pick your store type', 'err');
    setBusy(true);
    try {
      enter(await api('/auth/register', { method: 'POST', body: { ...f, store_type_id: Number(f.store_type_id) } }));
      router.replace('/');
    } catch (err) { toast(err.message, 'err'); setBusy(false); }
  }

  return (
    <Card className="p-5">
      <form onSubmit={submit} className="space-y-4">
        <Field label="Your name"><Input required autoComplete="name" value={f.name} onChange={set('name')} /></Field>
        <Field label="Email"><Input type="email" required autoComplete="email" value={f.email} onChange={set('email')} /></Field>
        <Field label="Password" hint="At least 6 characters"><Input type="password" required minLength={6} autoComplete="new-password" value={f.password} onChange={set('password')} /></Field>
        <hr className="border-slate-100" />
        <Field label="Store name"><Input required value={f.store_name} onChange={set('store_name')} placeholder="e.g. Mama Put Provisions" /></Field>
        <Field label="Store type">
          <Select required value={f.store_type_id} onChange={set('store_type_id')}>
            <option value="">Choose a category…</option>
            {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </Select>
        </Field>
        <Field label="Currency">
          <Select value={f.currency} onChange={set('currency')}>{CURRENCY_OPTIONS.map(([c, l]) => <option key={c} value={c}>{l}</option>)}</Select>
        </Field>
        <Button type="submit" size="lg" className="w-full" loading={busy}>Create my store</Button>
      </form>
      <p className="mt-4 text-center text-sm text-slate-500">Already have an account? <Link href="/login/" className="font-medium text-brand-700">Sign in</Link></p>
    </Card>
  );
}
