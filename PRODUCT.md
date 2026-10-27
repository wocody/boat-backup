# Boat Backup & Migration

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

WordPress administrators exporting a site and restoring it on another installation.

## Product Purpose

Local full-site migrations with no commercial size quota. Success is an intact database and content on the destination, with recoverable interrupted operations.

## Stack

PHP 7.4+, WordPress 6.0+, native WordPress administration, local JavaScript and CSS, ZIP-based versioned .wpboat packages.

## Capabilities and Constraints

Single-site exports, uploads, backups and restoration. Restore source users while retaining destination URL and database configuration. No multisite, cloud, schedules or import of packages from other plugins in v0.3.0. Native SQL dumps with automatic database compatibility adaptation. Only current package format 4 is accepted; no legacy formats are kept during development. Private storage and 64-bit PHP with mysqli and ZipArchive are required.

## Brand Commitments

Author: Wocody. Name: Boat Backup & Migration. The approved interface belongs inside the existing WordPress dashboard and inherits its controls, typography and accessibility conventions.

## Product Principles

- Validate before changing the destination.
- Make progress, interruptions and recovery visible.
- Keep backups and credentials private.
- Preserve the existing site during development; use disposable integration sites.

## Open publication details

Wocody's WordPress.org account and public URLs will be supplied before submission.
