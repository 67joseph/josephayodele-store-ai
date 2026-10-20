'use client';
import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api';
import { useAuth } from '@/components/auth';
import { Button, Card, Field, Input, useToast } from '@/components/ui';

export default function LoginPage() {
  const { enter, user, ready } = useAuth();
  const router = useRouter();
  const toast = useToast();
  const [f, setF] = useState({ email: '', password: '' });
  const [busy, setBusy] = useState(false);

  useEffect(() => { if (ready && user) router.replace('/'); }, [ready, user, router]);

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    try {
      enter(await api('/auth/login', { method: 'POST', body: f }));
      router.replace('/');
    } catch (err) { toast(err.message, 'err'); setBusy(false); }
  }

  return (
    <Card className="p-5">
      <form onSubmit={submit} className="space-y-4">
        <Field label="Email"><Input type="email" autoComplete="email" required value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="Password"><Input type="password" autoComplete="current-password" required value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} /></Field>
        <Button type="submit" size="lg" className="w-full" loading={busy}>Sign in</Button>
      </form>
      <p className="mt-4 text-center text-sm text-slate-500">New here? <Link href="/register/" className="font-medium text-brand-700">Create your store</Link></p>
    </Card>
  );
}
