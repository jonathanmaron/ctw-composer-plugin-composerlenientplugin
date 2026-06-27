# Running Laminas packages on PHP 8.5

This guide shows how **Composer Lenient Plugin** unblocks a real-world Laminas
application on **PHP 8.5**. Several Laminas components run cleanly on 8.5 but
still declare a `php` constraint that stops at 8.4 (or earlier), so Composer
refuses to install them on 8.5 — even though the code itself is compatible.

Rather than forking the packages or hand-editing `vendor/`, this plugin widens
the `php` constraint of an explicit allowlist in memory while the dependency
solver runs. See the [README](README.md) for the general mechanism; this
document covers the Laminas specifics.

## The problematic packages

When migrating our application to PHP 8.5 we hit exactly five Laminas packages
whose tagged releases declare a `php` upper bound below 8.5. They install and run
without issue on 8.5; the cap is simply a metadata limit upstream had not yet
raised.

| Package                                            | Version | Declared `php` constraint                    | Highest PHP allowed | Blocks on |
|----------------------------------------------------|---------|----------------------------------------------|---------------------|-----------|
| `laminas/laminas-cache-storage-adapter-filesystem` | 2.5.0   | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0` | 8.4.x               | 8.5       |
| `laminas/laminas-mail`                             | 2.25.1  | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0`             | 8.3.x               | 8.4, 8.5  |
| `laminas/laminas-mime`                             | 2.12.0  | `~8.0.0 \|\| ~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0` | 8.3.x               | 8.4, 8.5  |
| `laminas/laminas-serializer`                       | 2.18.0  | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0` | 8.4.x               | 8.5       |
| `laminas/laminas-tag`                              | 2.13.0  | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0` | 8.4.x               | 8.5       |

Each `~8.N.0` clause means `>=8.N.0 <8.(N+1).0`, so the highest clause fixes the
ceiling: a package topping out at `~8.4.0` resolves up to `<8.5.0` and rejects
PHP 8.5; `laminas-mail` and `laminas-mime`, topping out at `~8.3.0`, reject even
8.4.

On PHP 8.5 a plain `composer install` fails with the familiar message:

```
laminas/laminas-tag 2.13.0 requires php ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 ->
your php version (8.5.x) does not satisfy that requirement.
```

### Note on transitive dependencies

`laminas-mime` is pulled in by `laminas-mail` (`laminas/laminas-mime: ^2.11.0`)
rather than required directly by the application. Composer still enforces its
`php` constraint during resolution, so it **must** be listed in the allowlist
explicitly — relaxing `laminas-mail` alone is not enough.

## Alternatives — and why this plugin is the best fit

Every approach below can get a `~8.4.0`-capped Laminas package onto PHP 8.5. What
separates them is the cost across **five** packages, a deploy pipeline, and every
future PHP bump. The plugin is the only option that is surgical, honest, and
low-maintenance at the same time.

### `composer install --ignore-platform-req=php+`

Tells Composer to ignore the upper bound of the `php` platform requirement. It
works, but:

- it must be repeated on **every** `install` and `update`, including on each
  deploy and CI run — miss it once and the build breaks;
- it is **global** — the check is lifted for *every* package, so a genuinely
  incompatible dependency slips through silently instead of failing loudly.

The plugin relaxes only the five packages you name and leaves the platform check
fully enforced everywhere else. Once it has run, the relaxed constraints live in
`composer.lock`, so no flag is ever needed again.

### `config.platform.php` set to `8.4`

Tells Composer to pretend the platform is PHP 8.4 so the capped packages resolve.
But the application was deliberately migrated **to** 8.5, and other dependencies
legitimately require 8.5 — pinning the platform to 8.4 lies to the entire
dependency tree and breaks resolution for everything that genuinely needs the
newer runtime. The plugin never touches the reported platform version; your real
PHP stays 8.5 for every other package.

### `type: package` repository overrides

This is exactly what the application used before — and what the migration to this
plugin removed. Each of the five packages was re-declared by hand in
`composer.json`: a full copy of its `require` block (with `php` widened), plus a
pinned `version`, `dist` URL, and commit `reference`. That is five blocks of
brittle, duplicated metadata that:

- pin each package to one exact version, so you stop receiving upstream patch
  releases until you manually bump the reference and SHA;
- must be re-synced by hand on every upstream update — forever.

The plugin replaces all five blocks with a single allowlist. The packages keep
updating normally through Composer; only their `php` cap is widened.

### Inline version aliasing

Aliasing (`"laminas/laminas-tag": "2.13.0 as 2.99.0"`) rewrites the *version* a
package presents — it does not touch `require.php`, so the platform check still
rejects it. It is the wrong tool for a platform-requirement block, and where it
is forced to fit it pins you to one exact version, just like the override above.

### Forking each package

Cloning all five repositories to edit one line of `composer.json` in each is the
highest-maintenance option: you inherit responsibility for keeping every fork in
sync with upstream security and bug-fix releases. The plugin needs no forks and
no vendored code.

### Why the plugin wins

For the Laminas-on-8.5 case specifically, only the plugin is all of:

- **Surgical** — only the five named packages are relaxed; every other platform
  check stays fully enforced.
- **Honest** — your real PHP version is never faked to the dependency tree.
- **Version-agnostic** — nothing is pinned; as Laminas ships new tags of these
  packages, the relaxation keeps applying to them automatically.
- **Low-maintenance** — one declarative allowlist, with no copied dist URLs,
  SHAs, or forks to re-sync.
- **Flag-free after bootstrap** — the relaxed constraints bake into
  `composer.lock`, so deploys and CI run a plain `composer install`.
- **Bounded and reversible** — `^8.5` stops before 9.0, and you delete an entry
  the moment upstream raises its own ceiling.

## Configuration

Add the plugin to the application and declare the five packages under
`extra.ctw`, exactly as our production application does:

```json
{
    "require": {
        "ctw/ctw-composer-plugin-composerlenientplugin": "*"
    },
    "config": {
        "allow-plugins": {
            "ctw/ctw-composer-plugin-composerlenientplugin": true
        }
    },
    "extra": {
        "ctw": {
            "ctw-composer-plugin-composerlenientplugin": {
                "allow": "^8.5",
                "packages": [
                    "laminas/laminas-cache-storage-adapter-filesystem",
                    "laminas/laminas-mail",
                    "laminas/laminas-mime",
                    "laminas/laminas-serializer",
                    "laminas/laminas-tag"
                ]
            }
        }
    }
}
```

`"allow": "^8.5"` widens each listed package's `php` requirement to
`original || ^8.5`, which permits PHP 8.5 through the rest of the 8.x line but
deliberately stops before 9.0 — a major release that may carry breaking changes.
`^8.5` is also the default, so the `allow` key can be omitted if you want that
exact behavior. Every package **not** named in `packages` keeps its original
constraint fully enforced.

## Bootstrapping (first install only)

A plugin cannot rewrite the solver pool until it is itself installed, so the
update that first installs the plugin still needs the platform flag once:

```bash
# 1. Installs and activates the plugin (flag required this one time).
composer update -W --ignore-platform-req=php+

# 2. Plugin is active; it rewrites the pool and bakes the relaxed
#    constraints into composer.lock. No flag.
composer update -W
```

Afterwards every `composer install` (deploys, CI) runs **without**
`--ignore-platform-req`, because the relaxed `php` constraints already live in
`composer.lock`.

## Verifying

After the second update, the lock carries the widened constraints. For example:

```bash
php -r '$l=json_decode(file_get_contents("composer.lock"),true);
foreach($l["packages"] as $p){ if($p["name"]==="laminas/laminas-tag"){ echo $p["require"]["php"],"\n"; } }'
# ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ^8.5
```

Run an update with `-v` to see exactly what the plugin relaxed:

```
ctw-composer-plugin-composerlenientplugin: relaxed php for laminas/laminas-tag -> ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || ^8.5
```

## When to remove it

Each entry is a temporary bridge. As upstream Laminas releases raise their own
`php` ceiling to include 8.5, drop that package from `packages` and run
`composer update` for it. When the list is empty, remove the plugin entirely —
nothing in your own code depends on it.
