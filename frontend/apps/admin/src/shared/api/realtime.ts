import { authHeaders } from '@/shared/auth/session';
import { SESSION_STORAGE } from '@/shared/auth/storage';

const realtimeHttpBase = () =>
  (import.meta.env.VITE_REALTIME_BASE || 'http://127.0.0.1:8097').replace(/\/$/, '');

const realtimeWsBase = () => {
  const http = realtimeHttpBase();
  if (http.startsWith('https://')) {
    return http.replace('https://', 'wss://');
  }
  return http.replace('http://', 'ws://');
};

export type RealtimeEvent = {
  type: 'event';
  channel: string;
  payload: {
    event?: string;
    entity?: string;
    record_id?: number;
    origin?: string;
    project_id?: number;
  };
};

export async function createSubscription(name: string, channels: string[]): Promise<number> {
  const json = await fetch(`${realtimeHttpBase()}/api/v1/subscriptions`, {
    method: 'POST',
    headers: authHeaders(true),
    body: JSON.stringify({ name, channels }),
  }).then((r) => r.json());
  if (!json.ok || !json.subscription?.id) {
    throw new Error(json.error || 'Не удалось создать подписку');
  }
  return Number(json.subscription.id);
}

export function connectRealtime(
  subscriptionId: number,
  onEvent: (event: RealtimeEvent) => void,
  onStatus?: (status: string) => void,
): () => void {
  const token = localStorage.getItem(SESSION_STORAGE.access) || '';
  const url = `${realtimeWsBase()}/ws?token=${encodeURIComponent(token)}&subscription_id=${subscriptionId}`;
  const ws = new WebSocket(url);

  ws.onopen = () => onStatus?.('connected');
  ws.onclose = () => onStatus?.('closed');
  ws.onerror = () => onStatus?.('error');
  ws.onmessage = (ev) => {
    try {
      const msg = JSON.parse(ev.data);
      if (msg.type === 'event') {
        onEvent(msg as RealtimeEvent);
      }
    } catch {
      // ignore malformed
    }
  };

  const ping = window.setInterval(() => {
    if (ws.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({ type: 'ping' }));
    }
  }, 45000);

  return () => {
    window.clearInterval(ping);
    ws.close();
  };
}
