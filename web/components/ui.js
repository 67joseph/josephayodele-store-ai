'use client';
import { createContext, forwardRef, useCallback, useContext, useEffect, useState } from 'react';
import { Loader2, X, Inbox } from 'lucide-react';

export const cn = (...a) => a.filter(Boolean).join(' ');

// ───────── Buttons & fields ─────────
const VARIANTS = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 active:bg-brand-800 shadow-sm',
  soft: 'bg-brand-50 text-brand-700 hover:bg-brand-100',
  ghost: 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50',
  danger: 'bg-red-50 text-red-600 hover:bg-red-100',
};
const SIZES = { sm: 'h-9 px-3 text-sm', md: 'h-11 px-4 text-[15px]', lg: 'h-12 px-5 text-base' };

export function Button({ variant = 'primary', size = 'md', loading, className, children, disabled, ...p }) {
  return (
    <button
      disabled={disabled || loading}
      className={cn('inline-flex items-center justify-center gap-2 rounded-xl font-medium transition disabled:opacity-50 disabled:pointer-events-none', VARIANTS[variant], SIZES[size], className)}
      {...p}
    >
      {loading && <Loader2 className="h-4 w-4 animate-spin" />}
      {children}
    </button>
  );
}

const fieldCls = 'w-full rounded-xl border border-slate-200 bg-white px-3.5 h-11 text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500/40 focus:border-brand-500';
export const Input = forwardRef(function Input({ className, ...p }, ref) {
  return <input ref={ref} className={cn(fieldCls, className)} {...p} />;
});
export function Select({ className, children, ...p }) {
  return <select className={cn(fieldCls, 'pr-8', className)} {...p}>{children}</select>;
}
export function MoneyInput(p) {
  return <Input type="number" inputMode="decimal" min="0" step="any" {...p} />;
}
export function IntInput(p) {
  return <Input type="number" inputMode="numeric" min="0" step="1" {...p} />;
}
export function Field({ label, hint, className, children }) {
  return (
    <label className={cn('block', className)}>
      {label && <span className="mb-1.5 block text-sm font-medium text-slate-600">{label}</span>}
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-400">{hint}</span>}
    </label>
  );
}

// ───────── Layout atoms ─────────
export function Card({ className, children, ...p }) {
  return <div className={cn('rounded-2xl border border-slate-100 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]', className)} {...p}>{children}</div>;
}

const TONES = {
  green: 'bg-emerald-50 text-emerald-700',
  red: 'bg-red-50 text-red-600',
  amber: 'bg-amber-50 text-amber-700',
  slate: 'bg-slate-100 text-slate-600',
  blue: 'bg-sky-50 text-sky-700',
};
export function Badge({ tone = 'slate', children, className }) {
  return <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', TONES[tone], className)}>{children}</span>;
}

export function Spinner({ className }) {
  return <Loader2 className={cn('h-5 w-5 animate-spin text-brand-600', className)} />;
}
export function PageLoader() {
  return <div className="flex justify-center py-16"><Spinner className="h-7 w-7" /></div>;
}

export function Empty({ icon: Icon = Inbox, title, text, action }) {
  return (
    <div className="flex flex-col items-center px-6 py-12 text-center">
      <div className="mb-3 grid h-12 w-12 place-items-center rounded-2xl bg-slate-100 text-slate-400"><Icon className="h-6 w-6" /></div>
      <p className="font-medium text-slate-700">{title}</p>
      {text && <p className="mt-1 max-w-xs text-sm text-slate-400">{text}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function Segmented({ value, onChange, options, className }) {
  return (
    <div className={cn('grid gap-1 rounded-xl bg-slate-100 p-1', className)} style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0,1fr))` }}>
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          onClick={() => onChange(o.value)}
          className={cn('h-9 rounded-lg px-2 text-sm font-medium transition', value === o.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700')}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

export function Stat({ label, value, sub, tone }) {
  const color = tone === 'red' ? 'text-red-600' : tone === 'green' ? 'text-brand-700' : 'text-slate-900';
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className={cn('mt-1 text-xl font-semibold tabular-nums sm:text-2xl', color)}>{value}</p>
      {sub && <p className="mt-0.5 text-xs text-slate-400">{sub}</p>}
    </Card>
  );
}

export function PageHeader({ title, sub, right }) {
  return (
    <div className="mb-4 flex items-end justify-between gap-3">
      <div className="min-w-0">
        <h1 className="truncate text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
        {sub && <p className="text-sm text-slate-400">{sub}</p>}
      </div>
      {right}
    </div>
  );
}

// ───────── Sheet (bottom sheet on phones, dialog on desktop) ─────────
export function Sheet({ open, onClose, title, children, footer }) {
  useEffect(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const esc = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', esc);
    return () => { document.body.style.overflow = prev; window.removeEventListener('keydown', esc); };
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center sm:items-center">
      <div className="absolute inset-0 bg-slate-900/40 backdrop-blur-[1px]" onClick={onClose} />
      <div className="relative flex max-h-[92dvh] w-full flex-col rounded-t-3xl bg-white shadow-xl sm:max-w-md sm:rounded-3xl">
        <div className="flex items-center justify-between px-5 pb-2 pt-4">
          <h2 className="text-lg font-semibold">{title}</h2>
          <button onClick={onClose} aria-label="Close" className="grid h-9 w-9 place-items-center rounded-full text-slate-400 hover:bg-slate-100"><X className="h-5 w-5" /></button>
        </div>
        <div className="overflow-y-auto px-5 pb-4 pt-1">{children}</div>
        {footer && <div className="border-t border-slate-100 px-5 pb-safe pt-3">{footer}<div className="h-2" /></div>}
      </div>
    </div>
  );
}

// ───────── Toasts ─────────
const ToastCtx = createContext(() => {});
export const useToast = () => useContext(ToastCtx);

export function ToastProvider({ children }) {
  const [items, setItems] = useState([]);
  const push = useCallback((msg, tone = 'ok') => {
    const id = Math.random().toString(36).slice(2);
    setItems((l) => [...l, { id, msg, tone }]);
    setTimeout(() => setItems((l) => l.filter((x) => x.id !== id)), tone === 'err' ? 5000 : 2800);
  }, []);
  return (
    <ToastCtx.Provider value={push}>
      {children}
      <div className="pointer-events-none fixed inset-x-0 top-3 z-[60] flex flex-col items-center gap-2 px-4">
        {items.map((t) => (
          <div key={t.id} className={cn('pointer-events-auto max-w-sm rounded-xl px-4 py-2.5 text-sm font-medium text-white shadow-lg', t.tone === 'err' ? 'bg-red-600' : 'bg-slate-900')}>
            {t.msg}
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}
