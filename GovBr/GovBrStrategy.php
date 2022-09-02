<?php

use Curl\Curl;

class GovBrStrategy extends OpauthStrategy
{

	/**
	 * Compulsory config keys, listed as unassociative arrays
	 */
	public $expects = ['client_id',  'auth_endpoint', 'token_endpoint'];
	/**
	 * Optional config keys, without predefining any default values.
	 */
	public $optionals = ['redirect_uri', 'scope', 'response_type', 'register_form_action', 'register_form_method'];
	/**
	 * Optional config keys with respective default values, listed as associative arrays
	 * eg. array('scope' => 'email');
	 */
	public $defaults = ['redirect_uri' => '{complete_url_to_strategy}oauth2callback'];

	/**
	 * Auth request
	 */
	public function request()
	{
		$_SESSION['govbr-state'] = md5($this->strategy['state_salt'].time());

		$url = $this->strategy['auth_endpoint'];
		$params = array(
			'client_id' => $this->strategy['client_id'],
			'redirect_uri' => $this->strategy['redirect_uri'],
			'response_type' => 'code',
			'scope' => $this->strategy['scope'],
			'state' => $_SESSION['govbr-state'],
			'code_challenge' => $this->strategy['code_challenge'],
			'code_challenge_method' => $this->strategy['code_challenge_method'],
		);

		foreach ($this->optionals as $key) {
			if (!empty($this->strategy[$key])) $params[$key] = $this->strategy[$key];
		}

		$this->clientGet($url, $params);
	}

	/**
	 * Internal callback, after OAuth
	 */
	public function oauth2callback()
	{
		$app = App::i();

		if ((array_key_exists('code', $_GET) && !empty($_GET['code'])) && (array_key_exists("state", $_GET) && $_GET['state'] == $_SESSION['govbr-state'])) {
			
			$code = $_GET['code'];
		
			$url = $this->strategy['token_endpoint'];
			$params = array(
				'grant_type' => 'authorization_code',
				'code' => $code,
				'redirect_uri' => $this->strategy['redirect_uri'],
				'code_verifier' => $this->strategy['code_verifier'],
			);

			$token = base64_encode("{$this->strategy['client_id']}:{$this->strategy['client_secret']}");
			$curl = new Curl;
			$curl->setHeader('Content-Type', 'application/x-www-form-urlencoded');
			$curl->setHeader('Authorization', "Basic {$token}");

			$curl->post($url, $params);
			$curl->close();
			$response = $curl->response;

			$results = json_decode($response);

			if (!empty($results) && !empty($results->id_token)) {

				/** @var stdClass $userinfo */
				$userinfo = $this->userinfo($results->id_token);

        		//@TODO O nome deve ser o primeiro nome


				$info = [
					'name' => $userinfo->name,
					'cpf' => $userinfo->sub,
					'email' => $userinfo->email_verified ? $userinfo->email : null,
					'phone_number' => $userinfo->phone_number_verified ? $userinfo->phone_number : null,
				];
				
				$this->auth = array(
					'uid' => $userinfo->jti,
					'credentials' => array(
						'token' => $results->id_token,
						'expires' => $userinfo->exp
					),
					'raw' => $info,
					'info' => $info
				);
				
				$app->hook("entity(Agent).insert:after", function() use ($userinfo, $token){
					$this->nomeCompleto = $userinfo->name;
					
					// @TODO definir o avatar
					// $curl = new Curl;
					// $curl->setHeader('Content-Type', 'application/x-www-form-urlencoded');
					// $curl->setHeader('Authorization', "Basic {$token}");

					// $curl->post($url, $params);
					// $curl->close();
					// $response = $curl->response;
				});

				$this->callback();
			} else {
				$error = array(
					'code' => 'access_token_error',
					'message' => 'Failed when attempting to obtain access token',
					'raw' => array(
						'response' => $response,
						'headers' => $headers
					)
				);
				$this->errorCallback($error);
			}
		} else {
			$error = array(
				'code' => 'oauth2callback_error',
				'raw' => $_GET
			);

			$this->errorCallback($error);
		}
	}

	/**
	 * @param string $id_token 
	 * @return array Parsed JSON results
	 */
	private function userinfo($id_token)
	{
		$exp = explode(".", $id_token);
		return json_decode(base64_decode($exp[1]));
	}
}
