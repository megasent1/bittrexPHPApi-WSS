<?php
/**
 * Created by PhpStorm.
 * User: MeGa
 * Date: 21.01.2018
 * Time: 22:38
 */

use WebSocket\Client;


class Bittrex {
	// cloudflare vars
	public static $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36';
	public static $cfHiddenPage = "https://bittrex.com/Home/Api"; // page behind cloudflare protection for testing
	public $cookies = null;
	// api vars
	public $pair;
	public $marketSummary;
	public $marketSummaries;
	private $wssClient;

	// api methods
	public function __construct( $pair = 'BTC-ETH', $checkApi = false, $checkCf = true ) {
		$this->pair = $pair;
		if ( $checkCf ) {
			try {
				$this->cfPass();
			} catch ( Exception $exception ) {
//			var_dump($exception);
				return false;
			}
		}
		if ( $checkApi ) {
			if ( ! $checkCf ) {
				try {
					$this->cfPass();
				} catch ( Exception $exception ) {
					return false;
				}
			}
			if ( ! $this->checkApiIsUp() ) {
				return false;
			}
		}

		return $this;

	}

	/**
	 * pass cloudlfare protection
	 * @return bool
	 * @throws Exception
	 */
	private function cfPass() {
		$httpProxy   = new httpProxy();
		$requestPage = json_decode( $httpProxy->performRequest( self::$cfHiddenPage ) );
		if ( $requestPage->status->http_code == 503 ) {

			// Make this the same user agent you use for other cURL requests in your app
			cloudflare::useUserAgent( self::$userAgent );

			// attempt to get clearance cookie
			if ( $clearanceCookie = cloudflare::bypass( self::$cfHiddenPage ) ) {
				$this->cookies = $clearanceCookie;

				return true;
			} else {
				// could not fetch clearance cookie
				throw new Exception( 'Could not fetch CloudFlare clearance cookie (most likely due to excessive requests)' );
			}
		}

		return true;

	}

	private function noCfCURLQuery( $query ) {
		if ( ! $query ) {
			return false;
		}

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 4 );
		//build url
		curl_setopt( $ch, CURLOPT_URL, $query );

		// run the query
		return curl_exec( $ch );
	}

	private function useCfCURLQuery( $query ) {
		if ( ! $query ) {
			return false;
		}
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 4 );
		curl_setopt( $ch, CURLOPT_COOKIE, $this->cookies );
		curl_setopt( $ch, CURLOPT_USERAGENT, self::$userAgent );
		//build url
		curl_setopt( $ch, CURLOPT_URL, $query );

		// run the query
		return curl_exec( $ch );
	}

	/**
	 * Check if API is working
	 * @return bool
	 */
	public function checkApiIsUp() {
		$result = $this->useCfCURLQuery( "https://socket.bittrex.com/signalr/ping" );
		if ( $result ) {
			return true;
		}

		return false;
	}

	/**
	 * Gets last filled order price
	 * @return bool
	 */
	public function getLastPrice() {
		$history = $this->getMarketHistory();
		if ( $history ) {
			return $history[0]['Price'];
		}

		return false;
	}

	/**
	 * Gets the history of a current market.
	 *
	 * @return bool|array
	 */
	public function getMarketHistory() {

		$response = $this->noCfCURLQuery( "https://bittrex.com/api/v1.1/public/getmarkethistory?market={$this->pair}" );
		$response = json_decode( $response, true );
		if ( $response['success'] ) {
			return $response['result'];
		} else {
			return false;
		}
	}

	/**
	 * Gets all the market summaries.
	 *
	 * @param bool $update
	 *
	 * @return bool|array
	 */
	public function getMarketSummaries( $update = false ) {
		if ( $this->marketSummaries && ! $update ) {
			return $this->marketSummaries;
		} else {

			$response = $this->noCfCURLQuery( "https://bittrex.com/api/v1.1/public/getmarketsummaries" );
			$response = json_decode( $response, 1 );
			if ( $response['success'] ) {
				return $response['result'];
			} else {
				return false;
			}

		}
	}

	/**
	 * Gets the summary of a single market.
	 *
	 * @return bool|array
	 */
	public function getMarketSummary() {
		if ( ! $this->marketSummary ) {
			$allMarkets = $this->getMarketSummaries();
			foreach ( $allMarkets as $market ) {
				if ( $market['MarketName'] == $this->pair ) {
					$this->marketSummary = $market;

					return $this->marketSummary;
				}
			}
		} else {
			return $this->marketSummary;
		}

		return false;
	}

	/**
	 * Get 24hours base volume for pair
	 * @return mixed
	 */
	public function get24HoursBaseVolume() {
		$response = $this->getMarketSummary();

		return $response['BaseVolume'];

	}

	/**
	 * Get array of counted BUY and SELL orders
	 * @return array
	 */
	public function getOrdersCount() {
		$summary = $this->getMarketSummary();

		return [ 'BUY' => $summary['OpenBuyOrders'], 'SELL' => $summary['OpenSellOrders'] ];

	}

	/**
	 * calculate orders balance
	 * @return string
	 */
	public function getOrdersBalance() {
		$orders = $this->getOrdersCount();
		if ( $orders['BUY'] > $orders['SELL'] ) {
			return "OPENED: BUY cnt > SELL cnt x " . number_format( ( $orders['BUY'] / $orders['SELL'] ), 3 );
		} elseif ( $orders['SELL'] > $orders['BUY'] ) {
			return "OPENED: SELL cnt > BUY cnt x " . number_format( ( $orders['SELL'] / $orders['BUY'] ), 3 );
		} else {
			return "OPENED: BUY cnt = SELL cnt";
		}

	}


	/**
	 * V2.0 Gets last candle
	 *
	 * @param int $period period in minutes, must be in [ 1, 5, 30, 60, 1440 ] .
	 *
	 * @return bool|array
	 *  returns array like below
	 *  'O' => float 4.401E-5 // open price
	 * 'H' => float 4.403E-5 // high price
	 * 'L' => float 4.384E-5 // low price
	 * 'C' => float 4.403E-5 // close price
	 * 'V' => float 89661.56555246 // 24H volume
	 * 'T' => string '2018-01-15T03:15:00' // candle start time
	 * 'BV' => float 3.94374189 // volume for the period in base quote
	 */
	public function getLastCandle( $period = 5 ) {
		switch ( $period ) {
			case 1:
				$period = 'oneMin';
				break;
			case 5:
				$period = 'fiveMin';
				break;
			case 30:
				$period = 'thirtyMin';
				break;
			case 60:
				$period = 'hour';
				break;
			case 1440:
				$period = 'day';
				break;
			default:
				$period = 'fiveMin';
				break;
		}

		$response = $this->noCfCURLQuery( "https://bittrex.com/Api/v2.0/pub/market/GetLatestTick?marketName={$this->pair}&tickInterval={$period}" );
		$response = json_decode( $response, 1 );
		if ( $response['success'] ) {
			return $response['result'][0];
		} else {
			return false;
		}

	}

	/**
	 * V2.0 Gets last candle and it's actual last time price
	 *
	 * @param int $period
	 *
	 * @return array|bool
	 */
	public function lastCandleExactPrice( $period = 5 ) {
		$candle    = $this->getLastCandle( $period );
		$lastPrice = $this->getLastPrice();
		if ( $lastPrice ) {
			$candle['C'] = $lastPrice;
		}

		return $candle;
	}

	/**
	 *  V2.0 get CoinDesk Bitcoin Price Index. Non-USD currency data converted using hourly conversion rate from openexchangerates.org
	 * @return bool|array
	 */
	public function getBtcPrice() {

		$response = json_decode( $this->noCfCURLQuery( "https://bittrex.com/api/v2.0/pub/currencies/GetBTCPrice" ), 1 );
		if ( $response['success'] ) {
			return $response['result'];
		} else {
			return false;
		}
	}

	/**
	 *  V2.0 Gets the candles for a market.
	 *
	 * @param int $period period in minutes, must be in [ 1, 5, 30, 60, 1440 ] .
	 *
	 * @return bool|array
	 */
	public function getTicks( $period = 5 ) {
		switch ( $period ) {
			case 1:
				$period = 'oneMin';
				break;
			case 5:
				$period = 'fiveMin';
				break;
			case 30:
				$period = 'thirtyMin';
				break;
			case 60:
				$period = 'hour';
				break;
			case 1440:
				$period = 'day';
				break;
			default:
				$period = 'fiveMin';
				break;
		}
		$maxTries = 5;
		$i = 0;
		$response = false;
		while (!$response && $i < $maxTries) {
			$response = $this->noCfCURLQuery( "https://bittrex.com/Api/v2.0/pub/market/GetTicks?marketName={$this->pair}&tickInterval={$period}" );
			$i++;
		}
		$response = json_decode( $response, true );
		if ( $response['success'] ) {
			return $response['result'];
		} else {
			return false;
		}

	}


	/**
	 * make WSS connection as singleton
	 * @return Client
	 * @throws \WebSocket\ConnectionException
	 */
	public function getWssClient() {
		if ( ! $this->wssClient ) {
			$this->wssClient = new Client( "wss://socket.bittrex.com/signalr/connect?transport=webSockets&clientProtocol=1.5&connectionToken=GdZ4Rx88j7PkWtqp5ttcw1F%2FDGMFPBegN3KLQi7uK5GyeF5hCH9%2BiV8kPz0hyI%2B0OugNzGbwkoPjRiosWQBQACwZQx2ccxHcEYdev7UeZbjobRR0&connectionData=%5B%7B%22name%22%3A%22corehub%22%7D%5D&tid=3",
				[
					'headers' =>
						[
							'user-agent' => self::$userAgent,
							'Cookie'     => $this->cookies
						]
				]
			);
			$this->wssClient->receive();
		}

		return $this->wssClient;
	}

	/**
	 * WSS Get all opened orders from socket
	 *
	 * @return bool
	 * @throws \WebSocket\BadOpcodeException
	 * @throws \WebSocket\ConnectionException
	 */
	public function getOpenedOrders() {
		$client = $this->getWssClient();
		$client->send( "{\"H\":\"corehub\",\"M\":\"QueryExchangeState\",\"A\":[\"{$this->pair}\"],\"I\":1}" );
		time_nanosleep( 0, 500000 );
		$orders = json_decode( $client->receive(), true )['R'];
		if ( ! $orders ) {
			time_nanosleep( 0, 500000 );
			$orders = json_decode( $client->receive(), true )['R'];
		}

		return $orders;
	}

	/**
	 * WSS count open volumes and weighted price from the WSS response
	 *
	 * @param bool $inBase
	 *
	 * @return array
	 * @throws \WebSocket\BadOpcodeException
	 * @throws \WebSocket\ConnectionException
	 */
	public function countOpenVolumes( $inBase = false ) {
		$orderBook = $this->getOpenedOrders();
		$buyVol    = 0;
		$sellVol   = 0;
		$piviBuy   = 0;
		$piviSell  = 0;
		foreach ( $orderBook['Buys'] as $buy ) {
			$buyVol  += $buy['Quantity'];
			$piviBuy += $buy['Quantity'] * $buy['Rate'];
		}
		foreach ( $orderBook['Sells'] as $sell ) {
			$sellVol  += $sell['Quantity'];
			$piviSell += $sell['Quantity'] * $sell['Rate'];

		}
		$weightedBuy  = $piviBuy / $buyVol;
		$weightedSell = $piviSell / $sellVol;
		$weighted     = number_format( ( $weightedSell * $sellVol + $weightedBuy * $buyVol ) / ( $buyVol + $sellVol ), 8 );
		if ( $inBase ) {
			$buyVol  = $piviBuy;
			$sellVol = $piviSell;
		}

		return [
			'BUY'          => $buyVol,
			'SELL'         => $sellVol,
			'WeightedBUY'  => $weightedBuy,
			'WeightedSELL' => $weightedSell,
			'WEIGHTED'     => $weighted
		];
	}

	/**
	 * change current pair but saves Web Socket connection and cloud flare cookies
	 *
	 * @param string $newPair
	 */
	public function changePair( $newPair = 'BTC-ETH' ) {
		if ( $newPair != $this->pair ) {
			$this->pair            = $newPair;
			$this->marketSummary   = null;
			$this->marketSummaries = null;
		}
	}

	/**
	 * check if candle is pin-bar from candle array
	 *
	 * @param array $candle
	 *
	 * @return bool
	 */
	public static function isPinBar( array $candle ) {
		if ( $candle['C'] > $candle['O'] ) { // going up
			if ( ( $candle['C'] - $candle['O'] ) * 2.5 < ( $candle['H'] - $candle['L'] ) ) {
				return true;
			}
		} else { // going down or stalled
			if ( ( $candle['O'] - $candle['C'] ) * 2.5 < ( $candle['H'] - $candle['L'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * get pin-bar direction from candle array
	 *
	 * @param array $candle
	 *
	 * @return int
	 */
	public static function pinBarDirection( array $candle ) {
		if ( self::isPinBar( $candle ) ) {
			$bodyUp   = max( $candle['C'], $candle['O'] );
			$bodyDown = min( $candle['C'], $candle['O'] );
			if ( ( $candle['H'] - $bodyUp ) > ( $bodyDown - $candle['L'] ) ) {
				return 1; // up tail
			} else {
				return - 1; // down tail
			}
		}

		return 0;
	}

	public static function addBollingerBandsToArray( array &$candles, $period = 20, $d = 2 ) {
		$total = count( $candles );
		if (!$candles[$total-1]["StdDev$period"]) {
			self::addStandardDeviationToArray( $candles, $period );

		}
		foreach ( $candles as &$candle ) {
			$candle['BBUpper'.$period.'d' . $d] = $candle['MA'.$period] + ($d * $candle['StdDev'.$period]);
			$candle['BBLower'.$period.'d' . $d] = $candle['MA'.$period] - ($d * $candle['StdDev'.$period]);
		}
	}

	public static function addStandardDeviationToArray( array &$candles, $period = 20 ) {
		$i     = 0;
		$total = count( $candles );
		if (!$candles[$total-1]["MA$period"]) {
			self::addMovingAverageToArray($candles, $period);
		}
		while ( $i < $total ) {
			if ( $i >= $period - 1 ) {
				$mean = $candles[$i]['MA' . $period];
				$stdDev = 0;
				for ($j = $i - $period + 1; $j<=$i; $j++) {
					$stdDev+=pow($candles[$j]['C'] - $mean,2);


				}
				$candles[$i]['StdDev' . $period] = sqrt($stdDev / $period);
			}

			$i ++;
		}

		return $candles;
	}

	/**
	 * simple moving average of period
	 *
	 * @param array $candles
	 * @param $period
	 *
	 * @return array
	 */
	public static function addMovingAverageToArray( array &$candles, $period = 20 ) {
		$i     = 0;
		$total = count( $candles );
		while ( $i < $total ) {
			if ( $i >= $period - 1 ) {
				$ma = 0;
				for ( $j = $i - $period + 1; $j <= $i; $j ++ ) {
					$ma += $candles[ $j ]['C'];
				}
				$ma                  /= $period;
				$candles[ $i ]['MA' . $period] = $ma;
			}

			$i ++;
		}

		return $candles;
	}


}