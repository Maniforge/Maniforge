import { useEffect, useRef, useState } from 'react';
import { connectRealtime, createSubscription, type RealtimeEvent } from '@/shared/api/realtime';
import { hasAccessToken } from '@/shared/auth/storage';

type Options = {
  channels: string[];
  enabled?: boolean;
  onEvent?: (event: RealtimeEvent) => void;
};

export function useRealtime({ channels, enabled = true, onEvent }: Options) {
  const [status, setStatus] = useState<'idle' | 'connecting' | 'connected' | 'error'>('idle');
  const onEventRef = useRef(onEvent);
  onEventRef.current = onEvent;

  useEffect(() => {
    if (!enabled || !hasAccessToken() || channels.length === 0) {
      setStatus('idle');
      return;
    }

    let dispose: (() => void) | undefined;
    let cancelled = false;

    (async () => {
      setStatus('connecting');
      try {
        const subId = await createSubscription('Admin SPA', channels);
        if (cancelled) {
          return;
        }
        dispose = connectRealtime(
          subId,
          (event) => onEventRef.current?.(event),
          (s) => {
            if (s === 'connected') setStatus('connected');
            if (s === 'error') setStatus('error');
          },
        );
      } catch {
        if (!cancelled) setStatus('error');
      }
    })();

    return () => {
      cancelled = true;
      dispose?.();
    };
  }, [enabled, channels.join('|')]);

  return { status };
}
