import { useMemo, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { NumberRow, PatternRow } from '@/components/settings/fields';
import { __, sprintf } from '@/lib/i18n';

/**
 * One category's event types, each with an on/off switch.
 */
function CategoryTypes({ category, types, disabled, onToggle, onToggleAll }) {
  const [open, setOpen] = useState(false);
  const off = types.filter((t) => disabled.includes(t.key)).length;

  return (
    <div className="border-b last:border-0">
      <div className="flex items-center justify-between gap-2 py-2">
        <button type="button" onClick={() => setOpen(!open)} className="flex items-center gap-1 text-sm font-medium">
          {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
          {category.label}
          <span className="font-normal text-muted-foreground">
            ({types.length - off} of {types.length}{' '}{__( 'recorded)', 'wp-activity-ledger' )}
          </span>
        </button>
        <Switch checked={off === 0} onCheckedChange={(checked) => onToggleAll(types, checked)} aria-label={sprintf( __( 'Record all %s events', 'wp-activity-ledger' ), category.label )} />
      </div>
      {open && (
        <ul className="mb-2 grid gap-1 pl-5 md:grid-cols-2">
          {types.map((t) => (
            <li key={t.key} className="flex items-center justify-between gap-2 rounded px-2 py-1 text-sm hover:bg-muted/50">
              <span>
                {t.label} <code className="text-xs text-muted-foreground">{t.key}</code>
              </span>
              <Switch checked={!disabled.includes(t.key)} onCheckedChange={(checked) => onToggle(t.key, checked)} aria-label={t.label} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

/**
 * Which events are recorded, what is ignored or redacted, and the value size limit.
 */
export default function EventsTab({ draft, set, errors, meta }) {
  const disabled = draft.disabled_event_types ?? [];
  const groups = useMemo(
    () =>
      (meta?.categories ?? [])
        .map((category) => ({ category, types: (meta?.event_types ?? []).filter((t) => t.category === category.slug) }))
        .filter((g) => g.types.length > 0),
    [meta]
  );

  const toggle = (key, on) => set({ disabled_event_types: on ? disabled.filter((k) => k !== key) : [...new Set([...disabled, key])] });
  const toggleAll = (types, on) => {
    const keys = types.map((t) => t.key);
    set({ disabled_event_types: on ? disabled.filter((k) => !keys.includes(k)) : [...new Set([...disabled, ...keys])] });
  };

  return (
    <div>
      <div className="border-b py-4">
        <div className="mb-2 text-sm font-medium">{__( 'Recorded events', 'wp-activity-ledger' )}</div>
        <p className="mb-2 text-xs text-muted-foreground">{__( 'Switch off event types you never need. Activity Ledger\'s own events are always recorded.', 'wp-activity-ledger' )}</p>
        <div className="rounded-md border px-3">
          {groups.map((g) => (
            <CategoryTypes key={g.category.slug} category={g.category} types={g.types} disabled={disabled} onToggle={toggle} onToggleAll={toggleAll} />
          ))}
        </div>
      </div>
      <PatternRow
        name="ignored_option_patterns"
        label={__( 'Ignored options', 'wp-activity-ledger' )}
        help={__( 'Option names never recorded, one per line. * matches anything. WordPress\'s own busy options are always ignored.', 'wp-activity-ledger' )}
        draft={draft}
        set={set}
        errors={errors}
      />
      <PatternRow name="ignored_meta_keys" label={__( 'Ignored meta keys', 'wp-activity-ledger' )} help={__( 'Post and user meta keys never recorded, one per line.', 'wp-activity-ledger' )} draft={draft} set={set} errors={errors} />
      <PatternRow
        name="redact_patterns"
        label={__( 'Redacted fields', 'wp-activity-ledger' )}
        help={__( 'Fields whose values are stored as [redacted], one per line (passwords, keys, tokens).', 'wp-activity-ledger' )}
        draft={draft}
        set={set}
        errors={errors}
      />
      <NumberRow
        name="max_value_size"
        label={__( 'Largest stored value', 'wp-activity-ledger' )}
        help={__( 'Longer before/after values are cut and marked as truncated.', 'wp-activity-ledger' )}
        suffix="bytes"
        min={1024}
        draft={draft}
        set={set}
        errors={errors}
      />
    </div>
  );
}
