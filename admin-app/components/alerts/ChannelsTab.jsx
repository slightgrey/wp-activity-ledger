import { useState } from 'react';
import { KeyRound, Mail, Pencil, Plus, Send, Trash2, Webhook } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast';
import FieldError from '@/components/FieldError';
import { useAlertItems, useAlertMutations, useTestChannel } from '@/hooks/useAlerts';

export const CHANNEL_TYPES = {
  email: { label: 'Email', hint: 'Sends an HTML email to each recipient.' },
  webhook: { label: 'Webhook', hint: 'POSTs signed JSON (X-WPAL-Signature) to your endpoint.' },
  slack: { label: 'Slack', hint: 'Paste a Slack incoming-webhook URL.' },
  discord: { label: 'Discord', hint: 'Paste a Discord channel webhook URL.' },
  teams: { label: 'Microsoft Teams', hint: 'Paste a Teams Workflows (or incoming) webhook URL.' },
};

/**
 * Returns a random signing secret.
 */
function newSecret() {
  const bytes = new Uint8Array(24);
  crypto.getRandomValues(bytes);

  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * A short description of where a channel sends to.
 */
function target(channel) {
  return channel.type === 'email' ? channel.config.recipients?.join(', ') : channel.config.url;
}

/**
 * Add/edit dialog for a channel.
 */
function ChannelDialog({ channel, onClose }) {
  const toast = useToast();
  const { save } = useAlertMutations('channels');
  const [form, setForm] = useState(() => ({
    type: channel?.type ?? 'email',
    name: channel?.name ?? '',
    recipients: channel?.config?.recipients?.join(', ') ?? '',
    url: channel?.config?.url ?? '',
    secret: channel?.config?.secret ?? '',
  }));
  const errors = save.error?.data?.errors ?? {};
  const set = (key) => (e) => setForm({ ...form, [key]: e.target.value });

  const submit = (e) => {
    e.preventDefault();

    const config = form.type === 'email' ? { recipients: form.recipients } : { url: form.url, ...(form.type === 'webhook' ? { secret: form.secret } : {}) };

    save.mutate(
      { id: channel?.id, type: form.type, name: form.name, config },
      {
        onSuccess: () => {
          toast({ title: channel ? 'Channel saved' : 'Channel added' });
          onClose();
        },
      }
    );
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <form onSubmit={submit} className="grid gap-4">
          <DialogHeader>
            <DialogTitle>{channel ? 'Edit channel' : 'Add a channel'}</DialogTitle>
            <DialogDescription>{CHANNEL_TYPES[form.type].hint}</DialogDescription>
          </DialogHeader>

          <div className="grid gap-1.5">
            <Label>Type</Label>
            <Select value={form.type} onValueChange={(type) => setForm({ ...form, type })} disabled={Boolean(channel)}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {Object.entries(CHANNEL_TYPES).map(([value, { label }]) => (
                  <SelectItem key={value} value={value}>
                    {label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="wpal-ch-name">Name</Label>
            <Input id="wpal-ch-name" value={form.name} onChange={set('name')} placeholder="e.g. Admins" />
            <FieldError errors={errors} field="name" />
          </div>

          {form.type === 'email' ? (
            <div className="grid gap-1.5">
              <Label htmlFor="wpal-ch-recipients">Recipients</Label>
              <Input id="wpal-ch-recipients" value={form.recipients} onChange={set('recipients')} placeholder="admin@example.com, ops@example.com" />
              <FieldError errors={errors} field="config.recipients" />
            </div>
          ) : (
            <div className="grid gap-1.5">
              <Label htmlFor="wpal-ch-url">Webhook URL</Label>
              <Input id="wpal-ch-url" value={form.url} onChange={set('url')} placeholder="https://…" />
              {channel && <p className="text-xs text-muted-foreground">Stored URLs are hidden. Leave it as is to keep the current one.</p>}
              <FieldError errors={errors} field="config.url" />
            </div>
          )}

          {form.type === 'webhook' && (
            <div className="grid gap-1.5">
              <Label htmlFor="wpal-ch-secret">Signing secret</Label>
              <div className="flex gap-2">
                <Input id="wpal-ch-secret" value={form.secret} onChange={set('secret')} placeholder="Generated when empty" />
                <Button type="button" variant="outline" onClick={() => setForm({ ...form, secret: newSecret() })}>
                  <KeyRound className="h-4 w-4" />
                  Generate
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">Copy a new secret now; it is hidden after saving.</p>
            </div>
          )}

          {save.isError && !Object.keys(errors).length && <p className="text-sm text-destructive">{save.error.message}</p>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {channel ? 'Save' : 'Add channel'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Channel list with add, edit, delete and "Send test".
 */
export default function ChannelsTab() {
  const toast = useToast();
  const channels = useAlertItems('channels');
  const { remove } = useAlertMutations('channels');
  const test = useTestChannel();
  const [editing, setEditing] = useState(null);
  const [deleting, setDeleting] = useState(null);

  const sendTest = (channel) =>
    test.mutate(channel.id, {
      onSuccess: (result) =>
        result.ok
          ? toast({ title: 'Test alert sent', description: `Check ${channel.name}.` })
          : toast({ title: 'Test alert failed', description: result.error, variant: 'destructive' }),
      onError: (error) => toast({ title: 'Test alert failed', description: error.message, variant: 'destructive' }),
    });

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">Where alerts are sent. Rules pick one or more channels.</p>
        <Button onClick={() => setEditing({})}>
          <Plus className="h-4 w-4" />
          Add channel
        </Button>
      </div>

      <div className="divide-y rounded-lg border bg-card">
        {channels.isLoading && <Skeleton className="m-4 h-10" />}
        {channels.data?.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">No channels yet. Add one to start sending alerts.</p>}
        {channels.data?.map((channel) => (
          <div key={channel.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
            <div className="flex min-w-0 items-center gap-3">
              {channel.type === 'email' ? <Mail className="h-5 w-5 text-muted-foreground" /> : <Webhook className="h-5 w-5 text-muted-foreground" />}
              <div className="min-w-0 leading-tight">
                <div className="flex items-center gap-2 font-medium">
                  {channel.name} <Badge variant="outline">{CHANNEL_TYPES[channel.type]?.label ?? channel.type}</Badge>
                </div>
                <div className="truncate text-xs text-muted-foreground">{target(channel)}</div>
              </div>
            </div>
            <div className="flex gap-1">
              <Button variant="outline" size="sm" disabled={test.isPending} onClick={() => sendTest(channel)}>
                <Send className="h-4 w-4" />
                Send test
              </Button>
              <Button variant="ghost" size="icon" aria-label="Edit" onClick={() => setEditing(channel)}>
                <Pencil className="h-4 w-4" />
              </Button>
              <Button variant="ghost" size="icon" aria-label="Delete" onClick={() => setDeleting(channel)}>
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          </div>
        ))}
      </div>

      {editing && <ChannelDialog channel={editing.id ? editing : null} onClose={() => setEditing(null)} />}

      <Dialog open={Boolean(deleting)} onOpenChange={(open) => !open && setDeleting(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete “{deleting?.name}”?</DialogTitle>
            <DialogDescription>Rules that use this channel stop sending to it.</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })}
            >
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
