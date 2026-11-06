# Claude Publishing Bridge

WordPress plugin (`causeway-claude-bridge`) that lets named staff draft, edit, publish and remove website posts from Claude, safely, through a dedicated MCP server.

- Project guide and non-negotiables: [`CLAUDE.md`](CLAUDE.md)
- Specs (source of truth): [`docs/`](docs/)
- Build plan and progress: [`docs/13-build-plan.md`](docs/13-build-plan.md)

Status: milestone 1 (plugin skeleton) done. The plugin activates, creates its roles, tables and default configuration, and uninstalls cleanly; it doesn't connect to Claude yet.

## Development

Requires PHP 8.1+, Composer and git. No Docker or database server needed for tests.

```bash
composer install
composer test:unit
bin/install-wp-tests.sh 6.9.11
composer test:integration
bin/build-release.sh && bin/smoke-test.sh
```

See the Commands section of [`CLAUDE.md`](CLAUDE.md) for the rest.
