# Drupal SDC Validator

A CLI tool to validate [Drupal Single Directory Component (SDC)](https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components) `.component.yml` files for structure and schema compliance.

This validator helps you ensure your SDC metadata files follow the [Drupal core schema](https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata-full.schema.json), and reports any missing fields, invalid structures, or schema violations.

---

## 🚀 Features

- ✅ Validates `.component.yml` files recursively in any path
- ✅ Validates against Drupal core's `metadata-full.schema.json`
- ✅ Checks for name collisions between props and slots
- ✅ Validates non-string property types
- ✅ Supports class/interface type validation (like Drupal core)
- ✅ Optional `--enforce-schemas` flag for strict validation
- ✅ JSON Schema validation via [`justinrainbow/json-schema`](https://github.com/justinrainbow/json-schema)
- ✅ Works as a Composer-installed CLI tool (`vendor/bin/validate-sdc`)
- ✅ Caches remote schema for 24 hours for faster re-runs
- ✅ Error messages match Drupal core's ComponentValidator format  

---

## 🧩 Installation

```bash
composer require --dev kanopi/drupal-sdc-validator
```

---

## 📖 Usage

### Basic Usage

Validate components in one or more directories:

```bash
# Single directory
vendor/bin/validate-sdc web/themes/custom/your_theme/components

# Multiple directories
vendor/bin/validate-sdc web/themes/custom/theme1/components web/modules/custom/module1/components
```

### Enforce Schema Validation

Use the `--enforce-schemas` flag to require schema definitions (similar to Drupal modules):

```bash
vendor/bin/validate-sdc web/modules/custom/your_module/components --enforce-schemas
```

This will fail validation if any component is missing a `props` schema definition.

### Validation Modes

**Default (Lenient Mode)**
- Components **without** `props` are valid (matches Drupal theme behavior)
- Components **with** `props` are validated against the schema

**Strict Mode (`--enforce-schemas`)**
- **All** components must have `props` defined (matches Drupal module behavior)
- Use this for module components or when you want strict validation

### Example Output

```
web/themes/custom/mytheme/components/button/button.component.yml has validation errors:
  • The component "button" declared [variant] both as a prop and as a slot. Make sure to use different names.
  • [props.properties.size.type] The property type must be a string.

============================================================
✗ Validation failed!
  Total files checked: 15
  Files with errors: 1
```

---

## 🔍 Validation Rules

This validator implements the same validation logic as Drupal core's `ComponentValidator`:

### Name Collision Detection
Checks that props and slots don't share the same names.

### Non-String Type Validation
Ensures all property types are declared as strings (not integers, booleans, etc.).

### Class/Interface Type Support
Validates custom class/interface types exist in the codebase (e.g., `Drupal\Core\Url`).

### Schema Enforcement
With `--enforce-schemas`, requires all components to have prop schemas defined.

### Empty Properties Handling
Properly handles empty `properties: {}` declarations.

---

## 🧪 Integration with Your Project

### Add Composer Scripts

Once installed, add these scripts to your project's `composer.json` for easy access:

```json
{
  "scripts": {
    "validate-sdc": "vendor/bin/validate-sdc web/themes/custom",
    "validate-sdc-enforce": "vendor/bin/validate-sdc web/themes/custom --enforce-schemas"
  }
}
```

Then run:
```bash
# Lenient mode (themes)
composer validate-sdc

# Strict mode (modules)
composer validate-sdc-enforce
```

---

## 📝 License

MIT
