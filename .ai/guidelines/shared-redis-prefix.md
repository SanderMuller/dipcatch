## Shared Redis — Keep the Key Prefix

Every project on this Laravel Cloud account shares one Redis instance. The key prefix is the only thing that separates them, so a lost prefix means one project reads and overwrites another project's cache, sessions and queues.

- Keep `REDIS_PREFIX` and `CACHE_PREFIX` app-specific. Never set either to an empty value, and keep the `Str::slug(APP_NAME)` defaults in `config/database.php` and `config/cache.php`.
- Every connection under `database.redis` inherits the top-level `options.prefix`. A per-connection `prefix` or `options` key overrides it — do not add one that clears the prefix.
- Do not use a database index for isolation. Managed Redis may allow index 0 only, and an index is not a namespace.
- A package or client that reaches Redis outside `Redis::connection()` never gets that prefix. Give it an app-scoped prefix in its own config — `queue-insights.key_prefix` is one such setting.
