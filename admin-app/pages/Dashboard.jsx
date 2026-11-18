import Placeholder from '@/components/Placeholder';
import { Skeleton } from '@/components/ui/skeleton';
import { useMeta } from '@/hooks/useMeta';

export default function Dashboard() {
  const meta = useMeta();

  return (
    <Placeholder title="Dashboard">
      {meta.isLoading && <Skeleton className="h-4 w-48" />}
      {meta.isError && <p className="text-sm text-destructive">{meta.error.message}</p>}
      {meta.data && (
        <p className="text-sm text-muted-foreground">
          {meta.data.event_types.length} event types from {meta.data.integrations.length + 1} sources are being recorded.
        </p>
      )}
    </Placeholder>
  );
}
