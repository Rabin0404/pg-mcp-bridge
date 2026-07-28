<?php
/**
 * Copy this file to config.php on the server and fill in real values.
 * Prefer placing config.php outside the web root and adjusting the require path
 * in pg_mcp_tunnel.php if your host allows it.
 */
return [
    // Shared secret Cursor sends as: Authorization: Bearer <token>
    'token' => 'change-me-to-a-long-random-secret',

    // PostgreSQL connection (same machine as PHP, typically)
    'host' => '127.0.0.1',
    'port' => '5432',
    'database' => 'postgres',
    'user' => 'postgres',
    'password' => '',

    // Access level for the query action:
    //   readonly  - SELECT / WITH...SELECT / EXPLAIN / SHOW
    //   readwrite - + INSERT / UPDATE / DELETE (no DDL)
    //   full      - + CREATE / ALTER / DROP / TRUNCATE / etc. (Navicat-like)
    'access_mode' => 'full',

    // Safety limits
    'max_rows' => 500,
    'statement_timeout_ms' => 15000,
];
