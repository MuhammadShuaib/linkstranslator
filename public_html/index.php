<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
$json = json_encode(translateLinks(
	isset($_REQUEST['p']) ? (is_array($_REQUEST['p']) ? $_REQUEST['p'] : [$_REQUEST['p']]) : [],
	isset($_REQUEST['from']) ? $_REQUEST['from'] : 'enwiki',
	isset($_REQUEST['to']) ? $_REQUEST['to'] : 'fawiki'
));
echo $json !== '[]' ? $json : '{}';

function translateLinks($pages, $fromWiki, $toWiki) {
	$fromWiki = strtolower($fromWiki);
	if (preg_match('/^[a-z_]{1,20}$/', $fromWiki) === 0) { return []; };
	if (preg_match('/wiki$/', $fromWiki) === 0) { $fromWiki = $fromWiki . 'wiki'; }
	$toWiki = strtolower($toWiki);
	if (preg_match('/^[a-z_]{1,20}$/', $toWiki) === 0) { return []; };
	if (preg_match('/wiki$/', $toWiki) === 0) { $toWiki = $toWiki . 'wiki'; }
	
	$redirects = [];
	$resolvedPages = getResolvedRedirectPages($pages, $fromWiki, $redirects);
	$entities = getWikidataEntities($resolvedPages, $fromWiki);
	$equs = [];

	foreach ($entities as $entity) {
		if (!isset($entity['sitelinks']) || !isset($entity['sitelinks'][$toWiki])) { continue; }

		// not updated Wikidata items may don't have title on their sitelinks
		$from = isset($entity['sitelinks'][$fromWiki]['title'])
			? $entity['sitelinks'][$fromWiki]['title']
			: $entity['sitelinks'][$fromWiki];
		$to = isset($entity['sitelinks'][$toWiki]['title'])
			? $entity['sitelinks'][$toWiki]['title']
			: $entity['sitelinks'][$toWiki];

		$equs[$from] = $to;
	}
	
	$result = [];
	foreach ($pages as $i) {
		$page = isset($redirects[$i]) ? $redirects[$i] : $i;
		if (isset($equs[$page])) { $result[$i] = $equs[$page]; }

		$i = str_replace('_', ' ', $i);
		$page = isset($redirects[$i]) ? $redirects[$i] : $i;
		if (isset($equs[$page])) { $result[$i] = $equs[$page]; }
	}
	return $result;
}

function getWikidataEntities($pages, $fromWiki) {
	$apiResultArray = batchApi('wikidatawiki', $pages, function ($batch) use ($fromWiki) {
		return [
			'action' => 'wbgetentities',
			'format' => 'json',
			'sites' => $fromWiki,
			'titles' => implode('|', $batch),
			'props' => 'sitelinks'
		];
	});
	$entities = [];
	foreach ($apiResultArray as $i) {
		$json = json_decode($i, true);
		if (is_array($json) && isset($json['entities'])) {
			foreach ($json['entities'] as $entity) {
				$entities[] = $entity;
			}
		}
	}
	return $entities;
}

function getResolvedRedirectPages($pages, $fromWiki, &$redirects) {
	$apiResultArray = batchApi($fromWiki, $pages, function ($batch) {
		return [
			'action' => 'query',
			'format' => 'json',
			'redirects' => '',
			'titles' => implode('|', $batch)
		];
	});
	$titles = [];
	foreach ($apiResultArray as $i) {
		$json = json_decode($i, true);
		if (!is_array($json) || !isset($json['query'])) { continue; }
		$query = $json['query'];
		$queryPages = $query['pages'];
		if (isset($query['redirects'])) {
			foreach ($query['redirects'] as $x) {
				$redirects[$x['from']] = $x['to'];
			}
		}
		if (isset($query['normalized'])) {
			foreach ($query['normalized'] as $x) {
				$redirects[$x['from']] = $x['to'];
			}
		}
		foreach ($queryPages as $x) {
			$titles[] = $x['title'];
		}
	}
	return $titles;
}

function dbNameToOrigin($dbName) {
	if ($dbName === 'wikidatawiki') { return 'www.wikidata.org'; }
	if ($dbName === 'commonswiki') { return 'commons.wikimedia.org'; }
	$p = explode('wiki', $dbName);
	return str_replace("_", "-", $p[0]) . '.wiki' . (isset($p[1]) && strlen($p[1]) ? $p[1] : 'pedia') . '.org';
}

function batchApi($dbName, $pages, $requestCreator) {
	$host = dbNameToOrigin($dbName);
	$batches = array_chunk($pages, 50);
	return multiRequest(array_map(function ($data) use ($host, $requestCreator) {
		return 'https://' . $host . '/w/api.php?' . http_build_query($requestCreator($data));
	}, $batches), [CURLOPT_SSL_VERIFYPEER => false]);
}

// http://www.phpied.com/simultaneuos-http-requests-in-php-with-curl/
function multiRequest($data, $options = array()) {
 
  // array of curl handles
  $curly = array();
  // data to be returned
  $result = array();
 
  // multi handle
  $mh = curl_multi_init();
 
  // loop through $data and create curl handles
  // then add them to the multi-handle
  foreach ($data as $id => $d) {
 
    $curly[$id] = curl_init();
 
    $url = (is_array($d) && !empty($d['url'])) ? $d['url'] : $d;
    curl_setopt($curly[$id], CURLOPT_URL,            $url);
    curl_setopt($curly[$id], CURLOPT_HEADER,         0);
    curl_setopt($curly[$id], CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curly[$id], CURLOPT_USERAGENT,      'linkstranslator (github.com/ebraminio/linkstranslator)');

 
    // post?
    if (is_array($d)) {
      if (!empty($d['post'])) {
        curl_setopt($curly[$id], CURLOPT_POST,       1);
        curl_setopt($curly[$id], CURLOPT_POSTFIELDS, $d['post']);
      }
    }
 
    // extra options?
    if (!empty($options)) {
      curl_setopt_array($curly[$id], $options);
    }
 
    curl_multi_add_handle($mh, $curly[$id]);
  }
 
  // execute the handles
  $running = null;
  do {
    curl_multi_exec($mh, $running);
  } while($running > 0);
 
 
  // get content and remove handles
  foreach($curly as $id => $c) {
    $result[$id] = curl_multi_getcontent($c);
    curl_multi_remove_handle($mh, $c);
  }
 
  // all done
  curl_multi_close($mh);
 
  return $result;
}
