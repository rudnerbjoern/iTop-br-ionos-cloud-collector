# Release preparation

Install the test dependencies with `composer install` in the collector checkout. On a `release/X.Y.Z` branch, run `php scripts/prepare-release.php X.Y.Z` from the repository root. This updates VERSION and runs the local checks. Publication requires the collector entry point in `collectors/main.php`. Review the changes before opening the release pull request.

Merging a release pull request from this repository into `main` publishes the version tag, runtime ZIP and SHA-256 checksum after the required PR checks pass. To recover an interrupted release, retry its workflow run for the same commit.
