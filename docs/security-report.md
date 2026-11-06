# Security review report — Claude Publishing Bridge 0.1.0

Worked through the `docs/09` checklist on 2026-11-06 against the code on branch `claude/new-plugin-planning-hq4zcw`. Everything that can be tested in code has an automated test that runs in CI on every push. Two items depend on the client's live domain and are left for the site audit and pilot.

## What was tested

| Checklist item (`docs/09`) | Result | Evidence |
| --- | --- | --- |
| Every ability tested with no auth, Contributor, Publisher, admin; wrong post type; wrong status | Pass | `SecurityChecklistTest::test_every_ability_refuses_logged_out_and_roleless_users` (all 21 abilities: logged out, Subscriber, Editor), `test_contributors_are_refused_every_publish_only_ability`; `McpServerTest::test_unauthenticated_requests_get_401`, `test_users_without_the_bridge_role_get_403`; `ReadAbilitiesTest::test_check_connection_for_*`, `test_list_posts_only_returns_section_post_types`, `test_get_post_refuses_posts_outside_sections_and_missing_posts`; `WriteAbilitiesTest::test_update_refuses_live_posts_and_stale_reads`; `LiveChangesTest::test_update_live_only_for_live_posts_and_not_elementor_content` |
| Contributor can't publish or schedule via any ability, including `status: publish` on create/update | Pass | `WriteAbilitiesTest::test_contributor_post_is_pending_even_when_asking_to_publish`, `test_publisher_chooses_draft_or_pending_but_never_publish`, `test_publisher_can_move_a_draft_to_pending_but_not_live`; `LiveChangesTest::test_contributors_can_never_publish_or_schedule` |
| Live actions refused without a token, expired, reused, another user's, other post or payload | Pass | `LiveChangesTest::test_actions_need_a_token`, `test_tokens_expire_after_ten_minutes`, `test_tokens_work_once`, `test_tokens_are_bound_to_the_user_and_the_post`, `test_tokens_are_bound_to_the_action`, `test_a_changed_payload_is_refused`, `test_a_post_changed_after_prepare_is_refused_and_the_token_is_spent`; browser test `setup-pack.spec.js` (wrong and reused token through the real proxy) |
| Default MCP Adapter server and its discover/execute tools not reachable | Pass | `McpServerTest::test_default_server_and_meta_abilities_are_not_created`, `test_tools_list_contains_exactly_the_ccb_abilities`, `test_other_plugins_abilities_cannot_be_called_through_the_server`, `test_ccb_abilities_are_hidden_from_rest_and_default_servers`; also with the standalone adapter plugin active (CI matrix) |
| No `_elementor_*` meta ever written | Pass | `WriteAbilitiesTest::test_no_elementor_meta_is_ever_written`, `test_elementor_built_posts_keep_their_content` |
| Terms never created | Pass | `WriteAbilitiesTest::test_unknown_terms_are_refused_and_never_created` |
| Upload pipeline rejects SVG, HEIC, PHP renamed `.jpg`, polyglot JPEG/PHP, oversized files, fake PDFs | Pass | `MediaTest::test_unaccepted_files_are_refused_by_content`, `test_heic_gets_an_iphone_hint`, `test_polyglot_jpeg_php_is_re_encoded_without_the_code`, `test_too_large_and_invalid_data`, `test_fake_pdf_is_refused` |
| EXIF and GPS absent from processed images | Pass | `MediaTest::test_photo_with_gps_becomes_webp_without_metadata_and_is_featured`, `test_without_webp_support_jpeg_is_stripped_too`, `test_portrait_phone_photos_are_turned_upright_before_stripping` |
| Upload link: reuse, expiry, wrong type, other user's post | Pass | `MediaTest::test_expired_and_unknown_links_are_refused`, `test_upload_page_responses`, `test_file_field_links_need_a_file_field`, `test_links_only_for_posts_in_sections`, `test_upload_links_are_limited_to_ten_an_hour`, `test_temp_folder_is_protected` |
| XSS in title, content, excerpt, fields, alt text and config strings neutralised | Pass | `SecurityChecklistTest::test_xss_in_title_excerpt_fields_and_alt_text_is_neutralised`, `test_config_strings_are_escaped_in_emails`; `ContentSanitiserTest` (allow-list, `javascript:` and entity-obfuscated links); admin screen uses `x-text` (never `x-html`) and late escaping in PHP templates |
| CSV export formula injection neutralised | Pass | `AdminRestTest::test_csv_export_neutralises_formulas` (`=`, `+`, `-`, `@`, tab, CR) |
| PHPCS security sniffs clean; PHPStan clean | Pass | CI job "PHPCS and PHPStan" (WordPress standard incl. security sniffs; PHPStan level 6 with WordPress 7.1 and ACF stubs) |
| Rate limits and daily caps enforced | Pass | `ReadAbilitiesTest::test_requests_over_the_per_minute_limit_are_refused`; `LiveChangesTest::test_daily_cap_on_live_changes`; `MediaTest::test_upload_links_are_limited_to_ten_an_hour` |
| Logs contain no secrets | Pass | `SecurityChecklistTest::test_activity_log_holds_no_tokens_passwords_or_content` (tokens, upload-link tokens, Application Passwords, post body), `test_debug_mode_logs_calls_without_secrets_and_switches_itself_off`; `LiveChangesTest::test_only_hashes_of_tokens_are_stored`; `WriteAbilitiesTest::test_activity_rows_name_the_post_and_never_store_content` |
| WAF/CDN rules verified on the live domain | **Open** | Needs the live domain. The daily health check reports `endpoint_cached` / `endpoint_blocked` / `endpoint_open` (`OperationsTest`); run it from Settings → Claude Publishing → Run checks now after go-live |

Also checked:

| Requirement | Evidence |
| --- | --- |
| Every REST route has a permission callback; writes need `manage_options` | `AdminRestTest::test_every_route_has_a_permission_callback_and_refuses_contributors`, `SecurityChecklistTest::test_no_ability_or_route_reaches_users_settings_plugins_or_permanent_deletion` |
| No ability for users, settings, plugins, themes, pages, menus, permanent deletion | `SecurityChecklistTest::test_no_ability_or_route_reaches_users_settings_plugins_or_permanent_deletion` |
| Bridge roles never get `unfiltered_html`, `manage_categories`, `edit_pages`, `edit_theme_options`, `manage_options` | `ActivationTest::test_capability_matrix` |
| HTTPS required for abilities and upload links | `SecurityChecklistTest::test_plain_http_is_refused`, `ReadAbilitiesTest::test_plain_http_is_refused_with_a_clear_code`, `MediaTest::test_upload_page_responses` |
| Setup pack contains no credentials | `SetupPackTest::test_manifest_points_at_the_ccb_server_and_holds_no_credentials` |
| Config import ≤ 256 KB, validated and diffed before applying | `AdminRestTest::test_import_validates_and_previews_without_saving` |
| Every admin change, role change and revoke audited | `AdminRestTest::test_save_validates_bumps_the_version_and_logs`, `test_restore_saves_an_old_version_as_new_and_is_logged`, `test_switching_roles_and_revoking_app_passwords` |
| No external assets in admin pages | Assets compiled into `assets/dist`; browser test "other admin screens are untouched" and axe run with no network |

## Issues found and fixed during the review

| Issue | Fix |
| --- | --- |
| Debug mode was a stored switch with no logging behind it | `Audit\DebugLog`: logs ability input and result with tokens, passwords, nonces, URLs, post content and excerpts redacted and long strings cut; turns itself off after 24 hours; switching it is audited |
| A stale local PHPCS cache hid findings that CI caught | Cache removed from `phpcs.xml.dist` |
| Several WP Admin colour schemes' accents failed 4.5:1 contrast | Accent darkened with `color-mix` (M8) |

## Accepted risks

| Risk | Why accepted | Mitigation |
| --- | --- | --- |
| A Publisher who says "yes" to a misleading summary publishes it | The human yes is the control by design | Full summary shown before every live change; email to other Publishers; daily caps; Bin-only removal; revisions before live updates |
| Application Passwords are long-lived | WordPress core behaviour | One per device, named; revoke from the Access tab or profile; device name and IP in the activity log |
| The bundled MCP Adapter is pre-1.0 and upstream has deprecated bundling | No stable release yet | Version pinned and Strauss-prefixed; standalone adapter coexistence tested in CI; decide before 1.0 (`docs/13`) |
| Debug lines go to the PHP error log | Hosts expose it differently | Off by default, 24-hour limit, redacted, audited when switched |

## Still to do before go-live

- [ ] WAF/CDN: confirm the MCP endpoint and upload links are neither cached nor challenged on the live domain (health check "Run checks now").
- [ ] Manual install of the setup pack on macOS and Windows during the pilot.
- [ ] Full site backup confirmed.
