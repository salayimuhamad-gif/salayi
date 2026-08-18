Mulkihawler 4.0 — Hostinger deployment package

  application/   -> /home/ACCOUNT/application   (OUTSIDE public_html)
  public_html/   -> /home/ACCOUNT/public_html

This package DOES contain application/vendor/ — production-only dependencies,
resolved from the committed composer.lock and installed with --no-dev. You do
NOT need to run Composer on the server, and shared hosting usually cannot.

Requirements: PHP 8.3 with bcmath, ctype, curl, dom, fileinfo, filter, hash,
intl, json, libxml, mbstring, openssl, pcre, pdo, pdo_mysql, session, tokenizer,
xml and zip. MySQL 8.0 or MariaDB 10.6+.

Composer is needed only for a DEVELOPER build from the clean-source archive
(`composer install`), never for deploying this package.

.env is deliberately excluded: it holds credentials and an APP_KEY that must
never be shared between installs. The guided web installer at /install writes it.

Layout: upload the contents of application/ outside the web root, and the
contents of public_html/ into the web root. public_html/index.php resolves the
application path; do not move one without the other.

Full instructions, including the upgrade sequence: application/docs/HOSTINGER_DEPLOYMENT.md

Do not upload a .env file. The installer writes it.
