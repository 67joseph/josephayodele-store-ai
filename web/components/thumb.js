import { assetUrl } from '@/lib/api';

export function Thumb({ p, size = 'h-12 w-12' }) {
  const url = assetUrl(p.image_path);
  return url ? (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={url} alt="" className={`${size} shrink-0 rounded-xl bg-slate-100 object-cover`} />
  ) : (
    <div className={`${size} grid shrink-0 place-items-center rounded-xl bg-brand-50 font-semibold text-brand-700`}>{p.name.charAt(0).toUpperCase()}</div>
  );
}
