# OIDC — OpenID Connect Single Sign-On for Omeka S

OpenID Connect (OIDC) Single Sign-On module for Omeka S. Designed primarily for **CILogon** (US) and **AAF CILogon** (Australia), but works against any standards-compliant OIDC identity provider.

Users sign in with their existing institutional credentials. Omeka accounts are created on first login (just-in-time provisioning) and roles are assigned automatically based on group membership.

---

## Features

- OIDC Authorization Code flow + PKCE
- Just-in-time user provisioning from claims
- Group-based role mapping (e.g. `omeka-instance-a-editor` → `editor`)
- Per-instance access guard
- RP-initiated logout
- Blocks password reset and password change for OIDC-managed accounts

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ≥ 8.1 |
| Omeka S | ^4.0.0 |
| HTTPS in production | required by all real IdPs |

---

## Installation

### Option A — Drop-in zip (recommended for production servers)

1. Download or build the module zip 
2. Extract into Omeka's `modules/` directory:
   ```
   omeka-s/modules/Oidc/
   ```
3. In Omeka admin → Modules, find **OIDC** and click **Install**.
4. Click **Configure** to set up your IdP (see "Configuration" below).

### Option B — From source (clone + Composer)

```bash
cd omeka-s/modules
git clone <repo-url> Oidc
cd Oidc
composer install --no-dev
```

Then activate via Omeka admin → Modules → Install.

---

## Configuration overview

After install, go to **Admin → Modules → OIDC → Configure**. The settings split into three groups:

### 1. Identity provider connection

| Field | Description |
|---|---|
| **IdP discovery URL** | The OIDC discovery document URL — usually `<issuer>/.well-known/openid-configuration`. The module fetches all endpoints from here. |
| **Client ID** | Issued by the IdP when you register your client. |
| **Client Secret** | Issued by the IdP. Treat as sensitive. Leave blank in the config form to keep the existing stored value. |
| **Scopes** | Space-separated. Must include `openid`. For CILogon, add `org.cilogon.userinfo` to receive group membership. Example: `openid email profile org.cilogon.userinfo` |

### 2. Access control & roles

| Field | Description |
|---|---|
| **Role claim** | Name of the claim carrying group memberships. CILogon: `isMemberOf`. Dex: `groups`. AAF: varies — check the actual claims (see "Debugging claims"). |
| **Roles map** | Lines like `<group-substring> = <omeka-role>`. Substring is matched anywhere within a claim value, so `omeka-instance-a-editor` matches the COU path `CO:COU:omeka-instance-a-editor:members:active`. |
| **Default role** | Fallback role when no roles_map entry matches. Leave blank to **deny** unmapped users. |
| **Access-guard claim** / **Access-guard value** | **Optional gate** — restricts who can sign in. Both fields must be set together for the gate to activate; leave both blank to disable it. A partially configured pair is rejected. Set them only if you want to limit access to a specific institution, project group, or affiliation. 

**Allowed Omeka role IDs:** `global_admin`, `site_admin`, `editor`, `reviewer`, `author`, `researcher`. Note that `site_admin` is displayed as **"Supervisor"** in the Omeka UI but the internal ID is `site_admin`.

### 3. UI / behaviour

| Field | Description |
|---|---|
| **Hide local login form** | Hides the username/password fields on the standard login page. The local form is still reachable as a super-admin fallback (the URL works, the fields are CSS-hidden). |
| **Post-login redirect** | Where to send users after successful login. Use `home`, a site-relative path like `/items`, or an HTTP(S) URL on the same origin. |
| **Claims to display** | Lines like `<claim-name> = <label>`. Stored on every login as user-settings. Empty label = stored but not shown in the user admin UI. |

---
 
### Production with CILogon

**Step 1 — Register your OIDC client at CILogon** (https://cilogon.org/oauth2/register):

| Field | Value |
|---|---|
| Client Name | descriptive, e.g. `My Omeka Site` |
| Contact Email | admin email |
| Home URL | `https://your-domain/path-to-omeka/` |
| Callback URL | `https://your-domain/path-to-omeka/oidc/callback` |
| Client Type | Confidential |
| Scopes | `openid`, `email`, `profile`, `org.cilogon.userinfo` |
| Refresh tokens | not required |

CILogon **manually approves** registrations. Approval takes 1–7 business days. Non-commercial research use; they coordinate Australian use through AAF and prefer registrations from a university-affiliated applicant.

**Step 2 — Set up groups in COmanage** (https://registry.cilogon.org):

You need a CO (Collaborative Organization). If your institution doesn't have one, email `help@cilogon.org`. Inside the CO, create COUs whose names match your roles map, then enroll users into the right COUs.

Example COUs:
```
omeka-instance-a-supervisor
omeka-instance-a-editor
omeka-instance-a-reviewer
omeka-instance-a-author
omeka-instance-a-researcher
```

**Step 3 — Configure the module:**

| Field | Value |
|---|---|
| IdP discovery URL | `https://cilogon.org/.well-known/openid-configuration` |
| Client ID | *(from CILogon approval email)* |
| Client Secret | *(from CILogon approval email)* |
| Scopes | `openid email profile org.cilogon.userinfo` |
| Role claim | `isMemberOf` |
| Roles map | one line per COU → role mapping |
| Default role | blank (recommended) — denies users with no mapped COU |
| Access-guard claim | `isMemberOf` |
| Access-guard value | `omeka-instance-a` (or whatever prefix you chose) |
| Hide local login | checked |




## Login flow

```
1. User clicks "Sign in with CILogon" on the login page
   ↓
2. Module redirects to the IdP's authorize endpoint
   (state, nonce, PKCE saved in session)
   ↓
3. User authenticates with their institution / Google / ORCID / etc.
   ↓
4. IdP redirects back to /oidc/callback with an authorization code
   ↓
5. Module exchanges the code for tokens (server-to-server)
   ↓
6. Module fetches userinfo and merges with id_token claims
   ↓
7. Access guard check (if configured)
   ↓
8. Role resolved from claims via roles_map / default
   ↓
9. Session ID is regenerated; login stops if rotation fails
   ↓
10. User looked up by sub+iss → found: sync; not found: provision
   ↓
11. Identity written to Omeka's auth storage → user is logged in
   ↓
12. Redirect to post-login URL
```

---

## How accounts are managed

- **Identity:** stable composite key `sub` + `iss` from the IdP. Email changes don't break the link.
- **Provisioning:** new user created on first login with a random 32-byte password (never disclosed — the account is OIDC-only).
- **Sync on every login:** name, email, and role are re-derived from claims and updated if changed. Manual role overrides in Omeka admin will be **overwritten** on next login.
- **Local password protection:**
  - Forgot-password flow blocked for OIDC users on the standard login page.
  - Change-password fieldset hidden and blocked on the user edit page.
- **Logout:** clears the local session, then redirects to the IdP's `end_session_endpoint` (if advertised) so the user is signed out everywhere.

---

## Routes

| URL | Purpose |
|---|---|
| `/oidc/login` | Start the OIDC flow (this is what the "Sign in" button hits) |
| `/oidc/callback` | IdP redirects back here after authentication — must match the registered Callback URL exactly |
| `/oidc/logout` | RP-initiated logout (called automatically when an OIDC-managed user uses Omeka's standard `/logout`) |

---

## License

GPL-3.0-or-later. See module.ini.
