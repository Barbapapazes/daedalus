import { canonicalize, type ImplementationEvent } from "./contracts";

/** Durable append-only SQL log doubles as the callback outbox. */
export class EventLog {
  constructor(private readonly storage: DurableObjectStorage) {
    storage.sql.exec("CREATE TABLE IF NOT EXISTS talos_events (sequence INTEGER PRIMARY KEY, event_id TEXT UNIQUE NOT NULL, value TEXT NOT NULL)");
  }

  async append(eventId: string, type: ImplementationEvent["type"], payload: Record<string, unknown>): Promise<ImplementationEvent> {
    return this.storage.transactionSync(() => {
      const row = this.storage.sql.exec<{ value: string }>("SELECT value FROM talos_events WHERE event_id = ?", eventId).toArray()[0];
      if (row) {
        const existing = JSON.parse(row.value) as ImplementationEvent;
        if (existing.type !== type || JSON.stringify(canonicalize(existing.payload)) !== JSON.stringify(canonicalize(payload))) {
          throw new Error("Event ID already has a different payload");
        }
        return existing;
      }
      if (this.get("terminal")) throw new Error("Execution already ended; no new events may be appended");
      const [last] = this.storage.sql.exec<{ sequence: number }>("SELECT COALESCE(MAX(sequence), 0) AS sequence FROM talos_events").toArray();
      const sequence = last!.sequence + 1;
      const event: ImplementationEvent = { eventId, sequence, type, payload, createdAt: new Date().toISOString() };
      const value = JSON.stringify(event);
      if (new TextEncoder().encode(value).byteLength > 2 * 1024 * 1024 - 4096) throw new Error("Event exceeds the SQL row budget");
      this.storage.sql.exec("INSERT INTO talos_events (sequence, event_id, value) VALUES (?, ?, ?)", sequence, eventId, value);
      return event;
    });
  }

  get(eventId: string): ImplementationEvent | undefined {
    const row = this.storage.sql.exec<{ value: string }>("SELECT value FROM talos_events WHERE event_id = ?", eventId).toArray()[0];
    return row ? JSON.parse(row.value) as ImplementationEvent : undefined;
  }

  all(): ImplementationEvent[] {
    return this.storage.sql.exec<{ value: string }>("SELECT value FROM talos_events ORDER BY sequence")
      .toArray().map(row => JSON.parse(row.value) as ImplementationEvent);
  }

  async next(): Promise<ImplementationEvent | undefined> {
    const delivered = await this.storage.get<number>("event-delivered") ?? 0;
    const row = this.storage.sql.exec<{ value: string }>("SELECT value FROM talos_events WHERE sequence = ?", delivered + 1).toArray()[0];
    return row ? JSON.parse(row.value) as ImplementationEvent : undefined;
  }

  async delivered(sequence: number) { await this.storage.put("event-delivered", sequence); }
}
