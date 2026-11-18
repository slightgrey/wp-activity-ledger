import { cva } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const badgeVariants = cva(
  'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium transition-colors',
  {
    variants: {
      variant: {
        default: 'border-transparent bg-primary text-primary-foreground',
        secondary: 'border-transparent bg-secondary text-secondary-foreground',
        destructive: 'border-transparent bg-destructive text-destructive-foreground',
        outline: 'text-foreground',
        info: 'border-transparent bg-slate-100 text-slate-700',
        notice: 'border-transparent bg-blue-100 text-blue-800',
        warning: 'border-transparent bg-amber-100 text-amber-800',
        critical: 'border-transparent bg-red-100 text-red-800',
        success: 'border-transparent bg-green-100 text-green-800',
      },
    },
    defaultVariants: { variant: 'default' },
  }
);

export function Badge({ className, variant, ...props }) {
  return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}
