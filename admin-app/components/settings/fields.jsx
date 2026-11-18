import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import FieldError from '@/components/FieldError';

/**
 * A settings row: label and help on the left, control on the right.
 */
export function Row({ label, help, htmlFor, children, error }) {
  return (
    <div className="grid gap-2 border-b py-4 last:border-0 md:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)] md:gap-6">
      <div>
        <Label htmlFor={htmlFor} className="text-sm font-medium">
          {label}
        </Label>
        {help && <p className="mt-1 text-xs text-muted-foreground">{help}</p>}
      </div>
      <div className="grid content-start gap-1">
        {children}
        {error}
      </div>
    </div>
  );
}

/**
 * An on/off setting.
 */
export function ToggleRow({ name, label, help, draft, set }) {
  const id = `wpal-set-${name}`;

  return (
    <Row label={label} help={help} htmlFor={id}>
      <Switch id={id} checked={Boolean(draft[name])} onCheckedChange={(checked) => set({ [name]: checked })} />
    </Row>
  );
}

/**
 * A number setting.
 */
export function NumberRow({ name, label, help, draft, set, errors, min = 0, suffix }) {
  const id = `wpal-set-${name}`;

  return (
    <Row label={label} help={help} htmlFor={id} error={<FieldError errors={errors} field={name} />}>
      <div className="flex items-center gap-2">
        <Input id={id} type="number" min={min} className="w-36" value={draft[name] ?? ''} onChange={(e) => set({ [name]: e.target.value === '' ? '' : Number(e.target.value) })} />
        {suffix && <span className="text-sm text-muted-foreground">{suffix}</span>}
      </div>
    </Row>
  );
}

/**
 * A list of patterns edited as one per line.
 */
export function PatternRow({ name, label, help, draft, set, errors }) {
  const id = `wpal-set-${name}`;
  const [text, setText] = useState(() => (draft[name] ?? []).join('\n'));

  useEffect(() => setText((draft[name] ?? []).join('\n')), [draft, name]);

  return (
    <Row label={label} help={help} htmlFor={id} error={<FieldError errors={errors} field={name} />}>
      <textarea
        id={id}
        rows={6}
        className="w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
        value={text}
        onChange={(e) => setText(e.target.value)}
        onBlur={() =>
          set({
            [name]: text
              .split('\n')
              .map((line) => line.trim())
              .filter(Boolean),
          })
        }
      />
    </Row>
  );
}
