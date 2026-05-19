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
        const settings = collectAllGroupSettings( $( '#mcp-groups-container' ) );
        ajaxSave(
            'mcp_save_settings',
            { settings: JSON.stringify( settings ) },
            $( this ),
            $( '#mcp-save-status' )
        );
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

    // ── Warn on unsaved changes ──────────────────────────────────────────────

    $( window ).on( 'beforeunload', function () {
        if ( state.dirty ) return 'You have unsaved changes.';
    } );

} )( jQuery );
