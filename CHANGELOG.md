# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-10-15

### Added
- Initial release of Drupal SDC Validator
- Validation against Drupal core's `metadata-full.schema.json`
- Name collision detection between props and slots
- Non-string property type validation
- Class/interface type validation support
- `--enforce-schemas` flag for strict schema requirement
- Empty properties object handling (`properties: {}`)
- Remote schema caching (24 hour TTL)
- Error message formatting matching Drupal core's ComponentValidator
- Recursive `.component.yml` file discovery
- Multiple directory path support

### Changed
- Uses `metadata-full.schema.json` instead of `metadata.schema.json`
- Removed local schema file dependency (always uses remote + cache)

[1.0.0]: https://github.com/kanopi/drupal-sdc-validator/releases/tag/v1.0.0
