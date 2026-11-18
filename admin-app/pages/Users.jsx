import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ArrowDown, ChevronLeft, ChevronRight, Search } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDebounced } from '@/hooks/useDebounced';
import { useMeta } from '@/hooks/useMeta';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { formatDateTime, relativeTime } from '@/lib/format';
import { cn } from '@/lib/utils';

const PER_PAGE = 100;
const ALL = 'all';

const SORTS = {
  login: (a, b) => a.login.localeCompare(b.login),
  last_activity: (a, b) => (b.last_activity ?? '').localeCompare(a.last_activity ?? ''),
  events: (a, b) => b.events - a.events,
};

/**
 * A sortable column header.
 */
function SortHead({ label, sortKey, sort, onSort, className }) {
  return (
    <TableHead className={className}>
      <button type="button" onClick={() => onSort(sortKey)} className={cn('inline-flex items-center gap-1 uppercase', sort === sortKey && 'text-foreground')}>
        {label}
        {sort === sortKey && <ArrowDown className="h-3 w-3" />}
      </button>
    </TableHead>
  );
}

/**
 * A stored UTC time as "x ago", with the site time on hover.
 */
function When({ value }) {
  if (!value) return <span className="text-muted-foreground">—</span>;

  return <span title={formatDateTime(value, config.timezone)}>{relativeTime(value)}</span>;
}

export default function Users() {
  const navigate = useNavigate();
  const meta = useMeta();
  const [search, setSearch] = useState('');
  const [role, setRole] = useState('');
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState('last_activity');
  const term = useDebounced(search.trim(), 300);

  const users = useQuery({
    queryKey: ['users', term, role, page],
    queryFn: async () => {
      const result = await api('/users', { query: { search: term, role, page, per_page: PER_PAGE } });

      return { items: result.data, total: result.total ?? 0, totalPages: result.totalPages ?? 1 };
    },
    placeholderData: keepPreviousData,
  });

  // Sorting applies to the loaded page (up to 100 users).
  const rows = useMemo(() => [...(users.data?.items ?? [])].sort(SORTS[sort]), [users.data, sort]);
  const totalPages = Math.max(1, users.data?.totalPages ?? 1);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-3">
        <div className="relative min-w-[220px] flex-1">
          <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            className="pl-8"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            placeholder="Search by name, login or email…"
          />
        </div>
        <Select
          value={role || ALL}
          onValueChange={(value) => {
            setRole(value === ALL ? '' : value);
            setPage(1);
          }}
        >
          <SelectTrigger className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>Any role</SelectItem>
            {(meta.data?.roles ?? []).map((r) => (
              <SelectItem key={r.slug} value={r.slug}>
                {r.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <p className="text-sm text-muted-foreground">
        {users.isLoading ? 'Loading…' : `${(users.data?.total ?? 0).toLocaleString()} users`} · event counts cover the last 30 days
      </p>

      <div className="rounded-lg border bg-card">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <SortHead label="User" sortKey="login" sort={sort} onSort={setSort} />
              <TableHead>Roles</TableHead>
              <SortHead label="Last activity" sortKey="last_activity" sort={sort} onSort={setSort} />
              <TableHead>Last login</TableHead>
              <SortHead label="Events" sortKey="events" sort={sort} onSort={setSort} className="text-right" />
              <TableHead className="text-right">Failed logins</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {users.isLoading &&
              Array.from({ length: 6 }, (_, i) => (
                <TableRow key={i}>
                  {Array.from({ length: 6 }, (__, j) => (
                    <TableCell key={j}>
                      <Skeleton className="h-4 w-full" />
                    </TableCell>
                  ))}
                </TableRow>
              ))}
            {rows.map((user) => (
              <TableRow key={user.id} className="cursor-pointer" onClick={() => navigate(`/users/${user.id}`)}>
                <TableCell>
                  <span className="flex items-center gap-2">
                    {user.avatar && <img src={user.avatar} alt="" className="h-7 w-7 rounded-full" />}
                    <span className="leading-tight">
                      <span className="block font-medium">{user.name}</span>
                      <span className="block text-xs text-muted-foreground">{user.login}</span>
                    </span>
                  </span>
                </TableCell>
                <TableCell className="text-muted-foreground">{user.roles.join(', ') || '—'}</TableCell>
                <TableCell>
                  <When value={user.last_activity} />
                </TableCell>
                <TableCell>
                  <When value={user.last_login} />
                </TableCell>
                <TableCell className="text-right tabular-nums">{user.events.toLocaleString()}</TableCell>
                <TableCell className="text-right">
                  {user.failed_logins > 0 ? <Badge variant="critical">{user.failed_logins}</Badge> : <span className="text-muted-foreground">0</span>}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
        {users.data && rows.length === 0 && <p className="p-8 text-center text-sm text-muted-foreground">No users match.</p>}
        {users.isError && <p className="p-6 text-center text-sm text-destructive">{users.error.message}</p>}
      </div>

      {totalPages > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <span className="text-muted-foreground">
            Page {page} of {totalPages}
          </span>
          <Button variant="outline" size="icon" disabled={page <= 1} onClick={() => setPage(page - 1)} aria-label="Previous page">
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <Button variant="outline" size="icon" disabled={page >= totalPages} onClick={() => setPage(page + 1)} aria-label="Next page">
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      )}
    </div>
  );
}
