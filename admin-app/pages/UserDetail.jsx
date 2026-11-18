import { useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, LogOut, Monitor } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast';
import ActivityLog from '@/pages/ActivityLog';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { formatDateTime, relativeTime } from '@/lib/format';

/**
 * A short browser description from a user agent.
 */
function browserOf(ua) {
  const name = ['Edg', 'Chrome', 'Firefox', 'Safari'].find((b) => ua.includes(b)) ?? '';
  const os = ['Windows', 'Mac OS', 'Android', 'iPhone', 'Linux'].find((o) => ua.includes(o)) ?? '';

  return [name === 'Edg' ? 'Edge' : name, os].filter(Boolean).join(' on ') || ua || 'Unknown browser';
}

/**
 * The user's active sessions, with "Log out" per session and "Log out everywhere".
 */
function Sessions({ userId, onShowEvents }) {
  const client = useQueryClient();
  const toast = useToast();
  const [confirmAll, setConfirmAll] = useState(false);
  const sessions = useQuery({ queryKey: ['sessions', userId], queryFn: async () => (await api(`/users/${userId}/sessions`)).data });

  const destroy = useMutation({
    mutationFn: async (verifier) => (await api(`/users/${userId}/sessions${verifier ? `/${verifier}` : ''}`, { method: 'DELETE' })).data,
    onSuccess: (result) => {
      client.invalidateQueries({ queryKey: ['sessions', userId] });
      client.invalidateQueries({ queryKey: ['events'] });
      toast({ title: result.destroyed === 1 ? 'Session ended' : `${result.destroyed} sessions ended` });
      setConfirmAll(false);
    },
    onError: (error) => toast({ title: 'Could not end the session', description: error.message, variant: 'destructive' }),
  });

  const others = (sessions.data ?? []).filter((s) => !s.current);

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-2">
        <CardTitle className="text-base">Active sessions</CardTitle>
        {others.length > 0 && (
          <Button variant="outline" size="sm" onClick={() => setConfirmAll(true)}>
            <LogOut className="h-4 w-4" />
            Log out everywhere
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {sessions.isLoading && <Skeleton className="h-12 w-full" />}
        {sessions.isError && <p className="text-sm text-destructive">{sessions.error.message}</p>}
        {sessions.data?.length === 0 && <p className="text-sm text-muted-foreground">No active sessions.</p>}
        <ul className="divide-y">
          {(sessions.data ?? []).map((s) => (
            <li key={s.verifier} className="flex flex-wrap items-center justify-between gap-3 py-2">
              <div className="flex items-center gap-3">
                <Monitor className="h-5 w-5 text-muted-foreground" />
                <div className="text-sm leading-tight">
                  <div className="flex items-center gap-2 font-medium">
                    {browserOf(s.user_agent)} {s.current && <Badge variant="success">this session</Badge>}
                  </div>
                  <div className="text-xs text-muted-foreground">
                    {s.ip || 'unknown IP'} · signed in {relativeTime(s.login)} · expires {formatDateTime(s.expiration, config.timezone)}
                  </div>
                </div>
              </div>
              <div className="flex gap-2">
                <Button variant="ghost" size="sm" onClick={() => onShowEvents(s.session_hash)}>
                  Events
                </Button>
                {!s.current && (
                  <Button variant="outline" size="sm" disabled={destroy.isPending} onClick={() => destroy.mutate(s.verifier)}>
                    Log out
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ul>
      </CardContent>

      <Dialog open={confirmAll} onOpenChange={setConfirmAll}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Log out everywhere?</DialogTitle>
            <DialogDescription>
              This ends {others.length === 1 ? '1 session' : `${others.length} sessions`}. The user has to sign in again on those devices.
              {sessions.data?.some((s) => s.current) && ' Your current session stays signed in.'}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmAll(false)}>
              Cancel
            </Button>
            <Button variant="destructive" disabled={destroy.isPending} onClick={() => destroy.mutate(null)}>
              Log out everywhere
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

export default function UserDetail() {
  const { id } = useParams();
  const [, setParams] = useSearchParams();
  const fixed = useMemo(() => ({ user: [String(id)] }), [id]);
  const user = useQuery({
    queryKey: ['user', id],
    queryFn: async () => (await api('/users', { query: { include: [id], per_page: 1 } })).data[0] ?? null,
  });

  const u = user.data;

  return (
    <div className="space-y-4">
      <Link to="/users" className="inline-flex items-center gap-1 text-sm">
        <ArrowLeft className="h-4 w-4" /> All users
      </Link>

      <Card>
        <CardContent className="flex flex-wrap items-center justify-between gap-4 p-5">
          {user.isLoading && <Skeleton className="h-12 w-64" />}
          {user.isSuccess && !u && <p className="text-sm text-muted-foreground">This user no longer exists. Their recorded activity is below.</p>}
          {u && (
            <>
              <div className="flex items-center gap-3">
                {u.avatar && <img src={u.avatar} alt="" className="h-12 w-12 rounded-full" />}
                <div>
                  <div className="text-lg font-semibold">{u.name}</div>
                  <div className="text-sm text-muted-foreground">
                    {u.login} · {u.roles.join(', ') || 'no role'}
                  </div>
                </div>
              </div>
              <dl className="flex flex-wrap gap-6 text-sm">
                <div>
                  <dt className="text-muted-foreground">Last activity</dt>
                  <dd>{u.last_activity ? relativeTime(u.last_activity) : '—'}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Last login</dt>
                  <dd>{u.last_login ? relativeTime(u.last_login) : '—'}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Events (30 days)</dt>
                  <dd className="tabular-nums">{u.events.toLocaleString()}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Failed logins (30 days)</dt>
                  <dd className="tabular-nums">{u.failed_logins.toLocaleString()}</dd>
                </div>
              </dl>
            </>
          )}
        </CardContent>
      </Card>

      {config.caps.manage_sessions && u && <Sessions userId={id} onShowEvents={(session) => setParams(new URLSearchParams({ session }))} />}

      <div>
        <h3 className="mb-2 text-base font-semibold">Activity</h3>
        <ActivityLog fixed={fixed} />
      </div>
    </div>
  );
}
