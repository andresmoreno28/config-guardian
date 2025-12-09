# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Reporting a Vulnerability

**Please do NOT report security vulnerabilities through public GitHub issues.**

Config Guardian follows the [Drupal Security Team](https://www.drupal.org/drupal-security-team) process for handling security issues.

### How to Report

1. **Email the Drupal Security Team** at security@drupal.org
2. Include "Config Guardian" in the subject line
3. Provide a detailed description of the vulnerability
4. Include steps to reproduce if possible
5. Do NOT disclose the vulnerability publicly until it has been addressed

### What to Expect

- **Acknowledgment**: You will receive acknowledgment of your report within 48 hours
- **Assessment**: The security team will assess the vulnerability and its impact
- **Resolution**: We will work on a fix and coordinate disclosure
- **Credit**: Security researchers who report valid vulnerabilities will be credited in the security advisory

### Security Advisory Process

Once a vulnerability is confirmed and fixed:

1. A security release will be prepared
2. A [Security Advisory](https://www.drupal.org/security) will be published on Drupal.org
3. Users will be notified through the standard Drupal security announcement channels

## Security Best Practices

When using Config Guardian, we recommend:

### Permissions
- Grant the `administer config guardian` permission only to trusted administrators
- Use granular permissions for specific operations (view, create, delete snapshots)
- Regularly audit user permissions

### Configuration
- Enable automatic pre-import snapshots for safety
- Set appropriate retention policies to manage storage
- Use exclude patterns for sensitive configuration if needed

### Operations
- Always review rollback simulations before executing
- Create manual snapshots before major changes
- Monitor the activity log for unusual operations

## Security Measures in Config Guardian

Config Guardian implements several security measures:

### Input Validation
- All user inputs are validated and sanitized
- Configuration names are validated against allowed patterns
- File paths are validated to prevent directory traversal

### Database Security
- All database queries use parameterized statements
- No dynamic SQL construction with user input

### Access Control
- 11 granular permissions for fine-grained access control
- All routes are protected by appropriate permissions
- CSRF protection on all forms

### Data Integrity
- SHA-256 hash verification for snapshot integrity
- Compressed storage with integrity checks
- Atomic operations for rollback processes

## Contact

For general security questions about Config Guardian, please use the [issue queue](https://www.drupal.org/project/issues/config_guardian) with the "security" tag (for non-vulnerability questions only).

For actual security vulnerabilities, always use the Drupal Security Team process described above.
