/* global mcpAbilities, jQuery */
( function ( $ ) {
    'use strict';

    // ── State ───────────────────────────────────────────────────────────────
    const state = { groups: {}, dirty: false };

    // ── Tabs ────────────────────────────────────────────────────────────────

    $( '.mcp-tab' ).on( 'click', function () {
        const tab = $( this ).data( 'tab' );
        $( '.mcp-tab' ).removeClass( 'mcp-tab--active' );
        $( this ).addClass( 'mcp-tab--active' );
        $( '.mcp-tab-panel' ).addClass( 'mcp-tab-panel--hidden' );
        $( '#mcp-tab-' + tab ).removeClass( 'mcp-tab-panel--hidden' );
    } );

    // ── Group expand/collapse ────────────────────────────────────────────────

    $( document ).on( 'click', '.mcp-group__header', function ( e ) {
        // Don't expand when clicking the toggle switch itself
        if ( $( e.target ).closest( '.mcp-toggle, .mcp-group__expand' ).length &&
             ! $( e.target ).closest( '.mcp-group__expand' ).length ) {
            return;
        }
        const $group = $( this ).closest( '.mcp-group' );
        const $body  = $group.find( '.mcp-group__body' );
        const $btn   = $group.find( '.mcp-group__expand' );
        $body.toggleClass( 'is-open' );
        $btn.toggleClass( 'is-open' );
        $btn.attr( 'aria-expanded', $body.hasClass( 'is-open' ) );
    } );

    // ── Group toggle ─────────────────────────────────────────────────────────

    $( document ).on( 'change', '.mcp-group-toggle', function () {
        const slug    = $( this ).data( 'group' );
        const enabled = $( this ).is( ':checked' );
        const $group  = $( this ).closest( '.mcp-group' );

        $group.find( '.mcp-group__body' ).toggleClass( 'mcp-group__body--disabled', ! enabled );
        $group.find( '.mcp-group__warning' ).toggleClass( 'mcp-group__warning--hidden', ! enabled );

        initGroup( slug );
        state.groups[ slug ].enabled = enabled;
        markDirty();
    } );

    // ── Ability toggle ───────────────────────────────────────────────────────

    $( document ).on( 'change', '.mcp-ability-toggle', function () {
        const slug    = $( this ).data( 'group' );
        const key     = $( this ).data( 'key' );
        const enabled = $( this ).is( ':checked' );

        initGroup( slug );
        if ( ! state.groups[ slug ].abilities ) state.groups[ slug ].abilities = {};
        state.groups[ slug ].abilities[ key ] = enabled;
        markDirty();
    } );

    function initGroup( slug ) {
        if ( ! state.groups[ slug ] ) state.groups[ slug ] = {};
    }

    // ── Save (main abilities page) ───────────────────────────────────────────

    $( '#mcp-save-btn' ).on( 'click', function () {
        const settings    = collectAllGroupSettings( $( '#mcp-groups-container' ) );
        const consolidate = $( '#mcp-consolidate-toggle' ).is( ':checked' ) ? 1 : 0;
        ajaxSave(
            'mcp_save_settings',
            { settings: JSON.stringify( settings ), consolidate: consolidate },
            $( this ),
            $( '#mcp-save-status' )
        );
    } );

    // Consolidation toggle marks the form dirty (counts refresh on reload).
    $( document ).on( 'change', '#mcp-consolidate-toggle', function () {
        markDirty();
        $( '#mcp-consolidate-hint' ).toggle( true );
    } );

    // ── Save (extensions page) ───────────────────────────────────────────────

    $( document ).on( 'click', '.mcp-ext-save-btn', function () {
        const extId    = $( this ).data( 'extension' );
        const $section = $( '.mcp-extension[data-extension-id="' + extId + '"]' );
        const $status  = $( '#mcp-ext-status-' + extId );
        const settings = collectAllGroupSettings( $section );

        ajaxSave(
            'mcp_save_extension_settings',
            { extension_id: extId, settings: JSON.stringify( settings ) },
            $( this ),
            $status
        );
    } );

    // ── Shared AJAX save helper ──────────────────────────────────────────────

    function ajaxSave( action, extraData, $btn, $status ) {
        $btn.prop( 'disabled', true );
        $status.removeClass( 'mcp-save-status--success mcp-save-status--error' ).text( '' );

        $.ajax( {
            url:    mcpAbilities.ajaxUrl,
            method: 'POST',
            data:   Object.assign( {
                action: action,
                nonce:  mcpAbilities.nonce,
            }, extraData ),
            success: function ( res ) {
                if ( res.success ) {
                    $status.addClass( 'mcp-save-status--success' ).text( mcpAbilities.i18n.saved );
                    markClean();
                } else {
                    $status.addClass( 'mcp-save-status--error' ).text( mcpAbilities.i18n.error );
                }
            },
            error: function () {
                $status.addClass( 'mcp-save-status--error' ).text( mcpAbilities.i18n.error );
            },
            complete: function () {
                $btn.prop( 'disabled', false ).html(
                    '<span class="dashicons dashicons-saved"></span> ' + mcpAbilities.i18n.save
                );
                setTimeout( function () { $status.text( '' ); }, 3000 );
            },
        } );
    }

    // ── Settings collector ───────────────────────────────────────────────────

    function collectAllGroupSettings( $container ) {
        const settings = {};
        $container.find( '.mcp-group' ).each( function () {
            const slug    = $( this ).data( 'slug' );
            const enabled = $( this ).find( '.mcp-group-toggle' ).is( ':checked' );
            settings[ slug ] = { enabled: enabled, abilities: {} };
            $( this ).find( '.mcp-ability-toggle' ).each( function () {
                settings[ slug ].abilities[ $( this ).data( 'key' ) ] = $( this ).is( ':checked' );
            } );
        } );
        return settings;
    }

    function markDirty() {
        state.dirty = true;
        $( '#mcp-save-btn' ).addClass( 'mcp-btn--dirty' );
    }

    function markClean() {
        state.dirty = false;
        $( '#mcp-save-btn' ).removeClass( 'mcp-btn--dirty' );
    }

    // ── Search ───────────────────────────────────────────────────────────────

    $( '#mcp-search' ).on( 'input', function () {
        const q = $( this ).val().toLowerCase().trim();

        $( '.mcp-group' ).each( function () {
            const $group    = $( this );
            let   groupMatch = false;

            $( '.mcp-ability-row', $group ).each( function () {
                const text  = $( this ).text().toLowerCase();
                const match = ! q || text.includes( q );
                $( this ).toggleClass( 'mcp-ability-row--hidden', ! match );
                if ( match ) groupMatch = true;
            } );

            $group.toggle( ! q || groupMatch );

            if ( q && groupMatch ) {
                $group.find( '.mcp-group__body' ).addClass( 'is-open' );
                $group.find( '.mcp-group__expand' ).addClass( 'is-open' );
            }
        } );
    } );

    // ── One-click extension install ──────────────────────────────────────────

    $( document ).on( 'click', '.mcp-install-btn', function () {
        const $btn    = $( this );
        const slug    = $btn.data( 'slug' );
        const $status = $btn.siblings( '.mcp-install-status' );
        const original = $btn.html();

        $btn.prop( 'disabled', true ).html( '<span class="dashicons dashicons-update mcp-spin"></span> ' + ( mcpAbilities.i18n.installing || 'Installing…' ) );
        $status.removeClass( 'is-error is-success' ).text( '' );

        $.ajax( {
            url:    mcpAbilities.ajaxUrl,
            method: 'POST',
            data:   { action: 'mcp_install_extension', nonce: mcpAbilities.nonce, slug: slug },
            success: function ( res ) {
                if ( res.success ) {
                    $status.addClass( 'is-success' ).text( ( res.data && res.data.message ) || 'Done.' );
                    setTimeout( function () { window.location.reload(); }, 900 );
                } else {
                    $btn.prop( 'disabled', false ).html( original );
                    $status.addClass( 'is-error' ).text( ( res.data && res.data.message ) || 'Install failed.' );
                }
            },
            error: function () {
                $btn.prop( 'disabled', false ).html( original );
                $status.addClass( 'is-error' ).text( 'Install failed.' );
            },
        } );
    } );

    // ── Connect: generate application password ───────────────────────────────

    $( '#mcp-generate-pw' ).on( 'click', function () {
        const $btn    = $( this );
        const $status = $( '#mcp-generate-status' );
        $btn.prop( 'disabled', true );
        $status.removeClass( 'is-error is-success' ).text( ( mcpAbilities.i18n.installing || 'Working…' ) );

        const userId = $( '#mcp-connect-user-select' ).val() || '';

        $.ajax( {
            url:    mcpAbilities.ajaxUrl,
            method: 'POST',
            data:   { action: 'mcp_generate_app_password', nonce: mcpAbilities.nonce, user_id: userId },
            success: function ( res ) {
                if ( res.success && res.data ) {
                    $( '#mcp-connect-user' ).text( res.data.username );
                    $( '#mcp-connect-pass' ).text( res.data.password );
                    $( '#mcp-connect-config' ).text( res.data.config );
                    $( '#mcp-connect-result' ).show();
                    $status.text( '' );
                    upsertConnectionRow( res.data );
                } else {
                    $status.addClass( 'is-error' ).text( ( res.data && res.data.message ) || 'Failed.' );
                }
            },
            error: function () {
                $status.addClass( 'is-error' ).text( 'Request failed.' );
            },
            complete: function () {
                $btn.prop( 'disabled', false );
            },
        } );
    } );

    // Insert (or refresh) a row in the "Existing connections" table after generating.
    function upsertConnectionRow( data ) {
        const $tbody = $( '#mcp-connections-table tbody' );
        if ( ! $tbody.length ) return;
        $tbody.find( '.mcp-conn-empty' ).hide();

        const login = data.username || '';
        const name  = data.display_name || login;
        let $row = $tbody.find( 'tr[data-user="' + data.user_id + '"]' );
        if ( ! $row.length ) {
            $row = $( '<tr>' ).attr( 'data-user', data.user_id );
            $row.html(
                '<td><strong class="js-name"></strong> <code class="js-login"></code></td>' +
                '<td class="js-created"></td>' +
                '<td>' + ( mcpAbilities.i18n.never || 'Never' ) + '</td>' +
                '<td><button class="mcp-btn mcp-btn--link mcp-revoke-conn" type="button">' +
                    ( mcpAbilities.i18n.revoke || 'Revoke' ) + '</button></td>'
            );
            $tbody.prepend( $row );
        }
        $row.attr( 'data-uuid', data.uuid || '' );
        $row.find( '.js-name' ).text( name );
        $row.find( '.js-login' ).text( login );
        $row.find( '.js-created' ).text( mcpAbilities.i18n.justNow || 'Just now' );
    }

    // ── Connect: revoke a stored connection ──────────────────────────────────

    $( document ).on( 'click', '.mcp-revoke-conn', function () {
        const $row    = $( this ).closest( 'tr' );
        const $status = $( '#mcp-revoke-status' );
        const userId  = $row.attr( 'data-user' );
        const uuid    = $row.attr( 'data-uuid' );
        if ( ! uuid || ! window.confirm( mcpAbilities.i18n.confirmRevoke || 'Revoke this connection? Claude will lose access on next reconnect.' ) ) {
            return;
        }
        const $btn = $( this ).prop( 'disabled', true );
        $status.removeClass( 'is-error is-success' ).text( mcpAbilities.i18n.working || 'Working…' );

        $.ajax( {
            url:    mcpAbilities.ajaxUrl,
            method: 'POST',
            data:   { action: 'mcp_revoke_connection', nonce: mcpAbilities.nonce, user_id: userId, uuid: uuid },
            success: function ( res ) {
                if ( res.success ) {
                    $row.remove();
                    const $tbody = $( '#mcp-connections-table tbody' );
                    if ( ! $tbody.find( 'tr[data-uuid]' ).length ) {
                        $tbody.find( '.mcp-conn-empty' ).show();
                    }
                    $status.addClass( 'is-success' ).text( ( res.data && res.data.message ) || 'Revoked.' );
                } else {
                    $btn.prop( 'disabled', false );
                    $status.addClass( 'is-error' ).text( ( res.data && res.data.message ) || 'Failed.' );
                }
            },
            error: function () {
                $btn.prop( 'disabled', false );
                $status.addClass( 'is-error' ).text( 'Request failed.' );
            },
        } );
    } );

    // ── Warn on unsaved changes ──────────────────────────────────────────────

    $( window ).on( 'beforeunload', function () {
        if ( state.dirty ) return 'You have unsaved changes.';
    } );

} )( jQuery );
