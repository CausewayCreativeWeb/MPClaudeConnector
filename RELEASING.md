# Releasing Claude Publishing Bridge

Releases are zips uploaded by hand to the update server at `repo.causewaycreative.co.uk`; sites pick them up through plugin-update-checker (`docs/13` section 1a). CI builds and smoke-tests the zip; a person uploads it.

## 1. Prepare

1. Start from an up-to-date `main` with green CI.
2. Pick the version (`MAJOR.MINOR.PATCH`). Patch for fixes, minor for features, major for anything that changes how sites or staff work.
3. Set it in all three places (the build refuses a mismatch):
   - `causeway-claude-bridge.php`: the `Version:` header and `define( 'CCB_VERSION', … )`
   - `readme.txt`: `Stable tag:`
4. Add a `= x.y.z =` entry at the top of the `readme.txt` changelog. Plain English: this is what site admins see in the update screen.
5. **Setup pack:** if anything in `setup-pack/` changed, or the skill or how-to text in `src/SetupPack/SetupPackBuilder.php`, bump `SetupPackBuilder::VERSION`. Staff then see "A newer setup pack is available" from `check-connection` and download it again. Don't bump it otherwise: every bump asks every member of staff to reinstall.
6. Pinned dependencies: only change `wordpress/mcp-adapter` or `@automattic/mcp-wordpress-remote` on purpose, after reading their changelogs. Both are pre-1.0 and have had breaking changes. Record the change in `docs/13`.
7. Regenerate the translation template: `php bin/make-pot.php` (CI fails if it's out of date).
8. If the admin screen changed: `npm run build` and commit `assets/dist/` (CI checks the build is current and within the size budgets).

## 2. Check locally (optional; CI runs all of this)

```bash
composer install --prefer-source
composer lint
composer test:unit
bin/install-wp-tests.sh 7.1.4
composer test:integration
npm ci && npm run build && npm run build:extension
npx playwright test --trace off
bin/build-release.sh
bin/smoke-test.sh
```

## 3. Tag and build

1. Merge the release changes to `main`.
2. Tag: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. Wait for every CI job to pass on the tag: lint, unit, integration (WordPress 6.9 and 7.1, PHP 8.1 and 8.3, with and without the standalone MCP Adapter), Admin UI (build, budgets, browser tests including the setup pack through the real proxy), and release + smoke test on PHP 8.1 and 8.3.
4. Download the `causeway-claude-bridge-zip` artifact from the PHP 8.1 release job. Check the file is `causeway-claude-bridge-X.Y.Z.zip` and note the SHA-256 printed in the job log.

## 4. Publish

1. Upload the zip to `repo.causewaycreative.co.uk` for the slug `causeway-claude-bridge`.
2. Check the metadata URL shows the new version and changelog:
   `https://repo.causewaycreative.co.uk/?action=get_metadata&slug=causeway-claude-bridge`
   (On the first release, confirm the server builds this from the zip's header and `readme.txt`; if it doesn't, add a metadata step here.)
3. On a **staging** site: Dashboard → Updates → Check again. The update should appear; install it.
4. On staging, after the update:
   - Settings → Claude Publishing → Overview → **Run checks now**: everything Good (or the expected warnings).
   - From Claude Desktop, ask Claude to check the connection; if `SetupPackBuilder::VERSION` changed, it should say a newer setup pack is available.
   - Create a test draft, then move it to the Bin.
5. Update live sites (or let auto-updates run if they're on). Run the checks on each live site.

## 5. If something goes wrong

- Deactivating the plugin removes the MCP server and cuts Claude's access at once without touching posts.
- To roll back, upload the previous zip to the update server (and install it on affected sites from Plugins → Add New → Upload). Settings, history and the activity log are kept across versions.
- Settings mistakes: Settings → Claude Publishing → Advanced → Version history → Compare and restore.

## Release checklist

- [ ] Version set in the header, `CCB_VERSION` and `readme.txt`
- [ ] Changelog entry in `readme.txt`
- [ ] `SetupPackBuilder::VERSION` bumped if the pack changed (and only then)
- [ ] `.pot` and `assets/dist/` up to date
- [ ] Tag pushed; all CI jobs green on the tag
- [ ] Zip downloaded from CI and uploaded to `repo.causewaycreative.co.uk`
- [ ] Metadata URL shows the new version
- [ ] Staging updated and checked (health check, connection, test draft)
- [ ] Live sites updated and checked
