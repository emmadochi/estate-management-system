# Database Setup Guide

## Quick Start

1. **Start XAMPP Services**
   - Open XAMPP Control Panel
   - Start Apache and MySQL services

2. **Create Database**
   - Open phpMyAdmin: http://localhost/phpmyadmin
   - Create a new database named `estate_management`
   - Or use MySQL command line:
     ```sql
     CREATE DATABASE estate_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```

3. **Import Schema**
   - In phpMyAdmin, select the `estate_management` database
   - Go to "Import" tab
   - Choose file: `schema.sql`
   - Click "Go"
   - Or use MySQL command line:
     ```bash
     mysql -u root -p estate_management < schema.sql
     ```

4. **Configure Database Connection**
   - Edit `config.php` if your MySQL credentials differ from defaults
   - Default XAMPP MySQL credentials:
     - Username: `root`
     - Password: `` (empty)

## Database Structure

The schema includes the following main tables:

### Core Tables
- **users** - User accounts and authentication
- **estates** - Estate/branch information
- **properties** - Buildings/blocks within estates
- **units** - Individual units/apartments
- **tenants** - Tenant information
- **leases** - Lease agreements

### Financial Tables
- **invoices** - Rent and service charge invoices
- **payments** - Payment records
- **wallets** - Tenant wallet system
- **wallet_transactions** - Wallet transaction history

### Operations Tables
- **maintenance_tickets** - Maintenance requests
- **vendors** - Vendor/contractor information
- **announcements** - Estate-wide announcements
- **visitor_logs** - Visitor management
- **staff_attendance** - Staff attendance tracking

### Supporting Tables
- **documents** - Document storage references
- **audit_logs** - System audit trail
- **settings** - System and estate-specific settings
- **user_estates** - Multi-estate user relationships

## Default Credentials

After importing the schema, you can login with:
- **Email:** admin@estatepro.com
- **Password:** admin123

⚠️ **Important:** Change the default password immediately in production!

## Next Steps

After setting up the database:
1. Create a database connection class/helper
2. Implement authentication system
3. Build CRUD operations for each module
4. Create API endpoints or direct PHP pages

## Notes

- All tables use InnoDB engine for foreign key support
- UTF8MB4 charset ensures proper emoji and special character support
- Foreign keys maintain referential integrity
- Indexes are optimized for common query patterns
- Timestamps are automatically managed
