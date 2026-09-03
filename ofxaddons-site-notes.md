# ofxaddons.danoli3.com — what it is

## One line

A community "discover" site for the openFrameworks addon ecosystem: it crawls GitHub for
repos whose names match the OF addon convention, and lists them with categories, star
counts, activity, and links back to each repo.

## The pages

| Page | Purpose |
|------|---------|
| **Categories** (`/categories`, the homepage) | 24 categories (Algorithms, Animation, Artificial Intelligence, Bridges, Computer Vision, Game Engine, Geometry, Graphics, GUI, Hardware Interface, iOS, Linux, Machine Learning, macOS, Physics, Sound, Typography, Utilities, Video/Camera, Virtual Reality, Web/Networking, Windows, …) with counts and a few examples each; "View all →" links to the full list. |
| **All Addons** (`/addons`) | The complete list, A→Z by name, with **Freshest** and **Popular** sort options (`?sort=freshest`, `?sort=popular`). |
| **Unsorted** (`/unsorted`) | "Addons the crawler has found on GitHub but nobody has categorized yet." |
| **Contributors** (`/contributors`) | GitHub users ranked by number of addons they've contributed (e.g. armadillu 64, satoruhiga 60, …), each linking to a per-user page. |
| **How To** (`/pages/howto`) | "What's an openFrameworks addon?", how to install one (`git clone` into `openFrameworks/addons/`), and the expected folder structure (src/, libs/, example/). |
| **Sign in with GitHub** | Auth via GitHub (presumably for the admin side — categorization, etc.). |

## What the listing data looks like

Each addon entry shows:

- thumbnail (if the repo contains an `ofxaddons_thumbnail.png`), else the repo name
- repo name → GitHub repo link, owner handle → site's contributor page
- description (empty = "No description.")
- "Archived" badge for archived repos
- "Releases" badge if the repo has tagged GitHub releases
- its category tags (can be more than one, e.g. "Bridges Sound")
- star count and "Updated Xy ago" (last GitHub activity)

## How it works under the hood (my read)

- A **crawler** discovers GitHub repos by name (the ofx/of prefix convention) and pulls
  metadata: description, stars, last update, archive flag, releases, thumbnail presence,
  and structural signals (Makefile, example folders, expected folder layout).
- **Humans categorize** them into the 24 categories; anything unassigned sits in
  Unsorted.
- It's a **rebuilt/second-generation** version of the original ofxaddons archive — the
  data and content (e.g. "if you're coming from ofxaddons.com" in one description, the
  "How To" page, the "How To" link in the footer) track the old site's content while
  running on this new host/domain.

## In short

A GitHub-based index/directory for openFrameworks addons: automatic discovery + manual
categorization, with contributor stats and install guidance. The interesting open work
on it is the ~2,900-odd "Unsorted" entries that still need triage into categories.
