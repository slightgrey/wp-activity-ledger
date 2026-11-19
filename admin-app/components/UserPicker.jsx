import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, Check, Cpu, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useDebounced } from '@/hooks/useDebounced';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import { __, sprintf } from '@/lib/i18n';

// Events without a logged-in user (cron, WP-CLI, remote managers) are stored as user 0.
const SYSTEM = { id: 0, name: __( 'System', 'wp-activity-ledger' ), login: '' };

/**
 * Picks users with an async search on GET /users?search=. "System" (user 0) is offered
 * first; picking it shows every System event, even when the log hides them.
 *
 * Picked users are shown by login. Logins of IDs picked earlier (saved settings, a shared
 * URL) are looked up with GET /users?include[]=.
 *
 * @param {object}   props
 * @param {string[]} props.value          Selected user IDs (strings).
 * @param {Function} props.onChange       Called with the new IDs.
 * @param {boolean}  [props.showSelected] Also list the picked users below the button, each removable.
 */
export default function UserPicker({ value, onChange, showSelected = false }) {
  const [query, setQuery] = useState('');
  const [names, setNames] = useState({ 0: SYSTEM.name });
  const search = useDebounced(query.trim(), 300);
  const showSystem = SYSTEM.name.toLowerCase().includes(query.trim().toLowerCase());

  const results = useQuery({
    queryKey: ['users-search', search],
    queryFn: async () => (await api('/users', { query: { search, per_page: 10 } })).data,
    enabled: search.length > 0,
  });

  const missing = value.filter((id) => Number(id) > 0 && !(id in names)).sort((a, b) => a - b);
  const lookup = useQuery({
    queryKey: ['users-include', missing],
    queryFn: async () => (await api('/users', { query: { include: missing, per_page: 100 } })).data,
    enabled: missing.length > 0,
    staleTime: 5 * 60 * 1000,
  });

  const known = { ...names };
  for (const user of lookup.data ?? []) {
    known[String(user.id)] = user.login;
  }

  const label = (id) => known[id] ?? sprintf( __( 'User #%s', 'wp-activity-ledger' ), id );

  const toggle = (user) => {
    const id = String(user.id);
    setNames((n) => ({ ...n, [id]: user.id === 0 ? SYSTEM.name : user.login }));
    onChange(value.includes(id) ? value.filter((v) => v !== id) : [...value, id]);
  };

  const remove = (id) => onChange(value.filter((v) => v !== id));

  const allLabels = value.map(label).join(', ');
  const summary =
    value.length === 0
      ? __( 'User', 'wp-activity-ledger' )
      : value.length > 1 && lookup.isLoading
        ? sprintf( __( 'Users: %s', 'wp-activity-ledger' ), value.length )
        : allLabels;

  return (
    <div className="space-y-2">
      <Popover>
        <PopoverTrigger asChild>
          <Button variant="outline" className={cn('h-9 w-40 justify-between font-normal', value.length && 'border-primary')} title={value.length > 1 ? allLabels : undefined}>
            <span className="truncate">{summary}</span>
            <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-72 p-0">
          <div className="border-b p-2">
            <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder={__( 'Search users…', 'wp-activity-ledger' )} autoFocus />
          </div>
          <div className="max-h-64 overflow-y-auto p-1">
            {showSystem && (
              <button
                type="button"
                onClick={() => toggle(SYSTEM)}
                className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
              >
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-muted-foreground">
                  <Cpu className="h-3.5 w-3.5" aria-hidden="true" />
                </span>
                <span className="flex-1 truncate">
                  {SYSTEM.name} <span className="text-muted-foreground">{__( '(no logged-in user)', 'wp-activity-ledger' )}</span>
                </span>
                {value.includes('0') && <Check className="h-4 w-4" />}
              </button>
            )}
            {search.length === 0 && <p className="p-2 text-sm text-muted-foreground">{__( 'Type a name, login or email.', 'wp-activity-ledger' )}</p>}
            {results.isFetching && <p className="p-2 text-sm text-muted-foreground">{__( 'Searching…', 'wp-activity-ledger' )}</p>}
            {results.data?.length === 0 && <p className="p-2 text-sm text-muted-foreground">{__( 'No users found.', 'wp-activity-ledger' )}</p>}
            {results.data?.map((user) => (
              <button
                key={user.id}
                type="button"
                onClick={() => toggle(user)}
                className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
              >
                {user.avatar && <img src={user.avatar} alt="" className="h-6 w-6 rounded-full" />}
                <span className="flex-1 truncate">
                  {user.name} <span className="text-muted-foreground">({user.login})</span>
                </span>
                {value.includes(String(user.id)) && <Check className="h-4 w-4" />}
              </button>
            ))}
          </div>
          {value.length > 0 && (
            <div className="border-t p-1">
              <Button variant="ghost" size="sm" className="w-full" onClick={() => onChange([])}>
                {__( 'Clear selection', 'wp-activity-ledger' )}
              </Button>
            </div>
          )}
        </PopoverContent>
      </Popover>
      {showSelected && value.length > 0 && (
        <ul className="flex flex-wrap gap-1.5" aria-label={__( 'Selected users', 'wp-activity-ledger' )}>
          {value.map((id) => (
            <li key={id}>
              <Badge variant="secondary" className="gap-1 pr-1">
                {label(id)}
                <button
                  type="button"
                  onClick={() => remove(id)}
                  className="rounded-sm p-0.5 hover:bg-background/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  aria-label={sprintf( __( 'Remove %s', 'wp-activity-ledger' ), label(id) )}
                >
                  <X className="h-3 w-3" aria-hidden="true" />
                </button>
              </Badge>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
