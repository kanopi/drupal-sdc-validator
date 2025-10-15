<?php

namespace Kanopi\DrupalSdcValidator;

use Symfony\Component\Yaml\Yaml;
use JsonSchema\Validator as JsonValidator;

/**
 * Validates Drupal Single Directory Component (.component.yml) files.
 *
 * This validator mirrors the validation logic from Drupal core's
 * ComponentValidator class in:
 * web/core/lib/Drupal/Core/Theme/Component/ComponentValidator.php
 */
class Validator
{

  /**
   * Entry point for the validator.
   */
  public function run(array $argv): int
  {
    $paths = array_slice($argv, 1);

    if (empty($paths)) {
      echo "Usage: validate-sdc [path1] [path2] ... [--enforce-schemas]\n";
      echo "Example: validate-sdc web/themes/custom/[theme_name]/components\n";
      echo "Options:\n";
      echo "  --enforce-schemas  Require schema definitions for all components\n";
      return 1;
    }

    // Check for --enforce-schemas flag.
    $enforce_schemas = false;
    $paths = array_filter($paths, function($path) use (&$enforce_schemas) {
      if ($path === '--enforce-schemas') {
        $enforce_schemas = true;
        return false;
      }
      return true;
    });

    // Try to find local schema file first, fall back to remote.
    $schemaPath = $this->findSchemaFile();

    // Collect all .component.yml files.
    $allFiles = [];
    foreach ($paths as $path) {
      $allFiles = array_merge($allFiles, $this->findComponentFiles($path));
    }

    if (empty($allFiles)) {
      echo "No .component.yml files found in the specified paths.\n";
      return 0;
    }

    $hasErrors = false;
    $totalFiles = 0;
    $filesWithErrors = 0;

    foreach ($allFiles as $filePath) {
      $totalFiles++;

      try {
        $yamlData = Yaml::parseFile($filePath);

        // Add synthetic 'id' if not present (based on directory name).
        if (!isset($yamlData['id'])) {
          $yamlData['id'] = basename(dirname($filePath));
        }

        // Remove $schema property - it's informational and can cause validation issues.
        unset($yamlData['$schema']);

        // Validate like Drupal's ComponentValidator::validateDefinition().
        $allErrors = $this->validateComponentDefinition($yamlData, $filePath, $schemaPath, $enforce_schemas);

        if (!empty($allErrors)) {
          $hasErrors = true;
          $filesWithErrors++;
          echo "\n{$filePath} has validation errors:\n";
          foreach ($allErrors as $error) {
            echo "  • {$error}\n";
          }
        }
      } catch (\Exception $e) {
        $hasErrors = true;
        $filesWithErrors++;
        echo "\n{$filePath} - Parse error:\n";
        echo "  • " . $e->getMessage() . "\n";
      }
    }

    echo "\n" . str_repeat("=", 60) . "\n";
    if ($hasErrors) {
      echo "✗ Validation failed!\n";
      echo "  Total files checked: {$totalFiles}\n";
      echo "  Files with errors: {$filesWithErrors}\n";
      return 1;
    } else {
      echo "✓ All {$totalFiles} component files are valid!\n";
      return 0;
    }
  }

  /**
   * Finds the schema file path.
   *
   * Tries multiple possible locations for the Drupal schema file.
   *
   * @return string
   *   The absolute path to the schema file.
   */
  private function findSchemaFile(): string
  {
    // Possible schema locations (relative to project root).
    $possiblePaths = [
      'web/core/assets/schemas/v1/metadata.schema.json',
      'docroot/core/assets/schemas/v1/metadata.schema.json',
      'core/assets/schemas/v1/metadata.schema.json',
    ];

    // Try from current working directory.
    $cwd = getcwd();
    foreach ($possiblePaths as $relativePath) {
      $fullPath = $cwd . '/' . $relativePath;
      if (file_exists($fullPath)) {
        return $fullPath;
      }
    }

    // Error if schema file not found.
    echo "Error: Schema file not found. Tried:\n";
    foreach ($possiblePaths as $path) {
      echo "  - $path\n";
    }
    exit(1);
  }

  /**
   * Loads schema (cached or remote).
   */
  private function getSchema(string $remoteUrl): ?object
  {
    static $schema = null;
    if ($schema !== null) {
      return $schema;
    }

    // Cache location.
    $cacheDir = sys_get_temp_dir() . '/sdc-schema-cache';
    $cacheFile = $cacheDir . '/metadata-full.schema.json';

    if (!is_dir($cacheDir)) {
      mkdir($cacheDir, 0777, true);
    }

    // Use cached schema if < 24 hours old.
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
      $schema = json_decode(file_get_contents($cacheFile));
      if ($schema !== null) {
        return $schema;
      }
    }

    // Fetch remote schema.
    echo "Fetching schema from remote...\n";
    $context = stream_context_create([
      'http' => [
        'timeout' => 10,
        'user_agent' => 'Drupal SDC Validator/1.0',
      ],
      'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
      ],
    ]);

    $schemaContent = @file_get_contents($remoteUrl, false, $context);

    // Try curl as fallback if file_get_contents fails
    if ($schemaContent === false && function_exists('curl_init')) {
      echo "Retrying with cURL...\n";
      $ch = curl_init($remoteUrl);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      curl_setopt($ch, CURLOPT_USERAGENT, 'Drupal SDC Validator/1.0');
      $schemaContent = curl_exec($ch);
      $curlError = curl_error($ch);
      curl_close($ch);

      if ($schemaContent === false || $curlError) {
        echo "Warning: Unable to fetch remote schema";
        if ($curlError) {
          echo ": " . $curlError;
        }
        echo "\nContinuing with basic validation only.\n";
        return null;
      }
    } elseif ($schemaContent === false) {
      $error = error_get_last();
      echo "Warning: Unable to fetch remote schema";
      if ($error) {
        echo ": " . $error['message'];
      }
      echo "\nContinuing with basic validation only.\n";
      return null;
    }

    $schema = json_decode($schemaContent);
    if ($schema === null) {
      echo "Error: Invalid schema format. Basic validation only.\n";
      return null;
    }

    // Cache schema.
    file_put_contents($cacheFile, $schemaContent);

    return $schema;
  }

  /**
   * Validates component definition like ComponentValidator::validateDefinition().
   *
   * @param array $definition
   *   The component definition from YAML.
   * @param string $filePath
   *   The file path (for error context).
   * @param string $schemaPath
   *   The path to the JSON schema file.
   * @param bool $enforce_schemas
   *   Whether to enforce schema definitions.
   *
   * @return array
   *   Array of error messages.
   */
  private function validateComponentDefinition(array $definition, string $filePath, string $schemaPath, bool $enforce_schemas): array
  {
    $errors = [];
    $componentId = $definition['id'] ?? 'unknown';

    // 1. Check for name collisions between props and slots.
    $prop_names = array_keys($definition['props']['properties'] ?? []);
    $slot_names = array_keys($definition['slots'] ?? []);
    $collisions = array_intersect($prop_names, $slot_names);
    if ($collisions) {
      $errors[] = sprintf(
        'The component "%s" declared [%s] both as a prop and as a slot. Make sure to use different names.',
        $componentId,
        implode(', ', $collisions)
      );
    }

    // 2. Check if schema (props) exists.
    $propsSchema = $definition['props'] ?? NULL;
    if (!$propsSchema) {
      if ($enforce_schemas) {
        $errors[] = sprintf(
          'The component "%s" does not provide schema information. Schema definitions are mandatory for components declared in modules. For components declared in themes, schema definitions are only mandatory if the "enforce_prop_schemas" key is set to "true" in the theme info file.',
          $componentId
        );
      }
      return $errors;
    }

    // 3. If there are no props, force casting to object instead of array.
    if (($propsSchema['properties'] ?? NULL) === []) {
      $propsSchema['properties'] = new \stdClass();
      $definition['props']['properties'] = new \stdClass();
    }

    // 4. Ensure that all property types are strings.
    $non_string_props = [];
    foreach ($prop_names as $prop) {
      if (!isset($propsSchema['properties'][$prop]['type'])) {
        continue;
      }
      $type = $propsSchema['properties'][$prop]['type'];
      $types = !is_array($type) ? [$type] : $type;
      $non_string_types = array_filter($types, static fn (mixed $type) => !is_string($type));
      if ($non_string_types) {
        $non_string_props[] = $prop;
      }
    }

    if ($non_string_props) {
      $errors[] = sprintf(
        'The component "%s" uses non-string types for properties: %s.',
        $componentId,
        implode(', ', $non_string_props)
      );
    }

    // 5. Detect props with class types and validate they exist.
    $classes_per_prop = $this->getClassProps($propsSchema);
    $missing_class_errors = [];
    foreach ($classes_per_prop as $prop_name => $class_types) {
      $missing_classes = array_filter($class_types, static fn(string $class) => !class_exists($class) && !interface_exists($class));
      foreach ($missing_classes as $class) {
        $missing_class_errors[] = sprintf(
          'Unable to find class/interface "%s" specified in the prop "%s" for the component "%s".',
          $class,
          $prop_name,
          $componentId
        );
      }
    }

    // 6. Remove non JSON Schema types for validation.
    $definition['props'] = $this->nullifyClassPropsSchema($propsSchema, $classes_per_prop);

    // 7. Validate against JSON Schema.
    try {
      // Recursively remove any $schema properties from the definition.
      $definition = $this->removeSchemaReferences($definition);

      $validator = new JsonValidator();
      $definition_object = JsonValidator::arrayToObjectRecursive($definition);
      $validator->reset();
      $validator->validate(
        $definition_object,
        (object) ['$ref' => 'file://' . $schemaPath]
      );

      if (!$validator->isValid()) {
        foreach ($validator->getErrors() as $error) {
          $errors[] = sprintf('[%s] %s', $error['property'], $error['message']);
        }
      }
    } catch (\Exception $e) {
      $errors[] = 'Schema validation error: ' . $e->getMessage();
    }

    // 8. Add missing class errors.
    $errors = array_merge($errors, $missing_class_errors);

    return $errors;
  }

  /**
   * Recursively removes $schema properties from an array.
   *
   * @param array $data
   *   The array to clean.
   *
   * @return array
   *   The cleaned array.
   */
  private function removeSchemaReferences(array $data): array
  {
    unset($data['$schema']);

    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $data[$key] = $this->removeSchemaReferences($value);
      }
    }

    return $data;
  }

  /**
   * Gets the props that are not JSON Schema types (class names).
   *
   * @param array $props_schema
   *   The props schema.
   *
   * @return array
   *   Associative array of prop names to class type arrays.
   */
  private function getClassProps(array $props_schema): array
  {
    $classes_per_prop = [];
    foreach ($props_schema['properties'] ?? [] as $prop_name => $prop_def) {
      $type = $prop_def['type'] ?? 'null';
      $types = is_string($type) ? [$type] : $type;
      // Filter to only class types (not standard JSON schema types).
      $class_types = array_filter($types, static fn(string $type) => !in_array(
        $type,
        ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string']
      ));
      if (!empty($class_types)) {
        $classes_per_prop[$prop_name] = $class_types;
      }
    }
    return $classes_per_prop;
  }

  /**
   * Nullify class props schema for JSON Schema validation.
   *
   * @param array $schema_props
   *   The props schema.
   * @param array $classes_per_prop
   *   Associative array of prop names to class types.
   *
   * @return array
   *   The modified schema with class types replaced by 'null'.
   */
  private function nullifyClassPropsSchema(array $schema_props, array $classes_per_prop): array
  {
    foreach ($schema_props['properties'] ?? [] as $prop_name => $prop_def) {
      $class_types = $classes_per_prop[$prop_name] ?? [];
      if (empty($class_types)) {
        continue;
      }
      // Remove the non JSON Schema types.
      $types = (array) ($prop_def['type'] ?? ['null']);
      $types = array_diff($types, $class_types);
      $types = empty($types) ? ['null'] : $types;
      $schema_props['properties'][$prop_name]['type'] = $types;
    }
    return $schema_props;
  }

  /**
   * Finds .component.yml files in a directory or single file.
   */
  private function findComponentFiles(string $path): array
  {
    $files = [];

    // If relative, resolve relative to CWD.
    if (!str_starts_with($path, '/')) {
      $path = getcwd() . '/' . $path;
    }

    if (!file_exists($path)) {
      echo "Warning: Path does not exist: {$path}\n";
      return $files;
    }

    if (is_file($path) && str_ends_with($path, '.component.yml')) {
      return [$path];
    }

    if (is_dir($path)) {
      $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
      $iterator = new \RecursiveIteratorIterator($directory);
      $regex = new \RegexIterator($iterator, '/^.+\.component\.yml$/i', \RegexIterator::GET_MATCH);

      foreach ($regex as $file) {
        $files[] = $file[0];
      }
    }

    return $files;
  }
}
