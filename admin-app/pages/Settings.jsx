import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToast } from '@/components/ui/toast';
import FieldError from '@/components/FieldError';
import MultiSelect from '@/components/MultiSelect';
import UserPicker from '@/components/UserPicker';
import EventsTab from '@/components/settings/EventsTab';
import PurgePanel from '@/components/settings/PurgePanel';
import { NumberRow, Row, ToggleRow } from '@/components/settings/fields';
import { useMeta } from '@/hooks/useMeta';
import { useSaveSettings, useSettings } from '@/hooks/useSettings';
import { config } from '@/lib/config';

const TABS = ['general', 'events', 'privacy', 'retention', 'advanced'];
const REMOTE_ADDR = 'remote';

function GeneralTab({ draft, set, errors, meta }) {
  const roles = (meta?.roles ?? []).map((r) => ({ value: r.slug, label: r.name }));
  const everyRole = (draft.logged_roles ?? []).includes('*');

  return (
    <div>
      <Row label="Record activity of" help="Whose actions are logged. Anonymous and system actions are controlled below." error={<FieldError errors={errors} field="logged_roles" />}>
        <div className="flex items-center gap-2">
          <Switch id="wpal-set-every-role" checked={everyRole} onCheckedChange={(on) => set({ logged_roles: on ? ['*'] : [] })} />
          <label htmlFor="wpal-set-every-role" className="text-sm">
            Every role
          </label>
        </div>
        {!everyRole && <MultiSelect label="Choose roles" options={roles} value={draft.logged_roles ?? []} onChange={(logged_roles) => set({ logged_roles })} />}
      </Row>
      <Row label="Never record these users" help="For service accounts or your own test users." error={<FieldError errors={errors} field="excluded_users" />}>
        <UserPicker value={(draft.excluded_users ?? []).map(String)} onChange={(ids) => set({ excluded_users: ids.map(Number) })} />
      </Row>
      <ToggleRow name="log_anonymous" label="Record anonymous actions" help="Failed logins and password reset requests by visitors." draft={draft} set={set} />
      <Row label="Who can view the log" help="Administrators always can. Viewers also see the dashboard and users." error={<FieldError errors={errors} field="view_roles" />}>
        <MultiSelect label="Administrators only" options={roles} value={draft.view_roles ?? []} onChange={(view_roles) => set({ view_roles })} />
      </Row>
      <Row label="Who can export" help="Export also needs permission to view." error={<FieldError errors={errors} field="export_roles" />}>
        <MultiSelect label="Administrators only" options={roles} value={draft.export_roles ?? []} onChange={(export_roles) => set({ export_roles })} />
      </Row>
      <NumberRow
        name="failed_login_dedupe_minutes"
        label="Group repeated failed logins"
        help="Failed logins for the same username and IP within this time are counted on one entry. 0 records each one."
        suffix="minutes"
        draft={draft}
        set={set}
        errors={errors}
      />
      <ToggleRow name="dashboard_widget" label="Dashboard widget" help="Show recent activity on Dashboard → Home." draft={draft} set={set} />
    </div>
  );
}

function PrivacyTab({ draft, set, errors, meta }) {
  return (
    <div>
      <ToggleRow name="anonymize_ip" label="Anonymise IP addresses" help="Stores 203.0.113.0 instead of 203.0.113.42 (IPv6: the first 48 bits)." draft={draft} set={set} />
      <ToggleRow name="store_user_agent" label="Store browser details" help="The user agent of each request." draft={draft} set={set} />
      <Row
        label="Client IP source"
        help="Only use a proxy header when your site is behind a proxy or CDN that sets it; otherwise visitors can fake their IP."
        error={<FieldError errors={errors} field="trusted_proxy_header" />}
      >
        <Select value={draft.trusted_proxy_header || REMOTE_ADDR} onValueChange={(v) => set({ trusted_proxy_header: v === REMOTE_ADDR ? '' : v })}>
          <SelectTrigger className="w-72">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={REMOTE_ADDR}>Connection address (REMOTE_ADDR)</SelectItem>
            {(meta?.proxy_headers ?? []).map((h) => (
              <SelectItem key={h} value={h}>
                {h.replace(/^HTTP_/, '').replace(/_/g, '-')} header
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </Row>
    </div>
  );
}

function RetentionTab({ draft, set, errors }) {
  return (
    <div className="space-y-4">
      <div>
        <NumberRow name="retention_days" label="Keep events for" help="Older events are deleted every day. 0 keeps them forever." suffix="days" draft={draft} set={set} errors={errors} />
        <NumberRow name="max_rows" label="Keep at most" help="The oldest events beyond this number are deleted every day. 0 means no limit." suffix="events" draft={draft} set={set} errors={errors} />
      </div>
      {config.caps.delete && <PurgePanel />}
    </div>
  );
}

function AdvancedTab({ draft, set, meta }) {
  return (
    <div>
      <Row label="Delete data when uninstalled" help="When the plugin is deleted from Plugins: remove the log tables, settings, alerts and capabilities.">
        <div className="flex items-center gap-2">
          <Switch id="wpal-set-delete" checked={Boolean(draft.delete_data_on_uninstall)} onCheckedChange={(on) => set({ delete_data_on_uninstall: on })} />
          <label htmlFor="wpal-set-delete" className="text-sm">
            {draft.delete_data_on_uninstall ? 'Delete everything on uninstall' : 'Keep the audit trail'}
          </label>
        </div>
        {draft.delete_data_on_uninstall && (
          <p className="flex items-center gap-1.5 text-xs text-amber-800">
            <AlertTriangle className="h-3.5 w-3.5" /> The whole activity log is lost when the plugin is deleted.
          </p>
        )}
      </Row>
      {meta?.multisite && meta?.super_admin && (
        <Row label="Network lock" help="Stop site administrators from changing these settings or purging logs. Managed in the network admin.">
          <p className="text-sm text-muted-foreground">Set this under Network Admin → Activity Ledger.</p>
        </Row>
      )}
    </div>
  );
}

/**
 * Settings, in tabs (kept in the URL). One draft is shared by all tabs and saved together.
 */
export default function Settings() {
  const [params, setParams] = useSearchParams();
  const tab = TABS.includes(params.get('tab')) ? params.get('tab') : 'general';
  const toast = useToast();
  const meta = useMeta().data;
  const settings = useSettings();
  const save = useSaveSettings();
  const [draft, setDraft] = useState(null);

  useEffect(() => {
    if (settings.data && draft === null) setDraft(settings.data);
  }, [settings.data, draft]);

  const dirty = useMemo(() => draft !== null && JSON.stringify(draft) !== JSON.stringify(settings.data), [draft, settings.data]);
  const errors = save.error?.data?.errors ?? {};
  const set = (patch) => setDraft((d) => ({ ...d, ...patch }));

  const submit = () =>
    save.mutate(draft, {
      onSuccess: (saved) => {
        setDraft(saved);
        toast({ title: 'Settings saved' });
      },
      onError: (error) => toast({ title: 'Settings not saved', description: error.message, variant: 'destructive' }),
    });

  if (!draft) {
    return settings.isError ? <p className="text-sm text-destructive">{settings.error.message}</p> : <Skeleton className="h-64 w-full" />;
  }

  const props = { draft, set, errors, meta };
  const readOnly = !config.caps.manage_settings;

  return (
    <Tabs value={tab} onValueChange={(value) => setParams(value === 'general' ? {} : { tab: value })}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <TabsList>
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="events">Events</TabsTrigger>
          <TabsTrigger value="privacy">Privacy</TabsTrigger>
          <TabsTrigger value="retention">Retention</TabsTrigger>
          <TabsTrigger value="advanced">Advanced</TabsTrigger>
        </TabsList>
        <div className={readOnly ? 'hidden' : 'flex items-center gap-2'}>
          {dirty && <span className="text-xs text-muted-foreground">Unsaved changes</span>}
          <Button variant="outline" disabled={!dirty || save.isPending} onClick={() => setDraft(settings.data)}>
            Discard
          </Button>
          <Button disabled={!dirty || save.isPending} onClick={submit}>
            Save settings
          </Button>
        </div>
      </div>
      {readOnly && (
        <p className="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          These settings are managed by the network administrator. You can view them but not change them.
        </p>
      )}
      <Card className="mt-3">
        <CardContent className="py-2">
          <fieldset disabled={readOnly} className="contents">
          <TabsContent value="general" className="mt-0">
            <GeneralTab {...props} />
          </TabsContent>
          <TabsContent value="events" className="mt-0">
            <EventsTab {...props} />
          </TabsContent>
          <TabsContent value="privacy" className="mt-0">
            <PrivacyTab {...props} />
          </TabsContent>
          <TabsContent value="retention" className="mt-0">
            <RetentionTab {...props} />
          </TabsContent>
          <TabsContent value="advanced" className="mt-0">
            <AdvancedTab {...props} />
          </TabsContent>
          </fieldset>
        </CardContent>
      </Card>
    </Tabs>
  );
}
