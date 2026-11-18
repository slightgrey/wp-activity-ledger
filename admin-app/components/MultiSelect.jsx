import { useMemo, useState } from 'react';
import { Check, ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * A searchable multiselect in a popover, with optional groups.
 *
 * @param {object}   props
 * @param {string}   props.label    Button label when nothing is selected.
 * @param {Array<{value: string, label: string, group?: string}>} props.options Options.
 * @param {string[]} props.value    Selected values.
 * @param {Function} props.onChange Called with the new selection.
 */
export default function MultiSelect({ label, options, value, onChange, className }) {
  const [query, setQuery] = useState('');
  const selected = new Set(value);

  const groups = useMemo(() => {
    const needle = query.trim().toLowerCase();
    const map = new Map();

    for (const option of options) {
      if (needle && !`${option.label} ${option.value}`.toLowerCase().includes(needle)) continue;

      const group = option.group ?? '';
      if (!map.has(group)) map.set(group, []);
      map.get(group).push(option);
    }

    return [...map.entries()];
  }, [options, query]);

  const toggle = (option) => {
    const next = new Set(selected);
    next.has(option) ? next.delete(option) : next.add(option);
    onChange([...next]);
  };

  const summary =
    value.length === 0 ? label : value.length === 1 ? (options.find((o) => o.value === value[0])?.label ?? value[0]) : `${label}: ${value.length}`;

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="outline" className={cn('h-9 justify-between font-normal', value.length && 'border-primary', className)}>
          <span className="truncate">{summary}</span>
          <ChevronDown className="h-4 w-4 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-80 p-0">
        <div className="border-b p-2">
          <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search…" autoFocus />
        </div>
        <div className="max-h-72 overflow-y-auto p-1">
          {groups.length === 0 && <p className="p-2 text-sm text-muted-foreground">No matches.</p>}
          {groups.map(([group, items]) => (
            <div key={group}>
              {group && <div className="px-2 pb-1 pt-2 text-xs font-semibold uppercase text-muted-foreground">{group}</div>}
              {items.map((option) => (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => toggle(option.value)}
                  className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                >
                  <span className={cn('flex h-4 w-4 items-center justify-center rounded border', selected.has(option.value) && 'border-primary bg-primary text-primary-foreground')}>
                    {selected.has(option.value) && <Check className="h-3 w-3" />}
                  </span>
                  <span className="truncate">{option.label}</span>
                </button>
              ))}
            </div>
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
