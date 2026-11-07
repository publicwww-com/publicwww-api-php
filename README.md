# PublicWWW API - PHP

A small client for the [PublicWWW API](https://publicwww.com/docs/api/): search the
source code (HTML, JavaScript, CSS) of web pages from your
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

## Clusters

A cluster is a saved list of sites - the results of a search or your own list - to
pull values out of their page source or to combine with other clusters. See
[clusters in the API](https://publicwww.com/docs/api/clusters/).

```sh
php PublicWWW.php cluster-create --query '"googletagmanager.com/gtm.js"'   # prints id, size, created, name
php PublicWWW.php cluster-create --query 'like:"online course" site:de' --limit 100   # sites about a topic; like: removes nothing, so pass --limit
php PublicWWW.php extract 12 --presets email,phone > contacts.csv          # the whole cluster, part after part
php PublicWWW.php extract 12 --presets email --delimiter ';' > emails.csv
php PublicWWW.php signatures 12    # code the sites share, as searches for more like them
php PublicWWW.php extract 12 --regex '/data-site-id="([0-9]+)"/i' > ids.csv
php PublicWWW.php cluster-combine diff 14 12 --name "new this month"       # also: and, or
php PublicWWW.php clusters      # and: cluster ID, presets, cluster-rename ID NAME, cluster-delete ID
```

```php
$gtm  = $pw->createCluster ('"googletagmanager.com/gtm.js"');          // costs one search
$mine = $pw->createClusterFromDomains (array ("example.com", "https://example.org/"), "mine");
$topic = $pw->createCluster ('like:"online course" site:de', null, 100);   // the first 100 by similarity

// Every site, in parts of up to 1000 (following next_offset); spends extraction points.
foreach ($pw->iterExtract ($gtm ["id"], array ("email", "phone")) as $row) {
    list ($emails, $phones) = $row ["values"];      // one list per expression
    echo $row ["domain"], "\t", join (" ", $emails), "\t", join (" ", $phones), "\n";
}

$hotjar = $pw->createCluster ('"static.hotjar.com"');
$both = $pw->combineClusters ("and", array ($gtm ["id"], $hotjar ["id"]));   // "or"; "diff" - the first without the second
foreach ($pw->iterCluster ($both ["id"]) as $domain) echo $domain, "\n";
$pw->deleteCluster ($hotjar ["id"]);
```

`clusterPresets ()` lists the ready-made expressions: email, phone, whatsapp,
telegram, skype, facebook, instagram, twitter, linkedin, gtm, ga4, ua, hotjar,
adsense, bitcoin. Your own `$regex` is a PCRE in slashes; the first group is the
value. `extract ()` is one part, `next_offset` says where the next one starts. An
account keeps up to 100 clusters (`409 cluster_limit` past that). If the day's
extraction points run out midway, `iterExtract` throws `extract_quota_exceeded`
with the offset to resume from (`extract ... --offset N`).

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
