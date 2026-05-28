# Mercor Jobs Scraper → talentsforai.com

Fetches all active Mercor job listings every 4 hours via GitHub Actions,
writes `jobs.json`, and serves them on your WordPress site via shortcode.

---

## Setup (one time)

### 1. Create the GitHub repo

Create a **public** repo: `github.com/levingtn27/mercor-jobs`  
(Public so WordPress can read the raw `jobs.json` without auth)

Push this folder's contents to it.

### 2. Add GitHub Secrets

Go to repo → Settings → Secrets and variables → Actions → New repository secret:

| Secret name | Value |
|---|---|
| `MERCOR_REFRESH_TOKEN` | The long-lived Firebase refresh token from IndexedDB |
| `FIREBASE_API_KEY` | The Web API key from Mercor's Firebase config (see below) |

**How to get the Firebase API key:**
In DevTools Console on work.mercor.com:
```js
fetch('/__/firebase/init.json').then(r=>r.json()).then(d=>console.log(d.apiKey))
```
Or look in the page source for `apiKey:"AIzaSy..."`.

### 3. Update the WordPress plugin

Edit `wp-plugin/mercor-jobs-board.php`, line 18:
```php
define('MERCOR_JOBS_JSON_URL', 'https://raw.githubusercontent.com/levingtn27/mercor-jobs/main/jobs.json');
```
Replace `levingtn27/mercor-jobs` with your actual repo path if different.

### 4. Install the WordPress plugin

Upload `wp-plugin/mercor-jobs-board.php` to `/wp-content/plugins/mercor-jobs-board/`  
(create the folder), then activate it in WP Admin → Plugins.

### 5. Run the scraper once manually

GitHub → Actions → "Mercor Jobs Sync" → Run workflow  
Check the logs — you should see "Done — X active jobs written to jobs.json"

### 6. Add the shortcode to any page

```
[mercor_jobs]
```

**Filtered examples:**
```
[mercor_jobs tab="project-based"]
[mercor_jobs domain="Medical" limit="6"]
[mercor_jobs tab="one-time" limit="12"]
```

---

## How dynamic removal works

Each Action run does a **full replace** of `jobs.json`.  
Mercor's API only returns currently open roles.  
→ If Mercor closes a role, it disappears from the next fetch.  
→ WordPress reads from `jobs.json`, so it vanishes from your site automatically.  
No manual cleanup needed.

---

## Token refresh

Firebase refresh tokens don't expire unless you log out of Mercor.  
The scraper auto-refreshes the short-lived ID token on every run.  
If it ever breaks (you logged out, password change), just grab a new
`MERCOR_REFRESH_TOKEN` from IndexedDB and update the GitHub Secret.

---

## Clear WP cache manually

Visit: `https://talentsforai.com/wp-admin/?mercor_clear_cache=1`
