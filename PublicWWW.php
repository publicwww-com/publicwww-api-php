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
 *   php PublicWWW.php account
 *   php PublicWWW.php search '"angular.min.js"' 20
 *   php PublicWWW.php export '"angular.min.js"' > sites.txt
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
	const USER_AGENT = "publicwww-api-php/1.0";

	private $key;
	private $api;

	public function __construct ($key = null, $api = null) {
		$this->key = $key ?: (string) getenv ("PUBLICWWW_KEY");
		$this->api = rtrim ($api ?: (getenv ("PUBLICWWW_API") ?: "https://api.publicwww.com"), "/");
		if ($this->key === "") throw new Error (0, "missing_key", "set PUBLICWWW_KEY or pass a key");
	}

	/** GET a path and return an open stream. Waits out the rate limit; any other error is thrown. */
	private function open ($path, array $params = array ()) {
		$url = $this->api . $path . ($params ? "?" . http_build_query ($params) : "");
		$ctx = stream_context_create (array ("http" => array (
			"header"        => "Authorization: Bearer " . $this->key . "\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
			"ignore_errors" => true,          // read the error body instead of failing
			"timeout"       => 600,
		)));

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
			if ($status === 200) return $fp;

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

	private function json ($path, array $params = array ()) {
		$fp = $this->open ($path, $params);
		$data = json_decode (stream_get_contents ($fp), true);
		fclose ($fp);
		return $data;
	}

	/** Plan, quota and limits. Spends nothing. */
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
}

// ---- command line ------------------------------------------------------------

if (PHP_SAPI === "cli" && isset ($argv [0]) && realpath ($argv [0]) === __FILE__) {
	$cmd   = isset ($argv [1]) ? $argv [1] : "";
	$query = isset ($argv [2]) ? $argv [2] : "";
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
		} else {
			fwrite (STDERR, "usage: php PublicWWW.php account | search QUERY [PER_PAGE] | export QUERY\n");
			exit (2);
		}
	} catch (Error $e) {
		fwrite (STDERR, $e->getMessage () . "\n");
		exit (1);
	}
}
