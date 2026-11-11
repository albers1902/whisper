-- Down for 0004: fjerner MCP-nøgler og rate limit-tællere (F003). Alle udstedte nøgler holder op med at virke.
DROP TABLE IF EXISTS mcp_rate;
DROP TABLE IF EXISTS mcp_tokens;
