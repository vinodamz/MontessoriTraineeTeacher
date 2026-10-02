# Google Maps transport setup

The transport server uses Google Routes API Compute Routes with DRIVE and
TRAFFIC_AWARE. Enable routes.googleapis.com in the billing-enabled Maps project.
Geocoding API is useful for address lookup but is not used by this route refresh.

Create a dedicated server key, restricted to Routes API (and Geocoding API if
address lookup is added). Add the hosting server outbound IP restriction once its
actual outbound address is verified; do not assume the website DNS IP is the
outbound IP. Never put the server key in Android, JavaScript or Git.

Save the key in Transport → Setup. Add coordinates to consecutive stops, then
use Refresh travel times on the route. Each refresh makes one billed request per
located consecutive pair. Configure Google API quotas and billing alerts before
regular use. Billing alerts are notifications, not hard spending caps.

The request sends only the two stop coordinates to Google, not child names,
phone numbers or notes. The key goes in the request header, not the URL. Failed
requests preserve saved travel times. Verify the privacy policy covers this
location-processing provider before refreshing real school routes.

This change supplies traffic-aware estimates at refresh time. It does not
reorder stops, add address search, continuously recalculate live traffic, or
replace the current live cab-to-next-stop approximation. Travel estimates remain
approximate, especially when pickup waits or traffic change after refresh.

Tests: php tests/transport_test.php. Official request contract:
https://developers.google.com/maps/documentation/routes/compute_route_directions
