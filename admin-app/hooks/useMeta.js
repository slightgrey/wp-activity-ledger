import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Loads /meta (event types, categories, severities, roles, integrations, caps, prefs) once.
 *
 * @returns {import('@tanstack/react-query').UseQueryResult} Query.
 */
export function useMeta() {
  return useQuery({
    queryKey: ['meta'],
    queryFn: async () => (await api('/meta')).data,
    staleTime: Infinity,
  });
}
