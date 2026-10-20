'use client';
import { useState } from 'react';
import { num, shortDate } from '@/lib/format';

// Fills gaps so days without sales show as empty bars.
export function fillDays(rows, from, to) {
  const map = {};
  rows.forEach((r) => { map[r.day] = r; });
  const out = [];
  const d = new Date(from + 'T00:00:00');
  const end = new Date(to + 'T00:00:00');
  let guard = 0;
  while (d <= end && guard++ < 400) {
    const k = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    out.push({ day: k, revenue: num(map[k]?.revenue), profit: num(map[k]?.profit) });
    d.setDate(d.getDate() + 1);
  }
  return out;
}

// Revenue bars with a profit overlay; tap/hover a bar to read it.
export function DayBars({ data, money, height = 140 }) {
  const [active, setActive] = useState(null);
  const max = Math.max(1, ...data.map((d) => d.revenue));
  const shown = active != null ? data[active] : null;
  const labelEvery = Math.ceil(data.length / 7);
  return (
    <div>
      <div className="mb-2 flex h-5 items-center justify-between text-xs text-slate-500">
        {shown ? (
          <>
            <span className="font-medium text-slate-700">{shortDate(shown.day)}</span>
            <span>Sales <b className="text-slate-800">{money(shown.revenue)}</b> · Profit <b className="text-brand-700">{money(shown.profit)}</b></span>
          </>
        ) : (
          <>
            <span className="flex items-center gap-3">
              <span className="flex items-center gap-1"><i className="h-2 w-2 rounded-sm bg-brand-200" />Sales</span>
              <span className="flex items-center gap-1"><i className="h-2 w-2 rounded-sm bg-brand-600" />Profit</span>
            </span>
            <span className="text-slate-400">Tap a bar</span>
          </>
        )}
      </div>
      <div className="flex items-end gap-[3px]" style={{ height }} onMouseLeave={() => setActive(null)}>
        {data.map((d, i) => (
          <button
            key={d.day}
            type="button"
            aria-label={`${d.day} sales ${d.revenue}`}
            onClick={() => setActive(active === i ? null : i)}
            onMouseEnter={() => setActive(i)}
            className="group relative flex h-full flex-1 items-end"
          >
            <span className={`relative block w-full rounded-t ${active === i ? 'bg-brand-300' : 'bg-brand-200'}`} style={{ height: `${Math.max(d.revenue ? 3 : 0, (d.revenue / max) * 100)}%` }}>
              <span className="absolute inset-x-0 bottom-0 block rounded-t bg-brand-600" style={{ height: `${d.revenue ? Math.max(0, Math.min(100, (d.profit / d.revenue) * 100)) : 0}%` }} />
            </span>
            {!d.revenue && <span className="absolute inset-x-0 bottom-0 h-px bg-slate-200" />}
          </button>
        ))}
      </div>
      <div className="mt-1.5 flex gap-[3px] text-[10px] text-slate-400">
        {data.map((d, i) => (
          <span key={d.day} className="flex-1 overflow-visible whitespace-nowrap text-center">{i % labelEvery === 0 ? shortDate(d.day) : ''}</span>
        ))}
      </div>
    </div>
  );
}

// Horizontal share bar list.
export function ShareBars({ rows, money, valueKey = 'total', labelKey = 'label' }) {
  const max = Math.max(1, ...rows.map((r) => num(r[valueKey])));
  return (
    <div className="space-y-3">
      {rows.map((r) => (
        <div key={r[labelKey]}>
          <div className="mb-1 flex justify-between text-sm">
            <span className="text-slate-600">{r[labelKey]}</span>
            <span className="font-medium tabular-nums">{money(r[valueKey])}</span>
          </div>
          <div className="h-2 rounded-full bg-slate-100">
            <div className="h-2 rounded-full bg-brand-500" style={{ width: `${(num(r[valueKey]) / max) * 100}%` }} />
          </div>
        </div>
      ))}
    </div>
  );
}
