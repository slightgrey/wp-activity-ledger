import { useCallback, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Columns3, ExternalLink, Inbox } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useToast } from '@/components/ui/toast';
import EventDrawer from '@/components/EventDrawer';
import FilterBar from '@/components/FilterBar';
import { useEvents } from '@/hooks/useEvents';
import { useMeta } from '@/hooks/useMeta';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { EMPTY, parseFilters, serializeFilters, toApiQuery } from '@/lib/filters';
import { categoryLabel, formatDateTime, relativeTime, severity, typeLabel } from '@/lib/format';

const COLUMNS = [
  { key: 'time', label: 'Time' },
  { key: 'user', label: 'User' },
  { key: 'event', label: 'Event' },
  { key: 'severity', label: 'Severity' },
  { key: 'category', label: 'Category' },
  { key: 'object', label: 'Object' },
  { key: 'ip', label: 'IP' },
  { key: 'source', label: 'Source' },
];

/**
 * Renders one cell of an event row.
 */
function Cell({ column, event, meta }) {
  switch (column) {
    case 'time':
      return (
        <span title={relativeTime(event.created_at)} className="whitespace-nowrap tabular-nums">
          {formatDateTime(event.created_at, config.timezone)}
        </span>
      );
    case 'user':
      return event.user_id > 0 ? (
        <span className="flex items-center gap-2">
          {event.user.avatar && <img src={event.user.avatar} alt="" className="h-6 w-6 rounded-full" />}
          <span className="leading-tight">
            <span className="block">{event.user.name}</span>
            <span className="block text-xs text-muted-foreground">{event.user_roles.join(', ') || event.user_login}</span>
          </span>
        </span>
      ) : (
        <span className="text-muted-foreground">System</span>
      );
    case 'event': {
      const level = severity(event.severity);

      return (
        <span className="block max-w-md leading-tight">
          <span className="flex items-center gap-2 font-medium">
            {typeLabel(event.event_type, meta)}
            {level.value > 0 && <Badge variant={level.variant}>{level.slug}</Badge>}
            {event.change_count > 0 && <span className="text-xs font-normal text-muted-foreground">{event.change_count} changes</span>}
          </span>
          <span className="block truncate text-xs text-muted-foreground">{event.message}</span>
        </span>
      );
    }
    case 'severity': {
      const level = severity(event.severity);

      return <Badge variant={level.variant}>{level.slug}</Badge>;
    }
    case 'category':
      return categoryLabel(event.category, meta);
    case 'object':
      return event.edit_link ? (
        <a href={event.edit_link} onClick={(e) => e.stopPropagation()} className="inline-flex max-w-xs items-center gap-1 truncate">
          {event.object_label || event.object_id} <ExternalLink className="h-3 w-3 shrink-0" />
        </a>
      ) : (
        <span className="block max-w-xs truncate">{event.object_label || event.object_id || '—'}</span>
      );
    case 'ip':
      return <span className="tabular-nums">{event.ip || '—'}</span>;
    case 'source':
      return <span className="text-muted-foreground">{event.context?.request?.source ?? '—'}</span>;
    default:
      return null;
  }
}

/**
 * Loading rows.
 */
function SkeletonRows({ columns, count }) {
  return Array.from({ length: Math.min(count, 10) }, (_, row) => (
    <TableRow key={row}>
      {columns.map((column) => (
        <TableCell key={column}>
          <Skeleton className="h-4 w-full" />
        </TableCell>
      ))}
    </TableRow>
  ));
}

/**
 * The column toggle menu; the choice is saved to the user's prefs.
 */
function ColumnMenu({ columns, onChange }) {
  const toggle = (key) => onChange(columns.includes(key) ? columns.filter((c) => c !== key) : COLUMNS.map((c) => c.key).filter((c) => c === key || columns.includes(c)));

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" size="sm">
          <Columns3 className="h-4 w-4" />
          Columns
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel>Show columns</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {COLUMNS.map((column) => (
          <DropdownMenuCheckboxItem
            key={column.key}
            checked={columns.includes(column.key)}
            disabled={columns.length === 1 && columns.includes(column.key)}
            onSelect={(e) => e.preventDefault()}
            onCheckedChange={() => toggle(column.key)}
          >
            {column.label}
          </DropdownMenuCheckboxItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

/**
 * Saves UI prefs and updates the cached /meta.
 */
function useSavePrefs() {
  const client = useQueryClient();
  const toast = useToast();

  return useMutation({
    mutationFn: async (prefs) => (await api('/me/prefs', { method: 'PUT', body: prefs })).data,
    onMutate: (prefs) => client.setQueryData(['meta'], (meta) => (meta ? { ...meta, prefs: { ...meta.prefs, ...prefs } } : meta)),
    onSuccess: (prefs) => client.setQueryData(['meta'], (meta) => (meta ? { ...meta, prefs } : meta)),
    onError: (error) => toast({ title: 'Could not save your view', description: error.message, variant: 'destructive' }),
  });
}

/**
 * The activity log. With `fixed`, those filters always apply and are not shown or stored
 * in the URL (used on a user's page).
 *
 * @param {object} props
 * @param {object} [props.fixed] Fixed filters, e.g. { user: ['5'] } (keep the object stable).
 */
export default function ActivityLog({ fixed = null }) {
  const [params, setParams] = useSearchParams();
  const [eventId, setEventId] = useState(null);
  const [autoRefresh, setAutoRefresh] = useState(false);
  const meta = useMeta();
  const savePrefs = useSavePrefs();

  const filters = useMemo(() => ({ ...parseFilters(params), ...(fixed ?? {}) }), [params, fixed]);
  const prefs = meta.data?.prefs;
  const columns = prefs?.columns?.length ? prefs.columns : ['time', 'user', 'event', 'object', 'ip'];
  const perPage = filters.per_page || prefs?.per_page || 25;

  // Relative ranges ("last 24 hours") are anchored when the filters change, not on every render.
  const query = useMemo(
    () => toApiQuery(filters, { timeZone: config.timezone, perPage, now: new Date() }),
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `filters` derives from `params` and `fixed`.
    [params, fixed, perPage]
  );

  const events = useEvents(query, { refetchInterval: autoRefresh ? 30000 : false });

  const withoutFixed = useCallback(
    (state) => serializeFilters(Object.fromEntries(Object.entries(state).filter(([key]) => !(fixed && key in fixed)))),
    [fixed]
  );

  const update = useCallback((patch) => setParams(withoutFixed({ ...filters, page: 1, ...patch })), [filters, setParams, withoutFixed]);

  const applyFromDrawer = (patch) => {
    setEventId(null);
    setParams(withoutFixed({ ...EMPTY, ...patch }));
  };

  const total = events.data?.total ?? 0;
  const totalPages = Math.max(1, events.data?.totalPages ?? 1);

  return (
    <div className="space-y-3">
      <FilterBar filters={filters} meta={meta.data} hidden={Object.keys(fixed ?? {})} onChange={update} onClear={() => setParams(new URLSearchParams())} />

      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          {events.isLoading ? 'Loading…' : `${total.toLocaleString()} ${total === 1 ? 'event' : 'events'}`}
          {events.isFetching && !events.isLoading && ' · updating…'}
        </p>
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2">
            <Switch id="wpal-autorefresh" checked={autoRefresh} onCheckedChange={setAutoRefresh} />
            <Label htmlFor="wpal-autorefresh" className="text-sm font-normal">
              Auto-refresh (30 s)
            </Label>
          </div>
          <ColumnMenu columns={columns} onChange={(next) => savePrefs.mutate({ columns: next })} />
        </div>
      </div>

      <div className="rounded-lg border bg-card">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              {columns.map((key) => (
                <TableHead key={key}>{COLUMNS.find((c) => c.key === key)?.label}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {events.isLoading && <SkeletonRows columns={columns} count={perPage} />}
            {events.data?.items.map((event) => (
              <TableRow key={event.id} className="cursor-pointer" onClick={() => setEventId(event.id)}>
                {columns.map((key) => (
                  <TableCell key={key}>
                    <Cell column={key} event={event} meta={meta.data} />
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>

        {events.isError && <p className="p-6 text-center text-sm text-destructive">{events.error.message}</p>}

        {events.data && events.data.items.length === 0 && (
          <div className="flex flex-col items-center gap-2 p-10 text-center text-muted-foreground">
            <Inbox className="h-8 w-8" />
            <p className="text-sm">No events match these filters.</p>
          </div>
        )}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-sm">
          <span className="text-muted-foreground">Rows per page</span>
          <Select
            value={String(perPage)}
            onValueChange={(value) => {
              savePrefs.mutate({ per_page: Number(value) });
              update({ per_page: 0 });
            }}
          >
            <SelectTrigger className="h-8 w-20">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {[25, 50, 100].map((n) => (
                <SelectItem key={n} value={String(n)}>
                  {n}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex items-center gap-2 text-sm">
          <span className="text-muted-foreground">
            Page {filters.page} of {totalPages}
          </span>
          <Button variant="outline" size="icon" disabled={filters.page <= 1} onClick={() => update({ page: filters.page - 1 })} aria-label="Previous page">
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button variant="outline" size="icon" disabled={filters.page >= totalPages} onClick={() => update({ page: filters.page + 1 })} aria-label="Next page">
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      </div>

      <EventDrawer eventId={eventId} meta={meta.data} onClose={() => setEventId(null)} onFilter={applyFromDrawer} />
    </div>
  );
}
