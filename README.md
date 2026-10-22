# Command Bridge

Command Bridge is a web-based Incident Command System (ICS) application for municipal incident coordination and documentation. It is designed to help incident teams manage incidents, operational periods, personnel, resources, communications, and ICS documentation from a central, role-based workspace.

The application is intended for municipal government operations in Alberta, Canada. It should support—not replace—approved emergency plans, incident command procedures, records-management practices, or direction from the authority having jurisdiction.

## Key capabilities

- Create and manage incidents and operational periods
- Assign users to ICS roles and incident positions
- Maintain incident personnel, check-in, contact, and resource information
- Create and manage operational ICS forms, including modules for:
  - ICS 201, 202, 203, 204, 205, 205A, 206, 207, 208, 209, 210, 211, 213, 213RR, 214, 215A, 218, and 230
- Produce PDF outputs for supported forms
- Maintain audit records of application activity
- Manage organization, role, lookup, resource catalogue, and form-permission settings
- Support local PIN-based access for incident users
- Support password-based access for the Technical Services Coordinator (TSC) administrator
- Optionally support Microsoft Entra ID / Microsoft 365 sign-in
- Configure outbound email through SMTP relay or Microsoft Graph
- Provide administrative backup and restore functions

## Technology

| Component | Technology |
| --- | --- |
| Web server | Apache HTTP Server |
| Application | PHP |
| Database | MariaDB |
| User interface | HTML, CSS, JavaScript |
| PDF generation | TCPDF / FPDF libraries included with the project |

## Repository layout

```text
CommandBridge/
├── command-bridge-webapp/     # Web application document root
│   ├── admin/                 # Administrative functions
│   ├── assets/                # CSS, images, PDFs, and static assets
│   ├── auth/                  # Microsoft Entra sign-in endpoints
│   ├── dashboard/             # Dashboard
│   ├── forms/                 # ICS form modules
│   ├── includes/              # Shared PHP code
│   ├── operational_periods/   # Operational period management
│   ├── setup/                 # First-run setup
│   └── storage/               # Application-managed uploads and branding assets
└── deployment_seed.sql        # Database schema and seed data
```

## Before publishing to GitHub

**Do not publish this repository publicly until sensitive data has been reviewed and removed.**

In particular:

- `command-bridge-webapp/db.env` contains database connection settings and must not be committed with a real password.
- `deployment_seed.sql` may contain organization-specific, user, incident, or other operational data. Review and sanitize it before publishing.
- Review uploaded files, exported reports, branding assets, email settings, Entra settings, and any backups before committing.
- Use a private repository for operational development unless the code and all included data have been cleared for public release.

At minimum, remove `db.env` from source control and replace it with a non-sensitive example file before committing:

```bash
git rm --cached command-bridge-webapp/db.env
```

A safe example configuration is:

```ini
DB_HOST=127.0.0.1
DB_NAME=ics_app
DB_APP_USER=ics_app_user
DB_APP_PASSWORD=replace_with_a_strong_secret
```

> Use organization-approved secret management for production credentials. Do not store database passwords, API secrets, or backup files in Git.

## Prerequisites

- Apache HTTP Server with PHP enabled
- PHP 8.0 or later, with PDO MySQL support enabled
- MariaDB compatible with the included `deployment_seed.sql`
- A dedicated MariaDB database and least-privilege application account
- HTTPS certificate for production use
- Write access for the web-server account to:
  - `command-bridge-webapp/storage/uploads/`
  - `command-bridge-webapp/storage/branding/`

Depending on the server build and enabled form/PDF functions, common PHP extensions such as `pdo_mysql`, `mbstring`, `gd`, and `fileinfo` may also be required.

## Installation

### 1. Deploy the application files

Set Apache's document root to the `command-bridge-webapp/` directory.

```text
/var/www/command-bridge/command-bridge-webapp
```

Keep `deployment_seed.sql`, backups, and any installation files outside the public document root where practical.

### 2. Create and load the database

Create an empty MariaDB database, create a dedicated application account, and import the supplied database seed.

```bash
mariadb -u <database-administrator> -p -e "CREATE DATABASE ics_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -u <database-administrator> -p ics_app < deployment_seed.sql
```

Use a dedicated database account with only the permissions required by the application. Do not use a MariaDB root account in `db.env`.

### 3. Configure database access

Create `command-bridge-webapp/db.env` using the example above. Restrict read access to the web-server account and avoid making the file world-readable.

The application can also use an alternate configuration file path through the `DB_ENV_PATH` environment variable. For production deployments, storing this file outside the web root is recommended.

### 4. Configure the application URL

Review `command-bridge-webapp/includes/config.php`.

- `APP_BASE_URL` defaults to `/` and is appropriate when the app is hosted at the web root.
- Update it if Command Bridge is hosted in a subdirectory.
- Confirm the organization name, logos, mail sender address, and timezone settings are correct for the deployment.

### 5. Complete first-run setup

Open the application URL in a browser. If no TSC administrator exists, Command Bridge redirects to the first-run setup process.

The setup process creates:

1. The Technical Services Coordinator administrator account
2. An initial incident and operational period
3. An initial Incident Commander user

Use a strong, unique password for the TSC administrator account.

## Authentication

Command Bridge supports the following sign-in approaches:

| Account type | Sign-in method |
| --- | --- |
| Technical Services Coordinator administrator | Username and password |
| Local incident user | Username and six-digit PIN |
| Microsoft Entra user | Microsoft Entra ID / Microsoft 365 sign-in |

Administrators can manage users and reset local credentials from the user administration area. Users can use the account link in the application header to change their own password or PIN, where applicable.

### Microsoft Entra ID

Microsoft Entra sign-in is optional. Configuration guidance is available in [command-bridge-webapp/ENTRA_SETUP_NOTES.md](command-bridge-webapp/ENTRA_SETUP_NOTES.md).

For each Entra user, Command Bridge retains the local ICS role and form permissions while Entra ID performs authentication.

## Email delivery

Command Bridge can send email using an SMTP relay or Microsoft Graph. Configuration guidance is available in [command-bridge-webapp/EMAIL_DELIVERY_SETUP.md](command-bridge-webapp/EMAIL_DELIVERY_SETUP.md).

Use a controlled organizational service account or approved mail relay. Test email delivery after configuration and monitor errors through normal server and application support processes.

## Security and operational guidance

- Enforce HTTPS and protect the application with current TLS configuration.
- Restrict Apache, PHP, database, and file-system permissions to the minimum required.
- Keep PHP, Apache, MariaDB, and operating-system packages patched.
- Back up the database and uploaded files using approved municipal backup procedures.
- Test backup restoration in a non-production environment before relying on it operationally.
- Review user roles, access, and audit logs routinely.
- Treat incident records, contact information, and uploaded documents as potentially sensitive municipal records.
- Confirm privacy, retention, disclosure, and records-management practices with the appropriate Municipal District, legal, privacy, and emergency-management authorities.

## Development notes

- The application uses PHP sessions, CSRF protection for POST requests, prepared database queries in application code, password hashing for local credentials, and audit logging.
- Keep the existing directory layout when deploying changes.
- When changing database structure, update `deployment_seed.sql` and create a separate incremental migration script for existing deployments.
- Test changes against representative incident workflows before production deployment.

## Contributing

1. Work from a non-production copy of the application and database.
2. Make the smallest practical change needed.
3. Do not commit secrets, production data, uploaded documents, or backups.
4. Test affected workflows, permissions, and PDF outputs.
5. Document any required database migration and deployment steps in the pull request.

## Disclaimer

Command Bridge is a technical tool for incident coordination and documentation. It does not replace incident command training, municipal emergency plans, operational policies, legal advice, privacy advice, or direction from the authority having jurisdiction.

Before production use, test the application across representative operational scenarios and have the deployment reviewed by the appropriate IT, privacy, records-management, security, and emergency-management authorities.
