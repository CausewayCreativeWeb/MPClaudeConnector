=== Claude Publishing Bridge ===
Contributors: causewaycreative
Tags: claude, mcp, publishing
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets named staff draft, edit, publish and remove website posts from Claude, safely.

== Description ==

Each person works under their own WordPress login and their own Application Password, so every action is attributable. Posts from AI Contributors land as Pending Review. Only AI Publishers can publish or schedule, and every change to the live site needs an explicit confirmation from the person making it. Deleting moves a post to the Bin.

== Changelog ==

= 0.1.0 =
* Plugin skeleton: requirement checks, activation, AI Contributor and AI Publisher roles, database tables, versioned configuration with validation, uninstall.
* Site discovery, configuration checks against the live site, publishing guide, MP site preset.
* Dedicated MCP server with the read abilities: check connection, publishing guide, sections, terms, posts.
