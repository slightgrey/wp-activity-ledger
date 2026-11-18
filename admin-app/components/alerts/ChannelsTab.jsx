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
import { __, sprintf } from '@/lib/i18n';

export const CHANNEL_TYPES = {
  email: { label: __( 'Email', 'wp-activity-ledger' ), hint: __( 'Sends an HTML email to each recipient.', 'wp-activity-ledger' ) },
  webhook: { label: __( 'Webhook', 'wp-activity-ledger' ), hint: __( 'POSTs signed JSON (X-WPAL-Signature) to your endpoint.', 'wp-activity-ledger' ) },
  slack: { label: __( 'Slack', 'wp-activity-ledger' ), hint: __( 'Paste a Slack incoming-webhook URL.', 'wp-activity-ledger' ) },
  discord: { label: __( 'Discord', 'wp-activity-ledger' ), hint: __( 'Paste a Discord channel webhook URL.', 'wp-activity-ledger' ) },
  teams: { label: __( 'Microsoft Teams', 'wp-activity-ledger' ), hint: __( 'Paste a Teams Workflows (or incoming) webhook URL.', 'wp-activity-ledger' ) },
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
          toast({ title: channel ? __( 'Channel saved', 'wp-activity-ledger' ) : __( 'Channel added', 'wp-activity-ledger' ) });
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
            <DialogTitle>{channel ? __( 'Edit channel', 'wp-activity-ledger' ) : __( 'Add a channel', 'wp-activity-ledger' )}</DialogTitle>
            <DialogDescription>{CHANNEL_TYPES[form.type].hint}</DialogDescription>
          </DialogHeader>

          <div className="grid gap-1.5">
            <Label>{__( 'Type', 'wp-activity-ledger' )}</Label>
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
            <Label htmlFor="wpal-ch-name">{__( 'Name', 'wp-activity-ledger' )}</Label>
            <Input id="wpal-ch-name" value={form.name} onChange={set('name')} placeholder={__( 'e.g. Admins', 'wp-activity-ledger' )} />
            <FieldError errors={errors} field="name" />
          </div>

          {form.type === 'email' ? (
            <div className="grid gap-1.5">
              <Label htmlFor="wpal-ch-recipients">{__( 'Recipients', 'wp-activity-ledger' )}</Label>
              <Input id="wpal-ch-recipients" value={form.recipients} onChange={set('recipients')} placeholder={__( 'admin@example.com, ops@example.com', 'wp-activity-ledger' )} />
              <FieldError errors={errors} field="config.recipients" />
            </div>
          ) : (
            <div className="grid gap-1.5">
              <Label htmlFor="wpal-ch-url">{__( 'Webhook URL', 'wp-activity-ledger' )}</Label>
              <Input id="wpal-ch-url" value={form.url} onChange={set('url')} placeholder={__( 'https://…', 'wp-activity-ledger' )} />
              {channel && <p className="text-xs text-muted-foreground">{__( 'Stored URLs are hidden. Leave it as is to keep the current one.', 'wp-activity-ledger' )}</p>}
              <FieldError errors={errors} field="config.url" />
            </div>
          )}

          {form.type === 'webhook' && (
            <div className="grid gap-1.5">
              <Label htmlFor="wpal-ch-secret">{__( 'Signing secret', 'wp-activity-ledger' )}</Label>
              <div className="flex gap-2">
                <Input id="wpal-ch-secret" value={form.secret} onChange={set('secret')} placeholder={__( 'Generated when empty', 'wp-activity-ledger' )} />
                <Button type="button" variant="outline" onClick={() => setForm({ ...form, secret: newSecret() })}>
                  <KeyRound className="h-4 w-4" />
                  {__( 'Generate', 'wp-activity-ledger' )}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">{__( 'Copy a new secret now; it is hidden after saving.', 'wp-activity-ledger' )}</p>
            </div>
          )}

          {save.isError && !Object.keys(errors).length && <p className="text-sm text-destructive">{save.error.message}</p>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              {__( 'Cancel', 'wp-activity-ledger' )}
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {channel ? __( 'Save', 'wp-activity-ledger' ) : __( 'Add channel', 'wp-activity-ledger' )}
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
          ? toast({ title: __( 'Test alert sent', 'wp-activity-ledger' ), description: sprintf( __( 'Check %s.', 'wp-activity-ledger' ), channel.name ) })
          : toast({ title: __( 'Test alert failed', 'wp-activity-ledger' ), description: result.error, variant: 'destructive' }),
      onError: (error) => toast({ title: __( 'Test alert failed', 'wp-activity-ledger' ), description: error.message, variant: 'destructive' }),
    });

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">{__( 'Where alerts are sent. Rules pick one or more channels.', 'wp-activity-ledger' )}</p>
        <Button onClick={() => setEditing({})}>
          <Plus className="h-4 w-4" />
          {__( 'Add channel', 'wp-activity-ledger' )}
        </Button>
      </div>

      <div className="divide-y rounded-lg border bg-card">
        {channels.isLoading && <Skeleton className="m-4 h-10" />}
        {channels.data?.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">{__( 'No channels yet. Add one to start sending alerts.', 'wp-activity-ledger' )}</p>}
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
                {__( 'Send test', 'wp-activity-ledger' )}
              </Button>
              <Button variant="ghost" size="icon" aria-label={__( 'Edit', 'wp-activity-ledger' )} onClick={() => setEditing(channel)}>
                <Pencil className="h-4 w-4" />
              </Button>
              <Button variant="ghost" size="icon" aria-label={__( 'Delete', 'wp-activity-ledger' )} onClick={() => setDeleting(channel)}>
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
            <DialogTitle>{__( 'Delete “', 'wp-activity-ledger' )}{deleting?.name}”?</DialogTitle>
            <DialogDescription>{__( 'Rules that use this channel stop sending to it.', 'wp-activity-ledger' )}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleting(null)}>
              {__( 'Cancel', 'wp-activity-ledger' )}
            </Button>
            <Button
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })}
            >
              {__( 'Delete', 'wp-activity-ledger' )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
