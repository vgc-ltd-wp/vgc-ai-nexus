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

    // Read-only mode saves on its own, immediately — it is not part of the
    // per-ability form, so it never waits for "Save Settings".
    $( document ).on( 'change', '#mcp-readonly-toggle', function () {
        const $box    = $( this );
        const on      = $box.is( ':checked' );
        const $status = $( '#mcp-readonly-status' );
        $box.prop( 'disabled', true );
        $status.removeClass( 'mcp-save-status--success mcp-save-status--error' ).text( mcpAbilities.i18n.saving );
        $.ajax( {
            url:    mcpAbilities.ajaxUrl,
            method: 'POST',
            data:   { action: 'mcp_set_read_only', nonce: mcpAbilities.nonce, on: on ? 1 : 0 },
            success: function ( res ) {
                if ( res.success ) {
                    $box.closest( '.mcp-readonly' ).toggleClass( 'is-on', !! res.data.on );
                    $status.addClass( 'mcp-save-status--success' ).text( mcpAbilities.i18n.saved );
                } else {
                    $box.prop( 'checked', ! on );
                    $status.addClass( 'mcp-save-status--error' ).text( ( res.data && res.data.message ) || mcpAbilities.i18n.error );
                }
            },
            error: function () {
                $box.prop( 'checked', ! on );
                $status.addClass( 'mcp-save-status--error' ).text( mcpAbilities.i18n.error );
            },
            complete: function () {
                $box.prop( 'disabled', false );
                setTimeout( function () { $status.text( '' ); }, 3000 );
            },
        } );
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

    // ── Connect: claude.ai (OAuth) — registration policy + disconnect ────────

    $( document ).on( 'change', '#mcp-oauth-policy', function () {
        const $sel    = $( this );
        const $status = $( '#mcp-oauth-policy-status' );
        $sel.prop( 'disabled', true );
        $status.removeClass( 'is-error is-success' ).text( mcpAbilities.i18n.saving || 'Saving…' );
        $.post( mcpAbilities.ajaxUrl, { action: 'mcp_oauth_policy', nonce: mcpAbilities.nonce, policy: $sel.val() } )
            .done( function ( res ) {
                $status.addClass( res.success ? 'is-success' : 'is-error' ).text( ( res.data && res.data.message ) || ( res.success ? 'Saved.' : 'Failed.' ) );
            } )
            .fail( function () { $status.addClass( 'is-error' ).text( 'Failed.' ); } )
            .always( function () { $sel.prop( 'disabled', false ); } );
    } );

    $( document ).on( 'click', '.mcp-oauth-revoke', function () {
        const $row    = $( this ).closest( 'tr' );
        const $status = $( '#mcp-oauth-status' );
        const client  = $row.attr( 'data-client' );
        if ( ! client || ! window.confirm( 'Disconnect this app? Claude loses access immediately and will have to connect and approve again.' ) ) {
            return;
        }
        const $btn = $( this ).prop( 'disabled', true );
        $status.removeClass( 'is-error is-success' ).text( mcpAbilities.i18n.working || 'Working…' );
        $.post( mcpAbilities.ajaxUrl, { action: 'mcp_oauth_revoke', nonce: mcpAbilities.nonce, client_id: client } )
            .done( function ( res ) {
                if ( res.success ) {
                    $row.remove();
                    const $tbody = $( '#mcp-oauth-table tbody' );
                    if ( ! $tbody.find( 'tr[data-client]' ).length ) {
                        $tbody.find( '.mcp-conn-empty' ).show();
                    }
                    $status.addClass( 'is-success' ).text( ( res.data && res.data.message ) || 'Disconnected.' );
                } else {
                    $btn.prop( 'disabled', false );
                    $status.addClass( 'is-error' ).text( ( res.data && res.data.message ) || 'Failed.' );
                }
            } )
            .fail( function () {
                $btn.prop( 'disabled', false );
                $status.addClass( 'is-error' ).text( 'Failed.' );
            } );
    } );

    // ── Connect: "Require admin approval for new connections" ───────────────
    // The switch never acts on its own: it opens an inline confirmation row.

    $( document ).on( 'change', '#mcp-approval-toggle', function () {
        const wantOn = this.checked;
        this.checked = ! wantOn; // stays as it was until confirmed
        $( '#mcp-approval-confirm-on' ).prop( 'hidden', ! wantOn );
        $( '#mcp-approval-confirm-off' ).prop( 'hidden', wantOn );
    } );

    $( document ).on( 'click', '.mcp-approval-cancel', function () {
        $( '.mcp-approval__confirm' ).prop( 'hidden', true );
        $( '#mcp-approval-existing' ).prop( 'checked', false );
    } );

    $( document ).on( 'click', '#mcp-approval-on-yes, #mcp-approval-off-yes', function () {
        const on      = 'mcp-approval-on-yes' === this.id;
        const $status = $( '#mcp-approval-status' );
        const $btn    = $( this ).prop( 'disabled', true );
        $status.removeClass( 'is-error is-success' ).text( mcpAbilities.i18n.working || 'Working…' );
        $.post( mcpAbilities.ajaxUrl, {
            action: 'mcp_approval_setting',
            nonce: mcpAbilities.nonce,
            on: on ? 1 : 0,
            existing_pending: $( '#mcp-approval-existing' ).is( ':checked' ) ? 1 : 0
        } )
            .done( function ( res ) {
                if ( res.success ) {
                    $status.addClass( 'is-success' ).text( ( res.data && res.data.message ) || 'Saved.' );
                    window.setTimeout( function () { window.location.reload(); }, 900 );
                } else {
                    $btn.prop( 'disabled', false );
                    $status.addClass( 'is-error' ).text( ( res.data && res.data.message ) || 'Failed.' );
                }
            } )
            .fail( function () {
                $btn.prop( 'disabled', false );
                $status.addClass( 'is-error' ).text( 'Failed.' );
            } );
    } );

    $( document ).on( 'click', '.mcp-approval-decide', function () {
        const $row    = $( this ).closest( 'tr' );
        const $status = $( '#mcp-approval-decide-status' );
        const $btns   = $row.find( 'button' ).prop( 'disabled', true );
        $status.removeClass( 'is-error is-success' ).text( mcpAbilities.i18n.working || 'Working…' );
        $.post( mcpAbilities.ajaxUrl, {
            action: 'mcp_approval_decide',
            nonce: mcpAbilities.nonce,
            key: $row.attr( 'data-key' ),
            decision: $( this ).attr( 'data-decision' )
        } )
            .done( function ( res ) {
                if ( res.success ) {
                    $status.addClass( 'is-success' ).text( ( res.data && res.data.message ) || 'Saved.' );
                    window.setTimeout( function () { window.location.reload(); }, 700 );
                } else {
                    $btns.prop( 'disabled', false );
                    $status.addClass( 'is-error' ).text( ( res.data && res.data.message ) || 'Failed.' );
                }
            } )
            .fail( function () {
                $btns.prop( 'disabled', false );
                $status.addClass( 'is-error' ).text( 'Failed.' );
            } );
    } );

    $( document ).on( 'click', '.mcp-approval-disconnect', function () {
        $( this ).prop( 'hidden', true ).siblings( '.mcp-approval__inline-confirm' ).prop( 'hidden', false );
    } );

    $( document ).on( 'click', '.mcp-approval-disconnect-no', function () {
        const $wrap = $( this ).closest( '.mcp-approval__inline-confirm' ).prop( 'hidden', true );
        $wrap.siblings( '.mcp-approval-disconnect' ).prop( 'hidden', false );
    } );

    $( document ).on( 'click', '.mcp-approval-disconnect-yes', function () {
        const $row    = $( this ).closest( 'tr' );
        const $status = $( '#mcp-approval-decide-status' );
        $( this ).prop( 'disabled', true );
        $.post( mcpAbilities.ajaxUrl, { action: 'mcp_oauth_revoke', nonce: mcpAbilities.nonce, client_id: $row.attr( 'data-client' ) } )
            .done( function ( res ) {
                $status.removeClass( 'is-error is-success' ).addClass( res.success ? 'is-success' : 'is-error' )
                    .text( ( res.data && res.data.message ) || ( res.success ? 'Disconnected.' : 'Failed.' ) );
                if ( res.success ) {
                    window.setTimeout( function () { window.location.reload(); }, 700 );
                }
            } )
            .fail( function () { $status.addClass( 'is-error' ).text( 'Failed.' ); } );
    } );

    // ── Connect: "who is connecting" for a claude.ai connection ─────────────
    // The name is set with .text() / .val() only: it is typed by people.

    $( document ).on( 'click', '.mcp-oauth-operator-edit', function () {
        const $person = $( this ).closest( '.mcp-oauth-user' );
        $( this ).prop( 'hidden', true );
        $person.find( '.mcp-oauth-operator-form' ).prop( 'hidden', false ).find( 'input' ).trigger( 'focus' );
    } );

    $( document ).on( 'click', '.mcp-oauth-operator-cancel', function () {
        const $person = $( this ).closest( '.mcp-oauth-user' );
        const current = $person.find( '.mcp-oauth-operator-name' ).text();
        $person.find( '.mcp-oauth-operator-form' ).prop( 'hidden', true ).find( 'input' ).val( current );
        $person.find( '.mcp-oauth-operator-edit' ).prop( 'hidden', false );
    } );

    $( document ).on( 'keydown', '.mcp-oauth-operator-form input', function ( e ) {
        if ( 'Enter' === e.key ) {
            e.preventDefault();
            $( this ).closest( '.mcp-oauth-operator-form' ).find( '.mcp-oauth-operator-save' ).trigger( 'click' );
        } else if ( 'Escape' === e.key ) {
            $( this ).closest( '.mcp-oauth-operator-form' ).find( '.mcp-oauth-operator-cancel' ).trigger( 'click' );
        }
    } );

    $( document ).on( 'click', '.mcp-oauth-operator-save', function () {
        const $person = $( this ).closest( '.mcp-oauth-user' );
        const $status = $( '#mcp-oauth-status' );
        const client  = $person.closest( 'tr' ).attr( 'data-client' );
        const userId  = $person.attr( 'data-user' );
        const $btn    = $( this ).prop( 'disabled', true );
        $status.removeClass( 'is-error is-success' ).text( mcpAbilities.i18n.working || 'Working…' );
        $.post( mcpAbilities.ajaxUrl, {
            action: 'mcp_oauth_operator',
            nonce: mcpAbilities.nonce,
            client_id: client,
            user_id: userId,
            name: $person.find( '.mcp-oauth-operator-form input' ).val()
        } )
            .done( function ( res ) {
                if ( res.success ) {
                    const name = ( res.data && res.data.name ) || '';
                    $person.find( '.mcp-oauth-operator-name' ).text( name ).prop( 'hidden', '' === name );
                    $person.find( '.mcp-oauth-operator-none' ).prop( 'hidden', '' !== name );
                    $person.find( '.mcp-oauth-operator-form' ).prop( 'hidden', true ).find( 'input' ).val( name );
                    $person.find( '.mcp-oauth-operator-edit' ).prop( 'hidden', false );
                    $status.addClass( 'is-success' ).text( ( res.data && res.data.message ) || 'Saved.' );
                } else {
                    $status.addClass( 'is-error' ).text( ( res.data && res.data.message ) || 'Failed.' );
                }
            } )
            .fail( function () {
                $status.addClass( 'is-error' ).text( 'Failed.' );
            } )
            .always( function () { $btn.prop( 'disabled', false ); } );
    } );

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
