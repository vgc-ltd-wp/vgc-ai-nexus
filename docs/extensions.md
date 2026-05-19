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
    ];
    return $extensions;
} );
```

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

### Ability return helpers

| Method | Use when |
|---|---|
| `$this->success( 'message' )` | Simple confirmation with no data |
| `$this->error( 'message' )` | Validation or runtime failure |
| `$this->json_result( array $data )` | Returning structured data |
