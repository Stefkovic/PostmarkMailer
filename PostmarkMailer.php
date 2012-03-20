<?php

use Nette\Utils\Json;
use Nette\Mail\Message;

/**
 * Postmarkapp.com mailer for Nette Framework
 *
 * @author Marek Štefkovič
 */
class PostmarkMailer extends \Nette\Object implements \Nette\Mail\IMailer
{
	const API_URL = 'https://api.postmarkapp.com/email';

	/** @var string */
	private $apiKey;

	/** @var  mixed */
	private $response;

	/**
	 * @param string $apiKey Postmark api key
	 */
	public function __construct($apiKey)
	{
		$this->apiKey = $apiKey;
	}

	/**
	 * @return mixed Postmark response
	 */
	public function getResponse()
	{
		return $this->response;
	}

	/**
	 * Sends email
	 *
	 * @param \Nette\Mail\Message $mail
	 * @throws PostmarkException
	 */
	function send(Message $mail)
	{
		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
			'X-Postmark-Server-Token: ' . $this->apiKey
		);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, self::API_URL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_POSTFIELDS, $this->encodeMessage($mail));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/certificates/api.postmarkapp.com.pem');

		$result = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if (curl_errno($ch)) {
			throw new PostmarkException('CURL Error:' . curl_error($ch));
		}

		switch ($code) {
			case 200:
				try {
					$this->response = Json::decode($result);
				}
				catch (\Nette\Utils\JsonException $e) {
					throw new PostmarkException($e->getMessage(), $e->getCode());
				}
				break;
			case 401:
				throw new PostmarkException('Unauthorized: Missing or incorrect API Key.', 401);
				break;
			case 422:
				try {
					$error = Json::decode($result);
					throw new PostmarkException($error->Message, $error->ErrorCode);
				}
				catch (\Nette\Utils\JsonException $e) {
					throw new PostmarkException($e->getMessage(), $e->getCode());
				}
				break;
			case 500:
				throw new PostmarkException('Internal Server Error', 500);
				break;
			default:
				throw new PostmarkException('Unknown error occured.');
				break;
		}
	}

	/**
	 * Crates JSON for Nette\Mail|Message
	 *
	 * @param \Nette\Mail\Message $message
	 * @return string
	 * @throws PostmarkException
	 */
	private function encodeMessage(Message $message)
	{
		$data = array();

		$defaultHeaders = array(
			/* Nette => Postmark */
			'From' => 'From',
			'To' => 'To',
			'Cc' => 'Cc',
			'Bcc' => 'Bcc',
			'Subject' => 'Subject',
			'Reply-To' => 'ReplyTo'
		);

		foreach ($defaultHeaders as $nette => $postmark) {
			if (isset($message->headers[$nette])) {
				if (is_array($message->headers[$nette])) {
					$items = array();
					foreach ($message->headers[$nette] as $key => $value) {
						$items[] = $this->formatEmail(array($key, $value));
					}
					$data[$postmark] = implode(',', $items);
				}
				else {
					$data[$postmark] = $message->headers[$nette];
				}
			}
		}

		$data['HtmlBody'] = $message->htmlBody;
		$data['TextBody'] = $message->body;

		foreach ($message->getAttachments() as $attachment) {
			$filename = $attachment->getHeader('Content-Disposition');
			$filename = str_replace(array('"', 'filename='), array('', ''), explode('; ',$filename[0]));
			$data['Attachments'][] = array(
				'Name' => $filename,
				'Content' => base64_encode($attachment->body),
				'ContentType' => $attachment->getHeader('Content-Type')
			);
		}

		try {
			return Json::encode($data);
		}
		catch (\Nette\Utils\JsonException $e) {
			throw new PostmarkException($e->getMessage(), $e->getCode());
		}
	}

	/**
	 * Formats email
	 *
	 * @param $parts
	 * @return string
	 */
	private function formatEmail($parts)
	{
		if ($parts[1] === NULL)
			return $parts[0];
		return $parts[1] . ' <' . $parts[0] . '>';
	}
}

/**
 * Postmark mailer exception
 */
class PostmarkException extends \Exception
{
}

Message::extensionMethod('getAttachments', function (Message $_this)
{
	$reflProp = $_this->getReflection()->getProperty('attachments');
	$reflProp->setAccessible(TRUE);
	return $reflProp->getValue($_this);
});
