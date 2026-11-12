-- Down for 0006: fjerner OAuth-klienter, bevillinger, koder, tokens og rate limit (F006). Alle forbundne apps holder op
-- med at virke; F003-nøglerne er uberørt.
DROP TABLE IF EXISTS oauth_ip_rate;
DROP TABLE IF EXISTS oauth_rate;
DROP TABLE IF EXISTS oauth_tokens;
DROP TABLE IF EXISTS oauth_codes;
DROP TABLE IF EXISTS oauth_grants;
DROP TABLE IF EXISTS oauth_clients;
