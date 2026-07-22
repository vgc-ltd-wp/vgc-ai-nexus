<?php
namespace MCP_Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the "how to use this site" guide served by the usage_guide ability.
 *
 * The guide is assembled per site rather than shipped static, because the
 * failures it exists to prevent are site-specific: the wrong post type slug,
 * an extension the caller didn't know was installed, a capability it assumed
 * was missing. Three sources merge here:
 *
 *   1. Built-in sections written below (orientation + anti-patterns).
 *   2. Live facts read from this WordPress install.
 *   3. Sections contributed by extensions via `mcp_abilities_guide_sections`.
 *
 * Site-specific conventions authored by the site owner are merged in too, from
 * the `mcp_abilities_conventions` option (edited in the admin).
 */
class Guide {

    const CONVENTIONS_OPTION = 'mcp_abilities_conventions';

    /** Post types that are never useful to a caller. */
    private const HIDDEN_TYPES = [
        'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request', 'wp_global_styles', 'wp_font_family',
        'wp_font_face', 'wp_navigation', 'patterns_ai_data',
    ];

    /**
     * The short orientation injected into the MCP initialize response, so it is
     * in context BEFORE the first tool call — the only channel that can reach a
     * caller before it forms a wrong theory. Deliberately ~10 lines: this text
     * is present in every conversation on this connector.
     */
    public static function orientation(): string {
        $lines = [
            'VGC AI Nexus — WordPress management tools for this site.',
            '',
            'Before improvising or working around an apparent limitation, call the "mcp-abilities-usage-guide" tool. It returns a short guide for THIS site: exact post type slugs, what each installed extension can do, and known anti-patterns.',
            '',
            'Rules that prevent the most common mistakes:',
            '- These tools run server-side (WP_Query / wp_insert_post). They work with EVERY registered post type, including ones NOT exposed via the WordPress REST API. A post type with rest_base/show_in_rest false is fully usable here.',
            '- Never move large content through your own output. Use the server-side copy tools (e.g. create_from_template, import_template with url=) instead of reading markup and pasting it back.',
            '- If a filter appears to have been ignored, check "ignored_parameters" in the response: parameters this site does not support are reported there, and the results are UNFILTERED with respect to them.',
            '- Never guess a post type, taxonomy or template slug. Call list_post_types (or usage_guide) — error messages also list the valid options.',
        ];
        return implode( "\n", $lines );
    }

    /**
     * All guide sections, keyed by topic slug.
     *
     * @return array<string,array{title:string,body:string}>
     */
    public static function sections(): array {
        $sections = [
            'overview'      => [ 'title' => 'How these tools are organised', 'body' => self::overview() ],
            'anti-patterns' => [ 'title' => 'Anti-patterns — read this before improvising', 'body' => self::anti_patterns() ],
            'site'          => [ 'title' => 'This site', 'body' => self::site_facts() ],
        ];

        $conventions = trim( (string) get_option( self::CONVENTIONS_OPTION, '' ) );
        if ( '' !== $conventions ) {
            $sections['conventions'] = [
                'title' => 'Conventions for this site (set by the site owner)',
                'body'  => $conventions,
            ];
        }

        /**
         * Lets an extension contribute its own guide section.
         *
         * Each entry: [ 'title' => string, 'body' => markdown string ], keyed by
         * a topic slug. Extensions should document their own tools AND their own
         * anti-patterns, so the guide stays accurate to what is installed.
         *
         * @param array<string,array{title:string,body:string}> $sections
         */
        $sections = (array) apply_filters( 'mcp_abilities_guide_sections', $sections );

        return $sections;
    }

    /**
     * A short block sized for pasting into a Claude Project's instructions (or
     * an assistant's memory).
     *
     * Deliberately contains ONLY durable facts — rules, post type slugs, which
     * extensions exist. No versions, counts, IDs or dates: those go stale the
     * moment they are memorised, and a confidently-stale fact is worse than no
     * fact. Volatile detail stays in the live guide, which is one call away.
     */
    public static function compact(): string {
        $out   = [];
        $out[] = '# VGC AI Nexus — operating rules for ' . get_bloginfo( 'name' );
        $out[] = '';
        $out[] = 'Guide version: ' . self::version() . '. Re-run the "usage_guide" tool if the site changes, or for anything not covered here.';
        $out[] = '';
        $out[] = '## Always';
        $out[] = '- These tools run server-side. EVERY registered post type is usable, including ones not exposed via the WordPress REST API. Never treat rest_base/show_in_rest as a limit.';
        $out[] = '- Never move large content through your own output. Use the server-side copy tools (create_from_template, import_template with url) instead of reading markup and pasting it back.';
        $out[] = '- If a response contains "ignored_parameters", those parameters did nothing and the results are unfiltered with respect to them. Fix the call rather than reasoning about the results.';
        $out[] = '- Never guess a post type, taxonomy, template or icon slug. list_post_types and the tools\' own error messages give the valid options.';
        $out[] = '- Filter by a custom taxonomy with taxonomy + terms together, not by inventing a parameter named after the taxonomy.';

        $builders = [];
        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            $builders[] = 'Elementor' . ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ' (Pro)' : '' );
        }
        if ( class_exists( 'FusionBuilder' ) ) {
            $builders[] = 'Avada / Fusion Builder';
        }

        $out[] = '';
        $out[] = '## This site';
        if ( $builders ) {
            $out[] = '- Page builder: ' . implode( ', ', $builders );
        }
        if ( class_exists( 'WooCommerce' ) ) {
            $out[] = '- WooCommerce is active. The AI Nexus tools are the woocommerce-* ones; WooCommerce also registers its own, smaller woocommerce/... set.';
        }
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $out[] = '- WPML is active: listing content without a language parameter returns the current language only.';
        }

        $rows = [];
        foreach ( self::post_type_rows( 18 ) as $row ) {
            $rows[] = '  - `' . $row['slug'] . '`' . ( $row['taxonomies'] ? ' — taxonomies: ' . implode( ', ', $row['taxonomies'] ) : '' );
        }
        if ( $rows ) {
            $out[] = '- Post type slugs on this site:';
            $out   = array_merge( $out, $rows );
        }

        $conventions = trim( (string) get_option( self::CONVENTIONS_OPTION, '' ) );
        if ( '' !== $conventions ) {
            $out[] = '';
            $out[] = '## House rules';
            $out[] = $conventions;
        }

        return implode( "\n", $out );
    }

    /** The same content shaped as a Claude skill file. */
    public static function skill(): string {
        $name = get_bloginfo( 'name' );
        return "---\nname: " . sanitize_text_field( $name ) . " — WordPress operations\n"
            . "description: How to work with " . sanitize_text_field( $name ) . " through VGC AI Nexus. Use whenever making changes to this site.\n---\n\n"
            . self::compact()
            . "\n\n## Going deeper\n\nCall the \"usage_guide\" tool for the full guide, including per-extension detail and the complete anti-pattern list. Pass a topic (see the topics list it returns) for one section.\n";
    }

    /**
     * Post types worth telling a caller about.
     *
     * @return array<int,array{slug:string,label:string,taxonomies:string[]}>
     */
    private static function post_type_rows( int $limit = 40 ): array {
        $rows = [];
        foreach ( get_post_types( [], 'objects' ) as $type ) {
            if ( in_array( $type->name, self::HIDDEN_TYPES, true ) ) {
                continue;
            }
            if ( ! $type->public && ! $type->show_ui ) {
                continue;
            }
            $rows[] = [
                'slug'       => $type->name,
                'label'      => $type->label,
                'taxonomies' => array_values( array_diff( get_object_taxonomies( $type->name ), [ 'post_format' ] ) ),
            ];
            if ( count( $rows ) >= $limit ) {
                break;
            }
        }
        return $rows;
    }

    /** Stable-ish fingerprint so a cached/memorised copy can be checked. */
    public static function version(): string {
        $body = '';
        foreach ( self::sections() as $slug => $section ) {
            $body .= $slug . (string) ( $section['body'] ?? '' );
        }
        return MCP_ABILITIES_VERSION . '-' . substr( md5( $body ), 0, 8 );
    }

    // ── Built-in sections ────────────────────────────────────────────────────

    private static function overview(): string {
        return <<<MD
Most tools here are **group dispatchers**: one tool per group, with an `action`
parameter selecting the operation. For example `mcp-abilities-post-management`
takes `action: "list_posts" | "get_post" | "create_post" | "update_post" | ...`,
and each action has its own parameters (listed in the tool description).

Discovery, in the order that avoids guesswork:

- `mcp-abilities-options-management` → `action: "list_post_types"` — every post
  type on this site with its real slug and taxonomies. Use this before any call
  that takes a `post_type`.
- `mcp-abilities-taxonomy-management` → `action: "list_terms"` — term slugs/IDs
  for a taxonomy.
- `mcp-adapter-discover-abilities` — the full list of abilities exposed here.
- This guide (`usage_guide`) — per-topic; call with `topic` for a section.

Filtering content by a custom taxonomy uses `taxonomy` + `terms` together, e.g.
`{ post_type: "avada_portfolio", taxonomy: "portfolio_category", terms: ["automotive"] }`.
That pattern works on `list_posts` and on `list_content_seo`.

Writes are capability-checked against the connected WordPress user, and each
group can be disabled per site in WP Admin → AI Nexus → Abilities.
MD;
    }

    private static function anti_patterns(): string {
        return <<<MD
These are real failures observed in practice. Each one cost a session's worth of
wrong turns before the cause was found.

**Do not infer capability from REST exposure.** `list_post_types` reports
`rest_base` / `rest_exposed` for information only. These abilities do not use the
WordPress REST API to reach content — they run server-side. Post types such as
`avada_portfolio`, `fusion_template` and `tribe_events` are NOT REST-exposed and
are still fully readable and writable here. If a call returns the wrong content,
REST exposure is not the reason.

**Do not read large content just to write it back.** Fetching a 60 KB template in
chunks and re-emitting it wastes the majority of a turn and risks corrupting the
markup. Prefer the server-side copy paths:
- Avada: `create_from_template` (copies a Library template into a new page).
- Elementor: `import_template` with `url=` (the server fetches the file).
If you find yourself planning several chunked reads followed by a large write,
stop and look for a server-side equivalent first.

**Trust `ignored_parameters`, not appearances.** A result that looks correctly
filtered may not be. If the response contains `ignored_parameters`, those
parameters did nothing and the data is unfiltered with respect to them — fix the
call rather than reasoning about the results.

**Do not guess slugs.** Post types, taxonomies, template types and icon classes
are site-specific. Every relevant tool either lists them or names the valid
options in its error message. Guessing produces plausible-looking wrong results.

**Do not assume a missing feature.** If something seems impossible, check this
guide and `discover-abilities` first. Several "missing" capabilities in past
sessions already existed under a different tool than the one being tried.
MD;
    }

    private static function site_facts(): string {
        $out = [];

        $theme = wp_get_theme();
        $out[] = '- **Site:** ' . get_bloginfo( 'name' ) . ' (' . home_url() . ')';
        $out[] = '- **WordPress:** ' . get_bloginfo( 'version' ) . ' · **Locale:** ' . get_locale();
        $out[] = '- **Theme:** ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' );

        $builders = [];
        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            $builders[] = 'Elementor ' . ELEMENTOR_VERSION . ( defined( 'ELEMENTOR_PRO_VERSION' ) ? ' + Pro' : '' );
        }
        if ( class_exists( 'FusionBuilder' ) ) {
            $builders[] = 'Avada / Fusion Builder';
        }
        if ( $builders ) {
            $out[] = '- **Page builder:** ' . implode( ', ', $builders );
        }
        if ( class_exists( 'WooCommerce' ) ) {
            $out[] = '- **WooCommerce:** active';
        }
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $out[] = '- **WPML:** active (list/read abilities accept a `language` parameter)';
        }

        // Installed AI Nexus plugins and their versions.
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $vgc = [];
        foreach ( (array) get_plugins() as $file => $data ) {
            if ( 0 === strpos( $file, 'vgc-ai-nexus' ) && is_plugin_active( $file ) ) {
                $vgc[] = $data['Name'] . ' ' . $data['Version'];
            }
        }
        if ( $vgc ) {
            $out[] = '- **AI Nexus components:** ' . implode( ', ', $vgc );
        }

        // Which adapter is serving. Several plugins bundle the same library, and
        // a foreign one winning changes which features are available — reported
        // here so it is never something that has to be inferred.
        $out[] = '- **MCP adapter:** ' . Adapter_Status::summary();

        // The money data: real post type slugs with their taxonomies.
        $rows = [];
        foreach ( self::post_type_rows( 40 ) as $row ) {
            $rows[] = sprintf(
                '| `%s` | %s | %s |',
                $row['slug'],
                $row['label'],
                $row['taxonomies'] ? '`' . implode( '`, `', $row['taxonomies'] ) . '`' : '—'
            );
        }

        $body = implode( "\n", $out );
        if ( $rows ) {
            $body .= "\n\n**Post types on this site** — use these exact slugs:\n\n"
                . "| Slug | Label | Taxonomies |\n|---|---|---|\n"
                . implode( "\n", $rows );
        }

        return $body;
    }
}
