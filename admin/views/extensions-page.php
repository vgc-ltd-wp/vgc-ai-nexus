<?php
/**
 * Admin Extensions Page View
 *
 * Variables available:
 *   $extensions – array[]  (each has: id, label, description, icon, groups, option_key)
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
                <p><?php esc_html_e( 'Enable or disable tools provided by installed add-ons.', 'mcp-abilities' ); ?></p>
            </div>
        </div>
    </div>

    <?php if ( empty( $extensions ) ) : ?>

    <div class="mcp-empty-state">
        <span class="dashicons dashicons-admin-plugins mcp-empty-state__icon"></span>
        <h2><?php esc_html_e( 'No extensions installed', 'mcp-abilities' ); ?></h2>
        <p><?php esc_html_e( 'Install a compatible add-on (e.g. VGC AI Nexus for WooCommerce) to manage its tools here.', 'mcp-abilities' ); ?></p>
    </div>

    <?php else : ?>

    <div class="mcp-extensions" id="mcp-extensions-container">
    <?php foreach ( $extensions as $extension ) :
        $ext_id     = esc_attr( $extension['id'] );
        $option_key = esc_attr( $extension['option_key'] ?? $ext_id );
        $ext_groups = $extension['groups'] ?? [];
    ?>

        <div class="mcp-extension" data-extension-id="<?php echo $ext_id; ?>" data-option-key="<?php echo $option_key; ?>">

            <!-- Extension header -->
            <div class="mcp-extension__header">
                <span class="mcp-extension__icon dashicons <?php echo esc_attr( $extension['icon'] ?? 'dashicons-admin-plugins' ); ?>"></span>
                <div class="mcp-extension__meta">
                    <h2 class="mcp-extension__label"><?php echo esc_html( $extension['label'] ); ?></h2>
                    <p class="mcp-extension__desc"><?php echo esc_html( $extension['description'] ?? '' ); ?></p>
                </div>
                <div class="mcp-extension__actions">
                    <span id="mcp-ext-status-<?php echo $ext_id; ?>" class="mcp-save-status"></span>
                    <button class="mcp-btn mcp-btn--primary mcp-ext-save-btn"
                            data-extension="<?php echo $ext_id; ?>">
                        <span class="dashicons dashicons-saved"></span>
                        <?php esc_html_e( 'Save', 'mcp-abilities' ); ?>
                    </button>
                </div>
            </div>

            <!-- Groups (same structure as the main abilities page) -->
            <?php if ( empty( $ext_groups ) ) : ?>
            <p class="mcp-extension__no-groups">
                <?php esc_html_e( 'No ability groups found in this extension.', 'mcp-abilities' ); ?>
            </p>
            <?php else : ?>
            <div class="mcp-groups mcp-extension__groups">
            <?php foreach ( $ext_groups as $group ) : ?>

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
                            <span class="mcp-badge">
                                <?php echo count( $group->get_abilities() ); ?>
                                <?php esc_html_e( 'tools', 'mcp-abilities' ); ?>
                            </span>
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
            </div><!-- /.mcp-groups -->
            <?php endif; ?>

        </div><!-- /.mcp-extension -->

    <?php endforeach; ?>
    </div><!-- /#mcp-extensions-container -->

    <?php endif; ?>

</div><!-- /.mcp-wrap -->
