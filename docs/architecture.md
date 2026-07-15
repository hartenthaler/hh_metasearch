# Architecture: hh_metasearch

This document describes the current architecture of the `hh_metasearch` custom module for webtrees 2.2.

The module is an API endpoint module. It does not provide a user-facing search page. Its primary consumer is the CompGen MetaSearch service.

## Goals

- expose selected public webtrees trees to CompGen MetaSearch
- return machine-readable XML
- avoid rendering webtrees page layouts for API responses
- protect the endpoint with an authorization key
- ensure that only visitor-public trees can be searched
- keep the implementation compatible with webtrees 2.2 module loading and routing

## Non-goals

- provide a general webtrees search UI
- expose private or member-only trees
- expose data that webtrees would hide from visitors
- store the authorization key in reversible form

## Files

### `module.php`

Entry point loaded by webtrees.

Responsibilities:

- require the module class
- return a new `MetaSearch` instance

### `MetaSearch.php`

Main module class.

Responsibilities:

- module metadata
- route registration
- settings view and settings save action
- authorization-key migration and verification
- public-tree filtering
- request parameter parsing
- XML response generation

The class implements:

- `ModuleCustomInterface`
- `ModuleConfigInterface`
- `RequestHandlerInterface`

## Routing

The module registers one route:

```php
Registry::routeFactory()->routeMap()
    ->get(static::class, self::ROUTE_URL, $this)
    ->allows(RequestMethodInterface::METHOD_POST)
    ->extras(['middleware' => [new MetaSearchApiMiddleware($this)]]);
```

`ROUTE_URL` is currently:

```text
/MetaSearch
```

Requests are handled by `MetaSearch::handle()`.

The route also registers a route-local `MetaSearchApiMiddleware`. This lets the API endpoint answer external POST requests before the global webtrees CSRF middleware runs. Without this, form-encoded MetaSearch POST requests without a webtrees CSRF token are redirected before the endpoint handler is reached.

With Pretty URLs enabled, the endpoint URL is `/MetaSearch`. Request parameters must be sent as form-encoded POST fields, or as query parameters for manual testing. They must not be appended as path segments.

## Request parameters

The endpoint accepts form-encoded request parameters. `MetaSearch::requestParameter()` reads the parsed POST body first and falls back to query parameters for compatibility.

`MetaSearch::parametersFromRequest()` maps the external parameter names into a `MetaSearchParameters` object:

| External parameter | PHP property | Purpose |
| --- | --- | --- |
| `key` | `$parameters->key` | Module authorization key |
| `trees` | `$parameters->trees` | Optional comma-separated list of tree names |
| `trees` | `$parameters->treeNames` | Validated, trimmed, deduplicated tree-name array |
| `lastname` | `$parameters->lastname` | Required surname search input if used |
| `placeid` | `$parameters->placeid` | Optional GOV place identifier |
| `placename` | `$parameters->placename` | Optional place name |
| `since` | `$parameters->since` | Optional date filter in `yyyy-mm-dd` format |
| `since` | `$parameters->sinceJulianDay` | Validated Gregorian date converted to Julian day, or `0` when omitted |

Validation rejects array values, invalid UTF-8, control characters, overlong values, malformed tree lists, invalid `placeid` whitespace, and invalid `since` dates.

Only the `key` parameter is read before authorization. The remaining request parameters are normalized and validated after the key has been accepted.

`MetaSearchParameters::requestedTreeNames()` returns the validated tree-name array. `MetaSearchParameters::isEmptySearch()` checks whether `lastname`, `placeid`, and `placename` are all empty, as required by the CompGen MetaSearch interface.

## Response model

API responses must not use `viewResponse()`.

`viewResponse()` renders a view and inserts it into a webtrees layout. That is correct for admin pages but wrong for XML endpoints.

The endpoint therefore generates XML with `DOMDocument` and returns it using:

```php
Registry::responseFactory()->response(
    $xml->saveXML(),
    $status,
    ['content-type' => 'text/xml; charset=UTF-8']
);
```

This keeps the body free of HTML layout output and sets the content type required by the CompGen MetaSearch interface.

## Authorization

The endpoint uses a shared authorization key passed as request parameter:

```text
key=...
```

Current rules:

- no configured key means access denied
- missing request key means access denied
- invalid request key means access denied
- successful verification is required before tree selection or search handling

Key storage:

- new keys are stored with `password_hash()`
- verification uses `password_verify()`
- old plain-text keys are detected with `password_get_info()` and migrated to hashed storage
- the stored key cannot be displayed again
- setting a new key replaces the old key

HTTP status:

- authorization failures return `401 Unauthorized`
- request validation failures return `400 Bad Request`

## Tree selection

The endpoint supports two tree-selection modes.

### Default tree list

If the request does not include a `trees` parameter, the module uses the tree list configured in the module settings.

The configured list stores order and enabled/disabled state.

### Explicit request tree list

If the request includes:

```text
trees=tree1,tree2
```

then the configured default list is ignored.

However, each requested tree must still be:

- currently public
- currently enabled in the module settings

Invalid trees produce an XML error response.

## Public-tree filtering

The module starts from webtrees `TreeService::all()` and then applies an explicit visitor-public filter:

```php
$tree->getPreference('imported') === '1'
$tree->getPreference('REQUIRE_AUTHENTICATION') !== '1'
```

This matters because administrators can see more trees than visitors. The MetaSearch endpoint must not inherit administrator visibility.

The filter is applied whenever the module reads the tree list. Therefore:

- private trees are not shown in module settings
- private trees are not accepted as request parameters
- private trees are removed from the effective default list
- if a tree is made private later, it automatically stops being searchable

## Settings

Stored module preferences:

- `module_version`
- `secret_key`
- `use_hash`
- `max_hit`
- `database_name`
- `tree_order`
- `status-{tree_name}` for tree enablement

`use_hash` is kept for migration compatibility. The current behavior always stores authorization keys as hashes.

## Current XML shape

Successful result:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<result>
  <database>
    <name>Database name - tree_name</name>
    <url>https://example.org/tree=tree_name</url>
  </database>
</result>
```

Error result:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<result>
  <error>
    <module>MetaSearch</module>
    <message>Key not accepted. Access denied.</message>
  </error>
</result>
```

The successful response shape follows the CompGen MetaSearch interface: one `result` element containing one or more `database` elements. Each `database` contains `name`, `url`, zero or more `entry` elements, and optional `more`.

## Search implementation status

Surname search is implemented for the `lastname` parameter.
Place-name filtering is implemented for the `placename` parameter.
GOV-ID filtering is implemented for the `placeid` parameter.
Change-date filtering is implemented for the `since` parameter.

Current behavior:

- empty search parameters return `database` elements without `entry` elements
- requests without `lastname` return no entries; `placename` is intentionally not used as a standalone search
- `lastname` searches query the webtrees `individuals` and `name` tables per selected tree
- `placename` is evaluated after the `lastname` index search to avoid false negatives from indirect location links
- direct places are confirmed against public `PLAC` values in individual facts
- linked shared locations are confirmed against public `_LOC:NAME` facts; multiple `NAME` facts and parent `_LOC` chains are supported
- `placeid` is evaluated after the `lastname` index search and matches public `INDI:*:PLAC:_GOV` values or `_GOV` values on linked public `INDI:*:PLAC:_LOC` records, including parent `_LOC` chains
- `since` is evaluated after the `lastname` index search and matches individuals whose public `INDI:CHAN:DATE` is later than the requested date
- matching individuals are filtered with visitor-level webtrees privacy (`Auth::PRIV_PRIVATE`)
- entry names are taken from public `NAME` facts, not directly from the search index row
- duplicate individual rows from multiple names are collapsed before applying `max_hit`
- tree-specific database URLs are generated with the webtrees `TreePage` route helper, so they follow the site's pretty-URL setting
- if no tree can be selected, the endpoint still returns one `database` element using the configured database name and the webtrees home-page route

The CompGen interface description is currently ambiguous about whether `lastname` is mandatory or whether a standalone `placename` or `placeid` search is permitted. This is tracked in [issue #9](https://github.com/hartenthaler/hh_metasearch/issues/9). Until the interface has been clarified, the implementation continues to require `lastname` before returning entries.

Search inputs:

- `lastname`: implemented
- `placename`: implemented as an AND filter for `lastname`
- `placeid`: implemented as an AND filter for `lastname`
- `since`: implemented as an AND filter for `lastname`

Expected search behavior:

- search only selected public trees
- combine provided search parameters with AND semantics
- respect webtrees privacy filtering
- limit results per tree by `max_hit`
- set `more=true` when more than `max_hit` matches exist

## Suggested next implementation steps

The final interface validation is tracked in [issue #8](https://github.com/hartenthaler/hh_metasearch/issues/8):

1. Prepare a webtrees tree with suitable public and private test data.
2. Agree with Jesper Zedlitz how the Metasuche and Alerts services will exercise the endpoint.
3. Validate authorization, tree filtering, search parameters, XML content type, and XML response shape against the real service.
4. Add focused automated tests for the verified behavior.

## Security notes

The authorization key is a shared secret.

Use HTTPS. URL parameters can otherwise be exposed through access logs, proxy logs, browser history, and referrer headers.

The key does not make private data public. It only allows access to the endpoint. The endpoint must continue to filter trees and records as visitor-visible.
