export default function AuthLayout({ children }) {
  return (
    <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-5 py-10">
      <div className="mb-8 text-center">
        <div className="mx-auto mb-3 grid h-14 w-14 place-items-center rounded-2xl bg-brand-600 text-2xl font-bold text-white shadow-lg shadow-brand-600/25">S</div>
        <h1 className="text-2xl font-semibold tracking-tight">Store-AI</h1>
        <p className="text-sm text-slate-400">Sales, stock and profit — made simple.</p>
      </div>
      {children}
    </main>
  );
}
