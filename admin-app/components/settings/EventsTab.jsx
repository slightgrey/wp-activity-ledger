import { useMemo, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { NumberRow, PatternRow } from '@/components/settings/fields';

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
            ({types.length - off} of {types.length} recorded)
          </span>
        </button>
        <Switch checked={off === 0} onCheckedChange={(checked) => onToggleAll(types, checked)} aria-label={`Record all ${category.label} events`} />
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
        <div className="mb-2 text-sm font-medium">Recorded events</div>
        <p className="mb-2 text-xs text-muted-foreground">Switch off event types you never need. Activity Ledger&apos;s own events are always recorded.</p>
        <div className="rounded-md border px-3">
          {groups.map((g) => (
            <CategoryTypes key={g.category.slug} category={g.category} types={g.types} disabled={disabled} onToggle={toggle} onToggleAll={toggleAll} />
          ))}
        </div>
      </div>
      <PatternRow
        name="ignored_option_patterns"
        label="Ignored options"
        help="Option names never recorded, one per line. * matches anything. WordPress's own busy options are always ignored."
        draft={draft}
        set={set}
        errors={errors}
      />
      <PatternRow name="ignored_meta_keys" label="Ignored meta keys" help="Post and user meta keys never recorded, one per line." draft={draft} set={set} errors={errors} />
      <PatternRow
        name="redact_patterns"
        label="Redacted fields"
        help="Fields whose values are stored as [redacted], one per line (passwords, keys, tokens)."
        draft={draft}
        set={set}
        errors={errors}
      />
      <NumberRow
        name="max_value_size"
        label="Largest stored value"
        help="Longer before/after values are cut and marked as truncated."
        suffix="bytes"
        min={1024}
        draft={draft}
        set={set}
        errors={errors}
      />
    </div>
  );
}
