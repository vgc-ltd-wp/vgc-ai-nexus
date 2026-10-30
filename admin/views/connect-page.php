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
            <?php echo \MCP_Abilities\Icons::logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup ?>
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
        // ── Admin approval for new connections ──────────────────────────────
        $appr_on      = \MCP_Abilities\Connection_Approval::enabled();
        $appr_forced  = \MCP_Abilities\Connection_Approval::forced();
        $appr_records = \MCP_Abilities\Connection_Approval::records();
        $appr_fmt     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        $appr_waiting = array_filter( $appr_records, static fn( $r ) => is_array( $r ) && in_array( $r['status'] ?? '', [ 'pending', 'rejected' ], true ) );
        $appr_ok      = array_filter( $appr_records, static fn( $r ) => is_array( $r ) && 'approved' === ( $r['status'] ?? '' ) );
        uasort( $appr_waiting, static fn( $a, $b ) => (int) $b['first_seen'] <=> (int) $a['first_seen'] );
        uasort( $appr_ok, static fn( $a, $b ) => (int) $b['decided_at'] <=> (int) $a['decided_at'] );
        $appr_who = static function ( array $r ): string {
            $u = get_userdata( (int) $r['user_id'] );
            return $u ? '<strong>' . esc_html( $u->display_name ) . '</strong><br><span class="mcp-section-sub">' . esc_html( $u->user_login . ' · ' . implode( ', ', (array) $u->roles ) ) . '</span>' : esc_html( '#' . (int) $r['user_id'] );
        };
        $appr_via = static function ( array $r ): string {
            $app = (string) ( $r['app'] ?? '' );
            return esc_html( 'oauth' === ( $r['auth'] ?? '' ) ? __( 'claude.ai sign-in', 'mcp-abilities' ) : __( 'Application Password', 'mcp-abilities' ) )
                . ( '' !== $app ? '<br><span class="mcp-section-sub">' . esc_html( $app ) . '</span>' : '' );
        };
        $appr_op = static function ( array $r ): string {
            $op = \MCP_Abilities\Connection_Approval::operator_of( $r );
            return '' !== $op ? esc_html( $op ) : '<span class="mcp-muted">&mdash;</span>';
        };
        $appr_when = static function ( $ts ) use ( $appr_fmt ): string {
            return (int) $ts ? esc_html( wp_date( $appr_fmt, (int) $ts ) ) : '&mdash;';
        };
        ?>
        <div class="mcp-doc-section mcp-approval" id="mcp-approval">
            <h3><?php esc_html_e( 'Admin approval for AI connections', 'mcp-abilities' ); ?></h3>
            <div class="mcp-approval__box<?php echo $appr_on ? ' is-on' : ''; ?>">
                <label class="mcp-readonly__switch">
                    <input type="checkbox" id="mcp-approval-toggle" <?php checked( $appr_on ); ?> <?php disabled( $appr_forced ); ?>>
                    <span class="mcp-readonly__title"><?php esc_html_e( 'Require admin approval for new connections', 'mcp-abilities' ); ?></span>
                    <span id="mcp-approval-status" class="mcp-save-status"></span>
                </label>
                <p class="mcp-readonly__help"><?php esc_html_e( 'When on, a new AI connection — a claude.ai sign-in or an Application Password — can connect but gets no tools until you approve it here. Until then it only sees that it is waiting. Administrators, WP-CLI and your own signed-in browser are never held.', 'mcp-abilities' ); ?></p>
                <?php if ( $appr_forced ) : ?>
                    <p class="mcp-readonly__help"><strong><?php esc_html_e( 'Locked on by VGC_AI_NEXUS_REQUIRE_APPROVAL in wp-config.php — remove that line to use this switch.', 'mcp-abilities' ); ?></strong></p>
                <?php endif; ?>
                <div class="mcp-approval__confirm" id="mcp-approval-confirm-on" hidden>
                    <p><?php esc_html_e( 'Turn on approval? New connections will get no tools until you approve them. Connections that work today are approved now, so scheduled tasks keep running.', 'mcp-abilities' ); ?></p>
                    <label class="mcp-approval__check"><input type="checkbox" id="mcp-approval-existing"> <?php esc_html_e( 'Also require approval for existing connections', 'mcp-abilities' ); ?></label>
                    <p>
                        <button type="button" class="mcp-btn mcp-btn--primary" id="mcp-approval-on-yes"><?php esc_html_e( 'Turn on', 'mcp-abilities' ); ?></button>
                        <button type="button" class="mcp-btn mcp-btn--ghost mcp-approval-cancel"><?php esc_html_e( 'Cancel', 'mcp-abilities' ); ?></button>
                    </p>
                </div>
                <div class="mcp-approval__confirm" id="mcp-approval-confirm-off" hidden>
                    <p><?php esc_html_e( 'Turn off approval? Every connection can use its tools again, including those waiting or rejected. The records below are kept.', 'mcp-abilities' ); ?></p>
                    <p>
                        <button type="button" class="mcp-btn mcp-btn--danger" id="mcp-approval-off-yes"><?php esc_html_e( 'Turn off', 'mcp-abilities' ); ?></button>
                        <button type="button" class="mcp-btn mcp-btn--ghost mcp-approval-cancel"><?php esc_html_e( 'Cancel', 'mcp-abilities' ); ?></button>
                    </p>
                </div>
            </div>

            <?php if ( $appr_on || $appr_records ) : ?>
                <?php if ( ! $appr_on ) : ?>
                    <p class="mcp-section-sub"><?php esc_html_e( 'Approval is off: these records are kept but not used.', 'mcp-abilities' ); ?></p>
                <?php endif; ?>
                <h4><?php printf( /* translators: %d: count */ esc_html__( 'Waiting for approval (%d)', 'mcp-abilities' ), count( $appr_waiting ) ); ?></h4>
                <div class="mcp-table-scroll">
                <table class="widefat striped mcp-connections-table" id="mcp-approval-waiting">
                    <thead><tr>
                        <th><?php esc_html_e( 'User', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Signed in with', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Who is connecting', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'First seen', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Last seen', 'mcp-abilities' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $appr_waiting as $key => $r ) : ?>
                        <tr data-key="<?php echo esc_attr( (string) $key ); ?>">
                            <td><?php echo $appr_who( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper ?>
                                <?php if ( 'rejected' === $r['status'] ) : ?><br><span class="mcp-approval-badge"><?php esc_html_e( 'Rejected', 'mcp-abilities' ); ?></span><?php endif; ?></td>
                            <td><?php echo $appr_via( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo $appr_op( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo $appr_when( $r['first_seen'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php if ( ! empty( $r['ip'] ) ) : ?><br><span class="mcp-section-sub"><?php echo esc_html( (string) $r['ip'] ); ?></span><?php endif; ?></td>
                            <td><?php echo $appr_when( $r['last_seen'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td class="mcp-approval__actions">
                                <button type="button" class="mcp-btn mcp-btn--primary mcp-approval-decide" data-decision="approve"><?php esc_html_e( 'Approve', 'mcp-abilities' ); ?></button>
                                <?php if ( 'rejected' === $r['status'] ) : ?>
                                    <button type="button" class="mcp-btn mcp-btn--link mcp-approval-decide" data-decision="remove"><?php esc_html_e( 'Remove record', 'mcp-abilities' ); ?></button>
                                <?php else : ?>
                                    <button type="button" class="mcp-btn mcp-btn--link mcp-approval-decide" data-decision="reject"><?php esc_html_e( 'Reject', 'mcp-abilities' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( ! $appr_waiting ) : ?>
                        <tr><td colspan="6" class="mcp-section-sub"><?php esc_html_e( 'Nothing is waiting.', 'mcp-abilities' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>

                <h4><?php printf( /* translators: %d: count */ esc_html__( 'Approved (%d)', 'mcp-abilities' ), count( $appr_ok ) ); ?></h4>
                <div class="mcp-table-scroll">
                <table class="widefat striped mcp-connections-table" id="mcp-approval-approved">
                    <thead><tr>
                        <th><?php esc_html_e( 'User', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Signed in with', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Who is connecting', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Approved', 'mcp-abilities' ); ?></th>
                        <th><?php esc_html_e( 'Last seen', 'mcp-abilities' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $appr_ok as $key => $r ) :
                        $by = (int) ( $r['decided_by'] ?? 0 ) ? get_userdata( (int) $r['decided_by'] ) : null; ?>
                        <tr data-key="<?php echo esc_attr( (string) $key ); ?>"<?php echo 'oauth' === ( $r['auth'] ?? '' ) ? ' data-client="' . esc_attr( (string) $r['client_id'] ) . '"' : ''; ?>>
                            <td><?php echo $appr_who( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo $appr_via( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo $appr_op( $r ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo $appr_when( $r['decided_at'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <br><span class="mcp-section-sub"><?php echo esc_html( $by ? sprintf( /* translators: %s: user login */ __( 'by %s', 'mcp-abilities' ), $by->user_login ) : '' ); ?><?php echo ! empty( $r['note'] ) ? ' · ' . esc_html( (string) $r['note'] ) : ''; ?></span></td>
                            <td><?php echo $appr_when( $r['last_seen'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td class="mcp-approval__actions">
                                <button type="button" class="mcp-btn mcp-btn--ghost mcp-approval-decide" data-decision="revoke"><?php esc_html_e( 'Revoke approval', 'mcp-abilities' ); ?></button>
                                <?php if ( 'oauth' === ( $r['auth'] ?? '' ) ) : ?>
                                    <button type="button" class="mcp-btn mcp-btn--link mcp-approval-disconnect"><?php esc_html_e( 'Disconnect', 'mcp-abilities' ); ?></button>
                                    <span class="mcp-approval__inline-confirm" hidden>
                                        <?php esc_html_e( 'Disconnect? Claude loses access and must connect again.', 'mcp-abilities' ); ?>
                                        <button type="button" class="mcp-btn mcp-btn--danger mcp-approval-disconnect-yes"><?php esc_html_e( 'Disconnect', 'mcp-abilities' ); ?></button>
                                        <button type="button" class="mcp-btn mcp-btn--ghost mcp-approval-disconnect-no"><?php esc_html_e( 'Cancel', 'mcp-abilities' ); ?></button>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( ! $appr_ok ) : ?>
                        <tr><td colspan="6" class="mcp-section-sub"><?php esc_html_e( 'No approved connections yet.', 'mcp-abilities' ); ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
                <span id="mcp-approval-decide-status" class="mcp-save-status"></span>
            <?php endif; ?>
        </div>

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
                        esc_html( (string) \MCP_Abilities\OAuth\Bootstrap::connector_version() )
                    );
                ?></p>
            <?php elseif ( 'off' === $oauth_mode ) : ?>
                <p><?php esc_html_e( 'Sign-in for claude.ai is switched off on this site (the mcp_abilities_oauth_enabled filter).', 'mcp-abilities' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'Works from anywhere, with nothing running on your computer — in claude.ai on the web, the Claude mobile apps, Claude Desktop’s connectors and scheduled tasks. In claude.ai open Settings → Connectors → Add custom connector, paste this address, leave the OAuth client ID and secret empty, then click Connect and approve with your WordPress login.', 'mcp-abilities' ); ?></p>
                <pre class="mcp-code-block"><?php echo esc_html( $server_url ); ?></pre>
                <?php $mcp_icon = (string) get_site_icon_url( 64 ); ?>
                <p class="mcp-section-sub" style="display:flex;align-items:center;gap:8px;">
                    <?php if ( '' !== $mcp_icon ) : ?>
                        <img src="<?php echo esc_url( $mcp_icon ); ?>" alt="" width="24" height="24" style="border-radius:4px;">
                        <?php
                        printf(
                            /* translators: %s: site name */
                            esc_html__( 'Claude is told this site is “%s”, with this icon (the Site Icon). Changed it? Remove and re-add the connector in claude.ai to refresh it.', 'mcp-abilities' ),
                            esc_html( \MCP_Abilities\Site_Identity::title() )
                        );
                        ?>
                    <?php else : ?>
                        <?php
                        printf(
                            /* translators: %s: link to the Customizer's Site Identity panel */
                            wp_kses( __( 'This site has no Site Icon, so Claude guesses one — often the main domain’s. <a href="%s">Set a Site Icon</a> to give this site its own.', 'mcp-abilities' ), [ 'a' => [ 'href' => [] ] ] ),
                            esc_url( admin_url( 'customize.php?autofocus[section]=title_tagline' ) )
                        );
                        ?>
                    <?php endif; ?>
                </p>
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
                            <th><?php esc_html_e( 'Signed in as · who is connecting', 'mcp-abilities' ); ?></th>
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
                            <td>
                                <?php if ( empty( $app['people'] ) ) : ?>&mdash;<?php endif; ?>
                                <?php foreach ( (array) ( $app['people'] ?? [] ) as $person ) : ?>
                                    <span class="mcp-oauth-user" data-user="<?php echo (int) $person['user_id']; ?>">
                                        <?php echo esc_html( $person['login'] ); ?> &middot;
                                        <span class="mcp-oauth-operator-name"<?php echo '' === $person['operator'] ? ' hidden' : ''; ?>><?php echo esc_html( $person['operator'] ); ?></span>
                                        <span class="mcp-oauth-operator-none"<?php echo '' !== $person['operator'] ? ' hidden' : ''; ?>><?php esc_html_e( 'no name given', 'mcp-abilities' ); ?></span>
                                        <button type="button" class="mcp-btn mcp-btn--link mcp-oauth-operator-edit" title="<?php esc_attr_e( 'Who is at the keyboard of this connection. Shown with every change made through it; not checked.', 'mcp-abilities' ); ?>"><?php esc_html_e( 'Edit name', 'mcp-abilities' ); ?></button>
                                        <span class="mcp-oauth-operator-form" hidden>
                                            <input type="text" class="regular-text" maxlength="<?php echo (int) \MCP_Abilities\OAuth\Operators::MAX; ?>" value="<?php echo esc_attr( $person['operator'] ); ?>"
                                                placeholder="<?php esc_attr_e( 'maria@company.com', 'mcp-abilities' ); ?>" aria-label="<?php esc_attr_e( 'Who is connecting', 'mcp-abilities' ); ?>">
                                            <button type="button" class="mcp-btn mcp-btn--primary mcp-oauth-operator-save"><?php esc_html_e( 'Save', 'mcp-abilities' ); ?></button>
                                            <button type="button" class="mcp-btn mcp-btn--link mcp-oauth-operator-cancel"><?php esc_html_e( 'Cancel', 'mcp-abilities' ); ?></button>
                                        </span>
                                    </span>
                                <?php endforeach; ?>
                            </td>
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
