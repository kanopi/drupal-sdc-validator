# Drupal SDC Validator

A CLI tool to validate [Drupal Single Directory Component (SDC)](https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components) `.component.yml` files for structure and schema compliance.

This validator helps you ensure your SDC metadata files follow the [Drupal core schema](https://git.drupalcode.org/project/drupal/-/blob/HEAD/core/assets/schemas/v1/metadata.schema.json), and reports any missing fields, invalid structures, or schema violations.

---

## 🚀 Features

- ✅ Validates `.component.yml` files recursively in any path  
- ✅ Supports local, cached, or remote Drupal schema resolution  
- ✅ Basic structure validation (e.g. `name`, `props`, `slots`)  
- ✅ JSON Schema validation via [`justinrainbow/json-schema`](https://github.com/justinrainbow/json-schema)  
- ✅ Works as a Composer-installed CLI tool (`vendor/bin/validate-sdc`)  
- ✅ Caches remote schema for 24 hours for faster re-runs  

---

## 🧩 Installation

### Option 1: Local (per project)

```bash
composer require --dev kanopi/drupal-sdc-validator
