import { ExternalLink } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import DiffView, { JsonTree } from '@/components/DiffView';
import { useEvent } from '@/hooks/useEvents';
import { config } from '@/lib/config';
import { categoryLabel, formatDateTime, relativeTime, severity, typeLabel } from '@/lib/format';

/**
 * A label/value row of the details table.
 */
function Row({ label, children }) {
  return (
    <tr className="border-b last:border-0">
      <th className="w-36 py-1.5 pr-3 text-left align-top text-xs font-medium text-muted-foreground">{label}</th>
      <td className="py-1.5 align-top text-sm">{children}</td>
    </tr>
  );
}

/**
 * The details of one event: who, what, where from, its context and its changes.
 *
 * @param {object}   props
 * @param {number}   props.eventId  Event ID (closed when null).
 * @param {Function} props.onClose  Closes the drawer.
 * @param {Function} props.onFilter Applies filters to the log (session or user links).
 * @param {object}   props.meta     /meta data.
 */
export default function EventDrawer({ eventId, onClose, onFilter, meta }) {
  const { data: event, isLoading, isError, error } = useEvent(eventId);
  const level = event ? severity(event.severity) : null;
  const request = event?.context?.request ?? {};
  const extra = event ? Object.fromEntries(Object.entries(event.context ?? {}).filter(([key]) => key !== 'request')) : {};

  return (
    <Sheet open={Boolean(eventId)} onOpenChange={(open) => !open && onClose()}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{event ? event.message : 'Event'}</SheetTitle>
          <SheetDescription>
            {event && (
              <span className="flex flex-wrap items-center gap-2">
                <span>{typeLabel(event.event_type, meta)}</span>
                <Badge variant={level.variant}>{level.slug}</Badge>
                <span>#{event.id}</span>
              </span>
            )}
          </SheetDescription>
        </SheetHeader>

        {isLoading && <Skeleton className="h-40 w-full" />}
        {isError && <p className="text-sm text-destructive">{error.message}</p>}

        {event && (
          <>
            <table className="w-full">
              <tbody>
                <Row label="When">
                  {formatDateTime(event.created_at, config.timezone)} <span className="text-muted-foreground">({relativeTime(event.created_at)}, UTC {event.created_at})</span>
                </Row>
                <Row label="User">
                  {event.user_id > 0 ? (
                    <span className="flex items-center gap-2">
                      {event.user.avatar && <img src={event.user.avatar} alt="" className="h-5 w-5 rounded-full" />}
                      {event.user.name} <span className="text-muted-foreground">({event.user_login})</span>
                      {!event.user.exists && <Badge variant="outline">deleted</Badge>}
                    </span>
                  ) : (
                    <span className="text-muted-foreground">System / anonymous</span>
                  )}
                </Row>
                {event.user_roles.length > 0 && <Row label="Roles">{event.user_roles.join(', ')}</Row>}
                <Row label="Category">{categoryLabel(event.category, meta)}</Row>
                <Row label="Object">
                  {event.edit_link ? (
                    <a href={event.edit_link} className="inline-flex items-center gap-1">
                      {event.object_label || event.object_id} <ExternalLink className="h-3 w-3" />
                    </a>
                  ) : (
                    event.object_label || event.object_id || '—'
                  )}
                  {event.object_type && <span className="ml-2 text-xs text-muted-foreground">{event.object_type} {event.object_id}</span>}
                </Row>
                <Row label="IP">{event.ip || '—'}</Row>
                <Row label="Source">
                  {request.source ?? '—'} {request.method && <code className="ml-1 text-xs">{request.method}</code>}{' '}
                  {request.uri && <code className="break-all text-xs text-muted-foreground">{request.uri}</code>}
                </Row>
                {event.user_agent && (
                  <Row label="User agent">
                    <span className="break-all text-xs">{event.user_agent}</span>
                  </Row>
                )}
                {Object.keys(extra).length > 0 && (
                  <Row label="Context">
                    <JsonTree value={extra} />
                  </Row>
                )}
              </tbody>
            </table>

            <div className="flex flex-wrap gap-2">
              {event.session_hash && (
                <Button variant="outline" size="sm" onClick={() => onFilter({ session: event.session_hash })}>
                  Other events in this session
                </Button>
              )}
              {event.user_id > 0 && (
                <Button variant="outline" size="sm" onClick={() => onFilter({ user: [String(event.user_id)] })}>
                  More by this user
                </Button>
              )}
              {event.object_id && (
                <Button variant="outline" size="sm" onClick={() => onFilter({ object_type: event.object_type, object_id: event.object_id })}>
                  History of this object
                </Button>
              )}
            </div>

            <div>
              <h3 className="mb-1 text-sm font-semibold">Changes</h3>
              <DiffView changes={event.changes} />
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
