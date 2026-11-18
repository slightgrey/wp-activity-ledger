import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { api, setSite } from '@/lib/api';

/**
 * Network admin only: picks the site whose log, dashboard and users are shown. Switching
 * clears the cached data so every screen reloads for the new site.
 */
export default function SiteSwitcher() {
  const client = useQueryClient();
  const sites = useQuery({ queryKey: ['network-sites'], queryFn: async () => (await api('/sites')).data, staleTime: Infinity });
  const [site, setSiteState] = useState(null);

  useEffect(() => {
    if (site === null && sites.data?.length) {
      const main = sites.data.find((s) => s.main) ?? sites.data[0];
      setSite(main.id);
      setSiteState(main.id);
    }
  }, [sites.data, site]);

  if (!sites.data) return null;

  const choose = (value) => {
    const id = Number(value);
    setSite(id);
    setSiteState(id);
    // Reset (not just invalidate): screens must not show the previous site's data meanwhile.
    client.resetQueries({ predicate: (query) => !['network-sites', 'network-settings'].includes(query.queryKey[0]) });
  };

  return (
    <Select value={site ? String(site) : undefined} onValueChange={choose}>
      <SelectTrigger className="w-64" aria-label="Site">
        <SelectValue placeholder="Choose a site" />
      </SelectTrigger>
      <SelectContent>
        {sites.data.map((s) => (
          <SelectItem key={s.id} value={String(s.id)}>
            {s.name || s.url} {s.main ? '(main site, network events)' : ''}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
