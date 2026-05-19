<?php
/**
 * Admin Settings Page View
 *
 * Variables available:
 *   $groups – Ability_Group[]
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="mcp-wrap">

    <!-- Header -->
    <div class="mcp-header">
        <div class="mcp-header__logo">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="32" height="32" rx="8" fill="#1a1a2e"/>
                <path d="M8 16L16 8L24 16L16 24L8 16Z" stroke="#6C63FF" stroke-width="2" fill="none"/>
                <circle cx="16" cy="16" r="3" fill="#6C63FF"/>
            </svg>
            <div>
                <h1><?php esc_html_e( 'VGC AI Nexus', 'mcp-abilities' ); ?></h1>
                <p><?php esc_html_e( 'AI agent tool manager for WordPress', 'mcp-abilities' ); ?></p>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="mcp-tabs">
        <button class="mcp-tab mcp-tab--active" data-tab="abilities"><?php esc_html_e( 'Abilities', 'mcp-abilities' ); ?></button>
        <button class="mcp-tab" data-tab="docs"><?php esc_html_e( 'Documentation', 'mcp-abilities' ); ?></button>
    </div>

    <!-- ── TAB: Abilities ─────────────────────────────────────────────────── -->
    <div class="mcp-tab-panel" id="mcp-tab-abilities">

        <div class="mcp-toolbar">
            <button id="mcp-save-btn" class="mcp-btn mcp-btn--primary">
                <span class="dashicons dashicons-saved"></span>
                <?php esc_html_e( 'Save Settings', 'mcp-abilities' ); ?>
            </button>
            <span id="mcp-save-status" class="mcp-save-status"></span>
            <div class="mcp-toolbar__search">
                <span class="dashicons dashicons-search"></span>
                <input type="text" id="mcp-search" placeholder="<?php esc_attr_e( 'Filter abilities…', 'mcp-abilities' ); ?>">
            </div>
        </div>

        <div class="mcp-groups" id="mcp-groups-container">
        <?php foreach ( $groups as $group ) : ?>
            <div class="mcp-group" data-slug="<?php echo esc_attr( $group->get_slug() ); ?>">

                <?php if ( $group->get_security_warning() ) : ?>
                <div class="mcp-group__warning<?php echo $group->is_enabled() ? '' : ' mcp-group__warning--hidden'; ?>"
                     data-group="<?php echo esc_attr( $group->get_slug() ); ?>">
                    <span class="dashicons dashicons-warning"></span>
                    <?php echo esc_html( $group->get_security_warning() ); ?>
                </div>
                <?php endif; ?>

                <div class="mcp-group__header">
                    <span class="mcp-group__icon dashicons <?php echo esc_attr( $group->get_icon() ); ?>"></span>
                    <div class="mcp-group__meta">
                        <h3 class="mcp-group__label"><?php echo esc_html( $group->get_label() ); ?></h3>
                        <p class="mcp-group__desc"><?php echo esc_html( $group->get_description() ); ?></p>
                    </div>
                    <div class="mcp-group__controls">
                        <span class="mcp-badge"><?php echo count( $group->get_abilities() ); ?> <?php esc_html_e( 'tools', 'mcp-abilities' ); ?></span>
                        <label class="mcp-toggle" title="<?php esc_attr_e( 'Enable/disable entire group', 'mcp-abilities' ); ?>">
                            <input type="checkbox"
                                class="mcp-group-toggle"
                                data-group="<?php echo esc_attr( $group->get_slug() ); ?>"
                                <?php checked( $group->is_enabled() ); ?>>
                            <span class="mcp-toggle__slider"></span>
                        </label>
                        <button class="mcp-group__expand" aria-expanded="false">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                    </div>
                </div>

                <div class="mcp-group__body <?php echo $group->is_enabled() ? '' : 'mcp-group__body--disabled'; ?>">
                    <table class="mcp-abilities-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Tool Name', 'mcp-abilities' ); ?></th>
                                <th><?php esc_html_e( 'Description', 'mcp-abilities' ); ?></th>
                                <th><?php esc_html_e( 'Capability', 'mcp-abilities' ); ?></th>
                                <th class="mcp-col-toggle"><?php esc_html_e( 'Enabled', 'mcp-abilities' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $group->get_abilities() as $ability ) : ?>
                            <tr class="mcp-ability-row" data-key="<?php echo esc_attr( $ability->get_key() ); ?>">
                                <td>
                                    <code class="mcp-tool-key"><?php echo esc_html( $ability->get_key() ); ?></code>
                                    <span class="mcp-tool-label"><?php echo esc_html( $ability->get_label() ); ?></span>
                                </td>
                                <td class="mcp-tool-desc"><?php echo esc_html( $ability->get_description() ); ?></td>
                                <td><code class="mcp-cap"><?php echo esc_html( $ability->get_required_cap() ); ?></code></td>
                                <td class="mcp-col-toggle">
                                    <label class="mcp-toggle mcp-toggle--sm">
                                        <input type="checkbox"
                                            class="mcp-ability-toggle"
                                            data-group="<?php echo esc_attr( $group->get_slug() ); ?>"
                                            data-key="<?php echo esc_attr( $ability->get_key() ); ?>"
                                            <?php checked( $ability->is_enabled() ); ?>>
                                        <span class="mcp-toggle__slider"></span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        <?php endforeach; ?>
        </div>

    </div><!-- /abilities tab -->

    <!-- ── TAB: Documentation ───────────────────────────────────────────── -->
    <div class="mcp-tab-panel mcp-tab-panel--hidden" id="mcp-tab-docs">
        <div class="mcp-docs">
            <h2><?php esc_html_e( 'Connecting to Claude', 'mcp-abilities' ); ?></h2>

            <div class="mcp-doc-section">
                <h3><?php esc_html_e( 'Step 1 – Find your MCP server URL', 'mcp-abilities' ); ?></h3>
                <p><?php esc_html_e( 'The MCP Adapter plugin exposes your site as an MCP server. Your server URL is:', 'mcp-abilities' ); ?></p>
                <pre class="mcp-code-block"><?php echo esc_html( home_url( '/wp-json/mcp/v1' ) ); ?></pre>
                <p><?php esc_html_e( 'You will need this URL when configuring Claude or any other MCP-compatible AI client.', 'mcp-abilities' ); ?></p>
            </div>

            <div class="mcp-doc-section">
                <h3><?php esc_html_e( 'Step 2 – Generate an Application Password', 'mcp-abilities' ); ?></h3>
                <p><?php esc_html_e( 'Claude authenticates using WordPress Application Passwords. To create one:', 'mcp-abilities' ); ?></p>
                <ol style="color:var(--mcp-muted);font-size:14px;padding-left:20px;margin:0 0 10px;">
                    <li><?php printf( esc_html__( 'Go to %s.', 'mcp-abilities' ), '<a href="' . esc_url( admin_url( 'profile.php' ) ) . '">' . esc_html__( 'Users → Profile', 'mcp-abilities' ) . '</a>' ); ?></li>
                    <li><?php esc_html_e( 'Scroll down to the "Application Passwords" section.', 'mcp-abilities' ); ?></li>
                    <li><?php esc_html_e( 'Enter a name (e.g. "Claude") and click Add New Application Password.', 'mcp-abilities' ); ?></li>
                    <li><?php esc_html_e( 'Copy the generated password — it will only be shown once.', 'mcp-abilities' ); ?></li>
                </ol>
                <p><?php esc_html_e( 'Use your WordPress username and the application password as the credentials when Claude prompts for authentication.', 'mcp-abilities' ); ?></p>
            </div>

            <div class="mcp-doc-section">
                <h3><?php esc_html_e( 'Step 3 – Add the server in Claude Desktop', 'mcp-abilities' ); ?></h3>
                <p><?php esc_html_e( 'Open your Claude Desktop configuration file and add the following entry under "mcpServers":', 'mcp-abilities' ); ?></p>
                <pre class="mcp-code-block"><?php
$site_url = home_url( '/wp-json/mcp/v1' );
echo esc_html(
    "\"mcpServers\": {\n" .
    "  \"vgc-ai-nexus\": {\n" .
    "    \"command\": \"npx\",\n" .
    "    \"args\": [\n" .
    "      \"-y\",\n" .
    "      \"mcp-remote\",\n" .
    "      \"{$site_url}\"\n" .
    "    ]\n" .
    "  }\n" .
    "}"
);
?></pre>
                <p><?php esc_html_e( 'The mcp-remote package handles the HTTP transport and Basic Auth handshake automatically. You will be prompted for your username and application password on first connect.', 'mcp-abilities' ); ?></p>
            </div>

            <div class="mcp-doc-section">
                <h3><?php esc_html_e( 'Step 4 – Connect from Claude.ai (Remote MCP)', 'mcp-abilities' ); ?></h3>
                <p><?php esc_html_e( 'On Claude.ai Pro and Team plans you can connect directly without installing anything:', 'mcp-abilities' ); ?></p>
                <ol style="color:var(--mcp-muted);font-size:14px;padding-left:20px;margin:0 0 10px;">
                    <li><?php esc_html_e( 'Open Claude.ai and go to Settings → Integrations.', 'mcp-abilities' ); ?></li>
                    <li><?php esc_html_e( 'Click "Add Integration" and choose "Custom MCP Server".', 'mcp-abilities' ); ?></li>
                    <li><?php printf( esc_html__( 'Paste your server URL: %s', 'mcp-abilities' ), '<code>' . esc_html( home_url( '/wp-json/mcp/v1' ) ) . '</code>' ); ?></li>
                    <li><?php esc_html_e( 'Enter your WordPress username and application password when prompted.', 'mcp-abilities' ); ?></li>
                </ol>
            </div>

            <div class="mcp-doc-section">
                <h3><?php esc_html_e( 'Troubleshooting', 'mcp-abilities' ); ?></h3>
                <p><?php esc_html_e( 'If Claude cannot connect or reports no tools:', 'mcp-abilities' ); ?></p>
                <ul style="color:var(--mcp-muted);font-size:14px;padding-left:20px;margin:0 0 10px;">
                    <li><?php esc_html_e( 'Confirm the MCP Adapter plugin is active.', 'mcp-abilities' ); ?></li>
                    <li><?php esc_html_e( 'Ensure at least one ability group is enabled on the Abilities tab.', 'mcp-abilities' ); ?></li>
                    <li><?php esc_html_e( 'Verify Application Passwords are enabled on your site (they require HTTPS).', 'mcp-abilities' ); ?></li>
                    <li><?php printf( esc_html__( 'Test the endpoint directly: %s', 'mcp-abilities' ), '<code>' . esc_html( home_url( '/wp-json/mcp/v1/tools' ) ) . '</code>' ); ?></li>
                </ul>
            </div>

        </div>
    </div><!-- /docs tab -->

</div><!-- /mcp-wrap -->
