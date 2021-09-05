<?php
/**
 * a mechanism capable to examine whether data and depending
 * on the temperature send an sms message to a specified number
 *
 * @author     John Kouvakis
 * @datetime   05 September 2021
 * @perpose    AMD Assessment
 */

/**
 * DEBUG config values.
 */
define("DEBUG_MODE", true);
define("LOCALHOST_MODE", true);

/**
 * OpenWeather config values.
 */
define("OPENWEATHER_API_KEY", "b385aa7d4e568152288b3c9f5c2458a5");
define("OPENWEATHER_API_URL", "https://api.openweathermap.org/data/2.5/weather");
define("OPENWEATHER_API_UNITS", "metric");

/**
 * Routee config values.
 */
define("ROUTEE_APP_ID", "5c5d5e28e4b0bae5f4accfec");
define("ROUTEE_APP_SECRET", "MGkNfqGud0");
define("ROUTEE_FROM", "amdTelecom");
define("ROUTEE_APP_AUTH_URL", "https://auth.routee.net/oauth/token");
define("ROUTEE_APP_SMS_URL", "https://connect.routee.net/sms");

class WeatherNotify {
	private string $weatherCity;
	private string $userMobile;

	private string $routeeToken = '';
	private \DateTime $routeeTokenExpiration;

	/**
	 * WeatherNotify constructor.
	 * @param string $_weatherCity
	 * @param string $_userMobile
	 */
    public function __construct(string $_weatherCity = '', string $_userMobile) {
        $this->weatherCity = $_weatherCity;
		$this->userMobile = $_userMobile;
    }

	/**
	 * Converts openweather response to json and returns temperature.
	 * @param string $responseData
	 *
	 * @return float temperature
	 */
	private function getJSONTemp(string $responseData) {
		try {
			$jsonData = json_decode($responseData);
			return (float)$jsonData->main->temp;
		} catch (\Throwable $th) {
			return false;
		}
	}

	/**
	 * Get openweather response for specific city.
	 *
	 * @return float temperature
	 */
	private function getTemperature() {
		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL => OPENWEATHER_API_URL . "?q=" . $this->weatherCity . "&units=" . OPENWEATHER_API_UNITS . "&appid=" . OPENWEATHER_API_KEY,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "GET",
		]);

		if (LOCALHOST_MODE) curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

		$response = curl_exec($curl);
		$err = curl_error($curl);
		if (DEBUG_MODE) var_dump($response);
		if (DEBUG_MODE) var_dump($err);

		curl_close($curl);

		if ($err) :
			return false;
		else :
			return $this->getJSONTemp($response);
		endif;
	}

	/**
	 * Get routee access token with expiration time.
	 */
	private function getRouteeAccessToken() {
		$authorizationString = base64_encode(ROUTEE_APP_ID . ":" . ROUTEE_APP_SECRET);
		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL => ROUTEE_APP_AUTH_URL,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "POST",
			CURLOPT_POSTFIELDS => "grant_type=client_credentials",
			CURLOPT_HTTPHEADER => [
				"authorization: Basic " . $authorizationString,
				"content-type: application/x-www-form-urlencoded"
			],
		]);

		if (LOCALHOST_MODE) curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

		$response = curl_exec($curl);
		$err = curl_error($curl);
		if (DEBUG_MODE) var_dump($response);
		if (DEBUG_MODE) var_dump($err);

		curl_close($curl);

		if ($err) {
			return false;
		} else {
			$jsonData = json_decode($response);
			if (DEBUG_MODE) var_dump($jsonData);
			$this->routeeToken = $jsonData->access_token;

			$dateNow = new \DateTime();
			// i would remove a second from the token for safety reasons ((int)$jsonData->expires_in - 1)
			$dateExpiration = $dateNow->add(new \DateInterval('PT' . (int)$jsonData->expires_in . 'S'));
			$this->routeeTokenExpiration = $dateExpiration;
			return true;
		}
	}

	/**
	 * Check expired or null routee token.
	 */
	private function renewRouteeToken() {
		if ($this->routeeToken == '') {
			$this->getRouteeAccessToken();
		} else {
			$dateNow = new \DateTime();
			if ($dateNow > $this->routeeTokenExpiration) :
				$this->getRouteeAccessToken();
			endif;
		}
	}

	/**
	 * Sends SMS to user mobile.
	 */
	private function sendSMS(string $body) {
		$this->renewRouteeToken();
		if (DEBUG_MODE) :
			var_dump($this->routeeToken);
			var_dump($this->routeeTokenExpiration);
		endif;

		$curl = curl_init();

		$postBody = [
			"body" => $body,
			"to" => $this->userMobile,
			"from" => ROUTEE_FROM
		];

		curl_setopt_array($curl, [
			CURLOPT_URL => ROUTEE_APP_SMS_URL,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "POST",
			CURLOPT_POSTFIELDS => json_encode($postBody),
			CURLOPT_HTTPHEADER => [
				"authorization: Bearer " . $this->routeeToken,
				"content-type: application/json"
			],
		]);

		if (LOCALHOST_MODE) curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

		$response = curl_exec($curl);
		$err = curl_error($curl);
		if (DEBUG_MODE) var_dump($response);
		if (DEBUG_MODE) var_dump($err);

		curl_close($curl);
		if ($err) {
			return false;
		} else {
			return true;
		}
	}

	/**
     * Sends sms notification to user for temperature is above/below user value.
     * @param float $userTempVal
     */
	public function getTempSendAlert(float $userTempVal) {
		$temp = $this->getTemperature();
		if (DEBUG_MODE) var_dump($temp);
		if ($temp !== false) :
			$mobileText = "";
			if ($temp >= $userTempVal) :
				$mobileText = "Your name and Temperature more than " . $userTempVal . "C. <" . $temp . ">";
				if (DEBUG_MODE) : var_dump($mobileText); endif;
			else :
				$mobileText = "Your name and Temperature less than " . $userTempVal . "C. <" . $temp . ">";
				if (DEBUG_MODE) : var_dump($mobileText); endif;
			endif;
			$this->sendSMS($mobileText);
		endif;
	}
}

$notifyUser = new WeatherNotify("thessaloniki,GR-B", "+306977977474");

$retryTimes = 10; // Retry count for loop
$sleepSeconds = 10*60; // Sleep time for loop x*y (x= mins, y = 60 for mins to seconds)
$userNotifyTemp = 20.0; // User's temperature to compare to

/*
 * Execute function 10 times and stop
 */
$counter = 0;
while ($counter < $retryTimes) :
	$notifyUser->getTempSendAlert($userNotifyTemp);
	$counter++;
	sleep($sleepSeconds);
endwhile;

/*
Some personal thoughts...
As for the classes i prefer to go with 3 classes 1 for Notification, 1 for openweather extend, and 1 for routee extend for more expandable function.
For lack of time i made one class including all those 3.
The above loop is not ideal. Best way in my opinion to do this is through database values after storing last execution time
and selecting the values that are executed less x times and datetime >= last execution time with a cronjob running every z minutes.
Thank you.
*/

