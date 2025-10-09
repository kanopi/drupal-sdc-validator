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
      echo "Usage: validate-sdc [path1] [path2] ...\n";
      echo "Example: validate-sdc web/themes/custom/mises/components\n";
      return 1;
    }

    $localSchemaPath = 'web/core/assets/schemas/v1/metadata.schema.json';
    $remoteSchemaUrl = 'https://git.drupalcode.org/project/drupal/-/raw/HEAD/core/assets/schemas/v1/metadata.schema.json';

    $schema = $this->getSchema($localSchemaPath, $remoteSchemaUrl);

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
        $jsonData = json_decode(json_encode($yamlData));

        $structureErrors = $this->validateComponentStructure($yamlData);
        $schemaErrors = $this->validateAgainstSchema($jsonData, $schema);

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
   * Loads schema (local, cached, or remote).
   */
  private function getSchema(string $localPath, string $remoteUrl): ?object
  {
    static $schema = null;
    if ($schema !== null) {
      return $schema;
    }

    // Try local schema first.
    if (file_exists($localPath)) {
      $schema = json_decode(file_get_contents($localPath));
      if ($schema !== null) {
        return $schema;
      }
    }

    // Cache location.
    $cacheDir = sys_get_temp_dir() . '/sdc-schema-cache';
    $cacheFile = $cacheDir . '/metadata.schema.json';

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
      'http' => ['timeout' => 10],
    ]);

    $schemaContent = @file_get_contents($remoteUrl, false, $context);
    if ($schemaContent === false) {
      echo "Error: Unable to fetch remote schema. Basic validation only.\n";
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

    if (isset($data['props'])) {
      if (!isset($data['props']['type'])) {
        $errors[] = "props must have a 'type' field";
      } elseif ($data['props']['type'] === 'object' && !isset($data['props']['properties'])) {
        $errors[] = "props with type 'object' must have a 'properties' field (use 'properties: {}' if empty)";
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
  private function validateAgainstSchema(object $data, ?object $schema): array
  {
    if ($schema === null) {
      return [];
    }

    $validator = new JsonValidator();
    $validator->validate($data, $schema, Constraint::CHECK_MODE_TYPE_CAST);

    if (!$validator->isValid()) {
      $errors = [];
      foreach ($validator->getErrors() as $error) {
        $property = $error['property'] ? "[{$error['property']}] " : '';
        $errors[] = $property . $error['message'];
      }
      return $errors;
    }

    return [];
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
