# Composer Lenient Plugin

**Install Composer packages that cap their `php` requirement on a newer PHP — without
`--ignore-platform-req`, without forking, and without editing anything in `vendor/`.**

`ctw/ctw-composer-plugin-composerlenientplugin` is a Composer 2 plugin that relaxes the
**upper bound** of the `php` platform requirement for an explicit allowlist of packages, at
dependency-solver pool-build time. It lets a package that is capped at, say, `~8.4.0` install
cleanly on **PHP 8.5, 8.6, and later**, while every other platform check stays fully enforced
and your real PHP version is never faked.

## Does this fix your problem?

If `composer install` or `composer update` fails on a newer PHP with a message like this:

```
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires laminas/laminas-tag ^2.11 -> satisfiable by laminas/laminas-tag[2.13.0].
    - laminas/laminas-tag 2.13.0 requires php ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 ->
      your php version (8.5.7) does not satisfy that requirement.
```

…then yes. The package's code runs fine on your PHP, but its declared `php` constraint has an
**upper bound** (`~8.4.0`, `<8.5`, `^8.0 <8.5`, etc.) that Composer refuses to cross. The same
failure happens with a `~8.3.0`-capped package on PHP 8.4, a `~8.5.0`-capped one on PHP 8.6, and
so on for every future PHP release.

This plugin removes that specific blocker for the packages you trust — and only those packages.

## Why not just use the usual workarounds?

| Workaround                                                               | Why it falls short                                                                                                                                                        |
|--------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `composer install --ignore-platform-req=php+` / `--ignore-platform-reqs` | Must be repeated on **every** install and update, including on deploy and in CI, and it switches the platform check off for *every* package, not just the ones you trust. |
| `config.platform.php` set to an older version                            | Lies about your PHP to the whole dependency tree. Impossible when your own `require` (or other dependencies) genuinely needs the newer PHP.                               |
| Inline aliasing (`"vendor/pkg": "2.13.0 as 2.99.0"`)                     | Per-package, fragile, and pins you to one exact version forever.                                                                                                          |
| A `type: package` repository override                                    | Re-declares the package by hand — you now maintain a pinned version **and** a dist URL per package, forever.                                                              |
| Forking the package to edit its `composer.json`                          | Highest-maintenance option; you inherit responsibility for keeping the fork in sync.                                                                                      |

This plugin does the job generically and declaratively: name the packages once, and it widens
their `php` constraint to `original || >=8.5` in memory during dependency resolution.

## How it works

The plugin subscribes to Composer's `PrePoolCreateEvent`. As the solver builds its candidate
package pool, the plugin finds each allowlisted package and replaces its `php` link with
`original || <allow>` (default `>=8.5`), preserving the original lower bound. Aliases are
unwrapped so the change lands on the real package definition.

Because this happens during `composer update`, the widened constraint is written straight into
`composer.lock`. Every later `composer install` (deploys, CI, teammates) then reads the relaxed
constraint **from the lock** and passes the platform check with **no flag at all**.

Packages that genuinely require a newer PHP than you have still fail to resolve, exactly as they
should — only the names you list are touched.

## Requirements

- PHP >= 8.3
- Composer 2 (`composer-plugin-api` `^2.0`)

## Installation

```bash
composer require ctw/ctw-composer-plugin-composerlenientplugin
```

A Composer plugin must be allow-listed before Composer will execute it:

```json
{
    "config": {
        "allow-plugins": {
            "ctw/ctw-composer-plugin-composerlenientplugin": true
        }
    }
}
```

## Configuration

Declare the packages to relax in your root `composer.json` `extra` block, namespaced under
`ctw`:

```json
{
    "extra": {
        "ctw": {
            "ctw-composer-plugin-composerlenientplugin": {
                "allow": ">=8.5",
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

| Key | Required | Default | Meaning |
|-----|----------|---------|---------|
| `allow` | no | `>=8.5` | The constraint OR-ed onto each allowlisted package's `php` requirement. Set it to `>=8.6` etc. for a future PHP. |
| `packages` | yes | `[]` | Exact package names to relax. Anything not listed resolves untouched. |

> **Tip:** list transitive dependencies explicitly. If a package such as `laminas/laminas-mail`
> is pulled in by another dependency rather than by your own `require`, it must still appear in
> `packages` or it will block resolution.

## Bootstrapping (first install only)

A plugin cannot rewrite the solver pool until it is itself installed and active, so the very
first update — the one that physically installs this plugin — still needs the flag once:

```bash
# 1. Installs and activates the plugin (flag required this one time).
composer update -W --ignore-platform-req=php+

# 2. Plugin is active and rewrites the pool, baking the relaxed
#    constraint into composer.lock. No flag.
composer update -W
```

After that, every `composer install` and `composer update` runs **without**
`--ignore-platform-req`, because the relaxed `php` constraint already lives in the lock file.

## Verifying

After the second update, the lock carries the widened constraint:

```bash
php -r '$l=json_decode(file_get_contents("composer.lock"),true);
foreach($l["packages"] as $p){ if($p["name"]==="laminas/laminas-tag"){ echo $p["require"]["php"],"\n"; } }'
# ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || >=8.5
```

Run an update with `-v` to see exactly what was relaxed:

```
ctw-composer-plugin-composerlenientplugin: relaxed php for laminas/laminas-tag -> ~8.1.0 || ~8.2.0 || ~8.3.0 || ~8.4.0 || >=8.5
```

## FAQ

**Why does `composer install` say "your php version does not satisfy that requirement" on a
newer PHP, even though the library works?**
Because the library declares an *upper* bound on `php` (for example `~8.4.0`), and Composer
enforces it literally. The code may be compatible, but Composer will not cross a declared cap.

**How do I install a package that does not officially support my PHP version?**
Add the package to this plugin's `packages` allowlist and run `composer update`. The plugin
widens only that package's `php` constraint; nothing else changes.

**Is this safe? Does it patch files in `vendor/`?**
No files are patched. The change is made in memory during dependency resolution and recorded in
`composer.lock`. Your real PHP version is never spoofed, and every other platform requirement
(extensions, other packages' `php`) stays enforced.

**Does it affect deploys and CI?**
Yes — positively. Because the relaxed constraint is baked into `composer.lock`, deploy and CI
runs use a plain `composer install` with no `--ignore-platform-req` flag.

**Can I use it for the next PHP, like 8.6 or 8.7?**
Yes. Set `"allow": ">=8.6"` (or whatever you need). The default is `>=8.5`.

## Testing

```bash
composer install
composer test
```

The suite builds real `Composer\Package` objects and asserts the relaxed `php` constraint with
`Composer\Semver\Semver`, so it exercises actual Composer semantics rather than mocking the
constraint logic.

## Caveats

- The plugin only **widens** the `php` requirement, and only by an OR (`|| <allow>`). It never
  narrows or removes any other constraint, and never touches non-`php` requirements.
- Only the packages you name are affected; the allowlist is deliberately explicit.
- The allowlist targets tagged upstream releases. Branch/dev aliases are out of scope, as the
  affected packages resolve to plain tags.

## Keywords

Composer plugin · ignore-platform-req alternative · `your php version does not satisfy that
requirement` · install package on PHP 8.5 / 8.6 · relax php platform constraint · php upper
bound · unsupported PHP version · `config.platform.php` alternative · force composer install on
newer PHP · `--ignore-platform-reqs`.

## License

See [LICENSE.md](LICENSE.md).
