## Estate Management System – Development TODO

- **Phase 1 – Product definition & roles**
  - [ ] Define user roles (e.g. Super Admin, Estate Admin/Owner, Property Manager, Tenant, Staff/Security).
  - [ ] Map features/modules from `readMe.md` to each role (who can see/do what).
  - [ ] Decide backend stack and architecture (plain PHP vs Laravel/other framework) on XAMPP.

- **Phase 2 – Marketing landing page (modern, effects, transitions)**
  - [x] Create a separate landing page file (e.g. `landing.html` or `public/index.html`) independent from the admin template.
  - [x] Implement hero section (headline, subtext, primary/secondary CTAs, product mockup/illustration, gradient background).
  - [x] Add smooth entry animations and hover micro-interactions (buttons, cards, icons).
  - [ ] Add “Problems we solve” section (manual processes: WhatsApp, Excel, paper files, etc.).
  - [ ] Add “Core Modules” section as responsive card grid (10 modules from `readMe.md` with icons and short copy).
  - [ ] Add “Advanced Features” highlight section (predictive rent forecasting, onboarding, app, AI complaints, wallet).
  - [ ] Add “Who it’s for” section (estate developers, agencies, facility managers, gated communities, etc.).
  - [x] Add simple pricing / revenue model section (sample plans or per-estate pricing).
  - [x] Add final CTA + contact section (demo form + quick contact/WhatsApp).
  - [x] Ensure accessibility (good contrast, keyboard focus, respect `prefers-reduced-motion` for animations).

- **Phase 3 – Role-based admin areas (using `pages/` Keen template)**
  - [x] Plan separate dashboards for key roles:
    - [x] Super Admin: global metrics for all estates, users, revenue, maintenance.
    - [x] Estate Admin/Owner: metrics for their estate(s) only (occupancy, rent, complaints, service charges).
    - [x] Tenant: next rent due, balances, payments, complaints, announcements.
    - [x] Staff/Security: visitor logs, gate passes, alerts.
  - [x] In `pages/`, create role-specific dashboard files (e.g. `pages/admin/dashboard.html`, `pages/estate/dashboard.html`, `pages/tenant/dashboard.html`, `pages/staff/dashboard.html`) by copying a suitable Keen demo page.
  - [x] For each dashboard, replace the content area with cards/tables/charts relevant to that role.
  - [x] Customize the sidebar/menu per role to only show allowed sections.

- **Phase 4 – Feature pages (CRUD + workflows)**
  - [x] Design database schema (estates, properties, units, tenants, leases, invoices, payments, maintenance_tickets, announcements, vendors, etc.).
  - [x] Implement Property & Unit Management (CRUD + occupancy/vacancy views).
  - [x] Implement Tenant & Lease Management (onboarding, agreements, status).
  - [ ] Implement Digital Rent & Service Charge Management (invoices, reminders, payment status).
  - [ ] Implement Maintenance & Facility Management (ticket creation, assignment, status tracking, cost, vendor logs).
  - [ ] Implement Communication & Announcement Hub (estate-wide messages, emails/SMS integration hooks).
  - [ ] Implement Reports & Dashboards (per estate and global, using Keen charts/tables).

- **Phase 5 – Integrations, auth & polishing**
  - [ ] Implement authentication and role-based authorization (redirect to correct dashboard after login).
  - [ ] Implement multi-estate/multi-branch support (scope all queries by `estate_id` and user role).
  - [ ] Integrate payments (Paystack/Flutterwave) for rent and service charges.
  - [ ] Add audit trails/logs for key operations (payments, allocations, role changes).
  - [ ] Optimize UI/UX for speed and clarity (simplify screens, consistent components).
  - [ ] Test flows for each role end-to-end (Super Admin, Estate Admin, Tenant, Staff).
  - [ ] Prepare for deployment and versioning (config, environment separation, backups).

