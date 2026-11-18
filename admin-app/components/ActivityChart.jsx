import { useMemo, useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { GROUPS, bucketKeys, bucketLabel, chartRows } from '@/lib/categoryGroups';
import { config } from '@/lib/config';
import { __, sprintf } from '@/lib/i18n';

const SURFACE = '#ffffff';
const GRID = '#e8e8e4';
const MUTED = '#6b6a66';

/**
 * Hover tooltip: the bucket, each group with events (swatch + label + count), and the total.
 */
function ChartTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null;

  const row = payload[0].payload;
  const groups = GROUPS.filter((g) => row[g.key] > 0);

  return (
    <div className="min-w-[180px] rounded-md border bg-popover p-2.5 text-xs shadow-md">
      <div className="mb-1.5 font-medium text-foreground">{label}</div>
      {groups.map((g) => (
        <div key={g.key} className="flex items-center justify-between gap-4 py-0.5">
          <span className="flex items-center gap-1.5 text-muted-foreground">
            <span className="h-2.5 w-2.5 rounded-sm" style={{ background: g.color }} />
            {g.label}
          </span>
          <span className="tabular-nums text-foreground">{row[g.key].toLocaleString()}</span>
        </div>
      ))}
      <div className="mt-1 flex justify-between border-t pt-1 font-medium text-foreground">
        <span>{__( 'Total', 'wp-activity-ledger' )}</span>
        <span className="tabular-nums">{row.total.toLocaleString()}</span>
      </div>
    </div>
  );
}

/**
 * Events over time, stacked by category group, with a legend and a table view.
 *
 * @param {object} props
 * @param {object} props.stats /stats response.
 */
export default function ActivityChart({ stats }) {
  const [asTable, setAsTable] = useState(false);
  const { from, to, bucket } = stats.range;

  const rows = useMemo(() => chartRows(stats.by_day, bucketKeys(from, to, bucket, config.timezone)), [stats, from, to, bucket]);
  const shown = GROUPS.filter((g) => rows.some((r) => r[g.key] > 0));
  const top = shown[shown.length - 1]?.key;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground" aria-label={__( 'Legend', 'wp-activity-ledger' )}>
          {GROUPS.map((g) => (
            <li key={g.key} className="flex items-center gap-1.5">
              <span className="h-2.5 w-2.5 rounded-sm" style={{ background: g.color }} />
              {g.label}
            </li>
          ))}
        </ul>
        <Button variant="ghost" size="sm" onClick={() => setAsTable(!asTable)}>
          {asTable ? __( 'Show chart', 'wp-activity-ledger' ) : __( 'Show table', 'wp-activity-ledger' )}
        </Button>
      </div>

      {asTable ? (
        <div className="max-h-80 overflow-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{bucket === 'hour' ? __( 'Hour', 'wp-activity-ledger' ) : __( 'Day', 'wp-activity-ledger' )}</TableHead>
                {GROUPS.map((g) => (
                  <TableHead key={g.key} className="text-right">
                    {g.label}
                  </TableHead>
                ))}
                <TableHead className="text-right">{__( 'Total', 'wp-activity-ledger' )}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((r) => (
                <TableRow key={r.bucket}>
                  <TableCell>{r.bucket}</TableCell>
                  {GROUPS.map((g) => (
                    <TableCell key={g.key} className="text-right tabular-nums">
                      {r[g.key]}
                    </TableCell>
                  ))}
                  <TableCell className="text-right font-medium tabular-nums">{r.total}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      ) : (
        <div className="h-64" role="img" aria-label={sprintf( __( 'Events per %s, stacked by category', 'wp-activity-ledger' ), bucket )}>
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={rows} margin={{ top: 4, right: 4, bottom: 0, left: -12 }} barCategoryGap={bucket === 'hour' ? 2 : 6}>
              <CartesianGrid vertical={false} stroke={GRID} />
              <XAxis
                dataKey="bucket"
                tickFormatter={bucketLabel}
                tick={{ fontSize: 11, fill: MUTED }}
                tickLine={false}
                axisLine={{ stroke: GRID }}
                interval="preserveStartEnd"
                minTickGap={16}
              />
              <YAxis allowDecimals={false} tick={{ fontSize: 11, fill: MUTED }} tickLine={false} axisLine={false} width={44} />
              <Tooltip content={<ChartTooltip />} cursor={{ fill: 'hsl(var(--wpal-muted))' }} labelFormatter={(key) => key} isAnimationActive={false} />
              {shown.map((g) => (
                <Bar
                  key={g.key}
                  dataKey={g.key}
                  name={g.label}
                  stackId="events"
                  fill={g.color}
                  stroke={SURFACE}
                  strokeWidth={1}
                  maxBarSize={28}
                  radius={g.key === top ? [4, 4, 0, 0] : 0}
                  isAnimationActive={false}
                />
              ))}
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  );
}
