<?php

namespace Kanopi\DrupalSdcValidator;

use Symfony\Component\Yaml\Yaml;
use JsonSchema\Validator as JsonValidator;
use JsonSchema\Constraints\Constraint;

/**
 * Validates Drupal Single Directory Component (.component.yml) files.
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

    $remoteSchemaUrl = 'https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata-full.schema.json';

    $schema = $this->getSchema($remoteSchemaUrl);

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

        // Remove $schema property - it's informational and can cause validation issues.
        unset($yamlData['$schema']);

        // Handle empty properties object.
        if (isset($yamlData['props']['properties']) && $yamlData['props']['properties'] === []) {
          $yamlData['props']['properties'] = new \stdClass();
        }

        $jsonData = json_decode(json_encode($yamlData));

        $structureErrors = $this->validateComponentStructure($yamlData);
        $schemaErrors = $this->validateAgainstSchema($jsonData, $schema, $enforce_schemas);

        $allErrors = array_merge($structureErrors, $schemaErrors);

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
   * Validates basic SDC structure.
   */
  private function validateComponentStructure(array $data): array
  {
    $errors = [];

    if (empty($data['name'])) {
      $errors[] = "Missing required field: 'name'";
    }

    // Check for name collisions between props and slots.
    $prop_names = array_keys($data['props']['properties'] ?? []);
    $slot_names = array_keys($data['slots'] ?? []);
    $collisions = array_intersect($prop_names, $slot_names);
    if ($collisions) {
      $component_id = $data['machineName'] ?? $data['name'] ?? 'unknown';
      $errors[] = sprintf(
        'The component "%s" declared [%s] both as a prop and as a slot. Make sure to use different names.',
        $component_id,
        implode(', ', $collisions)
      );
    }

    if (isset($data['props'])) {
      if (!isset($data['props']['type'])) {
        $errors[] = "props must have a 'type' field";
      } elseif ($data['props']['type'] === 'object' && !isset($data['props']['properties'])) {
        $errors[] = "props with type 'object' must have a 'properties' field (use 'properties: {}' if empty)";
      }

      // Validate that all property types are strings.
      if (isset($data['props']['properties'])) {
        $non_string_props = [];
        foreach ($data['props']['properties'] as $prop => $prop_def) {
          if (isset($prop_def['type'])) {
            $type = $prop_def['type'];
            $types = !is_array($type) ? [$type] : $type;
            $non_string_types = array_filter($types, fn($t) => !is_string($t));
            if ($non_string_types) {
              $non_string_props[] = $prop;
            }
          }
        }

        if ($non_string_props) {
          $component_id = $data['machineName'] ?? $data['name'] ?? 'unknown';
          $errors[] = sprintf(
            'The component "%s" uses non-string types for properties: %s.',
            $component_id,
            implode(', ', $non_string_props)
          );
        }
      }
    }

    if (isset($data['slots']) && !is_array($data['slots'])) {
      $errors[] = "slots must be an object/array";
    }

    return $errors;
  }

  /**
   * Validates a YAML data object against a JSON schema.
   */
  private function validateAgainstSchema(object $data, ?object $schema, bool $enforce_schemas = false): array
  {
    $errors = [];

    // Check if schema is enforced but missing.
    if ($enforce_schemas && !isset($data->props)) {
      $component_id = $data->machineName ?? $data->name ?? 'unknown';
      $errors[] = sprintf(
        'The component "%s" does not provide schema information. Schema definitions are mandatory for components declared in modules. For components declared in themes, schema definitions are only mandatory if the "enforce_prop_schemas" key is set to "true" in the theme info file.',
        $component_id
      );
      return $errors;
    }

    if ($schema === null) {
      return [];
    }

    // Validate class/interface types if present.
    if (isset($data->props->properties)) {
      $class_errors = $this->validateClassTypes($data);
      $errors = array_merge($errors, $class_errors);

      // Remove class types from the schema for JSON validation.
      $data = $this->removeClassTypesFromData($data);
    }

    $validator = new JsonValidator();
    $validator->validate($data, $schema, Constraint::CHECK_MODE_TYPE_CAST);

    if (!$validator->isValid()) {
      foreach ($validator->getErrors() as $error) {
        $message = sprintf('[%s] %s', $error['property'], $error['message']);
        $errors[] = $message;
      }
    }

    return $errors;
  }

  /**
   * Validates class/interface types in props.
   */
  private function validateClassTypes(object $data): array
  {
    $errors = [];
    $component_id = $data->machineName ?? $data->name ?? 'unknown';

    foreach ($data->props->properties as $prop_name => $prop_def) {
      if (!isset($prop_def->type)) {
        continue;
      }

      $types = is_array($prop_def->type) ? $prop_def->type : [$prop_def->type];

      // Filter for class/interface types (non-standard JSON Schema types).
      $class_types = array_filter($types, function($type) {
        return !in_array($type, ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string']);
      });

      // Check if these classes/interfaces exist.
      foreach ($class_types as $class) {
        if (!class_exists($class) && !interface_exists($class)) {
          $errors[] = sprintf(
            'Unable to find class/interface "%s" specified in the prop "%s" for the component "%s".',
            $class,
            $prop_name,
            $component_id
          );
        }
      }
    }

    return $errors;
  }

  /**
   * Removes class types from data for JSON Schema validation.
   */
  private function removeClassTypesFromData(object $data): object
  {
    $data = clone $data;

    if (!isset($data->props->properties)) {
      return $data;
    }

    foreach ($data->props->properties as $prop_name => $prop_def) {
      if (!isset($prop_def->type)) {
        continue;
      }

      $types = is_array($prop_def->type) ? $prop_def->type : [$prop_def->type];

      // Filter out class/interface types.
      $json_types = array_values(array_filter($types, function($type) {
        return in_array($type, ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string']);
      }));

      // If no valid JSON types remain, set to null.
      if (empty($json_types)) {
        $json_types = ['null'];
      }

      $data->props->properties->{$prop_name}->type = $json_types;
    }

    return $data;
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
