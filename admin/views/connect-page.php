<?php
/**
 * Admin "Connect" page — generate an Application Password + Claude Desktop config.
 */

defined( 'ABSPATH' ) || exit;

use MCP_Abilities\Admin\Connect;

$server_url  = Connect::server_url();
$connections = Connect::list_connections();
$adapter_ok  = Connect::adapter_active();
?>
<div class="mcp-wrap">

    <div class="mcp-header">
        <div class="mcp-header__logo">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="32" height="32" rx="8" fill="#1a1a2e"/>
                <path d="M8 16L16 8L24 16L16 24L8 16Z" stroke="#6C63FF" stroke-width="2" fill="none"/>
                <circle cx="16" cy="16" r="3" fill="#6C63FF"/>
            </svg>
            <div>
                <h1><?php esc_html_e( 'Connect to Claude', 'mcp-abilities' ); ?></h1>
                <p><?php esc_html_e( 'This plugin includes the MCP server — generate a password and paste the config into Claude Desktop.', 'mcp-abilities' ); ?></p>
            </div>
        </div>
    </div>

    <?php if ( ! $adapter_ok ) : ?>
    <div class="mcp-group__warning" style="margin-bottom:16px;">
        <span class="dashicons dashicons-warning"></span>
        <?php esc_html_e( 'The bundled MCP server failed to load. Another MCP Adapter may be conflicting.', 'mcp-abilities' ); ?>
    </div>
    <?php endif; ?>

    <div class="mcp-docs">

        <?php
        $oauth_mode = \MCP_Abilities\OAuth\Bootstrap::mode();
        $oauth_apps = 'core' === $oauth_mode ? \MCP_Abilities\OAuth\Bootstrap::connected_apps() : [];
        $date_fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        ?>
        <div class="mcp-doc-section mcp-oauth" id="mcp-oauth">
            <h3><?php esc_html_e( 'claude.ai — web, mobile and scheduled tasks', 'mcp-abilities' ); ?></h3>
            <?php if ( 'connector' === $oauth_mode ) : ?>
                <p><?php
                    printf(
                        /* translators: %s: connector plugin version */
                        esc_html__( 'Sign-in for claude.ai is currently served by the VGC MCP Claude Connector (OAuth) plugin, version %s. AI Nexus now includes it: update that plugin to 1.4.0 or later, or deactivate it, and AI Nexus takes over. Existing claude.ai connections keep working either way.', 'mcp-abilities' ),
                        esc_html( (string) VGC_CC_VERSION )
                    );
                ?></p>
            <?php elseif ( 'off' === $oauth_mode ) : ?>
                <p><?php esc_html_e( 'Sign-in for claude.ai is switched off on this site (the mcp_abilities_oauth_enabled filter).', 'mcp-abilities' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'Works from anywhere, with nothing running on your computer — in claude.ai on the web, the Claude mobile apps, Claude Desktop’s connectors and scheduled tasks. In claude.ai open Settings → Connectors → Add custom connector, paste this address, leave the OAuth client ID and secret empty, then click Connect and approve with your WordPress login.', 'mcp-abilities' ); ?></p>
                <pre class="mcp-code-block"><?php echo esc_html( $server_url ); ?></pre>
                <p class="mcp-section-sub"><?php esc_html_e( 'Claude acts with the permissions of the WordPress user who approves the connection, and stays signed in (renewing itself) until you disconnect it below.', 'mcp-abilities' ); ?></p>

                <p>
                    <label for="mcp-oauth-policy" style="font-weight:600;margin-right:8px;"><?php esc_html_e( 'Which apps may connect:', 'mcp-abilities' ); ?></label>
                    <select id="mcp-oauth-policy" class="mcp-select">
                        <?php
                        $policy  = \MCP_Abilities\OAuth\Options::policy();
                        $choices = [
                            'restricted' => __( 'Claude only (claude.ai and Anthropic) — recommended', 'mcp-abilities' ),
                            'open'       => __( 'Any app (each still needs your approval)', 'mcp-abilities' ),
                            'closed'     => __( 'None — no new connections', 'mcp-abilities' ),
                        ];
                        foreach ( $choices as $value => $label ) :
                        ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $policy, $value ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span id="mcp-oauth-policy-status" class="mcp-save-status"></span>
                </p>

                <table class="widefat striped mcp-connections-table" id="mcp-oauth-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'App', 'mcp-abilities' ); ?></th>
                            <th><?php esc_html_e( 'Registered', 'mcp-abilities' ); ?></th>
                            <th><?php esc_html_e( 'Signed in as', 'mcp-abilities' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'mcp-abilities' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $oauth_apps as $app ) : ?>
                        <tr data-client="<?php echo esc_attr( $app['client_id'] ); ?>">
                            <td><strong><?php echo esc_html( $app['name'] ?: __( 'Unnamed app', 'mcp-abilities' ) ); ?></strong>
                                <?php if ( $app['hosts'] ) : ?><br><span class="mcp-section-sub"><?php echo esc_html( implode( ', ', $app['hosts'] ) ); ?></span><?php endif; ?></td>
                            <td><?php echo $app['created_at'] ? esc_html( wp_date( $date_fmt, $app['created_at'] ) ) : '&mdash;'; ?></td>
                            <td><?php echo $app['users'] ? esc_html( implode( ', ', $app['users'] ) ) : '&mdash;'; ?></td>
                            <td><?php
                                echo $app['connected']
                                    ? esc_html( sprintf( /* translators: %s: date */ __( 'Connected (renews until %s)', 'mcp-abilities' ), wp_date( get_option( 'date_format' ), $app['refresh_until'] ) ) )
                                    : esc_html__( 'Not connected', 'mcp-abilities' );
                            ?></td>
                            <td><button class="mcp-btn mcp-btn--link mcp-oauth-revoke" type="button"><?php echo $app['connected'] ? esc_html__( 'Disconnect', 'mcp-abilities' ) : esc_html__( 'Remove', 'mcp-abilities' ); ?></button></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="mcp-conn-empty" <?php echo empty( $oauth_apps ) ? '' : 'style="display:none;"'; ?>>
                            <td colspan="5" class="mcp-section-sub"><?php esc_html_e( 'No claude.ai connections yet.', 'mcp-abilities' ); ?></td>
                        </tr>
                    </tbody>
                </table>
                <span id="mcp-oauth-status" class="mcp-save-status"></span>
            <?php endif; ?>
        </div>

        <h2 style="margin:28px 0 8px;"><?php esc_html_e( 'Claude Desktop with an Application Password', 'mcp-abilities' ); ?></h2>

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'Step 1 — Your MCP server URL', 'mcp-abilities' ); ?></h3>
            <p><?php esc_html_e( 'Claude connects to this site at:', 'mcp-abilities' ); ?></p>
            <pre class="mcp-code-block"><?php echo esc_html( $server_url ); ?></pre>
        </div>

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'Step 2 — Generate the connection', 'mcp-abilities' ); ?></h3>
            <p><?php esc_html_e( 'Choose the WordPress user the connection will act as — Claude’s permissions match this account’s capabilities — then generate its Application Password. The password is shown once.', 'mcp-abilities' ); ?></p>
            <?php
            $connect_users   = Connect::eligible_users();
            $connect_current = get_current_user_id();
            ?>
            <p>
                <label for="mcp-connect-user-select" style="font-weight:600;margin-right:8px;"><?php esc_html_e( 'Connection user:', 'mcp-abilities' ); ?></label>
                <select id="mcp-connect-user-select" class="mcp-select">
                    <?php foreach ( $connect_users as $u ) :
                        $roles = $u->roles ? ' — ' . esc_html( ucfirst( (string) reset( $u->roles ) ) ) : '';
                    ?>
                        <option value="<?php echo (int) $u->ID; ?>" <?php selected( $u->ID, $connect_current ); ?>>
                            <?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ) . $roles; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <button id="mcp-generate-pw" class="mcp-btn mcp-btn--primary">
                    <span class="dashicons dashicons-admin-network"></span>
                    <?php esc_html_e( 'Generate Application Password', 'mcp-abilities' ); ?>
                </button>
                <span id="mcp-generate-status" class="mcp-save-status"></span>
            </p>

            <div id="mcp-connect-result" style="display:none;">
                <p style="margin-top:14px;">
                    <strong><?php esc_html_e( 'Username:', 'mcp-abilities' ); ?></strong>
                    <code id="mcp-connect-user"></code><br>
                    <strong><?php esc_html_e( 'Application Password:', 'mcp-abilities' ); ?></strong>
                    <code id="mcp-connect-pass"></code>
                </p>
                <p><?php esc_html_e( 'Paste this into your Claude Desktop config (Settings → Developer → Edit Config), merging under "mcpServers":', 'mcp-abilities' ); ?></p>
                <pre class="mcp-code-block"><code id="mcp-connect-config"></code></pre>
                <p class="mcp-section-sub"><?php esc_html_e( 'Copy it now — the password cannot be shown again. Generating a new one replaces the previous connection for your account.', 'mcp-abilities' ); ?></p>
            </div>
        </div>

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'Step 3 — Restart Claude Desktop', 'mcp-abilities' ); ?></h3>
            <p><?php esc_html_e( 'Restart Claude Desktop; your site’s tools appear under the connector. Manage which tools are exposed on the Abilities and Extensions pages.', 'mcp-abilities' ); ?></p>
        </div>

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'Existing connections', 'mcp-abilities' ); ?></h3>
            <p class="mcp-section-sub"><?php esc_html_e( 'Connections created by AI Nexus. The password itself is never stored (WordPress hashes it), but each connection is recorded here so you can see who it belongs to and revoke it.', 'mcp-abilities' ); ?></p>
            <table class="widefat striped mcp-connections-table" id="mcp-connections-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'User', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Created', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Last used', 'mcp-abilities' ); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
                    foreach ( $connections as $c ) :
                    ?>
                    <tr data-user="<?php echo (int) $c['user_id']; ?>" data-uuid="<?php echo esc_attr( $c['uuid'] ); ?>">
                        <td>
                            <strong><?php echo esc_html( $c['display_name'] ); ?></strong>
                            <code><?php echo esc_html( $c['user_login'] ); ?></code>
                        </td>
                        <td><?php echo $c['created'] ? esc_html( wp_date( $date_fmt, $c['created'] ) ) : '&mdash;'; ?></td>
                        <td>
                            <?php
                            if ( $c['last_used'] ) {
                                echo esc_html( wp_date( $date_fmt, $c['last_used'] ) );
                                if ( $c['last_ip'] ) {
                                    echo ' <span class="mcp-section-sub">(' . esc_html( $c['last_ip'] ) . ')</span>';
                                }
                            } else {
                                esc_html_e( 'Never', 'mcp-abilities' );
                            }
                            ?>
                        </td>
                        <td>
                            <button class="mcp-btn mcp-btn--link mcp-revoke-conn" type="button">
                                <?php esc_html_e( 'Revoke', 'mcp-abilities' ); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="mcp-conn-empty" <?php echo empty( $connections ) ? '' : 'style="display:none;"'; ?>>
                        <td colspan="4" class="mcp-section-sub"><?php esc_html_e( 'No connections yet. Generate one above.', 'mcp-abilities' ); ?></td>
                    </tr>
                </tbody>
            </table>
            <span id="mcp-revoke-status" class="mcp-save-status"></span>
        </div>

    </div>
</div>
