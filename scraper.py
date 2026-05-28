#!/usr/bin/env python3
"""
Mercor Jobs Scraper
Refreshes Firebase token, fetches all listings across all tabs,
outputs jobs.json for talentsforai.com
"""

import os
import json
import requests
from datetime import datetime, timezone

# ── Config (all come from GitHub Secrets / env vars) ──────────────────────────
FIREBASE_API_KEY  = os.environ["FIREBASE_API_KEY"]     # Web API key from Mercor's app
REFRESH_TOKEN     = os.environ["MERCOR_REFRESH_TOKEN"] # Long-lived Firebase refresh token

LISTINGS_URL      = "https://aws.api.mercor.com/work/listings-explore-page"
TOKEN_URL         = f"https://securetoken.googleapis.com/v1/token?key={FIREBASE_API_KEY}"
OUTPUT_FILE       = "jobs.json"

TABS = ["project-based", "one-time"]

HEADERS_BASE = {
    "accept": "application/json, text/plain, */*",
    "origin": "https://work.mercor.com",
    "referer": "https://work.mercor.com/",
    "user-agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36",
    "x-client-ip": "true",
}

# ── Step 1: Refresh the Firebase ID token ────────────────────────────────────
def get_fresh_token():
    print("Refreshing Firebase token...")
    resp = requests.post(TOKEN_URL, json={
        "grant_type": "refresh_token",
        "refresh_token": REFRESH_TOKEN,
    })
    resp.raise_for_status()
    data = resp.json()
    id_token = data.get("id_token")
    if not id_token:
        raise ValueError(f"Token refresh failed: {data}")
    print("Token refreshed OK")
    return id_token

# ── Step 2: Fetch listings for a given tab ────────────────────────────────────
def fetch_tab(id_token: str, tab: str) -> list[dict]:
    headers = {**HEADERS_BASE, "authorization": f"Bearer {id_token}"}
    params  = {"tab": tab}
    print(f"Fetching tab: {tab}...")
    resp = requests.get(LISTINGS_URL, headers=headers, params=params, timeout=30)
    
    if resp.status_code == 401:
        raise RuntimeError("Auth failed — refresh token may be revoked")
    resp.raise_for_status()
    
    data = resp.json()
    listings = data.get("listings", [])
    print(f"  → {len(listings)} listings")
    return listings

# ── Step 3: Normalize a raw listing into a clean job object ──────────────────
def normalize(listing: dict, tab: str) -> dict | None:
    # Skip if applications disabled
    if listing.get("disableApplications"):
        return None

    title = listing.get("title") or listing.get("listingDomain") or "Untitled Role"
    listing_id = listing.get("listingId", "")
    commitment = listing.get("commitment", "hourly")  # "hourly" or "one-time"

    # ── Pay display ──────────────────────────────────────────────────────────
    # Hourly: hourlyPayRate / hourlyPayRateMin / hourlyPayRateMax
    # One-time: totalPayRate / totalPayRateMin / totalPayRateMax (shown as $1.1K–$1.4K)
    if commitment == "one-time":
        pay_min = listing.get("totalPayRateMin") or listing.get("totalPayRate")
        pay_max = listing.get("totalPayRateMax")
        suffix  = "/ one-time"
    else:
        pay_min = listing.get("hourlyPayRateMin") or listing.get("hourlyPayRate")
        pay_max = listing.get("hourlyPayRateMax")
        suffix  = "/ hr"

    def fmt_pay(val):
        if val is None: return None
        val = float(val)
        return f"${val/1000:.1f}K" if val >= 1000 else f"${int(val)}"

    if pay_min and pay_max and pay_min != pay_max:
        pay_display = f"{fmt_pay(pay_min)} – {fmt_pay(pay_max)} {suffix}"
    elif pay_min:
        pay_display = f"{fmt_pay(pay_min)} {suffix}"
    else:
        pay_display = listing.get("payRateDisplay") or "Pay not listed"

    # ── Status badges ─────────────────────────────────────────────────────────
    # "New opportunity" — shown when isMostRecent=true or isNew=true
    is_new = bool(listing.get("isNew") or listing.get("isMostRecent"))
    # "Closing soon" — shown when closingSoon=true or isClosingSoon=true
    closing_soon = bool(listing.get("closingSoon") or listing.get("isClosingSoon") or listing.get("isClosingSoon"))

    # ── Referral link ─────────────────────────────────────────────────────────
    referral_link = (
        listing.get("referralLink")
        or listing.get("referralShortLink")
        or listing.get("referralUrl")
        or f"https://work.mercor.com/explore?listingId={listing_id}"
    )
    referral_bonus = listing.get("referralBonusAmount")

    # ── Location eligibility ──────────────────────────────────────────────────
    eligible = listing.get("eligibleLocation") or listing.get("eligibleCountries") or []
    if isinstance(eligible, str):
        eligible = [eligible]

    return {
        "id":             listing_id,
        "title":          title,
        "domain":         listing.get("listingDomain", ""),
        "pay":            pay_display,
        "commitment":     commitment,
        "tab":            tab,
        "location":       listing.get("location") or "Remote",
        "eligible":       eligible,
        "hired_count":    listing.get("hiredThisMonth") or listing.get("hiredCount"),
        "is_new":         is_new,
        "closing_soon":   closing_soon,
        "referral_link":  referral_link,
        "referral_bonus": referral_bonus,
        "listing_url":    f"https://work.mercor.com/explore?listingId={listing_id}",
        "created_at":     listing.get("createdAt"),
    }

# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    id_token = get_fresh_token()

    all_jobs = []
    for tab in TABS:
        try:
            raw_listings = fetch_tab(id_token, tab)
            for listing in raw_listings:
                job = normalize(listing, tab)
                if job:
                    all_jobs.append(job)
        except Exception as e:
            print(f"Warning: failed to fetch tab '{tab}': {e}")

    # Deduplicate by listing ID (same role can appear in multiple tabs)
    seen = set()
    unique_jobs = []
    for job in all_jobs:
        if job["id"] not in seen:
            seen.add(job["id"])
            unique_jobs.append(job)

    output = {
        "updated_at": datetime.now(timezone.utc).isoformat(),
        "total":      len(unique_jobs),
        "jobs":       unique_jobs,
    }

    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(output, f, indent=2, ensure_ascii=False)

    print(f"\nDone — {len(unique_jobs)} active jobs written to {OUTPUT_FILE}")

    # Print a quick summary
    tabs_summary = {}
    for job in unique_jobs:
        tabs_summary[job["tab"]] = tabs_summary.get(job["tab"], 0) + 1
    for tab, count in tabs_summary.items():
        print(f"  {tab}: {count}")

if __name__ == "__main__":
    main()
