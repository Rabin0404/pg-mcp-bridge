# pg-mcp-bridge

Local **stdio MCP bridge** for Cursor so you can talk to a remote PostgreSQL database through a **PHP/Apache HTTP tunnel** (same idea as Navicat’s `ntunnel`).

```text
Cursor  →  pg-mcp-bridge (this package)  →  HTTPS  →  PHP tunnel  →  PostgreSQL
```

## PHP tunnel (required on your server)

This npm package is only the Cursor-side bridge. You must also upload the PHP tunnel to your own PHP host.

Download the PHP source from the GitHub repo and upload it to your server:

**https://github.com/Rabin0404/pg-mcp-bridge**

Repo files to deploy on PHP/Apache:

- `pg_mcp_tunnel.php`
- `config.example.php` → copy to `config.php` and set DB credentials + bearer token
- `.htaccess` (optional; blocks web access to `config.php`)

See the repo README for `access_mode` (`readonly` | `readwrite` | `full`) and curl test steps.

## Install / Cursor MCP config

```json
{
  "mcpServers": {
    "remote-postgres": {
      "command": "npx",
      "args": ["-y", "pg-mcp-bridge"],
      "env": {
        "PG_MCP_TUNNEL_URL": "https://your-domain.com/pg_mcp_tunnel.php",
        "PG_MCP_TOKEN": "your-bearer-token"
      }
    }
  }
}
```

Or install globally:

```bash
npm install -g pg-mcp-bridge
```

```json
{
  "mcpServers": {
    "remote-postgres": {
      "command": "pg-mcp-bridge",
      "env": {
        "PG_MCP_TUNNEL_URL": "https://your-domain.com/pg_mcp_tunnel.php",
        "PG_MCP_TOKEN": "your-bearer-token"
      }
    }
  }
}
```

## Environment variables

| Variable | Required | Description |
|----------|----------|-------------|
| `PG_MCP_TUNNEL_URL` | Yes | Full URL to `pg_mcp_tunnel.php` |
| `PG_MCP_TOKEN` | Yes | Same bearer token as in server `config.php` |

## MCP tools

- `ping` — connectivity + `access_mode`
- `list_schemas`
- `list_tables`
- `describe_table`
- `query` — SQL allowed by server `access_mode`

## License

MIT
