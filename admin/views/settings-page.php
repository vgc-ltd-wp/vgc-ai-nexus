<?php
/**
 * Admin Settings Page View
 *
 * Variables available:
 *   $groups – Ability_Group[]
 */

defined( 'ABSPATH' ) || exit;

$mcp_consolidate = (bool) get_option( 'mcp_abilities_consolidate', true );

// Tally tools exposed to the MCP server vs. the underlying abilities.
$mcp_tool_total    = 0;
$mcp_ability_total = 0;
foreach ( $groups as $g ) {
    $mcp_tool_total    += count( $g->get_mcp_abilities() );
    $mcp_ability_total += count( $g->get_abilities() );
}
// Claude also always sees the adapter's 3 meta-tools (discover / get-info / execute).
$mcp_meta_tools  = 3;
$mcp_grand_total = $mcp_tool_total + $mcp_meta_tools;

// Traffic-light against Claude's practical per-connector tool budget.
$mcp_budget_class = $mcp_grand_total <= 25 ? 'is-green' : ( $mcp_grand_total <= 40 ? 'is-amber' : 'is-red' );
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

        <!-- Tool budget summary + consolidation control -->
        <div class="mcp-budget">
            <div class="mcp-budget__counter">
                <span class="mcp-budget__dot <?php echo esc_attr( $mcp_budget_class ); ?>"></span>
                <span class="mcp-budget__num"><?php echo (int) $mcp_grand_total; ?></span>
                <span class="mcp-budget__label">
                    <?php esc_html_e( 'tools exposed to Claude', 'mcp-abilities' ); ?>
                </span>
                <span class="mcp-budget__detail">
                    <?php
                    printf(
                        /* translators: 1: AI Nexus tool count, 2: meta-tool count, 3: underlying ability count */
                        esc_html__( '(%1$d from AI Nexus + %2$d adapter meta-tools, backed by %3$d abilities)', 'mcp-abilities' ),
                        (int) $mcp_tool_total,
                        (int) $mcp_meta_tools,
                        (int) $mcp_ability_total
                    );
                    ?>
                </span>
            </div>
            <label class="mcp-budget__consolidate">
                <input type="checkbox" id="mcp-consolidate-toggle" <?php checked( $mcp_consolidate ); ?>>
                <span><?php esc_html_e( 'Consolidate each group into a single tool (recommended)', 'mcp-abilities' ); ?></span>
            </label>
            <p class="mcp-budget__help">
                <?php esc_html_e( 'When on, each group is exposed as ONE tool with an "action" parameter (list, create, update, delete…) instead of one tool per operation. Every ability still works and per-ability/permission settings are preserved — this just keeps the tool count under Claude\'s budget so the connector loads reliably.', 'mcp-abilities' ); ?>
            </p>
            <p id="mcp-consolidate-hint" class="mcp-budget__hint" style="display:none;">
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e( 'Save, then reload this page to see the updated tool count.', 'mcp-abilities' ); ?>
            </p>
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
                        <?php
                        $g_abilities = count( $group->get_abilities() );
                        $g_tools     = count( $group->get_mcp_abilities() );
                        $g_locked    = method_exists( $group, 'is_locked' ) && $group->is_locked();
                        ?>
                        <?php if ( $g_locked ) : ?>
                            <span class="mcp-badge mcp-badge--locked" title="<?php esc_attr_e( 'Disabled in wp-config.php (MCP_ABILITIES_DISABLE_CUSTOM_CODE)', 'mcp-abilities' ); ?>">
                                <span class="dashicons dashicons-lock" style="font-size:14px;width:14px;height:14px;"></span>
                                <?php esc_html_e( 'Locked by config', 'mcp-abilities' ); ?>
                            </span>
                        <?php elseif ( $g_tools < $g_abilities ) : ?>
                            <span class="mcp-badge mcp-badge--consolidated" title="<?php esc_attr_e( 'Exposed as a single tool with an action parameter', 'mcp-abilities' ); ?>">
                                <?php
                                printf(
                                    /* translators: 1: number of abilities, 2: number of exposed tools */
                                    esc_html__( '%1$d abilities → %2$d tool', 'mcp-abilities' ),
                                    (int) $g_abilities,
                                    (int) $g_tools
                                );
                                ?>
                            </span>
                        <?php else : ?>
                            <span class="mcp-badge"><?php echo (int) $g_abilities; ?> <?php esc_html_e( 'tools', 'mcp-abilities' ); ?></span>
                        <?php endif; ?>
                        <label class="mcp-toggle" title="<?php echo $g_locked ? esc_attr__( 'Disabled in wp-config.php; cannot be enabled here.', 'mcp-abilities' ) : esc_attr__( 'Enable/disable entire group', 'mcp-abilities' ); ?>">
                            <input type="checkbox"
                                class="mcp-group-toggle"
                                data-group="<?php echo esc_attr( $group->get_slug() ); ?>"
                                <?php checked( $group->is_enabled() ); ?>
                                <?php disabled( $g_locked ); ?>>
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
