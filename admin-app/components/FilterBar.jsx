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
import { __ } from '@/lib/i18n';

const ALL = 'all';

const RANGE_OPTIONS = [
  { value: ALL, label: __( 'All time', 'wp-activity-ledger' ) },
  { value: '24h', label: __( 'Last 24 hours', 'wp-activity-ledger' ) },
  { value: '7d', label: __( 'Last 7 days', 'wp-activity-ledger' ) },
  { value: '30d', label: __( 'Last 30 days', 'wp-activity-ledger' ) },
  { value: 'custom', label: __( 'Custom range', 'wp-activity-ledger' ) },
];

const SEVERITY_OPTIONS = [
  { value: ALL, label: __( 'Any severity', 'wp-activity-ledger' ) },
  { value: 'notice', label: __( 'Notice and up', 'wp-activity-ledger' ) },
  { value: 'warning', label: __( 'Warning and up', 'wp-activity-ledger' ) },
  { value: 'critical', label: __( 'Critical only', 'wp-activity-ledger' ) },
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
 * @param {string[]} props.hidden   Filters that are fixed by the page (not shown).
 */
export default function FilterBar({ filters, onChange, onClear, meta, hidden = [] }) {
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
          <DebouncedInput className="pl-8" value={filters.search} onChange={(search) => onChange({ search })} placeholder={__( 'Search messages and objects…', 'wp-activity-ledger' )} />
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
            <Input type="date" className="w-40" value={filters.from} onChange={(e) => onChange({ from: e.target.value })} aria-label={__( 'From', 'wp-activity-ledger' )} />
            <Input type="date" className="w-40" value={filters.to} onChange={(e) => onChange({ to: e.target.value })} aria-label={__( 'To', 'wp-activity-ledger' )} />
          </>
        )}
        {!hidden.includes('user') && <UserPicker value={filters.user} onChange={(user) => onChange({ user })} />}
        <Select value={filters.role || ALL} onValueChange={(role) => onChange({ role: role === ALL ? '' : role })}>
          <SelectTrigger className="w-36">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>{__( 'Any role', 'wp-activity-ledger' )}</SelectItem>
            {(meta?.roles ?? []).map((r) => (
              <SelectItem key={r.slug} value={r.slug}>
                {r.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <MultiSelect label={__( 'Category', 'wp-activity-ledger' )} className="w-36" options={categories} value={filters.category} onChange={(category) => onChange({ category })} />
        <MultiSelect label={__( 'Event', 'wp-activity-ledger' )} className="w-36" options={types} value={filters.type} onChange={(type) => onChange({ type })} />
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
          {__( 'More', 'wp-activity-ledger' )}
        </Button>
        {hasFilters(Object.fromEntries(Object.entries(filters).filter(([key]) => !hidden.includes(key)))) && (
          <Button variant="ghost" size="sm" onClick={onClear}>
            <X className="h-4 w-4" />
            {__( 'Clear', 'wp-activity-ledger' )}
          </Button>
        )}
      </div>
      {more && (
        <div className="flex flex-wrap items-center gap-2">
          <DebouncedInput className="w-40" value={filters.object_type} onChange={(object_type) => onChange({ object_type })} placeholder={__( 'Object type', 'wp-activity-ledger' )} />
          <DebouncedInput className="w-40" value={filters.object_id} onChange={(object_id) => onChange({ object_id })} placeholder={__( 'Object ID', 'wp-activity-ledger' )} />
          <DebouncedInput className="w-40" value={filters.ip} onChange={(ip) => onChange({ ip })} placeholder={__( 'IP address', 'wp-activity-ledger' )} />
          <DebouncedInput className="w-40" value={filters.session} onChange={(session) => onChange({ session })} placeholder={__( 'Session', 'wp-activity-ledger' )} />
        </div>
      )}
    </div>
  );
}
