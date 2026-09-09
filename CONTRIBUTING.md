# Contributing to FlatBB

Bug reports, translations and pull requests are welcome at https://github.com/nwnuyhs/flatbb and on https://www.flatbb.com.

## Before you start

- Read `CLAUDE.md` (the rules of the codebase) and `docs/ARCHITECTURE.md`.
- `php flatbb test`, `php flatbb security:check` and `php -l` on every changed file must pass; plugins pass `php flatbb plugin:check <id>`.
- Keep files small and functions plain, as the rest of the code does.

## Contributor licence agreement

FlatBB is published under the GNU AGPL-3.0-or-later **and** offered under a commercial licence to organisations that cannot use the AGPL (see `LICENSING.md`). For that to stay possible, every contribution to FlatBB's own code needs this agreement. By submitting a contribution (a pull request, a patch, a translation, code posted for inclusion) you agree that:

1. You wrote the contribution, or you have the right to submit it under these terms.
2. You license your contribution to the FlatBB copyright holder (www.flatbb.com) under the GNU AGPL-3.0-or-later, **and** you grant the copyright holder a perpetual, worldwide, non-exclusive, royalty-free right to also distribute your contribution under other licence terms, including the FlatBB commercial licence.
3. You keep the copyright of your contribution and may use it elsewhere under any terms.
4. The contribution is provided as is, without warranty.

Add the line `Signed-off-by: Your Name <email>` to your commits (or say "I agree to the CLA in CONTRIBUTING.md" in the pull request) to record your agreement. Plugins and themes you publish on the marketplace are yours and need no agreement.
