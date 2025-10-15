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
      echo "Usage: validate-sdc [path1] [path2] ...\n";
      echo "Example: validate-sdc web/themes/custom/[theme_name]/components\n";
      return 1;
    }

    // Find schema file.
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

    // Validate each file.
    $hasErrors = false;
    $totalFiles = 0;
    $filesWithErrors = 0;

    foreach ($allFiles as $filePath) {
      $totalFiles++;

      try {
        // Parse YAML file.
        $yamlData = Yaml::parseFile($filePath);

        // Add synthetic 'id' if not present (based on directory name).
        if (!isset($yamlData['id'])) {
          $yamlData['id'] = basename(dirname($filePath));
        }

        // Validate like Drupal's ComponentValidator.
        $errors = $this->validateComponentDefinition($yamlData, $filePath, $schemaPath);

        if (!empty($errors)) {
          $hasErrors = true;
          $filesWithErrors++;
          echo "\n{$filePath} has validation errors:\n";
          foreach ($errors as $error) {
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
      echo "\nThese errors match what Drupal's ComponentValidator would throw.\n";
      return 1;
    } else {
      echo "✓ All {$totalFiles} component files are valid!\n";
      return 0;
    }
  }

  /**
   * Finds the schema file path.
   */
  private function findSchemaFile(): string
  {
    $possiblePaths = [
      'web/core/assets/schemas/v1/metadata.schema.json',
      'docroot/core/assets/schemas/v1/metadata.schema.json',
      'core/assets/schemas/v1/metadata.schema.json',
    ];

    $cwd = getcwd();
    foreach ($possiblePaths as $relativePath) {
      $fullPath = $cwd . '/' . $relativePath;
      if (file_exists($fullPath)) {
        return $fullPath;
      }
    }

    echo "Error: Schema file not found. Tried:\n";
    foreach ($possiblePaths as $path) {
      echo "  - $path\n";
    }
    exit(1);
  }

  /**
   * Validates component definition like ComponentValidator::validateDefinition().
   */
  private function validateComponentDefinition(array $definition, string $filePath, string $schemaPath): array
  {
    $errors = [];
    $componentId = $definition['id'] ?? basename(dirname($filePath));

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

    // 2. Check props structure if present.
    if (isset($definition['props'])) {
      if (!isset($definition['props']['type'])) {
        $errors[] = "props must have a 'type' field";
      }
      elseif ($definition['props']['type'] === 'object' && !isset($definition['props']['properties'])) {
        $errors[] = "props with type 'object' must have a 'properties' field (use 'properties: {}' if empty)";
      }
    }

    // 3. Check if schema (props) exists - now required.
    $schema = $definition['props'] ?? NULL;
    if (!$schema) {
      $errors[] = sprintf(
        'The component "%s" does not provide schema information (props).',
        $componentId
      );
      return $errors;
    }

    // 4. If there are no props, force casting to object instead of array.
    if (($schema['properties'] ?? NULL) === []) {
      $schema['properties'] = new \stdClass();
    }

    // 5. Ensure that all property types are strings.
    $non_string_props = [];
    foreach ($prop_names as $prop) {
      if (!isset($schema['properties'][$prop]['type'])) {
        continue;
      }
      $type = $schema['properties'][$prop]['type'];
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

    // 6. Detect props with class types and validate they exist.
    $classes_per_prop = $this->getClassProps($schema);
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

    // 7. Remove non JSON Schema types for validation.
    $definition['props'] = $this->nullifyClassPropsSchema($schema, $classes_per_prop);

    // 8. Validate against JSON Schema.
    try {
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
    }
    catch (\Exception $e) {
      $errors[] = 'Schema validation error: ' . $e->getMessage();
    }

    // 9. Add missing class errors.
    $errors = array_merge($errors, $missing_class_errors);

    return $errors;
  }

  /**
   * Gets the props that are not JSON Schema types (class names).
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

    // Convert relative paths to absolute.
    if (!str_starts_with($path, '/')) {
      $path = getcwd() . '/' . $path;
    }

    // Check if path exists.
    if (!file_exists($path)) {
      echo "Warning: Path does not exist: {$path}\n";
      return $files;
    }

    // If it's a single file, return it.
    if (is_file($path) && str_ends_with($path, '.component.yml')) {
      return [$path];
    }

    // If it's a directory, find all .component.yml files recursively.
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
