<?php
/**
 * Admin Extensions Overview
 *
 * Lists each installed extension as a card linking to its own subpage, where the
 * user finds the usage guide + ability toggles.
 *
 * Variables available:
 *   $extensions – array[]  (each: id, label, description, icon, groups, option_key)
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
                <h1><?php esc_html_e( 'VGC AI Nexus – Extensions', 'mcp-abilities' ); ?></h1>
                <p><?php esc_html_e( 'Each installed add-on has its own page with a usage guide and per-tool toggles.', 'mcp-abilities' ); ?></p>
            </div>
        </div>
    </div>

    <?php $installable = $installable ?? []; ?>

    <?php if ( empty( $extensions ) && empty( $installable ) ) : ?>

    <div class="mcp-empty-state">
        <span class="dashicons dashicons-admin-plugins mcp-empty-state__icon"></span>
        <h2><?php esc_html_e( 'No extensions installed', 'mcp-abilities' ); ?></h2>
        <p><?php esc_html_e( 'Install a compatible add-on (e.g. VGC AI Nexus for WooCommerce) to manage its tools here.', 'mcp-abilities' ); ?></p>
    </div>

    <?php endif; ?>

    <?php if ( ! empty( $extensions ) ) : ?>
    <h2 class="mcp-section-title"><?php esc_html_e( 'Installed', 'mcp-abilities' ); ?></h2>
    <div class="mcp-ext-cards">
    <?php foreach ( $extensions as $extension ) :
        $ext_id     = sanitize_key( $extension['id'] );
        $ext_groups = $extension['groups'] ?? [];
        $tool_total = 0;
        $ab_total   = 0;
        foreach ( $ext_groups as $g ) {
            $tool_total += count( $g->get_mcp_abilities() );
            $ab_total   += count( $g->get_abilities() );
        }
        $url = \MCP_Abilities\Admin\Settings_Page::subpage_url( $ext_id );
    ?>
        <a class="mcp-ext-card" href="<?php echo esc_url( $url ); ?>">
            <span class="mcp-ext-card__icon dashicons <?php echo esc_attr( $extension['icon'] ?? 'dashicons-admin-plugins' ); ?>"></span>
            <div class="mcp-ext-card__body">
                <h2 class="mcp-ext-card__label"><?php echo esc_html( $extension['label'] ); ?></h2>
                <p class="mcp-ext-card__desc"><?php echo esc_html( $extension['description'] ?? '' ); ?></p>
                <div class="mcp-ext-card__meta">
                    <span class="mcp-badge"><?php echo (int) count( $ext_groups ); ?> <?php esc_html_e( 'groups', 'mcp-abilities' ); ?></span>
                    <span class="mcp-badge"><?php echo (int) $ab_total; ?> <?php esc_html_e( 'abilities', 'mcp-abilities' ); ?></span>
                </div>
            </div>
            <span class="mcp-ext-card__go">
                <?php esc_html_e( 'Open guide & settings', 'mcp-abilities' ); ?>
                <span class="dashicons dashicons-arrow-right-alt2"></span>
            </span>
        </a>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $installable ) ) : ?>
    <h2 class="mcp-section-title"><?php esc_html_e( 'Available to install', 'mcp-abilities' ); ?></h2>
    <p class="mcp-section-sub"><?php esc_html_e( 'One-click install of approved VGC AI Nexus extensions from the official channel.', 'mcp-abilities' ); ?></p>
    <div class="mcp-ext-cards mcp-ext-cards--available">
    <?php foreach ( $installable as $ext ) :
        $slug    = esc_attr( $ext['slug'] );
        $is_inst = 'inactive' === ( $ext['state'] ?? '' );
    ?>
        <div class="mcp-ext-card mcp-ext-card--install" data-slug="<?php echo $slug; ?>">
            <span class="mcp-ext-card__icon dashicons <?php echo esc_attr( $ext['icon'] ?? 'dashicons-admin-plugins' ); ?>"></span>
            <div class="mcp-ext-card__body">
                <h2 class="mcp-ext-card__label"><?php echo esc_html( $ext['name'] ?? $slug ); ?></h2>
                <p class="mcp-ext-card__desc"><?php echo esc_html( $ext['description'] ?? '' ); ?></p>
                <div class="mcp-ext-card__meta">
                    <?php if ( ! empty( $ext['version'] ) ) : ?>
                        <span class="mcp-badge">v<?php echo esc_html( $ext['version'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( ! empty( $ext['requires'] ) ) : ?>
                        <span class="mcp-badge mcp-badge--req"><?php printf( esc_html__( 'needs %s', 'mcp-abilities' ), esc_html( $ext['requires'] ) ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mcp-ext-card__install">
                <button class="mcp-btn mcp-btn--primary mcp-install-btn" data-slug="<?php echo $slug; ?>">
                    <span class="dashicons <?php echo $is_inst ? 'dashicons-yes' : 'dashicons-download'; ?>"></span>
                    <?php echo $is_inst ? esc_html__( 'Activate', 'mcp-abilities' ) : esc_html__( 'Install', 'mcp-abilities' ); ?>
                </button>
                <span class="mcp-install-status"></span>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div><!-- /.mcp-wrap -->
