import './globals.css';
import { AuthProvider } from '@/components/auth';
import { ToastProvider } from '@/components/ui';

export const metadata = {
  title: 'Store-AI — sales, stock & profit',
  description: 'Simple sales, inventory and profit tracking for small stores, with AI.',
};
export const viewport = { width: 'device-width', initialScale: 1, themeColor: '#059669' };

export default function RootLayout({ children }) {
  return (
    <html lang="en">
      <body>
        <ToastProvider>
          <AuthProvider>{children}</AuthProvider>
        </ToastProvider>
      </body>
    </html>
  );
}
