import * as SheetPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { portalContainer } from '@/lib/portal';

export const Sheet = SheetPrimitive.Root;
export const SheetTrigger = SheetPrimitive.Trigger;
export const SheetClose = SheetPrimitive.Close;

/**
 * A side drawer (Radix dialog) sliding in from the right, below the WP admin bar.
 */
export function SheetContent({ className, children, ...props }) {
  return (
    <SheetPrimitive.Portal container={portalContainer()}>
      <SheetPrimitive.Overlay className="fixed inset-0 z-[100000] bg-black/40" />
      <SheetPrimitive.Content
        className={cn(
          'fixed bottom-0 right-0 top-8 z-[100001] flex w-full max-w-2xl flex-col gap-4 overflow-y-auto border-l bg-background p-6 shadow-lg',
          className
        )}
        {...props}
      >
        {children}
        <SheetPrimitive.Close className="absolute right-4 top-4 rounded-sm opacity-70 hover:opacity-100 focus:outline-none">
          <X className="h-4 w-4" />
          <span className="sr-only">Close</span>
        </SheetPrimitive.Close>
      </SheetPrimitive.Content>
    </SheetPrimitive.Portal>
  );
}

export function SheetHeader({ className, ...props }) {
  return <div className={cn('flex flex-col space-y-1.5 pr-8', className)} {...props} />;
}

export function SheetTitle({ className, ...props }) {
  return <SheetPrimitive.Title className={cn('text-lg font-semibold', className)} {...props} />;
}

export function SheetDescription({ className, ...props }) {
  return <SheetPrimitive.Description className={cn('text-sm text-muted-foreground', className)} {...props} />;
}
