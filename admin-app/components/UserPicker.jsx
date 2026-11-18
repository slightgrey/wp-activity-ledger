import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronDown, Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { useDebounced } from '@/hooks/useDebounced';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

/**
 * Picks users with an async search on GET /users?search=.
 *
 * @param {object}   props
 * @param {string[]} props.value    Selected user IDs (strings).
 * @param {Function} props.onChange Called with the new IDs.
 */
export default function UserPicker({ value, onChange }) {
  const [query, setQuery] = useState('');
  const [names, setNames] = useState({});
  const search = useDebounced(query.trim(), 300);

  const results = useQuery({
    queryKey: ['users-search', search],
    queryFn: async () => (await api('/users', { query: { search, per_page: 10 } })).data,
    enabled: search.length > 0,
  });

  const toggle = (user) => {
    const id = String(user.id);
    setNames((n) => ({ ...n, [id]: user.name || user.login }));
    onChange(value.includes(id) ? value.filter((v) => v !== id) : [...value, id]);
  };

  const summary = value.length === 0 ? 'User' : value.length === 1 ? (names[value[0]] ?? `User #${value[0]}`) : `Users: ${value.length}`;

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" className={cn('h-9 w-40 justify-between font-normal', value.length && 'border-primary')}>
          <span className="truncate">{summary}</span>
          <ChevronDown className="h-4 w-4 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-72 p-0">
        <div className="border-b p-2">
          <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search users…" autoFocus />
        </div>
        <div className="max-h-64 overflow-y-auto p-1">
          {search.length === 0 && <p className="p-2 text-sm text-muted-foreground">Type a name, login or email.</p>}
          {results.isFetching && <p className="p-2 text-sm text-muted-foreground">Searching…</p>}
          {results.data?.length === 0 && <p className="p-2 text-sm text-muted-foreground">No users found.</p>}
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
              Clear selection
            </Button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}
