import { useSearchParams } from 'react-router-dom';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ChannelsTab from '@/components/alerts/ChannelsTab';
import RulesTab from '@/components/alerts/RulesTab';

/**
 * Alerts: rules (what triggers an alert) and channels (where it goes). The tab is kept in
 * the URL (#/alerts?tab=channels).
 */
export default function Alerts() {
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'channels' ? 'channels' : 'rules';

  return (
    <Tabs value={tab} onValueChange={(value) => setParams(value === 'rules' ? {} : { tab: value })}>
      <TabsList>
        <TabsTrigger value="rules">Rules</TabsTrigger>
        <TabsTrigger value="channels">Channels</TabsTrigger>
      </TabsList>
      <TabsContent value="rules">
        <RulesTab />
      </TabsContent>
      <TabsContent value="channels">
        <ChannelsTab />
      </TabsContent>
    </Tabs>
  );
}
