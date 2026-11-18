import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@/components/ui/toast';
import FieldError from '@/components/FieldError';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { siteDate } from '@/lib/format';

const MODES = [
  { value: 'before', label: 'Events older than a date' },
  { value: 'all', label: 'All events' },
];

/**
 * Deletes logged events after the admin types PURGE. The purge itself stays in the log.
 */
export default function PurgePanel() {
  const toast = useToast();
  const client = useQueryClient();
  const [mode, setMode] = useState('before');
  const [before, setBefore] = useState(() => siteDate(config.timezone));
  const [confirm, setConfirm] = useState('');

  const purge = useMutation({
    mutationFn: async () => (await api('/purge', { method: 'POST', body: { mode, before, confirm } })).data,
    onSuccess: (result) => {
      toast({ title: `${result.deleted.toLocaleString()} events deleted` });
      setConfirm('');
      client.invalidateQueries({ queryKey: ['events'] });
      client.invalidateQueries({ queryKey: ['stats'] });
    },
  });

  return (
    <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4">
      <div className="mb-1 flex items-center gap-2 font-medium text-destructive">
        <Trash2 className="h-4 w-4" /> Purge logs
      </div>
      <p className="mb-3 text-xs text-muted-foreground">Deleted events can&apos;t be restored. The purge itself is recorded and stays in the log.</p>

      <div className="grid gap-3">
        <div className="flex flex-wrap gap-4" role="radiogroup" aria-label="What to delete">
          {MODES.map((m) => (
            <label key={m.value} className="flex items-center gap-2 text-sm">
              <input type="radio" name="wpal-purge-mode" value={m.value} checked={mode === m.value} onChange={() => setMode(m.value)} />
              {m.label}
            </label>
          ))}
        </div>

        {mode === 'before' && (
          <div className="flex items-center gap-2">
            <Label htmlFor="wpal-purge-before" className="text-sm font-normal">
              Delete events before
            </Label>
            <Input id="wpal-purge-before" type="date" className="w-44" value={before} onChange={(e) => setBefore(e.target.value)} />
            <span className="text-xs text-muted-foreground">(site time, start of that day)</span>
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2">
          <Label htmlFor="wpal-purge-confirm" className="text-sm font-normal">
            Type <code className="font-semibold">PURGE</code> to confirm
          </Label>
          <Input id="wpal-purge-confirm" className="w-32" value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="off" />
          <Button variant="destructive" disabled={confirm !== 'PURGE' || purge.isPending} onClick={() => purge.mutate()}>
            Purge
          </Button>
        </div>
        <FieldError errors={purge.error?.data?.errors} field="confirm" />
        {purge.isError && !purge.error?.data?.errors && <p className="text-sm text-destructive">{purge.error.message}</p>}
      </div>
    </div>
  );
}
