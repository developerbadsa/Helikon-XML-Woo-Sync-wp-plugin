Helikon XML Woo Sync

What it does
- Connects to a password-protected XML URL with optional HTTP Basic Auth
- Validates the XML before changing WooCommerce data
- Processes the feed in locked, resumable batches instead of one long request
- Creates and updates variable products and variations by stable group key and SKU
- Imports featured and gallery images without failing the whole sync when an image breaks
- Supports manual runs, scheduled runs, logs, status, and safe missing-item handling

Safer defaults
- Missing items default to "Do nothing"
- Products are never deleted by default
- Manual sync queues a background-safe batch flow instead of doing everything in wp-admin
- A sync lock prevents overlapping manual and cron runs

Admin settings
- XML URL, username, password, media base URL
- Grouping mode and optional grouping field path
- Variant attribute map
- Price, sale price, stock quantity, and stock status paths
- Schedule, batch size, and missing item action

Included fixture
- `fixtures/sample-feed.xml` can be used as a simple local parsing reference while testing field mappings
