# Extending VGC AI Nexus

VGC AI Nexus is designed to be extended. Add-ons register their own ability groups and tools without modifying the core plugin.

---

## Official Extensions

| Extension | Description |
|---|---|
| [VGC AI Nexus for WooCommerce](https://github.com/vgc-ltd-wp/mcp-abilities-woocommerce) | Product, order, customer and coupon management |

---

## Building Your Own Extension

An extension is a standard WordPress plugin that hooks into VGC AI Nexus after it loads.

### 1. Wait for the core plugin

```php
add_action( 'mcp_abilities_loaded', function ( \MCP_Abilities\Plugin $core ): void {
    // Your extension code here.
} );
```

### 2. Register with the Extensions admin page

```php
add_filter( 'mcp_abilities_extensions', function ( array $extensions ): array {
    $extensions[] = [
        'id'          => 'my-extension',
        'label'       => 'My Extension',
        'description' => 'What this extension does.',
        'icon'        => 'dashicons-admin-generic',
        'groups'      => my_extension_get_groups(), // Ability_Group[]
        'option_key'  => 'my_extension_settings',
        'badge'       => 'Beta', // optional (2.48.1): a bubble after the name
    ];
    return $extensions;
} );
```

`badge` (optional, since 2.48.1) is a short tag shown as a bubble right after the extension's name on AI Nexus → Extensions and on its own page, in the style of the tags under the description: plain text, one line, at most 20 characters. A catalog entry (`extensions.json`) may carry a `badge` too; it shows on the "Available to install" card.

Since 2.39.0 core's `update_option` tool refuses the `option_key` every extension registers here, so an AI cannot switch an extension's disabled tools back on. Any other option that holds settings, definitions or credentials needs a name core already protects (see [security.md](security.md#options-protection)).

### 3. Register a category

```php
add_action( 'wp_abilities_api_categories_init', function (): void {
    wp_register_ability_category( 'my-extension', [
        'label'       => 'My Extension',
        'description' => 'My extension tools.',
    ] );
} );
```

### 4. Register abilities with the WP Abilities API

```php
add_action( 'wp_abilities_api_init', function () use ( $abilities ): void {
    foreach ( $abilities as $ability ) {
        $name     = 'my-extension/' . $ability->get_key();
        $schema   = $ability->get_input_schema();
        unset( $schema['type'] ); // Required for WP REST compatibility.

        wp_register_ability( $name, [
            'category'            => 'my-extension',
            'label'               => $ability->get_label(),
            'description'         => $ability->get_description(),
            'input_schema'        => $schema,
            'permission_callback' => fn() => $ability->current_user_can(),
            'execute_callback'    => fn( $p = [] ) => $ability->execute( (array) $p ),
            'meta'                => [ 'mcp' => [ 'public' => true ] ],
        ] );
    }
} );
```

Keep `execute_callback` a closure over the `$ability` object and return its
`{success:false, error:"..."}` arrays as they are. That closure is how AI Nexus
core (2.23.2+) recognises the ability: when another plugin's copy of the MCP
adapter serves the site, core hands its error arrays over as `WP_Error`, so the
error text still reaches the AI (adapter 0.1.0 would answer "An error occurred
while executing the tool.").

### 5. Inject tools into the MCP server

```php
add_filter( 'mcp_adapter_default_server_config', function ( array $config ) use ( $abilities ): array {
    foreach ( $abilities as $ability ) {
        $name   = str_replace( '/', '-', 'my-extension/' . $ability->get_key() );
        $schema = $ability->get_input_schema();

        $tool = \WP\MCP\Domain\Tools\McpTool::fromArray( [
            'name'        => $name,
            'description' => $ability->get_description(),
            'inputSchema' => $schema,
            'handler'     => fn( $p = [] ) => $ability->execute( (array) $p ),
            'permission'  => fn() => $ability->current_user_can(),
        ] );

        if ( ! $tool instanceof \WP_Error ) {
            $config['tools'][] = $tool;
        }
    }
    return $config;
} );
```

Core (2.36.0+) shows a user only the tools whose `permission` callback lets them in, so that callback must answer without the call's arguments. Keep it `$ability->current_user_can()`; an ability that needs more than one capability overrides that method.

An ability that fetches a URL the caller gave must not call `wp_remote_get()` or `download_url()` itself. `\MCP_Abilities\Safe_Fetch::download( $url, $max_bytes, $timeout )` returns a temporary file or a `WP_Error`, with the SSRF rules in [security.md](security.md#upload-security-ssrf-prevention).

---

## Ability Group Structure

```
my-extension/
  abilities/
    my-feature/
      class-group-my-feature.php     ← extends Ability_Group
      class-ability-my-thing.php     ← extends Ability (one or more classes)
  my-extension.php                   ← plugin bootstrap
```

### Example group

```php
namespace MCP_Abilities\Groups;

class My_Feature_Group extends \MCP_Abilities\Ability_Group {

    protected function define_meta(): void {
        $this->slug        = 'my-feature';
        $this->label       = 'My Feature';
        $this->description = 'What this group does.';
        $this->icon        = 'dashicons-admin-generic';
    }
}
```

Files matching `class-ability-*.php` in the group's directory are auto-discovered. Each file should contain one class named `{PascalCase}_Ability` matching the filename.

### Example ability

```php
namespace MCP_Abilities\Abilities;

class My_Thing_Ability extends \MCP_Abilities\Ability {

    protected function define_meta(): void {
        $this->key          = 'my_thing';
        $this->label        = 'My Thing';
        $this->description  = 'Does my thing.';
        $this->required_cap = 'manage_options';
        $this->input_schema = [
            'type'       => 'object',
            'properties' => [
                'name' => [ 'type' => 'string', 'description' => 'The thing name.' ],
            ],
            'required' => [ 'name' ],
        ];
    }

    public function execute( array $params ): array {
        $name = sanitize_text_field( $params['name'] ?? '' );
        if ( '' === $name ) {
            return $this->error( 'Name is required.' );
        }
        // Do the thing...
        return $this->success( "Did the thing: {$name}" );
    }
}
```

### Input validation (core 2.34.0+)

When your group is served as one consolidated tool, core's dispatcher checks each call against the selected ability's `input_schema` before `execute()`: required parameters and the declared **types** (also of array items and object properties). Declare types that match what `execute()` accepts — an `integer` parameter that also takes a slug must be `[ 'integer', 'string' ]`. **Enums are enforced for extensions too (2.52.0; before, only for core's own tools):** a value outside a parameter's `enum` is refused with the valid values, before `execute()`. So `enum` must be the complete list of values your code handles. A list that only names common values of a set the site defines (a shop's own order statuses, statuses or types other plugins add) is not an enum: write it as `examples` and check the value in your tool against the site, refusing an unknown one with the valid values. Never replace an unknown value with a default: an AI cannot see your code and reports the default's result as the answer to what it asked. Filters: `mcp_abilities_open_enum` (bool, parameter name, Ability) treats one parameter's enum as open; `mcp_abilities_validate_enums` (bool, Ability) turns enums off for an ability; `mcp_abilities_validate_input` skips validation for it. Abilities you execute through your own `execute_callback` are not validated by core; WordPress validates the Abilities API route itself. See [security.md](security.md#input-validation-since-2340).

### Ability return helpers

| Method | Use when |
|---|---|
| `$this->success( 'message' )` | Simple confirmation with no data |
| `$this->error( 'message' )` | Validation or runtime failure |
| `$this->json_result( array $data )` | Returning structured data |

Report a failure the AI should read with `$this->error()` or a `WP_Error`: both reach it unchanged. Since core 2.37.0 an uncaught exception or TypeError does not: its text can carry server paths, so it goes to the PHP error log and the AI is told only that an unexpected error happened. Catch what you expect and turn it into `$this->error()`. WooCommerce's `WC_Data_Exception` is passed on as it is; the filter `mcp_adapter_tool_exception_message` does the same for another class. See [security.md](security.md#when-a-tool-fails-since-2370).
