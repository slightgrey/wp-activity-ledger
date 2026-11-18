import { useState } from 'react';
import { Link } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import ActivityChart from '@/components/ActivityChart';
import { useMeta } from '@/hooks/useMeta';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { formatDateTime, relativeTime, typeLabel } from '@/lib/format';
import { cn } from '@/lib/utils';

const RANGES = [
  { key: '24h', label: '24 hours' },
  { key: '7d', label: '7 days' },
  { key: '30d', label: '30 days' },
];

const compact = new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 });

/**
 * A headline number with a label; links to the matching log view.
 */
function StatTile({ label, value, to, loading }) {
  const body = (
    <Card className="h-full p-4 transition-colors hover:bg-muted/40">
      <div className="text-sm text-muted-foreground">{label}</div>
      {loading ? <Skeleton className="mt-2 h-7 w-16" /> : <div className="mt-1 text-2xl font-semibold tabular-nums">{compact.format(value ?? 0)}</div>}
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
function TopList({ title, rows, empty }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base">{title}</CardTitle>
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
  const meta = useMeta();
  const stats = useQuery({
    queryKey: ['stats', range],
    queryFn: async () => (await api('/stats', { query: { range } })).data,
    placeholderData: keepPreviousData,
  });

  const data = stats.data;
  const totals = data?.totals ?? {};
  const log = (params) => `/log?${new URLSearchParams({ range, ...params }).toString()}`;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Activity in the last {RANGES.find((r) => r.key === range).label}
          {stats.isFetching && !stats.isLoading && ' · updating…'}
        </p>
        <div className="flex gap-1 rounded-lg bg-muted p-1" role="group" aria-label="Range">
          {RANGES.map((r) => (
            <button
              key={r.key}
              type="button"
              onClick={() => setRange(r.key)}
              aria-pressed={range === r.key}
              className={cn('rounded-md px-3 py-1 text-sm text-muted-foreground', range === r.key && 'bg-background text-foreground shadow-sm')}
            >
              {r.label}
            </button>
          ))}
        </div>
      </div>

      {stats.isError && <p className="text-sm text-destructive">{stats.error.message}</p>}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        <StatTile label="Events" value={totals.events} to={log({})} loading={!data} />
        <StatTile label="Failed logins" value={totals.failed_logins} to={log({ type: 'auth.login_failed' })} loading={!data} />
        <StatTile label="Critical events" value={totals.critical} to={log({ severity: 'critical' })} loading={!data} />
        <StatTile label="Active users" value={totals.users} loading={!data} />
        <StatTile label="Active sessions" value={totals.active_sessions} to="/users" loading={!data} />
      </div>

      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-base">Events over time</CardTitle>
        </CardHeader>
        <CardContent>{data ? <ActivityChart stats={data} /> : <Skeleton className="h-64 w-full" />}</CardContent>
      </Card>

      <div className="grid gap-3 md:grid-cols-3">
        <TopList
          title="Most active users"
          empty="No user activity."
          rows={(data?.top_users ?? []).map((u) => ({
            key: u.user_id,
            label: u.user_id > 0 ? u.user_login : 'System',
            count: u.count,
            to: log({ user: String(u.user_id) }),
          }))}
        />
        <TopList
          title="Top events"
          empty="No events."
          rows={(data?.top_types ?? []).map((t) => ({ key: t.event_type, label: typeLabel(t.event_type, meta.data), count: t.count, to: log({ type: t.event_type }) }))}
        />
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">Recent critical events</CardTitle>
          </CardHeader>
          <CardContent>
            {(data?.recent_critical ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">No critical events in this period.</p>
            ) : (
              <ul className="divide-y">
                {data.recent_critical.map((e) => (
                  <li key={e.id} className="py-1.5 text-sm">
                    <div className="flex items-center gap-2">
                      <Badge variant="critical">critical</Badge>
                      <span className="truncate">{e.message}</span>
                    </div>
                    <div className="text-xs text-muted-foreground" title={formatDateTime(e.created_at, config.timezone)}>
                      {e.user_login || 'System'} · {relativeTime(e.created_at)}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
