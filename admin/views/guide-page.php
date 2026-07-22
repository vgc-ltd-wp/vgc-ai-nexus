<?php
/**
 * Admin "Guide" page — house rules + the copy-paste block for Claude Projects.
 *
 * @var bool   $saved
 * @var string $conventions
 * @var string $compact
 */

defined( 'ABSPATH' ) || exit;

use MCP_Abilities\Adapter_Status;
?>
<div class="mcp-wrap">

    <div class="mcp-header">
        <div class="mcp-header__logo">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="32" height="32" rx="8" fill="#1a1a2e"/>
                <path d="M8 16L16 8L24 16L16 24L8 16Z" stroke="#6C63FF" stroke-width="2" fill="none"/>
                <circle cx="16" cy="16" r="3" fill="#6C63FF"/>
            </svg>
            <div>
                <h1><?php esc_html_e( 'Guide', 'mcp-abilities' ); ?></h1>
                <p><?php esc_html_e( 'What AI assistants are told about this site — and your own house rules.', 'mcp-abilities' ); ?></p>
            </div>
        </div>
    </div>

    <?php if ( $saved ) : ?>
        <div class="notice notice-success is-dismissible" style="margin:0 0 16px;">
            <p><?php esc_html_e( 'House rules saved. Assistants will pick them up on their next request.', 'mcp-abilities' ); ?></p>
        </div>
    <?php endif; ?>

    <div class="mcp-docs">

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'How this works', 'mcp-abilities' ); ?></h3>
            <p>
                <?php esc_html_e( 'Connected AI assistants can read a guide to this site instead of guessing: the real post type slugs, which extensions are installed and what they do, and the mistakes that have caused wrong results before. A short orientation is also sent the moment an assistant connects, pointing it at that guide.', 'mcp-abilities' ); ?>
            </p>
            <p class="mcp-section-sub">
                <?php esc_html_e( 'Anything you write below is merged into that guide. This is the part no built-in text can know — which template to use for which content, tone of voice, naming conventions, what to never touch.', 'mcp-abilities' ); ?>
            </p>
        </div>

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'House rules for this site', 'mcp-abilities' ); ?></h3>
            <form method="post">
                <?php wp_nonce_field( 'mcp_save_guide', 'mcp_guide_nonce' ); ?>
                <p class="mcp-section-sub">
                    <?php esc_html_e( 'Plain text or Markdown. Write it as instructions to an assistant, e.g. "Events are the tribe_events post type, never plain pages" or "Case studies always use the Success Stories template".', 'mcp-abilities' ); ?>
                </p>
                <textarea name="mcp_conventions" rows="12" style="width:100%;font-family:monospace;"
                    placeholder="<?php esc_attr_e( "- Events are the tribe_events post type, never plain pages.&#10;- New landing pages start from the 'Campaign — Basic' template.&#10;- Never edit the header or footer templates without asking.", 'mcp-abilities' ); ?>"><?php echo esc_textarea( $conventions ); ?></textarea>
                <p>
                    <button type="submit" class="mcp-btn mcp-btn--primary">
                        <span class="dashicons dashicons-saved"></span>
                        <?php esc_html_e( 'Save house rules', 'mcp-abilities' ); ?>
                    </button>
                </p>
            </form>
        </div>

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'Give an assistant the short version', 'mcp-abilities' ); ?></h3>
            <p>
                <?php esc_html_e( 'Paste this into a Claude Project’s custom instructions so it applies to every conversation about this site. Project instructions are more dependable than relying on the assistant to remember.', 'mcp-abilities' ); ?>
            </p>
            <p class="mcp-section-sub">
                <?php esc_html_e( 'It contains only durable facts — rules and slugs, no version numbers — so it stays correct. Re-copy it when the site changes; assistants can also fetch it themselves at any time.', 'mcp-abilities' ); ?>
            </p>
            <pre class="mcp-code-block" style="max-height:340px;overflow:auto;"><code id="mcp-compact-guide"><?php echo esc_html( $compact ); ?></code></pre>
        </div>

        <div class="mcp-doc-section">
            <h3><?php esc_html_e( 'Connection status', 'mcp-abilities' ); ?></h3>
            <p class="mcp-section-sub"><?php echo esc_html( Adapter_Status::summary() ); ?></p>
        </div>

    </div>
</div>
