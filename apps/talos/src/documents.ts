import { canonicalize } from "./contracts";

/** Large immutable documents belong in SQL, not KV's 128 KiB-per-value storage. */
export class Documents {
  constructor(private readonly storage: DurableObjectStorage) {
    storage.sql.exec("CREATE TABLE IF NOT EXISTS talos_documents (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
  }

  get<T>(key: string): T | undefined {
    const row = this.storage.sql.exec<{ value: string }>("SELECT value FROM talos_documents WHERE key = ?", key).toArray()[0];
    return row ? JSON.parse(row.value) as T : undefined;
  }

  putImmutable(key: string, document: unknown): void {
    const value = JSON.stringify(canonicalize(document));
    if (new TextEncoder().encode(value).byteLength > 2 * 1024 * 1024 - 4096) throw new Error("Document exceeds the SQL row budget");
    this.storage.transactionSync(() => {
      const previous = this.storage.sql.exec<{ value: string }>("SELECT value FROM talos_documents WHERE key = ?", key).toArray()[0];
      if (previous) {
        if (previous.value !== value) throw new Error("Reference document is already frozen: " + key);
        return;
      }
      this.storage.sql.exec("INSERT INTO talos_documents (key, value) VALUES (?, ?)", key, value);
    });
  }
}
