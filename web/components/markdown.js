// Tiny renderer for the assistant's replies: paragraphs, "-" bullets, **bold**. No HTML is ever injected.
function inline(text) {
  return text.split(/(\*\*[^*]+\*\*)/g).map((part, i) =>
    part.startsWith('**') && part.endsWith('**') && part.length > 4
      ? <strong key={i} className="font-semibold text-slate-900">{part.slice(2, -2)}</strong>
      : part
  );
}

export function Markdown({ text }) {
  const blocks = [];
  let list = null;
  text.split('\n').forEach((raw, idx) => {
    const line = raw.trimEnd();
    const bullet = line.match(/^\s*[-*•]\s+(.*)$/) || line.match(/^\s*\d+[.)]\s+(.*)$/);
    if (bullet) {
      if (!list) { list = []; blocks.push({ type: 'ul', items: list, key: idx }); }
      list.push(bullet[1]);
    } else {
      list = null;
      if (line.trim()) blocks.push({ type: 'p', text: line.replace(/^#+\s*/, ''), key: idx });
    }
  });
  return (
    <div className="space-y-2 text-[15px] leading-relaxed text-slate-700">
      {blocks.map((b) =>
        b.type === 'ul' ? (
          <ul key={b.key} className="list-disc space-y-1 pl-5 marker:text-brand-500">
            {b.items.map((t, i) => <li key={i}>{inline(t)}</li>)}
          </ul>
        ) : (
          <p key={b.key}>{inline(b.text)}</p>
        )
      )}
    </div>
  );
}
