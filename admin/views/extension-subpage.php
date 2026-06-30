<?php
/**
 * Single-extension subpage: usage guide + ability toggles for one add-on.
 *
 * Variables available:
 *   $extension – array (id, label, description, icon, groups, option_key,
 *                optionally: guide (string), examples (string[]), docs_url)
 */

defined( 'ABSPATH' ) || exit;

$ext_id     = esc_attr( $extension['id'] );
$option_key = esc_attr( $extension['option_key'] ?? $ext_id );
$ext_groups = $extension['groups'] ?? [];
$ext_guide  = $extension['guide'] ?? '';
$ext_examps = $extension['examples'] ?? [];

// Tool tally for this extension.
$ext_tool_total    = 0;
$ext_ability_total = 0;
foreach ( $ext_groups as $g ) {
    $ext_tool_total    += count( $g->get_mcp_abilities() );
    $ext_ability_total += count( $g->get_abilities() );
}
?>
<div class="mcp-wrap">

    <p class="mcp-backlink">
        <a href="<?php echo esc_url( \MCP_Abilities\Admin\Settings_Page::overview_url() ); ?>">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
            <?php esc_html_e( 'All Extensions', 'mcp-abilities' ); ?>
        </a>
    </p>

    <!-- Header -->
    <div class="mcp-header">
        <div class="mcp-header__logo">
            <span class="mcp-header__exticon dashicons <?php echo esc_attr( $extension['icon'] ?? 'dashicons-admin-plugins' ); ?>"></span>
            <div>
                <h1><?php echo esc_html( $extension['label'] ); ?></h1>
                <p><?php echo esc_html( $extension['description'] ?? '' ); ?></p>
            </div>
        </div>
    </div>

    <?php if ( $ext_guide || ! empty( $ext_examps ) ) : ?>
    <div class="mcp-intro">
        <?php if ( $ext_guide ) : ?>
        <p class="mcp-intro__lead"><?php echo wp_kses( $ext_guide, [ 'strong' => [], 'em' => [], 'code' => [], 'a' => [ 'href' => [], 'target' => [] ] ] ); ?></p>
        <?php endif; ?>
        <?php if ( ! empty( $ext_examps ) ) : ?>
        <ul class="mcp-intro__examples">
            <?php foreach ( $ext_examps as $example ) : ?>
            <li><span class="dashicons dashicons-format-chat"></span><?php echo esc_html( $example ); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="mcp-extension" data-extension-id="<?php echo $ext_id; ?>" data-option-key="<?php echo $option_key; ?>">

        <div class="mcp-toolbar">
            <button class="mcp-btn mcp-btn--primary mcp-ext-save-btn" data-extension="<?php echo $ext_id; ?>">
                <span class="dashicons dashicons-saved"></span>
                <?php esc_html_e( 'Save', 'mcp-abilities' ); ?>
            </button>
            <span id="mcp-ext-status-<?php echo $ext_id; ?>" class="mcp-save-status"></span>
            <span class="mcp-toolbar__count">
                <?php
                printf(
                    /* translators: 1: tools exposed, 2: underlying abilities */
                    esc_html__( '%1$d tools · %2$d abilities', 'mcp-abilities' ),
                    (int) $ext_tool_total,
                    (int) $ext_ability_total
                );
                ?>
            </span>
            <div class="mcp-toolbar__search">
                <span class="dashicons dashicons-search"></span>
                <input type="text" id="mcp-search" placeholder="<?php esc_attr_e( 'Filter abilities…', 'mcp-abilities' ); ?>">
            </div>
        </div>

        <?php if ( empty( $ext_groups ) ) : ?>
        <p class="mcp-extension__no-groups">
            <?php esc_html_e( 'No ability groups found in this extension.', 'mcp-abilities' ); ?>
        </p>
        <?php else : ?>
        <div class="mcp-groups mcp-extension__groups">
        <?php foreach ( $ext_groups as $group ) {
            include MCP_ABILITIES_DIR . 'admin/views/partials/group-card.php';
        } ?>
        </div>
        <?php endif; ?>

    </div><!-- /.mcp-extension -->

</div><!-- /.mcp-wrap -->
