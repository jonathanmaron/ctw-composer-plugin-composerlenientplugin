# CHANGELOG

## 1.0.0 - 2026-06-27

* Original release.
* Relaxes the `php` upper-bound constraint of an allowlisted set of packages at
  `PrePoolCreateEvent`, writing the widened constraint into `composer.lock`.
* Configurable via `extra.ctw.ctw-composer-plugin-composerlenientplugin` (`allow`, `packages`).
