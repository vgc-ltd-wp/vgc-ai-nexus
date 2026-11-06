<?php
/**
 * AI Nexus → Other AI tools.
 *
 * Variables available:
 *   $inventory – Other_Tools::inventory(): servers, tools, endpoints
 *   $settings  – Other_Tools::settings()
 *   $read_only – bool, read-only mode is on
 *   $security  – string[], Security_Status::other_ai_lines()
 */

use MCP_Abilities\Admin\Other_Tools_Page;

defined( 'ABSPATH' ) || exit;

// Tools grouped by plugin (ability namespace), or by server for tools without an ability.
$mcp_ot_groups = [];
foreach ( $inventory['tools'] as $mcp_ot_tool ) {
    $mcp_ot_groups[ $mcp_ot_tool['group'] ][] = $mcp_ot_tool;
}

/**
 * One On/Off switch.
 *
 * @param string $kind     tools | servers | endpoints
 * @param string $id       What it switches.
 * @param bool   $on       Current state.
 * @param string $label    What it is called, for screen readers.
 */
$mcp_ot_switch = static function ( string $kind, string $id, bool $on, string $label ): void {
    ?>
    <label class="mcp-toggle mcp-other__switch" title="<?php esc_attr_e( 'On or off for AI assistants', 'mcp-abilities' ); ?>">
        <input type="checkbox" class="mcp-other-switch" data-kind="<?php echo esc_attr( $kind ); ?>" data-id="<?php echo esc_attr( $id ); ?>" <?php checked( $on ); ?>
            aria-label="<?php /* translators: %s: tool, server or address */ echo esc_attr( sprintf( __( '%s: on for AI assistants', 'mcp-abilities' ), $label ) ); ?>">
        <span class="mcp-toggle__slider"></span>
    </label>
    <?php
};
?>
<div class="mcp-wrap mcp-other">

    <div class="mcp-header">
        <div class="mcp-header__logo">
            <?php echo \MCP_Abilities\Icons::logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup ?>
            <div>
                <h1><?php esc_html_e( 'Other AI tools', 'mcp-abilities' ); ?></h1>
                <p><?php esc_html_e( 'MCP servers and AI tools that other plugins add to this site. Turn off what AI assistants should not use.', 'mcp-abilities' ); ?></p>
            </div>
        </div>
    </div>

    <div class="mcp-readonly<?php echo $read_only ? ' is-on' : ''; ?>">
        <p class="mcp-readonly__title">
            <?php
            echo $read_only
                ? esc_html__( 'Read-only mode is on: AI assistants can use the tools below that only read. Tools that change the site are blocked.', 'mcp-abilities' )
                : esc_html__( 'Read-only mode is off. When it is on, it covers the tools below too: only tools that read can be used.', 'mcp-abilities' );
            ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=mcp-abilities' ) ); ?>"><?php esc_html_e( 'Read-only mode', 'mcp-abilities' ); ?></a>
        </p>
        <p class="mcp-readonly__help"><?php esc_html_e( 'A tool counts as reading only when its plugin marks it so, or when you tick "Counts as a read" for a tool its plugin did not mark. Everything else counts as a change.', 'mcp-abilities' ); ?></p>
        <?php if ( $security ) : ?>
            <ul class="mcp-other__security">
                <?php foreach ( $security as $mcp_ot_line ) : ?>
                    <li><?php echo esc_html( $mcp_ot_line ); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="mcp-toolbar">
        <button type="button" id="mcp-other-save" class="mcp-btn mcp-btn--primary">
            <span class="dashicons dashicons-saved"></span>
            <?php esc_html_e( 'Save Settings', 'mcp-abilities' ); ?>
        </button>
        <span id="mcp-other-status" class="mcp-save-status" role="status"></span>
    </div>

    <!-- 1. MCP servers -->
    <section class="mcp-other__section">
        <h2><?php esc_html_e( 'MCP servers on this site', 'mcp-abilities' ); ?></h2>
        <p class="mcp-other__intro"><?php esc_html_e( "An MCP server is an address AI assistants connect to. AI Nexus's own server is always on. A server that is off is gone: its address answers \"not found\".", 'mcp-abilities' ); ?></p>
        <?php if ( ! $inventory['servers'] ) : ?>
            <p class="mcp-muted"><?php esc_html_e( 'No MCP server is running. Is the MCP adapter loaded?', 'mcp-abilities' ); ?></p>
        <?php endif; ?>
        <?php foreach ( $inventory['servers'] as $mcp_ot_server ) : ?>
            <div class="mcp-other__card" data-server="<?php echo esc_attr( $mcp_ot_server['id'] ); ?>">
                <div class="mcp-other__card-head">
                    <div class="mcp-other__card-title">
                        <strong><?php echo esc_html( $mcp_ot_server['name'] ); ?></strong>
                        <code class="mcp-other__id"><?php echo esc_html( $mcp_ot_server['id'] ); ?></code>
                        <?php if ( $mcp_ot_server['new'] ) : ?><span class="mcp-badge mcp-other__new"><?php esc_html_e( 'New', 'mcp-abilities' ); ?></span><?php endif; ?>
                    </div>
                    <?php if ( $mcp_ot_server['own'] ) : ?>
                        <span class="mcp-badge mcp-badge--locked"><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Always on', 'mcp-abilities' ); ?></span>
                    <?php else : ?>
                        <?php $mcp_ot_switch( 'servers', $mcp_ot_server['id'], $mcp_ot_server['on'], $mcp_ot_server['name'] ); ?>
                    <?php endif; ?>
                </div>
                <dl class="mcp-other__facts">
                    <dt><?php esc_html_e( 'Address', 'mcp-abilities' ); ?></dt>
                    <dd><code><?php echo esc_html( '' !== $mcp_ot_server['address'] ? $mcp_ot_server['address'] : __( 'none (command line only)', 'mcp-abilities' ) ); ?></code></dd>
                    <dt><?php esc_html_e( 'From', 'mcp-abilities' ); ?></dt>
                    <dd><?php echo esc_html( '' !== $mcp_ot_server['source'] ? $mcp_ot_server['source'] : __( 'unknown plugin', 'mcp-abilities' ) ); ?></dd>
                    <?php if ( $mcp_ot_server['transports'] ) : ?>
                        <dt><?php esc_html_e( 'Served by', 'mcp-abilities' ); ?></dt>
                        <dd><code><?php echo esc_html( implode( ', ', $mcp_ot_server['transports'] ) ); ?></code></dd>
                    <?php endif; ?>
                    <dt><?php esc_html_e( 'Has', 'mcp-abilities' ); ?></dt>
                    <dd>
                        <?php
                        echo esc_html( sprintf(
                            /* translators: 1: number of tools, 2: number of resources, 3: number of prompts */
                            __( '%1$d tools, %2$d resources, %3$d prompts', 'mcp-abilities' ),
                            count( $mcp_ot_server['tools'] ),
                            count( $mcp_ot_server['resources'] ),
                            count( $mcp_ot_server['prompts'] )
                        ) );
                        ?>
                        <?php if ( $mcp_ot_server['tools'] || $mcp_ot_server['resources'] || $mcp_ot_server['prompts'] ) : ?>
                            <details class="mcp-other__names">
                                <summary><?php esc_html_e( 'Names', 'mcp-abilities' ); ?></summary>
                                <code><?php echo esc_html( implode( ', ', array_merge( $mcp_ot_server['tools'], $mcp_ot_server['resources'], $mcp_ot_server['prompts'] ) ) ); ?></code>
                            </details>
                        <?php endif; ?>
                    </dd>
                </dl>
                <?php if ( ! $mcp_ot_server['own'] ) : ?>
                    <p class="mcp-other__note"><?php esc_html_e( "AI Nexus's sign-in and connection approval do not cover this server if it has a sign-in of its own (WooCommerce's uses its own API keys). Read-only mode and the switches on this page do cover its tools.", 'mcp-abilities' ); ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- 2. Other plugins' tools -->
    <section class="mcp-other__section">
        <h2><?php esc_html_e( "Other plugins' tools AI can reach", 'mcp-abilities' ); ?></h2>
        <p class="mcp-other__intro"><?php esc_html_e( "AI Nexus's own tools are set on the Abilities and Extensions pages. These come from other plugins. A tool that is off is refused and no longer listed for AI assistants; the plugin itself and wp-admin keep working as before.", 'mcp-abilities' ); ?></p>
        <?php if ( ! $mcp_ot_groups ) : ?>
            <p class="mcp-muted"><?php esc_html_e( "No other plugin's tool can be reached by AI assistants on this site.", 'mcp-abilities' ); ?></p>
        <?php endif; ?>
        <?php foreach ( $mcp_ot_groups as $mcp_ot_group => $mcp_ot_rows ) : ?>
            <?php
            $mcp_ot_first  = $mcp_ot_rows[0];
            $mcp_ot_title  = 0 === strpos( (string) $mcp_ot_group, 'server:' )
                /* translators: %s: MCP server name */
                ? sprintf( __( 'Tools added to %s', 'mcp-abilities' ), $mcp_ot_first['group_label'] )
                : (string) $mcp_ot_group;
            $mcp_ot_source = '';
            foreach ( $mcp_ot_rows as $mcp_ot_row ) {
                if ( '' !== $mcp_ot_row['source'] ) {
                    $mcp_ot_source = $mcp_ot_row['source'];
                    break;
                }
            }
            ?>
            <div class="mcp-other__card">
                <h3 class="mcp-other__group">
                    <?php echo esc_html( $mcp_ot_title ); ?>
                    <?php if ( '' !== $mcp_ot_source ) : ?>
                        <span class="mcp-muted">— <?php echo esc_html( $mcp_ot_source ); ?></span>
                    <?php endif; ?>
                </h3>
                <table class="mcp-other__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Tool', 'mcp-abilities' ); ?></th>
                            <th><?php esc_html_e( 'How AI reaches it', 'mcp-abilities' ); ?></th>
                            <th><?php esc_html_e( 'Reads or changes', 'mcp-abilities' ); ?></th>
                            <th class="mcp-other__col-switch"><?php esc_html_e( 'On', 'mcp-abilities' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $mcp_ot_rows as $mcp_ot_row ) : ?>
                        <?php $mcp_ot_reads = 'read' === $mcp_ot_row['marked'] || $mcp_ot_row['admin_read']; ?>
                        <tr class="mcp-other__row" data-tool="<?php echo esc_attr( $mcp_ot_row['id'] ); ?>">
                            <td class="mcp-other__col-tool">
                                <code class="mcp-other__id"><?php echo esc_html( $mcp_ot_row['name'] ); ?></code>
                                <?php if ( $mcp_ot_row['new'] ) : ?><span class="mcp-badge mcp-other__new"><?php esc_html_e( 'New', 'mcp-abilities' ); ?></span><?php endif; ?>
                                <?php if ( '' !== $mcp_ot_row['label'] ) : ?>
                                    <span class="mcp-other__label"><?php echo esc_html( $mcp_ot_row['label'] ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="mcp-other__col-ways">
                                <?php echo esc_html( implode( '; ', array_filter( array_map( [ Other_Tools_Page::class, 'way_label' ], $mcp_ot_row['ways'] ) ) ) ); ?>
                            </td>
                            <td class="mcp-other__col-marked">
                                <span class="mcp-other__marked mcp-other__marked--<?php echo esc_attr( $mcp_ot_row['marked'] ); ?>"><?php echo esc_html( Other_Tools_Page::marked_label( $mcp_ot_row['marked'] ) ); ?></span>
                                <?php if ( 'unmarked' === $mcp_ot_row['marked'] ) : ?>
                                    <label class="mcp-other__read">
                                        <input type="checkbox" class="mcp-other-read" data-id="<?php echo esc_attr( $mcp_ot_row['id'] ); ?>" <?php checked( $mcp_ot_row['admin_read'] ); ?>>
                                        <?php esc_html_e( 'Counts as a read', 'mcp-abilities' ); ?>
                                    </label>
                                <?php endif; ?>
                                <?php if ( $read_only && 'read' !== $mcp_ot_row['marked'] ) : ?>
                                    <span class="mcp-other__blocked" <?php echo $mcp_ot_reads ? 'hidden' : ''; ?>><?php esc_html_e( 'Blocked while read-only mode is on', 'mcp-abilities' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="mcp-other__col-switch">
                                <?php $mcp_ot_switch( 'tools', $mcp_ot_row['id'], $mcp_ot_row['on'], $mcp_ot_row['name'] ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- 3. Other MCP-like addresses -->
    <section class="mcp-other__section">
        <h2><?php esc_html_e( 'Other MCP-like addresses', 'mcp-abilities' ); ?></h2>
        <p class="mcp-other__intro"><?php esc_html_e( "Addresses on this site that look like MCP endpoints but are not served through AI Nexus's adapter: AI Nexus cannot see or limit their tools; it can only switch the address off.", 'mcp-abilities' ); ?></p>
        <?php if ( ! $inventory['endpoints'] ) : ?>
            <p class="mcp-muted"><?php esc_html_e( 'None found.', 'mcp-abilities' ); ?></p>
        <?php else : ?>
            <div class="mcp-other__card">
                <table class="mcp-other__table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Address', 'mcp-abilities' ); ?></th>
                            <th><?php esc_html_e( 'From', 'mcp-abilities' ); ?></th>
                            <th class="mcp-other__col-switch"><?php esc_html_e( 'On', 'mcp-abilities' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $inventory['endpoints'] as $mcp_ot_endpoint ) : ?>
                        <tr class="mcp-other__row">
                            <td class="mcp-other__col-tool">
                                <code class="mcp-other__id"><?php echo esc_html( $mcp_ot_endpoint['route'] ); ?></code>
                                <?php if ( $mcp_ot_endpoint['new'] ) : ?><span class="mcp-badge mcp-other__new"><?php esc_html_e( 'New', 'mcp-abilities' ); ?></span><?php endif; ?>
                                <?php if ( $mcp_ot_endpoint['methods'] ) : ?>
                                    <span class="mcp-other__label"><?php echo esc_html( implode( ', ', $mcp_ot_endpoint['methods'] ) ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="mcp-other__col-ways"><?php echo esc_html( '' !== $mcp_ot_endpoint['source'] ? $mcp_ot_endpoint['source'] : __( 'unknown plugin', 'mcp-abilities' ) ); ?></td>
                            <td class="mcp-other__col-switch"><?php $mcp_ot_switch( 'endpoints', $mcp_ot_endpoint['route'], $mcp_ot_endpoint['on'], $mcp_ot_endpoint['route'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- 4. New ones -->
    <section class="mcp-other__section">
        <h2><?php esc_html_e( 'When a plugin adds a new AI tool or MCP server', 'mcp-abilities' ); ?></h2>
        <fieldset class="mcp-other__card mcp-other__policy">
            <legend class="screen-reader-text"><?php esc_html_e( 'When a plugin adds a new AI tool or MCP server', 'mcp-abilities' ); ?></legend>
            <label>
                <input type="radio" name="mcp-other-new" value="allow" <?php checked( 'allow', $settings['new'] ); ?>>
                <?php esc_html_e( 'Allow it (default)', 'mcp-abilities' ); ?>
            </label>
            <label>
                <input type="radio" name="mcp-other-new" value="off" <?php checked( 'off', $settings['new'] ); ?>>
                <?php esc_html_e( 'Keep it off until I turn it on here', 'mcp-abilities' ); ?>
            </label>
            <p class="mcp-other__intro"><?php esc_html_e( 'New means not yet shown on this page. With "keep it off", a new tool, server or address is refused to AI assistants until you open this page and switch it on.', 'mcp-abilities' ); ?></p>
        </fieldset>
    </section>

</div>
