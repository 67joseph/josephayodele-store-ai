export const num = (v) => Number(v) || 0;

export function makeMoney(currency = 'NGN') {
  const f = new Intl.NumberFormat('en', { style: 'currency', currency, currencyDisplay: 'narrowSymbol', maximumFractionDigits: 2, minimumFractionDigits: 0 });
  return (v) => f.format(num(v));
}

export const ymd = (d = new Date()) => {
  const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
  return z.toISOString().slice(0, 10);
};
export const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return ymd(d); };
export const monthStart = () => { const d = new Date(); d.setDate(1); return ymd(d); };

export const shortDate = (s) => new Date(String(s).replace(' ', 'T')).toLocaleDateString('en', { day: 'numeric', month: 'short' });
export const dateTime = (s) => new Date(String(s).replace(' ', 'T')).toLocaleString('en', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
export const MODE_LABEL = { cash: 'Cash', card: 'Card', transfer: 'Transfer', credit: 'Credit' };

export const CURRENCY_OPTIONS = [
  ['NGN', 'Naira (₦)'], ['GHS', 'Ghana Cedi (₵)'], ['KES', 'Kenya Shilling (KSh)'], ['ZAR', 'Rand (R)'],
  ['UGX', 'Uganda Shilling'], ['TZS', 'Tanzania Shilling'], ['XOF', 'CFA Franc'], ['USD', 'US Dollar ($)'], ['GBP', 'Pound (£)'], ['EUR', 'Euro (€)'],
];

