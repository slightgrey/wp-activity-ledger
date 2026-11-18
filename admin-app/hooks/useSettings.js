import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Loads the plugin settings.
 *
 * @returns {import('@tanstack/react-query').UseQueryResult} Query.
 */
export function useSettings() {
  return useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api('/settings')).data,
  });
}

/**
 * Saves settings (partial); on success the cached settings are replaced.
 * A 400 error carries the field messages in `error.data.errors`.
 *
 * @returns {import('@tanstack/react-query').UseMutationResult} Mutation.
 */
export function useSaveSettings() {
  const client = useQueryClient();

  return useMutation({
    mutationFn: async (values) => (await api('/settings', { method: 'POST', body: values })).data,
    onSuccess: (settings) => client.setQueryData(['settings'], settings),
  });
}
