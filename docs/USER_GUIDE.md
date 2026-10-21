# Eminent Factory ERP — User Guide

This guide explains how to use the parts of the ERP that are currently available:

1. [Signing in and your account](#1-signing-in-and-your-account)
2. [Dashboard](#2-dashboard)
3. [Factory Setup](#3-factory-setup) — Structure Overview, Factories, Departments, Storage Locations, Types
4. [System Settings](#4-system-settings) — Company Settings, Users, Roles & Permissions, Activity Logs
5. [First-time setup: recommended order](#5-first-time-setup-recommended-order)
6. [Rules that protect your data](#6-rules-that-protect-your-data)
7. [Common questions](#7-common-questions)

> **What you see depends on your role.** The sidebar only shows the menus your role allows, and
> buttons such as *Add*, *Edit*, *Deactivate* or *Delete* only appear if you have permission for
> them. If something described here is missing for you, ask an administrator to check your role.

---

## 1. Signing in and your account

### Signing in
1. Open the ERP address in your browser.
2. Enter your **work email** and **password**, then click **Sign in**.
3. Tick **Keep me signed in** only on your own computer.

- There is no self-registration. **Accounts are created by an administrator** (see [Users](#42-users)).
- Your session ends after about **2 hours without activity**; sign in again when asked.
- If your account is deactivated, you are signed out and cannot sign in until it is reactivated.
- Repeated wrong passwords are slowed down automatically for security.

### First sign-in (new users)
When an administrator creates your account, you receive an **email with a link to set your
password**. Open it, choose a password, and sign in. The link is valid for **60 minutes**; if it
has expired, ask the administrator to use **Send password reset**.

### Forgot your password
On the sign-in page click **Forgot password?**, enter your email, and follow the link in the email
to choose a new password.

### Password rules
At least **10 characters**, with **upper- and lower-case letters**, a **number** and a **symbol**.

### Your profile and password
Click your name in the top-right corner:
- **My Profile** — change your first name, last name and mobile number. Your email and roles can
  only be changed by an administrator.
- **Change Password** — enter your current password and the new one twice. Changing your password
  **signs you out of all other devices**.
- **Logout** — ends your session.

### The sidebar
- Click a menu heading (for example **FACTORY SETUP**) to close or open its list of pages. The arrow
  next to the heading shows whether it is open (▾) or closed (▸).
- The page you are on is highlighted.
- On a phone or tablet, open the menu with the ☰ button at the top-left.

---

## 2. Dashboard

The first page after signing in.

- A greeting and your company name.
- Three summary cards: **Active users**, **Inactive users** and **Roles**. Click a card to open the
  matching list (if you have access to it).
- **Recent administrative activity** — the latest sign-ins and changes, for users who may view the
  activity log. Click **View all** to open [Activity Logs](#44-activity-logs).

If your role has no dashboard access, you see a welcome message instead; use the sidebar to open
the pages assigned to you.

---

## 3. Factory Setup

Factory Setup describes **how the company is organised**: its factories (sites), the departments
inside each factory, and the storage locations where material is kept.

### 3.1 Structure Overview
A read-only map of the whole company: **Company → Factories → Departments and Storage locations**.

- The cards at the top show how many factories, departments and locations exist.
- Click the arrow next to a factory to expand or collapse it.
- Departments show their manager and supervisor; storage locations are shown as a tree
  (for example Zone → Rack → Shelf → Bin).
- **Search** finds a factory, department or location by code or name; the **Status** filter shows
  only active items, or only branches containing inactive items.
- **Export CSV** downloads the whole structure (if you have export permission).

### 3.2 Factories
A factory is a physical site operated by the company.

**List:** search by code, name or city; filter by type and status; sort by clicking column
headings. Icons on each row: view, edit, activate/deactivate.

**Add a factory** (**Add factory**):
| Field | Notes |
|---|---|
| Factory code | Required, short and unique within the company (e.g. `PUNE`). |
| Factory name | Required. |
| Factory type | Manufacturing, Warehouse (a storage site) or Other. |
| Address, contact person, contact number, email | Optional. |
| Active | Inactive factories cannot receive new departments, locations or user assignments. |

**Factory details page** shows the factory's information, an "At a glance" summary (departments,
storage locations, assigned users), its **Departments** and its **Storage locations**, with
buttons to add either directly for this factory.

**Deactivate / Activate:** use the power icon. Departments and locations are not changed when a
factory is deactivated, but nothing new can be added to an inactive factory. Factories are not
deleted.

### 3.3 Departments
Departments are organisational units inside a factory (e.g. Casting, Machining, Quality Control).

**List:** search by code or name; filter by factory, type and status. Row icons:
👁 view · 👥 employees · ✏️ edit · ⏻ activate/deactivate · 🗑 delete (Admin only).

**Add a department** (**Add department**):
| Field | Notes |
|---|---|
| Factory | Required. Only active factories can be chosen. |
| Department code | Required, unique within the factory. |
| Department name | Required. |
| Department type | Optional, from the list maintained under [Types](#35-types). |
| Description | Optional. |
| Department manager / supervisor | Optional. Only active users can be selected. |
| Active | — |

A department cannot be moved to another factory after it is created.

**Employees of a department** (👥 icon or **Employees** on the details page):
- **Add employee** — pick a user; they become a member of the department.
- **Remove from department** — takes the user out of the department (the user account is not
  affected).
- This only records *where* people work. *What* they may do is controlled by their roles.

**Manager & supervisor:** set from the department form or the **Manager & supervisor** page.
Both are optional; only active users can be assigned.

**Deactivate:** the department stays in all records and assigned employees keep their assignment,
but it can no longer be chosen for new work. This is the normal way to retire a department.

**Delete (Admin only):** 🗑 → confirm. Deletion is permanent and is only allowed when nothing uses
the department. If it is still in use, a message lists what refers to it, for example:

> *Quality Control cannot be deleted because it is still used by: 1 employee assignment(s)
> (Departments → Employees). Reassign the employees first, or deactivate the department instead.*

- Remove or move the listed employees, then try again; or
- if the message lists saved records (machines, processes, routings or stock documents kept from
  earlier modules), **deactivate** the department instead — those records are preserved.

### 3.4 Storage Locations
Storage locations are the places inside a factory where material is kept — zones, rooms, racks,
shelves, bins and floor areas. They can be nested (for example *Zone A → Rack A1 → Shelf A1-03*).
Locations are configuration only; they do not hold stock quantities.

**Table view:** search by code or name; filter by factory, type, status and virtual/physical.
Switch between **Table** and **Tree** with the buttons at the top of the page.

**Tree view:** choose a factory to see its full location hierarchy. Use **+** on a location to add
a child location inside it.

**Add a location** (**Add location**):
| Field | Notes |
|---|---|
| Factory | Required. Cannot be changed later. |
| Parent location | Optional. Leave empty for a top-level location. Up to 6 levels of nesting. |
| Location code | Required, unique within the factory (e.g. `RACK-A`, `A1-03`). Letters are upper-case. |
| Location name | Required. |
| Location type | Zone, Room, Rack, Shelf, Bin, Floor Area or Other. |
| Description | Optional. |
| Virtual location | For places that are not physical (e.g. "in transit"). |
| Pickable / Receivable | Whether material may be issued from / received into this place. |
| Active | — |

**Deactivate:** deactivate child locations first. A location can only be reactivated when its
parent is active.

**Delete:** only possible for a location created by mistake — one with no child locations and not
used anywhere. Otherwise deactivate it.

### 3.5 Types
The list of **department types** offered when creating departments (e.g. Casting, Machining,
Quality Control). Types are suggestions only — no departments are created from them.

- **Add type** — name, description, sort order (1, 2, 3 … decides the order in dropdowns), active.
- **Edit** — change any of these.
- A type that is in use can be deactivated but not deleted; the "In use" column shows how many
  departments use it.

---

## 4. System Settings

### 4.1 Company Settings
The legal and regional details of the operating company, used across the ERP.

| Section | Fields |
|---|---|
| Company identity | Company name, legal company name, company code, logo (upload or **Remove logo**), Active |
| Contact | Email, phone number, website |
| Registered address | Address lines, city, state, postal code, country |
| Tax & registration | GSTIN, PAN, CIN (optional) — checked for the correct Indian format |
| Regional & financial | Timezone, default currency, financial year start month (the end month is set automatically) |

Click **Save changes**. The operating company must stay active.

### 4.2 Users
Everyone who can sign in to the ERP.

**List:** search by name, email or employee code; filter by role and status; sort by column.
Row icons: view · edit · send password reset · activate/deactivate · delete.

**Add a user** (**Add user**):
1. Enter first name, last name, email (must be unique), optional employee code and mobile number.
2. Tick one or more **Roles** — the user's permissions come from their roles.
3. Leave **Active account** on and save.
4. The user receives an **email with a link to set their password** (see
   [First sign-in](#first-sign-in-new-users)). If the email could not be sent, use
   **Send password reset** later.

**User details page:**
- **Account** information and last login.
- **Assigned roles**.
- **Factory & department** — click **Change** to set the user's *primary factory*, *primary
  department* and any *additional departments*. This decides which factories' data the user can
  see (unless their role allows access to all factories).
- **Recent changes to this user** (from the activity log).

**Send password reset:** emails the user a link to choose a new password (the user must be active).

**Deactivate / Activate:** a deactivated user is signed out everywhere and cannot sign in. Their
history is kept. This is the normal way to remove someone's access.

**Delete:** permanently removes the account after confirmation. Not allowed when:
- it is your own account;
- the user is the last active administrator;
- the user is a department manager or supervisor, a storage-area manager, the approver of
  engineering documents, or the author of posted stock transactions.
The message tells you which; reassign those first, or deactivate the user instead.

**Safety rules:** you cannot change your own roles, cannot give someone permissions you do not have
yourself, and the last administrator cannot be deactivated or lose the Admin role.

### 4.3 Roles & Permissions
A **role** is a named set of permissions (for example *Factory Manager*). Users get permissions only
through their roles.

**Roles list:** shows each role, whether it is a **Core** role (created with the system) or
**Custom**, its status, and how many users and permissions it has.

Core roles: **Admin**, **Factory Manager**, **Store Keeper**, **Accountant**, **QC**.
- **Admin** always holds every permission; its permissions cannot be edited and it cannot be
  deactivated.
- Core roles cannot be renamed or deleted.

**Add role** — name, description, Active. Users of an inactive role do not receive its permissions.

**Role details** — description, status, its permissions, and the **users with this role**.

**Permissions (the matrix):** open a role and click **Permissions**.
- Each row is a module (e.g. *Departments*), each column an action (*view, create, update,
  deactivate, delete, …*). Tick the boxes to grant.
- **Toggle all in row** grants or removes every action of a module.
- Cells marked *Not applicable* do not exist for that module; rows marked *Future module* are
  reserved for modules not built yet.
- Click **Save permissions** (or **Discard** to undo unsaved changes).
- Important factory permission: **Factories → access-all** lets a user see *every* factory.
  Without it, a user only sees the factories/departments they are assigned to (see Users → Factory
  & department).

**Delete role:** only custom roles with no users assigned. Reassign the users first.

### 4.4 Activity Logs
A read-only audit trail of sign-ins and administrative changes (who did what, and when).

- Columns: **When**, **Actor** (the user, or *System / guest*), **Action**, **Entity**,
  **Description**.
- Filters: search text, action type, from date, to date; **Reset** clears them.
- Click a row's expand arrow to see details: the exact changes, IP address and browser
  (user agent). Passwords and other secrets are never recorded.

---

## 5. First-time setup: recommended order

1. **System Settings → Company Settings** — fill in the company details and logo.
2. **System Settings → Roles & Permissions** — review the core roles; create custom roles if
   needed and set their permissions.
3. **Factory Setup → Types** — adjust the department types to match your company.
4. **Factory Setup → Factories** — add each factory/site.
5. **Factory Setup → Departments** — add the departments of each factory.
6. **Factory Setup → Storage Locations** — add zones, racks, shelves and bins of each factory.
7. **System Settings → Users** — add users, assign roles, then set their factory & department.
8. **Factory Setup → Departments → Employees / Manager & supervisor** — assign people.
9. Check **Factory Setup → Structure Overview** to confirm everything looks right.

---

## 6. Rules that protect your data

- **Deactivate rather than delete.** Factories are never deleted; departments, locations, users and
  roles can only be deleted when nothing depends on them. Deactivation keeps history intact.
- **Inactive items can't be used for new work** (new departments in an inactive factory, new
  locations under an inactive parent, selecting inactive users as managers, and so on).
- **Data scope:** users without *Factories → access-all* only see the factories they are assigned
  to. Records of other factories are reported as "not found".
- **Every important change is logged** in Activity Logs.
- **Permissions are checked by the server**, not only by hiding buttons.

---

## 7. Common questions

**I can't see a menu or button.** Your role doesn't include that permission. Ask an administrator
to check **Roles & Permissions** for your role.

**A new user didn't receive the email.** Check the address, then use **Send password reset** on
the user. Links expire after 60 minutes.

**"… cannot be deleted because …"** — something still uses the record. Follow the message (reassign
the listed items) or deactivate the record instead.

**I don't see some factories.** Your user is assigned to specific factories only. An administrator
can add your factory/department under **Users → Factory & department**, or give your role
*Factories → access-all*.

**A department/location dropdown doesn't list an item.** Inactive items are not offered for new
work. Activate it first if it is still needed.

**I was signed out unexpectedly.** Sessions end after about 2 hours of inactivity, after a password
change on another device, or when an administrator deactivates your account.
