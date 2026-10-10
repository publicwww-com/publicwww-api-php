<?php
/**
 * A small client for the PublicWWW API - https://publicwww.com/docs/api/
 * PHP 7.4+, no extensions beyond openssl (https) and json.
 *
 *   require "PublicWWW.php";
 *   $pw   = new PublicWWW\Client ();            // key from $PUBLICWWW_KEY
 *   $page = $pw->search ('"angular.min.js"', 1, 20);
 *   foreach ($pw->iterAll ('"angular.min.js"') as $row) echo $row ["domain"], "\n";
 *
 *   $c = $pw->createCluster ('"googletagmanager.com/gtm.js"');
 *   foreach ($pw->iterExtract ($c ["id"], array ("email", "phone")) as $row) echo $row ["domain"], "\n";
 *
 *   php PublicWWW.php account
 *   php PublicWWW.php search '"angular.min.js"' 20
 *   php PublicWWW.php export '"angular.min.js"' > sites.txt
 *   php PublicWWW.php cluster-create --query '"googletagmanager.com/gtm.js"'
 *   php PublicWWW.php extract 12 --presets email,phone > contacts.csv
 */

namespace PublicWWW;

class Error extends \Exception {
	public $status;
	public $errorCode;

	public function __construct ($status, $code, $message) {
		parent::__construct ("$status $code: $message");
		$this->status    = $status;
		$this->errorCode = $code;
	}
}

class Client {
	const USER_AGENT = "publicwww-api-php/1.1";

	private $key;
	private $api;

	public function __construct ($key = null, $api = null) {
		$this->key = $key ?: (string) getenv ("PUBLICWWW_KEY");
		$this->api = rtrim ($api ?: (getenv ("PUBLICWWW_API") ?: "https://api.publicwww.com"), "/");
		if ($this->key === "") throw new Error (0, "missing_key", "set PUBLICWWW_KEY or pass a key");
	}

	/**
	 * GET a path, or POST $body as JSON when it is given, and return an open
	 * stream. Waits out the rate limit; any other error is thrown.
	 */
	private function open ($path, array $params = array (), $body = null) {
		$url = $this->api . $path . ($params ? "?" . http_build_query ($params) : "");
		$http = array (
			"header"        => "Authorization: Bearer " . $this->key . "\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
			"ignore_errors" => true,          // read the error body instead of failing
			"timeout"       => 600,
		);
		if ($body !== null) {
			$http ["method"]   = "POST";
			$http ["header"]  .= "Content-Type: application/json\r\n";
			$http ["content"]  = json_encode ($body, JSON_UNESCAPED_SLASHES);
		}
		$ctx = stream_context_create (array ("http" => $http));

		for (;;) {
			$fp = @fopen ($url, "r", false, $ctx);
			if ($fp === false) throw new Error (0, "network", "could not reach " . $this->api);
			// The response headers, the same way on every PHP version (the
			// old $http_response_header variable is deprecated in 8.5).
			$meta    = stream_get_meta_data ($fp);
			$headers = isset ($meta ["wrapper_data"]) ? (array) $meta ["wrapper_data"] : array ();

			$status = 0;
			$retry  = 0;
			foreach ($headers as $h) {
				if (preg_match ('|^HTTP/\S+\s+(\d+)|', $h, $m)) $status = (int) $m [1];   // the last one wins
				if (preg_match ('|^Retry-After:\s*(\d+)|i', $h, $m)) $retry = (int) $m [1];
			}
			if ($status >= 200 && $status < 300) return $fp;

			$body = json_decode (stream_get_contents ($fp), true);
			fclose ($fp);
			$err = isset ($body ["error"]) ? $body ["error"] : array ();
			$code = isset ($err ["code"]) ? $err ["code"] : "http_error";

			// Two different 429s: too_many_requests means "slow down and repeat",
			// quota_exceeded means "come back tomorrow".
			if ($status === 429 && $code === "too_many_requests") {
				sleep ($retry ?: (isset ($err ["retry_after"]) ? (int) $err ["retry_after"] : 10));
				continue;
			}
			throw new Error ($status, $code, isset ($err ["message"]) ? $err ["message"] : "");
		}
	}

	private function json ($path, array $params = array (), $body = null) {
		$fp = $this->open ($path, $params, $body);
		$data = json_decode (stream_get_contents ($fp), true);
		fclose ($fp);
		return $data;
	}

	/** Plan, quota and limits, clusters included. Spends nothing. */
	public function account () {
		return $this->json ("/v1/account");
	}

	/** One page of results: total, total_pages, truncated, results, ... */
	public function search ($query, $page = 1, $perPage = 100, array $params = array ()) {
		return $this->json ("/v1/search", array ("query" => $query, "page" => $page, "per_page" => $perPage) + $params);
	}

	/**
	 * Every row of a query your plan covers, streamed as NDJSON: one search,
	 * nothing held in memory. $perPage defaults to the plan's max_per_page
	 * (asking for more is a 400 per_page_too_large). $meta receives the
	 * metadata line (total, returned, truncated).
	 */
	public function iterAll ($query, $perPage = null, &$meta = null, array $params = array ()) {
		if (!$perPage) {
			$acc = $this->account ();
			$perPage = $acc ["limits"] ["max_per_page"];
		}
		$fp = $this->open ("/v1/search", array ("query" => $query, "per_page" => $perPage, "format" => "ndjson") + $params);
		while (($line = fgets ($fp)) !== false) {
			if (trim ($line) === "") continue;
			$obj = json_decode ($line, true);
			if (isset ($obj ["object"]) && $obj ["object"] === "meta") { $meta = $obj; continue; }   // the first line
			yield $obj;
		}
		fclose ($fp);
	}

	// ---- clusters: saved lists of sites, to extract data from or combine ----

	/** The account's clusters, newest first: count, max, clusters (id, name, size, created). */
	public function clusters () {
		return $this->json ("/v1/clusters");
	}

	/** One cluster and a page of its domains ($perPage up to 10000). */
	public function cluster ($id, $page = 1, $perPage = 1000) {
		return $this->json ("/v1/clusters/" . (int) $id, array ("page" => $page, "per_page" => $perPage));
	}

	/** Every domain of a cluster, page after page. */
	public function iterCluster ($id, $perPage = 10000) {
		for ($page = 1; ; $page ++) {
			$res = $this->cluster ($id, $page, $perPage);
			foreach ($res ["domains"] as $d) yield $d;
			if ($page >= $res ["total_pages"]) return;
		}
	}

	/** A new cluster from a search; costs one search, like search(). 409 cluster_limit when the account has 100. */
	public function createCluster ($query, $name = null) {
		return $this->json ("/v1/clusters", array (), array ("query" => $query) + ($name ? array ("name" => $name) : array ()));
	}

	/** A new cluster from a list of domains or urls; only sites in the index are kept. */
	public function createClusterFromDomains (array $domains, $name = null) {
		return $this->json ("/v1/clusters", array (), array ("domains" => array_values ($domains)) + ($name ? array ("name" => $name) : array ()));
	}

	/**
	 * A new cluster: "and" - domains in every cluster, "or" - in any, "diff" -
	 * in the first but not in the second (exactly two).
	 */
	public function combineClusters ($operation, array $ids, $name = null) {
		$body = array ("operation" => $operation, "clusters" => array_map ("intval", array_values ($ids)));
		return $this->json ("/v1/clusters/combine", array (), $body + ($name ? array ("name" => $name) : array ()));
	}

	/** Ready-made extraction expressions: presets (name, regex, about). */
	public function clusterPresets () {
		return $this->json ("/v1/clusters/presets");
	}

	/**
	 * One part of an extraction: domains [offset, offset + limit) of the
	 * cluster (limit up to 1000). $presets are names ("email", "phone", ...),
	 * $regex your own PCRE in slashes, the first group is the value. The
	 * answer has rows (domain, values - one list per expression, matches) and
	 * next_offset, null when the cluster is done. Costs extraction points.
	 */
	public function extract ($id, array $presets = array (), array $regex = array (), $offset = 0, $limit = 1000, $skipEmpty = true) {
		return $this->json ("/v1/clusters/" . (int) $id . "/extract", array (), array (
			"presets" => array_values ($presets), "regex" => array_values ($regex),
			"offset" => (int) $offset, "limit" => (int) $limit, "skip_empty" => (bool) $skipEmpty,
		));
	}

	/**
	 * Every row of an extraction over the whole cluster from $offset on, part
	 * after part (following next_offset). $meta receives the last part's
	 * numbers (regex, points_left, ...). If the day's extraction points run
	 * out midway, throws Error extract_quota_exceeded; its message says the
	 * offset to resume from.
	 */
	public function iterExtract ($id, array $presets = array (), array $regex = array (), &$meta = null, $offset = 0, $limit = 1000, $skipEmpty = true) {
		while ($offset !== null) {
			$part = $this->extract ($id, $presets, $regex, $offset, $limit, $skipEmpty);
			foreach ($part ["rows"] as $r) yield $r;
			unset ($part ["rows"]);
			$meta = $part;
			if (isset ($part ["stopped"]) && $part ["stopped"] === "extract_quota_exceeded")
				throw new Error (429, "extract_quota_exceeded", "the day's extraction points ran out; resume with offset=" . $part ["next_offset"]);
			$offset = $part ["next_offset"];
		}
	}

	public function renameCluster ($id, $name) {
		return $this->json ("/v1/clusters/" . (int) $id . "/rename", array (), array ("name" => $name));
	}

	/** Deletes the cluster for good: deleted (the id), name. */
	public function deleteCluster ($id) {
		return $this->json ("/v1/clusters/" . (int) $id . "/delete", array (), new \stdClass ());
	}
}

// ---- command line ------------------------------------------------------------

if (PHP_SAPI === "cli" && isset ($argv [0]) && realpath ($argv [0]) === __FILE__) {
	$cmd   = isset ($argv [1]) ? $argv [1] : "";
	$query = isset ($argv [2]) ? $argv [2] : "";
	// Positional arguments and --options; --regex may repeat.
	$pos = array ();
	$opt = array ("regex" => array ());
	for ($i = 2; $i < count ($argv); $i ++) {
		if (!preg_match ('|^--([a-z-]+)$|', $argv [$i], $m)) { $pos []= $argv [$i]; continue; }
		$v = isset ($argv [$i + 1]) ? $argv [++ $i] : "";
		if ($m [1] === "regex") $opt ["regex"] []= $v; else $opt [$m [1]] = $v;
	}
	$name = isset ($opt ["name"]) ? $opt ["name"] : null;
	$line = function ($c) { return $c ["id"] . "\t" . $c ["size"] . "\t" . $c ["created"] . "\t" . $c ["name"] . "\n"; };
	try {
		$pw = new Client ();
		if ($cmd === "account") {
			echo json_encode ($pw->account (), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
		} elseif ($cmd === "search" && $query !== "") {
			$page = $pw->search ($query, 1, isset ($argv [3]) ? (int) $argv [3] : 20);
			echo $page ["total"], " sites match, page ", $page ["page"], " of ", $page ["total_pages"],
				$page ["truncated"] ? " (cut short by the plan)" : "", "\n";
			foreach ($page ["results"] as $r) echo $r ["rank"], "\t", $r ["domain"], "\t", $r ["url"], "\n";
		} elseif ($cmd === "export" && $query !== "") {
			$meta = null;
			foreach ($pw->iterAll ($query, null, $meta) as $r) echo $r ["url"], "\n";
			if (!empty ($meta ["truncated"])) fwrite (STDERR, "note: the plan returned {$meta ["returned"]} of {$meta ["total"]}\n");
		} elseif ($cmd === "clusters") {
			$res = $pw->clusters ();
			echo $res ["count"], " clusters of at most ", $res ["max"], "\n";
			foreach ($res ["clusters"] as $c) echo $line ($c);
		} elseif ($cmd === "cluster" && isset ($pos [0])) {
			foreach ($pw->iterCluster ($pos [0]) as $d) echo $d, "\n";
		} elseif ($cmd === "cluster-create" && isset ($opt ["query"])) {
			echo $line ($pw->createCluster ($opt ["query"], $name));
		} elseif ($cmd === "cluster-create" && isset ($opt ["domains-file"])) {
			$f = $opt ["domains-file"] === "-" ? "php://stdin" : $opt ["domains-file"];
			$text = @file_get_contents ($f);
			if ($text === false) throw new Error (0, "file", "could not read $f");
			$domains = array_values (array_filter (array_map ("trim", preg_split ('|\r?\n|', $text)), "strlen"));
			echo $line ($pw->createClusterFromDomains ($domains, $name));
		} elseif ($cmd === "cluster-combine" && count ($pos) >= 3) {
			echo $line ($pw->combineClusters ($pos [0], array_slice ($pos, 1), $name));
		} elseif ($cmd === "presets") {
			foreach ($pw->clusterPresets () ["presets"] as $p) echo $p ["name"], "\t", $p ["about"], "\n";
		} elseif ($cmd === "extract" && isset ($pos [0])) {
			$presets = array_values (array_filter (array_map ("trim", explode (",", isset ($opt ["presets"]) ? $opt ["presets"] : "")), "strlen"));
			$offset  = isset ($opt ["offset"]) ? (int) $opt ["offset"] : 0;
			if ($offset === 0) fputcsv (STDOUT, array_merge (array ("domain"), $presets, $opt ["regex"]), ",", "\"", "");
			$meta = null;
			$n = 0;
			foreach ($pw->iterExtract ($pos [0], $presets, $opt ["regex"], $meta, $offset) as $r) {
				$cells = array ($r ["domain"]);
				foreach ($r ["values"] as $v) $cells []= join ("\n", $v);
				fputcsv (STDOUT, $cells, ",", "\"", "");
				$n ++;
			}
			fwrite (STDERR, "$n sites with matches, {$meta ["points_left"]} extraction points left\n");
		} elseif ($cmd === "cluster-rename" && count ($pos) >= 2) {
			echo $line ($pw->renameCluster ($pos [0], $pos [1]));
		} elseif ($cmd === "cluster-delete" && isset ($pos [0])) {
			$res = $pw->deleteCluster ($pos [0]);
			echo "deleted ", $res ["deleted"], "\t", $res ["name"], "\n";
		} else {
			fwrite (STDERR, "usage: php PublicWWW.php account | search QUERY [PER_PAGE] | export QUERY\n"
				. "       | clusters | cluster ID | cluster-create (--query Q | --domains-file F) [--name N]\n"
				. "       | cluster-combine and|or|diff ID ID... [--name N] | presets\n"
				. "       | extract ID [--presets a,b] [--regex R]... [--offset N] | cluster-rename ID NAME | cluster-delete ID\n");
			exit (2);
		}
	} catch (Error $e) {
		fwrite (STDERR, $e->getMessage () . "\n");
		exit (1);
	}
}
