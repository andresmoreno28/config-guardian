# Changelog

All notable changes to Config Guardian will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2024-12-09

### Added
- **Snapshot Management**
  - Point-in-time configuration snapshots
  - Dual storage capture (active config AND sync directory)
  - Automatic scheduled snapshots (hourly, daily, weekly)
  - Pre-import automatic snapshots
  - Gzip/Bzip2 compression for storage efficiency
  - SHA-256 integrity verification
  - Configurable retention policies
  - Snapshot exclusion patterns

- **Rollback Capabilities**
  - Safe rollback with simulation/dry-run mode
  - Pre-rollback automatic backup (optional, can be disabled with `--no-backup`)
  - Full environment restore (both active config AND sync directory)
  - Conflict detection before execution
  - Risk assessment with scoring system

- **Impact Analysis**
  - Configuration dependency mapping
  - Risk scoring (0-100) with level classification
  - Conflict detection (circular dependencies, missing requirements)
  - Interactive D3.js dependency graph visualization with zoom, pan, and search

- **Configuration Synchronization**
  - Export active configuration to sync storage
  - Import preview with change categorization
  - Batch processing for large configuration sets
  - Integration with Drupal core config system

- **Activity Logging**
  - Complete audit trail for all operations
  - User and IP tracking
  - Detailed change logging
  - Status tracking (success, warning, error)

- **Drush Integration**
  - `config-guardian:snapshot` - Create snapshots
  - `config-guardian:list` - List snapshots
  - `config-guardian:rollback` - Rollback to snapshot
  - `config-guardian:analyze` - Analyze pending changes
  - `config-guardian:diff` - Compare snapshots
  - `config-guardian:export` - Export snapshot to file
  - `config-guardian:delete` - Delete snapshot

- **User Interface**
  - Dashboard with status overview and statistics
  - Snapshot management pages with quick actions
  - Impact analysis visualization
  - Activity log with filtering and export
  - Settings configuration form
  - Dark mode support (Gin, Claro, system preference)
  - Responsive design for mobile devices
  - Toast notification system
  - Skeleton loaders for better UX

- **Security**
  - 11 granular permissions for fine-grained access control
  - Restricted access for sensitive operations
  - Input validation and sanitization
  - JSON encoding for data serialization (no PHP object injection)
  - Parameterized database queries (SQL injection prevention)
  - CSRF protection on all forms

- **Internationalization**
  - Full translation support
  - Spanish translation included
  - Ready for localize.drupal.org

---

## Version History Summary

| Version | Date | Highlights |
|---------|------|------------|
| 1.0.0 | 2024-12-09 | Initial stable release with full feature set |

---

## Contributors

Thanks to all contributors who have helped make Config Guardian possible!

---

[1.0.0]: https://www.drupal.org/project/config_guardian/releases/1.0.0
