<?php
/**
 * Reusable group card: warning + header (toggle/expand) + usage guide + ability table.
 *
 * Expects in scope:
 *   $group – MCP_Abilities\Ability_Group
 *
 * Used by both the core Abilities page (admin/views/settings-page.php) and the
 * per-extension subpages (admin/views/extension-subpage.php), so the markup and
 * the data-* hooks consumed by admin.js stay identical.
 */

defined( 'ABSPATH' ) || exit;

$g_slug      = $group->get_slug();
$g_abilities = count( $group->get_abilities() );
$g_tools     = count( $group->get_mcp_abilities() );
$g_locked    = method_exists( $group, 'is_locked' ) && $group->is_locked();
$g_guide     = method_exists( $group, 'get_guide' ) ? $group->get_guide() : '';
$g_examples  = method_exists( $group, 'get_examples' ) ? $group->get_examples() : [];
?>
<div class="mcp-group" data-slug="<?php echo esc_attr( $g_slug ); ?>">

    <?php if ( $group->get_security_warning() ) : ?>
    <div class="mcp-group__warning<?php echo $group->is_enabled() ? '' : ' mcp-group__warning--hidden'; ?>"
         data-group="<?php echo esc_attr( $g_slug ); ?>">
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
            <?php if ( $g_locked ) : ?>
                <span class="mcp-badge mcp-badge--locked" title="<?php esc_attr_e( 'Disabled in wp-config.php', 'mcp-abilities' ); ?>">
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
                    data-group="<?php echo esc_attr( $g_slug ); ?>"
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

        <?php if ( $g_guide || ! empty( $g_examples ) ) : ?>
        <div class="mcp-usage">
            <?php if ( $g_guide ) : ?>
            <div class="mcp-usage__lead">
                <span class="dashicons dashicons-lightbulb"></span>
                <p><?php echo wp_kses( $g_guide, [ 'strong' => [], 'em' => [], 'code' => [] ] ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $g_examples ) ) : ?>
            <div class="mcp-usage__examples">
                <span class="mcp-usage__examples-title"><?php esc_html_e( 'Try asking Claude:', 'mcp-abilities' ); ?></span>
                <ul>
                    <?php foreach ( $g_examples as $example ) : ?>
                    <li><span class="dashicons dashicons-format-chat"></span><?php echo esc_html( $example ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

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
                                data-group="<?php echo esc_attr( $g_slug ); ?>"
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
