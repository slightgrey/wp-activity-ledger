import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Loads alert channels or rules.
 *
 * @param {'channels'|'rules'} kind Kind.
 * @returns {import('@tanstack/react-query').UseQueryResult<Array>} Query.
 */
export function useAlertItems(kind) {
  return useQuery({
    queryKey: ['alerts', kind],
    queryFn: async () => (await api(`/alerts/${kind}`)).data,
  });
}

/**
 * Saves (creates or updates) and deletes alert channels or rules, refreshing both lists
 * (deleting a channel changes rules too).
 *
 * @param {'channels'|'rules'} kind Kind.
 * @returns {{ save: object, remove: object }} Mutations.
 */
export function useAlertMutations(kind) {
  const client = useQueryClient();
  const refresh = () => client.invalidateQueries({ queryKey: ['alerts'] });

  const save = useMutation({
    mutationFn: async ({ id, ...fields }) =>
      (await api(id ? `/alerts/${kind}/${id}` : `/alerts/${kind}`, { method: id ? 'PUT' : 'POST', body: fields })).data,
    onSuccess: refresh,
  });

  const remove = useMutation({
    mutationFn: async (id) => (await api(`/alerts/${kind}/${id}`, { method: 'DELETE' })).data,
    onSuccess: refresh,
  });

  return { save, remove };
}

/**
 * Sends a test alert through a channel.
 *
 * @returns {import('@tanstack/react-query').UseMutationResult<{ok: boolean, error: string|null}>} Mutation.
 */
export function useTestChannel() {
  return useMutation({
    mutationFn: async (id) => (await api(`/alerts/channels/${id}/test`, { method: 'POST' })).data,
  });
}
