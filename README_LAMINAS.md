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

## What it replaces

Before this plugin, each package was carried as a `type: package` repository
override in the application's `composer.json` — a hand-maintained copy of the
package definition with the `php` constraint manually widened, plus a pinned
version, dist URL, and SHA per package. That is five blocks of brittle,
copy-pasted metadata that must be re-synced by hand on every upstream update.

The plugin reduces all of it to a single declarative allowlist.

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
