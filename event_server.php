<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

require_once './vendor/autoload.php';
use Symfony\Component\DomCrawler\Crawler;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;

session_start();
$inputString = isset($_SESSION['inputString']) ? $_SESSION['inputString'] : '';

function sendUpdate($message) {
    echo "data: $message" . PHP_EOL;
    echo PHP_EOL;
    flush();
}

if (!empty($inputString)) {
    $formatted_string = str_replace(' ', '+', trim($inputString));
}

$search_results = [
	"https://www.ebay.com/sch/i.html?_from=R40&_nkw={$formatted_string}&_sacat=0&rt=nc&_ipg=240&_pgn=1",
	"https://www.ebay.com/sch/i.html?_from=R40&_nkw={$formatted_string}&_sacat=0&rt=nc&_ipg=240&_pgn=2",
	"https://www.ebay.com/sch/i.html?_from=R40&_nkw={$formatted_string}&_sacat=0&rt=nc&_ipg=240&_pgn=3",
	"https://www.ebay.com/sch/i.html?_from=R40&_nkw={$formatted_string}&_sacat=0&rt=nc&_ipg=240&_pgn=4",
	"https://www.ebay.com/sch/i.html?_from=R40&_nkw={$formatted_string}&_sacat=0&rt=nc&_ipg=240&_pgn=5",
	"https://www.ebay.com/sch/i.html?_from=R40&_nkw={$formatted_string}&_sacat=0&rt=nc&_ipg=240&_pgn=6" 
];

$all_data = array();
$client = new Client(['verify' => false]);

 $promises = array_map(function ($url) use ($client) {
	return $client->requestAsync('GET', $url);
}, $search_results);

foreach ($promises as $index => $promise) {
	$promise->then(
		function ($response) use ($client, &$all_data, &$conn, $index, $search_results) {
			$html = $response->getBody()->getContents();
			$crawler = new Crawler($html);
			$item_urls = $crawler->filter('.s-item__wrapper')->each(function ($node) use (&$all_data) {
				$url = $node->filter('a.s-item__link')->attr('href');
				$title_ = str_replace("Opens in a new window or tab", "", $node->filter('a.s-item__link')->text());
				$title = str_replace("New Listing", "", $title_);
				$price = $node->filter('span.s-item__price')->text();
				$img_node = $node->filter('.s-item__image-wrapper.image-treatment img');
				if ($img_node->count() > 0) {
					$img_url = $img_node->attr('src');
				} else {
					$img_url = '';
				}
				$all_data[$url] = array(
					'url' => $url,
					'title' => $title,
					'price' => $price,
					'img_url' => $img_url,
					'location' => '',
				);
				return $url;
			});
			
			$item_pool = new Pool($client, array_map(function ($url) {
				return new Request('GET', $url);
			}, $item_urls), [
				'concurrency' => 6,
				'fulfilled' => function ($response, $index) use (&$all_data, $item_urls, &$conn) {
					$html = $response->getBody()->getContents();
					$doc = new DOMDocument();
					@$doc->loadHTML($html);
					$xpath = new DOMXPath($doc);
					$nodes = $xpath->query('//span[contains(@class, "ux-textspans ux-textspans--SECONDARY") and contains(., "Located in:")]');
					if ($nodes->length > 0) {
						$all_data[$item_urls[$index]]['location'] = str_replace("Located in: ", "", $nodes->item(0)->nodeValue);
					}
					print_r($all_data[$item_urls[$index]]);
					sendUpdate(json_encode($all_data[$item_urls[$index]]));
				},
			]);
			$item_pool->promise()->wait();
		}
	)->wait();
	sendUpdate("stop");
}
	
	

