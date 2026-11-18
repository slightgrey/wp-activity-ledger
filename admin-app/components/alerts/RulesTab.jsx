import { useMemo, useState } from 'react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useToast } from '@/components/ui/toast';
import FieldError from '@/components/FieldError';
import MultiSelect from '@/components/MultiSelect';
import { useAlertItems, useAlertMutations } from '@/hooks/useAlerts';
import { useMeta } from '@/hooks/useMeta';
import { categoryLabel, typeLabel } from '@/lib/format';

const SEVERITIES = [
  { value: '0', label: 'Any severity' },
  { value: '1', label: 'Notice and up' },
  { value: '2', label: 'Warning and up' },
  { value: '3', label: 'Critical only' },
];

/**
 * Describes what a rule matches, in one line.
 */
function summary(rule, meta) {
  const parts = [];
  const { event_types: types, categories, min_severity: severity, roles } = rule.match;

  if (types.length) parts.push(types.length > 2 ? `${types.length} event types` : types.map((t) => typeLabel(t, meta)).join(', '));
  if (categories.length) parts.push(categories.map((c) => categoryLabel(c, meta)).join(', '));
  if (severity > 0) parts.push(SEVERITIES[severity].label.toLowerCase());
  if (roles.length) parts.push(`by ${roles.join(', ')}`);
  if (rule.threshold) parts.push(`${rule.threshold.count}× in ${rule.threshold.minutes} min per ${rule.threshold.group_by}`);

  return parts.join(' · ');
}

/**
 * A labelled form row.
 */
function Field({ label, children, hint }) {
  return (
    <div className="grid gap-1.5">
      <Label>{label}</Label>
      {children}
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/**
 * Add/edit dialog for a rule.
 */
function RuleDialog({ rule, channels, onClose }) {
  const toast = useToast();
  const meta = useMeta().data;
  const { save } = useAlertMutations('rules');
  const [form, setForm] = useState(() => ({
    name: rule?.name ?? '',
    enabled: rule?.enabled ?? true,
    event_types: rule?.match.event_types ?? [],
    categories: rule?.match.categories ?? [],
    min_severity: String(rule?.match.min_severity ?? 0),
    roles: rule?.match.roles ?? [],
    useThreshold: Boolean(rule?.threshold),
    count: String(rule?.threshold?.count ?? 5),
    minutes: String(rule?.threshold?.minutes ?? 10),
    group_by: rule?.threshold?.group_by ?? 'ip',
    cooldown_minutes: String(rule?.cooldown_minutes ?? 0),
    channels: rule?.channels ?? [],
  }));
  const errors = save.error?.data?.errors ?? {};
  const set = (patch) => setForm((f) => ({ ...f, ...patch }));

  const types = useMemo(
    () =>
      (meta?.event_types ?? [])
        .map((t) => ({ value: t.key, label: t.label, group: categoryLabel(t.category, meta) }))
        .sort((a, b) => a.group.localeCompare(b.group) || a.label.localeCompare(b.label)),
    [meta]
  );

  const submit = (e) => {
    e.preventDefault();
    save.mutate(
      {
        id: rule?.id,
        name: form.name,
        enabled: form.enabled,
        match: { event_types: form.event_types, categories: form.categories, min_severity: Number(form.min_severity), roles: form.roles },
        threshold: form.useThreshold ? { count: Number(form.count), minutes: Number(form.minutes), group_by: form.group_by } : null,
        cooldown_minutes: Number(form.cooldown_minutes) || 0,
        channels: form.channels,
      },
      {
        onSuccess: () => {
          toast({ title: rule ? 'Rule saved' : 'Rule added' });
          onClose();
        },
      }
    );
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
        <form onSubmit={submit} className="grid gap-4">
          <DialogHeader>
            <DialogTitle>{rule ? 'Edit rule' : 'Add a rule'}</DialogTitle>
            <DialogDescription>An alert is sent when an event meets every condition you set.</DialogDescription>
          </DialogHeader>

          <Field label="Name">
            <Input value={form.name} onChange={(e) => set({ name: e.target.value })} placeholder="e.g. Plugin changes" />
            <FieldError errors={errors} field="name" />
          </Field>

          <div className="grid gap-4 md:grid-cols-2">
            <Field label="Event types">
              <MultiSelect label="Any event type" options={types} value={form.event_types} onChange={(event_types) => set({ event_types })} />
            </Field>
            <Field label="Categories">
              <MultiSelect
                label="Any category"
                options={(meta?.categories ?? []).map((c) => ({ value: c.slug, label: c.label }))}
                value={form.categories}
                onChange={(categories) => set({ categories })}
              />
            </Field>
            <Field label="Minimum severity">
              <Select value={form.min_severity} onValueChange={(min_severity) => set({ min_severity })}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {SEVERITIES.map((s) => (
                    <SelectItem key={s.value} value={s.value}>
                      {s.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
            <Field label="Done by roles">
              <MultiSelect
                label="Any role"
                options={(meta?.roles ?? []).map((r) => ({ value: r.slug, label: r.name }))}
                value={form.roles}
                onChange={(roles) => set({ roles })}
              />
            </Field>
          </div>
          <FieldError errors={errors} field="match" />

          <div className="grid gap-3 rounded-md border p-3">
            <div className="flex items-center gap-2">
              <Switch id="wpal-rule-threshold" checked={form.useThreshold} onCheckedChange={(useThreshold) => set({ useThreshold })} />
              <Label htmlFor="wpal-rule-threshold">Only alert after repeated events</Label>
            </div>
            {form.useThreshold && (
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <Input type="number" min="2" className="w-20" value={form.count} onChange={(e) => set({ count: e.target.value })} aria-label="Count" />
                <span>events within</span>
                <Input type="number" min="1" className="w-20" value={form.minutes} onChange={(e) => set({ minutes: e.target.value })} aria-label="Minutes" />
                <span>minutes from the same</span>
                <Select value={form.group_by} onValueChange={(group_by) => set({ group_by })}>
                  <SelectTrigger className="w-28">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="ip">IP address</SelectItem>
                    <SelectItem value="user">user</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            )}
          </div>

          <div className="grid gap-4 md:grid-cols-2">
            <Field label="Cooldown (minutes)" hint="Quiet time after an alert. 0: none (threshold rules wait one window).">
              <Input type="number" min="0" value={form.cooldown_minutes} onChange={(e) => set({ cooldown_minutes: e.target.value })} />
            </Field>
            <Field label="Send to" hint={channels.length ? '' : 'Add a channel on the Channels tab first.'}>
              <MultiSelect label="No channel" options={channels.map((c) => ({ value: c.id, label: c.name }))} value={form.channels} onChange={(ids) => set({ channels: ids })} />
            </Field>
          </div>

          <div className="flex items-center gap-2">
            <Switch id="wpal-rule-enabled" checked={form.enabled} onCheckedChange={(enabled) => set({ enabled })} />
            <Label htmlFor="wpal-rule-enabled">Enabled</Label>
          </div>

          {save.isError && !Object.keys(errors).length && <p className="text-sm text-destructive">{save.error.message}</p>}

          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {rule ? 'Save' : 'Add rule'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Rule list with an enable switch per rule, add, edit and delete.
 */
export default function RulesTab() {
  const toast = useToast();
  const meta = useMeta().data;
  const rules = useAlertItems('rules');
  const channels = useAlertItems('channels');
  const { save, remove } = useAlertMutations('rules');
  const [editing, setEditing] = useState(null);
  const [deleting, setDeleting] = useState(null);
  const names = Object.fromEntries((channels.data ?? []).map((c) => [c.id, c.name]));

  const toggle = (rule, enabled) =>
    save.mutate(
      { id: rule.id, enabled },
      { onError: (error) => toast({ title: 'Could not change the rule', description: error.message, variant: 'destructive' }) }
    );

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">Which events send an alert. Built-in templates start switched off.</p>
        <Button onClick={() => setEditing({})}>
          <Plus className="h-4 w-4" />
          Add rule
        </Button>
      </div>

      <div className="divide-y rounded-lg border bg-card">
        {rules.isLoading && <Skeleton className="m-4 h-10" />}
        {rules.data?.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">No rules yet.</p>}
        {rules.data?.map((rule) => (
          <div key={rule.id} className="flex flex-wrap items-center justify-between gap-3 p-3">
            <div className="flex min-w-0 items-center gap-3">
              <Switch checked={rule.enabled} onCheckedChange={(enabled) => toggle(rule, enabled)} aria-label={`Enable ${rule.name}`} />
              <div className="min-w-0 leading-tight">
                <div className="flex flex-wrap items-center gap-2 font-medium">
                  {rule.name}
                  {rule.template && <Badge variant="secondary">template</Badge>}
                  {rule.enabled && rule.channels.length === 0 && <Badge variant="warning">no channel</Badge>}
                </div>
                <div className="text-xs text-muted-foreground">
                  {summary(rule, meta)}
                  {rule.channels.length > 0 && ` → ${rule.channels.map((id) => names[id] ?? 'deleted channel').join(', ')}`}
                </div>
              </div>
            </div>
            <div className="flex gap-1">
              <Button variant="ghost" size="icon" aria-label="Edit" onClick={() => setEditing(rule)}>
                <Pencil className="h-4 w-4" />
              </Button>
              <Button variant="ghost" size="icon" aria-label="Delete" onClick={() => setDeleting(rule)}>
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          </div>
        ))}
      </div>

      {editing && <RuleDialog rule={editing.id ? editing : null} channels={channels.data ?? []} onClose={() => setEditing(null)} />}

      <Dialog open={Boolean(deleting)} onOpenChange={(open) => !open && setDeleting(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete “{deleting?.name}”?</DialogTitle>
            <DialogDescription>{deleting?.template ? 'Built-in templates are not added again later.' : 'This cannot be undone.'}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button variant="destructive" disabled={remove.isPending} onClick={() => remove.mutate(deleting.id, { onSuccess: () => setDeleting(null) })}>
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
