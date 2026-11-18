import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useToast } from '@/components/ui/toast';
import { Row } from '@/components/settings/fields';
import { api } from '@/lib/api';

/**
 * Network admin: the settings lock and the default settings for new sites.
 */
export default function NetworkSettings() {
  const toast = useToast();
  const client = useQueryClient();
  const settings = useQuery({ queryKey: ['network-settings'], queryFn: async () => (await api('/network-settings')).data });

  const save = useMutation({
    mutationFn: async (fields) => (await api('/network-settings', { method: 'POST', body: fields })).data,
    onSuccess: (saved) => {
      client.setQueryData(['network-settings'], saved);
      toast({ title: 'Network settings saved' });
    },
    onError: (error) => toast({ title: 'Not saved', description: error.message, variant: 'destructive' }),
  });

  const copyMain = async () => {
    const main = (await api('/settings')).data;
    save.mutate({ defaults: main });
  };

  if (!settings.data) return <Skeleton className="h-40 w-full" />;

  return (
    <Card>
      <CardContent className="py-2">
        <Row
          label="Lock site settings"
          help="Site administrators can still view their log and settings, but can't change settings or alerts, or purge logs."
          htmlFor="wpal-net-lock"
        >
          <div className="flex items-center gap-2">
            <Switch id="wpal-net-lock" checked={settings.data.lock} disabled={save.isPending} onCheckedChange={(lock) => save.mutate({ lock })} />
            <label htmlFor="wpal-net-lock" className="text-sm">
              {settings.data.lock ? 'Locked by the network' : 'Each site manages its own settings'}
            </label>
          </div>
        </Row>
        <Row label="Defaults for new sites" help="Sites created from now on start with these settings instead of the plugin defaults.">
          <p className="text-sm text-muted-foreground">
            {settings.data.defaults ? 'New sites copy the saved defaults.' : 'New sites start with the plugin defaults.'}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" disabled={save.isPending} onClick={copyMain}>
              Use the main site&apos;s settings
            </Button>
            {settings.data.defaults && (
              <Button variant="ghost" size="sm" disabled={save.isPending} onClick={() => save.mutate({ defaults: null })}>
                Clear defaults
              </Button>
            )}
          </div>
        </Row>
      </CardContent>
    </Card>
  );
}
