<?php
/**
 * Connections Page View
 *
 * Variables available (passed from Connections_Page::render()):
 *   $summary          array  — stats from Logger::get_summary()
 *   $result           array  — { rows, total, per_page, page, pages }
 *   $all_tools        array  — distinct tool names for the dropdown
 *   $filter_tool      string
 *   $filter_user      int
 *   $filter_status    string
 *   $filter_date_from string
 *   $filter_date_to   string
 *   $paged            int
 */

defined( 'ABSPATH' ) || exit;

// Build base URL for pagination / filter links.
$base_url = add_query_arg( [ 'page' => \MCP_Abilities\Admin\Connections_Page::PAGE_SLUG ], admin_url( 'admin.php' ) );

// Helper: human-readable time difference.
function mcp_time_ago( string $utc_datetime ): string {
    $diff = time() - strtotime( $utc_datetime . ' UTC' );
    if ( $diff < 60 )  return $diff . 's ago';
    if ( $diff < 3600 ) return floor( $diff / 60 ) . 'm ago';
    if ( $diff < 86400 ) return floor( $diff / 3600 ) . 'h ago';
    return floor( $diff / 86400 ) . 'd ago';
}
?>
<div class="mcp-wrap">

    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <div class="mcp-header">
        <div class="mcp-header__logo">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="32" height="32" rx="8" fill="#1a1a2e"/>
                <path d="M8 16L16 8L24 16L16 24L8 16Z" stroke="#6C63FF" stroke-width="2" fill="none"/>
                <circle cx="16" cy="16" r="3" fill="#6C63FF"/>
            </svg>
            <div>
                <h1><?php esc_html_e( 'AI Connections', 'mcp-abilities' ); ?></h1>
                <p><?php esc_html_e( 'Activity log for all AI agent tool calls', 'mcp-abilities' ); ?></p>
            </div>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=mcp-abilities' ) ); ?>" class="mcp-btn mcp-btn--ghost">
            <span class="dashicons dashicons-arrow-left-alt"></span>
            <?php esc_html_e( 'Back to Abilities', 'mcp-abilities' ); ?>
        </a>
    </div>

    <!-- ── Summary stats ──────────────────────────────────────────────────── -->
    <div class="mcp-stats-grid">

        <div class="mcp-stat-card">
            <span class="mcp-stat-card__icon dashicons dashicons-clock"></span>
            <div class="mcp-stat-card__body">
                <span class="mcp-stat-card__value"><?php echo esc_html( number_format_i18n( $summary['today_calls'] ) ); ?></span>
                <span class="mcp-stat-card__label"><?php esc_html_e( 'Calls today', 'mcp-abilities' ); ?></span>
            </div>
        </div>

        <div class="mcp-stat-card">
            <span class="mcp-stat-card__icon dashicons dashicons-calendar-alt"></span>
            <div class="mcp-stat-card__body">
                <span class="mcp-stat-card__value"><?php echo esc_html( number_format_i18n( $summary['week_calls'] ) ); ?></span>
                <span class="mcp-stat-card__label"><?php esc_html_e( 'Calls this week', 'mcp-abilities' ); ?></span>
            </div>
        </div>

        <div class="mcp-stat-card">
            <span class="mcp-stat-card__icon dashicons dashicons-chart-bar"></span>
            <div class="mcp-stat-card__body">
                <span class="mcp-stat-card__value"><?php echo esc_html( number_format_i18n( $summary['total_calls'] ) ); ?></span>
                <span class="mcp-stat-card__label"><?php esc_html_e( 'Total calls', 'mcp-abilities' ); ?></span>
            </div>
        </div>

        <div class="mcp-stat-card">
            <span class="mcp-stat-card__icon dashicons dashicons-admin-users"></span>
            <div class="mcp-stat-card__body">
                <span class="mcp-stat-card__value"><?php echo esc_html( number_format_i18n( $summary['unique_users_today'] ) ); ?></span>
                <span class="mcp-stat-card__label"><?php esc_html_e( 'Active users today', 'mcp-abilities' ); ?></span>
            </div>
        </div>

        <div class="mcp-stat-card">
            <span class="mcp-stat-card__icon dashicons dashicons-star-filled"></span>
            <div class="mcp-stat-card__body">
                <?php if ( $summary['top_tool'] ) : ?>
                    <span class="mcp-stat-card__value mcp-stat-card__value--tool"><?php echo esc_html( $summary['top_tool'] ); ?></span>
                    <span class="mcp-stat-card__label"><?php printf( esc_html__( 'Top tool this week (%d calls)', 'mcp-abilities' ), $summary['top_tool_count'] ); ?></span>
                <?php else : ?>
                    <span class="mcp-stat-card__value">—</span>
                    <span class="mcp-stat-card__label"><?php esc_html_e( 'Top tool this week', 'mcp-abilities' ); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="mcp-stat-card">
            <span class="mcp-stat-card__icon dashicons dashicons-desktop"></span>
            <div class="mcp-stat-card__body">
                <?php if ( ! empty( $summary['distinct_clients'] ) ) : ?>
                    <span class="mcp-stat-card__value"><?php echo esc_html( implode( ', ', $summary['distinct_clients'] ) ); ?></span>
                <?php else : ?>
                    <span class="mcp-stat-card__value">—</span>
                <?php endif; ?>
                <span class="mcp-stat-card__label"><?php esc_html_e( 'Active clients this week', 'mcp-abilities' ); ?></span>
            </div>
        </div>

    </div>

    <!-- ── Filter bar ─────────────────────────────────────────────────────── -->
    <form method="GET" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="mcp-filter-bar">
        <input type="hidden" name="page" value="<?php echo esc_attr( \MCP_Abilities\Admin\Connections_Page::PAGE_SLUG ); ?>">

        <!-- Tool dropdown -->
        <select name="filter_tool" class="mcp-filter-select">
            <option value=""><?php esc_html_e( 'All tools', 'mcp-abilities' ); ?></option>
            <?php foreach ( $all_tools as $tool_name ) : ?>
                <option value="<?php echo esc_attr( $tool_name ); ?>" <?php selected( $filter_tool, $tool_name ); ?>>
                    <?php echo esc_html( $tool_name ); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- Status dropdown -->
        <select name="filter_status" class="mcp-filter-select">
            <option value=""><?php esc_html_e( 'All statuses', 'mcp-abilities' ); ?></option>
            <option value="success" <?php selected( $filter_status, 'success' ); ?>><?php esc_html_e( 'Success', 'mcp-abilities' ); ?></option>
            <option value="error"   <?php selected( $filter_status, 'error' ); ?>><?php esc_html_e( 'Error', 'mcp-abilities' ); ?></option>
        </select>

        <!-- Date range -->
        <input type="date" name="filter_date_from" class="mcp-filter-date"
               value="<?php echo esc_attr( $filter_date_from ); ?>"
               placeholder="<?php esc_attr_e( 'From', 'mcp-abilities' ); ?>">
        <span class="mcp-filter-sep">–</span>
        <input type="date" name="filter_date_to" class="mcp-filter-date"
               value="<?php echo esc_attr( $filter_date_to ); ?>"
               placeholder="<?php esc_attr_e( 'To', 'mcp-abilities' ); ?>">

        <button type="submit" class="mcp-btn mcp-btn--primary">
            <span class="dashicons dashicons-filter"></span>
            <?php esc_html_e( 'Filter', 'mcp-abilities' ); ?>
        </button>

        <?php if ( $filter_tool || $filter_user || $filter_status || $filter_date_from || $filter_date_to ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" class="mcp-btn mcp-btn--ghost">
                <?php esc_html_e( 'Clear', 'mcp-abilities' ); ?>
            </a>
        <?php endif; ?>

        <span class="mcp-filter-count">
            <?php printf(
                esc_html( _n( '%s entry', '%s entries', $result['total'], 'mcp-abilities' ) ),
                '<strong>' . esc_html( number_format_i18n( $result['total'] ) ) . '</strong>'
            ); ?>
        </span>
    </form>

    <!-- ── Activity log table ─────────────────────────────────────────────── -->
    <div class="mcp-log-wrap">
        <?php if ( empty( $result['rows'] ) ) : ?>
            <div class="mcp-empty-state">
                <span class="mcp-empty-state__icon dashicons dashicons-list-view"></span>
                <h2><?php esc_html_e( 'No activity yet', 'mcp-abilities' ); ?></h2>
                <p><?php esc_html_e( 'Tool calls from AI agents will appear here automatically.', 'mcp-abilities' ); ?></p>
            </div>
        <?php else : ?>
            <table class="mcp-log-table">
                <thead>
                    <tr>
                        <th class="mcp-col-when"><?php esc_html_e( 'When', 'mcp-abilities' ); ?></th>
                        <th class="mcp-col-tool"><?php esc_html_e( 'Tool', 'mcp-abilities' ); ?></th>
                        <th class="mcp-col-user"><?php esc_html_e( 'User', 'mcp-abilities' ); ?></th>
                        <th class="mcp-col-pass"><?php esc_html_e( 'App Password', 'mcp-abilities' ); ?></th>
                        <th class="mcp-col-client"><?php esc_html_e( 'Client', 'mcp-abilities' ); ?></th>
                        <th class="mcp-col-ip"><?php esc_html_e( 'IP', 'mcp-abilities' ); ?></th>
                        <th class="mcp-col-status"><?php esc_html_e( 'Status', 'mcp-abilities' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $result['rows'] as $row ) :
                    $user     = $row->user_id ? get_userdata( (int) $row->user_id ) : null;
                    $is_error = 'error' === $row->status;
                ?>
                    <tr class="mcp-log-row <?php echo $is_error ? 'mcp-log-row--error' : ''; ?>">

                        <!-- When -->
                        <td class="mcp-col-when" title="<?php echo esc_attr( $row->logged_at . ' UTC' ); ?>">
                            <?php echo esc_html( mcp_time_ago( $row->logged_at ) ); ?>
                        </td>

                        <!-- Tool -->
                        <td class="mcp-col-tool">
                            <code class="mcp-tool-key"><?php echo esc_html( $row->tool ); ?></code>
                        </td>

                        <!-- User -->
                        <td class="mcp-col-user">
                            <?php if ( $user ) : ?>
                                <a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>" class="mcp-user-link">
                                    <?php echo esc_html( $user->display_name ); ?>
                                </a>
                                <span class="mcp-user-role"><?php
                                    $roles = (array) $user->roles;
                                    echo esc_html( ! empty( $roles ) ? ucfirst( $roles[0] ) : '' );
                                ?></span>
                            <?php else : ?>
                                <span class="mcp-muted"><?php esc_html_e( 'Unknown', 'mcp-abilities' ); ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- App Password -->
                        <td class="mcp-col-pass">
                            <?php if ( $row->app_pass_name ) : ?>
                                <span class="mcp-pass-name"><?php echo esc_html( $row->app_pass_name ); ?></span>
                                <?php if ( $user && $row->app_pass_uuid ) : ?>
                                    <a href="<?php echo esc_url( get_edit_user_link( $user->ID ) . '#application-passwords-section' ); ?>"
                                       class="mcp-pass-link" title="<?php esc_attr_e( 'Manage application passwords', 'mcp-abilities' ); ?>">
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="mcp-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Client -->
                        <td class="mcp-col-client">
                            <?php if ( $row->client_name ) : ?>
                                <span class="mcp-client-name"><?php echo esc_html( $row->client_name ); ?></span>
                                <?php if ( $row->client_version ) : ?>
                                    <span class="mcp-client-version"><?php echo esc_html( $row->client_version ); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="mcp-muted">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- IP -->
                        <td class="mcp-col-ip">
                            <span class="mcp-ip"><?php echo esc_html( $row->ip ?: '—' ); ?></span>
                        </td>

                        <!-- Status -->
                        <td class="mcp-col-status">
                            <?php if ( $is_error ) : ?>
                                <span class="mcp-status mcp-status--error" title="<?php echo esc_attr( $row->error_message ?: '' ); ?>">
                                    <span class="dashicons dashicons-no-alt"></span>
                                    <?php esc_html_e( 'Error', 'mcp-abilities' ); ?>
                                </span>
                            <?php else : ?>
                                <span class="mcp-status mcp-status--success">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php esc_html_e( 'OK', 'mcp-abilities' ); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- ── Pagination ──────────────────────────────────────────────── -->
            <?php if ( $result['pages'] > 1 ) :
                $filter_args = array_filter( [
                    'filter_tool'      => $filter_tool,
                    'filter_status'    => $filter_status,
                    'filter_date_from' => $filter_date_from,
                    'filter_date_to'   => $filter_date_to,
                ] );
            ?>
            <div class="mcp-pagination">
                <?php if ( $paged > 1 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $filter_args, [ 'paged' => $paged - 1 ] ), $base_url ) ); ?>"
                       class="mcp-btn mcp-btn--ghost mcp-pagination__btn">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </a>
                <?php endif; ?>

                <span class="mcp-pagination__info">
                    <?php printf(
                        esc_html__( 'Page %1$d of %2$d', 'mcp-abilities' ),
                        $result['page'],
                        $result['pages']
                    ); ?>
                </span>

                <?php if ( $paged < $result['pages'] ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( array_merge( $filter_args, [ 'paged' => $paged + 1 ] ), $base_url ) ); ?>"
                       class="mcp-btn mcp-btn--ghost mcp-pagination__btn">
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    </div><!-- /mcp-log-wrap -->

    <p class="mcp-log-retention-note">
        <?php printf(
            esc_html__( 'Log entries are automatically pruned after %d days.', 'mcp-abilities' ),
            \MCP_Abilities\Logger::LOG_RETENTION_DAYS
        ); ?>
    </p>

</div><!-- /mcp-wrap -->
