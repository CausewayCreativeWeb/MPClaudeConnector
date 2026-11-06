// Claude Desktop extension entry point (docs/10): starts the pinned WordPress MCP proxy, nothing else.
// The proxy reads WP_API_URL, WP_API_USERNAME, WP_API_PASSWORD and OAUTH_ENABLED from the environment
// that Claude Desktop sets from manifest.json.
import '@automattic/mcp-wordpress-remote';
