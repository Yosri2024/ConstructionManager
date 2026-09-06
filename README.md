# ConstructionManager

A comprehensive construction site management system for tracking workers, sites, jobs, attendance, work hours, and daily reports across multiple construction projects.

## Features

- **Multi-site management** — Track active, paused, and completed construction sites
- **Worker & supervisor tracking** — Manage workers, roles, assignments, and leave status
- **Work hours & overtime** — Log daily hours with overtime tracking and period reporting
- **Attendance tracking** — Mark present, absent, late, or sick with notes
- **Daily site reports** — Work progress, weather, issues, and supervisor notes
- **Job & project tracking** — Manage jobs with codes, budgets, and pause reasons
- **Future assignment planning** — Schedule workers ahead of time
- **Transfer requests** — Workers can request site transfers (approve/reject flow)
- **Role-based access** — Manager and Supervisor roles with appropriate permissions
- **Responsive UI** — Clean, mobile-friendly interface

## Tech Stack

- **Backend:** PHP 8.0+ (no framework — plain PHP for portability)
- **Database:** SQLite (local) / MySQL (production)
- **Frontend:** Vanilla JavaScript, custom CSS (no framework dependencies)
- **Hosting:** Compatible with Render, Railway, Heroku, traditional LAMP stacks

## Quick Start (Local)

### Option A: WAMP/XAMPP (SQLite — no setup)

1. Drop the project folder into your web server's document root (e.g., `D:\wamp64\www\ConstructionManager`)
2. Start your web server (WAMP/XAMPP)
3. Open `http://localhost/ConstructionManager/` in your browser
4. Log in with default credentials:
   - Manager: `manager` / `admin123`
   - Supervisor: `supervisor` / `super123`

The SQLite database file is created automatically on first request.

### Option B: MySQL (Production)

1. Create a MySQL database
2. Import the schema: `mysql -u root -p < database_mysql.sql`
3. Copy `.env.example` to `.env` and fill in your database credentials
4. Deploy to a PHP host (Render, Railway, etc.)

## Deployment

### Deploy to Render (Free)

1. Push your code to GitHub
2. Sign up at [render.com](https://render.com)
3. Create a new "Web Service" pointing to your repo
4. Set environment variables from your `.env` file
5. Deploy — Render will give you a public URL

See `render.yaml` for the full configuration.

## Project Structure

```
ConstructionManager/
├── config.php              # Database config & bootstrap
├── index.php               # Entry point (redirects to login/dashboard)
├── login.php               # Login screen
├── logout.php              # Logout
├── dashboard.php           # Main dashboard
├── sites.php               # Site list & management
├── site_detail.php         # Individual site details
├── jobs.php                # Job/project management
├── workers.php             # Worker management
├── users.php               # User & role management
├── assignments.php         # Worker assignments
├── attendance.php          # Attendance tracking
├── hours.php               # Work hours entry
├── planning.php            # Future assignment planning
├── transfer_requests.php   # Transfer request flow
├── reports.php             # Reporting
├── report_view.php         # Report detail view
├── database_mysql.sql      # MySQL schema & seed data
├── data/                   # SQLite database files (auto-created)
├── css/                    # Stylesheets
├── js/                     # Client-side scripts
└── sidebar.php             # Shared navigation
```

## Database

The app supports two database modes:

- **SQLite** (default, local development) — A single `data/site_management.db` file is created automatically
- **MySQL** (production) — Set `DB_MODE=mysql` in `.env` and provide connection details

Migrations are handled automatically — schema updates run on each request via the migration system in `config.php`.

## Default Login Credentials

| Role       | Username     | Password   |
|------------|--------------|------------|
| Manager    | `manager`    | `admin123` |
| Supervisor | `supervisor` | `super123` |
| Supervisor | `super2`     | `super123` |

**⚠️ Change these passwords immediately in production.**

## License

MIT License — see LICENSE file.

## Support

For questions or issues, open an issue on GitHub.
