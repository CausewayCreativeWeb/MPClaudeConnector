# Reference — MCP Adapter 0.7.1 notes

Answers to the adapter **TO CONFIRM** items in `docs/01`, `docs/02`, `docs/03` and `docs/10`, read from the pinned version's own docs and source (`vendor/wordpress/mcp-adapter`) during M3 on 2026-11-06. Re-check this page whenever the pin changes.

## Bundling is deprecated upstream

0.7.0 made the adapter a canonical WordPress plugin and **deprecated bundling it as a Composer library**: "Bundled usage still works today, but will be removed in a future version." The upstream recommendation is `Requires Plugins: mcp-adapter`, which only works once the adapter is on wordpress.org (not yet; upstream issue #178).

The Bridge bundles it anyway, prefixed with Strauss (upstream lists Strauss as an acceptable way to avoid conflicts), because it must install as one standalone plugin (`docs/13` 1a). Consequences:

- On start-up the bundled copy logs a `_deprecated_function` notice once per request when `WP_DEBUG` is on (nothing when it's off, as on production). It's left in place on purpose: it's upstream's signal, and it doesn't reach users.
- Upgrading past 0.7.x may remove bundled support. All adapter code is in `src/Mcp/ServerRegistrar.php` (and `src/Mcp/ErrorHandler.php`), so moving to the canonical plugin later changes those files and the plugin header, not the abilities.

**Decision needed before 1.0:** keep bundling (and stay on 0.7.x until upstream says otherwise), or require the canonical plugin once it's on wordpress.org.

## Answers

| Question | Answer in 0.7.1 | Where it's used |
| --- | --- | --- |
| Abilities API hooks | Categories on `wp_abilities_api_categories_init`, abilities on `wp_abilities_api_init` (core 6.9). A category must exist before an ability uses it, or `wp_register_ability()` returns null with only a `_doing_it_wrong` notice | `AbilityRegistrar` |
| Creating a custom server | `McpAdapter::instance()`, then on `mcp_adapter_init` call `$adapter->create_server( id, route_namespace, route, name, description, version, transports, error_handler, observability_handler, tools, resources, prompts, transport_permission_callback )`. The adapter initialises on `rest_api_init` (priority 15), or `init` under WP-CLI | `ServerRegistrar` |
| Endpoint URL | `create_server( 'ccb', 'mcp', 'ccb', … )` → `https://<site>/wp-json/mcp/ccb` (or `?rest_route=/mcp/ccb`). This is the `WP_API_URL` for the Claude Desktop extension (`docs/10`) | `ServerRegistrar::url()` |
| Hiding the default server | Filter `mcp_adapter_create_default_server` → false stops the default server and its three meta-abilities (`mcp-adapter/discover-abilities`, `get-ability-info`, `execute-ability`). The filter is shared by every copy of the adapter on a site, so the Bridge answers false only when its own bundled copy asks (checked from the call stack) | `ServerRegistrar::filter_default_server()` |
| Keeping abilities off other servers | Default servers expose an ability only if `meta.mcp.public` is true (or, when unset, `meta.public`). Bridge abilities set `meta.mcp.public = false` and `meta.show_in_rest = false`, so no default server (ours or the standalone plugin's) and no core REST endpoint lists or runs them. Tested with the standalone plugin active | `AbstractAbility::registration_args()` |
| Annotation meta keys | `meta.annotations` with WordPress names `readonly`, `destructive`, `idempotent`; the adapter converts them to `readOnlyHint`, `destructiveHint`, `idempotentHint` | `AbstractAbility::annotations()` |
| MCP tool names | Ability name with `/` replaced by `-`: `ccb/check-connection` → `ccb-check-connection`. Filterable with `mcp_adapter_tool_name` (not used) | Skill template (M9) |
| Transport permission callback | Receives the `WP_REST_Request`; true/false (a `WP_Error` is logged and treated as false). WordPress turns false into 401 when logged out and 403 when logged in | `ServerRegistrar::transport_permission()` |
| Error reporting to Claude | A tool's `WP_Error` reaches the client as `isError: true` with **only the message text**; code and data are dropped. So every Bridge error message ends with `[code: ccb_…; field: …; ref: …]` | `AbilityError` |
| Validation order | The adapter checks permissions first, then core's `WP_Ability::execute()` validates input against `input_schema`, checks permissions again, runs the callback and validates output. Input-schema failures never reach the ability, so the `mcp_adapter_tool_call_result` filter audits them and converts them to `ccb_invalid_input` | `ServerRegistrar::filter_tool_result()` |
| Application Password used | `rest_get_authenticated_app_password()` returns the UUID on the adapter's REST route; the name comes from `WP_Application_Passwords::get_user_application_password()`. Verified over real HTTP in the smoke test | `RequestContext` |
| MCP resources (`ccb://publishing-guide`) | Supported (`meta.mcp.type = resource` with a `uri`), but resource errors are JSON-RPC errors, not tool results. Not used: the guide is a tool, which keeps the error format the same everywhere | — |
| Sessions | HTTP clients `initialize` first and send `Mcp-Session-Id` on later requests (2025-11-25 revision); the 2026-07-28 revision is sessionless. The proxy handles this | Tests, smoke test |
| Coexistence with the standalone plugin | Both copies fire `mcp_adapter_init`; the Bridge only creates its server on its own copy. Both servers' routes exist side by side. Tested in CI (`CCB_TEST_WITH_STANDALONE_ADAPTER=1`) | `StandaloneAdapterTest` |
