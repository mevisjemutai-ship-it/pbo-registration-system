# PBO Registration and Records Management System

A web-based Public Benefit Organization registration and records management system.

The system supports the registration workflow from initial organization particulars through submission and permanent records management.

## Main Features

- Staff authentication
- User administration
- New PBO registration
- National and International organization registration
- Draft application management
- Public benefit purposes
- Beneficiary information
- Financial year recording
- Officials and directors
- Signature uploads
- Application review and submission
- Submitted application records
- Organization search
- Printable application reports
- Application audit trail
- Annual application reference generation

Application references follow the format:

    PBO.REG.YYYY.NNNN

Example:

    PBO.REG.2026.0001

## Technology

- PHP
- MySQL 8
- Apache
- HTML
- CSS
- JavaScript
- PDO

## Requirements

The deployment server should have:

- Apache
- PHP 8+
- MySQL 8+
- PHP PDO MySQL extension
- Git

## Installation

### 1. Clone the repository

Clone the repository into the Apache web directory:

    cd /var/www/html
    git clone <REPOSITORY-URL> pbo-portal

Then enter the project:

    cd pbo-portal

### 2. Create the database

Open MySQL:

    sudo mysql

Create the database:

    CREATE DATABASE pbo_portal
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

Exit MySQL:

    EXIT;

### 3. Import the database schema

Run:

    sudo mysql pbo_portal < database/schema.sql

The schema file contains the database structure required by the application.

### 4. Configure database access

Copy the example database configuration:

    cp config/database.example.php config/database.php

Edit:

    nano config/database.php

Configure the database connection for the deployment server:

    $host = 'localhost';
    $dbname = 'pbo_portal';
    $dbuser = 'YOUR_DATABASE_USER';
    $dbpass = 'YOUR_DATABASE_PASSWORD';

The real `config/database.php` file is intentionally excluded from Git.

### 5. Configure uploads

Create the application upload directory:

    sudo mkdir -p /var/www/html/pbo-portal/uploads/applications

Give Apache ownership:

    sudo chown -R www-data:www-data /var/www/html/pbo-portal/uploads

Set directory permissions:

    sudo chmod -R 775 /var/www/html/pbo-portal/uploads

### 6. Open the system

For a local installation:

    http://localhost/pbo-portal/

For a LAN installation, use the IP address or hostname assigned to the web server, for example:

    http://SERVER-IP/pbo-portal/

## Security Notes

Do not commit or distribute:

- `config/database.php`
- Database passwords
- Production database dumps
- Uploaded signatures
- User-uploaded documents
- Server logs
- Session files

These are excluded from Git where applicable.

## Database

The clean database structure is located at:

    database/schema.sql

The schema does not contain application test data.

## Upload Storage

Uploaded files are stored under:

    uploads/applications/

Application upload content is not tracked by Git.

## Application Areas

The main application workflow consists of:

1. Organization Details
2. Public Benefit Purposes
3. Beneficiaries
4. Financial Year
5. Officials and Directors
6. Review and Submission

Submitted applications become read-only organization records.

Draft applications remain associated with the staff member who created them.

Submitted records are available to authorized staff for organizational records management.

## Deployment

This repository contains the application source code and clean database schema required for deployment.

Environment-specific configuration, database credentials, production data, and uploaded files must be configured separately on the target server.
