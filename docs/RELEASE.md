# Submission preparation

The initial release is local; no WordPress.org account, remote Git repository, directory submission or publishing action was created.

Before submitting, supply the actual Wocody WordPress.org contributor account and public project/support/security-reporting URLs. Recheck name and slug availability through the submission process. The requested `Boat Backup & Migration` name and `boat-backup` slug are preserved in this implementation.

Plugin Check 2.1.0 flags the `WP` prefix in the name and slug as `trademarked_term` warnings. Treat these as an outstanding naming review for the directory, rather than a guarantee of rejection or approval. The upstream project has tracked similar prefix checks in [issue 464](https://github.com/WordPress/plugin-check/issues/464). A maintainer must resolve the submission's actual naming decision before publishing.

Run Plugin Check against the built runtime ZIP installed in a disposable site, not the development checkout: the checkout intentionally contains Git, development tools and integration tests that are not distribution files. Runtime suppressions are narrow and documented at their use sites: raw exception text is escaped by HTML renderers or JSON encoded by REST; content renames implement site restoration; SQL identifiers pass the strict local validator; bundled text domains must work before directory hosting and on WordPress 6.0.

Keep the package format, restore limitations, retention policy and compatibility claims in the readme. Confirm `Tested up to` against actual tests before every release, and publish only a complete, validated runtime package. WordPress.org manual review remains required.
