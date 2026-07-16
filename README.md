P7 Carrier Pickup

A WordPress / WooCommerce plugin for scheduling UPS and PPL package pickups fast, from a single screen. It counts the labels created since your last pickup, works out the total weight, and books the pickup with each carrier — in one click.

It does not create shipping labels. It reads the tracking numbers your existing shipping tools already produce (UPS DashboardLink notes, the PPL WooCommerce plugin's package table) and turns them into a scheduled collection.


Features


One-click pickups for UPS and PPL from one page.
Counts since the last pickup, per carrier and per location — so labels created across a weekend all get collected on the next run. Optional manual "count since" override and a per-location "reset to now" button.
Real package weights, summed from WooCommerce product weights plus a configurable packaging weight per parcel.
Multiple pickup locations with per-admin attribution: whoever creates an order's invoice determines which location that order's parcels are counted toward.
PPL via the official CPL (myapi2) API — collections are created with the exact registered sender address, fetched straight from your PPL account, so pickups schedule at the right depot.
UPS via the UPS Pickup API (OAuth 2.0) — create and cancel pickups.
Live preview of what's waiting at each location before you schedule.
Cancel scheduled pickups for either carrier.
Optional logging to WooCommerce → Status → Logs for troubleshooting.
One-click updates from a GitHub repository (bundles the Plugin Update Checker library).
No credentials or business details in the code — everything is entered in Settings.



Requirements


WordPress 6.2+ and WooCommerce 7.0+ (HPOS compatible).
A UPS developer account (Client ID / Secret / account number) for UPS pickups.
PPL CPL API credentials (myapi2 scope) for PPL pickups.
The shipping tools that generate your labels (e.g. UPS DashboardLink, the PPL CZ WooCommerce plugin). The plugin reads their output; it doesn't replace them.
Optional: the Toret Fakturoid plugin, if you want per-admin location attribution based on who creates each invoice.



Installation


Download the latest release ZIP.
In WordPress, go to Plugins → Add New → Upload Plugin, choose the ZIP, and activate.
Open Carrier Pickup → Settings and complete the configuration below.



Configuration

Do these once, in order:


Location details — fill in your pickup locations (nickname, company, contact, address, phone, email). Nothing is pre-filled, so the plugin ships with no personal data.
UPS API — Client ID, Client Secret, account number, and environment (production / test). Credentials can instead be defined as constants in wp-config.php (UPS_CLIENT_ID, UPS_CLIENT_SECRET, UPS_ACCOUNT_NUMBER).
PPL API — Client ID, Client Secret, scope (usually myapi2), and environment. Constants: P7CP_PPL_CLIENT_ID, P7CP_PPL_CLIENT_SECRET.
PPL pickup addresses — click Refresh addresses from PPL to load your registered collection addresses, then assign one to each location. Saving this also fills in each location's address fields (so UPS uses the same address).
Who ships from where — map each administrator to a location, and pick the default location for anything unattributed.



Usage

Open Carrier Pickup. The Pending since last pickup table shows how many UPS and PPL parcels are waiting at each location, and their weight. Choose a date and location, tick the carriers, and click Schedule Pickup. Each successful pickup records a timestamp, so the next run only counts what's new.

Use Reset to now on a location to set a fresh baseline (handy the first time). Active pickups can be cancelled from the same page.

Turn on Logging in Settings while troubleshooting — pickup counts, weights, and the raw carrier API responses are written to WooCommerce → Status → Logs (source p7-carrier-pickup).


How it works


UPS counts come from the 1Z… tracking numbers in WooCommerce order notes, filtered to those created after the last pickup.
PPL counts come from the PPL WooCommerce plugin's package table, filtered the same way.
Weight is the sum of each order's product weights (converted to kg) plus a packaging allowance per parcel.
Attribution is captured when an admin creates an order's invoice (Fakturoid); that admin's mapped location is where the order's parcels are counted. Anything unattributed falls to the configured default location.



Updates

The plugin checks a GitHub repository for new releases and offers them through the normal WordPress update flow — no manual re-upload.

Set the repository under Settings → Updates (or via wp-config.php):

phpdefine( 'P7CP_GITHUB_REPO', 'https://github.com/OWNER/REPO' );
// For a private repo only:
define( 'P7CP_GITHUB_TOKEN', 'github_pat_...' );

Public repositories need no token. To publish an update: bump the Version: header and the P7CP_VERSION constant in the main file, commit, and create a GitHub Release whose tag matches the new version.


Credits & license

Bundles the Plugin Update Checker library by Yahnis Elsts (MIT).

Licensed under the GNU General Public License v2.0 or later.
