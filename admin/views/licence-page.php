<?php
/**
 * AI Nexus → Licence.
 *
 * @var array                       $state       Licence::state()
 * @var string                      $server_host The licence server's host.
 * @var array{0:string,1:string}|null $notice    The result of the last form.
 * @var string[]                    $extensions  Active extensions, with versions.
 */

defined( 'ABSPATH' ) || exit;

use MCP_Abilities\Licence;

$mcp_lic_status = (string) $state['status'];
$mcp_lic_labels = [
    'valid'   => [ 'success', __( 'Active', 'mcp-abilities' ) ],
    'expired' => [ 'error', __( 'Expired', 'mcp-abilities' ) ],
    'off'     => [ 'error', __( 'Not valid here', 'mcp-abilities' ) ],
    'pending' => [ 'held', __( 'Waiting for a check', 'mcp-abilities' ) ],
];
?>
<div class="mcp-wrap mcp-licence">

    <div class="mcp-header">
        <div class="mcp-header__logo">
            <?php echo \MCP_Abilities\Icons::logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup ?>
            <div>
                <h1><?php esc_html_e( 'Licence', 'mcp-abilities' ); ?></h1>
                <p><?php esc_html_e( 'AI Nexus itself is free. A licence key is for its extensions.', 'mcp-abilities' ); ?></p>
            </div>
        </div>
    </div>

    <?php if ( null !== $notice ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?>" style="margin:0 0 16px;">
            <p><?php echo esc_html( $notice[1] ); ?></p>
        </div>
    <?php endif; ?>

    <div class="mcp-docs">

        <div class="mcp-doc-section">
            <?php if ( 'none' === $mcp_lic_status ) : ?>
                <h3><?php esc_html_e( 'No key on this site', 'mcp-abilities' ); ?></h3>
                <p>
                    <?php
                    echo esc_html(
                        $extensions
                            ? __( 'Extensions will need a licence key in a later version. There is nothing to do yet: they keep working as they do today.', 'mcp-abilities' )
                            : __( 'No extension is active on this site, so it does not need a key.', 'mcp-abilities' )
                    );
                    ?>
                </p>
            <?php else : ?>
                <h3>
                    <?php esc_html_e( 'This site', 'mcp-abilities' ); ?>
                    <span class="mcp-status mcp-status--<?php echo esc_attr( $mcp_lic_labels[ $mcp_lic_status ][0] ); ?>"><?php echo esc_html( $mcp_lic_labels[ $mcp_lic_status ][1] ); ?></span>
                </h3>

                <?php if ( 'expired' === $mcp_lic_status ) : ?>
                    <p>
                        <?php
                        /* translators: %s: date */
                        echo esc_html( sprintf( __( 'The licence expired on %s. Extensions still work at full speed for now.', 'mcp-abilities' ), $state['expires'] ) );
                        ?>
                        <?php if ( '' !== $state['renew_url'] ) : ?>
                            <a href="<?php echo esc_url( $state['renew_url'] ); ?>"><?php esc_html_e( 'Renew', 'mcp-abilities' ); ?></a>
                        <?php endif; ?>
                    </p>
                <?php elseif ( 'off' === $mcp_lic_status ) : ?>
                    <p><?php echo esc_html( Licence::off_reason( $state ) . ' ' . __( 'Extensions still work for now.', 'mcp-abilities' ) ); ?></p>
                <?php elseif ( 'pending' === $mcp_lic_status ) : ?>
                    <p><?php esc_html_e( 'There is a key on this site, but no confirmed answer for this address yet. This happens when a site has moved. Nothing changes because of it.', 'mcp-abilities' ); ?></p>
                <?php endif; ?>

                <?php if ( $state['unreachable'] ) : ?>
                    <p>
                        <?php
                        /* translators: %d: number of days */
                        echo esc_html( sprintf( __( 'AI Nexus has not reached the licence server for %d days. Nothing changes because of that; it keeps trying.', 'mcp-abilities' ), $state['days_since_answer'] ) );
                        ?>
                    </p>
                <?php endif; ?>

                <table class="mcp-licence__facts">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Key', 'mcp-abilities' ); ?></th>
                        <td><code>…<?php echo esc_html( $state['hint'] ); ?></code></td>
                    </tr>
                    <?php if ( '' !== $state['kind'] ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Counts as', 'mcp-abilities' ); ?></th>
                            <td><?php echo esc_html( 'staging' === $state['kind'] ? __( 'A staging copy', 'mcp-abilities' ) : __( 'The live site', 'mcp-abilities' ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ( in_array( $mcp_lic_status, [ 'valid', 'expired' ], true ) ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Valid until', 'mcp-abilities' ); ?></th>
                            <td><?php echo esc_html( '' !== $state['expires'] ? $state['expires'] : __( 'No end date', 'mcp-abilities' ) ); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Last answer', 'mcp-abilities' ); ?></th>
                        <td>
                            <?php
                            echo esc_html(
                                $state['checked_at']
                                    /* translators: %s: a time span, such as "3 hours" */
                                    ? sprintf( __( '%s ago', 'mcp-abilities' ), human_time_diff( $state['checked_at'] ) )
                                    : __( 'None yet', 'mcp-abilities' )
                            );
                            ?>
                        </td>
                    </tr>
                    <?php if ( '' !== $state['last_error'] ) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Last try', 'mcp-abilities' ); ?></th>
                            <td class="mcp-licence__detail">
                                <?php esc_html_e( 'No answer. The last confirmed answer still counts.', 'mcp-abilities' ); ?>
                                <?php if ( '' !== $state['last_detail'] ) : ?>
                                    <br><code><?php echo esc_html( $state['last_detail'] ); ?></code>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </table>

                <div class="mcp-licence__actions">
                    <form method="post">
                        <?php wp_nonce_field( 'mcp_licence_check' ); ?>
                        <input type="hidden" name="mcp_licence_action" value="check">
                        <button type="submit" class="mcp-btn mcp-btn--ghost"><?php esc_html_e( 'Check now', 'mcp-abilities' ); ?></button>
                    </form>
                    <form method="post" class="mcp-licence__remove">
                        <?php wp_nonce_field( 'mcp_licence_remove' ); ?>
                        <input type="hidden" name="mcp_licence_action" value="remove">
                        <label>
                            <input type="checkbox" name="confirm" value="1">
                            <?php esc_html_e( 'Yes, remove it', 'mcp-abilities' ); ?>
                        </label>
                        <button type="submit" class="mcp-btn mcp-btn--ghost"><?php esc_html_e( 'Remove the key from this site', 'mcp-abilities' ); ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <div class="mcp-doc-section">
            <h3><?php echo esc_html( 'none' === $mcp_lic_status ? __( 'Enter a key', 'mcp-abilities' ) : __( 'Use another key', 'mcp-abilities' ) ); ?></h3>
            <form method="post" class="mcp-licence__enter" autocomplete="off">
                <?php wp_nonce_field( 'mcp_licence_activate' ); ?>
                <input type="hidden" name="mcp_licence_action" value="activate">
                <label for="mcp-licence-key" class="screen-reader-text"><?php esc_html_e( 'Licence key', 'mcp-abilities' ); ?></label>
                <input type="text" id="mcp-licence-key" name="licence_key" class="mcp-licence__key" maxlength="64" spellcheck="false" autocapitalize="characters" placeholder="VGCN-XXXXX-XXXXX-XXXXX-XXXXX">
                <button type="submit" class="mcp-btn mcp-btn--primary"><?php esc_html_e( 'Activate', 'mcp-abilities' ); ?></button>
            </form>
            <p class="mcp-section-sub"><?php esc_html_e( 'One key covers one live site and its staging copies, and unlocks every AI Nexus extension there.', 'mcp-abilities' ); ?></p>
        </div>

        <?php if ( $extensions ) : ?>
            <div class="mcp-doc-section">
                <h3><?php esc_html_e( 'Extensions a key covers on this site', 'mcp-abilities' ); ?></h3>
                <ul class="mcp-licence__list">
                    <?php foreach ( $extensions as $mcp_lic_extension ) : ?>
                        <li><?php echo esc_html( $mcp_lic_extension ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'What AI Nexus sends', 'mcp-abilities' ); ?></h3>
            <p>
                <?php
                /* translators: %s: host name of the licence server */
                echo esc_html( sprintf( __( 'Without a key, nothing. With a key, AI Nexus contacts %s once a day and when this page is opened. It sends the key, this site\'s address and environment type, a random id for this installation, and the WordPress, PHP and AI Nexus versions.', 'mcp-abilities' ), $server_host ) );
                ?>
            </p>
        </div>

    </div>
</div>
