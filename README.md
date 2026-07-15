# 🔎 **webtrees** module for CompGen MetaSearch (hh_metasearch)

[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](http://www.gnu.org/licenses/gpl-3.0)
[![Latest release](https://img.shields.io/github/v/release/hartenthaler/hh_metasearch?label=release)](https://github.com/hartenthaler/hh_metasearch/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/hartenthaler/hh_metasearch/total)](https://github.com/hartenthaler/hh_metasearch/releases)

![webtrees major version](https://img.shields.io/badge/webtrees-v2.2.x-green)

This [webtrees](https://www.webtrees.net) custom module provides an XML endpoint for the CompGen "Metasuche" at [meta.genealogy.net](https://meta.genealogy.net/).

The endpoint, authorization handling, public-tree filtering, XML transport, surname search, place-name search, GOV-ID filtering, and change-date filtering are available.

<a name="Contents"></a>
## 📚 Contents

This Readme contains the following main sections

- [Purpose](#Purpose)
- [Scope](#Scope)
- [Functions](#Functions)
- [Security model](#Security)
- [Usage and API](#Usage)
- [XML response](#Results)
- [Module settings](#Settings)
- [Architecture](#Architecture)
- [Requirements](#Requirements)
- [Installation](#Installation)
- [Upgrade](#Upgrade)
- [Translation](#Translation)
- [Contact Support](#Support)
- [License](#License)

<a name="Purpose"></a>
## 🎯 Purpose

CompGen MetaSearch can call external genealogy databases and display links to matching records.

This module makes selected public webtrees trees searchable through a small XML API.
It is intended for controlled machine-to-machine access from MetaSearch, not as a general public search UI.

<a name="Scope"></a>
## 🔎 Scope

The endpoint supports the request shape expected by MetaSearch:

- select one or more webtrees trees by tree name
- check an authorization key
- validate search parameters for surname, place name, GOV ID, tree names, and change date
- return XML

Only trees that are public for visitors are eligible.
The module filters tree selection by webtrees tree preferences:

- `imported` must be `1`
- `REQUIRE_AUTHENTICATION` must not be `1`

This rule is applied whenever the configured tree list is read. If an administrator later makes a tree private, it automatically disappears from the effective MetaSearch tree list.

<a name="Functions"></a>
## 🧰 Functions

The module provides:

- route registration for `/MetaSearch`
- XML response
- XML error responses
- external POST handling without webtrees CSRF redirects
- authorization key storage as one-way hash
- public-tree filtering
- request-parameter validation and normalization
- surname search using `lastname`
- place-name search using direct GEDCOM `PLAC` values and linked `_LOC:NAME` records, including parent `_LOC` records
- GOV-ID filtering using direct `INDI:*:PLAC:_GOV` values and `_GOV` values on linked public `INDI:*:PLAC:_LOC` records, including parent `_LOC` records
- change-date filtering using visitor-visible `INDI:CHAN:DATE` values
- configurable default tree list
- configurable database name and maximum hit count

Open validation work and interface questions are tracked in GitHub:

- [Validate the MetaSearch interface with a test tree](https://github.com/hartenthaler/hh_metasearch/issues/8)
- [Clarify whether `lastname` is required](https://github.com/hartenthaler/hh_metasearch/issues/9)

Searches require `lastname`. `placename`, `placeid`, and `since` are used only as additional AND filters
for visitor-visible individual entries.

<a name="Security"></a>
## 🔐 Security model

The MetaSearch endpoint is protected by an authorization key.

Important behavior:

- if no key is configured, access is denied
- if a key is configured, every request must provide `key=...`
- keys are stored with PHP `password_hash()`
- verification uses `password_verify()`
- the configured key cannot be displayed in the control panel
- entering a new key replaces the old key

Use a long random key and share it only with the external MetaSearch service.

Use HTTPS. The authorization key is passed as a request parameter and can otherwise appear in logs, proxy logs, and server access logs.

<a name="Usage"></a>
## 🛠 Usage and API

MetaSearch sends `application/x-www-form-urlencoded` data using HTTP `POST`.
For development and compatibility, this module also accepts the same parameters from the query string as a fallback.

### URL format

The route is:

```text
/MetaSearch
```

The module settings page shows the endpoint URL for the current webtrees URL mode.

Typical request:

```text
https://example.org/index.php?route=MetaSearch
```

Typical POST body:

```text
key=KEY&trees=TREE1,TREE2&lastname=NAME&placename=PLACE&placeid=GOV&since=YYYY-MM-DD
```

Depending on the webtrees installation path and URL rewriting, the route parameter may need to be adapted.

Do not put parameters into the path, such as `/MetaSearch/key=...` or `/module/_hh_metasearch_/key=...`. Use HTTP POST form fields, or query parameters such as `/MetaSearch?key=...` for manual tests.

### Parameters

`key`

Authorization key. Required when the endpoint is called.

`trees`

Comma-separated list of webtrees tree names. Use the internal tree name, not the tree title.

If this parameter is omitted, the default tree list configured in the module settings is used.
Explicitly requested trees are trimmed, deduplicated, and must also be public and enabled in the module settings.

`lastname`

Surname search parameter. Intended to match GEDCOM `SURN` values in `INDI:NAME` records.

`placename`

Optional place-name filter. It is evaluated only together with `lastname` and matches visitor-visible direct GEDCOM `PLAC` values in personal facts and visitor-visible linked `_LOC` records by their public `NAME` facts. Multiple `NAME` facts in one `_LOC` record are considered, as are public parent `_LOC` records.

`placeid`

GOV ID search parameter. It matches visitor-visible direct `INDI:*:PLAC:_GOV` values and `_GOV` values on linked public `INDI:*:PLAC:_LOC` records, including parent `_LOC` records.

`since`

Optional date in `YYYY-MM-DD` format. Intended to restrict matches to records changed after this date.
The value must be a valid Gregorian date, must not be in the future, and is compared with visitor-visible `INDI:CHAN:DATE` values.

### Empty searches

If no `lastname`, `placename`, or `placeid` parameter is supplied, the endpoint returns an empty result structure for the selected trees.

<a name="Results"></a>
## 🧾 XML response

The endpoint returns XML directly with `Content-Type: text/xml; charset=UTF-8`.
It does not render a webtrees HTML page.
It also avoids the normal webtrees CSRF redirect for this API route, because authorization is handled by the MetaSearch key.

Successful response shape in the current implementation:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<result>
  <database>
    <name>Database name - tree_name</name>
    <url>https://example.org/tree=tree_name</url>
  </database>
</result>
```

Error response shape:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<result>
  <error>
    <module>MetaSearch</module>
    <message>Key not accepted. Access denied.</message>
  </error>
</result>
```

Authorization failures return HTTP `401`.
Invalid request parameters return HTTP `400`.

Matching persons are returned as `entry` elements:

```xml
<entry>
  <lastname>lastname</lastname>
  <firstname>firstname</firstname>
  <details>* 1900 Berlin, + 1970 Hamburg</details>
  <url>https://example.org/tree/TREE/individual/XREF</url>
</entry>
```

If additional matches exist beyond the configured result limit, the database element will include:

```xml
<more>true</more>
```

<a name="Settings"></a>
## ⚙️ Module settings

The control panel allows administrators to configure:

- authorization key
- database display name
- maximum number of hits per tree
- default list and order of searchable trees

Only public trees are shown in the module settings.
If a tree later becomes private, it is automatically ignored by the endpoint and removed from the effective configured list on the next save.

The authorization key is shown only as a status. It cannot be displayed after it has been saved.

<a name="Architecture"></a>
## 🧭 Architecture

Detailed architecture notes are maintained in [docs/architecture.md](docs/architecture.md).

<a name="Requirements"></a>
## 📌 Requirements

This module requires **webtrees** version 2.2 or later.
The version of PHP must match the requirements of the installed webtrees version.

This module is currently maintained against webtrees 2.2.
This module was tested with **webtrees** 2.2.6 version and all other custom modules.

<a name="Installation"></a>
## 📥 Installation

Install and use [Custom Module Manager](https://github.com/Jefferson49/CustomModuleManager) for an easy and convenient installation of **webtrees** custom modules:

1. Open the Custom Module Manager view in **webtrees**, scroll to "MetaSearch", and click on the "Install Module" button.

**Manual installation**:

1. Make a backup of files and database.
1. Download or copy the module folder.
1. Place it in `webtrees/modules_v4/hh_metasearch`.

**After installation with either method**:

1. Login to **webtrees** as administrator.
1. Open the control panel and enable the module.
1. Open the module settings.
1. Set a long random authorization key.
1. Configure the database name, maximum hits, and public trees.
1. Save the module settings.

<a name="Upgrade"></a>
## ⬆️ Upgrade

To update, replace the `hh_metasearch` files with the new ones from the latest release.

<a name="Translation"></a>
## 🌍 Translation

You can help to translate this module.
The language information is stored in the folder `resources/lang/`.
You can edit those files and return them to me.
You can do this via a pull request (if you know how) or by e-mail.
Updated translations will be included in the next release of this module.

There are the following translations available
- English by @Hartenthaler
- German by @Hartenthaler

<a name="Support"></a>
## ❓ Support

- <span style="font-weight: bold;">Issues: </span> You can report errors by raising an [issue in this GitHub repository](https://github.com/hartenthaler/hh_metasearch/issues).
- <span style="font-weight: bold;">Forum: </span>General webtrees support can be found at the [webtrees forum](https://www.webtrees.net/index.php/forum).

<a name="License"></a>
## 📄 License

This module uses [GPL-3.0-or-later](LICENSE.md) as a license.

* Copyright (C) 2026 Hermann Hartenthaler
* Derived from **webtrees** - Copyright 2026 webtrees development team.

This program is free software: you can redistribute it and/or modify it
under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
