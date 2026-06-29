# CHANGELOG

## 2.0.0 - 2026-06-29

* **BREAKING:** configuration is now a uniform **list of rules**, each
  `{ "require": ..., "allow": ..., "packages": [...] }`. `php` is no longer special — express it
  as a rule with `"require": "php"`.
* Any requirement can be widened, not only `php` — e.g. resolving
  `laminas/laminas-servicemanager: ^4` against packages that still declare `^3` on a
  not-yet-released branch.
* Removed the implicit `^8.5` default; every rule sets `require`, `allow`, and `packages`
  explicitly. A rule missing any of them, or with an empty `packages`, is ignored.
* Removed the hardcoded `version` field so the package version derives from VCS.

### Upgrading from 1.x

Wrap the old `allow` / `packages` object in a list and give it `"require": "php"`:

```diff
- "ctw-composer-plugin-composerlenientplugin": {
-     "allow": "^8.5",
-     "packages": ["laminas/laminas-tag"]
- }
+ "ctw-composer-plugin-composerlenientplugin": [
+     { "require": "php", "allow": "^8.5", "packages": ["laminas/laminas-tag"] }
+ ]
```

## 1.0.0 - 2026-06-27

* Original release.
* Relaxes the `php` upper-bound constraint of an allowlisted set of packages at
  `PrePoolCreateEvent`, writing the widened constraint into `composer.lock`.
* Configurable via `extra.ctw.ctw-composer-plugin-composerlenientplugin` (`allow`, `packages`).
