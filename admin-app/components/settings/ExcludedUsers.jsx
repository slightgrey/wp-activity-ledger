import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ChevronRight, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDebounced } from '@/hooks/useDebounced';
import { api } from '@/lib/api';
import { __, sprintf } from '@/lib/i18n';

const PER_PAGE = 50;

/**
 * One user line: avatar, login and display name.
 */
function UserLine({ user, id }) {
  return (
    <>
      {user?.avatar ? <img src={user.avatar} alt="" className="h-6 w-6 shrink-0 rounded-full" /> : <span className="h-6 w-6 shrink-0 rounded-full bg-muted" />}
      <span className="min-w-0 flex-1 truncate">
        {user ? user.login : sprintf( __( 'User #%s', 'wp-activity-ledger' ), id )}
        {user?.name && user.name !== user.login && <span className="text-muted-foreground"> ({user.name})</span>}
      </span>
    </>
  );
}

/**
 * Two lists for the "Never record these users" setting: every user on the left (searchable),
 * the excluded users on the right. Clicking a user moves it to the other list.
 *
 * @param {object}   props
 * @param {number[]} props.value    Excluded user IDs.
 * @param {Function} props.onChange Called with the new IDs.
 */
export default function ExcludedUsers({ value, onChange }) {
  const [query, setQuery] = useState('');
  const [picked, setPicked] = useState({});
  const search = useDebounced(query.trim(), 300);
  const excluded = new Set(value.map(Number));

  const all = useQuery({
    queryKey: ['users-all', search],
    queryFn: () => api('/users', { query: { search, per_page: PER_PAGE, orderby: 'login' } }),
    placeholderData: keepPreviousData,
  });

  // Users excluded before this visit: look up their login and name once.
  const missing = value.map(Number).filter((id) => id > 0 && !picked[id]).sort((a, b) => a - b);
  const lookup = useQuery({
    queryKey: ['users-include', missing],
    queryFn: async () => (await api('/users', { query: { include: missing, per_page: 100 } })).data,
    enabled: missing.length > 0,
    staleTime: 5 * 60 * 1000,
  });

  const known = { ...picked };
  for (const user of lookup.data ?? []) known[user.id] = user;

  const available = (all.data?.data ?? []).filter((user) => !excluded.has(user.id));
  const total = all.data?.total ?? 0;

  const add = (user) => {
    setPicked((p) => ({ ...p, [user.id]: user }));
    onChange([...value.map(Number), user.id]);
  };
  const remove = (id) => onChange(value.map(Number).filter((v) => v !== id));

  const rowClass = 'group flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent focus:outline-none focus-visible:ring-2 focus-visible:ring-ring';

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      <div className="flex min-w-0 flex-col rounded-md border">
        <div className="space-y-2 border-b p-2">
          <p className="text-xs font-medium text-muted-foreground">{__( 'All users', 'wp-activity-ledger' )}</p>
          <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder={__( 'Search users…', 'wp-activity-ledger' )} aria-label={__( 'Search users', 'wp-activity-ledger' )} className="h-8" />
        </div>
        <ul className="h-56 overflow-y-auto p-1">
          {all.isLoading && <li className="p-2 text-sm text-muted-foreground">{__( 'Loading…', 'wp-activity-ledger' )}</li>}
          {all.isError && <li className="p-2 text-sm text-destructive">{all.error.message}</li>}
          {all.data && available.length === 0 && <li className="p-2 text-sm text-muted-foreground">{__( 'No users found.', 'wp-activity-ledger' )}</li>}
          {available.map((user) => (
            <li key={user.id}>
              <button type="button" className={rowClass} onClick={() => add(user)} aria-label={sprintf( __( 'Stop recording %s', 'wp-activity-ledger' ), user.login )}>
                <UserLine user={user} />
                <ChevronRight className="h-4 w-4 shrink-0 opacity-0 group-hover:opacity-60 group-focus-visible:opacity-60" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
        {total > PER_PAGE && (
          <p className="border-t px-2 py-1.5 text-xs text-muted-foreground">
            {sprintf( __( 'Showing %1$s of %2$s. Search to find others.', 'wp-activity-ledger' ), PER_PAGE, total.toLocaleString() )}
          </p>
        )}
      </div>

      <div className="flex min-w-0 flex-col rounded-md border">
        <div className="flex items-center justify-between gap-2 border-b p-2">
          <p className="text-xs font-medium text-muted-foreground">{sprintf( __( 'Not recorded (%s)', 'wp-activity-ledger' ), value.length )}</p>
          {value.length > 0 && (
            <Button variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => onChange([])}>
              {__( 'Remove all', 'wp-activity-ledger' )}
            </Button>
          )}
        </div>
        <ul className="h-56 overflow-y-auto p-1">
          {value.length === 0 && <li className="p-2 text-sm text-muted-foreground">{__( 'Every user is recorded. Click a user on the left to stop recording them.', 'wp-activity-ledger' )}</li>}
          {value.map(Number).map((id) => (
            <li key={id}>
              <button type="button" className={rowClass} onClick={() => remove(id)} aria-label={sprintf( __( 'Record %s again', 'wp-activity-ledger' ), known[id]?.login ?? id )}>
                <UserLine user={known[id]} id={id} />
                <X className="h-4 w-4 shrink-0 opacity-40 group-hover:opacity-80" aria-hidden="true" />
              </button>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
