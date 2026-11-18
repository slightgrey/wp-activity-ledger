import { diffLines } from 'diff';
import { ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import { __, sprintf } from '@/lib/i18n';

const LONG_TEXT = 120;

/**
 * Tells whether a value is a plain object or array.
 */
function isStructured(value) {
  return value !== null && typeof value === 'object';
}

/**
 * Tells whether two values should be compared line by line.
 */
function isLongText(oldValue, newValue) {
  const texts = [oldValue, newValue].filter((v) => typeof v === 'string');

  return texts.length > 0 && texts.every((v) => v === null || typeof v === 'string') && texts.some((v) => v.length > LONG_TEXT || v.includes('\n'));
}

/**
 * Shows a scalar value; null as an em dash, empty strings as "(empty)".
 */
function Scalar({ value }) {
  if (value === null || value === undefined) return <span className="text-muted-foreground">—</span>;
  if (value === '') return <span className="italic text-muted-foreground">{__( '(empty)', 'wp-activity-ledger' )}</span>;
  if (typeof value === 'boolean') return <code>{value ? 'true' : 'false'}</code>;

  return <span className="break-all">{String(value)}</span>;
}

/**
 * Renders arrays and objects as a collapsible tree.
 */
export function JsonTree({ value, depth = 0 }) {
  if (!isStructured(value)) return <Scalar value={value} />;

  const entries = Array.isArray(value) ? value.map((v, i) => [i, v]) : Object.entries(value);

  if (entries.length === 0) return <code className="text-muted-foreground">{Array.isArray(value) ? '[]' : '{}'}</code>;

  return (
    <details open={depth < 1} className="text-xs">
      <summary className="cursor-pointer text-muted-foreground">
        {Array.isArray(value) ? sprintf( __( 'Array (%s)', 'wp-activity-ledger' ), entries.length ) : sprintf( __( 'Object (%s)', 'wp-activity-ledger' ), entries.length )}
      </summary>
      <ul className="ml-3 border-l pl-2">
        {entries.map(([key, item]) => (
          <li key={key} className="py-0.5">
            <span className="font-mono text-muted-foreground">{key}: </span>
            <JsonTree value={item} depth={depth + 1} />
          </li>
        ))}
      </ul>
    </details>
  );
}

/**
 * A line diff of two texts: removed lines red, added lines green.
 */
function LineDiff({ oldValue, newValue }) {
  // A missing final newline would otherwise mark the unchanged last line as changed.
  const withEol = (text) => (text && !text.endsWith('\n') ? `${text}\n` : text ?? '');
  const parts = diffLines(withEol(oldValue), withEol(newValue));

  return (
    <pre className="max-h-96 overflow-auto rounded-md border bg-muted/40 p-2 font-mono text-xs leading-5">
      {parts.map((part, index) => (
        <span
          key={index}
          className={cn('block whitespace-pre-wrap break-all', part.added && 'bg-green-100 text-green-900', part.removed && 'bg-red-100 text-red-900 line-through decoration-red-400/60')}
        >
          {part.value
            .replace(/\n$/, '')
            .split('\n')
            .map((line) => `${part.added ? '+ ' : part.removed ? '- ' : '  '}${line}`)
            .join('\n')}
        </span>
      ))}
    </pre>
  );
}

/**
 * One change: field name and its old → new value in the best format for its type.
 */
function ChangeRow({ change }) {
  const { field, old: oldValue, new: newValue, truncated } = change;

  let body;

  if (isStructured(oldValue) || isStructured(newValue)) {
    body = (
      <div className="grid gap-2 md:grid-cols-2">
        <div className="rounded-md border border-red-200 bg-red-50/50 p-2">
          <div className="mb-1 text-xs font-medium text-red-800">{__( 'Before', 'wp-activity-ledger' )}</div>
          <JsonTree value={oldValue} />
        </div>
        <div className="rounded-md border border-green-200 bg-green-50/50 p-2">
          <div className="mb-1 text-xs font-medium text-green-800">{__( 'After', 'wp-activity-ledger' )}</div>
          <JsonTree value={newValue} />
        </div>
      </div>
    );
  } else if (isLongText(oldValue, newValue)) {
    body = <LineDiff oldValue={oldValue} newValue={newValue} />;
  } else {
    body = (
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span className="rounded bg-red-50 px-1.5 py-0.5 text-red-900">
          <Scalar value={oldValue} />
        </span>
        <ArrowRight className="h-3.5 w-3.5 text-muted-foreground" />
        <span className="rounded bg-green-50 px-1.5 py-0.5 text-green-900">
          <Scalar value={newValue} />
        </span>
      </div>
    );
  }

  return (
    <li className="space-y-1.5 py-2">
      <div className="flex items-center gap-2">
        <code className="text-xs font-semibold">{field}</code>
        {truncated && <span className="text-xs text-amber-700">{__( 'truncated: values were longer than the stored limit', 'wp-activity-ledger' )}</span>}
      </div>
      {body}
    </li>
  );
}

/**
 * The list of field changes of an event.
 *
 * @param {object} props
 * @param {Array<{field: string, old: any, new: any, truncated: boolean}>} props.changes Changes.
 */
export default function DiffView({ changes }) {
  if (!changes?.length) return <p className="text-sm text-muted-foreground">{__( 'No field changes were recorded for this event.', 'wp-activity-ledger' )}</p>;

  return (
    <ul className="divide-y">
      {changes.map((change, index) => (
        <ChangeRow key={`${change.field}-${index}`} change={change} />
      ))}
    </ul>
  );
}
