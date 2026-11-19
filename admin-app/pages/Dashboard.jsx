import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import ActivityChart from '@/components/ActivityChart';
import EventDrawer from '@/components/EventDrawer';
import { EMPTY, serializeFilters } from '@/lib/filters';
import { useMeta } from '@/hooks/useMeta';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { formatDateTime, relativeTime, typeLabel } from '@/lib/format';
import { Activity, AlertOctagon, AlertTriangle, BarChart3, Clock, ListOrdered, MonitorSmartphone, ShieldAlert, UserCheck, Users } from 'lucide-react';
import { cn, segmentClass, segmentGroupClass } from '@/lib/utils';
import { __, sprintf } from '@/lib/i18n';

const RANGES = [
  { key: '24h', label: __( '24 hours', 'wp-activity-ledger' ) },
  { key: '7d', label: __( '7 days', 'wp-activity-ledger' ) },
  { key: '30d', label: __( '30 days', 'wp-activity-ledger' ) },
];

const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });

const TONES = {
  blue: 'bg-blue-100 text-blue-700',
  amber: 'bg-amber-100 text-amber-700',
  red: 'bg-red-100 text-red-700',
  emerald: 'bg-emerald-100 text-emerald-700',
  violet: 'bg-violet-100 text-violet-700',
};

/**
 * A card heading with a leading icon.
 */
function IconTitle({ icon: Icon, children, iconClass = 'text-muted-foreground' }) {
  return (
    <CardTitle className="flex items-center gap-2 text-base">
      <Icon className={cn('h-4 w-4 shrink-0', iconClass)} aria-hidden="true" />
      {children}
    </CardTitle>
  );
}

/**
 * A headline number with a label and a tinted icon; links to the matching log view.
 */
function StatTile({ label, value, to, loading, icon: Icon, tone = 'blue' }) {
  const body = (
    <Card className="flex h-full items-start justify-between gap-3 p-4 transition-colors hover:bg-muted/40">
      <div className="min-w-0">
        <div className="text-sm text-muted-foreground">{label}</div>
        {loading ? <Skeleton className="mt-2 h-7 w-16" /> : <div className="mt-1 text-2xl font-semibold tabular-nums">{compact.format(value ?? 0)}</div>}
      </div>
      <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', TONES[tone])}>
        <Icon className="h-5 w-5" aria-hidden="true" />
      </span>
    </Card>
  );

  return to ? (
    <Link to={to} className="block !text-inherit">
      {body}
    </Link>
  ) : (
    body
  );
}

/**
 * A ranked list of name + count rows.
 */
function TopList({ title, icon, rows, empty }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <IconTitle icon={icon}>{title}</IconTitle>
      </CardHeader>
      <CardContent>
        {rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">{empty}</p>
        ) : (
          <ol className="divide-y">
            {rows.map((row) => (
              <li key={row.key} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                <Link to={row.to} className="truncate">
                  {row.label}
                </Link>
                <span className="tabular-nums text-muted-foreground">{row.count.toLocaleString()}</span>
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  );
}

export default function Dashboard() {
  const [range, setRange] = useState('7d');
  const [eventId, setEventId] = useState(null);
  const navigate = useNavigate();
  const meta = useMeta();
  // Same per-user choice as the Activity Log's "Show system events" switch (hidden by default).
  const hideSystem = !meta.data?.prefs?.show_system;
  const stats = useQuery({
    queryKey: ['stats', range, hideSystem],
    queryFn: async () => (await api('/stats', { query: { range, ...(hideSystem ? { hide_system: 1 } : {}) } })).data,
    placeholderData: keepPreviousData,
  });

  const data = stats.data;
  const totals = data?.totals ?? {};
  const log = (params) => `/log?${new URLSearchParams({ range, ...params }).toString()}`;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
          <Clock className="h-4 w-4" aria-hidden="true" />
          {sprintf( __( 'Activity in the last %s', 'wp-activity-ledger' ), RANGES.find((r) => r.key === range).label )}
          {hideSystem && (
            <span title={__( 'Events with no logged-in user, including failed logins, are left out. Change this with "Show system events" in the Activity Log.', 'wp-activity-ledger' )}>
              {' · '}
              {__( 'system events hidden', 'wp-activity-ledger' )}
            </span>
          )}
          {stats.isFetching && !stats.isLoading && ' · updating…'}
        </p>
        <div className={segmentGroupClass} role="group" aria-label={__( 'Range', 'wp-activity-ledger' )}>
          {RANGES.map((r) => (
            <button
              key={r.key}
              type="button"
              onClick={() => setRange(r.key)}
              aria-pressed={range === r.key}
              className={segmentClass(range === r.key, 'sm')}
            >
              {r.label}
            </button>
          ))}
        </div>
      </div>

      {stats.isError && <p className="text-sm text-destructive">{stats.error.message}</p>}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        <StatTile label={__( 'Events', 'wp-activity-ledger' )} icon={Activity} tone="blue" value={totals.events} to={log({})} loading={!data} />
        <StatTile label={__( 'Failed logins', 'wp-activity-ledger' )} icon={ShieldAlert} tone="amber" value={totals.failed_logins} to={log({ type: 'auth.login_failed' })} loading={!data} />
        <StatTile label={__( 'Critical events', 'wp-activity-ledger' )} icon={AlertTriangle} tone="red" value={totals.critical} to={log({ severity: 'critical' })} loading={!data} />
        <StatTile label={__( 'Active users', 'wp-activity-ledger' )} icon={UserCheck} tone="emerald" value={totals.users} loading={!data} />
        <StatTile label={__( 'Active sessions', 'wp-activity-ledger' )} icon={MonitorSmartphone} tone="violet" value={totals.active_sessions} to="/users" loading={!data} />
      </div>

      <Card>
        <CardHeader className="pb-2">
          <IconTitle icon={BarChart3}>{__( 'Events over time', 'wp-activity-ledger' )}</IconTitle>
        </CardHeader>
        <CardContent>{data ? <ActivityChart stats={data} /> : <Skeleton className="h-64 w-full" />}</CardContent>
      </Card>

      <div className="grid gap-3 md:grid-cols-3">
        <TopList
          title={__( 'Most active users', 'wp-activity-ledger' )}
          icon={Users}
          empty={__( 'No user activity.', 'wp-activity-ledger' )}
          rows={(data?.top_users ?? []).map((u) => ({
            key: u.user_id,
            label: u.user_id > 0 ? u.user_login : __( 'System', 'wp-activity-ledger' ),
            count: u.count,
            to: log({ user: String(u.user_id) }),
          }))}
        />
        <TopList
          title={__( 'Top events', 'wp-activity-ledger' )}
          icon={ListOrdered}
          empty={__( 'No events.', 'wp-activity-ledger' )}
          rows={(data?.top_types ?? []).map((t) => ({ key: t.event_type, label: typeLabel(t.event_type, meta.data), count: t.count, to: log({ type: t.event_type }) }))}
        />
        <Card>
          <CardHeader className="pb-2">
            <IconTitle icon={AlertOctagon} iconClass="text-red-600">{__( 'Recent critical events', 'wp-activity-ledger' )}</IconTitle>
          </CardHeader>
          <CardContent>
            {(data?.recent_critical ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">{__( 'No critical events in this period.', 'wp-activity-ledger' )}</p>
            ) : (
              <ul className="divide-y">
                {data.recent_critical.map((e) => (
                  <li key={e.id}>
                    <button
                      type="button"
                      onClick={() => setEventId(e.id)}
                      className="group -mx-2 block w-[calc(100%+1rem)] rounded-md px-2 py-1.5 text-left text-sm transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      <div className="flex items-center gap-2">
                        <Badge variant="critical">critical</Badge>
                        <span className="truncate group-hover:underline group-hover:underline-offset-[3px]">{e.message}</span>
                      </div>
                      <div className="text-xs text-muted-foreground" title={formatDateTime(e.created_at, config.timezone)}>
                        {e.user_login || 'System'} · {relativeTime(e.created_at)}
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>

      <EventDrawer
        eventId={eventId}
        meta={meta.data}
        onClose={() => setEventId(null)}
        onFilter={(patch) => {
          setEventId(null);
          navigate(`/log?${serializeFilters({ ...EMPTY, ...patch }).toString()}`);
        }}
      />
    </div>
  );
}
