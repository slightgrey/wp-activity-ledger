import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Loads a filtered page of events, keeping the previous page visible while the next loads.
 *
 * @param {object} filters  Filters for GET /events.
 * @param {object} options  { refetchInterval } for auto-refresh.
 * @returns {import('@tanstack/react-query').UseQueryResult<{items: Array, total: number, totalPages: number}>} Query.
 */
export function useEvents(filters, { refetchInterval = false } = {}) {
  return useQuery({
    queryKey: ['events', filters],
    queryFn: async () => {
      const result = await api('/events', { query: filters });

      return { items: result.data, total: result.total ?? 0, totalPages: result.totalPages ?? 0 };
    },
    placeholderData: keepPreviousData,
    refetchInterval,
  });
}

/**
 * Loads one event with its changes.
 *
 * @param {number|null} id Event ID (no request while null).
 * @returns {import('@tanstack/react-query').UseQueryResult} Query.
 */
export function useEvent(id) {
  return useQuery({
    queryKey: ['event', id],
    queryFn: async () => (await api(`/events/${id}`)).data,
    enabled: Boolean(id),
  });
}
