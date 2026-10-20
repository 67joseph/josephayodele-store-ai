'use client';
import { useCallback, useEffect, useRef, useState } from 'react';
import { History, Send, Sparkles, SquarePen } from 'lucide-react';
import { api } from '@/lib/api';
import { useAuth } from '@/components/auth';
import { Markdown } from '@/components/markdown';
import { cn, Sheet, useToast } from '@/components/ui';

const SUGGESTIONS = [
  'How did I do this week compared to last week?',
  'Which products make me the most profit?',
  'What should I restock soon?',
  'Who owes me money and what is overdue?',
  'Why is my net profit low this month?',
];

export default function AssistantPage() {
  const { store } = useAuth();
  const toast = useToast();
  const [sessionId, setSessionId] = useState(null);
  const [msgs, setMsgs] = useState([]);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [past, setPast] = useState(null);
  const endRef = useRef(null);

  useEffect(() => { endRef.current && endRef.current.scrollIntoView({ behavior: 'smooth', block: 'end' }); }, [msgs, busy]);

  const send = useCallback(async (override) => {
    const message = (override ?? text).trim();
    if (!message || busy) return;
    setText('');
    setMsgs((m) => [...m, { role: 'user', content: message }]);
    setBusy(true);
    try {
      const d = await api('/chat', { method: 'POST', body: { message, session_id: sessionId } });
      setSessionId(d.session_id);
      setMsgs((m) => [...m, { role: 'assistant', content: d.reply }]);
    } catch (e) {
      setMsgs((m) => [...m, { role: 'assistant', content: e.message, error: true }]);
      toast(e.message, 'err');
    } finally { setBusy(false); }
  }, [text, busy, sessionId, toast]);

  async function openHistory() {
    setPast([]);
    try { setPast((await api('/chat/sessions')).sessions); } catch (e) { toast(e.message, 'err'); setPast(null); }
  }
  async function loadSession(id) {
    try {
      const d = await api(`/chat/sessions/${id}`);
      setSessionId(id); setMsgs(d.messages); setPast(null);
    } catch (e) { toast(e.message, 'err'); }
  }
  const fresh = () => { setSessionId(null); setMsgs([]); };

  return (
    <div className="flex min-h-[calc(100dvh-10rem)] flex-col lg:min-h-[calc(100dvh-6rem)]">
      <div className="mb-3 flex items-center justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight sm:text-2xl"><Sparkles className="h-5 w-5 text-brand-600" />AI analyst</h1>
          <p className="text-sm text-slate-400">Ask anything about {store.name}</p>
        </div>
        <div className="flex gap-1">
          <button onClick={openHistory} className="grid h-10 w-10 place-items-center rounded-full text-slate-500 hover:bg-slate-100" aria-label="Past chats"><History className="h-5 w-5" /></button>
          <button onClick={fresh} className="grid h-10 w-10 place-items-center rounded-full text-slate-500 hover:bg-slate-100" aria-label="New chat"><SquarePen className="h-5 w-5" /></button>
        </div>
      </div>

      <div className="flex-1 space-y-4 pb-4">
        {msgs.length === 0 && (
          <div className="pt-4">
            <p className="mb-3 text-sm text-slate-500">I read your live sales, stock, expenses and debts. Try one of these:</p>
            <div className="flex flex-col gap-2">
              {SUGGESTIONS.map((s) => (
                <button key={s} onClick={() => send(s)} className="rounded-2xl border border-slate-100 bg-white px-4 py-3 text-left text-[15px] text-slate-700 shadow-[0_1px_2px_rgba(15,23,42,0.04)] active:bg-slate-50">{s}</button>
              ))}
            </div>
          </div>
        )}
        {msgs.map((m, i) => (
          <div key={i} className={cn('flex', m.role === 'user' ? 'justify-end' : 'justify-start')}>
            <div className={cn('max-w-[92%] rounded-2xl px-4 py-3', m.role === 'user' ? 'rounded-br-md bg-brand-600 text-white' : m.error ? 'rounded-bl-md bg-red-50 text-red-700' : 'rounded-bl-md border border-slate-100 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]')}>
              {m.role === 'user' || m.error ? <p className="whitespace-pre-wrap text-[15px]">{m.content}</p> : <Markdown text={m.content} />}
            </div>
          </div>
        ))}
        {busy && (
          <div className="flex justify-start"><div className="flex gap-1 rounded-2xl rounded-bl-md border border-slate-100 bg-white px-4 py-3.5">
            {[0, 150, 300].map((d) => <span key={d} className="h-2 w-2 animate-bounce rounded-full bg-brand-400" style={{ animationDelay: `${d}ms` }} />)}
          </div></div>
        )}
        <div ref={endRef} />
      </div>

      <form onSubmit={(e) => { e.preventDefault(); send(); }} className="sticky bottom-[72px] flex items-end gap-2 bg-slate-50 pb-2 pt-2 lg:bottom-0">
        <textarea
          rows={1}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }}
          placeholder="Ask about sales, profit, stock, debts…"
          className="max-h-32 min-h-[44px] flex-1 resize-none rounded-2xl border border-slate-200 bg-white px-4 py-2.5 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/40"
        />
        <button type="submit" disabled={!text.trim() || busy} className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-brand-600 text-white disabled:opacity-40" aria-label="Send"><Send className="h-5 w-5" /></button>
      </form>

      <Sheet open={past !== null} onClose={() => setPast(null)} title="Past conversations">
        <div className="space-y-1 pb-3">
          {past && past.length === 0 && <p className="py-6 text-center text-sm text-slate-400">No conversations yet.</p>}
          {(past || []).map((s) => (
            <button key={s.id} onClick={() => loadSession(s.id)} className="block w-full rounded-xl px-3 py-3 text-left hover:bg-slate-50">
              <span className="block truncate font-medium">{s.title || 'Conversation'}</span>
              <span className="text-xs text-slate-400">{s.created_at}</span>
            </button>
          ))}
        </div>
      </Sheet>
    </div>
  );
}
