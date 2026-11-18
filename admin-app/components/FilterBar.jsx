import { useEffect, useMemo, useState } from 'react';
import { Search, SlidersHorizontal, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import MultiSelect from '@/components/MultiSelect';
import UserPicker from '@/components/UserPicker';
import { useDebounced } from '@/hooks/useDebounced';
import { hasFilters } from '@/lib/filters';
import { categoryLabel } from '@/lib/format';

const ALL = 'all';

const RANGE_OPTIONS = [
  { value: ALL, label: 'All time' },
  { value: '24h', label: 'Last 24 hours' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: 'custom', label: 'Custom range' },
];

const SEVERITY_OPTIONS = [
  { value: ALL, label: 'Any severity' },
  { value: 'notice', label: 'Notice and up' },
  { value: 'warning', label: 'Warning and up' },
  { value: 'critical', label: 'Critical only' },
];

/**
 * A text input that reports its value after typing pauses.
 */
function DebouncedInput({ value, onChange, ...props }) {
  const [text, setText] = useState(value);
  const debounced = useDebounced(text, 400);

  useEffect(() => setText(value), [value]);

  useEffect(() => {
    if (debounced !== value) onChange(debounced);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- Only react to typing.
  }, [debounced]);

  return <Input value={text} onChange={(e) => setText(e.target.value)} {...props} />;
}

/**
 * Filters for the activity log. Every change goes through `onChange(patch)`.
 *
 * @param {object}   props
 * @param {object}   props.filters  Current filter state.
 * @param {Function} props.onChange Called with changed keys.
 * @param {Function} props.onClear  Resets every filter.
 * @param {object}   props.meta     /meta data.
 */
export default function FilterBar({ filters, onChange, onClear, meta }) {
  const [more, setMore] = useState(Boolean(filters.object_type || filters.object_id || filters.ip || filters.session));

  const categories = useMemo(() => (meta?.categories ?? []).map((c) => ({ value: c.slug, label: c.label })), [meta]);
  const types = useMemo(
    () =>
      (meta?.event_types ?? [])
        .map((t) => ({ value: t.key, label: t.label, group: categoryLabel(t.category, meta) }))
        .sort((a, b) => a.group.localeCompare(b.group) || a.label.localeCompare(b.label)),
    [meta]
  );

  return (
    <div className="space-y-2 rounded-lg border bg-card p-3">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-[200px] flex-1">
          <Search className="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <DebouncedInput className="pl-8" value={filters.search} onChange={(search) => onChange({ search })} placeholder="Search messages and objects…" />
        </div>
        <Select value={filters.range || ALL} onValueChange={(range) => onChange({ range: range === ALL ? '' : range })}>
          <SelectTrigger className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {RANGE_OPTIONS.map((o) => (
              <SelectItem key={o.value} value={o.value}>
                {o.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {filters.range === 'custom' && (
          <>
            <Input type="date" className="w-40" value={filters.from} onChange={(e) => onChange({ from: e.target.value })} aria-label="From" />
            <Input type="date" className="w-40" value={filters.to} onChange={(e) => onChange({ to: e.target.value })} aria-label="To" />
          </>
        )}
        <UserPicker value={filters.user} onChange={(user) => onChange({ user })} />
        <Select value={filters.role || ALL} onValueChange={(role) => onChange({ role: role === ALL ? '' : role })}>
          <SelectTrigger className="w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>Any role</SelectItem>
            {(meta?.roles ?? []).map((r) => (
              <SelectItem key={r.slug} value={r.slug}>
                {r.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <MultiSelect label="Category" className="w-36" options={categories} value={filters.category} onChange={(category) => onChange({ category })} />
        <MultiSelect label="Event" className="w-36" options={types} value={filters.type} onChange={(type) => onChange({ type })} />
        <Select value={filters.severity || ALL} onValueChange={(severity) => onChange({ severity: severity === ALL ? '' : severity })}>
          <SelectTrigger className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {SEVERITY_OPTIONS.map((o) => (
              <SelectItem key={o.value} value={o.value}>
                {o.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Button variant="ghost" size="sm" onClick={() => setMore(!more)}>
          <SlidersHorizontal className="h-4 w-4" />
          More
        </Button>
        {hasFilters(filters) && (
          <Button variant="ghost" size="sm" onClick={onClear}>
            <X className="h-4 w-4" />
            Clear
          </Button>
        )}
      </div>
      {more && (
        <div className="flex flex-wrap items-center gap-2">
          <DebouncedInput className="w-40" value={filters.object_type} onChange={(object_type) => onChange({ object_type })} placeholder="Object type" />
          <DebouncedInput className="w-40" value={filters.object_id} onChange={(object_id) => onChange({ object_id })} placeholder="Object ID" />
          <DebouncedInput className="w-40" value={filters.ip} onChange={(ip) => onChange({ ip })} placeholder="IP address" />
          <DebouncedInput className="w-40" value={filters.session} onChange={(session) => onChange({ session })} placeholder="Session" />
        </div>
      )}
    </div>
  );
}
