---
applyTo: "wiki/**"
---

# Wiki Documentation Instructions

## Context

This repository contains a **Magento 2 module** in the `SR` namespace.
When assigned a wiki documentation issue, your job is to update the wiki
based on what actually changed in the code — not to rewrite or invent.

The issue will always contain:
- Release tag and version
- Git diff of changed files
- List of modified PHP/XML files
- GitHub release URL

---

## Files You Work With

- `wiki/Home.md` — describes the **current state** of the module
- `wiki/Release-Notes.md` — **historical changelog**, newest entry on top

---

## Core Rules

1. **Read the diff first.** Everything you write must be grounded in the
   actual diff provided in the issue. Do not describe what you assume —
   describe what the diff shows.

2. **Write in plain language that both store administrators and developers
   can understand.** Describe the user-visible effect or the problem solved,
   not the internal implementation. Technical details (class names, method
   names, config paths) belong only in the Configuration table — not in
   feature descriptions or changelog entries.

    - ❌ "Added `stale-while-revalidate` support in `WorkerDeployment::deploy()`"
    - ❌ "Improved caching performance"
    - ✅ "Pages are now served from cache even while the cache is being refreshed,
      reducing the number of slow responses during high traffic"

    - ❌ "Fixed KV binding not applied on `WorkerDeployment::firstDeploy()`"
    - ❌ "Fixed caching bug"
    - ✅ "Fixed the issue where cache settings were not applied correctly on the
      first deployment"

   Documentation style as a reference:
   short sentences, active voice, present tense for features,
   past tense for fixes. One idea per bullet point.

3. **`wiki/Home.md`** reflects the current module state.
   Update it only when something structurally changes:
   | Change type | Update Home.md? |
   |-------------|-----------------|
   | New feature or capability | ✅ Yes |
   | New or changed config field | ✅ Yes |
   | Changed architecture or data flow | ✅ Yes |
   | Bug fix with no API/UI change | ❌ No |
   | Refactor with no behavior change | ❌ No |

4. **`wiki/Release-Notes.md`** gets a new entry on **every release**,
   inserted at the top right after the `# Release Notes` heading.

5. **Do NOT modify any source files** — `.php`, `.xml`, `.phtml`, etc.

6. **Do NOT rewrite existing wiki content** unless it is factually outdated
   based on the diff.

7. **Infer the module name, purpose, and components from the repository
   structure and diff.** Do not rely on hardcoded assumptions.

---

## Release Notes Entry Format
````markdown
## [vX.Y.Z](github_release_url) — YYYY-MM-DD

### Features
- Description of what was added and what it allows the user to do

### Improvements
- Description of what works better now and why it matters

### Fixed
- Fixed the issue where [specific thing] was [wrong behavior]

### Configuration
- Added **Setting Name** — short explanation of what it controls
````

Omit any section that has no entries for this release.
Changelog style: one bullet per change, no code references,
describe the symptom or benefit — not the implementation.

---

## Home.md Structure to Maintain

If `wiki/Home.md` does not exist, create it with this structure.
If it exists, update only the sections affected by the diff.
````markdown
# Module Name

> One sentence: what this module does and for whom.

## Overview

Two or three sentences explaining the problem this module solves
and how it solves it. No code references. Write as if explaining
to a store owner who is evaluating whether to use this module.

## Features

- What the module allows the user to do (not how it works internally)
- Each bullet is one capability, written as a benefit

## Requirements

- Magento 2.4.x or higher
- PHP 8.1 or higher
- Any external services or accounts required

## Installation
```bash
composer require vendor/module-name
bin/magento module:enable Vendor_ModuleName
bin/magento setup:upgrade
```

## Configuration

*Admin panel: Stores → Configuration → [Section] → [Group]*

| Setting | Default | Description |
|---------|---------|-------------|
| Setting Name | value | What it controls, in plain language |

## How It Works

Optional section. Use only if the data flow or integration with
external services is important for the administrator to understand.
Write in prose, not bullet points. One short paragraph maximum.

## Wiki Pages

- [Release Notes](Release-Notes)
````
