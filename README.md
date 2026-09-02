# ofxAddons

Daily crawler for [ofxaddons.danoli3.com](https://ofxaddons.danoli3.com) - discovers openFrameworks addons on Github (repos matching the `ofx` name prefix) and publishes a JSON snapshot as a Github Release.

## How it works

- `.github/workflows/crawl.yml` runs `crawl.php` daily (and on manual dispatch), authenticated with the workflow's own `GITHUB_TOKEN` - no extra secret needed.
- `crawl.php` searches Github for `ofx0`..`ofx9`, `ofxa`..`ofxz`, prunes anything that doesn't actually start with `ofx` or has no commits, dedupes, and checks each repo's root folder listing for the conventions [ofxaddons.danoli3.com/pages/howto](https://ofxaddons.danoli3.com/pages/howto) documents (`src/` folder, an `example` folder, an addon makefile, a `ofxaddons_thumbnail.png`).
- The result is written to `data/addons.json` and published as a release asset, tagged `data-YYYY-MM-DD`. Github's `/releases/latest/download/addons.json` always resolves to the most recent one.
- If a `SYNC_WEBHOOK_URL` repository variable and `SYNC_SECRET` repository secret are set, the workflow POSTs to that URL after publishing so the consuming site can pull the new data immediately instead of waiting for its own polling cycle.
- Before pruning, `crawl.php` fetches [ofxaddons.danoli3.com/banned.json](https://ofxaddons.danoli3.com/banned.json) - full names the site has already ruled out (banned as a false positive, or deleted) - and skips them entirely, so no API calls are wasted re-checking repos an admin already ruled out.

## What this repo doesn't do

Categorization, banning false positives (repos that share the `ofx` prefix by coincidence, nothing to do with openFrameworks), and everything else data-curation-related lives on the consuming site, not here. This repo only answers "what does Github currently say exists" - merging that against existing categorization/moderation decisions is the site's job. The one exception is `banned.json` above: the site publishes its ban list back so the crawler can stop wasting calls on repos already ruled out, but the decision itself is still made entirely on the site.

## `data/addons.json` shape

```json
{
  "generated_at": "2026-09-02T03:00:00+00:00",
  "count": 4750,
  "addons": [
    {
      "full_name": "owner/ofxSomeAddon",
      "name": "ofxSomeAddon",
      "description": "...",
      "owner": { "id": 123, "login": "owner", "avatar_url": "..." },
      "fork": false,
      "parent": null,
      "source": null,
      "stargazers_count": 12,
      "forks_count": 3,
      "pushed_at": "2026-08-01T00:00:00Z",
      "created_at": "2020-01-01T00:00:00Z",
      "has_makefile": true,
      "example_count": 1,
      "has_correct_folder_structure": true,
      "has_thumbnail": false,
      "archived": false,
      "has_releases": true
    }
  ]
}
```
