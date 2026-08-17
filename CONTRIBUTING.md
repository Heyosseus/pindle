# Contributing

Thanks for taking the time. A few things worth knowing before you start.

## Running the suite

```bash
composer install
composer test
```

`composer test` runs, in order: Rector in dry-run, Pint, PHPStan at level max,
100% type coverage, and the test suite at 100% line coverage. All five are
enforced in CI; a pull request that drops any of them will not go green.

The suite runs against SQLite in memory and needs no services.

## The viewer

The front end lives in `js/src` and is built with esbuild:

```bash
npm install
npm test        # coordinate and API tests, via node --test
npm run build   # writes resources/dist
npm run watch
```

**The compiled bundle is committed.** That is deliberate: applications install
Pindle with `composer require` and publish the assets, and none of them runs
npm. CI rebuilds and fails if `resources/dist` differs from what the sources
produce, so run `npm run build` and commit the result with any change under
`js/`.

## The parts to be careful with

**Anchoring.** Rectangles are PDF user-space points with a bottom-left origin.
Nothing scale-dependent, rotation-dependent or viewport-dependent may ever be
persisted — that is the whole reason a highlight stays on the right word. If you
touch `src/Geometry`, `js/src/coordinates.js`, or the cast between them, the
round-trip tests are the specification.

**Authorisation.** Every endpoint asks the policy about the *owning model*,
never about the annotation. `tests/Feature/Http/IsolationTest.php` enumerates all
eight routes and is not optional: a new route without a policy check should fail
a test rather than ship.

**Optional peers.** Filament and Livewire are `suggest`, never `require`. Only
`src/Filament` and `src/Livewire` may mention them; an arch test enforces it, and
a CI leg runs the suite with neither installed.

**The Laravel 11 legs.** Laravel 11 has left security support, so every `11.x`
release now carries a Packagist advisory and Composer refuses to resolve one by
default — which means Testbench 9 cannot be installed at all. Those two legs, and
only those two, lift `policy.advisories.block` before installing. It is written
into the CI checkout rather than into `composer.json`, so advisory blocking stays
on for you, for the Laravel 12 and 13 legs, and for anybody who clones the repo.
If Laravel 11 is ever dropped, delete the `eol-framework` flag with the legs.

## Style

Match the code that is already there. In particular, comments explain *why*
rather than *what* — if a decision took thought, the next reader deserves the
thought and not a restatement of the line below it.

Pint decides formatting; do not argue with it, run it.

## Commits and pull requests

One concise, descriptive line per commit. Group related work rather than
committing every small change on its own.

Open the pull request against `main`, and say what you did *not* finish.
