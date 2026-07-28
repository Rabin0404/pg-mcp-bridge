# pg-mcp-bridge

PostgreSQL access for **Cursor MCP** when your database host only allows **PHP/Apache** (HTTP JSON tunnel).

| Part | Where it runs | Get it |
|------|----------------|--------|
| **PHP tunnel** | Your PHP server | This repo — download and upload to Apache |
| **MCP bridge** (`pg-mcp-bridge`) | Your PC (Cursor) | [npm](https://www.npmjs.com/package/pg-mcp-bridge) · [source](https://github.com/Rabin0404/pg-mcp-server) |

```text
Cursor  →  npx pg-mcp-bridge  →  HTTPS  →  PHP tunnel  →  PostgreSQL
```

## 1. Upload PHP tunnel (this repo)

Download from **https://github.com/Rabin0404/pg-mcp-bridge** and upload to your PHP host:

- [`pg_mcp_tunnel.php`](pg_mcp_tunnel.php)
- [`config.example.php`](config.example.php) → copy to `config.php` (do not commit real `config.php`)
- [`.htaccess`](.htaccess) (optional)

Requirements: PHP with **pgsql** extension, HTTPS recommended.

```php
// config.php
return [
    'token' => 'generate-a-long-random-secret',
    'host' => '127.0.0.1',
    'port' => '5432',
    'database' => 'your_db',
    'user' => 'your_user',
    'password' => 'your_password',
    'access_mode' => 'full', // readonly | readwrite | full
    'max_rows' => 500,
    'statement_timeout_ms' => 15000,
];
```

Test:

```bash
curl -s -X POST "https://your-domain.com/pg_mcp_tunnel.php" \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json" \
  -d '{"action":"ping"}'
```

### Access modes

| Mode | Allowed SQL |
|------|-------------|
| `readonly` | SELECT / EXPLAIN / SHOW |
| `readwrite` | + INSERT / UPDATE / DELETE |
| `full` | + CREATE / ALTER / DROP / etc. |

## 2. Cursor MCP (npm package)

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

Bridge source: [github.com/Rabin0404/pg-mcp-server](https://github.com/Rabin0404/pg-mcp-server)  
PHP tunnel source: this repo.

## Security

- Never publish real `config.php`
- Bearer token required
- Prefer HTTPS
- One SQL statement per request

## License

MIT
