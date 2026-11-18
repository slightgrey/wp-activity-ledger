import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import * as ToastPrimitive from '@radix-ui/react-toast';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

const ToastContext = createContext(() => {});

/**
 * Provides `useToast()` and renders the toasts (inside #wpal-root, bottom right).
 */
export function Toaster({ children }) {
  const [toasts, setToasts] = useState([]);

  const toast = useCallback(({ title, description = '', variant = 'default' }) => {
    const id = `${Date.now()}-${Math.random()}`;
    setToasts((list) => [...list, { id, title, description, variant }]);
  }, []);

  const remove = useCallback((id) => setToasts((list) => list.filter((t) => t.id !== id)), []);
  const value = useMemo(() => toast, [toast]);

  return (
    <ToastContext.Provider value={value}>
      <ToastPrimitive.Provider swipeDirection="right" duration={5000}>
        {children}
        {toasts.map((t) => (
          <ToastPrimitive.Root
            key={t.id}
            onOpenChange={(open) => !open && remove(t.id)}
            className={cn(
              'relative flex w-full items-start justify-between gap-3 rounded-md border p-4 pr-8 shadow-lg',
              t.variant === 'destructive' ? 'border-destructive bg-destructive text-destructive-foreground' : 'bg-background'
            )}
          >
            <div className="grid gap-1">
              <ToastPrimitive.Title className="text-sm font-semibold">{t.title}</ToastPrimitive.Title>
              {t.description && <ToastPrimitive.Description className="text-sm opacity-90">{t.description}</ToastPrimitive.Description>}
            </div>
            <ToastPrimitive.Close className="absolute right-2 top-2 opacity-70 hover:opacity-100">
              <X className="h-4 w-4" />
            </ToastPrimitive.Close>
          </ToastPrimitive.Root>
        ))}
        <ToastPrimitive.Viewport className="fixed bottom-4 right-4 z-[100020] flex w-[360px] max-w-[100vw] flex-col gap-2" />
      </ToastPrimitive.Provider>
    </ToastContext.Provider>
  );
}

/**
 * Returns the function that shows a toast: toast({ title, description, variant }).
 *
 * @returns {Function} Toast function.
 */
export function useToast() {
  return useContext(ToastContext);
}
