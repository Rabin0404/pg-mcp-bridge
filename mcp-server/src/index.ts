#!/usr/bin/env node
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { TunnelClient, formatResult } from "./tunnel.js";

const tunnelUrl = process.env.PG_MCP_TUNNEL_URL?.trim();
const tunnelToken = process.env.PG_MCP_TOKEN?.trim();

if (!tunnelUrl) {
  console.error("PG_MCP_TUNNEL_URL is required");
  process.exit(1);
}
if (!tunnelToken) {
  console.error("PG_MCP_TOKEN is required");
  process.exit(1);
}

const tunnel = new TunnelClient(tunnelUrl, tunnelToken);

const server = new McpServer({
  name: "remote-postgres",
  version: "1.0.0",
});

server.tool(
  "list_schemas",
  "List non-system PostgreSQL schemas available through the remote tunnel",
  {},
  async () => {
    const result = await tunnel.call("list_schemas");
    return {
      content: [{ type: "text", text: formatResult(result) }],
    };
  },
);

server.tool(
  "list_tables",
  "List tables and views in a schema",
  {
    schema: z
      .string()
      .default("public")
      .describe("Schema name (default: public)"),
  },
  async ({ schema }) => {
    const result = await tunnel.call("list_tables", { schema });
    return {
      content: [{ type: "text", text: formatResult(result) }],
    };
  },
);

server.tool(
  "describe_table",
  "Describe columns, types, nullability, defaults, and primary key for a table",
  {
    schema: z
      .string()
      .default("public")
      .describe("Schema name (default: public)"),
    table: z.string().describe("Table or view name"),
  },
  async ({ schema, table }) => {
    const result = await tunnel.call("describe_table", { schema, table });
    return {
      content: [{ type: "text", text: formatResult(result) }],
    };
  },
);

server.tool(
  "query",
  "Run a single SQL statement. Allowed statements depend on server access_mode: readonly (SELECT/EXPLAIN/SHOW), readwrite (+ INSERT/UPDATE/DELETE), full (+ CREATE/ALTER/DROP and other DDL). Use ping to see the current access_mode. Only one statement per call.",
  {
    sql: z.string().describe("SQL statement (permissions enforced by server access_mode)"),
  },
  async ({ sql }) => {
    const result = await tunnel.call("query", { sql });
    return {
      content: [{ type: "text", text: formatResult(result) }],
    };
  },
);

server.tool(
  "ping",
  "Check tunnel and PostgreSQL connectivity; returns version, database, user, and access_mode (readonly|readwrite|full)",
  {},
  async () => {
    const result = await tunnel.call("ping");
    return {
      content: [{ type: "text", text: formatResult(result) }],
    };
  },
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
