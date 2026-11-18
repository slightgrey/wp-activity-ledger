import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { useToast } from '@/components/ui/toast';
import { Row } from '@/components/settings/fields';
import { api } from '@/lib/api';
import { __ } from '@/lib/i18n';

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
      toast({ title: __( 'Network settings saved', 'wp-activity-ledger' ) });
    },
    onError: (error) => toast({ title: __( 'Not saved', 'wp-activity-ledger' ), description: error.message, variant: 'destructive' }),
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
          label={__( 'Lock site settings', 'wp-activity-ledger' )}
          help={__( 'Site administrators can still view their log and settings, but can\'t change settings or alerts, or purge logs.', 'wp-activity-ledger' )}
          htmlFor="wpal-net-lock"
        >
          <div className="flex items-center gap-2">
            <Switch id="wpal-net-lock" checked={settings.data.lock} disabled={save.isPending} onCheckedChange={(lock) => save.mutate({ lock })} />
            <label htmlFor="wpal-net-lock" className="text-sm">
              {settings.data.lock ? __( 'Locked by the network', 'wp-activity-ledger' ) : __( 'Each site manages its own settings', 'wp-activity-ledger' )}
            </label>
          </div>
        </Row>
        <Row label={__( 'Defaults for new sites', 'wp-activity-ledger' )} help={__( 'Sites created from now on start with these settings instead of the plugin defaults.', 'wp-activity-ledger' )}>
          <p className="text-sm text-muted-foreground">
            {settings.data.defaults ? __( 'New sites copy the saved defaults.', 'wp-activity-ledger' ) : __( 'New sites start with the plugin defaults.', 'wp-activity-ledger' )}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" disabled={save.isPending} onClick={copyMain}>
              {__( 'Use the main site\'s settings', 'wp-activity-ledger' )}
            </Button>
            {settings.data.defaults && (
              <Button variant="ghost" size="sm" disabled={save.isPending} onClick={() => save.mutate({ defaults: null })}>
                {__( 'Clear defaults', 'wp-activity-ledger' )}
              </Button>
            )}
          </div>
        </Row>
      </CardContent>
    </Card>
  );
}
