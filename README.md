# PHP PostgreSQL MCP tunnel

HTTP JSON tunnel for Cursor MCP when the host is **PHP/Apache only** (Navicat-style).

Upload these files to your PHP host, then connect Cursor via the local `pg-mcp-bridge` Node package (separate from this PHP deploy).

## Files

| File | Purpose |
|------|---------|
| `pg_mcp_tunnel.php` | Tunnel endpoint |
| `config.example.php` | Copy to `config.php` and fill in real values |
| `.htaccess` | Blocks web access to `config.php` (Apache) |

**Never commit `config.php`** — it contains DB password and API token.

## Deploy

1. Copy `pg_mcp_tunnel.php`, `config.example.php`, and `.htaccess` to a web path, e.g. `https://your-domain.com/rb-mcp/`.
2. Copy `config.example.php` → `config.php` on the server and edit:

```php
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

3. Ensure PHP `pgsql` extension is enabled.
4. Test:

```bash
curl -s -X POST "https://your-domain.com/rb-mcp/pg_mcp_tunnel.php" \
  -H "Authorization: Bearer your-token" \
  -H "Content-Type: application/json" \
  -d '{"action":"ping"}'
```

## Access modes

| Mode | Allowed SQL |
|------|-------------|
| `readonly` | SELECT / EXPLAIN / SHOW |
| `readwrite` | + INSERT / UPDATE / DELETE |
| `full` | + CREATE / ALTER / DROP / etc. |

## Actions

`ping`, `list_schemas`, `list_tables`, `describe_table`, `query`

## Security

- Bearer token required
- DB credentials stay in server `config.php` only
- Prefer HTTPS
- One SQL statement per request
