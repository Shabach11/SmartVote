# SmartVote

## Project structure

- `app/config/` — PDO database connection settings.
- `database/schema.sql` — normalized voter-list, ballot-receipt, and audit tables.
- `includes/` — authentication, shared layout, and XLSX reader.
- `public/admin/` — election lifecycle, voter import, candidate management, results, and audit pages.
- `public/vote/` — validated voter ballot and OTP confirmation flow.
- `uploads/` — candidate images (do not allow script execution in this directory).

## Setup

1. Set the database credentials in `app/config/database.php`.
2. Install the Excel reader with `composer install`, then run `database/schema.sql` on new installations. Existing installations are upgraded by `config.php` when a database user has `ALTER` privileges.
   Existing accounts without a username are assigned `User_<id>` during this migration. Usernames accept uppercase and lowercase letters; the system capitalizes the first letter on registration and login.
3. Set a trusted existing user to the `admin` role directly in the database, then use **Admin Access** to promote other existing voters:

```sql
UPDATE users SET role = 'admin' WHERE id = 1;
```

If `users.role` is an ENUM, ensure it permits `admin`. Public registration never grants administrative access.

On first initialization, SmartVote creates the default dashboard account: username `Admin`, password `123456789`. Change this password immediately after signing in.

## Voter spreadsheet

Organizers import an `.xlsx` worksheet via PhpSpreadsheet. It may be a simple name list, or contain a `Name`/`Full Name` column with any organization identifier column, such as `Company ID`, `Index Number`, `Staff ID`, or `Email`. The imported list is the primary voter validation source: accounts not present in it cannot sign in or vote. A new import replaces that election’s previous eligible-voter list.
