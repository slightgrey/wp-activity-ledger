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
import { __ } from '@/lib/i18n';

const TABS = ['general', 'events', 'privacy', 'retention', 'advanced'];
const REMOTE_ADDR = 'remote';

function GeneralTab({ draft, set, errors, meta }) {
  const roles = (meta?.roles ?? []).map((r) => ({ value: r.slug, label: r.name }));
  const everyRole = (draft.logged_roles ?? []).includes('*');

  return (
    <div>
      <Row label={__( 'Record activity of', 'wp-activity-ledger' )} help={__( 'Whose actions are logged. Anonymous and system actions are controlled below.', 'wp-activity-ledger' )} error={<FieldError errors={errors} field="logged_roles" />}>
        <div className="flex items-center gap-2">
          <Switch id="wpal-set-every-role" checked={everyRole} onCheckedChange={(on) => set({ logged_roles: on ? ['*'] : [] })} />
          <label htmlFor="wpal-set-every-role" className="text-sm">
            {__( 'Every role', 'wp-activity-ledger' )}
          </label>
        </div>
        {!everyRole && <MultiSelect label={__( 'Choose roles', 'wp-activity-ledger' )} options={roles} value={draft.logged_roles ?? []} onChange={(logged_roles) => set({ logged_roles })} />}
      </Row>
      <Row label={__( 'Never record these users', 'wp-activity-ledger' )} help={__( 'For service accounts or your own test users.', 'wp-activity-ledger' )} error={<FieldError errors={errors} field="excluded_users" />}>
        <UserPicker value={(draft.excluded_users ?? []).map(String)} onChange={(ids) => set({ excluded_users: ids.map(Number) })} />
      </Row>
      <ToggleRow name="log_anonymous" label={__( 'Record anonymous actions', 'wp-activity-ledger' )} help={__( 'Failed logins and password reset requests by visitors.', 'wp-activity-ledger' )} draft={draft} set={set} />
      <Row label={__( 'Who can view the log', 'wp-activity-ledger' )} help={__( 'Administrators always can. Viewers also see the dashboard and users.', 'wp-activity-ledger' )} error={<FieldError errors={errors} field="view_roles" />}>
        <MultiSelect label={__( 'Administrators only', 'wp-activity-ledger' )} options={roles} value={draft.view_roles ?? []} onChange={(view_roles) => set({ view_roles })} />
      </Row>
      <Row label={__( 'Who can export', 'wp-activity-ledger' )} help={__( 'Export also needs permission to view.', 'wp-activity-ledger' )} error={<FieldError errors={errors} field="export_roles" />}>
        <MultiSelect label={__( 'Administrators only', 'wp-activity-ledger' )} options={roles} value={draft.export_roles ?? []} onChange={(export_roles) => set({ export_roles })} />
      </Row>
      <NumberRow
        name="failed_login_dedupe_minutes"
        label={__( 'Group repeated failed logins', 'wp-activity-ledger' )}
        help={__( 'Failed logins for the same username and IP within this time are counted on one entry. 0 records each one.', 'wp-activity-ledger' )}
        suffix="minutes"
        draft={draft}
        set={set}
        errors={errors}
      />
      <ToggleRow name="dashboard_widget" label={__( 'Dashboard widget', 'wp-activity-ledger' )} help={__( 'Show recent activity on Dashboard → Home.', 'wp-activity-ledger' )} draft={draft} set={set} />
    </div>
  );
}

function PrivacyTab({ draft, set, errors, meta }) {
  return (
    <div>
      <ToggleRow name="anonymize_ip" label={__( 'Anonymise IP addresses', 'wp-activity-ledger' )} help={__( 'Stores 203.0.113.0 instead of 203.0.113.42 (IPv6: the first 48 bits).', 'wp-activity-ledger' )} draft={draft} set={set} />
      <ToggleRow name="store_user_agent" label={__( 'Store browser details', 'wp-activity-ledger' )} help={__( 'The user agent of each request.', 'wp-activity-ledger' )} draft={draft} set={set} />
      <Row
        label={__( 'Client IP source', 'wp-activity-ledger' )}
        help={__( 'Only use a proxy header when your site is behind a proxy or CDN that sets it; otherwise visitors can fake their IP.', 'wp-activity-ledger' )}
        error={<FieldError errors={errors} field="trusted_proxy_header" />}
      >
        <Select value={draft.trusted_proxy_header || REMOTE_ADDR} onValueChange={(v) => set({ trusted_proxy_header: v === REMOTE_ADDR ? '' : v })}>
          <SelectTrigger className="w-72">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={REMOTE_ADDR}>{__( 'Connection address (REMOTE_ADDR)', 'wp-activity-ledger' )}</SelectItem>
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
        <NumberRow name="retention_days" label={__( 'Keep events for', 'wp-activity-ledger' )} help={__( 'Older events are deleted every day. 0 keeps them forever.', 'wp-activity-ledger' )} suffix="days" draft={draft} set={set} errors={errors} />
        <NumberRow name="max_rows" label={__( 'Keep at most', 'wp-activity-ledger' )} help={__( 'The oldest events beyond this number are deleted every day. 0 means no limit.', 'wp-activity-ledger' )} suffix="events" draft={draft} set={set} errors={errors} />
      </div>
      {config.caps.delete && <PurgePanel />}
    </div>
  );
}

function AdvancedTab({ draft, set, meta }) {
  return (
    <div>
      <Row label={__( 'Delete data when uninstalled', 'wp-activity-ledger' )} help={__( 'When the plugin is deleted from Plugins: remove the log tables, settings, alerts and capabilities.', 'wp-activity-ledger' )}>
        <div className="flex items-center gap-2">
          <Switch id="wpal-set-delete" checked={Boolean(draft.delete_data_on_uninstall)} onCheckedChange={(on) => set({ delete_data_on_uninstall: on })} />
          <label htmlFor="wpal-set-delete" className="text-sm">
            {draft.delete_data_on_uninstall ? __( 'Delete everything on uninstall', 'wp-activity-ledger' ) : __( 'Keep the audit trail', 'wp-activity-ledger' )}
          </label>
        </div>
        {draft.delete_data_on_uninstall && (
          <p className="flex items-center gap-1.5 text-xs text-amber-800">
            <AlertTriangle className="h-3.5 w-3.5" />{' '}{__( 'The whole activity log is lost when the plugin is deleted.', 'wp-activity-ledger' )}
          </p>
        )}
      </Row>
      {meta?.multisite && meta?.super_admin && (
        <Row label={__( 'Network lock', 'wp-activity-ledger' )} help={__( 'Stop site administrators from changing these settings or purging logs. Managed in the network admin.', 'wp-activity-ledger' )}>
          <p className="text-sm text-muted-foreground">{__( 'Set this under Network Admin → Activity Ledger.', 'wp-activity-ledger' )}</p>
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
        toast({ title: __( 'Settings saved', 'wp-activity-ledger' ) });
      },
      onError: (error) => toast({ title: __( 'Settings not saved', 'wp-activity-ledger' ), description: error.message, variant: 'destructive' }),
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
          <TabsTrigger value="general">{__( 'General', 'wp-activity-ledger' )}</TabsTrigger>
          <TabsTrigger value="events">{__( 'Events', 'wp-activity-ledger' )}</TabsTrigger>
          <TabsTrigger value="privacy">{__( 'Privacy', 'wp-activity-ledger' )}</TabsTrigger>
          <TabsTrigger value="retention">{__( 'Retention', 'wp-activity-ledger' )}</TabsTrigger>
          <TabsTrigger value="advanced">{__( 'Advanced', 'wp-activity-ledger' )}</TabsTrigger>
        </TabsList>
        <div className={readOnly ? 'hidden' : 'flex items-center gap-2'}>
          {dirty && <span className="text-xs text-muted-foreground">{__( 'Unsaved changes', 'wp-activity-ledger' )}</span>}
          <Button variant="outline" disabled={!dirty || save.isPending} onClick={() => setDraft(settings.data)}>
            {__( 'Discard', 'wp-activity-ledger' )}
          </Button>
          <Button disabled={!dirty || save.isPending} onClick={submit}>
            {__( 'Save settings', 'wp-activity-ledger' )}
          </Button>
        </div>
      </div>
      {readOnly && (
        <p className="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          {__( 'These settings are managed by the network administrator. You can view them but not change them.', 'wp-activity-ledger' )}
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
