# FPDF 1.9

Vendored, unmodified, from http://www.fpdf.org (Olivier Plathey).

FPDF is released under a permissive licence: "You may use it for any kind of
usage and modify it to suit your needs, provided that you keep the notice in
the source." The notice at the top of `fpdf.php` is intact.

Why vendored rather than installed: this app has no Composer step and deploys
by rsyncing source to shared hosting, so dependencies have to live in the repo.
FPDF is a single 51 KB file with no dependencies of its own — the JSON files in
`font/` are the core-font metrics it needs.

It lives under `includes/` because the root `.htaccess` blocks that path from
direct web access.

Used by `includes/child_report_pdf.php` to generate the child progress report.
