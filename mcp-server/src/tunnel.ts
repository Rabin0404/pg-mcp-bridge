export type TunnelResponse = {
  ok: boolean;
  error?: string;
  data?: unknown;
  columns?: Array<{ name: string; type: string }>;
  rowCount?: number;
  affectedRows?: number;
  access_mode?: string;
  truncated?: boolean;
  maxRows?: number;
};

export class TunnelClient {
  constructor(
    private readonly url: string,
    private readonly token: string,
  ) {}

  async call(
    action: string,
    params: Record<string, unknown> = {},
  ): Promise<TunnelResponse> {
    const res = await fetch(this.url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${this.token}`,
      },
      body: JSON.stringify({ action, ...params }),
    });

    let body: TunnelResponse;
    try {
      body = (await res.json()) as TunnelResponse;
    } catch {
      throw new Error(
        `Tunnel returned non-JSON response (HTTP ${res.status})`,
      );
    }

    if (!res.ok && body.error) {
      throw new Error(body.error);
    }
    if (!body.ok) {
      throw new Error(body.error || `Tunnel action failed: ${action}`);
    }
    return body;
  }
}

export function formatResult(result: TunnelResponse): string {
  return JSON.stringify(
    {
      data: result.data,
      columns: result.columns,
      rowCount: result.rowCount,
      affectedRows: result.affectedRows,
      access_mode: result.access_mode,
      truncated: result.truncated,
      maxRows: result.maxRows,
    },
    null,
    2,
  );
}
