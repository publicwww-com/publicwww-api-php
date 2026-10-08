# PublicWWW API - PHP

A small client for the [PublicWWW API](https://publicwww.com/docs/api/): search the
source code (HTML, JavaScript, CSS) of hundreds of millions of websites from your
own code. One file, PHP 7.4+, no extensions beyond openssl and json.

## Setup

Issue a token on your [profile page](https://publicwww.com/profile/api) (a paid
plan is required for the API) and keep it in the environment, not in the code:

```sh
export PUBLICWWW_KEY=...
```

## Command line

```sh
php PublicWWW.php account                          # plan and quota, spends nothing
php PublicWWW.php search '"angular.min.js"' 20     # one page
php PublicWWW.php export '"angular.min.js"' > sites.txt   # every url your plan covers
```

## As a library

```php
<?php
require "PublicWWW.php";

$pw = new PublicWWW\Client ();                     // key from $PUBLICWWW_KEY

$page = $pw->search ('"angular.min.js"', 1, 20);
echo $page ["total"], " sites match\n";
foreach ($page ["results"] as $r)                  // rank is null for unranked sites
    echo $r ["rank"], "\t", $r ["domain"], "\t", $r ["url"], "\n";

// Everything at once, streamed as NDJSON: one search, nothing held in memory.
$meta = null;
foreach ($pw->iterAll ('"angular.min.js" site:de', null, $meta) as $r) echo $r ["domain"], "\n";
if (!empty ($meta ["truncated"])) echo "the plan returned {$meta ["returned"]} of {$meta ["total"]}\n";
```

The query is the usual [PublicWWW syntax](https://publicwww.com/docs/query-syntax/):
`"exact phrase"`, several words for all of them, `-word` to exclude, `site:de`,
`depth:all` for inner pages.

## Errors

Any refusal throws `PublicWWW\Error` with `->status` and `->errorCode`, except one: `429 too_many_requests` (more than ten
requests a minute) is waited out using `Retry-After` and the request is repeated.
`429 quota_exceeded` is different - the day's searches are used up, retrying will
not help. All codes: [errors](https://publicwww.com/docs/api/errors/).

`PUBLICWWW_API` overrides the API address if you ever need to.

## See also

- [API documentation](https://publicwww.com/docs/api/)
- [MCP server](https://publicwww.com/docs/mcp/) - the same search for AI assistants:
  `https://api.publicwww.com/mcp`
- The same client in [Python](https://github.com/publicwww-com/publicwww-api-python), [JavaScript](https://github.com/publicwww-com/publicwww-api-javascript), [Go](https://github.com/publicwww-com/publicwww-api-go), [Ruby](https://github.com/publicwww-com/publicwww-api-ruby)

## License

MIT
